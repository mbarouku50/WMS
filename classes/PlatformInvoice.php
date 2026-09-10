<?php
/**
 * WMS - Platform fees charged to providers.
 *
 * One invoice per provider per billing cycle. The amount and the date the
 * first one falls due are agreed per provider when they are registered, so
 * a new business can be given a grace period before the meter starts.
 */
class PlatformInvoice extends Model
{
    protected string $table = 'platform_invoices';
    protected bool $tenantScoped = true;
    protected array $searchable = ['invoice_number', 'notes'];
    protected array $sortable = ['id', 'due_on', 'amount', 'status'];

    public const STATUSES = [
        'unpaid'    => 'Unpaid',
        'paid'      => 'Paid',
        'waived'    => 'Waived',
        'cancelled' => 'Cancelled',
    ];

    public function search(array $filters, int $page, int $perPage = 25): array
    {
        [$scopeSql, $scopeParams] = $this->scope('i');
        $where  = [$scopeSql];
        $params = $scopeParams;

        if (!empty($filters['q'])) {
            [$clause, $p] = $this->searchClause((string)$filters['q'], 'i');
            if ($clause) {
                $where[] = $clause;
                $params  = array_merge($params, $p);
            }
        }
        if (!empty($filters['status'])) {
            $where[]  = 'i.status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['provider_id']) && ProviderContext::isGlobalScope()) {
            $where[]  = 'i.provider_id = ?';
            $params[] = (int)$filters['provider_id'];
        }
        if (($filters['overdue'] ?? '') === '1') {
            $where[] = "i.status = 'unpaid' AND i.due_on < CURDATE()";
        }

        $whereSql = implode(' AND ', $where);

        return $this->paginate(
            "SELECT i.*, pr.business_name AS provider_name, pr.provider_code, pr.wallet_balance
               FROM platform_invoices i
               JOIN providers pr ON pr.id = i.provider_id
              WHERE $whereSql ORDER BY i.due_on DESC, i.id DESC",
            "SELECT COUNT(*) FROM platform_invoices i WHERE $whereSql",
            $params,
            $page,
            $perPage
        );
    }

    public function nextNumber(): string
    {
        $prefix = 'INV-' . date('Ym') . '-';
        $last = $this->db->fetchColumn(
            'SELECT invoice_number FROM platform_invoices WHERE invoice_number LIKE ? ORDER BY id DESC LIMIT 1',
            [$prefix . '%']
        );
        $n = $last ? (int)substr((string)$last, strlen($prefix)) + 1 : 1;
        do {
            $number = $prefix . str_pad((string)$n, 4, '0', STR_PAD_LEFT);
            $n++;
        } while ($this->db->count('SELECT COUNT(*) FROM platform_invoices WHERE invoice_number = ?', [$number]) > 0);
        return $number;
    }

    /**
     * Has this provider already been invoiced for this period?
     *
     * A cancelled invoice does not count. Cancelling is how a terms change
     * withdraws a demand, and if that also barred the period for ever, an
     * owner who moved a start date forward and then back again would
     * silently never be paid for those months - the unique key on
     * (provider_id, period_start) would refuse the new row and nothing
     * would say why.
     */
    public function existsForPeriod(int $providerId, string $periodStart): bool
    {
        return $this->db->count(
            "SELECT COUNT(*) FROM platform_invoices
              WHERE provider_id = ? AND period_start = ? AND status <> 'cancelled'",
            [$providerId, $periodStart]
        ) > 0;
    }

    /** A cancelled invoice occupying a period that is being billed again. */
    public function cancelledForPeriod(int $providerId, string $periodStart): ?array
    {
        return $this->db->fetchOne(
            "SELECT * FROM platform_invoices
              WHERE provider_id = ? AND period_start = ? AND status = 'cancelled'
              ORDER BY id DESC LIMIT 1",
            [$providerId, $periodStart]
        ) ?: null;
    }

    public function unpaidFor(int $providerId): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM platform_invoices WHERE provider_id = ? AND status = 'unpaid' ORDER BY due_on",
            [$providerId]
        );
    }

    public function outstandingTotal(int $providerId): float
    {
        return (float)$this->db->fetchColumn(
            "SELECT COALESCE(SUM(amount),0) FROM platform_invoices WHERE provider_id = ? AND status = 'unpaid'",
            [$providerId],
            0
        );
    }

    /** Platform-wide figures for the billing dashboard. */
    public function platformTotals(): array
    {
        $row = $this->db->fetchOne(
            "SELECT
                COALESCE(SUM(CASE WHEN status='paid' THEN amount END),0) AS collected,
                COALESCE(SUM(CASE WHEN status='unpaid' THEN amount END),0) AS outstanding,
                COALESCE(SUM(CASE WHEN status='unpaid' AND due_on < CURDATE() THEN amount END),0) AS overdue,
                COUNT(CASE WHEN status='unpaid' AND due_on < CURDATE() THEN 1 END) AS overdue_count,
                COALESCE(SUM(CASE WHEN status='paid' AND paid_at >= DATE_FORMAT(CURDATE(),'%Y-%m-01') THEN amount END),0) AS collected_month
               FROM platform_invoices"
        );
        return $row ?? [];
    }

    /** Invoices that are due and could be settled from a wallet. */
    public function dueForCollection(int $limit = 100, ?int $providerId = null): array
    {
        $scope  = $providerId === null ? '' : ' AND i.provider_id = ?';
        $params = $providerId === null ? [] : [$providerId];

        return $this->db->fetchAll(
            "SELECT i.*, pr.wallet_balance, pr.business_name
               FROM platform_invoices i
               JOIN providers pr ON pr.id = i.provider_id
              WHERE i.status = 'unpaid' AND i.due_on <= CURDATE()" . $scope . "
              ORDER BY i.due_on ASC LIMIT " . (int)$limit,
            $params
        );
    }

    /** The single oldest unpaid invoice, which is the one that must be paid first. */
    public function oldestUnpaid(int $providerId): ?array
    {
        return $this->db->fetchOne(
            "SELECT * FROM platform_invoices
              WHERE provider_id = ? AND status = 'unpaid'
              ORDER BY due_on ASC, id ASC LIMIT 1",
            [$providerId]
        ) ?: null;
    }

    /** Providers currently shut out over an unpaid fee, for the platform screen. */
    public function lockedProviders(): array
    {
        return $this->db->fetchAll(
            "SELECT pr.id, pr.business_name, pr.provider_code, pr.billing_locked_at,
                    pr.service_suspended_at, pr.billing_grace_until, pr.wallet_balance,
                    COALESCE(SUM(i.amount),0) AS owed,
                    MIN(i.due_on) AS oldest_due
               FROM providers pr
               LEFT JOIN platform_invoices i
                      ON i.provider_id = pr.id AND i.status = 'unpaid'
              WHERE pr.billing_locked_at IS NOT NULL OR pr.service_suspended_at IS NOT NULL
              GROUP BY pr.id, pr.business_name, pr.provider_code, pr.billing_locked_at,
                       pr.service_suspended_at, pr.billing_grace_until, pr.wallet_balance
              ORDER BY pr.service_suspended_at IS NULL, pr.billing_locked_at ASC"
        );
    }
}
