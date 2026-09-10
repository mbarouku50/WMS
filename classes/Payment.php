<?php
/**
 * WMS - Payments.
 *
 * Stores one row per payment attempt.  Provider traffic (create order,
 * status checks, callbacks) is written to the transactions table so the
 * money trail stays auditable.
 */
class Payment extends Model
{
    protected string $table = 'payments';
    protected bool $tenantScoped = true;
    protected array $searchable = ['transaction_ref', 'provider_ref', 'provider_txn_id', 'payer_name', 'payer_phone'];
    protected array $sortable = ['id', 'amount', 'status', 'created_at'];

    public function search(array $filters, int $page, int $perPage = 25): array
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
        foreach (['status' => 'p.status', 'provider' => 'p.provider', 'method' => 'p.method'] as $key => $column) {
            if (!empty($filters[$key])) {
                $where[]  = "$column = ?";
                $params[] = $filters[$key];
            }
        }
        if (!empty($filters['package_id'])) {
            $where[]  = 'p.package_id = ?';
            $params[] = (int)$filters['package_id'];
        }
        if (!empty($filters['customer_id'])) {
            $where[]  = 'p.customer_id = ?';
            $params[] = (int)$filters['customer_id'];
        }
        if (!empty($filters['date_from'])) {
            $where[]  = 'p.created_at >= ?';
            $params[] = $filters['date_from'] . ' 00:00:00';
        }
        if (!empty($filters['date_to'])) {
            $where[]  = 'p.created_at <= ?';
            $params[] = $filters['date_to'] . ' 23:59:59';
        }

        $whereSql = implode(' AND ', $where);
        $sort     = $this->safeOrder((string)($filters['sort'] ?? 'created_at DESC'), 'created_at DESC');

        return $this->paginate(
            "SELECT p.*, c.full_name AS customer_name, c.customer_code, pk.name AS package_name
               FROM payments p
               LEFT JOIN customers c ON c.id = p.customer_id
               LEFT JOIN packages pk ON pk.id = p.package_id
              WHERE $whereSql ORDER BY p.$sort",
            "SELECT COUNT(*) FROM payments p WHERE $whereSql",
            $params,
            $page,
            $perPage
        );
    }

    public function withRelations(int $id): ?array
    {
        [$scopeSql, $params] = $this->scope('p');
        return $this->db->fetchOne(
            "SELECT p.*, c.full_name AS customer_name, c.phone AS customer_phone, c.customer_code,
                    pk.name AS package_name, v.code AS voucher_code
               FROM payments p
               LEFT JOIN customers c ON c.id = p.customer_id
               LEFT JOIN packages pk ON pk.id = p.package_id
               LEFT JOIN vouchers v ON v.id = p.voucher_id
              WHERE p.id = ? AND $scopeSql LIMIT 1",
            array_merge([$id], $params)
        );
    }

    /**
     * Unscoped variant for the portal's payment screens, which a customer
     * reaches before signing in. It returns only what that screen shows.
     */
    public function withRelationsUnscoped(int $id): ?array
    {
        return $this->db->fetchOne(
            'SELECT p.*, c.full_name AS customer_name, c.phone AS customer_phone, c.customer_code,
                    pk.name AS package_name, v.code AS voucher_code
               FROM payments p
               LEFT JOIN customers c ON c.id = p.customer_id
               LEFT JOIN packages pk ON pk.id = p.package_id
               LEFT JOIN vouchers v ON v.id = p.voucher_id
              WHERE p.id = ? LIMIT 1',
            [$id]
        );
    }

    public function findByRef(string $ref): ?array
    {
        return $this->findBy('transaction_ref', $ref);
    }

    /**
     * Looks a payment up by its gateway reference, across every tenant.
     * Webhooks arrive without a session, so the payment's own provider_id
     * becomes the context.
     */
    public function findByProviderRef(string $ref): ?array
    {
        return $this->db->fetchOne('SELECT * FROM payments WHERE provider_ref = ? LIMIT 1', [$ref]);
    }

    /** Generates a unique internal reference, e.g. WMS26090800042. */
    public function nextReference(): string
    {
        do {
            $ref = 'WMS' . date('ymd') . strtoupper(random_code(5, '0123456789ABCDEFGHJKLMNPQRSTUVWXYZ'));
        } while ($this->db->count('SELECT COUNT(*) FROM payments WHERE transaction_ref = ?', [$ref]) > 0);
        return $ref;
    }

    /** Appends a provider interaction to the transactions ledger. */
    public function logTransaction(?int $paymentId, string $type, string $provider, ?string $providerRef, float $amount, string $status, string $message = '', mixed $raw = null): int
    {
        return $this->db->insert('transactions', [
            'payment_id'   => $paymentId,
            'type'         => $type,
            'provider'     => $provider,
            'provider_ref' => $providerRef,
            'amount'       => $amount,
            'status'       => mb_substr($status, 0, 40),
            'message'      => mb_substr($message, 0, 255),
            'raw_response' => is_string($raw) ? mb_substr($raw, 0, 8000) : (($raw === null) ? null : mb_substr((string)json_encode($raw), 0, 8000)),
        ]);
    }

    public function transactions(int $paymentId): array
    {
        return $this->db->fetchAll('SELECT * FROM transactions WHERE payment_id = ? ORDER BY id DESC', [$paymentId]);
    }

    /* ---------------------------------------------------------- reporting */

    /** Revenue totals for a date range. */
    public function revenueBetween(string $from, string $to): float
    {
        [$scopeSql, $params] = $this->scope();
        return (float)$this->db->fetchColumn(
            "SELECT COALESCE(SUM(amount),0) FROM payments
              WHERE status = 'successful' AND created_at BETWEEN ? AND ? AND $scopeSql",
            array_merge([$from, $to], $params),
            0
        );
    }

    /** Revenue for one provider - the platform dashboard's per-tenant figures. */
    public function revenueForProvider(int $providerId, string $from, string $to): float
    {
        return (float)$this->db->fetchColumn(
            "SELECT COALESCE(SUM(amount),0) FROM payments
              WHERE status = 'successful' AND provider_id = ? AND created_at BETWEEN ? AND ?",
            [$providerId, $from, $to],
            0
        );
    }

    public function revenueToday(): float
    {
        return $this->revenueBetween(date('Y-m-d 00:00:00'), date('Y-m-d 23:59:59'));
    }

    public function revenueThisMonth(): float
    {
        return $this->revenueBetween(date('Y-m-01 00:00:00'), date('Y-m-t 23:59:59'));
    }

    /** Daily revenue series for the dashboard chart. */
    public function dailySeries(int $days = 14): array
    {
        [$scopeSql, $params] = $this->scope();
        return $this->db->fetchAll(
            "SELECT DATE(created_at) AS day,
                    COALESCE(SUM(CASE WHEN status = 'successful' THEN amount ELSE 0 END),0) AS revenue,
                    COUNT(CASE WHEN status = 'successful' THEN 1 END) AS sales,
                    COUNT(CASE WHEN status = 'failed' THEN 1 END) AS failed
               FROM payments
              WHERE created_at >= (CURDATE() - INTERVAL ? DAY) AND $scopeSql
              GROUP BY DATE(created_at) ORDER BY day",
            array_merge([$days], $params)
        );
    }

    /** Counts and totals grouped by status. */
    public function statusSummary(string $from, string $to): array
    {
        [$scopeSql, $scopeParams] = $this->scope();
        $rows = $this->db->fetchAll(
            "SELECT status, COUNT(*) AS total, COALESCE(SUM(amount),0) AS amount
               FROM payments WHERE created_at BETWEEN ? AND ? AND $scopeSql GROUP BY status",
            array_merge([$from, $to], $scopeParams)
        );
        $out = [];
        foreach (array_keys(WMS_PAYMENT_STATUSES) as $status) {
            $out[$status] = ['total' => 0, 'amount' => 0.0];
        }
        foreach ($rows as $row) {
            $out[$row['status']] = ['total' => (int)$row['total'], 'amount' => (float)$row['amount']];
        }
        return $out;
    }

    public function recent(int $limit = 8): array
    {
        [$scopeSql, $params] = $this->scope('p');
        return $this->db->fetchAll(
            "SELECT p.*, c.full_name AS customer_name, pk.name AS package_name
               FROM payments p
               LEFT JOIN customers c ON c.id = p.customer_id
               LEFT JOIN packages pk ON pk.id = p.package_id
              WHERE $scopeSql
              ORDER BY p.created_at DESC LIMIT " . (int)$limit,
            $params
        );
    }

    /** Payments still pending after a while - candidates for a status poll. */
    public function stalePending(int $minutes = 2, int $limit = 25): array
    {
        // Intentionally unscoped: the reconciliation job runs for the whole
        // platform, and each payment carries its own provider_id.
        return $this->db->fetchAll(
            "SELECT * FROM payments
              WHERE status = 'pending' AND provider_ref IS NOT NULL
                AND created_at < (NOW() - INTERVAL ? MINUTE)
              ORDER BY created_at ASC LIMIT " . (int)$limit,
            [$minutes]
        );
    }

    /** Distinct providers seen in the ledger, for the filter dropdown. */
    /** Distinct payment gateways seen in the ledger (not tenants). */
    public function providers(): array
    {
        [$scopeSql, $params] = $this->scope();
        return array_column(
            $this->db->fetchAll("SELECT DISTINCT provider FROM payments WHERE $scopeSql ORDER BY provider", $params),
            'provider'
        );
    }
}
