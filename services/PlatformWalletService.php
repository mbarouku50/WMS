<?php
/**
 * WMS - The platform's own money.
 *
 * Fees collected from providers land in the platform's SonicPesa merchant
 * account, because the API keys are the platform's. Until now they simply
 * accumulated there with nothing in WMS to draw them down. This is that.
 *
 *      provider pays a fee   ->  platform_invoices.status = paid
 *      owner withdraws       ->  SonicPesa payout  ->  platform_withdrawals
 *
 * There is no wallet table for the platform, and that is on purpose. A
 * balance stored in a column can drift from the truth; this one is derived
 * every time it is asked for, from two things that are already facts:
 *
 *      available = fees actually collected - payouts already sent
 *
 * Note what is NOT counted as available: money WMS is holding on behalf of
 * providers. That sits in the same merchant account but it is theirs, and
 * the owner withdrawing it would leave the platform unable to pay out a
 * provider who asked. The figure is shown separately so the owner can see
 * the difference between what is in the account and what is theirs.
 */
class PlatformWalletService
{
    private Database $db;
    private PlatformPayout $payouts;

    public function __construct()
    {
        $this->db      = Database::getInstance();
        $this->payouts = new PlatformPayout();
    }

    /* ================================================================= */
    /* What the platform has                                             */
    /* ================================================================= */

    /**
     * @return array{earned:float,paid_out:float,in_flight:float,available:float,
     *               owed_to_providers:float,in_merchant_account:float,
     *               collected_month:float,outstanding:float}
     */
    public function balance(): array
    {
        $fees = $this->db->fetchOne(
            "SELECT
                COALESCE(SUM(CASE WHEN status = 'paid' THEN amount END),0) AS earned,
                COALESCE(SUM(CASE WHEN status = 'paid'
                                   AND paid_at >= DATE_FORMAT(CURDATE(),'%Y-%m-01') THEN amount END),0) AS collected_month,
                COALESCE(SUM(CASE WHEN status = 'unpaid' THEN amount END),0) AS outstanding
               FROM platform_invoices"
        ) ?? [];

        /*
         * A fee settled from a provider's wallet never reached the merchant
         * account as new money - it was already there, held for them, and
         * paying the fee simply moved whose it was. A fee paid by mobile
         * money or in cash did arrive. Both are earnings; the split matters
         * only for the note about what is physically in the account.
         */
        $held = (float)$this->db->fetchColumn(
            'SELECT COALESCE(SUM(wallet_balance),0) FROM providers',
            [],
            0
        );

        $totals    = $this->payouts->totals();
        $earned    = (float)($fees['earned'] ?? 0);
        $paidOut   = (float)$totals['paid_out'];
        $inFlight  = (float)$totals['in_flight'];
        $available = max(0, round($earned - $paidOut - $inFlight, 2));

        return [
            'earned'              => $earned,
            'paid_out'            => $paidOut,
            'in_flight'           => $inFlight,
            'in_flight_count'     => (int)$totals['in_flight_count'],
            'payout_fees'         => (float)$totals['payout_fees'],
            'available'           => $available,
            'owed_to_providers'   => $held,
            'in_merchant_account' => round($available + $held, 2),
            'collected_month'     => (float)($fees['collected_month'] ?? 0),
            'outstanding'         => (float)($fees['outstanding'] ?? 0),
        ];
    }

    /* ================================================================= */
    /* Taking it out                                                     */
    /* ================================================================= */

    /**
     * Sends the platform owner their own money.
     *
     * Unlike a provider withdrawal there is no approval step: the person
     * asking is the person who would approve it. So this validates, records
     * and sends in one go, and the row exists before the gateway is called
     * so a payout can never happen without a record of it.
     */
    public function withdraw(array $input): array
    {
        $amount  = round((float)($input['amount'] ?? 0), 2);
        $minimum = (float)setting('platform_withdrawal_minimum', 5000);
        $balance = $this->balance();

        if ($amount < $minimum) {
            return ['ok' => false, 'message' => 'The smallest withdrawal is ' . money($minimum) . '.'];
        }
        if ($amount > $balance['available']) {
            return [
                'ok' => false,
                'message' => 'You can withdraw up to ' . money($balance['available']) . ' of collected fees right now.'
                    . ($balance['in_flight'] > 0
                        ? ' ' . money($balance['in_flight']) . ' is already on its way out.'
                        : '')
                    . ($balance['owed_to_providers'] > 0
                        ? ' The other ' . money($balance['owed_to_providers']) . ' in the merchant account belongs to providers.'
                        : ''),
            ];
        }

        $method  = Validator::string($input['method'] ?? '', 40);
        $account = Validator::string($input['account_number'] ?? '', 60);
        $name    = Validator::string($input['account_name'] ?? '', 140);

        if (!array_key_exists($method, Withdrawal::METHODS)) {
            return ['ok' => false, 'message' => 'Choose where the money should be sent.'];
        }
        if ($account === '' || $name === '') {
            return ['ok' => false, 'message' => 'Enter the account number and the name on the account.'];
        }

        // Selcom's own account-number rules, same as a provider payout.
        if ($method === 'Selcom') {
            $digits = preg_replace('/\D+/', '', $account) ?? '';
            if (str_starts_with($digits, '0') || str_starts_with($digits, '255')) {
                return ['ok' => false, 'message' => 'For Selcom the account number must not start with 0 or 255.'];
            }
            if (strlen($digits) <= 9 && strlen($digits) !== 9) {
                return ['ok' => false, 'message' => 'For Selcom use a 9-digit phone number (7XXXXXXXX) or a longer card number.'];
            }
        }

        $gateway = new SonicPesaProvider();
        if (!$gateway->isConfigured()) {
            return ['ok' => false, 'message' => 'SonicPesa is not configured, so no payout can be sent. Add the API key and secret under Settings → Payments.'];
        }

        $reference = $this->payouts->nextReference();

        // The record comes first. If the gateway call dies mid-flight, there
        // is still a row saying money may have left, rather than nothing.
        $payoutId = $this->db->insert('platform_withdrawals', [
            'reference'      => $reference,
            'amount'         => $amount,
            'currency'       => (string)setting('payment_currency', 'TZS'),
            'method'         => $method,
            'account_number' => $account,
            'account_name'   => $name,
            'status'         => 'processing',
            'requested_by'   => Auth::id(),
            'note'           => Validator::string($input['note'] ?? '', 255) ?: null,
        ]);

        $result = $gateway->createPayout($amount, $method, $account, $name);

        if (!$result['ok']) {
            $this->db->update('platform_withdrawals', [
                'status'         => 'failed',
                'failure_reason' => mb_substr((string)$result['message'], 0, 255),
            ], 'id = ?', [$payoutId]);

            AuditLog::record('platform_payout_failed', 'platform_withdrawal', $payoutId,
                'Payout of ' . money($amount) . ' to ' . $method . ' was refused: ' . $result['message']);

            return ['ok' => false, 'message' => 'The payout was refused: ' . $result['message']];
        }

        $data = $result['data'] ?? [];
        $this->db->update('platform_withdrawals', [
            'provider_ref' => isset($data['withdrawal_id']) ? (string)$data['withdrawal_id'] : null,
            'fee'          => (float)($data['fee'] ?? 0),
            'net_amount'   => (float)($data['net_amount'] ?? $amount),
            'raw_response' => mb_substr(json_encode($data) ?: '', 0, 8000),
        ], 'id = ?', [$payoutId]);

        AuditLog::record('platform_payout', 'platform_withdrawal', $payoutId,
            'Withdrew ' . money($amount) . ' of platform fees to ' . $method . ' ' . $account . ' (' . $name . ')');

        if (strtolower((string)($data['status'] ?? 'pending')) === 'completed') {
            $this->complete($payoutId);
            return ['ok' => true, 'message' => money($amount) . ' sent to your ' . $method . ' account.'];
        }

        return [
            'ok'      => true,
            'message' => money($amount) . ' is on its way to your ' . $method . ' account. '
                       . 'Reference ' . $reference . '. It will show as completed once SonicPesa confirms it.',
        ];
    }

    /** SonicPesa confirmed the money landed. */
    public function complete(int $payoutId): array
    {
        $payout = $this->db->fetchOne('SELECT * FROM platform_withdrawals WHERE id = ? LIMIT 1', [$payoutId]);
        if (!$payout) {
            return ['ok' => false, 'message' => 'That payout could not be found.'];
        }
        if ($payout['status'] === 'completed') {
            return ['ok' => true, 'message' => 'Already completed.'];
        }

        $this->db->update('platform_withdrawals', [
            'status'       => 'completed',
            'completed_at' => date('Y-m-d H:i:s'),
        ], 'id = ?', [$payoutId]);

        AuditLog::record('platform_payout_complete', 'platform_withdrawal', $payoutId,
            'Payout ' . $payout['reference'] . ' completed (' . money((float)$payout['amount']) . ')',
            'system', 'Billing');

        return ['ok' => true, 'message' => 'Payout completed.'];
    }

    /** It did not happen. The money never left, so it is available again. */
    public function fail(int $payoutId, string $reason = '', string $status = 'failed'): array
    {
        $payout = $this->db->fetchOne('SELECT * FROM platform_withdrawals WHERE id = ? LIMIT 1', [$payoutId]);
        if (!$payout) {
            return ['ok' => false, 'message' => 'That payout could not be found.'];
        }
        if (in_array($payout['status'], ['completed', 'failed', 'cancelled'], true)) {
            return ['ok' => true, 'message' => 'That payout is already closed.'];
        }

        $this->db->update('platform_withdrawals', [
            'status'         => in_array($status, ['failed', 'cancelled'], true) ? $status : 'failed',
            'failure_reason' => mb_substr($reason, 0, 255) ?: null,
        ], 'id = ?', [$payoutId]);

        AuditLog::record('platform_payout_' . $status, 'platform_withdrawal', $payoutId,
            'Payout ' . $payout['reference'] . ' ' . $status . '. ' . $reason);

        Alert::raiseFor(null, 'platform_payout_failed', 'warning',
            'Your withdrawal did not go through',
            'Payout ' . $payout['reference'] . ' for ' . money((float)$payout['amount'])
            . ' failed. The money is still in your merchant account. ' . $reason,
            'platform_withdrawal', $payoutId);

        return ['ok' => true, 'message' => 'Payout marked as ' . $status . '. The money is available again.'];
    }

    /**
     * Polls SonicPesa for the platform's own payouts still in flight.
     * Called by cron, alongside the provider payout reconciliation.
     *
     * @return array{checked:int,settled:int,failed:int}
     */
    public function reconcile(int $limit = 25): array
    {
        $gateway = new SonicPesaProvider();
        if (!$gateway->isConfigured()) {
            return ['checked' => 0, 'settled' => 0, 'failed' => 0];
        }

        $checked = $settled = $failed = 0;

        foreach ($this->payouts->inFlight($limit) as $payout) {
            $checked++;
            try {
                $status = $gateway->payoutStatus((int)$payout['provider_ref']);
                if (!$status['ok']) {
                    continue;   // unreachable this run; try again next time
                }
                $state = strtolower((string)($status['data']['status'] ?? ''));

                // Same vocabulary the provider payout reconciliation accepts.
                if (in_array($state, ['completed', 'success', 'successful'], true)) {
                    $this->complete((int)$payout['id']);
                    $settled++;
                } elseif (in_array($state, ['failed', 'rejected', 'cancelled'], true)) {
                    $this->fail((int)$payout['id'],
                        'SonicPesa reported: ' . $state,
                        $state === 'cancelled' ? 'cancelled' : 'failed');
                    $failed++;
                }
            } catch (Throwable $e) {
                // One unreachable status check must not stop the others.
                Logger::error('Could not check a platform payout: ' . $e->getMessage(),
                    ['payout_id' => (int)$payout['id']]);
            }
        }

        return ['checked' => $checked, 'settled' => $settled, 'failed' => $failed];
    }
}
