<?php
/**
 * WMS - Routers (MikroTik or otherwise).
 *
 * The application supports many routers.  API credentials are stored
 * encrypted and are never sent to the browser - not in HTML, not in JSON.
 *
 * A router in "demo" mode has no credentials and reports no live figures; a
 * router in "live" mode is polled through NetworkService.
 */
class Router extends Model
{
    protected string $table = 'routers';
    protected bool $tenantScoped = true;
    protected array $searchable = ['name', 'ip_address', 'location', 'identity'];
    protected array $sortable = ['id', 'name', 'status', 'last_seen_at'];

    /**
     * How many consecutive failed contacts are tolerated before a router
     * that used to answer is called Offline. A single API timeout is a
     * blip, not an outage.
     */
    public const FAILURE_THRESHOLD = 3;

    /** Health thresholds above which a reachable router is DEGRADED. */
    public const CPU_DEGRADED_PCT    = 85;
    public const MEMORY_DEGRADED_PCT = 90;

    /** A live reply slower than this is treated as degraded, in ms. */
    public const SLOW_RESPONSE_MS = 3000;

    /* --------------------------------------------------------- scoping --- */

    /**
     * Tenant scope plus soft deletion.
     *
     * Retiring a router keeps its sessions, vouchers, payments and audit
     * trail readable while removing it from every working list, so the
     * whole application stops seeing it in one place.
     */
    public function scope(string $alias = ''): array
    {
        [$clause, $params] = parent::scope($alias);
        $prefix = $alias !== '' ? $alias . '.' : '';
        return [$clause . ' AND ' . $prefix . '`deleted_at` IS NULL', $params];
    }

    /** Finds a router including retired ones - for historical screens. */
    public function findWithRetired(int $id): ?array
    {
        [$clause, $params] = parent::scope();
        return $this->db->fetchOne(
            'SELECT * FROM routers WHERE id = ? AND ' . $clause . ' LIMIT 1',
            array_merge([$id], $params)
        );
    }

    public function search(array $filters, int $page, int $perPage = 20): array
    {
        /*
         * parent::scope() plus an explicit soft-delete clause, so the one
         * filter that is *about* retired routers can ask for them. Every
         * other call still sees only working routers.
         */
        [$scopeSql, $scopeParams] = parent::scope('r');
        $where  = [$scopeSql, ($filters['status'] ?? '') === 'retired'
            ? 'r.deleted_at IS NOT NULL'
            : 'r.deleted_at IS NULL'];
        $params = $scopeParams;

        if (!empty($filters['q'])) {
            [$clause, $p] = $this->searchClause((string)$filters['q'], 'r');
            if ($clause) {
                $where[] = $clause;
                $params  = array_merge($params, $p);
            }
        }
        foreach (['status' => 'r.status', 'mode' => 'r.mode'] as $key => $column) {
            if (!empty($filters[$key])) {
                $where[]  = "$column = ?";
                $params[] = $filters[$key];
            }
        }
        if (!empty($filters['location'])) {
            $where[]  = 'r.location LIKE ?';
            $params[] = '%' . $filters['location'] . '%';
        }
        /*
         * Only a platform administrator can narrow by provider: for anybody
         * else the tenant scope above has already decided the answer, and
         * an extra provider_id here could only ever be their own.
         */
        if (!empty($filters['provider_id']) && ProviderContext::isGlobalScope()) {
            $where[]  = 'r.provider_id = ?';
            $params[] = (int)$filters['provider_id'];
        }
        $whereSql = implode(' AND ', $where);

        /*
         * The dependency tallies ride along as subqueries rather than a
         * dependencyCounts() call per card: the listing needs them for every
         * row it draws, and asking row by row would be six queries a router.
         */
        return $this->paginate(
            "SELECT r.*,
                    (SELECT COUNT(*) FROM access_points a WHERE a.router_id = r.id) AS ap_count,
                    (SELECT COUNT(*) FROM sessions s WHERE s.router_id = r.id AND s.status = 'active') AS live_sessions,
                    (SELECT COUNT(*) FROM sessions s WHERE s.router_id = r.id) AS session_count,
                    (SELECT COUNT(*) FROM vouchers v WHERE v.router_id = r.id) AS voucher_count,
                    (SELECT COUNT(*) FROM voucher_batches b WHERE b.router_id = r.id) AS batch_count,
                    (SELECT COUNT(*) FROM devices d WHERE d.router_id = r.id) AS device_count,
                    (SELECT COUNT(*) FROM usage_records u WHERE u.router_id = r.id) AS usage_count
               FROM routers r WHERE $whereSql ORDER BY r.name ASC",
            "SELECT COUNT(*) FROM routers r WHERE $whereSql",
            $params,
            $page,
            $perPage
        );
    }

    /** All routers - safe for dropdowns (no credential columns selected). */
    public function listAll(): array
    {
        [$scopeSql, $params] = $this->scope();
        return $this->db->fetchAll(
            "SELECT id, name, ip_address, status, mode, location, provider_id, hotspot_server
               FROM routers WHERE $scopeSql ORDER BY name",
            $params
        );
    }

    /** Routers belonging to one provider (Super Admin assignment screens). */
    public function forProvider(int $providerId): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM routers WHERE provider_id = ? AND deleted_at IS NULL ORDER BY name',
            [$providerId]
        );
    }

    /**
     * Resolves which provider owns the router serving a given client IP or
     * router address. Used by the captive portal, where nobody is signed in
     * yet, to decide which provider's packages to show.
     */
    public function findByAddress(string $ipAddress): ?array
    {
        return $this->db->fetchOne(
            'SELECT * FROM routers WHERE ip_address = ? AND deleted_at IS NULL LIMIT 1',
            [$ipAddress]
        );
    }

    /**
     * Returns the row with the API password decrypted.
     * Server side only - never pass the result into a template.
     */
    public function withCredentials(int $id): ?array
    {
        // find() is tenant scoped, so another provider's router never
        // reaches the decryption step at all.
        $row = $this->find($id);
        if (!$row) {
            return null;
        }
        $row['api_password_plain'] = Crypto::decrypt($row['api_password'] ?? null);
        return $row;
    }

    /** Stores the API password encrypted. */
    public function setPassword(int $id, string $password): void
    {
        $this->updateById($id, ['api_password' => Crypto::encrypt($password)]);
    }

    /** True when this router has enough configuration to be polled live. */
    public static function isLiveCapable(array $router): bool
    {
        return ($router['mode'] ?? 'demo') === 'live'
            && !empty($router['ip_address'])
            && !empty($router['api_username'])
            && !empty($router['api_password']);
    }

    /* ----------------------------------------------------- status model --- */

    /**
     * Records the outcome of a successful poll.
     *
     * Only values the router actually returned are written; anything it did
     * not report stays NULL so the UI can say "Unknown" rather than show a
     * fabricated zero.
     *
     * The resulting status is ONLINE, or DEGRADED when a health metric the
     * router did report crosses its threshold.
     */
    public function recordReachable(int $id, array $status): string
    {
        $now      = date('Y-m-d H:i:s');
        $resolved = self::healthStatus($status);

        $data = [
            'status'        => $resolved,
            'last_seen_at'  => $now,
            'last_sync_at'  => $now,
            'failed_checks' => 0,
            'last_error'    => null,
        ];

        /*
         * The active-user count is only written when the router actually
         * returned one. If the hotspot table could not be read, the previous
         * figure is left alone rather than being reset to a zero that would
         * read as "nobody is online".
         */
        if (array_key_exists('active_users', $status) && $status['active_users'] !== null) {
            $data['active_users'] = max(0, (int)$status['active_users']);
        }

        // Written only when present: a missing reading must not overwrite a
        // good one with NULL, and must never be invented as 0.
        $optional = [
            'identity'         => 'identity',
            'version'          => 'routeros_version',
            'board'            => 'board',
            'uptime'           => 'uptime',
            'cpu_load'         => 'cpu_load',
            'memory_used_pct'  => 'memory_used_pct',
        ];
        foreach ($optional as $key => $column) {
            if (array_key_exists($key, $status) && $status[$key] !== null && $status[$key] !== '') {
                $data[$column] = in_array($column, ['cpu_load', 'memory_used_pct'], true)
                    ? max(0, min(255, (int)$status[$key]))
                    : mb_substr((string)$status[$key], 0, 120);
            }
        }

        $this->updateById($id, $data);
        return $resolved;
    }

    /**
     * Records a failed contact attempt.
     *
     * last_seen_at is deliberately left alone - "last seen 17 minutes ago"
     * is exactly the information an operator needs about a router that has
     * just stopped answering.
     *
     * The router only becomes OFFLINE once the failures pass the retry
     * threshold; before that it keeps its previous status. A router that
     * has never answered at all stays UNKNOWN, because "offline" would
     * claim knowledge we do not have.
     *
     * @return string the status now stored
     */
    public function recordUnreachable(int $id, string $error): string
    {
        $row = $this->find($id);
        if (!$row) {
            return 'unknown';
        }

        $failures = (int)($row['failed_checks'] ?? 0) + 1;
        $everSeen = !empty($row['last_seen_at']);

        if ($failures < self::FAILURE_THRESHOLD && in_array($row['status'], ['online', 'degraded'], true)) {
            // Within the retry budget: keep the last known status, but do
            // record why the attempt failed.
            $status = $row['status'];
        } else {
            $status = $everSeen ? 'offline' : 'unknown';
        }

        $this->updateById($id, [
            'status'        => $status,
            'failed_checks' => min(9999, $failures),
            'last_error'    => mb_substr($error, 0, 255),
        ]);

        return $status;
    }

    /**
     * Compatibility shim for callers that hand over a whole status array.
     * Routes to the reachable/unreachable paths above.
     */
    public function recordStatus(int $id, array $status): void
    {
        if (($status['status'] ?? '') === 'online' || ($status['status'] ?? '') === 'degraded') {
            $this->recordReachable($id, $status);
            return;
        }
        if (($status['status'] ?? '') === 'unknown') {
            // Demo mode, or nothing was attempted: no failure to count.
            $this->updateById($id, [
                'status'     => 'unknown',
                'last_error' => isset($status['error']) ? mb_substr((string)$status['error'], 0, 255) : null,
            ]);
            return;
        }
        $this->recordUnreachable($id, (string)($status['error'] ?? $status['message'] ?? 'The router did not answer.'));
    }

    /** ONLINE, or DEGRADED when a reported health metric is over threshold. */
    public static function healthStatus(array $status): string
    {
        $cpu    = $status['cpu_load'] ?? null;
        $memory = $status['memory_used_pct'] ?? null;
        $ms     = $status['response_ms'] ?? null;

        if ($cpu !== null && (int)$cpu >= self::CPU_DEGRADED_PCT) {
            return 'degraded';
        }
        if ($memory !== null && (int)$memory >= self::MEMORY_DEGRADED_PCT) {
            return 'degraded';
        }
        if ($ms !== null && (int)$ms >= self::SLOW_RESPONSE_MS) {
            return 'degraded';
        }
        return 'online';
    }

    /** Why a router is degraded, in words. Empty when it is not. */
    public static function degradedReason(array $router): string
    {
        $reasons = [];
        if ($router['cpu_load'] !== null && (int)$router['cpu_load'] >= self::CPU_DEGRADED_PCT) {
            $reasons[] = 'CPU at ' . (int)$router['cpu_load'] . '%';
        }
        if ($router['memory_used_pct'] !== null && (int)$router['memory_used_pct'] >= self::MEMORY_DEGRADED_PCT) {
            $reasons[] = 'memory at ' . (int)$router['memory_used_pct'] . '%';
        }
        return implode(', ', $reasons);
    }

    /**
     * True when the cached readings are older than the poll interval allows,
     * so the UI must stop presenting them as current.
     */
    public static function isStale(array $router): bool
    {
        if (($router['mode'] ?? 'demo') !== 'live') {
            return false;                       // demo rows are never "stale"
        }
        if (empty($router['last_sync_at'])) {
            return true;
        }
        $interval = max(30, (int)setting('router_poll_seconds', 60));
        return (time() - strtotime((string)$router['last_sync_at'])) > ($interval * 3);
    }

    /** Seconds since the last successful sync, or null when never synced. */
    public static function secondsSinceSync(array $router): ?int
    {
        return empty($router['last_sync_at'])
            ? null
            : max(0, time() - strtotime((string)$router['last_sync_at']));
    }

    /* -------------------------------------------------------- retirement --- */

    /**
     * Retires a router instead of deleting it.
     *
     * Sessions, vouchers, payments, usage and audit rows keep pointing at a
     * record that still exists, so no history is lost. Its access points
     * are retired with it: they are no longer reachable through it.
     */
    public function retire(int $id): bool
    {
        $row = $this->find($id);
        if (!$row) {
            return false;
        }

        $now = date('Y-m-d H:i:s');
        $this->updateById($id, [
            'status'     => 'retired',
            'mode'       => 'demo',            // never contacted again
            'deleted_at' => $now,
        ]);

        // Access points hang off the router; retiring one retires them too.
        [$scopeSql, $params] = parent::scope();
        $this->db->execute(
            "UPDATE access_points SET status = 'retired', deleted_at = ?
              WHERE router_id = ? AND deleted_at IS NULL AND $scopeSql",
            array_merge([$now, $id], $params)
        );

        return true;
    }

    /**
     * Brings a retired router back, in Demo mode and unknown state - nothing
     * has been contacted since it was retired, so no reading is carried over.
     *
     * retire() stamps the router and its access points with the same
     * deleted_at, so that timestamp identifies the access points that went
     * down with this router. Only those come back; one retired on its own
     * beforehand stays retired.
     */
    public function restore(int $id): bool
    {
        $row = $this->findWithRetired($id);
        if (!$row || $row['deleted_at'] === null) {
            return false;
        }

        [$clause, $params] = parent::scope();
        $restored = $this->db->update(
            'routers',
            ['deleted_at' => null, 'status' => 'unknown', 'failed_checks' => 0, 'last_error' => null],
            'id = ? AND ' . $clause,
            array_merge([$id], $params)
        ) > 0;

        if ($restored) {
            $this->db->execute(
                "UPDATE access_points SET status = 'unknown', deleted_at = NULL
                  WHERE router_id = ? AND deleted_at = ? AND " . $clause,
                array_merge([$id, $row['deleted_at']], $params)
            );
        }

        return $restored;
    }

    /* --------------------------------------------------- hard deletion --- */

    /**
     * What a permanent delete would touch, for the operator to read first.
     *
     * Nothing here is destroyed by the delete except the access points: the
     * five foreign keys pointing at routers are ON DELETE SET NULL, so the
     * sessions, vouchers, batches and devices survive and only stop naming
     * which router they happened on. usage_records carries no foreign key at
     * all, so deletePermanently() clears it by hand.
     *
     * @return array{access_points:int,sessions:int,vouchers:int,batches:int,devices:int,usage:int}
     */
    public function dependencyCounts(int $id): array
    {
        [$clause, $params] = parent::scope();
        $count = fn (string $table): int => $this->db->count(
            'SELECT COUNT(*) FROM `' . $table . '` WHERE router_id = ? AND ' . $clause,
            array_merge([$id], $params)
        );

        return [
            'access_points' => $count('access_points'),
            'sessions'      => $count('sessions'),
            'vouchers'      => $count('vouchers'),
            'batches'       => $count('voucher_batches'),
            'devices'       => $count('devices'),
            'usage'         => $count('usage_records'),
        ];
    }

    /**
     * Deletes a router for good - row and all. Retire() is the reversible
     * option; this is the one that is not.
     *
     * findWithRetired(), so a retired router can be cleared out as easily as a
     * working one, and so another provider's id still resolves to nothing.
     *
     * Business history is detached, not deleted - the foreign keys set
     * router_id to NULL - so payments, vouchers, sessions and usage stay
     * auditable and only stop naming the router. Two things go with it because
     * they mean nothing without it: its access points, which are physically
     * part of the router, and the alerts raised about it, which nobody could
     * act on again.
     *
     * @return bool false when the id is not one of this provider's routers, or
     *              when the delete failed (the reason is logged)
     */
    public function deletePermanently(int $id): bool
    {
        $row = $this->findWithRetired($id);
        if (!$row) {
            return false;
        }

        [$clause, $params] = parent::scope();
        $providerId = $row['provider_id'] === null ? null : (int)$row['provider_id'];
        $apIds = array_column($this->db->fetchAll(
            'SELECT id FROM access_points WHERE router_id = ? AND ' . $clause,
            array_merge([$id], $params)
        ), 'id');

        $this->db->beginTransaction();
        try {
            // Nothing else would clear this: usage_records.router_id has no
            // foreign key, so the rows would keep citing an id that is gone.
            $this->db->execute(
                'UPDATE usage_records SET router_id = NULL WHERE router_id = ? AND ' . $clause,
                array_merge([$id], $params)
            );

            foreach ($apIds as $apId) {
                $this->db->execute(
                    'DELETE FROM alerts WHERE source_type = ? AND source_id = ? AND (provider_id <=> ?)',
                    ['access_point', (int)$apId, $providerId]
                );
            }
            $this->db->execute(
                'DELETE FROM alerts WHERE source_type = ? AND source_id = ? AND (provider_id <=> ?)',
                ['router', $id, $providerId]
            );

            // An access point is part of a router; it cannot outlive one.
            $this->db->execute(
                'DELETE FROM access_points WHERE router_id = ? AND ' . $clause,
                array_merge([$id], $params)
            );

            $deleted = $this->db->delete('routers', 'id = ? AND ' . $clause, array_merge([$id], $params)) > 0;
            $this->db->commit();
            return $deleted;
        } catch (Throwable $e) {
            $this->db->rollback();
            /*
             * Reported, not rethrown: an uncaught exception here would reach
             * the operator as a blank HTTP 500, which says nothing at all.
             * The caller turns false into a message it can act on.
             */
            Logger::error('Permanent router delete failed: ' . $e->getMessage(), [
                'router_id' => $id, 'router_name' => $row['name'],
            ]);
            return false;
        }
    }

    public function counts(): array
    {
        return [
            'total'    => $this->countAll(),
            'online'   => $this->countAll("status = 'online'"),
            'degraded' => $this->countAll("status = 'degraded'"),
            'offline'  => $this->countAll("status = 'offline'"),
            'unknown'  => $this->countAll("status IN ('unknown','disabled')"),
            'live'     => $this->countAll("mode = 'live'"),
            'demo'     => $this->countAll("mode = 'demo'"),
            'retired'  => $this->retiredCount(),
        ];
    }

    /**
     * Retired routers. countAll() cannot answer this: Router::scope() filters
     * them out, which is the whole point of it everywhere else.
     */
    public function retiredCount(): int
    {
        [$clause, $params] = parent::scope();
        return $this->db->count(
            'SELECT COUNT(*) FROM routers WHERE deleted_at IS NOT NULL AND ' . $clause,
            $params
        );
    }

    public function accessPoints(int $routerId): array
    {
        [$scopeSql, $params] = $this->scope();
        return $this->db->fetchAll(
            "SELECT * FROM access_points WHERE router_id = ? AND deleted_at IS NULL AND $scopeSql ORDER BY name",
            array_merge([$routerId], $params)
        );
    }

    /**
     * The retired router holding a name, when that is what is blocking it.
     *
     * Lets the caller tell the two cases apart: another working router has
     * the name (pick a different one), or a retired one still reserves it
     * and restoring it brings back the router and its history.
     */
    public function findRetiredByName(string $name): ?array
    {
        [$clause, $params] = parent::scope();
        return $this->db->fetchOne(
            'SELECT * FROM routers WHERE name = ? AND deleted_at IS NOT NULL AND ' . $clause . ' LIMIT 1',
            array_merge([$name], $params)
        );
    }

    /**
     * Is this name already used by one of this provider's routers?
     *
     * parent::scope() on purpose, not $this->scope(): the check has to match
     * uq_routers_provider_name, which covers every row in the table including
     * retired ones. Filtering retired routers out here reported a free name
     * and then let the INSERT fail on the index - a raw duplicate-key
     * exception, which reaches the operator as HTTP 500.
     */
    public function isNameTaken(string $name, ?int $exceptId = null): bool
    {
        // Router names stay unique per provider, not across the platform.
        [$scopeSql, $scopeParams] = parent::scope();
        $sql    = "SELECT COUNT(*) FROM routers WHERE name = ? AND $scopeSql";
        $params = array_merge([$name], $scopeParams);
        if ($exceptId) {
            $sql     .= ' AND id <> ?';
            $params[] = $exceptId;
        }
        return $this->db->count($sql, $params) > 0;
    }
}
