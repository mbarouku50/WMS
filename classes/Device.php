<?php
/**
 * WMS - Customer devices.
 *
 * A device is identified by its MAC address.  Device limits are enforced
 * server side by SessionService before access is granted.
 */
class Device extends Model
{
    protected string $table = 'devices';
    protected bool $tenantScoped = true;
    protected array $searchable = ['name', 'mac_address', 'ip_address'];
    protected array $sortable = ['id', 'name', 'last_seen_at', 'status'];

    public function search(array $filters, int $page, int $perPage = 25): array
    {
        [$scopeSql, $scopeParams] = $this->scope('d');
        $where  = [$scopeSql];
        $params = $scopeParams;

        if (!empty($filters['q'])) {
            $where[] = '(d.name LIKE ? OR d.mac_address LIKE ? OR d.ip_address LIKE ? OR c.full_name LIKE ?)';
            $term    = '%' . $filters['q'] . '%';
            $params  = array_merge($params, [$term, $term, $term, $term]);
        }
        foreach (['status' => 'd.status', 'device_type' => 'd.device_type', 'source' => 'd.source'] as $key => $column) {
            if (!empty($filters[$key])) {
                $where[]  = "$column = ?";
                $params[] = $filters[$key];
            }
        }
        if (!empty($filters['router_id'])) {
            $where[]  = 'd.router_id = ?';
            $params[] = (int)$filters['router_id'];
        }
        if (!empty($filters['customer_id'])) {
            $where[]  = 'd.customer_id = ?';
            $params[] = (int)$filters['customer_id'];
        }

        $whereSql = implode(' AND ', $where);

        return $this->paginate(
            "SELECT d.*, c.full_name AS customer_name, c.customer_code, r.name AS router_name, a.name AS ap_name,
                    (SELECT COUNT(*) FROM sessions s WHERE s.device_id = d.id AND s.status = 'active') AS active_sessions
               FROM devices d
               LEFT JOIN customers c ON c.id = d.customer_id
               LEFT JOIN routers r ON r.id = d.router_id
               LEFT JOIN access_points a ON a.id = d.access_point_id
              WHERE $whereSql ORDER BY d.last_seen_at DESC",
            "SELECT COUNT(*) FROM devices d LEFT JOIN customers c ON c.id = d.customer_id WHERE $whereSql",
            $params,
            $page,
            $perPage
        );
    }

    public function withRelations(int $id): ?array
    {
        [$scopeSql, $params] = $this->scope('d');
        return $this->db->fetchOne(
            "SELECT d.*, c.full_name AS customer_name, c.customer_code, r.name AS router_name, a.name AS ap_name
               FROM devices d
               LEFT JOIN customers c ON c.id = d.customer_id
               LEFT JOIN routers r ON r.id = d.router_id
               LEFT JOIN access_points a ON a.id = d.access_point_id
              WHERE d.id = ? AND $scopeSql LIMIT 1",
            array_merge([$id], $params)
        );
    }

    public function findByMac(string $mac, ?int $customerId = null): ?array
    {
        $mac = normalise_mac($mac);
        if (!$mac) {
            return null;
        }
        [$scopeSql, $params] = $this->scope();
        if ($customerId !== null) {
            return $this->db->fetchOne(
                "SELECT * FROM devices WHERE mac_address = ? AND customer_id = ? AND $scopeSql LIMIT 1",
                array_merge([$mac, $customerId], $params)
            );
        }
        return $this->db->fetchOne(
            "SELECT * FROM devices WHERE mac_address = ? AND $scopeSql ORDER BY last_seen_at DESC LIMIT 1",
            array_merge([$mac], $params)
        );
    }

    /**
     * Registers a device (or refreshes an existing one) and returns its row.
     */
    public function register(array $data): array
    {
        $mac = normalise_mac($data['mac_address'] ?? '') ?? '';
        if ($mac === '') {
            throw new InvalidArgumentException('A valid MAC address is required to register a device.');
        }

        $customerId = isset($data['customer_id']) ? (int)$data['customer_id'] : null;
        $existing   = $this->findByMac($mac, $customerId);

        $payload = [
            'provider_id'     => $data['provider_id'] ?? ProviderContext::providerId(),
            'customer_id'     => $customerId,
            'voucher_id'      => $data['voucher_id'] ?? null,
            'name'            => $data['name'] ?? ('Device ' . substr(str_replace(':', '', $mac), -4)),
            'device_type'     => $data['device_type'] ?? 'other',
            'mac_address'     => $mac,
            'ip_address'      => $data['ip_address'] ?? null,
            'router_id'       => $data['router_id'] ?? null,
            'access_point_id' => $data['access_point_id'] ?? null,
            'user_agent'      => isset($data['user_agent']) ? mb_substr((string)$data['user_agent'], 0, 255) : null,
            'source'          => $data['source'] ?? 'demo',
            'last_seen_at'    => date('Y-m-d H:i:s'),
        ];

        if ($existing) {
            // Keep what we already know: a later connection that arrives
            // without a user agent must not downgrade a recognised device
            // to "other", and a missing address must not blank a known one.
            unset($payload['name']); // the customer may have renamed it
            if ($payload['device_type'] === 'other' && $existing['device_type'] !== 'other') {
                unset($payload['device_type']);
            }
            foreach (['ip_address', 'router_id', 'access_point_id', 'user_agent', 'voucher_id'] as $key) {
                if (($payload[$key] ?? null) === null && !empty($existing[$key])) {
                    unset($payload[$key]);
                }
            }
            $this->updateById((int)$existing['id'], $payload);
            return $this->find((int)$existing['id']) ?? $existing;
        }

        $id = $this->create($payload);
        return $this->find($id) ?? [];
    }

    /** Devices currently holding an active session for a customer. */
    public function activeForCustomer(int $customerId): array
    {
        [$scopeSql, $params] = $this->scope('d');
        return $this->db->fetchAll(
            "SELECT DISTINCT d.* FROM devices d
               JOIN sessions s ON s.device_id = d.id AND s.status = 'active'
              WHERE d.customer_id = ? AND $scopeSql",
            array_merge([$customerId], $params)
        );
    }

    public function activeForVoucher(int $voucherId): array
    {
        [$scopeSql, $params] = $this->scope('d');
        return $this->db->fetchAll(
            "SELECT DISTINCT d.* FROM devices d
               JOIN sessions s ON s.device_id = d.id AND s.status = 'active'
              WHERE s.voucher_id = ? AND $scopeSql",
            array_merge([$voucherId], $params)
        );
    }

    public function setStatus(int $id, string $status): void
    {
        $this->updateById($id, ['status' => $status]);
    }

    public function history(int $deviceId, int $limit = 20): array
    {
        [$scopeSql, $params] = $this->scope('s');
        return $this->db->fetchAll(
            "SELECT s.*, r.name AS router_name FROM sessions s
               LEFT JOIN routers r ON r.id = s.router_id
              WHERE s.device_id = ? AND $scopeSql ORDER BY s.started_at DESC LIMIT " . (int)$limit,
            array_merge([$deviceId], $params)
        );
    }

    public function typeBreakdown(): array
    {
        [$scopeSql, $params] = $this->scope();
        return $this->db->fetchAll(
            "SELECT device_type, COUNT(*) AS total FROM devices WHERE $scopeSql GROUP BY device_type ORDER BY total DESC",
            $params
        );
    }

    /** Distinct devices holding an open session, within tenant scope. */
    public function onlineCount(): int
    {
        [$scopeSql, $params] = $this->scope();
        return $this->db->count(
            "SELECT COUNT(DISTINCT device_id) FROM sessions
              WHERE status = 'active' AND device_id IS NOT NULL AND $scopeSql",
            $params
        );
    }

    public function stats(): array
    {
        return [
            'total'   => $this->countAll(),
            'active'  => $this->countAll("status = 'active'"),
            'blocked' => $this->countAll("status = 'blocked'"),
            'online'  => $this->onlineCount(),
        ];
    }
}
