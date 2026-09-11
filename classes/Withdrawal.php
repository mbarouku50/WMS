<?php
/**
 * WMS - Provider withdrawals.
 *
 * A provider asking for their money. The amount leaves the available
 * balance the moment it is requested (it moves to "held"), so the same
 * shillings cannot be requested twice while a payout is in flight.
 */
class Withdrawal extends Model
{
    protected string $table = 'withdrawals';
    protected bool $tenantScoped = true;
    protected array $searchable = ['reference', 'account_name', 'account_number', 'provider_ref'];
    protected array $sortable = ['id', 'amount', 'status', 'created_at'];

    /**
     * The floor for any payout, provider or platform, in shillings.
     *
     * Settings `withdrawal_minimum` and `platform_withdrawal_minimum`
     * override it; this is what applies when neither has been set.
     */
    public const MINIMUM = 30000;

    public const STATUSES = [
        'pending'    => 'Pending',
        'processing' => 'Processing',
        'completed'  => 'Completed',
        'failed'     => 'Failed',
        'cancelled'  => 'Cancelled',
    ];

    /** Payout destinations SonicPesa accepts. */
    public const METHODS = [
        'M-Pesa'       => 'M-Pesa',
        'Tigo Pesa'    => 'Tigo Pesa',
        'Airtel Money' => 'Airtel Money',
        'Halopesa'     => 'Halopesa',
        'CRDB Bank'    => 'CRDB Bank',
        'NMB Bank'     => 'NMB Bank',
        'Selcom'       => 'Selcom',
    ];

    public function search(array $filters, int $page, int $perPage = 25): array
    {
        [$scopeSql, $scopeParams] = $this->scope('w');
        $where  = [$scopeSql];
        $params = $scopeParams;

        if (!empty($filters['q'])) {
            [$clause, $p] = $this->searchClause((string)$filters['q'], 'w');
            if ($clause) {
                $where[] = $clause;
                $params  = array_merge($params, $p);
            }
        }
        if (!empty($filters['status'])) {
            $where[]  = 'w.status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['provider_id']) && ProviderContext::isGlobalScope()) {
            $where[]  = 'w.provider_id = ?';
            $params[] = (int)$filters['provider_id'];
        }
        if (!empty($filters['date_from'])) {
            $where[]  = 'w.created_at >= ?';
            $params[] = $filters['date_from'] . ' 00:00:00';
        }
        if (!empty($filters['date_to'])) {
            $where[]  = 'w.created_at <= ?';
            $params[] = $filters['date_to'] . ' 23:59:59';
        }

        $whereSql = implode(' AND ', $where);

        return $this->paginate(
            "SELECT w.*, pr.business_name AS provider_name, pr.provider_code,
                    u.full_name AS requested_by_name, a.full_name AS approved_by_name
               FROM withdrawals w
               JOIN providers pr ON pr.id = w.provider_id
               LEFT JOIN users u ON u.id = w.requested_by
               LEFT JOIN users a ON a.id = w.approved_by
              WHERE $whereSql ORDER BY w.created_at DESC",
            "SELECT COUNT(*) FROM withdrawals w WHERE $whereSql",
            $params,
            $page,
            $perPage
        );
    }

    public function withProvider(int $id): ?array
    {
        [$scopeSql, $params] = $this->scope('w');
        return $this->db->fetchOne(
            "SELECT w.*, pr.business_name AS provider_name, pr.provider_code
               FROM withdrawals w JOIN providers pr ON pr.id = w.provider_id
              WHERE w.id = ? AND $scopeSql LIMIT 1",
            array_merge([$id], $params)
        );
    }

    /** Looks a withdrawal up by the gateway's own id, for webhooks. */
    public function findByProviderRef(string $ref): ?array
    {
        return $this->db->fetchOne('SELECT * FROM withdrawals WHERE provider_ref = ? LIMIT 1', [$ref]);
    }

    public function nextReference(): string
    {
        do {
            $ref = 'WD' . date('ymd') . strtoupper(random_code(5, '0123456789ABCDEFGHJKLMNPQRSTUVWXYZ'));
        } while ($this->db->count('SELECT COUNT(*) FROM withdrawals WHERE reference = ?', [$ref]) > 0);
        return $ref;
    }

    /** Awaiting the platform owner's approval. */
    public function pendingApproval(int $limit = 20): array
    {
        return $this->db->fetchAll(
            "SELECT w.*, pr.business_name AS provider_name
               FROM withdrawals w JOIN providers pr ON pr.id = w.provider_id
              WHERE w.status = 'pending' ORDER BY w.created_at ASC LIMIT " . (int)$limit
        );
    }

    /** Payouts already sent that have not reached a final state. */
    public function inFlight(int $limit = 50): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM withdrawals WHERE status = 'processing' AND provider_ref IS NOT NULL
              ORDER BY created_at ASC LIMIT " . (int)$limit
        );
    }

    public function counts(): array
    {
        return [
            'pending'    => $this->countAll("status = 'pending'"),
            'processing' => $this->countAll("status = 'processing'"),
            'completed'  => $this->countAll("status = 'completed'"),
            'failed'     => $this->countAll("status = 'failed'"),
        ];
    }
}
