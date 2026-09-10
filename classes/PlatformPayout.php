<?php
/**
 * WMS - The platform owner paying themselves.
 *
 * Deliberately not a provider withdrawal. A provider's money sits in a
 * wallet WMS holds for them, with a hold placed while a payout is in
 * flight and an approval step in front of it. The platform's money is
 * already the platform's: it is in their own SonicPesa merchant account,
 * and there is nobody to ask for permission.
 *
 * So there is no wallet row and no held balance here. What is available is
 * worked out from the ledger of fact - fees actually collected, less
 * payouts already sent - by PlatformWalletService.
 */
class PlatformPayout extends Model
{
    protected string $table = 'platform_withdrawals';

    /* Platform money is never tenant scoped: a provider must not see it,
       and Permission::requirePlatform() is what keeps them out. */
    protected bool $tenantScoped = false;

    protected array $searchable = ['reference', 'account_name', 'account_number', 'provider_ref', 'note'];
    protected array $sortable = ['id', 'amount', 'status', 'created_at'];

    public const STATUSES = [
        'pending'    => 'Pending',
        'processing' => 'Processing',
        'completed'  => 'Completed',
        'failed'     => 'Failed',
        'cancelled'  => 'Cancelled',
    ];

    public function search(array $filters, int $page, int $perPage = 25): array
    {
        $where  = ['1=1'];
        $params = [];

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
            "SELECT w.*, u.full_name AS requested_by_name
               FROM platform_withdrawals w
               LEFT JOIN users u ON u.id = w.requested_by
              WHERE $whereSql ORDER BY w.created_at DESC",
            "SELECT COUNT(*) FROM platform_withdrawals w WHERE $whereSql",
            $params,
            $page,
            $perPage
        );
    }

    public function nextReference(): string
    {
        do {
            $ref = 'PW' . date('ymd') . strtoupper(random_code(5, '0123456789ABCDEFGHJKLMNPQRSTUVWXYZ'));
        } while ($this->db->count('SELECT COUNT(*) FROM platform_withdrawals WHERE reference = ?', [$ref]) > 0);
        return $ref;
    }

    public function findByProviderRef(string $ref): ?array
    {
        return $this->db->fetchOne('SELECT * FROM platform_withdrawals WHERE provider_ref = ? LIMIT 1', [$ref]);
    }

    /** Payouts sent to SonicPesa that have not reached a final state. */
    public function inFlight(int $limit = 25): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM platform_withdrawals
              WHERE status = 'processing' AND provider_ref IS NOT NULL
              ORDER BY created_at ASC LIMIT " . (int)$limit
        );
    }

    public function recent(int $limit = 10): array
    {
        return $this->db->fetchAll(
            'SELECT w.*, u.full_name AS requested_by_name
               FROM platform_withdrawals w
               LEFT JOIN users u ON u.id = w.requested_by
              ORDER BY w.created_at DESC LIMIT ' . (int)$limit
        );
    }

    /**
     * What has left the merchant account, and what is on its way out.
     *
     * A payout that has been sent but not yet confirmed counts against the
     * balance exactly as a completed one does. It is real money already
     * gone; treating it as still available would let the owner send the
     * same shillings twice while SonicPesa was still thinking.
     */
    public function totals(): array
    {
        $row = $this->db->fetchOne(
            "SELECT
                COALESCE(SUM(CASE WHEN status = 'completed' THEN amount END),0) AS paid_out,
                COALESCE(SUM(CASE WHEN status IN ('pending','processing') THEN amount END),0) AS in_flight,
                COUNT(CASE WHEN status IN ('pending','processing') THEN 1 END) AS in_flight_count,
                COALESCE(SUM(CASE WHEN status = 'completed' THEN fee END),0) AS payout_fees
               FROM platform_withdrawals"
        );
        return $row ?? ['paid_out' => 0, 'in_flight' => 0, 'in_flight_count' => 0, 'payout_fees' => 0];
    }
}
