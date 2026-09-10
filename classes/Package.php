<?php
/**
 * WMS - Internet packages.
 *
 * Everything about a package is configurable: duration, data cap, speeds and
 * how many devices may share it.  A NULL data_limit_mb means unlimited data.
 */
class Package extends Model
{
    protected string $table = 'packages';
    protected bool $tenantScoped = true;
    protected array $searchable = ['name', 'code', 'description'];
    protected array $sortable = ['id', 'name', 'price', 'sort_order', 'created_at'];

    public function search(array $filters, int $page, int $perPage = 20): array
    {
        [$scopeSql, $scopeParams] = $this->scope('p');
        $where  = [$scopeSql];
        $params = $scopeParams;

        if (!empty($filters['q'])) {
            [$clause, $p] = $this->searchClause((string)$filters['q'], 'p');
            if ($clause) {
                $where[] = $clause;
                $params  = array_merge($params, $p);
            }
        }
        if (!empty($filters['status'])) {
            $where[]  = 'p.status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['duration_unit'])) {
            $where[]  = 'p.duration_unit = ?';
            $params[] = $filters['duration_unit'];
        }
        if (($filters['data_type'] ?? '') === 'unlimited') {
            $where[] = 'p.data_limit_mb IS NULL';
        } elseif (($filters['data_type'] ?? '') === 'capped') {
            $where[] = 'p.data_limit_mb IS NOT NULL';
        }

        $whereSql = implode(' AND ', $where);

        return $this->paginate(
            "SELECT p.*, b.name AS profile_name,
                    (SELECT COUNT(*) FROM vouchers v WHERE v.package_id = p.id) AS voucher_count,
                    (SELECT COUNT(*) FROM subscriptions s WHERE s.package_id = p.id) AS subscription_count
               FROM packages p LEFT JOIN bandwidth_profiles b ON b.id = p.bandwidth_profile_id
              WHERE $whereSql ORDER BY p.sort_order ASC, p.price ASC",
            "SELECT COUNT(*) FROM packages p WHERE $whereSql",
            $params,
            $page,
            $perPage
        );
    }

    /** Active packages for dropdowns and the customer portal. */
    public function active(): array
    {
        [$scopeSql, $params] = $this->scope();
        return $this->db->fetchAll(
            "SELECT * FROM packages WHERE status = 'active' AND $scopeSql ORDER BY sort_order ASC, price ASC",
            $params
        );
    }

    /** Active packages for one provider, whoever is asking (captive portal). */
    public function activeForProvider(int $providerId): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM packages WHERE status = 'active' AND provider_id = ? ORDER BY sort_order ASC, price ASC",
            [$providerId]
        );
    }

    public function withProfile(int $id): ?array
    {
        [$scopeSql, $params] = $this->scope('p');
        return $this->db->fetchOne(
            "SELECT p.*, b.name AS profile_name, b.download_kbps AS profile_download, b.upload_kbps AS profile_upload
               FROM packages p LEFT JOIN bandwidth_profiles b ON b.id = p.bandwidth_profile_id
              WHERE p.id = ? AND $scopeSql LIMIT 1",
            array_merge([$id], $params)
        );
    }

    public function isCodeTaken(string $code, ?int $exceptId = null): bool
    {
        [$scopeSql, $scopeParams] = $this->scope();
        $sql    = "SELECT COUNT(*) FROM packages WHERE code = ? AND $scopeSql";
        $params = array_merge([$code], $scopeParams);
        if ($exceptId) {
            $sql     .= ' AND id <> ?';
            $params[] = $exceptId;
        }
        return $this->db->count($sql, $params) > 0;
    }

    /** Duration of a package expressed in seconds. */
    public static function durationSeconds(int $value, string $unit): int
    {
        return $value * match ($unit) {
            'minutes' => 60,
            'hours'   => 3600,
            'days'    => 86400,
            'months'  => 2592000,
            default   => 3600,
        };
    }

    /** The MySQL interval expression for this duration, e.g. "24 HOUR". */
    public static function mysqlInterval(int $value, string $unit): string
    {
        $keyword = match ($unit) {
            'minutes' => 'MINUTE',
            'hours'   => 'HOUR',
            'days'    => 'DAY',
            'months'  => 'MONTH',
            default   => 'HOUR',
        };
        return $value . ' ' . $keyword;
    }

    /** Short human summary, e.g. "5 GB · 24 Hours · 2 devices". */
    public static function summary(array $package): string
    {
        $parts = [
            format_mb($package['data_limit_mb'] === null ? null : (int)$package['data_limit_mb']),
            format_package_duration((int)$package['duration_value'], (string)$package['duration_unit']),
        ];
        $devices = (int)($package['device_limit'] ?? 1);
        $parts[] = $devices . ' device' . ($devices === 1 ? '' : 's');
        return implode(' · ', $parts);
    }

    /** Sales performance per package, used by reports and the dashboard. */
    public function performance(string $from, string $to, int $limit = 8): array
    {
        [$scopeSql, $scopeParams] = $this->scope('p');
        return $this->db->fetchAll(
            "SELECT p.id, p.name, p.price,
                    COUNT(pay.id) AS sales,
                    COALESCE(SUM(pay.amount),0) AS revenue
               FROM packages p
               LEFT JOIN payments pay
                      ON pay.package_id = p.id
                     AND pay.status = 'successful'
                     AND pay.created_at BETWEEN ? AND ?
              WHERE $scopeSql
              GROUP BY p.id, p.name, p.price
              ORDER BY revenue DESC, sales DESC
              LIMIT " . (int)$limit,
            array_merge([$from, $to], $scopeParams)
        );
    }

    public function stats(): array
    {
        return [
            'total'    => $this->countAll(),
            'active'   => $this->countAll('status = ?', ['active']),
            'inactive' => $this->countAll('status = ?', ['inactive']),
        ];
    }
}
