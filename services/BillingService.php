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
 * Invoices are raised on their due date and, if the provider has the
 * balance, settled straight from their wallet. Otherwise the provider
 * settles it themselves - from the wallet, or straight from their phone -
 * on the pay screen.
 *
 * What happens when it is simply not paid is BillingGuard's job: the
 * account locks onto the pay screen, and eventually the network stops
 * selling. See services/BillingGuard.php.
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

        /*
         * Withdraw any demand the new terms have just made wrong.
         *
         * This is the whole point of moving a start date. Without it, giving
         * a provider another month left last month's invoice standing, the
         * guard still saw an unpaid debt, and the provider was still shown a
         * demand for money their agreement no longer asked for.
         */
        $cancelled = $this->cancelInvoicesBefore(
            $providerId,
            $data['billing_status'] === 'exempt' ? null : $startsOn,
            $data['billing_status'] === 'exempt'
                ? 'This provider was made exempt from the platform fee.'
                : 'Billing now starts on ' . format_date($startsOn, 'd M Y') . '.'
        );

        $this->refreshStatus($providerId);
        BillingGuard::forget($providerId);

        $terms = money($fee) . ' every ' . ($cycle === 1 ? 'month' : $cycle . ' months');

        AuditLog::record('billing_terms', 'provider', $providerId,
            'Set the platform fee to ' . $terms . ', starting ' . format_date($startsOn, 'd M Y')
            . ($cancelled > 0 ? '. Cancelled ' . $cancelled . ' invoice(s) the new terms no longer ask for' : ''));

        // Tell the provider. They are the one who has to pay it, and until
        // now the terms changed under them without a word.
        $this->notifyTerms($providerId, $data['billing_status'], $terms, $startsOn, $cancelled);

        return [
            'ok'      => true,
            'message' => 'Billing terms saved: ' . $terms
                . ', first due ' . format_date($startsOn, 'd M Y') . '.'
                . ($cancelled > 0
                    ? ' ' . $cancelled . ' invoice' . ($cancelled === 1 ? '' : 's')
                      . ' the new terms no longer ask for ' . ($cancelled === 1 ? 'was' : 'were')
                      . ' cancelled, and any lock has been lifted.'
                    : ''),
            'starts_on' => $startsOn,
            'cancelled' => $cancelled,
        ];
    }

    /**
     * Cancels unpaid invoices for periods the provider is no longer billed
     * for, and lifts anything those invoices were causing.
     *
     * Paid invoices are never touched: that money has moved, and rewriting
     * history to match a new agreement would be a lie about the accounts.
     *
     * @param string|null $startsOn cancel unpaid invoices whose period began
     *                              before this date; null cancels them all
     * @return int how many were cancelled
     */
    public function cancelInvoicesBefore(int $providerId, ?string $startsOn, string $reason = ''): int
    {
        $where  = "provider_id = ? AND status = 'unpaid'";
        $params = [$providerId];

        if ($startsOn !== null) {
            $where   .= ' AND period_start < ?';
            $params[] = $startsOn;
        }

        $doomed = $this->db->fetchAll("SELECT * FROM platform_invoices WHERE $where", $params);
        if (!$doomed) {
            return 0;
        }

        foreach ($doomed as $invoice) {
            $this->db->update('platform_invoices', [
                'status' => 'cancelled',
                'notes'  => mb_substr($reason, 0, 255) ?: null,
            ], 'id = ?', [(int)$invoice['id']]);

            AuditLog::record('invoice_cancelled', 'provider', $providerId,
                'Cancelled ' . $invoice['invoice_number'] . ' (' . money((float)$invoice['amount']) . '). ' . $reason);
        }

        // Every reminder and lock those invoices caused goes with them.
        Alert::clearTypes(
            ['platform_fee_due', 'platform_fee_overdue', 'platform_fee_reminder',
             'provider_locked', 'service_suspended'],
            'provider',
            $providerId
        );

        return count($doomed);
    }

    /** Tells the provider what their fee agreement now says. */
    private function notifyTerms(int $providerId, string $status, string $terms, string $startsOn, int $cancelled): void
    {
        if ($status === 'exempt') {
            Alert::raiseFor($providerId, 'platform_fee_terms', 'info',
                'You are exempt from the platform fee',
                'Nothing is owed, and any outstanding invoice has been cancelled.',
                'provider', $providerId);
            return;
        }

        $future = $startsOn > date('Y-m-d');

        Alert::raiseFor($providerId, 'platform_fee_terms', 'info',
            'Your platform fee has been set',
            $terms . '. ' . ($future
                ? 'The first payment falls due on ' . format_date($startsOn, 'd M Y') . ', and nothing is owed before then.'
                : 'Billing starts ' . format_date($startsOn, 'd M Y') . '.')
            . ($cancelled > 0 ? ' Any earlier invoice has been cancelled.' : ''),
            'provider', $providerId);
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
     * @param int|null $providerId limit the run to one provider. BillingGuard
     *                             passes this so the first page load after a
     *                             billing date raises that provider's invoice
     *                             immediately, without waiting for cron.
     * @return array{raised:int,collected:int,unpaid:int}
     */
    public function runBilling(?int $providerId = null): array
    {
        $raised = 0;

        $scope  = $providerId === null ? '' : ' AND id = ?';
        $params = $providerId === null ? [] : [$providerId];

        $due = $this->db->fetchAll(
            "SELECT * FROM providers
              WHERE billing_status <> 'exempt'
                AND status <> 'inactive'
                AND billing_next_due_on IS NOT NULL
                AND billing_next_due_on <= CURDATE()" . $scope,
            $params
        );

        foreach ($due as $provider) {
            if ($this->raiseInvoice($provider)) {
                $raised++;
            }
        }

        $collection = $this->collectDue($providerId);

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

        /*
         * An invoice raised late must still give the agreed number of days
         * to pay it.
         *
         * Normally an invoice is raised on the day its period starts and
         * this changes nothing. But a billing date moved back to a past day,
         * or a cron that did not run for a week, would otherwise produce an
         * invoice whose deadline had already expired - the provider is
         * handed a bill and locked out in the same instant, for a demand
         * they had no chance to meet. Nobody can settle a bill before they
         * have been shown it.
         */
        $earliestFair = date('Y-m-d', strtotime('+' . $dueDays . ' days'));
        if ($dueOn < $earliestFair) {
            $dueOn = $earliestFair;
        }

        if ($this->invoices->existsForPeriod($providerId, $periodStart)) {
            // Already invoiced; just move the pointer on.
            $this->advanceCycle($providerId, $periodStart, $cycle);
            return false;
        }

        /*
         * A cancelled invoice may already occupy this period - the terms
         * were moved forward and then back again. The unique key on
         * (provider_id, period_start) means it has to be reopened rather
         * than duplicated, and the row keeps its history either way.
         */
        $revived = $this->invoices->cancelledForPeriod($providerId, $periodStart);

        try {
            if ($revived) {
                $invoiceId = (int)$revived['id'];
                $this->db->update('platform_invoices', [
                    'period_end' => $periodEnd,
                    'amount'     => (float)$provider['platform_fee_amount'],
                    'currency'   => $provider['currency_code'] ?? 'TZS',
                    'due_on'     => $dueOn,
                    'status'     => 'unpaid',
                    'paid_at'    => null,
                    'paid_from'  => null,
                    'notes'      => 'Reopened - this period is being billed again.',
                ], 'id = ?', [$invoiceId]);
            } else {
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
            }
        } catch (Throwable $e) {
            Logger::error('Could not raise a platform invoice: ' . $e->getMessage(), ['provider_id' => $providerId]);
            return false;
        }

        $number = (string)$this->db->fetchColumn(
            'SELECT invoice_number FROM platform_invoices WHERE id = ? LIMIT 1', [$invoiceId], ''
        );

        $this->advanceCycle($providerId, $periodStart, $cycle);
        $this->db->update('providers', ['billing_status' => 'due'], 'id = ?', [$providerId]);

        AuditLog::record('invoice_raised', 'provider', $providerId,
            ($revived ? 'Reopened' : 'Raised') . ' platform fee invoice ' . $number . ' for '
            . money((float)$provider['platform_fee_amount'])
            . ', covering ' . format_date($periodStart, 'd M Y') . ' to ' . format_date($periodEnd, 'd M Y')
            . ', to be paid by ' . format_date($dueOn, 'd M Y'),
            'system', 'Billing');

        Alert::raiseFor(null, 'platform_fee_due', 'info',
            'Platform fee due for ' . $provider['business_name'],
            money((float)$provider['platform_fee_amount']) . ' is due by ' . format_date($dueOn, 'd M Y') . '.',
            'provider', $providerId);

        // And the provider, who is the one that has to find the money.
        Alert::raiseFor($providerId, 'platform_fee_invoice', 'info',
            'Your platform fee invoice is ready',
            money((float)$provider['platform_fee_amount']) . ' for '
            . format_date($periodStart, 'd M Y') . ' to ' . format_date($periodEnd, 'd M Y')
            . ', to be paid by ' . format_date($dueOn, 'd M Y') . '.',
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
    public function collectDue(?int $providerId = null): array
    {
        if ((string)setting('auto_charge_fee_from_wallet', '1') !== '1') {
            return ['collected' => 0, 'unpaid' => 0, 'total' => 0.0];
        }

        $collected = 0;
        $unpaid    = 0;
        $total     = 0.0;

        foreach ($this->invoices->dueForCollection(100, $providerId) as $invoice) {
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
        $this->announcePayment($providerId, $invoice, $amount, 'their wallet');

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
        $this->announcePayment((int)$invoice['provider_id'], $invoice, (float)$invoice['amount'], 'outside the platform');

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

    /**
     * Says out loud that a fee has been paid: on the owner's bell, because
     * money arriving is the thing they most want to know, and by taking
     * down every demand the provider was still looking at.
     */
    private function announcePayment(int $providerId, array $invoice, float $amount, string $how): void
    {
        $name = (string)$this->db->fetchColumn(
            'SELECT business_name FROM providers WHERE id = ? LIMIT 1',
            [$providerId],
            ''
        );

        Alert::clearTypes(
            ['platform_fee_due', 'platform_fee_overdue', 'platform_fee_invoice',
             'platform_fee_reminder', 'platform_fee_locked'],
            'provider',
            $providerId
        );

        Alert::raiseFor(null, 'platform_fee_paid', 'info',
            'Platform fee received from ' . ($name ?: 'a provider'),
            money($amount) . ' for ' . $invoice['invoice_number'] . ', paid from ' . $how . '.',
            'invoice', (int)$invoice['id']);

        BillingGuard::forget($providerId);
    }

    private function markOverdue(int $providerId, array $invoice): void
    {
        $this->db->update('providers', ['billing_status' => 'overdue'], 'id = ?', [$providerId]);

        Alert::raiseFor(null, 'platform_fee_overdue', 'warning',
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

        // Standing has changed, so the lock has to be re-decided in the same
        // breath - this is what reopens an account the instant it is paid.
        try {
            (new BillingGuard())->state($providerId, false);
        } catch (Throwable $e) {
            Logger::error('Could not refresh the fee lock: ' . $e->getMessage(), ['provider_id' => $providerId]);
        }
    }

    /* ================================================================= */
    /* Paying the fee from a phone                                       */
    /* ================================================================= */

    /*
     * The wallet was the only way to settle an invoice, which works for a
     * provider selling by mobile money and not at all for one selling by
     * voucher: their wallet is always empty, because they take that cash
     * themselves. They would be locked out with no way back in.
     *
     * So a fee can also be charged straight to the provider's phone. It is
     * deliberately NOT written to the `payments` table: that table is
     * customer sales, scoped per provider and reported as their revenue.
     * Money going the other way belongs on the invoice.
     */

    /** Sends the USSD prompt that settles one invoice. */
    public function payByMobile(int $invoiceId, string $phone): array
    {
        if ((string)setting('platform_fee_pay_by_mobile', '1') !== '1') {
            return ['ok' => false, 'message' => 'Paying the fee by phone is switched off. Please use your wallet or contact the platform.'];
        }

        $invoice = $this->db->fetchOne('SELECT * FROM platform_invoices WHERE id = ? LIMIT 1', [$invoiceId]);
        if (!$invoice) {
            return ['ok' => false, 'message' => 'That invoice could not be found.'];
        }
        if ($invoice['status'] !== 'unpaid') {
            return ['ok' => true, 'message' => 'That invoice is already ' . $invoice['status'] . '.'];
        }

        $phone = Validator::normalisePhone($phone);
        if ($phone === '') {
            return ['ok' => false, 'message' => 'Enter the mobile number that will pay this fee.'];
        }

        // An unanswered prompt from a minute ago is still live on the handset;
        // sending a second one would risk being charged twice.
        if ($invoice['pay_status'] === 'pending' && $invoice['pay_started_at'] !== null
            && (time() - strtotime((string)$invoice['pay_started_at'])) < 120) {
            return [
                'ok'      => true,
                'pending' => true,
                'message' => 'A payment prompt was already sent to ' . $invoice['pay_phone'] . '. Check that phone and enter the PIN.',
            ];
        }

        $payments = new PaymentService();
        $gateway  = $payments->provider();
        $provider = $this->db->fetchOne('SELECT business_name, email FROM providers WHERE id = ? LIMIT 1',
            [(int)$invoice['provider_id']]) ?? [];

        $reference = 'FEE-' . $invoice['invoice_number'] . '-' . strtoupper(random_code(4));

        $this->db->update('platform_invoices', [
            'pay_reference'  => $reference,
            'pay_provider'   => $gateway->name(),
            'pay_phone'      => $phone,
            'pay_status'     => 'pending',
            'pay_started_at' => date('Y-m-d H:i:s'),
            'pay_message'    => null,
        ], 'id = ?', [$invoiceId]);

        $result = $gateway->createOrder([
            'amount'      => (float)$invoice['amount'],
            'currency'    => $invoice['currency'] ?? 'TZS',
            'reference'   => $reference,
            'payer_name'  => $provider['business_name'] ?? 'Provider',
            'payer_phone' => $phone,
            'payer_email' => filter_var($provider['email'] ?? '', FILTER_VALIDATE_EMAIL) ? $provider['email'] : '',
            'description' => 'Platform fee ' . $invoice['invoice_number'],
        ]);

        if (!$result['ok']) {
            $this->db->update('platform_invoices', [
                'pay_status'  => 'failed',
                'pay_message' => mb_substr((string)$result['message'], 0, 255),
            ], 'id = ?', [$invoiceId]);

            Logger::payment('Platform fee charge could not be started', [
                'invoice' => $invoice['invoice_number'], 'message' => $result['message'],
            ]);
            return ['ok' => false, 'message' => $result['message']];
        }

        $this->db->update('platform_invoices', [
            'pay_provider_ref' => $result['provider_ref'] ?? null,
            'pay_status'       => 'pending',
            'pay_message'      => mb_substr((string)$result['message'], 0, 255),
        ], 'id = ?', [$invoiceId]);

        AuditLog::record('fee_payment_started', 'provider', (int)$invoice['provider_id'],
            'Started a mobile money payment of ' . money((float)$invoice['amount'])
            . ' for platform fee ' . $invoice['invoice_number'] . ' on ' . $phone);

        // Some gateways settle a small charge immediately.
        if (($result['status'] ?? 'pending') === 'successful') {
            return $this->refreshFeePayment($invoiceId);
        }

        return [
            'ok'      => true,
            'pending' => true,
            'message' => $result['message'] ?: 'Check ' . $phone . ' and enter your PIN to approve the payment.',
        ];
    }

    /**
     * Asks the gateway where a fee payment got to, and settles the invoice
     * if the money arrived. The pay screen polls this.
     */
    public function refreshFeePayment(int $invoiceId): array
    {
        $invoice = $this->db->fetchOne('SELECT * FROM platform_invoices WHERE id = ? LIMIT 1', [$invoiceId]);
        if (!$invoice) {
            return ['ok' => false, 'status' => 'unknown', 'message' => 'That invoice could not be found.'];
        }
        if ($invoice['status'] === 'paid') {
            return ['ok' => true, 'status' => 'successful', 'message' => 'This fee is paid.'];
        }
        if (empty($invoice['pay_provider_ref'])) {
            return ['ok' => false, 'status' => (string)$invoice['pay_status'], 'message' => 'No payment has been started for this invoice yet.'];
        }

        $gateway = (new PaymentService())->provider((string)$invoice['pay_provider']);
        $result  = $gateway->checkStatus((string)$invoice['pay_provider_ref']);

        if ($result['status'] === 'successful') {
            $this->db->update('platform_invoices', [
                'status'      => 'paid',
                'paid_at'     => date('Y-m-d H:i:s'),
                'paid_from'   => 'mobile_money',
                'pay_status'  => 'successful',
                'pay_message' => mb_substr((string)$result['message'], 0, 255),
            ], 'id = ?', [$invoiceId]);

            $this->refreshStatus((int)$invoice['provider_id']);
            $this->announcePayment((int)$invoice['provider_id'], $invoice, (float)$invoice['amount'],
                'mobile money on ' . $invoice['pay_phone']);

            AuditLog::record('invoice_paid', 'provider', (int)$invoice['provider_id'],
                'Platform fee ' . $invoice['invoice_number'] . ' paid by mobile money ('
                . money((float)$invoice['amount']) . ' from ' . $invoice['pay_phone'] . ')');

            return ['ok' => true, 'status' => 'successful',
                    'message' => 'Payment received. ' . $invoice['invoice_number'] . ' is settled.'];
        }

        if (in_array($result['status'], ['failed', 'cancelled'], true)) {
            $this->db->update('platform_invoices', [
                'pay_status'  => $result['status'],
                'pay_message' => mb_substr((string)$result['message'], 0, 255),
            ], 'id = ?', [$invoiceId]);
        }

        return ['ok' => $result['ok'], 'status' => $result['status'], 'message' => $result['message']];
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
