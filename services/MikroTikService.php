<?php
/**
 * WMS - MikroTik network provider (Live mode).
 *
 * Wraps RouterOsApi and translates RouterOS replies into the plain arrays the
 * rest of WMS understands.  Every method returns ok=false with a friendly
 * message instead of throwing, so an offline router degrades the page rather
 * than breaking it.
 *
 * Router credentials arrive from Router::withCredentials() and stay server
 * side; they are never echoed into HTML or JSON.
 */
class MikroTikService implements NetworkProvider
{
    private array $router;
    private ?RouterOsApi $api = null;
    private bool $connected = false;
    private string $error = '';

    /** Round-trip time of the last successful connect, in milliseconds. */
    private ?int $responseMs = null;

    /**
     * Connection attempts before giving up on a router.
     *
     * A hotspot gateway under load can drop a single API connection; three
     * short tries distinguishes that from a router that is genuinely down.
     */
    public const CONNECT_ATTEMPTS = 3;

    /** Pause between attempts, in microseconds. */
    private const RETRY_PAUSE_US = 400000;

    public function __construct(array $router)
    {
        $this->router = $router;
    }

    public function isLive(): bool
    {
        return true;
    }

    public function routerId(): int
    {
        return (int)($this->router['id'] ?? 0);
    }

    /* ------------------------------------------------------------ session */

    public function connect(): array
    {
        if ($this->connected) {
            return ['ok' => true, 'message' => 'Already connected.', 'response_ms' => $this->responseMs];
        }

        $password = $this->router['api_password_plain'] ?? Crypto::decrypt($this->router['api_password'] ?? null);
        if (empty($this->router['ip_address']) || empty($this->router['api_username']) || $password === '') {
            $this->error = 'This router is missing its API address, username or password.';
            return ['ok' => false, 'message' => $this->error, 'response_ms' => null];
        }

        /*
         * Retry policy: up to CONNECT_ATTEMPTS short tries with a brief pause
         * between them. Only after the last one has failed does the caller
         * hear that the router is unreachable, so one dropped packet does not
         * take a working gateway offline in the UI.
         *
         * Authentication failures are NOT retried - a wrong password will be
         * just as wrong the third time, and repeating it only invites the
         * router's own brute-force protection.
         */
        $attempts = self::CONNECT_ATTEMPTS;
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $started = microtime(true);

            $this->api = new RouterOsApi(
                (string)$this->router['ip_address'],
                self::port($this->router),
                self::usesTls($this->router),
                (int)setting('router_timeout_seconds', 5)
            );

            if ($this->api->connect((string)$this->router['api_username'], $password)) {
                $this->responseMs = (int)round((microtime(true) - $started) * 1000);
                $this->connected  = true;
                $this->error      = '';
                return [
                    'ok'          => true,
                    'message'     => 'Connected to ' . ($this->router['name'] ?? 'router') . '.',
                    'response_ms' => $this->responseMs,
                    'attempts'    => $attempt,
                ];
            }

            $this->error = $this->api->lastError() ?: 'Connection failed.';
            $this->api   = null;

            if (self::isAuthFailure($this->error) || $attempt === $attempts) {
                break;
            }
            usleep(self::RETRY_PAUSE_US);
        }

        // The technical detail goes to the server log; the caller gets a
        // sentence it can safely show. Neither carries the credentials.
        Logger::network('Router connection failed', [
            'router'   => $this->router['name'] ?? '',
            'address'  => ($this->router['ip_address'] ?? '') . ':' . self::port($this->router),
            'tls'      => self::usesTls($this->router) ? 'yes' : 'no',
            'attempts' => $attempts,
            'error'    => $this->error,
        ]);

        return ['ok' => false, 'message' => $this->friendly($this->error), 'response_ms' => null];
    }

    /** True when the router answered but rejected the credentials. */
    private static function isAuthFailure(string $error): bool
    {
        $lower = strtolower($error);
        return str_contains($lower, 'password')
            || str_contains($lower, 'login')
            || str_contains($lower, 'not allowed')
            || str_contains($lower, 'invalid user');
    }

    /**
     * The API port, defaulting to the plain-API port.
     * Nothing here guesses TLS from the port - see usesTls().
     */
    public static function port(array $router): int
    {
        $port = (int)($router['api_port'] ?? 0);
        return ($port >= 1 && $port <= 65535) ? $port : 8728;
    }

    /**
     * Whether to speak TLS.
     *
     * The stored flag decides. It is never inferred silently: the router
     * form sets the flag when the operator picks "RouterOS API TLS", and
     * warns when the flag and the port disagree, rather than quietly
     * overriding one with the other.
     */
    public static function usesTls(array $router): bool
    {
        return (bool)($router['use_tls'] ?? false);
    }

    public function disconnect(): void
    {
        $this->api?->disconnect();
        $this->api = null;
        $this->connected = false;
    }

    /** Round-trip time of the last successful connect, in ms. */
    public function responseMs(): ?int
    {
        return $this->responseMs;
    }

    /**
     * Why a hotspot server exists but is not usable.
     *
     * Returns null when there is genuinely no server at all, so callers can
     * keep telling that operator to run the setup - and a specific reason
     * when a server is present but RouterOS has disabled it.
     */
    private static function hotspotBlockReason(array $servers): ?string
    {
        if ($servers === []) {
            return null;
        }

        foreach ($servers as $srv) {
            $reason = strtolower($srv['reason'] ?? '');
            if (str_contains($reason, 'device-mode')) {
                return 'The hotspot server "' . $srv['name'] . '" exists but RouterOS has disabled it:'
                    . ' the router is in device-mode "home", which blocks the hotspot feature.'
                    . ' Run /system/device-mode/update hotspot=yes and then confirm it by power-cycling'
                    . ' the router or pressing its reset button - device-mode cannot be changed over'
                    . ' the network alone. Do not create a second server; this one is configured correctly.';
            }
        }

        foreach ($servers as $srv) {
            if ($srv['invalid'] ?? false) {
                return 'The hotspot server "' . $srv['name'] . '" is marked invalid by RouterOS.'
                    . ' Check that its interface, address pool and profile all still exist.';
            }
        }

        $names = implode(', ', array_column($servers, 'name'));
        return 'A hotspot server already exists (' . $names . ') but is disabled.'
            . ' Enable it under IP > Hotspot > Servers rather than creating another one.';
    }

    /** Turns protocol errors into something a non-engineer can act on. */
    private function friendly(string $error): string
    {
        $lower = strtolower($error);
        return match (true) {
            str_contains($lower, 'refused')                       => 'Connection refused. Check that the API service is enabled on the router.',
            str_contains($lower, 'timed out') || str_contains($lower, 'time')
                                                                  => 'The router did not answer in time. It may be offline or unreachable from this server.',
            str_contains($lower, 'password') || str_contains($lower, 'login')
                                                                  => 'The router rejected the API username or password.',
            // The port answered - prefixing "could not reach" would point the
            // operator at the network instead of at the TLS setup.
            str_contains($lower, 'handshake') || str_contains($lower, 'ssl')
                || str_contains($lower, 'tls')                    => $error,
            default                                               => 'Could not reach the router. ' . $error,
        };
    }

    /** Guard used by every command method. */
    private function ensure(): ?array
    {
        if ($this->connected) {
            return null;
        }
        $result = $this->connect();
        return $result['ok'] ? null : ['ok' => false, 'message' => $result['message'], 'data' => []];
    }

    /* ------------------------------------------------------------- status */

    public function getRouterIdentity(): array
    {
        if ($guard = $this->ensure()) {
            return $guard;
        }
        $rows = $this->api->query('/system/identity/print');
        $name = $rows[0]['name'] ?? null;
        return $name
            ? ['ok' => true, 'identity' => $name]
            : ['ok' => false, 'message' => 'The router did not return an identity.'];
    }

    public function getRouterStatus(): array
    {
        if ($guard = $this->ensure()) {
            return array_merge($guard, ['status' => 'offline', 'source' => 'live', 'error' => $this->error]);
        }

        $resource = $this->api->query('/system/resource/print')[0] ?? [];
        $identity = $this->api->query('/system/identity/print')[0]['name'] ?? null;

        /*
         * The active-user count is only reported when the hotspot table
         * could actually be read. An empty table is a real zero; a failed
         * read is not, and must stay null so the UI says "Unknown".
         */
        $activeRows  = $this->api->command('/ip/hotspot/active/print');
        $activeCount = $this->responseFailed($activeRows)
            ? null
            : count(array_filter($activeRows, static fn($r) => ($r['_type'] ?? '') === '!re'));

        $totalMemory = (int)($resource['total-memory'] ?? 0);
        $freeMemory  = (int)($resource['free-memory'] ?? 0);
        $memoryUsed  = $totalMemory > 0 ? (int)round((($totalMemory - $freeMemory) / $totalMemory) * 100) : null;

        return [
            'ok'              => true,
            'status'          => 'online',
            'identity'        => $identity,
            'version'         => $resource['version'] ?? null,
            'board'           => $resource['board-name'] ?? null,
            'cpu_load'        => isset($resource['cpu-load']) ? (int)$resource['cpu-load'] : null,
            'memory_used_pct' => $memoryUsed,
            'uptime'          => $resource['uptime'] ?? null,
            'active_users'    => $activeCount,
            'response_ms'     => $this->responseMs,
            'source'          => 'live',
        ];
    }

    /** True when a raw command response carried a RouterOS error sentence. */
    private function responseFailed(array $response): bool
    {
        if ($response === []) {
            return true;
        }
        foreach ($response as $sentence) {
            if (in_array($sentence['_type'] ?? '', ['!trap', '!fatal'], true)) {
                return true;
            }
        }
        return false;
    }

    public function getSystemResources(): array
    {
        if ($guard = $this->ensure()) {
            return $guard;
        }
        $rows = $this->api->query('/system/resource/print');
        return $rows ? ['ok' => true, 'data' => $rows[0]] : ['ok' => false, 'message' => 'No resource information returned.'];
    }

    public function getInterfaces(): array
    {
        if ($guard = $this->ensure()) {
            return $guard;
        }
        $rows = $this->api->query('/interface/print');
        $out  = [];
        foreach ($rows as $row) {
            $out[] = [
                'name'     => $row['name'] ?? '',
                'type'     => $row['type'] ?? '',
                'running'  => ($row['running'] ?? 'false') === 'true',
                'disabled' => ($row['disabled'] ?? 'false') === 'true',
                'rx_bytes' => (int)($row['rx-byte'] ?? 0),
                'tx_bytes' => (int)($row['tx-byte'] ?? 0),
                'mac'      => $row['mac-address'] ?? null,
            ];
        }
        return ['ok' => true, 'data' => $out, 'source' => 'live'];
    }

    public function getTrafficStatistics(?string $interface = null): array
    {
        $interfaces = $this->getInterfaces();
        if (!$interfaces['ok']) {
            return $interfaces;
        }
        $rx = 0;
        $tx = 0;
        foreach ($interfaces['data'] as $row) {
            if ($interface !== null && $row['name'] !== $interface) {
                continue;
            }
            $rx += $row['rx_bytes'];
            $tx += $row['tx_bytes'];
        }
        return ['ok' => true, 'data' => ['rx_bytes' => $rx, 'tx_bytes' => $tx, 'interfaces' => $interfaces['data']], 'source' => 'live'];
    }

    /* ------------------------------------------------------ hotspot users */

    /**
     * Creates a hotspot user.
     *
     * @param array $user name, password, profile, limit_bytes_total,
     *                    limit_uptime, rate_limit, comment, server
     */
    public function createHotspotUser(array $user): array
    {
        if ($guard = $this->ensure()) {
            return $guard;
        }

        $attributes = array_filter([
            'name'               => $user['name'] ?? '',
            'password'           => $user['password'] ?? ($user['name'] ?? ''),
            'server'             => $user['server'] ?? ($this->router['hotspot_server'] ?? 'all'),
            'profile'            => $user['profile'] ?? ($this->router['default_user_profile'] ?? null),
            'limit-uptime'       => $user['limit_uptime'] ?? null,
            'limit-bytes-total'  => isset($user['limit_bytes_total']) ? (string)$user['limit_bytes_total'] : null,
            'comment'            => $user['comment'] ?? 'Created by WMS',
        ], static fn($v) => $v !== null && $v !== '');

        $response = $this->api->command('/ip/hotspot/user/add', $attributes);
        $result   = $this->simpleResult($response, 'Hotspot user created on the router.');
        if (!$result['ok']) {
            return $result;
        }

        /*
         * Do not take "no error" for success. RouterOS can accept a sentence
         * and still not end up with the user we asked for (a name collision
         * resolved differently, a profile that silently did not apply). Read
         * it back before telling WMS the customer has access.
         */
        $name = (string)($attributes['name'] ?? '');
        if ($name !== '' && $this->hotspotUserId($name) === null) {
            Logger::network('Hotspot user creation could not be verified', [
                'router' => $this->router['name'] ?? '',
                'user'   => $name,
            ]);
            return [
                'ok'      => false,
                'message' => 'The router accepted the request but the hotspot user is not present afterwards. Nothing was granted.',
            ];
        }

        return $result;
    }

    public function updateHotspotUser(string $username, array $changes): array
    {
        if ($guard = $this->ensure()) {
            return $guard;
        }
        $id = $this->hotspotUserId($username);
        if ($id === null) {
            return ['ok' => false, 'message' => 'That hotspot user does not exist on the router.'];
        }

        $map = [
            'password'          => 'password',
            'profile'           => 'profile',
            'limit_uptime'      => 'limit-uptime',
            'limit_bytes_total' => 'limit-bytes-total',
            'disabled'          => 'disabled',
            'comment'           => 'comment',
        ];
        $attributes = ['.id' => $id];
        foreach ($map as $key => $routerKey) {
            if (array_key_exists($key, $changes)) {
                $attributes[$routerKey] = (string)$changes[$key];
            }
        }

        return $this->simpleResult($this->api->command('/ip/hotspot/user/set', $attributes), 'Hotspot user updated.');
    }

    public function deleteHotspotUser(string $username): array
    {
        if ($guard = $this->ensure()) {
            return $guard;
        }
        $id = $this->hotspotUserId($username);
        if ($id === null) {
            return ['ok' => true, 'message' => 'That hotspot user was already absent from the router.'];
        }

        $result = $this->simpleResult($this->api->command('/ip/hotspot/user/remove', ['.id' => $id]), 'Hotspot user removed.');
        if ($result['ok'] && $this->hotspotUserId($username) !== null) {
            // Verified: the user is still there, so revocation did not happen.
            Logger::network('Hotspot user removal could not be verified', [
                'router' => $this->router['name'] ?? '',
                'user'   => $username,
            ]);
            return ['ok' => false, 'message' => 'The router still lists that hotspot user after the removal request.'];
        }

        return $result;
    }

    public function getHotspotUsers(): array
    {
        if ($guard = $this->ensure()) {
            return $guard;
        }
        $rows = $this->api->query('/ip/hotspot/user/print');
        $out  = [];
        foreach ($rows as $row) {
            $out[] = [
                'id'          => $row['.id'] ?? null,
                'name'        => $row['name'] ?? '',
                'profile'     => $row['profile'] ?? '',
                'uptime'      => $row['uptime'] ?? '0s',
                'bytes_in'    => (int)($row['bytes-in'] ?? 0),
                'bytes_out'   => (int)($row['bytes-out'] ?? 0),
                'limit_bytes' => (int)($row['limit-bytes-total'] ?? 0),
                'disabled'    => ($row['disabled'] ?? 'false') === 'true',
                'comment'     => $row['comment'] ?? '',
            ];
        }
        return ['ok' => true, 'data' => $out, 'source' => 'live'];
    }

    private function hotspotUserId(string $username): ?string
    {
        $rows = $this->api->query('/ip/hotspot/user/print', [], ['?name=' . $username]);
        return $rows[0]['.id'] ?? null;
    }

    /* ---------------------------------------------------- active sessions */

    public function getActiveSessions(): array
    {
        if ($guard = $this->ensure()) {
            return $guard;
        }
        $rows = $this->api->query('/ip/hotspot/active/print');
        $out  = [];
        foreach ($rows as $row) {
            $out[] = [
                'id'          => $row['.id'] ?? null,
                'username'    => $row['user'] ?? '',
                'ip_address'  => $row['address'] ?? '',
                'mac_address' => normalise_mac($row['mac-address'] ?? '') ?? ($row['mac-address'] ?? ''),
                'uptime'      => $row['uptime'] ?? '0s',
                'bytes_in'    => (int)($row['bytes-in'] ?? 0),
                'bytes_out'   => (int)($row['bytes-out'] ?? 0),
                'server'      => $row['server'] ?? '',
            ];
        }
        return ['ok' => true, 'data' => $out, 'source' => 'live'];
    }

    public function getActiveSession(string $username): array
    {
        $all = $this->getActiveSessions();
        if (!$all['ok']) {
            return $all;
        }
        foreach ($all['data'] as $session) {
            if ($session['username'] === $username) {
                return ['ok' => true, 'data' => $session, 'source' => 'live'];
            }
        }
        return ['ok' => false, 'message' => 'That user is not currently connected.'];
    }

    public function disconnectUser(string $username): array
    {
        if ($guard = $this->ensure()) {
            return $guard;
        }
        $rows = $this->api->query('/ip/hotspot/active/print', [], ['?user=' . $username]);
        $id   = $rows[0]['.id'] ?? null;
        if ($id === null) {
            return ['ok' => false, 'message' => 'That user is not currently connected to this router.'];
        }
        return $this->simpleResult(
            $this->api->command('/ip/hotspot/active/remove', ['.id' => $id]),
            'User disconnected from the hotspot.'
        );
    }

    /* -------------------------------------------------- bandwidth profiles */

    /**
     * Creates (or replaces) a hotspot user profile carrying the rate limit.
     */
    public function createBandwidthProfile(array $profile): array
    {
        if ($guard = $this->ensure()) {
            return $guard;
        }
        $name = $profile['mikrotik_name'] ?: $profile['name'];
        $existing = $this->api->query('/ip/hotspot/user/profile/print', [], ['?name=' . $name]);
        if ($existing) {
            return $this->updateBandwidthProfile($name, $profile);
        }

        return $this->simpleResult($this->api->command('/ip/hotspot/user/profile/add', [
            'name'          => $name,
            'rate-limit'    => BandwidthProfile::rateLimit($profile),
            'shared-users'  => (string)($profile['shared_users'] ?? 1),
        ]), 'Bandwidth profile pushed to the router.');
    }

    public function updateBandwidthProfile(string $name, array $profile): array
    {
        if ($guard = $this->ensure()) {
            return $guard;
        }
        $rows = $this->api->query('/ip/hotspot/user/profile/print', [], ['?name=' . $name]);
        $id   = $rows[0]['.id'] ?? null;
        if ($id === null) {
            return ['ok' => false, 'message' => 'That profile does not exist on the router.'];
        }
        return $this->simpleResult($this->api->command('/ip/hotspot/user/profile/set', [
            '.id'        => $id,
            'rate-limit' => BandwidthProfile::rateLimit($profile),
        ]), 'Bandwidth profile updated on the router.');
    }

    /* ---------------------------------------------------- provisioning */

    /**
     * Reads the hotspot servers configured on the router.
     *
     * WMS needs at least one before a voucher can grant real access - a
     * hotspot user with no server to belong to authorises nothing.
     */
    public function getHotspotServers(): array
    {
        if ($guard = $this->ensure()) {
            return $guard;
        }
        $rows = $this->api->query('/ip/hotspot/print');
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'name'       => $row['name'] ?? '',
                'interface'  => $row['interface'] ?? '',
                'profile'    => $row['profile'] ?? '',
                'addresses'  => $row['address-pool'] ?? '',
                'disabled'   => ($row['disabled'] ?? 'false') === 'true',
                // RouterOS explains its own refusals in ".about" - notably
                // "inactivated, not allowed by device-mode" on a router in
                // home mode, where the server is configured correctly and
                // still disabled. Without this the operator is told no server
                // exists and goes off to create a duplicate.
                'reason'     => trim((string)($row['.about'] ?? '')),
                'invalid'    => ($row['invalid'] ?? 'false') === 'true',
            ];
        }
        return ['ok' => true, 'data' => $out, 'source' => 'live'];
    }

    /** Hotspot user profiles - where bandwidth profiles land. */
    public function getHotspotProfiles(): array
    {
        if ($guard = $this->ensure()) {
            return $guard;
        }
        $rows = $this->api->query('/ip/hotspot/user/profile/print');
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'name'         => $row['name'] ?? '',
                'rate_limit'   => $row['rate-limit'] ?? '',
                'shared_users' => $row['shared-users'] ?? '1',
            ];
        }
        return ['ok' => true, 'data' => $out, 'source' => 'live'];
    }

    /** IP pools, so the operator can see the hotspot has addresses to hand out. */
    public function getIpPools(): array
    {
        if ($guard = $this->ensure()) {
            return $guard;
        }
        $rows = $this->api->query('/ip/pool/print');
        return ['ok' => true, 'data' => array_map(static fn($r) => [
            'name'   => $r['name'] ?? '',
            'ranges' => $r['ranges'] ?? '',
        ], $rows), 'source' => 'live'];
    }

    /* ------------------------------------------------- access point sources */

    /**
     * CAPsMAN-managed access points currently connected to this router.
     *
     * Only present on routers actually running CAPsMAN. An empty list from a
     * router without CAPsMAN is not evidence that an AP is down - the caller
     * must treat "absent" as unknown, not offline.
     *
     * @return array{ok:bool,data:array,supported:bool,message?:string}
     */
    public function getCapsmanAccessPoints(): array
    {
        if ($guard = $this->ensure()) {
            return $guard + ['data' => [], 'supported' => false];
        }

        $response = $this->api->command('/caps-man/remote-cap/print');
        if ($this->responseFailed($response)) {
            // No CAPsMAN package / not configured. Say so rather than
            // reporting an empty fleet.
            return ['ok' => true, 'data' => [], 'supported' => false,
                    'message' => 'This router does not run CAPsMAN.'];
        }

        $out = [];
        foreach ($response as $row) {
            if (($row['_type'] ?? '') !== '!re') {
                continue;
            }
            $mac = normalise_mac($row['base-mac'] ?? ($row['mac-address'] ?? ''));
            $out[] = [
                'mac'       => $mac,
                'identity'  => $row['identity'] ?? '',
                'name'      => $row['name'] ?? '',
                'board'     => $row['board'] ?? '',
                'version'   => $row['version'] ?? '',
                'radios'    => (int)($row['radios'] ?? 0),
                'state'     => $row['state'] ?? '',
            ];
        }

        return ['ok' => true, 'data' => $out, 'supported' => true, 'source' => 'live'];
    }

    /**
     * Wireless clients registered per CAPsMAN access point, keyed by AP MAC.
     *
     * @return array{ok:bool,data:array<string,int>,supported:bool}
     */
    public function getCapsmanClientCounts(): array
    {
        if ($guard = $this->ensure()) {
            return $guard + ['data' => [], 'supported' => false];
        }

        $response = $this->api->command('/caps-man/registration-table/print');
        if ($this->responseFailed($response)) {
            return ['ok' => true, 'data' => [], 'supported' => false];
        }

        $counts = [];
        foreach ($response as $row) {
            if (($row['_type'] ?? '') !== '!re') {
                continue;
            }
            // "interface" is the CAP's virtual interface; base MAC identifies
            // the physical AP when RouterOS supplies it.
            $mac = normalise_mac($row['base-mac'] ?? '');
            if ($mac === null) {
                continue;
            }
            $counts[$mac] = ($counts[$mac] ?? 0) + 1;
        }

        return ['ok' => true, 'data' => $counts, 'supported' => true];
    }

    /**
     * The router's ARP table.
     *
     * A complete ARP entry is real evidence that the device at that address
     * spoke to the router recently. An absent entry proves nothing - entries
     * age out - which is why the monitor maps "absent" to unknown.
     *
     * @return array{ok:bool,data:array,message?:string}
     */
    public function getArpTable(): array
    {
        if ($guard = $this->ensure()) {
            return $guard + ['data' => []];
        }

        $response = $this->api->command('/ip/arp/print');
        if ($this->responseFailed($response)) {
            return ['ok' => false, 'data' => [], 'message' => 'The router did not return its ARP table.'];
        }

        $out = [];
        foreach ($response as $row) {
            if (($row['_type'] ?? '') !== '!re') {
                continue;
            }
            $out[] = [
                'address'   => $row['address'] ?? '',
                'mac'       => normalise_mac($row['mac-address'] ?? ''),
                'interface' => $row['interface'] ?? '',
                'complete'  => ($row['complete'] ?? 'false') === 'true',
                'disabled'  => ($row['disabled'] ?? 'false') === 'true',
            ];
        }

        return ['ok' => true, 'data' => $out, 'source' => 'live'];
    }

    /* -------------------------------------------------------- test connect */

    /**
     * The "Test connection" action, end to end.
     *
     * Runs the sequence the operator sees on screen: validate, connect,
     * authenticate, read identity, read version, confirm the API grants the
     * access WMS needs, and look for a hotspot. Every line of the result is
     * something the router just told us.
     *
     * Read-only: unlike diagnose(), this never creates a probe user, so it is
     * safe to run against a production gateway at any time.
     *
     * @return array{ok:bool,message:string,checks:array,facts:array}
     */
    public function testConnection(): array
    {
        $checks = [];
        $facts  = [
            'identity'     => null,
            'version'      => null,
            'board'        => null,
            'uptime'       => null,
            'response_ms'  => null,
            'hotspot'      => null,
            'hotspots'     => [],
            'active_users' => null,
        ];

        /* 1. Reach the API and authenticate. */
        $connection = $this->connect();
        $facts['response_ms'] = $connection['response_ms'] ?? null;

        $checks[] = [
            'key'    => 'api',
            'label'  => 'API connection',
            'ok'     => $connection['ok'],
            'detail' => $connection['ok']
                ? ($this->router['ip_address'] ?? '') . ':' . self::port($this->router)
                    . (self::usesTls($this->router) ? ' over TLS' : '')
                    . ' answered in ' . (int)$facts['response_ms'] . ' ms'
                : $connection['message'],
        ];

        if (!$connection['ok']) {
            return [
                'ok'      => false,
                'message' => $connection['message'],
                'checks'  => $checks,
                'facts'   => $facts,
                'causes'  => self::failureCauses($this->error),
            ];
        }

        $checks[] = [
            'key'    => 'auth',
            'label'  => 'Authentication',
            'ok'     => true,
            'detail' => 'The API account "' . ($this->router['api_username'] ?? '') . '" was accepted.',
        ];

        /* 2. Identity and RouterOS version. */
        $status = $this->getRouterStatus();
        $facts['identity']     = $status['identity'] ?? null;
        $facts['version']      = $status['version'] ?? null;
        $facts['board']        = $status['board'] ?? null;
        $facts['uptime']       = $status['uptime'] ?? null;
        $facts['active_users'] = $status['active_users'] ?? null;

        $checks[] = [
            'key'    => 'identity',
            'label'  => 'Router identity',
            'ok'     => $facts['identity'] !== null,
            'detail' => $facts['identity'] ?? 'The router did not return an identity.',
        ];
        $checks[] = [
            'key'    => 'version',
            'label'  => 'RouterOS version',
            'ok'     => $facts['version'] !== null,
            'detail' => $facts['version']
                ? 'RouterOS ' . $facts['version'] . ($facts['board'] ? ' on ' . $facts['board'] : '')
                : 'The router did not report its version.',
        ];

        /* 3. Does this API account have the read access WMS depends on? */
        $users = $this->getHotspotUsers();
        $checks[] = [
            'key'    => 'user_access',
            'label'  => 'Hotspot user/profile access',
            'ok'     => (bool)$users['ok'],
            'detail' => $users['ok']
                ? count($users['data'] ?? []) . ' hotspot user(s) readable'
                : 'The API account cannot read /ip/hotspot/user. Give it the "read" policy.',
        ];

        $sessions = $this->getActiveSessions();
        $checks[] = [
            'key'    => 'session_access',
            'label'  => 'Active session access',
            'ok'     => (bool)$sessions['ok'],
            'detail' => $sessions['ok']
                ? count($sessions['data'] ?? []) . ' active session(s) right now'
                : 'The API account cannot read /ip/hotspot/active.',
        ];

        /* 4. Hotspot. Never assume a server called "hotspot1" exists. */
        $servers = $this->getHotspotServers();
        $enabled = array_values(array_filter($servers['data'] ?? [], static fn($srv) => !$srv['disabled']));
        $facts['hotspots'] = array_column($enabled, 'name');

        $configured = trim((string)($this->router['hotspot_server'] ?? ''));
        $found      = $configured === '' || $configured === 'all'
            ? $enabled !== []
            : in_array($configured, $facts['hotspots'], true);

        $facts['hotspot'] = $found ? ($configured !== '' && $configured !== 'all' ? $configured : ($facts['hotspots'][0] ?? null)) : null;

        $checks[] = [
            'key'    => 'hotspot',
            'label'  => 'Hotspot configuration',
            'ok'     => $found,
            'detail' => match (true) {
                $enabled === []                         => self::hotspotBlockReason($servers['data'] ?? [])
                    ?? 'No RouterOS hotspot server detected. Run /ip hotspot setup on the router.',
                !$found                                 => 'This router has no enabled hotspot server named "' . $configured . '". Available: ' . implode(', ', $facts['hotspots']) . '.',
                $configured === '' || $configured === 'all' => 'Found: ' . implode(', ', $facts['hotspots']) . '.',
                default                                 => 'Using "' . $configured . '".',
            },
        ];

        $this->disconnect();

        $failed = array_values(array_filter($checks, static fn($c) => !$c['ok']));

        return [
            'ok'      => $failed === [],
            'message' => $failed === []
                ? 'Connection successful.'
                : count($failed) . ' check(s) did not pass: ' . implode(', ', array_column($failed, 'label')) . '.',
            'checks'  => $checks,
            'facts'   => $facts,
            'causes'  => [],
        ];
    }

    /**
     * The plausible causes shown beside a failed connection.
     * Deliberately generic - it never echoes a credential or a raw exception.
     */
    public static function failureCauses(string $error): array
    {
        $lower = strtolower($error);

        if (str_contains($lower, 'password') || str_contains($lower, 'login') || str_contains($lower, 'invalid user')) {
            return [
                'The API username or password is wrong',
                'The API account is disabled on the router',
                'The account lacks the "api" policy',
            ];
        }

        return [
            'The router is offline',
            'The API service is disabled (/ip service enable api)',
            'The API address is wrong',
            'The API port is wrong (8728 plain, 8729 for API-SSL)',
            'The credentials are incorrect',
            'A firewall is blocking API access',
            'The router is unreachable from the WMS server',
        ];
    }

    /**
     * A full readiness check for one router.
     *
     * Returns a list of named checks, each pass/fail with a plain-language
     * explanation and, where it helps, the RouterOS command that fixes it.
     * This is what the provisioning screen renders.
     *
     * @return array{ok:bool,checks:array,summary:string}
     */
    public function diagnose(): array
    {
        $checks = [];

        /* 1. Can we reach the API at all? */
        $connection = $this->connect();
        $checks[] = [
            'key'     => 'api',
            'label'   => 'RouterOS API reachable',
            'ok'      => $connection['ok'],
            'detail'  => $connection['ok']
                ? 'Connected to ' . ($this->router['ip_address'] ?? '') . ':' . ($this->router['api_port'] ?? 8728) . '.'
                : $connection['message'],
            'fix'     => $connection['ok'] ? null : '/ip service enable api' . PHP_EOL . '/ip service set api address=' . self::serverHint(),
        ];

        if (!$connection['ok']) {
            return [
                'ok'      => false,
                'checks'  => $checks,
                'summary' => 'WMS cannot reach this router, so nothing else could be checked.',
            ];
        }

        /* 2. Identity and version. */
        $status = $this->getRouterStatus();
        $checks[] = [
            'key'    => 'identity',
            'label'  => 'Router identity and RouterOS version',
            'ok'     => $status['ok'],
            'detail' => $status['ok']
                ? ($status['identity'] ?? 'unnamed') . ' · RouterOS ' . ($status['version'] ?? 'unknown')
                    . ' · ' . ($status['board'] ?? 'unknown board')
                : ($status['message'] ?? 'No system information returned.'),
            'fix'    => null,
        ];

        /* 3. Is there a hotspot server for users to belong to? */
        $servers = $this->getHotspotServers();
        $enabled = array_values(array_filter($servers['data'] ?? [], static fn($s) => !$s['disabled']));
        $checks[] = [
            'key'    => 'hotspot',
            'label'  => 'Hotspot server configured',
            'ok'     => $servers['ok'] && $enabled !== [],
            'detail' => $enabled
                ? count($enabled) . ' enabled: ' . implode(', ', array_column($enabled, 'name'))
                : (self::hotspotBlockReason($servers['data'] ?? [])
                    ?? 'No enabled hotspot server. A voucher can be created on the router but it will not let anybody online.'),
            'fix'    => $enabled
                ? null
                : (($servers['data'] ?? []) === [] ? '/ip hotspot setup' : '/system/device-mode/print'),
        ];

        /* 4. Are there addresses to hand out? */
        $pools = $this->getIpPools();
        $checks[] = [
            'key'    => 'pool',
            'label'  => 'IP pool available',
            'ok'     => $pools['ok'] && ($pools['data'] ?? []) !== [],
            'detail' => ($pools['data'] ?? [])
                ? implode(', ', array_map(static fn($p) => $p['name'] . ' (' . $p['ranges'] . ')', $pools['data']))
                : 'No IP pool found. Hotspot clients will not receive an address.',
            'fix'    => ($pools['data'] ?? []) ? null : '/ip pool add name=hs-pool ranges=10.5.50.2-10.5.50.254',
        ];

        /* 5. Can WMS write? This is the permission that matters most. */
        $probeName = 'wms-probe-' . substr(bin2hex(random_bytes(3)), 0, 6);
        $created = $this->createHotspotUser([
            'name'     => $probeName,
            'password' => $probeName,
            'comment'  => 'Temporary WMS permission check - safe to delete',
        ]);
        if ($created['ok']) {
            $this->deleteHotspotUser($probeName);
        }
        $checks[] = [
            'key'    => 'write',
            'label'  => 'WMS may create and remove hotspot users',
            'ok'     => $created['ok'],
            'detail' => $created['ok']
                ? 'Created and removed a temporary user successfully. Voucher activation will work.'
                : 'Write failed: ' . $created['message'] . ' The API account needs write access to /ip/hotspot/user.',
            'fix'    => $created['ok'] ? null : '/user group set full policy=api,read,write,test,policy',
        ];

        /* 6. Existing hotspot users and live sessions, for context. */
        $users = $this->getHotspotUsers();
        $active = $this->getActiveSessions();
        $checks[] = [
            'key'    => 'state',
            'label'  => 'Current hotspot state',
            'ok'     => true,
            'detail' => count($users['data'] ?? []) . ' hotspot user(s) on the router, '
                      . count($active['data'] ?? []) . ' active session(s) right now.',
            'fix'    => null,
        ];

        $this->disconnect();

        $failed = array_values(array_filter($checks, static fn($c) => !$c['ok']));

        return [
            'ok'      => $failed === [],
            'checks'  => $checks,
            'summary' => $failed === []
                ? 'This router is ready. Vouchers activated by this provider will create real hotspot users here.'
                : count($failed) . ' item(s) need attention before this router can grant real internet access.',
        ];
    }

    /** Best guess at this server's address, for the API allow-list hint. */
    private static function serverHint(): string
    {
        $ip = $_SERVER['SERVER_ADDR'] ?? '';
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : 'YOUR.WMS.SERVER.IP';
    }

    /* --------------------------------------------------------------- misc */

    /** Normalises a command response into ok/message. */
    private function simpleResult(array $response, string $successMessage): array
    {
        foreach ($response as $sentence) {
            if (($sentence['_type'] ?? '') === '!trap' || ($sentence['_type'] ?? '') === '!fatal') {
                $message = (string)($sentence['message'] ?? 'The router refused the request.');
                Logger::network('RouterOS command failed', ['router' => $this->router['name'] ?? '', 'error' => $message]);
                return ['ok' => false, 'message' => $message];
            }
        }
        $id = null;
        foreach ($response as $sentence) {
            if (isset($sentence['ret'])) {
                $id = $sentence['ret'];
            }
        }
        return ['ok' => true, 'message' => $successMessage, 'id' => $id];
    }
}
