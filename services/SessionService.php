<?php
/**
 * WMS - Session service.
 *
 * Owns the rules around connecting a device: device limits, opening and
 * closing session rows, recording usage and asking the network layer to drop
 * a user.
 *
 * WMS records and authorises sessions; the router is what actually enforces
 * access.  When no live router is attached, sessions are flagged demo.
 */
class SessionService
{
    private Database $db;
    private SessionModel $sessions;
    private Device $devices;
    private Usage $usage;
    private NetworkService $network;

    public function __construct()
    {
        $this->db       = Database::getInstance();
        $this->sessions = new SessionModel();
        $this->devices  = new Device();
        $this->usage    = new Usage();
        $this->network  = new NetworkService();
    }

    /**
     * Checks whether one more device may connect under a voucher.
     *
     * @return array{allowed:bool,message:string,in_use:int,limit:int}
     */
    public function checkVoucherDeviceLimit(array $voucher, ?string $mac = null): array
    {
        $limit = max(1, (int)$voucher['device_limit']);
        $mac   = normalise_mac($mac);

        $rows = $this->db->fetchAll(
            "SELECT DISTINCT mac_address FROM sessions WHERE voucher_id = ? AND status = 'active'",
            [(int)$voucher['id']]
        );
        $macs = array_filter(array_column($rows, 'mac_address'));

        if ($mac !== null && in_array($mac, $macs, true)) {
            return ['allowed' => true, 'message' => 'This device is already connected.', 'in_use' => count($macs), 'limit' => $limit];
        }

        if (count($macs) >= $limit) {
            return [
                'allowed' => false,
                'message' => 'This voucher allows ' . $limit . ' device' . ($limit === 1 ? '' : 's') . ' and they are all in use. Disconnect another device and try again.',
                'in_use'  => count($macs),
                'limit'   => $limit,
            ];
        }

        return ['allowed' => true, 'message' => '', 'in_use' => count($macs), 'limit' => $limit];
    }

    /** Same check for an account subscription. */
    public function checkSubscriptionDeviceLimit(array $subscription, ?string $mac = null): array
    {
        $limit = max(1, (int)$subscription['device_limit']);
        $mac   = normalise_mac($mac);

        $rows = $this->db->fetchAll(
            "SELECT DISTINCT mac_address FROM sessions WHERE subscription_id = ? AND status = 'active'",
            [(int)$subscription['id']]
        );
        $macs = array_filter(array_column($rows, 'mac_address'));

        if ($mac !== null && in_array($mac, $macs, true)) {
            return ['allowed' => true, 'message' => 'This device is already connected.', 'in_use' => count($macs), 'limit' => $limit];
        }
        if (count($macs) >= $limit) {
            return [
                'allowed' => false,
                'message' => 'Your package allows ' . $limit . ' device' . ($limit === 1 ? '' : 's') . ' at a time.',
                'in_use'  => count($macs),
                'limit'   => $limit,
            ];
        }
        return ['allowed' => true, 'message' => '', 'in_use' => count($macs), 'limit' => $limit];
    }

    /**
     * Opens a session for a device.
     *
     * @param array $context voucher, subscription, customer, mac, ip,
     *                       user_agent, router_id, access_point_id
     * @return array{ok:bool,message:string,session_id?:int,device_id?:int,live:bool}
     */
    public function startSession(array $context): array
    {
        $voucher      = $context['voucher'] ?? null;
        $subscription = $context['subscription'] ?? null;
        $customerId   = isset($context['customer_id']) ? (int)$context['customer_id'] : null;
        $mac          = normalise_mac($context['mac'] ?? null);
        $routerId     = $context['router_id'] ?? ($voucher['router_id'] ?? null);

        if ($mac === null) {
            // Portals behind a router that does not pass the MAC still work:
            // we synthesise a stable identifier from the client IP.
            $mac = self::syntheticMac($context['ip'] ?? wms_client_ip());
        }

        // Everything opened here belongs to the voucher's / customer's tenant.
        $providerId = $context['provider_id']
            ?? ($voucher['provider_id'] ?? ($subscription['provider_id'] ?? ProviderContext::providerId()));

        $device = $this->devices->register([
            'provider_id'     => $providerId === null ? null : (int)$providerId,
            'customer_id'     => $customerId,
            'voucher_id'      => $voucher['id'] ?? null,
            'device_type'     => $context['device_type'] ?? guess_device_type($context['user_agent'] ?? ''),
            'mac_address'     => $mac,
            'ip_address'      => $context['ip'] ?? wms_client_ip(),
            'router_id'       => $routerId,
            'access_point_id' => $context['access_point_id'] ?? null,
            'user_agent'      => $context['user_agent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? null),
            'source'          => $this->network->isDemoMode() ? 'demo' : 'live',
        ]);

        if (($device['status'] ?? '') === 'blocked') {
            return ['ok' => false, 'message' => 'This device has been blocked by an administrator.', 'live' => false];
        }

        $sessionId = $this->sessions->open([
            'provider_id'     => $providerId === null ? null : (int)$providerId,
            'customer_id'     => $customerId,
            'voucher_id'      => $voucher['id'] ?? null,
            'subscription_id' => $subscription['id'] ?? null,
            'device_id'       => (int)$device['id'],
            'username'        => $voucher['code'] ?? ($context['username'] ?? null),
            'mac_address'     => $mac,
            'ip_address'      => $context['ip'] ?? wms_client_ip(),
            'router_id'       => $routerId,
            'access_point_id' => $context['access_point_id'] ?? null,
            'source'          => $this->network->isDemoMode() ? 'demo' : 'live',
        ]);

        return [
            'ok'         => true,
            'message'    => 'Session opened.',
            'session_id' => $sessionId,
            'device_id'  => (int)$device['id'],
            'live'       => !$this->network->isDemoMode(),
        ];
    }

    /**
     * Closes a session, writes its usage record and asks the router to drop
     * the user when we are attached to one.
     */
    public function disconnect(int $sessionId, string $reason = 'Disconnected by staff', string $status = 'closed'): array
    {
        $session = $this->sessions->withRelations($sessionId);
        if (!$session) {
            return ['ok' => false, 'message' => 'That session no longer exists.'];
        }
        if ($session['status'] !== 'active') {
            return ['ok' => false, 'message' => 'That session has already ended.'];
        }

        $networkMessage = '';
        $live = false;
        if (!empty($session['username'])) {
            $provider = $this->network->providerForRouterId($session['router_id'] ? (int)$session['router_id'] : null);
            $result   = $provider->disconnectUser((string)$session['username']);
            $live     = $provider->isLive();
            $networkMessage = (string)($result['message'] ?? '');
            $provider->disconnect();
        }

        $closed = $this->sessions->close($sessionId, $reason, $status);

        if ($closed) {
            $this->usage->record([
                'provider_id'      => $closed['provider_id'] ?? null,
                'customer_id'      => $closed['customer_id'],
                'voucher_id'       => $closed['voucher_id'],
                'subscription_id'  => $closed['subscription_id'],
                'session_id'       => $sessionId,
                'router_id'        => $closed['router_id'],
                'record_date'      => date('Y-m-d', strtotime((string)$closed['started_at'])),
                'download_bytes'   => (int)$closed['download_bytes'],
                'upload_bytes'     => (int)$closed['upload_bytes'],
                'duration_seconds' => (int)$closed['duration_seconds'],
                'source'           => $closed['source'],
            ]);
        }

        AuditLog::record('session_disconnect', 'session', $sessionId, $reason);

        return [
            'ok'      => true,
            'live'    => $live,
            'message' => 'Session closed.' . ($networkMessage ? ' ' . $networkMessage : ''),
        ];
    }

    /** Blocks a device and drops any session it currently holds. */
    public function blockDevice(int $deviceId): array
    {
        $device = $this->devices->find($deviceId);
        if (!$device) {
            return ['ok' => false, 'message' => 'That device no longer exists.'];
        }

        $this->devices->setStatus($deviceId, 'blocked');
        foreach ($this->db->fetchAll("SELECT id FROM sessions WHERE device_id = ? AND status = 'active'", [$deviceId]) as $row) {
            $this->disconnect((int)$row['id'], 'Device blocked by staff', 'blocked');
        }

        AuditLog::record('device_block', 'device', $deviceId, 'Blocked device ' . $device['mac_address']);
        return ['ok' => true, 'message' => 'Device blocked and disconnected.'];
    }

    public function unblockDevice(int $deviceId): array
    {
        $device = $this->devices->find($deviceId);
        if (!$device) {
            return ['ok' => false, 'message' => 'That device no longer exists.'];
        }
        $this->devices->setStatus($deviceId, 'idle');
        AuditLog::record('device_unblock', 'device', $deviceId, 'Unblocked device ' . $device['mac_address']);
        return ['ok' => true, 'message' => 'Device unblocked.'];
    }

    /**
     * Closes sessions whose voucher or subscription has run out.
     * Safe to call from a cron job or on dashboard load.
     */
    public function closeStaleSessions(): int
    {
        $closed = 0;
        foreach ($this->sessions->staleActive() as $session) {
            $this->disconnect((int)$session['id'], 'Access period ended', 'closed');
            $closed++;
        }
        return $closed;
    }

    /**
     * Pulls active sessions from a live router and reconciles the traffic
     * counters held in WMS.  Only ever called for live routers.
     */
    public function syncFromRouter(array $router): array
    {
        $provider = $this->network->providerFor($router);
        if (!$provider->isLive()) {
            return ['ok' => false, 'message' => 'Demo mode: there is no live router to synchronise with.', 'updated' => 0];
        }

        $result = $provider->getActiveSessions();
        if (!$result['ok']) {
            return ['ok' => false, 'message' => (string)($result['message'] ?? 'Could not read sessions.'), 'updated' => 0];
        }

        $updated = 0;
        foreach ($result['data'] as $live) {
            $session = $this->db->fetchOne(
                "SELECT * FROM sessions WHERE username = ? AND status = 'active' ORDER BY started_at DESC LIMIT 1",
                [$live['username']]
            );
            if (!$session) {
                continue;
            }
            $this->sessions->updateById((int)$session['id'], [
                'download_bytes' => (int)$live['bytes_out'],
                'upload_bytes'   => (int)$live['bytes_in'],
                'ip_address'     => $live['ip_address'] ?: $session['ip_address'],
                'mac_address'    => $live['mac_address'] ?: $session['mac_address'],
                'source'         => 'live',
            ]);

            // Keep the voucher's data counter in step with the router.
            if (!empty($session['voucher_id'])) {
                $usedMb = (int)round(((int)$live['bytes_in'] + (int)$live['bytes_out']) / 1048576);
                $this->db->execute('UPDATE vouchers SET data_used_mb = GREATEST(data_used_mb, ?) WHERE id = ?', [$usedMb, (int)$session['voucher_id']]);
            }
            $updated++;
        }

        $provider->disconnect();
        return ['ok' => true, 'message' => $updated . ' session(s) synchronised.', 'updated' => $updated];
    }

    /**
     * Stable pseudo-MAC for portals that do not receive a real one.
     * Prefixed 02: (locally administered) so it can never collide with a
     * real vendor address, and clearly identifiable in the device list.
     */
    public static function syntheticMac(string $seed): string
    {
        $hash = substr(md5('wms|' . $seed), 0, 10);
        return strtoupper('02:' . implode(':', str_split($hash, 2)));
    }
}
