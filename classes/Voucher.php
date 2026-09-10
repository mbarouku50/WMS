<?php
/**
 * WMS - Vouchers.
 *
 * A voucher is a prepaid access code carrying a snapshot of the package rules
 * (duration, data cap, speeds, device limit) so later package edits cannot
 * change what a customer already bought.
 *
 * Lifecycle: available -> activated -> active -> expired | exhausted
 *            (and the manual states suspended / cancelled)
 */
class Voucher extends Model
{
    protected string $table = 'vouchers';
    protected bool $tenantScoped = true;
    protected array $searchable = ['code'];
    protected array $sortable = ['id', 'code', 'status', 'created_at', 'expires_at', 'price'];

    /** Paginated voucher list with every filter the admin screen offers. */
    public function search(array $filters, int $page, int $perPage = 25): array
    {
        [$scopeSql, $scopeParams] = $this->scope('v');
        $where  = [$scopeSql];
        $params = $scopeParams;

        if (!empty($filters['q'])) {
            $where[]  = '(v.code LIKE ? OR c.full_name LIKE ? OR c.phone LIKE ?)';
            $term     = '%' . $filters['q'] . '%';
            $params   = array_merge($params, [$term, $term, $term]);
        }
        if (!empty($filters['status'])) {
            $where[]  = 'v.status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['package_id'])) {
            $where[]  = 'v.package_id = ?';
            $params[] = (int)$filters['package_id'];
        }
        if (!empty($filters['batch_id'])) {
            $where[]  = 'v.batch_id = ?';
            $params[] = (int)$filters['batch_id'];
        }
        if (!empty($filters['router_id'])) {
            $where[]  = 'v.router_id = ?';
            $params[] = (int)$filters['router_id'];
        }
        if (!empty($filters['date_from'])) {
            $where[]  = 'v.created_at >= ?';
            $params[] = $filters['date_from'] . ' 00:00:00';
        }
        if (!empty($filters['date_to'])) {
            $where[]  = 'v.created_at <= ?';
            $params[] = $filters['date_to'] . ' 23:59:59';
        }

        $whereSql = implode(' AND ', $where);
        $sort     = $this->safeOrder((string)($filters['sort'] ?? 'created_at DESC'), 'created_at DESC');

        return $this->paginate(
            "SELECT v.*, p.name AS package_name, b.batch_code, c.full_name AS customer_name, c.phone AS customer_phone
               FROM vouchers v
               JOIN packages p ON p.id = v.package_id
               LEFT JOIN voucher_batches b ON b.id = v.batch_id
               LEFT JOIN customers c ON c.id = v.customer_id
              WHERE $whereSql ORDER BY v.$sort",
            "SELECT COUNT(*) FROM vouchers v LEFT JOIN customers c ON c.id = v.customer_id WHERE $whereSql",
            $params,
            $page,
            $perPage
        );
    }

    /** Everything needed to print or inspect one voucher, tenant scoped. */
    public function withPackage(int $id): ?array
    {
        [$scopeSql, $params] = $this->scope('v');
        return $this->db->fetchOne(
            "SELECT v.*, p.name AS package_name, p.description AS package_description,
                    b.batch_code, r.name AS router_name, c.full_name AS customer_name, c.phone AS customer_phone
               FROM vouchers v
               JOIN packages p ON p.id = v.package_id
               LEFT JOIN voucher_batches b ON b.id = v.batch_id
               LEFT JOIN routers r ON r.id = v.router_id
               LEFT JOIN customers c ON c.id = v.customer_id
              WHERE v.id = ? AND $scopeSql LIMIT 1",
            array_merge([$id], $params)
        );
    }

    /**
     * The same row without tenant scope, for flows that run before any
     * context exists (a webhook issuing a voucher, the captive portal
     * showing the code it just sold). Callers own the authorisation.
     */
    public function withPackageUnscoped(int $id): ?array
    {
        return $this->db->fetchOne(
            'SELECT v.*, p.name AS package_name, p.description AS package_description,
                    b.batch_code, r.name AS router_name, c.full_name AS customer_name, c.phone AS customer_phone
               FROM vouchers v
               JOIN packages p ON p.id = v.package_id
               LEFT JOIN voucher_batches b ON b.id = v.batch_id
               LEFT JOIN routers r ON r.id = v.router_id
               LEFT JOIN customers c ON c.id = v.customer_id
              WHERE v.id = ? LIMIT 1',
            [$id]
        );
    }

    /** Finds a voucher inside the current tenant scope. */
    public function findByCode(string $code): ?array
    {
        [$scopeSql, $params] = $this->scope('v');
        return $this->db->fetchOne(
            "SELECT v.*, p.name AS package_name FROM vouchers v
               JOIN packages p ON p.id = v.package_id
              WHERE v.code = ? AND $scopeSql LIMIT 1",
            array_merge([strtoupper(trim($code))], $params)
        );
    }

    /**
     * Finds a voucher by code across every provider.
     *
     * The captive portal needs this: a customer types a code before anyone
     * is signed in, so there is no tenant context yet. The caller must then
     * treat the voucher's own provider_id as the context - which is exactly
     * what VoucherService::activate() does.
     */
    public function findByCodeAnyProvider(string $code): ?array
    {
        return $this->db->fetchOne(
            'SELECT v.*, p.name AS package_name FROM vouchers v
               JOIN packages p ON p.id = v.package_id
              WHERE v.code = ? LIMIT 1',
            [strtoupper(trim($code))]
        );
    }

    /** Voucher codes stay globally unique, so this check is deliberately unscoped. */
    public function codeExists(string $code): bool
    {
        return $this->db->count('SELECT COUNT(*) FROM vouchers WHERE code = ?', [$code]) > 0;
    }

    /** All vouchers in a batch, for printing. */
    public function byBatch(int $batchId): array
    {
        [$scopeSql, $params] = $this->scope('v');
        return $this->db->fetchAll(
            "SELECT v.*, p.name AS package_name FROM vouchers v
               JOIN packages p ON p.id = v.package_id
              WHERE v.batch_id = ? AND $scopeSql ORDER BY v.id",
            array_merge([$batchId], $params)
        );
    }

    /** Vouchers by explicit id list, for printing a selection. */
    public function byIds(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        [$scopeSql, $scopeParams] = $this->scope('v');
        return $this->db->fetchAll(
            "SELECT v.*, p.name AS package_name FROM vouchers v
               JOIN packages p ON p.id = v.package_id
              WHERE v.id IN ($placeholders) AND $scopeSql ORDER BY v.id",
            array_merge($ids, $scopeParams)
        );
    }

    /* ------------------------------------------------------------ batches */

    public function batches(array $filters, int $page, int $perPage = 20): array
    {
        // voucher_batches is aliased b in this query, not v.
        [$scopeSql, $scopeParams] = $this->scope('b');
        $where  = [$scopeSql];
        $params = $scopeParams;
        if (!empty($filters['q'])) {
            $where[]  = '(b.batch_code LIKE ? OR b.name LIKE ?)';
            $term     = '%' . $filters['q'] . '%';
            $params   = array_merge($params, [$term, $term]);
        }
        if (!empty($filters['package_id'])) {
            $where[]  = 'b.package_id = ?';
            $params[] = (int)$filters['package_id'];
        }
        $whereSql = implode(' AND ', $where);

        return $this->paginate(
            "SELECT b.*, p.name AS package_name, u.full_name AS created_by_name,
                    (SELECT COUNT(*) FROM vouchers v WHERE v.batch_id = b.id) AS total,
                    (SELECT COUNT(*) FROM vouchers v WHERE v.batch_id = b.id AND v.status = 'available') AS available,
                    (SELECT COUNT(*) FROM vouchers v WHERE v.batch_id = b.id AND v.status IN ('active','activated')) AS active,
                    (SELECT COUNT(*) FROM vouchers v WHERE v.batch_id = b.id AND v.status IN ('expired','exhausted')) AS used
               FROM voucher_batches b
               JOIN packages p ON p.id = b.package_id
               LEFT JOIN users u ON u.id = b.created_by
              WHERE $whereSql ORDER BY b.created_at DESC",
            "SELECT COUNT(*) FROM voucher_batches b WHERE $whereSql",
            $params,
            $page,
            $perPage
        );
    }

    public function batch(int $id): ?array
    {
        [$scopeSql, $params] = $this->scope('b');
        return $this->db->fetchOne(
            "SELECT b.*, p.name AS package_name FROM voucher_batches b
               JOIN packages p ON p.id = b.package_id WHERE b.id = ? AND $scopeSql LIMIT 1",
            array_merge([$id], $params)
        );
    }

    public function allBatches(): array
    {
        [$scopeSql, $params] = $this->scope();
        return $this->db->fetchAll(
            "SELECT id, batch_code, name FROM voucher_batches WHERE $scopeSql ORDER BY created_at DESC LIMIT 200",
            $params
        );
    }

    /* ---------------------------------------------------------- reporting */

    /** Counts per status, used by the dashboard and reports. */
    public function statusCounts(): array
    {
        [$scopeSql, $params] = $this->scope();
        $rows = $this->db->fetchAll("SELECT status, COUNT(*) AS total FROM vouchers WHERE $scopeSql GROUP BY status", $params);
        $out  = array_fill_keys(array_keys(WMS_VOUCHER_STATUSES), 0);
        foreach ($rows as $row) {
            $out[$row['status']] = (int)$row['total'];
        }
        $out['all'] = array_sum($out);
        return $out;
    }

    /** Vouchers whose access ends within the given number of hours. */
    public function expiringSoon(int $hours = 24, int $limit = 20): array
    {
        [$scopeSql, $params] = $this->scope('v');
        return $this->db->fetchAll(
            "SELECT v.*, p.name AS package_name, c.full_name AS customer_name
               FROM vouchers v
               JOIN packages p ON p.id = v.package_id
               LEFT JOIN customers c ON c.id = v.customer_id
              WHERE $scopeSql
                AND v.status IN ('active','activated')
                AND v.expires_at IS NOT NULL
                AND v.expires_at BETWEEN NOW() AND (NOW() + INTERVAL ? HOUR)
              ORDER BY v.expires_at ASC LIMIT " . (int)$limit,
            array_merge($params, [$hours])
        );
    }

    public function recentActivations(int $limit = 8): array
    {
        [$scopeSql, $params] = $this->scope('v');
        return $this->db->fetchAll(
            "SELECT v.code, v.activated_at, v.status, p.name AS package_name, c.full_name AS customer_name
               FROM vouchers v
               JOIN packages p ON p.id = v.package_id
               LEFT JOIN customers c ON c.id = v.customer_id
              WHERE $scopeSql AND v.activated_at IS NOT NULL
              ORDER BY v.activated_at DESC LIMIT " . (int)$limit,
            $params
        );
    }

    /** Data remaining in MB, or null when the voucher is unlimited. */
    public static function remainingData(array $voucher): ?float
    {
        if ($voucher['data_limit_mb'] === null) {
            return null;
        }
        return max(0, (float)$voucher['data_limit_mb'] - (float)$voucher['data_used_mb']);
    }

    /** Percentage of the data allowance already used (0 when unlimited). */
    public static function dataUsedPercent(array $voucher): float
    {
        if ($voucher['data_limit_mb'] === null || (float)$voucher['data_limit_mb'] <= 0) {
            return 0.0;
        }
        return min(100, percent((float)$voucher['data_used_mb'], (float)$voucher['data_limit_mb'], 1));
    }
}
