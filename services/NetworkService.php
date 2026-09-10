<?php
/**
 * WMS - Network service.
 *
 * The single door between the application and network equipment.  Pages never
 * talk to a router directly: they call NetworkService, which picks the right
 * provider (MikroTik for a configured router, Demo otherwise), records the
 * outcome and raises or clears alerts.
 *
 *      Page -> NetworkService -> NetworkProvider -> MikroTikService -> Router
 */
class NetworkService
{
    private Database $db;
    private Router $routers;
    private AccessPoint $accessPoints;

    /** Providers are cached per router for the life of the request. */
    private array $providers = [];

    public function __construct()
    {
        $this->db           = Database::getInstance();
        $this->routers      = new Router();
        $this->accessPoints = new AccessPoint();
    }

    /* ------------------------------------------------------------ providers */

    /**
     * Returns the network provider for a router row.
     *
     * Refuses outright if the router belongs to another tenant: this is the
     * gate that stops Provider A ever issuing a RouterOS command to
     * Provider B's hardware, however the router id was arrived at.
     */
    public function providerFor(array $router): NetworkProvider
    {
        $id  = (int)($router['id'] ?? 0);

        /*
         * The cache key carries the connection settings, not just the id.
         * Editing a router's address, port, TLS flag, username or mode
         * therefore produces a new provider rather than reusing one that
         * would still be dialling the old endpoint.
         */
        $key = self::providerKey($router);
        if (isset($this->providers[$key])) {
            return $this->providers[$key];
        }

        if ($router !== [] && array_key_exists('provider_id', $router)) {
            $ownerId = $router['provider_id'] === null ? null : (int)$router['provider_id'];
            if (!ProviderContext::canAccess($ownerId)) {
                Logger::network('Blocked a cross-provider router operation', [
                    'router_id'        => $id,
                    'router_provider'  => $ownerId,
                    'acting_provider'  => ProviderContext::providerId(),
                ]);
                AuditLog::record('access_denied', 'router', $id,
                    'Blocked an attempt to reach a router belonging to another provider');
                // A refusing Demo provider: it performs nothing and says so.
                return $this->providers[$key] = new DemoNetworkProvider([]);
            }
        }

        if (Router::isLiveCapable($router) && !demo_mode()) {
            $withCredentials = $this->routers->withCredentials($id) ?? $router;
            $provider = new MikroTikService($withCredentials);
        } else {
            $provider = new DemoNetworkProvider($router);
        }

        return $this->providers[$key] = $provider;
    }

    /** Cache key covering everything that decides how a router is contacted. */
    private static function providerKey(array $router): string
    {
        return implode('|', [
            (int)($router['id'] ?? 0),
            (string)($router['ip_address'] ?? ''),
            (int)($router['api_port'] ?? 0),
            (int)!empty($router['use_tls']),
            (string)($router['api_username'] ?? ''),
            (string)($router['mode'] ?? ''),
        ]);
    }

    /** Provider for the router a voucher/session belongs to, or the default. */
    public function providerForRouterId(?int $routerId): NetworkProvider
    {
        // Router::find() is tenant scoped, so another provider's id misses.
        $router = $routerId ? $this->routers->find($routerId) : $this->defaultRouter();
        return $this->providerFor($router ?? []);
    }

    /**
     * First live-capable router for the current tenant, otherwise its first
     * router of any kind. Scoped, so a provider never falls back onto
     * somebody else's hardware.
     */
    public function defaultRouter(): ?array
    {
        [$scopeSql, $params] = $this->routers->scope();

        $live = $this->db->fetchOne(
            "SELECT * FROM routers WHERE mode = 'live' AND status <> 'disabled' AND $scopeSql
              ORDER BY status = 'online' DESC, id ASC LIMIT 1",
            $params
        );
        if ($live) {
            return $live;
        }
        return $this->db->fetchOne(
            "SELECT * FROM routers WHERE $scopeSql ORDER BY id ASC LIMIT 1",
            $params
        );
    }

    /** True when nothing in the system can talk to real hardware right now. */
    public function isDemoMode(): bool
    {
        if (demo_mode()) {
            return true;
        }
        [$scopeSql, $params] = $this->routers->scope();
        return $this->db->count("SELECT COUNT(*) FROM routers WHERE mode = 'live' AND $scopeSql", $params) === 0;
    }

    /* --------------------------------------------------------------- polls */

    /**
     * TEST CONNECTION - the full production path.
     *
     *   validate input -> check provider ownership -> connect -> authenticate
     *   -> read identity -> read RouterOS version -> confirm the API access
     *   WMS needs -> check the hotspot -> record -> audit -> return
     *
     * Read-only: no probe user is written, so this is safe against a live
     * gateway carrying customers.
     *
     * @return array{ok:bool,message:string,status:array,checks:array,facts:array,causes:array}
     */
    public function testConnection(int $routerId): array
    {
        /*
         * Ownership first. find() is tenant scoped, so a router belonging to
         * another provider is simply not found - changing the id in a URL or
         * a JSON body gets a "not found", never somebody else's hardware.
         */
        $router = $this->routers->find($routerId);
        if (!$router) {
            Logger::network('Router test refused - not in scope', [
                'router_id' => $routerId,
                'provider'  => ProviderContext::providerId(),
            ]);
            AuditLog::record('access_denied', 'router', $routerId,
                'Blocked a connection test against a router outside this provider');
            return self::testFailure('That router could not be found.');
        }

        // A suspended provider may look, but may not touch the network.
        if ($blocked = $this->refuseWhenSuspended($router)) {
            return self::testFailure($blocked);
        }

        $provider = $this->providerFor($router);

        if (!$provider->isLive()) {
            // Demo routers are never contacted and never marked offline.
            $result = $provider->testConnection();
            return [
                'ok'      => false,
                'message' => $result['message'],
                'status'  => ['status' => 'unknown', 'source' => 'demo'],
                'checks'  => $result['checks'],
                'facts'   => $result['facts'],
                'causes'  => [],
            ];
        }

        $result = $provider->testConnection();
        $facts  = $result['facts'];

        if (empty($result['checks'][0]['ok'])) {
            // Could not reach it at all: this counts towards the retry budget.
            $status = $this->routers->recordUnreachable($routerId, $result['message']);
            $this->raiseOrClearOfflineAlert($router, $status, $result['message']);
            AuditLog::record('TEST_ROUTER', 'router', $routerId,
                'Connection test FAILED for ' . $router['name'] . ' - ' . str_limit($result['message'], 120));

            return [
                'ok'      => false,
                'message' => $result['message'],
                'status'  => ['status' => $status, 'source' => 'live'],
                'checks'  => $result['checks'],
                'facts'   => $facts,
                'causes'  => $result['causes'] ?? [],
            ];
        }

        // It answered. Store what it actually told us.
        $recorded = $this->routers->recordReachable($routerId, [
            'identity'        => $facts['identity'],
            'version'         => $facts['version'],
            'board'           => $facts['board'],
            'uptime'          => $facts['uptime'],
            'active_users'    => $facts['active_users'],
            'response_ms'     => $facts['response_ms'],
        ]);
        Alert::clear('router_offline', 'router', $routerId);

        AuditLog::record('TEST_ROUTER', 'router', $routerId,
            'Connection test ' . ($result['ok'] ? 'SUCCESS' : 'PARTIAL') . ' for ' . $router['name']
            . ($facts['identity'] ? ' (' . $facts['identity'] . ')' : ''));

        return [
            'ok'      => (bool)$result['ok'],
            'message' => $result['message'],
            'status'  => ['status' => $recorded, 'source' => 'live'] + $facts,
            'checks'  => $result['checks'],
            'facts'   => $facts,
            'causes'  => [],
        ];
    }

    /**
     * Shapes a test result for the JSON API.
     *
     * Deliberately an allow-list: only these keys ever leave the server, so a
     * credential cannot reach a response by being added to the result array
     * somewhere upstream.
     */
    public static function testPayload(array $result): array
    {
        return [
            'ok'     => (bool)($result['ok'] ?? false),
            'status' => $result['status']['status'] ?? 'unknown',
            'checks' => array_map(static fn(array $c): array => [
                'label'  => (string)$c['label'],
                'ok'     => (bool)$c['ok'],
                'detail' => (string)$c['detail'],
            ], $result['checks'] ?? []),
            'causes' => array_values($result['causes'] ?? []),
            'facts'  => [
                'identity'     => $result['facts']['identity']     ?? null,
                'version'      => $result['facts']['version']      ?? null,
                'board'        => $result['facts']['board']        ?? null,
                'uptime'       => $result['facts']['uptime']       ?? null,
                'response_ms'  => $result['facts']['response_ms']  ?? null,
                'hotspot'      => $result['facts']['hotspot']      ?? null,
                'hotspots'     => $result['facts']['hotspots']     ?? [],
                'active_users' => $result['facts']['active_users'] ?? null,
            ],
        ];
    }

    /** A refusal shaped like a test result, so callers need no special case. */
    private static function testFailure(string $message): array
    {
        return [
            'ok'      => false,
            'message' => $message,
            'status'  => [],
            'checks'  => [],
            'facts'   => [],
            'causes'  => [],
        ];
    }

    /**
     * Runs the deeper readiness check, which writes a temporary probe user.
     * Ownership and suspension are enforced here too, not just in the page.
     *
     * @return array{ok:bool,checks:array,summary:string}
     */
    public function checkReadiness(int $routerId): array
    {
        $router = $this->routers->find($routerId);
        if (!$router) {
            AuditLog::record('access_denied', 'router', $routerId,
                'Blocked a readiness check against a router outside this provider');
            return ['ok' => false, 'checks' => [], 'summary' => 'That router could not be found.'];
        }
        if ($blocked = $this->refuseWhenSuspended($router)) {
            return ['ok' => false, 'checks' => [], 'summary' => $blocked];
        }

        $withCredentials = $this->routers->withCredentials($routerId) ?? $router;
        $provider    = $this->providerFor($withCredentials);
        $diagnostics = $provider->diagnose();
        $provider->disconnect();

        AuditLog::record('CHECK_ROUTER_READINESS', 'router', $routerId,
            'Readiness check on ' . $router['name'] . ' - ' . ($diagnostics['ok'] ? 'READY' : 'NEEDS ATTENTION'));

        return $diagnostics;
    }

    /**
     * The hotspot servers a live router actually has.
     *
     * Used by the router form so an operator picks a real server instead of
     * typing "hotspot1" and hoping. An empty list is reported as an empty
     * list - WMS never invents a hotspot that is not there.
     *
     * @return array{ok:bool,servers:array,message:string,live:bool}
     */
    public function discoverHotspotServers(int $routerId): array
    {
        $router = $this->routers->find($routerId);
        if (!$router) {
            return ['ok' => false, 'servers' => [], 'live' => false, 'message' => 'That router could not be found.'];
        }
        if ($blocked = $this->refuseWhenSuspended($router)) {
            return ['ok' => false, 'servers' => [], 'live' => false, 'message' => $blocked];
        }

        $provider = $this->providerFor($router);
        if (!$provider->isLive()) {
            return [
                'ok'      => false,
                'servers' => [],
                'live'    => false,
                'message' => 'This router is in Demo mode, so its hotspot servers cannot be read. Save it in Live mode with credentials first.',
            ];
        }

        $servers  = $provider->getHotspotServers();
        $profiles = $provider->getHotspotProfiles();
        $provider->disconnect();

        if (!$servers['ok']) {
            return ['ok' => false, 'servers' => [], 'live' => true, 'message' => (string)$servers['message']];
        }

        $enabled = array_values(array_filter($servers['data'], static fn($srv) => !$srv['disabled']));

        return [
            'ok'       => true,
            'live'     => true,
            'servers'  => array_map(static fn($srv) => [
                'name'      => $srv['name'],
                'interface' => $srv['interface'],
                'profile'   => $srv['profile'],
            ], $enabled),
            'profiles' => array_column($profiles['data'] ?? [], 'name'),
            'message'  => $enabled
                ? count($enabled) . ' hotspot server(s) found.'
                : 'No RouterOS hotspot server detected on this router.',
        ];
    }

    /**
     * A suspended provider keeps its routers and its history, but may not
     * start new network operations. Returns a refusal message, or '' when
     * the operation may proceed.
     */
    private function refuseWhenSuspended(array $router): string
    {
        $providerId = $router['provider_id'] ?? null;
        if ($providerId === null) {
            return '';
        }
        $owner = (new Provider())->findUnscoped((int)$providerId);
        if ($owner && ($owner['status'] ?? 'active') === 'suspended') {
            Logger::network('Network operation refused - provider suspended', [
                'provider_id' => (int)$providerId,
                'router_id'   => (int)($router['id'] ?? 0),
            ]);
            return 'This provider is suspended, so network operations are paused. Its routers and history are untouched.';
        }
        return '';
    }

    /** Keeps the offline alert in step with the resolved status. */
    private function raiseOrClearOfflineAlert(array $router, string $status, string $reason): void
    {
        $routerId = (int)$router['id'];
        if ($status === 'offline') {
            Alert::raise('router_offline', 'danger',
                'Router ' . $router['name'] . ' is unreachable', $reason, 'router', $routerId);
        } elseif (in_array($status, ['online', 'degraded'], true)) {
            // Still inside the retry budget - not an outage yet.
            Alert::clear('router_offline', 'router', $routerId);
        }
    }

    /**
     * Polls one router and stores what it reported.
     *
     * Never throws: a router that is down, slow or misconfigured produces a
     * recorded failure, not an exception, so one bad gateway cannot take the
     * dashboard - or the other routers in the same sweep - with it.
     */
    public function pollRouter(array $router): array
    {
        $routerId = (int)$router['id'];

        try {
            $provider = $this->providerFor($router);

            if (!$provider->isLive()) {
                // Never fabricate a status for equipment we cannot reach.
                $this->routers->recordStatus($routerId, ['status' => 'unknown', 'error' => 'Demo mode']);
                return ['status' => 'unknown', 'source' => 'demo', 'ok' => false];
            }

            $status = $provider->getRouterStatus();

            if (($status['status'] ?? '') !== 'online') {
                $reason   = (string)($status['message'] ?? 'The router did not answer the status poll.');
                $resolved = $this->routers->recordUnreachable($routerId, $reason);
                $this->raiseOrClearOfflineAlert($router, $resolved, $reason);
                $provider->disconnect();
                return ['status' => $resolved, 'source' => 'live', 'ok' => false, 'message' => $reason];
            }

            $resolved = $this->routers->recordReachable($routerId, $status);
            Alert::clear('router_offline', 'router', $routerId);

            // Health alerts use the same thresholds that decide DEGRADED, so
            // the badge and the alert can never disagree.
            if ($status['cpu_load'] !== null && (int)$status['cpu_load'] >= Router::CPU_DEGRADED_PCT) {
                Alert::raise('high_cpu', 'warning', 'High CPU load on ' . $router['name'],
                    'The router reported ' . (int)$status['cpu_load'] . '% CPU load.', 'router', $routerId);
            } else {
                Alert::clear('high_cpu', 'router', $routerId);
            }
            if ($status['memory_used_pct'] !== null && (int)$status['memory_used_pct'] >= Router::MEMORY_DEGRADED_PCT) {
                Alert::raise('high_memory', 'warning', 'High memory use on ' . $router['name'],
                    'The router reported ' . (int)$status['memory_used_pct'] . '% memory used.', 'router', $routerId);
            } else {
                Alert::clear('high_memory', 'router', $routerId);
            }

            // Access points are separate devices - measured, not inferred.
            $this->syncAccessPointStatus($router, $provider);

            $provider->disconnect();
            return $status + ['status' => $resolved];
        } catch (Throwable $e) {
            /*
             * A genuinely unexpected failure. The technical detail goes to the
             * server log; the router is recorded as failing a contact attempt,
             * and the sweep continues with the next one.
             */
            Logger::error('Router poll raised an exception: ' . $e->getMessage(), [
                'router_id' => $routerId,
                'router'    => $router['name'] ?? '',
            ]);
            $resolved = $this->routers->recordUnreachable($routerId, 'The poll failed unexpectedly. See the server log.');
            return ['status' => $resolved, 'source' => 'live', 'ok' => false,
                    'message' => 'The poll failed unexpectedly. Technical details have been recorded in the server log.'];
        }
    }

    /**
     * Polls every router this request may touch.
     *
     * Each router is isolated: one failing does not stop the rest, and one
     * provider's outage never touches another provider's sweep.
     */
    public function pollAll(): array
    {
        [$scopeSql, $params] = $this->routers->scope();
        $results = [];
        foreach ($this->db->fetchAll(
            "SELECT * FROM routers WHERE status NOT IN ('disabled','retired') AND $scopeSql",
            $params
        ) as $router) {
            $results[$router['name']] = $this->pollRouter($router);
        }
        return $results;
    }

    /**
     * The scheduled sweep: poll every live router on the platform.
     *
     * Intended for cron.php, which runs outside any provider session and so
     * sees every tenant. Providers stay isolated because each router is
     * polled with its own credentials and its own result row.
     *
     * @return array{routers:int,online:int,degraded:int,failed:int,skipped:int}
     */
    public function runScheduledSync(): array
    {
        $summary = ['routers' => 0, 'online' => 0, 'degraded' => 0, 'failed' => 0, 'skipped' => 0];

        [$scopeSql, $params] = $this->routers->scope();
        $routers = $this->db->fetchAll(
            "SELECT * FROM routers WHERE mode = 'live' AND status NOT IN ('disabled','retired') AND $scopeSql",
            $params
        );

        foreach ($routers as $router) {
            $summary['routers']++;

            if (!Router::isLiveCapable($router)) {
                $summary['skipped']++;
                continue;
            }
            if ($this->refuseWhenSuspended($router) !== '') {
                $summary['skipped']++;
                continue;
            }

            $result = $this->pollRouter($router);

            // 'demo' means nothing was contacted, so it is a skip, not a
            // failure - reporting it as "not answering" would be untrue.
            if (($result['source'] ?? '') === 'demo') {
                $summary['skipped']++;
                continue;
            }

            match ($result['status'] ?? 'unknown') {
                'online'   => $summary['online']++,
                'degraded' => $summary['degraded']++,
                default    => $summary['failed']++,
            };
        }

        return $summary;
    }

    /* -------------------------------------------------------- access points */

    /**
     * The monitor that can observe a given router's access points.
     *
     * Only the router-based monitor exists today. When an SNMP or controller
     * monitor is added later, this is the single place that has to choose
     * between them.
     */
    public function monitorFor(array $router, NetworkProvider $provider): AccessPointMonitor
    {
        return new RouterBasedMonitor($provider, $router);
    }

    /**
     * Refreshes the access points hanging off one router.
     *
     * An access point is a different device from the router, so being able to
     * reach the router proves nothing about the radio. Only access points the
     * monitor could genuinely observe are updated; the rest are left as they
     * are, and any whose status has gone unverifiable become UNKNOWN rather
     * than keeping a reading nobody can confirm.
     */
    private function syncAccessPointStatus(array $router, NetworkProvider $provider): void
    {
        $routerId = (int)$router['id'];
        $records  = $this->routers->accessPoints($routerId);
        if ($records === []) {
            return;
        }

        $monitor      = $this->monitorFor($router, $provider);
        $observations = $monitor->observe($records);

        foreach ($records as $ap) {
            $id = (int)$ap['id'];

            if (!isset($observations[$id])) {
                /*
                 * Not observed. If WMS previously had a measurement from this
                 * monitor, it is now stale - say "unknown" rather than keep
                 * showing a green dot nothing is backing up. Manually entered
                 * records are left exactly as the operator set them.
                 */
                if (($ap['monitoring_source'] ?? 'manual') === $monitor->sourceKey()
                    && in_array($ap['status'], ['online', 'offline'], true)
                    && self::observationExpired($ap)) {
                    $this->accessPoints->recordUnmonitored($id, 'no longer visible through ' . $monitor->label());
                    Alert::clear('ap_offline', 'access_point', $id);
                }
                continue;
            }

            $observed = $observations[$id];
            $this->accessPoints->recordStatus(
                $id,
                $observed['status'],
                $monitor->sourceKey(),
                $observed['clients'] ?? null
            );

            if ($observed['status'] === 'online') {
                Alert::clear('ap_offline', 'access_point', $id);
            } else {
                Alert::raise('ap_offline', 'warning', 'Access point ' . $ap['name'] . ' is down',
                    (string)($observed['detail'] ?? 'The monitoring source reports it as not responding.'),
                    'access_point', $id);
            }
        }
    }

    /**
     * True when the last measurement of an access point is old enough that it
     * should no longer be presented as current.
     */
    private static function observationExpired(array $ap): bool
    {
        if (empty($ap['last_status_change_at']) && empty($ap['last_seen_at'])) {
            return true;
        }
        $last     = strtotime((string)($ap['last_seen_at'] ?: $ap['last_status_change_at']));
        $interval = max(60, (int)setting('router_poll_seconds', 60) * 5);
        return (time() - $last) > $interval;
    }

    /* -------------------------------------------------------------- access */

    /**
     * Grants network access for an activated voucher.
     * In live mode this creates a hotspot user; in demo mode it records the
     * grant locally and says so.
     *
     * @return array{ok:bool,message:string,live:bool}
     */
    public function grantVoucherAccess(array $voucher, ?array $customer = null): array
    {
        $provider = $this->providerForRouterId($voucher['router_id'] ?? null);

        $limitBytes = $voucher['data_limit_mb'] === null ? null : (int)$voucher['data_limit_mb'] * 1024 * 1024;
        $uptime     = self::routerOsDuration((int)$voucher['duration_value'], (string)$voucher['duration_unit']);

        $result = $provider->createHotspotUser([
            'name'              => $voucher['code'],
            'password'          => $voucher['code'],
            'profile'           => $this->profileNameForVoucher($voucher),
            'limit_uptime'      => $uptime,
            'limit_bytes_total' => $limitBytes,
            'comment'           => 'WMS voucher' . ($customer ? ' for ' . $customer['full_name'] : ''),
        ]);

        Logger::network('Voucher access grant', [
            'voucher' => $voucher['code'],
            'live'    => $provider->isLive(),
            'ok'      => $result['ok'],
        ]);

        return [
            'ok'      => (bool)$result['ok'],
            'message' => (string)$result['message'],
            'live'    => $provider->isLive(),
        ];
    }

    /** Removes a voucher's hotspot user (expiry, cancellation, suspension). */
    public function revokeVoucherAccess(array $voucher): array
    {
        $provider = $this->providerForRouterId($voucher['router_id'] ?? null);
        $result   = $provider->deleteHotspotUser((string)$voucher['code']);
        return ['ok' => (bool)$result['ok'], 'message' => (string)$result['message'], 'live' => $provider->isLive()];
    }

    /** Name of the router-side profile a voucher should use. */
    private function profileNameForVoucher(array $voucher): ?string
    {
        $profile = $this->db->fetchOne(
            'SELECT b.* FROM packages p
               JOIN bandwidth_profiles b ON b.id = p.bandwidth_profile_id
              WHERE p.id = ? AND p.provider_id <=> b.provider_id LIMIT 1',
            [(int)$voucher['package_id']]
        );
        if (!$profile) {
            return null;
        }
        return $profile['mikrotik_name'] ?: $profile['name'];
    }

    /** Pushes a bandwidth profile to every live router. */
    public function syncBandwidthProfile(int $profileId): array
    {
        $profile = (new BandwidthProfile())->find($profileId);
        if (!$profile) {
            return ['ok' => false, 'message' => 'That bandwidth profile no longer exists.'];
        }

        $messages = [];
        $anyLive  = false;
        [$scopeSql, $scopeParams] = $this->routers->scope();
        foreach ($this->db->fetchAll("SELECT * FROM routers WHERE mode = 'live' AND status <> 'disabled' AND $scopeSql", $scopeParams) as $router) {
            $provider = $this->providerFor($router);
            $result   = $provider->createBandwidthProfile($profile);
            $anyLive  = $anyLive || $provider->isLive();
            $messages[] = $router['name'] . ': ' . $result['message'];
            $provider->disconnect();
        }

        if ($anyLive) {
            (new BandwidthProfile())->updateById($profileId, ['synced_at' => date('Y-m-d H:i:s')]);
        }

        return [
            'ok'      => true,
            'live'    => $anyLive,
            'message' => $messages
                ? implode(' ', $messages)
                : 'No live router is configured, so the profile was saved in WMS only.',
        ];
    }

    /* ------------------------------------------------------------ overview */

    /** Aggregated figures for the dashboard network panel. */
    public function overview(): array
    {
        $routers = $this->routers->counts();
        $aps     = $this->accessPoints->counts();

        [$sessionScope, $sessionParams] = (new SessionModel())->scope();
        $activeSessions = $this->db->count("SELECT COUNT(*) FROM sessions WHERE status = 'active' AND $sessionScope", $sessionParams);
        $liveSessions   = $this->db->count("SELECT COUNT(*) FROM sessions WHERE status = 'active' AND source = 'live' AND $sessionScope", $sessionParams);
        $devicesOnline  = $this->db->count("SELECT COUNT(DISTINCT device_id) FROM sessions WHERE status = 'active' AND device_id IS NOT NULL AND $sessionScope", $sessionParams);

        $traffic = (new SessionModel())->trafficTotals(date('Y-m-d 00:00:00'), date('Y-m-d 23:59:59'));

        // Health: routers reachable out of routers expected to be reachable.
        // A degraded router is reachable, so it counts - it is just unwell.
        $expected  = max(1, $routers['live']);
        $reachable = $routers['online'] + $routers['degraded'];
        $health    = $routers['live'] > 0 ? (int)round(($reachable / $expected) * 100) : null;

        return [
            'routers'         => $routers,
            'access_points'   => $aps,
            'active_sessions' => $activeSessions,
            'live_sessions'   => $liveSessions,
            'devices_online'  => $devicesOnline,
            'download_today'  => $traffic['download'],
            'upload_today'    => $traffic['upload'],
            'health'          => $health,
            'demo'            => $this->isDemoMode(),
        ];
    }

    /**
     * Everything the Network Overview page shows.
     *
     * Every figure is either counted in the WMS database or was read from a
     * router during a sync. Nothing here is estimated: where a value has not
     * been retrieved the key is null and the page prints "Unknown".
     */
    public function networkOverview(): array
    {
        $base = $this->overview();

        [$scopeSql, $params] = $this->routers->scope();

        // Live routers whose cached readings are too old to present as current.
        $stale = 0;
        $liveRouters = $this->db->fetchAll(
            "SELECT id, mode, last_sync_at FROM routers
              WHERE mode = 'live' AND status NOT IN ('disabled','retired') AND $scopeSql",
            $params
        );
        foreach ($liveRouters as $row) {
            if (Router::isStale($row)) {
                $stale++;
            }
        }

        // Traffic totals are only meaningful when they came from live sessions.
        [$sessionScope, $sessionParams] = (new SessionModel())->scope();
        $liveTraffic = $this->db->fetchOne(
            "SELECT COALESCE(SUM(download_bytes),0) AS rx, COALESCE(SUM(upload_bytes),0) AS tx, COUNT(*) AS rows_counted
               FROM sessions
              WHERE source = 'live' AND started_at >= ? AND $sessionScope",
            array_merge([date('Y-m-d 00:00:00')], $sessionParams)
        ) ?: ['rx' => 0, 'tx' => 0, 'rows_counted' => 0];

        $alerts = 0;
        try {
            $alerts = (new Alert())->unreadCount();
        } catch (Throwable $e) {
            Logger::warning('Could not count network alerts: ' . $e->getMessage());
        }

        return $base + [
            'stale_routers'  => $stale,
            'alerts'         => $alerts,
            // null, not 0, when no live session has reported any traffic yet.
            'live_download'  => (int)$liveTraffic['rows_counted'] > 0 ? (int)$liveTraffic['rx'] : null,
            'live_upload'    => (int)$liveTraffic['rows_counted'] > 0 ? (int)$liveTraffic['tx'] : null,
            'poll_seconds'   => max(30, (int)setting('router_poll_seconds', 60)),
        ];
    }

    /* --------------------------------------------------------------- utils */

    /** Converts a package duration into a RouterOS uptime limit ("1d 00:00:00"). */
    public static function routerOsDuration(int $value, string $unit): string
    {
        $seconds = Package::durationSeconds($value, $unit);
        $days    = intdiv($seconds, 86400);
        $hours   = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $secs    = $seconds % 60;
        return ($days > 0 ? $days . 'd ' : '') . sprintf('%02d:%02d:%02d', $hours, $minutes, $secs);
    }
}
