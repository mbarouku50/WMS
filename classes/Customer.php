<?php
/**
 * WMS - Customers.
 *
 * Covers registered account holders and hotspot walk-ins created when a
 * voucher is activated with a phone number.
 */
class Customer extends Model
{
    protected string $table = 'customers';
    protected bool $tenantScoped = true;
    protected array $searchable = ['customer_code', 'full_name', 'phone', 'email', 'username'];
    protected array $sortable = ['id', 'full_name', 'created_at', 'status'];

    /** Paginated customer list with search, status, type and date filters. */
    public function search(array $filters, int $page, int $perPage = 20): array
    {
        [$scopeSql, $scopeParams] = $this->scope('c');
        $where  = [$scopeSql];
        $params = $scopeParams;

        if (!empty($filters['q'])) {
            [$clause, $p] = $this->searchClause((string)$filters['q'], 'c');
            if ($clause) {
                $where[] = $clause;
                $params  = array_merge($params, $p);
            }
        }
        foreach (['status' => 'c.status', 'customer_type' => 'c.customer_type'] as $key => $column) {
            if (!empty($filters[$key])) {
                $where[]  = "$column = ?";
                $params[] = $filters[$key];
            }
        }
        if (!empty($filters['date_from'])) {
            $where[]  = 'c.created_at >= ?';
            $params[] = $filters['date_from'] . ' 00:00:00';
        }
        if (!empty($filters['date_to'])) {
            $where[]  = 'c.created_at <= ?';
            $params[] = $filters['date_to'] . ' 23:59:59';
        }

        $whereSql = implode(' AND ', $where);
        $sort     = $this->safeOrder((string)($filters['sort'] ?? 'created_at DESC'), 'created_at DESC');

        return $this->paginate(
            "SELECT c.*,
                    (SELECT COUNT(*) FROM devices d WHERE d.customer_id = c.id) AS device_count,
                    (SELECT COUNT(*) FROM sessions s WHERE s.customer_id = c.id AND s.status = 'active') AS active_sessions,
                    (SELECT COALESCE(SUM(p.amount),0) FROM payments p WHERE p.customer_id = c.id AND p.status = 'successful') AS total_spend
               FROM customers c
              WHERE $whereSql
              ORDER BY c.$sort",
            "SELECT COUNT(*) FROM customers c WHERE $whereSql",
            $params,
            $page,
            $perPage
        );
    }

    /** Generates the next sequential customer code, e.g. CUS-00042. */
    public function nextCode(): string
    {
        $last = $this->db->fetchColumn("SELECT customer_code FROM customers WHERE customer_code LIKE 'CUS-%' ORDER BY id DESC LIMIT 1");
        $n    = $last ? (int)substr((string)$last, 4) + 1 : 1;
        do {
            $code = 'CUS-' . str_pad((string)$n, 5, '0', STR_PAD_LEFT);
            $n++;
        } while ($this->db->count('SELECT COUNT(*) FROM customers WHERE customer_code = ?', [$code]) > 0);
        return $code;
    }

    public function findByPhone(string $phone): ?array
    {
        return $this->findBy('phone', $phone);
    }

    /** Finds an existing customer by phone or creates a light hotspot record. */
    public function findOrCreateByPhone(string $phone, ?string $name = null): array
    {
        $existing = $this->findByPhone($phone);
        if ($existing) {
            return $existing;
        }
        $id = $this->create([
            'customer_code' => $this->nextCode(),
            'full_name'     => $name ?: 'Hotspot customer ' . substr($phone, -4),
            'phone'         => $phone,
            'customer_type' => 'hotspot',
            'status'        => 'active',
        ]);
        return $this->find($id) ?? [];
    }

    public function isTaken(string $column, string $value, ?int $exceptId = null): bool
    {
        $column = in_array($column, ['phone', 'email', 'username', 'customer_code'], true) ? $column : 'phone';
        [$scopeSql, $scopeParams] = $this->scope();
        $sql    = "SELECT COUNT(*) FROM customers WHERE `$column` = ? AND $scopeSql";
        $params = array_merge([$value], $scopeParams);
        if ($exceptId) {
            $sql     .= ' AND id <> ?';
            $params[] = $exceptId;
        }
        return $this->db->count($sql, $params) > 0;
    }

    /* ------------------------------------------------------------ profile */

    /** The subscription that is currently giving this customer access. */
    public function currentSubscription(int $customerId): ?array
    {
        [$scopeSql, $params] = $this->scope('s');
        return $this->db->fetchOne(
            "SELECT s.*, p.name AS package_name, p.download_kbps, p.upload_kbps
               FROM subscriptions s
               JOIN packages p ON p.id = s.package_id
              WHERE s.customer_id = ? AND s.status = 'active' AND s.end_at > NOW() AND $scopeSql
              ORDER BY s.end_at DESC LIMIT 1",
            array_merge([$customerId], $params)
        );
    }

    /** The voucher currently active for this customer, if any. */
    public function currentVoucher(int $customerId): ?array
    {
        [$scopeSql, $params] = $this->scope('v');
        return $this->db->fetchOne(
            "SELECT v.*, p.name AS package_name
               FROM vouchers v JOIN packages p ON p.id = v.package_id
              WHERE v.customer_id = ? AND v.status IN ('active','activated')
                AND (v.expires_at IS NULL OR v.expires_at > NOW()) AND $scopeSql
              ORDER BY v.expires_at DESC LIMIT 1",
            array_merge([$customerId], $params)
        );
    }

    public function devices(int $customerId): array
    {
        [$scopeSql, $params] = $this->scope('d');
        return $this->db->fetchAll(
            "SELECT d.*, r.name AS router_name, a.name AS ap_name
               FROM devices d
               LEFT JOIN routers r ON r.id = d.router_id
               LEFT JOIN access_points a ON a.id = d.access_point_id
              WHERE d.customer_id = ? AND $scopeSql ORDER BY d.last_seen_at DESC",
            array_merge([$customerId], $params)
        );
    }

    public function sessions(int $customerId, int $limit = 10): array
    {
        [$scopeSql, $params] = $this->scope('s');
        return $this->db->fetchAll(
            "SELECT s.*, d.name AS device_name, r.name AS router_name
               FROM sessions s
               LEFT JOIN devices d ON d.id = s.device_id
               LEFT JOIN routers r ON r.id = s.router_id
              WHERE s.customer_id = ? AND $scopeSql ORDER BY s.started_at DESC LIMIT " . (int)$limit,
            array_merge([$customerId], $params)
        );
    }

    public function payments(int $customerId, int $limit = 10): array
    {
        [$scopeSql, $params] = $this->scope('p');
        return $this->db->fetchAll(
            "SELECT p.*, pk.name AS package_name
               FROM payments p LEFT JOIN packages pk ON pk.id = p.package_id
              WHERE p.customer_id = ? AND $scopeSql ORDER BY p.created_at DESC LIMIT " . (int)$limit,
            array_merge([$customerId], $params)
        );
    }

    public function vouchers(int $customerId, int $limit = 10): array
    {
        [$scopeSql, $params] = $this->scope('v');
        return $this->db->fetchAll(
            "SELECT v.*, p.name AS package_name FROM vouchers v
               JOIN packages p ON p.id = v.package_id
              WHERE v.customer_id = ? AND $scopeSql ORDER BY v.activated_at DESC LIMIT " . (int)$limit,
            array_merge([$customerId], $params)
        );
    }

    /** Daily usage totals for the customer profile chart. */
    public function usageSeries(int $customerId, int $days = 14): array
    {
        return $this->db->fetchAll(
            'SELECT record_date, SUM(download_bytes) AS download, SUM(upload_bytes) AS upload
               FROM usage_records
              WHERE customer_id = ? AND record_date >= (CURDATE() - INTERVAL ? DAY)
              GROUP BY record_date ORDER BY record_date',
            [$customerId, $days]
        );
    }

    public function totals(int $customerId): array
    {
        $row = $this->db->fetchOne(
            'SELECT COALESCE(SUM(download_bytes),0) AS download, COALESCE(SUM(upload_bytes),0) AS upload,
                    COALESCE(SUM(duration_seconds),0) AS seconds, COUNT(*) AS sessions
               FROM sessions WHERE customer_id = ?',
            [$customerId]
        ) ?? [];
        $row['spend'] = (float)$this->db->fetchColumn(
            "SELECT COALESCE(SUM(amount),0) FROM payments WHERE customer_id = ? AND status = 'successful'",
            [$customerId],
            0
        );
        return $row;
    }

    public function setStatus(int $id, string $status): void
    {
        $this->updateById($id, ['status' => $status]);
    }

    public function stats(): array
    {
        return [
            'total'     => $this->countAll(),
            'active'    => $this->countAll('status = ?', ['active']),
            'suspended' => $this->countAll('status = ?', ['suspended']),
            'new_month' => $this->countAll('created_at >= DATE_FORMAT(NOW(), "%Y-%m-01")'),
        ];
    }
}
