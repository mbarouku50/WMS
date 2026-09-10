<?php
/**
 * WMS - Provider wallet ledger.
 *
 * Append-only record of every movement of a provider's money. Each row
 * carries the balance it produced, so the wallet can always be audited and,
 * if it ever disagrees with the cached total on `providers`, rebuilt.
 *
 * Nothing writes to this table directly - go through WalletService, which
 * moves the balance and appends the entry in one transaction.
 */
class Wallet extends Model
{
    protected string $table = 'wallet_transactions';
    protected bool $tenantScoped = true;
    protected array $searchable = ['reference', 'description'];
    protected array $sortable = ['id', 'created_at', 'amount'];

    public const TYPES = [
        'sale'                => 'Package sale',
        'platform_fee'        => 'Platform fee',
        'withdrawal'          => 'Withdrawal',
        'withdrawal_reversal' => 'Withdrawal returned',
        'refund'              => 'Refund to customer',
        'adjustment'          => 'Manual adjustment',
    ];

    /** Paginated ledger for the wallet screen. */
    public function search(array $filters, int $page, int $perPage = 25): array
    {
        [$scopeSql, $scopeParams] = $this->scope('w');
        $where  = [$scopeSql];
        $params = $scopeParams;

        if (!empty($filters['q'])) {
            $where[] = '(w.reference LIKE ? OR w.description LIKE ?)';
            $term    = '%' . $filters['q'] . '%';
            $params  = array_merge($params, [$term, $term]);
        }
        if (!empty($filters['type'])) {
            $where[]  = 'w.type = ?';
            $params[] = $filters['type'];
        }
        if (!empty($filters['direction'])) {
            $where[]  = 'w.direction = ?';
            $params[] = $filters['direction'];
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
            "SELECT w.*, u.full_name AS actor_name, p.transaction_ref AS payment_ref
               FROM wallet_transactions w
               LEFT JOIN users u ON u.id = w.created_by
               LEFT JOIN payments p ON p.id = w.payment_id
              WHERE $whereSql ORDER BY w.id DESC",
            "SELECT COUNT(*) FROM wallet_transactions w WHERE $whereSql",
            $params,
            $page,
            $perPage
        );
    }

    /** The last few movements, for the dashboard tile. */
    public function recent(int $limit = 6): array
    {
        [$scopeSql, $params] = $this->scope();
        return $this->db->fetchAll(
            "SELECT * FROM wallet_transactions WHERE $scopeSql ORDER BY id DESC LIMIT " . (int)$limit,
            $params
        );
    }

    /** Totals per type over a period, for the wallet summary. */
    public function summary(string $from, string $to): array
    {
        [$scopeSql, $params] = $this->scope();
        $rows = $this->db->fetchAll(
            "SELECT type, direction, COALESCE(SUM(amount),0) AS total, COUNT(*) AS entries
               FROM wallet_transactions
              WHERE $scopeSql AND created_at BETWEEN ? AND ?
              GROUP BY type, direction",
            array_merge($params, [$from, $to])
        );

        $out = ['credits' => 0.0, 'debits' => 0.0, 'by_type' => []];
        foreach ($rows as $row) {
            $amount = (float)$row['total'];
            $out[$row['direction'] === 'credit' ? 'credits' : 'debits'] += $amount;
            $out['by_type'][$row['type']] = ($out['by_type'][$row['type']] ?? 0) + ($row['direction'] === 'credit' ? $amount : -$amount);
        }
        $out['net'] = $out['credits'] - $out['debits'];
        return $out;
    }

    /** Daily earnings series for the wallet chart. */
    public function dailyEarnings(int $days = 14): array
    {
        [$scopeSql, $params] = $this->scope();
        return $this->db->fetchAll(
            "SELECT DATE(created_at) AS day,
                    COALESCE(SUM(CASE WHEN direction = 'credit' THEN amount ELSE 0 END),0) AS credited,
                    COALESCE(SUM(CASE WHEN direction = 'debit'  THEN amount ELSE 0 END),0) AS debited
               FROM wallet_transactions
              WHERE $scopeSql AND created_at >= (CURDATE() - INTERVAL ? DAY)
              GROUP BY DATE(created_at) ORDER BY day",
            array_merge($params, [$days])
        );
    }

    /**
     * Recomputes a provider's balance from the ledger.
     * The cached figure on `providers` should always match this; if it does
     * not, the ledger wins.
     */
    public function recomputeBalance(int $providerId): float
    {
        return (float)$this->db->fetchColumn(
            "SELECT COALESCE(SUM(CASE WHEN direction = 'credit' THEN amount ELSE -amount END),0)
               FROM wallet_transactions WHERE provider_id = ?",
            [$providerId],
            0
        );
    }
}
