<?php
/**
 * WMS - Platform billing.
 *
 * The platform charges each provider a recurring fee. Both the amount and
 * the date the first one falls due are agreed per provider when they are
 * registered, so you can say "TSh 10,000 a month, starting two months from
 * now" and the system honours it.
 *
 *      register provider  ->  grace period  ->  first invoice  ->  monthly
 *
 * Invoices are raised by cron on their due date and, if the provider has
 * the balance, settled straight from their wallet. If not, the invoice
 * stands as a debt and the platform owner is told - nothing is switched off
 * automatically, because cutting a business off is a decision for a person.
 */
class BillingService
{
    private Database $db;
    private PlatformInvoice $invoices;
    private WalletService $wallet;

    public function __construct()
    {
        $this->db       = Database::getInstance();
        $this->invoices = new PlatformInvoice();
        $this->wallet   = new WalletService();
    }

    /* ================================================================= */
    /* Terms                                                             */
    /* ================================================================= */

    /**
     * Sets a provider's billing terms. Used when registering them and
     * whenever the agreement changes.
     *
     * @param array $terms fee, cycle_months, grace_months|starts_on, status
     */
    public function setTerms(int $providerId, array $terms): array
    {
        $provider = $this->db->fetchOne('SELECT * FROM providers WHERE id = ? LIMIT 1', [$providerId]);
        if (!$provider) {
            return ['ok' => false, 'message' => 'That provider could not be found.'];
        }

        $fee    = isset($terms['fee']) && $terms['fee'] !== '' ? round((float)$terms['fee'], 2) : (float)setting('platform_fee_default', 10000);
        $cycle  = max(1, min(12, (int)($terms['cycle_months'] ?? setting('platform_fee_cycle_months', 1))));
        $status = $terms['status'] ?? null;

        // Either an explicit start date, or "N months from registration".
        if (!empty($terms['starts_on'])) {
            $startsOn = date('Y-m-d', strtotime((string)$terms['starts_on']));
        } else {
            $grace    = max(0, min(24, (int)($terms['grace_months'] ?? setting('platform_fee_grace_months', 1))));
            $from     = $terms['from'] ?? ($provider['created_at'] ?? date('Y-m-d'));
            $startsOn = date('Y-m-d', strtotime('+' . $grace . ' months', strtotime((string)$from)));
        }

        $data = [
            'platform_fee_amount'       => $fee,
            'platform_fee_cycle_months' => $cycle,
            'billing_starts_on'         => $startsOn,
            'billing_next_due_on'       => $startsOn,
        ];

        if ($status !== null && in_array($status, ['grace', 'current', 'due', 'overdue', 'exempt'], true)) {
            $data['billing_status'] = $status;
        } else {
            $data['billing_status'] = $startsOn > date('Y-m-d') ? 'grace' : 'current';
        }

        $this->db->update('providers', $data, 'id = ?', [$providerId]);

        AuditLog::record('billing_terms', 'provider', $providerId,
            'Set the platform fee to ' . money($fee) . ' every '
            . ($cycle === 1 ? 'month' : $cycle . ' months')
            . ', starting ' . format_date($startsOn, 'd M Y'));

        return [
            'ok'      => true,
            'message' => 'Billing terms saved: ' . money($fee) . ' every '
                . ($cycle === 1 ? 'month' : $cycle . ' months')
                . ', first due ' . format_date($startsOn, 'd M Y') . '.',
            'starts_on' => $startsOn,
        ];
    }

    /** A plain-language summary of where a provider stands. */
    public function terms(int $providerId): array
    {
        $provider = $this->db->fetchOne('SELECT * FROM providers WHERE id = ? LIMIT 1', [$providerId]);
        if (!$provider) {
            return [];
        }

        $outstanding = $this->invoices->outstandingTotal($providerId);
        $startsOn    = $provider['billing_starts_on'];
        $inGrace     = $startsOn !== null && $startsOn > date('Y-m-d');

        return [
            'fee'          => (float)$provider['platform_fee_amount'],
            'cycle_months' => (int)$provider['platform_fee_cycle_months'],
            'starts_on'    => $startsOn,
            'next_due_on'  => $provider['billing_next_due_on'],
            'status'       => $provider['billing_status'],
            'in_grace'     => $inGrace,
            'grace_days'   => $inGrace ? max(0, (int)ceil((strtotime((string)$startsOn) - time()) / 86400)) : 0,
            'outstanding'  => $outstanding,
            'unpaid'       => $this->invoices->unpaidFor($providerId),
            'summary'      => $this->describe($provider, $inGrace, $outstanding),
        ];
    }

    private function describe(array $provider, bool $inGrace, float $outstanding): string
    {
        $fee   = money((float)$provider['platform_fee_amount']);
        $cycle = (int)$provider['platform_fee_cycle_months'] === 1 ? 'month' : (int)$provider['platform_fee_cycle_months'] . ' months';

        if ($provider['billing_status'] === 'exempt') {
            return 'Exempt from the platform fee.';
        }
        if ($inGrace) {
            return $fee . ' every ' . $cycle . ', starting ' . format_date($provider['billing_starts_on'], 'd M Y')
                 . '. Nothing is owed yet.';
        }
        if ($outstanding > 0) {
            return $fee . ' every ' . $cycle . '. ' . money($outstanding) . ' currently outstanding.';
        }
        return $fee . ' every ' . $cycle . '. Up to date; next invoice '
             . format_date($provider['billing_next_due_on'], 'd M Y') . '.';
    }

    /* ================================================================= */
    /* Invoicing                                                         */
    /* ================================================================= */

    /**
     * Raises any invoices that have become due.
     * Safe to call repeatedly - one invoice per provider per period.
     *
     * @return array{raised:int,collected:int,unpaid:int}
     */
    public function runBilling(): array
    {
        $raised = 0;

        $due = $this->db->fetchAll(
            "SELECT * FROM providers
              WHERE billing_status <> 'exempt'
                AND status <> 'inactive'
                AND billing_next_due_on IS NOT NULL
                AND billing_next_due_on <= CURDATE()"
        );

        foreach ($due as $provider) {
            if ($this->raiseInvoice($provider)) {
                $raised++;
            }
        }

        $collection = $this->collectDue();

        return [
            'raised'    => $raised,
            'collected' => $collection['collected'],
            'unpaid'    => $collection['unpaid'],
        ];
    }

    /** Creates one invoice for a provider's current period. */
    private function raiseInvoice(array $provider): bool
    {
        $providerId  = (int)$provider['id'];
        $periodStart = (string)$provider['billing_next_due_on'];
        $cycle       = max(1, (int)$provider['platform_fee_cycle_months']);
        $periodEnd   = date('Y-m-d', strtotime('+' . $cycle . ' months -1 day', strtotime($periodStart)));
        $dueDays     = (int)setting('platform_fee_due_days', 7);
        $dueOn       = date('Y-m-d', strtotime('+' . $dueDays . ' days', strtotime($periodStart)));

        if ($this->invoices->existsForPeriod($providerId, $periodStart)) {
            // Already invoiced; just move the pointer on.
            $this->advanceCycle($providerId, $periodStart, $cycle);
            return false;
        }

        try {
            $invoiceId = $this->db->insert('platform_invoices', [
                'provider_id'    => $providerId,
                'invoice_number' => $this->invoices->nextNumber(),
                'period_start'   => $periodStart,
                'period_end'     => $periodEnd,
                'amount'         => (float)$provider['platform_fee_amount'],
                'currency'       => $provider['currency_code'] ?? 'TZS',
                'due_on'         => $dueOn,
                'status'         => 'unpaid',
            ]);
        } catch (Throwable $e) {
            Logger::error('Could not raise a platform invoice: ' . $e->getMessage(), ['provider_id' => $providerId]);
            return false;
        }

        $this->advanceCycle($providerId, $periodStart, $cycle);
        $this->db->update('providers', ['billing_status' => 'due'], 'id = ?', [$providerId]);

        AuditLog::record('invoice_raised', 'provider', $providerId,
            'Raised a platform fee invoice of ' . money((float)$provider['platform_fee_amount'])
            . ' for ' . format_date($periodStart, 'd M Y') . ' to ' . format_date($periodEnd, 'd M Y'),
            'system', 'Billing');

        Alert::raise('platform_fee_due', 'info',
            'Platform fee due for ' . $provider['business_name'],
            money((float)$provider['platform_fee_amount']) . ' is due by ' . format_date($dueOn, 'd M Y') . '.',
            'provider', $providerId);

        return true;
    }

    private function advanceCycle(int $providerId, string $periodStart, int $cycleMonths): void
    {
        $next = date('Y-m-d', strtotime('+' . $cycleMonths . ' months', strtotime($periodStart)));
        $this->db->update('providers', ['billing_next_due_on' => $next], 'id = ?', [$providerId]);
    }

    /**
     * Settles due invoices from provider wallets where the balance allows.
     *
     * @return array{collected:int,unpaid:int,total:float}
     */
    public function collectDue(): array
    {
        if ((string)setting('auto_charge_fee_from_wallet', '1') !== '1') {
            return ['collected' => 0, 'unpaid' => 0, 'total' => 0.0];
        }

        $collected = 0;
        $unpaid    = 0;
        $total     = 0.0;

        foreach ($this->invoices->dueForCollection() as $invoice) {
            $result = $this->payFromWallet((int)$invoice['id']);
            if ($result['ok']) {
                $collected++;
                $total += (float)$invoice['amount'];
            } else {
                $unpaid++;
                $this->markOverdue((int)$invoice['provider_id'], $invoice);
            }
        }

        return ['collected' => $collected, 'unpaid' => $unpaid, 'total' => $total];
    }

    /** Takes one invoice out of the provider's wallet. */
    public function payFromWallet(int $invoiceId): array
    {
        $invoice = $this->db->fetchOne('SELECT * FROM platform_invoices WHERE id = ? LIMIT 1', [$invoiceId]);
        if (!$invoice) {
            return ['ok' => false, 'message' => 'That invoice could not be found.'];
        }
        if ($invoice['status'] !== 'unpaid') {
            return ['ok' => true, 'message' => 'That invoice is already ' . $invoice['status'] . '.'];
        }

        $providerId = (int)$invoice['provider_id'];
        $amount     = (float)$invoice['amount'];
        $balance    = $this->wallet->balance($providerId);

        if ($balance['available'] < $amount) {
            return [
                'ok'      => false,
                'message' => 'Not enough in the wallet: ' . money($balance['available']) . ' available, ' . money($amount) . ' owed.',
            ];
        }

        $result = $this->wallet->debit($providerId, $amount, 'platform_fee',
            'Platform fee · ' . $invoice['invoice_number'],
            ['reference' => $invoice['invoice_number'], 'invoice_id' => $invoiceId, 'created_by' => null]);

        if (!$result['ok']) {
            return $result;
        }

        $this->db->update('platform_invoices', [
            'status'    => 'paid',
            'paid_at'   => date('Y-m-d H:i:s'),
            'paid_from' => 'wallet',
        ], 'id = ?', [$invoiceId]);

        $this->refreshStatus($providerId);
        Alert::clear('platform_fee_overdue', 'provider', $providerId);

        AuditLog::record('invoice_paid', 'provider', $providerId,
            'Platform fee ' . $invoice['invoice_number'] . ' settled from the wallet (' . money($amount) . ')',
            'system', 'Billing');

        return ['ok' => true, 'message' => 'Invoice ' . $invoice['invoice_number'] . ' settled from the wallet.'];
    }

    /** Marks an invoice paid outside the system (cash, bank transfer). */
    public function markPaidManually(int $invoiceId, string $note = ''): array
    {
        $invoice = $this->db->fetchOne('SELECT * FROM platform_invoices WHERE id = ? LIMIT 1', [$invoiceId]);
        if (!$invoice || $invoice['status'] !== 'unpaid') {
            return ['ok' => false, 'message' => 'Only an unpaid invoice can be marked as paid.'];
        }

        $this->db->update('platform_invoices', [
            'status'    => 'paid',
            'paid_at'   => date('Y-m-d H:i:s'),
            'paid_from' => 'manual',
            'notes'     => mb_substr($note, 0, 255) ?: null,
        ], 'id = ?', [$invoiceId]);

        $this->refreshStatus((int)$invoice['provider_id']);
        Alert::clear('platform_fee_overdue', 'provider', (int)$invoice['provider_id']);

        AuditLog::record('invoice_paid_manual', 'provider', (int)$invoice['provider_id'],
            'Marked ' . $invoice['invoice_number'] . ' as paid outside the platform. ' . $note);

        return ['ok' => true, 'message' => 'Invoice marked as paid.'];
    }

    /** Writes an invoice off. */
    public function waive(int $invoiceId, string $reason = ''): array
    {
        $invoice = $this->db->fetchOne('SELECT * FROM platform_invoices WHERE id = ? LIMIT 1', [$invoiceId]);
        if (!$invoice || $invoice['status'] !== 'unpaid') {
            return ['ok' => false, 'message' => 'Only an unpaid invoice can be waived.'];
        }

        $this->db->update('platform_invoices', [
            'status'    => 'waived',
            'paid_at'   => date('Y-m-d H:i:s'),
            'paid_from' => 'waived',
            'notes'     => mb_substr($reason, 0, 255) ?: null,
        ], 'id = ?', [$invoiceId]);

        $this->refreshStatus((int)$invoice['provider_id']);
        AuditLog::record('invoice_waived', 'provider', (int)$invoice['provider_id'],
            'Waived ' . $invoice['invoice_number'] . ' (' . money((float)$invoice['amount']) . '). ' . $reason);

        return ['ok' => true, 'message' => 'Invoice waived.'];
    }

    private function markOverdue(int $providerId, array $invoice): void
    {
        $this->db->update('providers', ['billing_status' => 'overdue'], 'id = ?', [$providerId]);

        Alert::raise('platform_fee_overdue', 'warning',
            'Platform fee overdue',
            'Invoice ' . $invoice['invoice_number'] . ' for ' . money((float)$invoice['amount'])
            . ' was due ' . format_date($invoice['due_on'], 'd M Y')
            . ' and the wallet does not cover it.',
            'provider', $providerId);
    }

    /** Recomputes a provider's billing status from its invoices. */
    public function refreshStatus(int $providerId): void
    {
        $provider = $this->db->fetchOne('SELECT * FROM providers WHERE id = ? LIMIT 1', [$providerId]);
        if (!$provider || $provider['billing_status'] === 'exempt') {
            return;
        }

        $overdue = $this->db->count(
            "SELECT COUNT(*) FROM platform_invoices WHERE provider_id = ? AND status = 'unpaid' AND due_on < CURDATE()",
            [$providerId]
        );
        $unpaid = $this->db->count(
            "SELECT COUNT(*) FROM platform_invoices WHERE provider_id = ? AND status = 'unpaid'",
            [$providerId]
        );

        $status = match (true) {
            $overdue > 0 => 'overdue',
            $unpaid > 0  => 'due',
            ($provider['billing_starts_on'] ?? '') > date('Y-m-d') => 'grace',
            default      => 'current',
        };

        $this->db->update('providers', ['billing_status' => $status], 'id = ?', [$providerId]);
    }

    /* ================================================================= */
    /* Reporting                                                         */
    /* ================================================================= */

    /** Headline numbers for the platform billing screen. */
    public function platformSummary(): array
    {
        $totals = $this->invoices->platformTotals();

        $wallets = $this->db->fetchOne(
            'SELECT COALESCE(SUM(wallet_balance),0) AS held_for_providers,
                    COALESCE(SUM(wallet_held),0) AS reserved,
                    COALESCE(SUM(lifetime_earned),0) AS lifetime_earned,
                    COALESCE(SUM(lifetime_withdrawn),0) AS lifetime_withdrawn
               FROM providers'
        ) ?? [];

        $withdrawals = $this->db->fetchOne(
            "SELECT COUNT(CASE WHEN status='pending' THEN 1 END) AS pending,
                    COALESCE(SUM(CASE WHEN status='pending' THEN amount END),0) AS pending_amount,
                    COALESCE(SUM(CASE WHEN status='completed' THEN amount END),0) AS paid_out
               FROM withdrawals"
        ) ?? [];

        return array_merge($totals, $wallets, $withdrawals);
    }
}
