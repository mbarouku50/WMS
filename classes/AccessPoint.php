<?php
/**
 * WMS - Access points.
 *
 * An access point is a Wi-Fi radio, not a router. It broadcasts an SSID and
 * carries client devices to the router; it does not authenticate anybody and
 * it does not enforce bandwidth. WMS therefore records an AP, but only ever
 * reports a status it genuinely obtained from a monitoring source.
 *
 * `monitoring_source` says where a status came from:
 *
 *   router      - read through the parent MikroTik (the only source
 *                 implemented today)
 *   snmp        )
 *   controller  ) reserved for later monitors; see AccessPointMonitor
 *   vendor_api  )
 *   manual      - a human typed it. Never presented as a live measurement.
 *
 * The management IP column is `ip_address`: it is the address an operator
 * uses to administer the AP, and has nothing to do with customer addresses.
 */
class AccessPoint extends Model
{
    protected string $table = 'access_points';
    protected bool $tenantScoped = true;
    protected array $searchable = ['name', 'location', 'ip_address', 'mac_address', 'ssid'];
    protected array $sortable = ['id', 'name', 'status', 'connected_users'];

    /** Sources whose status is a real measurement rather than a human's note. */
    public const MEASURED_SOURCES = ['router', 'snmp', 'controller', 'vendor_api'];

    /** How the monitoring source is described on screen. */
    public const SOURCE_LABELS = [
        'router'     => 'Parent router',
        'snmp'       => 'SNMP',
        'controller' => 'AP controller',
        'vendor_api' => 'Vendor API',
        'manual'     => 'Not monitored',
    ];

    /* --------------------------------------------------------- scoping --- */

    /** Tenant scope plus soft deletion, matching Router. */
    public function scope(string $alias = ''): array
    {
        [$clause, $params] = parent::scope($alias);
        $prefix = $alias !== '' ? $alias . '.' : '';
        return [$clause . ' AND ' . $prefix . '`deleted_at` IS NULL', $params];
    }

    public function findWithRetired(int $id): ?array
    {
        [$clause, $params] = parent::scope();
        return $this->db->fetchOne(
            'SELECT * FROM access_points WHERE id = ? AND ' . $clause . ' LIMIT 1',
            array_merge([$id], $params)
        );
    }

    /* ---------------------------------------------------------- listing --- */

    public function search(array $filters, int $page, int $perPage = 20): array
    {
        [$scopeSql, $scopeParams] = $this->scope('a');
        $where  = [$scopeSql];
        $params = $scopeParams;

        if (!empty($filters['q'])) {
            [$clause, $p] = $this->searchClause((string)$filters['q'], 'a');
            if ($clause) {
                $where[] = $clause;
                $params  = array_merge($params, $p);
            }
        }
        if (!empty($filters['status'])) {
            $where[]  = 'a.status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['router_id'])) {
            $where[]  = 'a.router_id = ?';
            $params[] = (int)$filters['router_id'];
        }
        if (!empty($filters['location'])) {
            $where[]  = 'a.location LIKE ?';
            $params[] = '%' . $filters['location'] . '%';
        }
        $whereSql = implode(' AND ', $where);

        return $this->paginate(
            "SELECT a.*, r.name AS router_name, r.status AS router_status, r.mode AS router_mode
               FROM access_points a LEFT JOIN routers r ON r.id = a.router_id
              WHERE $whereSql ORDER BY a.name ASC",
            "SELECT COUNT(*) FROM access_points a WHERE $whereSql",
            $params,
            $page,
            $perPage
        );
    }

    /** One access point together with its router and provider names. */
    public function findDetailed(int $id): ?array
    {
        [$scopeSql, $params] = $this->scope('a');
        return $this->db->fetchOne(
            "SELECT a.*, r.name AS router_name, r.status AS router_status, r.mode AS router_mode,
                    r.ip_address AS router_address, p.business_name AS provider_name
               FROM access_points a
               LEFT JOIN routers r   ON r.id = a.router_id
               LEFT JOIN providers p ON p.id = a.provider_id
              WHERE a.id = ? AND $scopeSql LIMIT 1",
            array_merge([$id], $params)
        );
    }

    public function listAll(): array
    {
        [$scopeSql, $params] = $this->scope();
        return $this->db->fetchAll(
            "SELECT id, name, router_id, location, status FROM access_points WHERE $scopeSql ORDER BY name",
            $params
        );
    }

    /** Distinct locations, for the filter bar. */
    public function locations(): array
    {
        [$scopeSql, $params] = $this->scope();
        $rows = $this->db->fetchAll(
            "SELECT DISTINCT location FROM access_points
              WHERE location IS NOT NULL AND location <> '' AND $scopeSql ORDER BY location",
            $params
        );
        return array_column($rows, 'location');
    }

    public function counts(): array
    {
        return [
            'total'      => $this->countAll(),
            'online'     => $this->countAll("status = 'online'"),
            'offline'    => $this->countAll("status = 'offline'"),
            'unknown'    => $this->countAll("status IN ('unknown','disabled')"),
            'monitored'  => $this->countAll("monitoring_source <> 'manual'"),
        ];
    }

    /* ------------------------------------------------------- uniqueness --- */

    /**
     * True when another access point of the same provider already claims
     * this MAC address.
     *
     * One physical radio gets one record: a second row for the same MAC
     * would split its history in two and make "which AP is this client on?"
     * unanswerable. Retired records do not block the MAC, so a replaced
     * device can be re-registered.
     */
    public function isMacTaken(?string $mac, ?int $exceptId = null): bool
    {
        $mac = normalise_mac($mac);
        if ($mac === null) {
            return false;
        }
        [$scopeSql, $scopeParams] = $this->scope();
        $sql    = "SELECT COUNT(*) FROM access_points WHERE mac_address = ? AND $scopeSql";
        $params = array_merge([$mac], $scopeParams);
        if ($exceptId) {
            $sql     .= ' AND id <> ?';
            $params[] = $exceptId;
        }
        return $this->db->count($sql, $params) > 0;
    }

    /* ----------------------------------------------------- status model --- */

    /**
     * Records a status that came from a real monitoring source.
     *
     * `connectedClients` and `health` are only written when the source
     * actually supplied them - passing null leaves the stored value alone
     * rather than claiming zero clients.
     */
    public function recordStatus(
        int $id,
        string $status,
        string $source = 'router',
        ?int $connectedClients = null,
        ?int $health = null
    ): void {
        $current = $this->find($id);
        if (!$current) {
            return;
        }

        $now  = date('Y-m-d H:i:s');
        $data = [
            'status'            => in_array($status, ['online', 'offline', 'unknown'], true) ? $status : 'unknown',
            'monitoring_source' => in_array($source, self::MEASURED_SOURCES, true) ? $source : 'manual',
        ];

        if ($data['status'] !== $current['status']) {
            $data['last_status_change_at'] = $now;
        }
        if ($data['status'] === 'online') {
            $data['last_seen_at'] = $now;
        }
        if ($connectedClients !== null) {
            $data['connected_users'] = max(0, $connectedClients);
        }
        if ($health !== null) {
            $data['health'] = max(0, min(100, $health));
        }

        $this->updateById($id, $data);
    }

    /**
     * Marks an access point as no longer measurable - the monitoring source
     * went away. The status becomes UNKNOWN rather than staying on a reading
     * nobody can confirm.
     */
    public function recordUnmonitored(int $id, string $reason = ''): void
    {
        $current = $this->find($id);
        if (!$current || $current['status'] === 'unknown') {
            return;
        }
        $this->updateById($id, [
            'status'                => 'unknown',
            'last_status_change_at' => date('Y-m-d H:i:s'),
        ]);
        if ($reason !== '') {
            Logger::network('Access point status became unknown', ['ap_id' => $id, 'reason' => $reason]);
        }
    }

    /** True when this record's status is a measurement rather than a guess. */
    public static function isMeasured(array $ap): bool
    {
        return in_array($ap['monitoring_source'] ?? 'manual', self::MEASURED_SOURCES, true);
    }

    /** Human label for the monitoring source. */
    public static function sourceLabel(array $ap): string
    {
        return self::SOURCE_LABELS[$ap['monitoring_source'] ?? 'manual'] ?? 'Not monitored';
    }

    /* -------------------------------------------------------- retirement --- */

    /** Retires an access point, keeping the sessions and devices that cite it. */
    public function retire(int $id): bool
    {
        if (!$this->find($id)) {
            return false;
        }
        $this->updateById($id, [
            'status'                => 'retired',
            'deleted_at'            => date('Y-m-d H:i:s'),
            'last_status_change_at' => date('Y-m-d H:i:s'),
        ]);
        return true;
    }

    public function restore(int $id): bool
    {
        [$clause, $params] = parent::scope();
        return $this->db->update(
            'access_points',
            ['deleted_at' => null, 'status' => 'unknown'],
            'id = ? AND ' . $clause,
            array_merge([$id], $params)
        ) > 0;
    }
}
