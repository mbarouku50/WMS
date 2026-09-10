<?php
/**
 * WMS - Reporting.
 *
 * One place that builds every business report, so the on-screen report, the
 * printed version and the CSV export always agree with each other.
 */
class ReportService
{
    private Database $db;

    /**
     * Optional provider filter for a platform administrator looking at one
     * tenant. A provider user never sets this: their own scope is already
     * applied by the models underneath.
     */
    private ?int $providerFilter = null;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /** Narrows every figure to one provider (platform scope only). */
    public function forProvider(?int $providerId): self
    {
        $this->providerFilter = ProviderContext::isGlobalScope() ? $providerId : null;
        return $this;
    }

    /**
     * Extra WHERE fragment for the raw queries in this class.
     *
     * Two layers are at work: the models apply the tenant scope for a
     * provider user, and this adds the optional per-provider filter a
     * platform administrator chose.
     *
     * @return array{0:string,1:array}
     */
    private function scope(string $alias = ''): array
    {
        $prefix = $alias !== '' ? $alias . '.' : '';

        if ($this->providerFilter !== null) {
            return [$prefix . 'provider_id = ?', [$this->providerFilter]];
        }

        $contextId = ProviderContext::providerId();
        if ($contextId !== null) {
            return [$prefix . 'provider_id = ?', [$contextId]];
        }
        return ['1=1', []];
    }

    /** The reports offered by admin/reports/index.php. */
    public static function types(): array
    {
        return [
            'revenue'   => 'Revenue',
            'customers' => 'Customers',
            'vouchers'  => 'Vouchers',
            'network'   => 'Network',
            'usage'     => 'Usage',
        ];
    }

    /**
     * Builds a report.
     *
     * @return array{title:string,summary:array,columns:array,rows:array,chart:array,note:string}
     */
    public function build(string $type, string $from, string $to): array
    {
        $fromDt = $from . ' 00:00:00';
        $toDt   = $to . ' 23:59:59';

        return match ($type) {
            'customers' => $this->customers($fromDt, $toDt),
            'vouchers'  => $this->vouchers($fromDt, $toDt),
            'network'   => $this->network($fromDt, $toDt),
            'usage'     => $this->usage($from, $to),
            default     => $this->revenue($fromDt, $toDt),
        };
    }

    /* ------------------------------------------------------------ revenue */

    private function revenue(string $from, string $to): array
    {
        [$scopeSql, $scopeParams] = $this->scope();
        $rows = $this->db->fetchAll(
            "SELECT DATE(created_at) AS day,
                    COUNT(CASE WHEN status = 'successful' THEN 1 END) AS sales,
                    COALESCE(SUM(CASE WHEN status = 'successful' THEN amount END),0) AS revenue,
                    COUNT(CASE WHEN status = 'failed' THEN 1 END) AS failed,
                    COUNT(CASE WHEN status = 'pending' THEN 1 END) AS pending
               FROM payments WHERE created_at BETWEEN ? AND ? AND $scopeSql
              GROUP BY DATE(created_at) ORDER BY day",
            array_merge([$from, $to], $scopeParams)
        );

        $totals = $this->db->fetchOne(
            "SELECT COALESCE(SUM(CASE WHEN status='successful' THEN amount END),0) AS revenue,
                    COUNT(CASE WHEN status='successful' THEN 1 END) AS sales,
                    COUNT(CASE WHEN status='failed' THEN 1 END) AS failed,
                    COUNT(CASE WHEN status='refunded' THEN 1 END) AS refunded,
                    COALESCE(AVG(CASE WHEN status='successful' THEN amount END),0) AS average
               FROM payments WHERE created_at BETWEEN ? AND ? AND $scopeSql",
            array_merge([$from, $to], $scopeParams)
        ) ?? [];

        $byPackage = (new Package())->performance($from, $to, 10);

        return [
            'title'   => 'Revenue report',
            'summary' => [
                ['label' => 'Total revenue',    'value' => money((float)($totals['revenue'] ?? 0)), 'tone' => 'success'],
                ['label' => 'Successful sales', 'value' => number_format((int)($totals['sales'] ?? 0)), 'tone' => 'info'],
                ['label' => 'Average sale',     'value' => money((float)($totals['average'] ?? 0)), 'tone' => 'neutral'],
                ['label' => 'Failed payments',  'value' => number_format((int)($totals['failed'] ?? 0)), 'tone' => 'danger'],
            ],
            'columns' => ['Date', 'Sales', 'Revenue', 'Failed', 'Pending'],
            'rows'    => array_map(static fn($r) => [
                format_date($r['day'], 'd M Y'),
                (int)$r['sales'],
                money((float)$r['revenue']),
                (int)$r['failed'],
                (int)$r['pending'],
            ], $rows),
            'chart'   => [
                'type'   => 'bar',
                'labels' => array_map(static fn($r) => date('d M', strtotime((string)$r['day'])), $rows),
                'series' => [['name' => 'Revenue', 'data' => array_map(static fn($r) => (float)$r['revenue'], $rows)]],
            ],
            'extra'   => ['Top packages' => array_map(static fn($p) => [
                $p['name'], (int)$p['sales'], money((float)$p['revenue']),
            ], $byPackage)],
            'extra_columns' => ['Top packages' => ['Package', 'Sales', 'Revenue']],
            'note'    => 'Revenue counts payments with status "successful" only.',
        ];
    }

    /* ---------------------------------------------------------- customers */

    private function customers(string $from, string $to): array
    {
        [$scopeSql, $scopeParams] = $this->scope();
        $rows = $this->db->fetchAll(
            "SELECT DATE(created_at) AS day, COUNT(*) AS new_customers
               FROM customers WHERE created_at BETWEEN ? AND ? AND $scopeSql
              GROUP BY DATE(created_at) ORDER BY day",
            array_merge([$from, $to], $scopeParams)
        );

        $stats = $this->db->fetchOne(
            "SELECT COUNT(*) AS total,
                    COUNT(CASE WHEN status='active' THEN 1 END) AS active,
                    COUNT(CASE WHEN status='suspended' THEN 1 END) AS suspended,
                    COUNT(CASE WHEN created_at BETWEEN ? AND ? THEN 1 END) AS new_in_range
               FROM customers WHERE $scopeSql",
            array_merge([$from, $to], $scopeParams)
        ) ?? [];

        [$custScope, $custParams] = $this->scope('c');
        $top = $this->db->fetchAll(
            "SELECT c.full_name, c.customer_code, c.phone,
                    COUNT(p.id) AS purchases, COALESCE(SUM(p.amount),0) AS spend
               FROM customers c
               JOIN payments p ON p.customer_id = c.id AND p.status='successful' AND p.created_at BETWEEN ? AND ?
              WHERE $custScope
              GROUP BY c.id, c.full_name, c.customer_code, c.phone
              ORDER BY spend DESC LIMIT 10",
            array_merge([$from, $to], $custParams)
        );

        return [
            'title'   => 'Customer report',
            'summary' => [
                ['label' => 'New customers', 'value' => number_format((int)($stats['new_in_range'] ?? 0)), 'tone' => 'success'],
                ['label' => 'Total customers', 'value' => number_format((int)($stats['total'] ?? 0)), 'tone' => 'info'],
                ['label' => 'Active', 'value' => number_format((int)($stats['active'] ?? 0)), 'tone' => 'success'],
                ['label' => 'Suspended', 'value' => number_format((int)($stats['suspended'] ?? 0)), 'tone' => 'warning'],
            ],
            'columns' => ['Date', 'New customers'],
            'rows'    => array_map(static fn($r) => [format_date($r['day'], 'd M Y'), (int)$r['new_customers']], $rows),
            'chart'   => [
                'type'   => 'line',
                'labels' => array_map(static fn($r) => date('d M', strtotime((string)$r['day'])), $rows),
                'series' => [['name' => 'New customers', 'data' => array_map(static fn($r) => (int)$r['new_customers'], $rows)]],
            ],
            'extra'   => ['Top customers by spend' => array_map(static fn($c) => [
                $c['full_name'], $c['customer_code'], $c['phone'], (int)$c['purchases'], money((float)$c['spend']),
            ], $top)],
            'extra_columns' => ['Top customers by spend' => ['Customer', 'Code', 'Phone', 'Purchases', 'Spend']],
            'note'    => 'Customer growth is counted from the registration date.',
        ];
    }

    /* ----------------------------------------------------------- vouchers */

    private function vouchers(string $from, string $to): array
    {
        [$scopeSql, $scopeParams] = $this->scope();
        $generated = $this->db->fetchAll(
            "SELECT DATE(created_at) AS day, COUNT(*) AS `generated`
               FROM vouchers WHERE created_at BETWEEN ? AND ? AND $scopeSql
              GROUP BY DATE(created_at) ORDER BY day",
            array_merge([$from, $to], $scopeParams)
        );

        $stats = $this->db->fetchOne(
            "SELECT COUNT(CASE WHEN created_at BETWEEN ? AND ? THEN 1 END) AS `generated`,
                    COUNT(CASE WHEN activated_at BETWEEN ? AND ? THEN 1 END) AS activated,
                    COUNT(CASE WHEN status='expired' THEN 1 END) AS expired,
                    COUNT(CASE WHEN status='exhausted' THEN 1 END) AS exhausted,
                    COUNT(CASE WHEN status='available' THEN 1 END) AS available
               FROM vouchers WHERE $scopeSql",
            array_merge([$from, $to, $from, $to], $scopeParams)
        ) ?? [];

        // vouchers and packages both carry provider_id, so this one must be
        // qualified or MySQL calls the column ambiguous.
        [$voucherScope, $voucherParams] = $this->scope('v');
        $byPackage = $this->db->fetchAll(
            "SELECT p.name, COUNT(v.id) AS `generated`,
                    COUNT(CASE WHEN v.activated_at IS NOT NULL THEN 1 END) AS activated,
                    COALESCE(SUM(CASE WHEN v.activated_at IS NOT NULL THEN v.price END),0) AS value
               FROM vouchers v JOIN packages p ON p.id = v.package_id
              WHERE v.created_at BETWEEN ? AND ? AND $voucherScope
              GROUP BY p.id, p.name ORDER BY `generated` DESC",
            array_merge([$from, $to], $voucherParams)
        );

        return [
            'title'   => 'Voucher report',
            'summary' => [
                ['label' => 'Generated', 'value' => number_format((int)($stats['generated'] ?? 0)), 'tone' => 'info'],
                ['label' => 'Activated', 'value' => number_format((int)($stats['activated'] ?? 0)), 'tone' => 'success'],
                ['label' => 'Expired', 'value' => number_format((int)($stats['expired'] ?? 0)), 'tone' => 'danger'],
                ['label' => 'Still available', 'value' => number_format((int)($stats['available'] ?? 0)), 'tone' => 'neutral'],
            ],
            'columns' => ['Date', 'Vouchers generated'],
            'rows'    => array_map(static fn($r) => [format_date($r['day'], 'd M Y'), (int)$r['generated']], $generated),
            'chart'   => [
                'type'   => 'bar',
                'labels' => array_map(static fn($r) => date('d M', strtotime((string)$r['day'])), $generated),
                'series' => [['name' => 'Generated', 'data' => array_map(static fn($r) => (int)$r['generated'], $generated)]],
            ],
            'extra'   => ['By package' => array_map(static fn($p) => [
                $p['name'], (int)$p['generated'], (int)$p['activated'], money((float)$p['value']),
            ], $byPackage)],
            'extra_columns' => ['By package' => ['Package', 'Generated', 'Activated', 'Activated value']],
            'note'    => 'Activation figures count vouchers activated inside the selected range.',
        ];
    }

    /* ------------------------------------------------------------ network */

    private function network(string $from, string $to): array
    {
        [$scopeSql, $scopeParams] = $this->scope();
        $sessions = $this->db->fetchAll(
            "SELECT DATE(started_at) AS day, COUNT(*) AS sessions,
                    COALESCE(SUM(download_bytes),0) AS download,
                    COALESCE(SUM(upload_bytes),0) AS upload
               FROM sessions WHERE started_at BETWEEN ? AND ? AND $scopeSql
              GROUP BY DATE(started_at) ORDER BY day",
            array_merge([$from, $to], $scopeParams)
        );

        $routers = (new Router())->counts();
        $aps     = (new AccessPoint())->counts();
        $devices = (new Device())->stats();
        $active  = $this->db->count("SELECT COUNT(*) FROM sessions WHERE status='active' AND $scopeSql", $scopeParams);

        [$routerScope, $routerParams] = $this->scope('r');
        $byRouter = $this->db->fetchAll(
            "SELECT r.name, COUNT(s.id) AS sessions,
                    COALESCE(SUM(s.download_bytes + s.upload_bytes),0) AS traffic
               FROM routers r LEFT JOIN sessions s ON s.router_id = r.id AND s.started_at BETWEEN ? AND ?
              WHERE $routerScope
              GROUP BY r.id, r.name ORDER BY traffic DESC",
            array_merge([$from, $to], $routerParams)
        );

        return [
            'title'   => 'Network report',
            'summary' => [
                ['label' => 'Routers online', 'value' => $routers['online'] . ' / ' . $routers['total'], 'tone' => $routers['online'] ? 'success' : 'danger'],
                ['label' => 'Access points online', 'value' => $aps['online'] . ' / ' . $aps['total'], 'tone' => $aps['online'] ? 'success' : 'warning'],
                ['label' => 'Active sessions', 'value' => number_format($active), 'tone' => 'info'],
                ['label' => 'Known devices', 'value' => number_format($devices['total']), 'tone' => 'neutral'],
            ],
            'columns' => ['Date', 'Sessions', 'Download', 'Upload'],
            'rows'    => array_map(static fn($r) => [
                format_date($r['day'], 'd M Y'), (int)$r['sessions'], format_bytes((float)$r['download']), format_bytes((float)$r['upload']),
            ], $sessions),
            'chart'   => [
                'type'   => 'line',
                'labels' => array_map(static fn($r) => date('d M', strtotime((string)$r['day'])), $sessions),
                'series' => [['name' => 'Sessions', 'data' => array_map(static fn($r) => (int)$r['sessions'], $sessions)]],
            ],
            'extra'   => ['By router' => array_map(static fn($r) => [
                $r['name'], (int)$r['sessions'], format_bytes((float)$r['traffic']),
            ], $byRouter)],
            'extra_columns' => ['By router' => ['Router', 'Sessions', 'Traffic']],
            'note'    => 'Router and access point states reflect the most recent successful poll.',
        ];
    }

    /* -------------------------------------------------------------- usage */

    private function usage(string $from, string $to): array
    {
        $usage  = new Usage();
        $totals = $usage->totals($from, $to);
        $daily  = $usage->dailySeries($from, $to);
        $top    = $usage->topCustomers($from, $to, 10);

        return [
            'title'   => 'Usage report',
            'summary' => [
                ['label' => 'Total data', 'value' => format_bytes($totals['total']), 'tone' => 'info'],
                ['label' => 'Download', 'value' => format_bytes($totals['download']), 'tone' => 'success'],
                ['label' => 'Upload', 'value' => format_bytes($totals['upload']), 'tone' => 'neutral'],
                ['label' => 'Connected time', 'value' => format_duration($totals['seconds']), 'tone' => 'neutral'],
            ],
            'columns' => ['Date', 'Download', 'Upload', 'Total'],
            'rows'    => array_map(static fn($r) => [
                format_date($r['record_date'], 'd M Y'),
                format_bytes((float)$r['download']),
                format_bytes((float)$r['upload']),
                format_bytes((float)$r['total']),
            ], $daily),
            'chart'   => [
                'type'   => 'line',
                'labels' => array_map(static fn($r) => date('d M', strtotime((string)$r['record_date'])), $daily),
                'series' => [
                    ['name' => 'Download', 'data' => array_map(static fn($r) => round((float)$r['download'] / 1048576, 1), $daily)],
                    ['name' => 'Upload',   'data' => array_map(static fn($r) => round((float)$r['upload'] / 1048576, 1), $daily)],
                ],
            ],
            'extra'   => ['Heaviest customers' => array_map(static fn($c) => [
                $c['full_name'], $c['customer_code'], format_bytes((float)$c['total']), format_duration((int)$c['seconds']),
            ], $top)],
            'extra_columns' => ['Heaviest customers' => ['Customer', 'Code', 'Data', 'Time online']],
            'note'    => 'Chart values are in megabytes. Usage rows generated without a live router are marked demo.',
        ];
    }

    /* --------------------------------------------------------- dashboard  */

    /** The headline figures shown on the admin dashboard. */
    public function dashboardSummary(): array
    {
        $payments = new Payment();
        $weekFrom = date('Y-m-d 00:00:00', strtotime('-6 days'));

        return [
            'customers'        => (new Customer())->stats(),
            'vouchers'         => (new Voucher())->statusCounts(),
            'packages'         => (new Package())->stats(),
            'revenue_today'    => $payments->revenueToday(),
            'revenue_week'     => $payments->revenueBetween($weekFrom, date('Y-m-d 23:59:59')),
            'revenue_month'    => $payments->revenueThisMonth(),
            'payments_pending' => $this->db->count("SELECT COUNT(*) FROM payments WHERE status = 'pending'"),
            'payments_failed'  => $this->db->count("SELECT COUNT(*) FROM payments WHERE status = 'failed' AND created_at >= (NOW() - INTERVAL 7 DAY)"),
            'subscriptions'    => (new Subscription())->stats(),
        ];
    }

    /** Flattens a report into CSV rows. */
    public function toCsv(array $report): array
    {
        $rows = [];
        foreach ($report['rows'] as $row) {
            $rows[] = $row;
        }
        foreach (($report['extra'] ?? []) as $title => $extraRows) {
            $rows[] = [];
            $rows[] = [$title];
            $rows[] = $report['extra_columns'][$title] ?? [];
            foreach ($extraRows as $extraRow) {
                $rows[] = $extraRow;
            }
        }
        return $rows;
    }
}
