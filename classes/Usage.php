<?php
/**
 * WMS - Usage monitoring.
 *
 * usage_records holds one row per session (or per accounting update), which
 * keeps daily/monthly aggregation cheap and lets reports group by customer,
 * voucher or router.
 */
class Usage extends Model
{
    protected string $table = 'usage_records';
    protected bool $tenantScoped = true;
    protected array $sortable = ['id', 'record_date', 'total_bytes'];

    /** Named date ranges used by the usage and report filters. */
    public static function range(string $key, string $from = '', string $to = ''): array
    {
        $today = date('Y-m-d');
        return match ($key) {
            'today'     => [$today, $today],
            'yesterday' => [date('Y-m-d', strtotime('-1 day')), date('Y-m-d', strtotime('-1 day'))],
            'last7'     => [date('Y-m-d', strtotime('-6 days')), $today],
            'last30'    => [date('Y-m-d', strtotime('-29 days')), $today],
            'month'     => [date('Y-m-01'), date('Y-m-t')],
            'custom'    => [$from ?: $today, $to ?: $today],
            default     => [date('Y-m-d', strtotime('-6 days')), $today],
        };
    }

    public static function rangeLabels(): array
    {
        return [
            'today'     => 'Today',
            'yesterday' => 'Yesterday',
            'last7'     => 'Last 7 days',
            'last30'    => 'Last 30 days',
            'month'     => 'This month',
            'custom'    => 'Custom range',
        ];
    }

    /** Totals for a date range. */
    public function totals(string $from, string $to, array $filters = []): array
    {
        [$where, $params] = $this->filterClause($from, $to, $filters);
        $row = $this->db->fetchOne(
            "SELECT COALESCE(SUM(download_bytes),0) AS download,
                    COALESCE(SUM(upload_bytes),0) AS upload,
                    COALESCE(SUM(total_bytes),0) AS total,
                    COALESCE(SUM(duration_seconds),0) AS seconds,
                    COUNT(*) AS records,
                    COUNT(DISTINCT customer_id) AS customers
               FROM usage_records u WHERE $where",
            $params
        ) ?? [];

        return [
            'download'  => (float)($row['download'] ?? 0),
            'upload'    => (float)($row['upload'] ?? 0),
            'total'     => (float)($row['total'] ?? 0),
            'seconds'   => (int)($row['seconds'] ?? 0),
            'records'   => (int)($row['records'] ?? 0),
            'customers' => (int)($row['customers'] ?? 0),
        ];
    }

    /** Daily series for the usage chart. */
    public function dailySeries(string $from, string $to, array $filters = []): array
    {
        [$where, $params] = $this->filterClause($from, $to, $filters);
        return $this->db->fetchAll(
            "SELECT record_date, SUM(download_bytes) AS download, SUM(upload_bytes) AS upload, SUM(total_bytes) AS total
               FROM usage_records u WHERE $where
              GROUP BY record_date ORDER BY record_date",
            $params
        );
    }

    /** Heaviest customers in the range. */
    public function topCustomers(string $from, string $to, int $limit = 10): array
    {
        [$scopeSql, $scopeParams] = $this->scope('u');
        return $this->db->fetchAll(
            "SELECT u.customer_id, c.full_name, c.customer_code, c.phone,
                    SUM(u.download_bytes) AS download, SUM(u.upload_bytes) AS upload,
                    SUM(u.total_bytes) AS total, SUM(u.duration_seconds) AS seconds
               FROM usage_records u
               JOIN customers c ON c.id = u.customer_id
              WHERE u.record_date BETWEEN ? AND ? AND $scopeSql
              GROUP BY u.customer_id, c.full_name, c.customer_code, c.phone
              ORDER BY total DESC LIMIT " . (int)$limit,
            array_merge([$from, $to], $scopeParams)
        );
    }

    /** Heaviest vouchers in the range. */
    public function topVouchers(string $from, string $to, int $limit = 10): array
    {
        [$scopeSql, $scopeParams] = $this->scope('u');
        return $this->db->fetchAll(
            "SELECT u.voucher_id, v.code, p.name AS package_name,
                    SUM(u.total_bytes) AS total, SUM(u.duration_seconds) AS seconds
               FROM usage_records u
               JOIN vouchers v ON v.id = u.voucher_id
               JOIN packages p ON p.id = v.package_id
              WHERE u.record_date BETWEEN ? AND ? AND $scopeSql
              GROUP BY u.voucher_id, v.code, p.name
              ORDER BY total DESC LIMIT " . (int)$limit,
            array_merge([$from, $to], $scopeParams)
        );
    }

    /** Paginated raw usage list. */
    public function search(array $filters, int $page, int $perPage = 25): array
    {
        [$from, $to] = self::range($filters['range'] ?? 'last7', $filters['date_from'] ?? '', $filters['date_to'] ?? '');
        [$where, $params] = $this->filterClause($from, $to, $filters);

        return $this->paginate(
            "SELECT u.*, c.full_name AS customer_name, c.customer_code, v.code AS voucher_code
               FROM usage_records u
               LEFT JOIN customers c ON c.id = u.customer_id
               LEFT JOIN vouchers v ON v.id = u.voucher_id
              WHERE $where ORDER BY u.record_date DESC, u.id DESC",
            "SELECT COUNT(*) FROM usage_records u WHERE $where",
            $params,
            $page,
            $perPage
        );
    }

    /** Records usage for a finished (or updating) session. */
    public function record(array $data): int
    {
        $download = (int)($data['download_bytes'] ?? 0);
        $upload   = (int)($data['upload_bytes'] ?? 0);
        return $this->create([
            'provider_id'      => $data['provider_id'] ?? ProviderContext::providerId(),
            'customer_id'      => $data['customer_id'] ?? null,
            'voucher_id'       => $data['voucher_id'] ?? null,
            'subscription_id'  => $data['subscription_id'] ?? null,
            'session_id'       => $data['session_id'] ?? null,
            'router_id'        => $data['router_id'] ?? null,
            'record_date'      => $data['record_date'] ?? date('Y-m-d'),
            'download_bytes'   => $download,
            'upload_bytes'     => $upload,
            'total_bytes'      => $download + $upload,
            'duration_seconds' => (int)($data['duration_seconds'] ?? 0),
            'source'           => $data['source'] ?? 'demo',
        ]);
    }

    /** Total bytes a customer has used this month. */
    public function monthlyForCustomer(int $customerId): float
    {
        return (float)$this->db->fetchColumn(
            'SELECT COALESCE(SUM(total_bytes),0) FROM usage_records
              WHERE customer_id = ? AND record_date >= DATE_FORMAT(CURDATE(), "%Y-%m-01")',
            [$customerId],
            0
        );
    }

    /** Devices or customers whose daily usage crossed the alert threshold. */
    public function heavyUsersToday(float $thresholdGb): array
    {
        $bytes = (int)($thresholdGb * 1024 * 1024 * 1024);
        // Unscoped by design: the alerting job sweeps the whole platform and
        // each row carries its own provider_id.
        return $this->db->fetchAll(
            'SELECT u.customer_id, u.provider_id, c.full_name, SUM(u.total_bytes) AS total
               FROM usage_records u JOIN customers c ON c.id = u.customer_id
              WHERE u.record_date = CURDATE()
              GROUP BY u.customer_id, u.provider_id, c.full_name
             HAVING total >= ?
              ORDER BY total DESC',
            [$bytes]
        );
    }

    /** @return array{0:string,1:array} WHERE fragment plus bound parameters */
    private function filterClause(string $from, string $to, array $filters): array
    {
        [$scopeSql, $scopeParams] = $this->scope('u');
        $where  = ['u.record_date BETWEEN ? AND ?', $scopeSql];
        $params = array_merge([$from, $to], $scopeParams);

        if (!empty($filters['customer_id'])) {
            $where[]  = 'u.customer_id = ?';
            $params[] = (int)$filters['customer_id'];
        }
        if (!empty($filters['voucher_id'])) {
            $where[]  = 'u.voucher_id = ?';
            $params[] = (int)$filters['voucher_id'];
        }
        if (!empty($filters['router_id'])) {
            $where[]  = 'u.router_id = ?';
            $params[] = (int)$filters['router_id'];
        }
        if (!empty($filters['source'])) {
            $where[]  = 'u.source = ?';
            $params[] = $filters['source'];
        }
        return [implode(' AND ', $where), $params];
    }
}
