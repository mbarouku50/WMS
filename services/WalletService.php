<?php
/**
 * WMS - Provider wallet.
 *
 * The only thing that moves a provider's money. Every credit and debit
 * updates the cached balance on `providers` and appends a ledger row in the
 * same database transaction, so the two can never drift apart.
 *
 *      Customer pays  ->  PaymentService::fulfil()  ->  WalletService::credit()
 *      Provider withdraws ->  requestWithdrawal()  ->  SonicPesa payout
 *      Platform fee due   ->  BillingService       ->  WalletService::debit()
 *
 * Money model: customer payments land in the PLATFORM's SonicPesa merchant
 * account, because the API keys belong to the platform. WMS credits the
 * selling provider here and settles to them when they withdraw.
 */
class WalletService
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /* ================================================================= */
    /* Balance                                                           */
    /* ================================================================= */

    /** @return array{balance:float,held:float,available:float,earned:float,withdrawn:float} */
    public function balance(int $providerId): array
    {
        $row = $this->db->fetchOne(
            'SELECT wallet_balance, wallet_held, lifetime_earned, lifetime_withdrawn, currency
               FROM providers WHERE id = ? LIMIT 1',
            [$providerId]
        ) ?? [];

        $balance = (float)($row['wallet_balance'] ?? 0);
        $held    = (float)($row['wallet_held'] ?? 0);

        return [
            'balance'   => $balance,
            'held'      => $held,
            'available' => max(0, $balance - $held),
            'earned'    => (float)($row['lifetime_earned'] ?? 0),
            'withdrawn' => (float)($row['lifetime_withdrawn'] ?? 0),
            'currency'  => $row['currency'] ?? 'TSh',
        ];
    }

    /* ================================================================= */
    /* Movements                                                         */
    /* ================================================================= */

    /**
     * Adds money to a provider's wallet.
     *
     * @param string $type sale|withdrawal_reversal|adjustment
     * @return array{ok:bool,message:string,balance?:float}
     */
    public function credit(int $providerId, float $amount, string $type, string $description, array $links = []): array
    {
        return $this->move($providerId, abs($amount), 'credit', $type, $description, $links);
    }

    /**
     * Takes money out of a provider's wallet.
     *
     * @param string $type platform_fee|withdrawal|refund|adjustment
     */
    public function debit(int $providerId, float $amount, string $type, string $description, array $links = []): array
    {
        return $this->move($providerId, abs($amount), 'debit', $type, $description, $links);
    }

    /**
     * The single write path. Locks the provider row, applies the change,
     * appends the ledger entry, commits.
     */
    private function move(int $providerId, float $amount, string $direction, string $type, string $description, array $links): array
    {
        if ($amount <= 0) {
            return ['ok' => false, 'message' => 'The amount must be greater than zero.'];
        }
        if (!array_key_exists($type, Wallet::TYPES)) {
            return ['ok' => false, 'message' => 'Unknown wallet entry type.'];
        }

        $this->db->beginTransaction();
        try {
            // SELECT ... FOR UPDATE so two concurrent payments cannot both
            // read the same starting balance.
            $provider = $this->db->fetchOne(
                'SELECT id, wallet_balance, wallet_held, lifetime_earned, lifetime_withdrawn, currency_code
                   FROM providers WHERE id = ? FOR UPDATE',
                [$providerId]
            );
            if (!$provider) {
                $this->db->rollback();
                return ['ok' => false, 'message' => 'That provider no longer exists.'];
            }

            $current = (float)$provider['wallet_balance'];
            $new     = $direction === 'credit' ? $current + $amount : $current - $amount;

            // A debit may not take the wallet negative - except a platform
            // fee, which is a genuine debt and is allowed to.
            if ($direction === 'debit' && $new < 0 && $type !== 'platform_fee') {
                $this->db->rollback();
                return [
                    'ok'      => false,
                    'message' => 'Not enough balance. Available ' . money($current) . ', requested ' . money($amount) . '.',
                ];
            }

            $updates = ['wallet_balance' => round($new, 2)];
            if ($direction === 'credit' && $type === 'sale') {
                $updates['lifetime_earned'] = round((float)$provider['lifetime_earned'] + $amount, 2);
            }
            if ($direction === 'debit' && $type === 'withdrawal') {
                $updates['lifetime_withdrawn'] = round((float)$provider['lifetime_withdrawn'] + $amount, 2);
            }
            $this->db->update('providers', $updates, 'id = ?', [$providerId]);

            /*
             * uq_wt_payment_type makes this insert the point where a second
             * credit for the same sale is refused. The webhook, the browser
             * status poll and the cron reconciliation all reach fulfil(), and
             * two of them arriving together would otherwise both pass the
             * "already credited?" check and both credit the wallet.
             */
            $this->db->insert('wallet_transactions', [
                'provider_id'   => $providerId,
                'type'          => $type,
                'direction'     => $direction,
                'amount'        => round($amount, 2),
                'balance_after' => round($new, 2),
                'currency'      => $provider['currency_code'] ?? 'TZS',
                'reference'     => $links['reference'] ?? null,
                'payment_id'    => $links['payment_id'] ?? null,
                'withdrawal_id' => $links['withdrawal_id'] ?? null,
                'invoice_id'    => $links['invoice_id'] ?? null,
                'description'   => mb_substr($description, 0, 255),
                'created_by'    => $links['created_by'] ?? Auth::id(),
            ]);

            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollback();

            // The unique index caught a duplicate: the other caller won the
            // race and the money has already moved. Nothing has been lost.
            if (self::isDuplicate($e)) {
                Logger::payment('Ignored a duplicate wallet movement', [
                    'provider_id' => $providerId, 'type' => $type,
                    'payment_id'  => $links['payment_id'] ?? null,
                ]);
                return ['ok' => true, 'message' => 'That movement was already recorded.', 'duplicate' => true];
            }

            Logger::error('Wallet movement failed: ' . $e->getMessage(), [
                'provider_id' => $providerId, 'type' => $type, 'direction' => $direction,
            ]);
            return ['ok' => false, 'message' => 'The wallet could not be updated. Please try again.'];
        }

        return ['ok' => true, 'message' => 'Wallet updated.', 'balance' => round($new, 2)];
    }

    /** True when MySQL refused a write because a unique key already held it. */
    private static function isDuplicate(Throwable $e): bool
    {
        for ($error = $e; $error !== null; $error = $error->getPrevious()) {
            if ((int)$error->getCode() === 1062 || str_contains($error->getMessage(), 'Duplicate entry')) {
                return true;
            }
        }
        return false;
    }

    /**
     * Credits a provider for a customer payment.
     * Called once, from PaymentService, when money has actually arrived.
     */
    public function creditSale(array $payment): array
    {
        $providerId = (int)($payment['provider_id'] ?? 0);
        if ($providerId <= 0) {
            return ['ok' => false, 'message' => 'That payment has no provider.'];
        }

        // Never credit the same payment twice.
        $already = $this->db->count(
            "SELECT COUNT(*) FROM wallet_transactions WHERE payment_id = ? AND type = 'sale'",
            [(int)$payment['id']]
        );
        if ($already > 0) {
            return ['ok' => true, 'message' => 'This sale was already credited.'];
        }

        return $this->credit(
            $providerId,
            (float)$payment['amount'],
            'sale',
            'Package sale · ' . ($payment['transaction_ref'] ?? ''),
            [
                'reference'  => $payment['transaction_ref'] ?? null,
                'payment_id' => (int)$payment['id'],
                'created_by' => null,
            ]
        );
    }

    /** Reverses a sale credit when a payment is refunded. */
    public function reverseSale(array $payment): array
    {
        $providerId = (int)($payment['provider_id'] ?? 0);
        if ($providerId <= 0) {
            return ['ok' => false, 'message' => 'That payment has no provider.'];
        }
        $credited = $this->db->count(
            "SELECT COUNT(*) FROM wallet_transactions WHERE payment_id = ? AND type = 'sale'",
            [(int)$payment['id']]
        );
        if ($credited === 0) {
            return ['ok' => true, 'message' => 'Nothing to reverse - this sale was never credited.'];
        }

        return $this->debit(
            $providerId,
            (float)$payment['amount'],
            'refund',
            'Refund · ' . ($payment['transaction_ref'] ?? ''),
            ['reference' => $payment['transaction_ref'] ?? null, 'payment_id' => (int)$payment['id']]
        );
    }

    /* ================================================================= */
    /* Withdrawals                                                       */
    /* ================================================================= */

    /**
     * A provider asks for their money.
     *
     * The amount moves from available to held immediately, so it cannot be
     * requested twice while the payout is in flight.
     *
     * @return array{ok:bool,message:string,withdrawal_id?:int}
     */
    public function requestWithdrawal(int $providerId, array $input): array
    {
        $amount  = round((float)($input['amount'] ?? 0), 2);
        $minimum = (float)setting('withdrawal_minimum', Withdrawal::MINIMUM);

        $provider = $this->db->fetchOne('SELECT * FROM providers WHERE id = ? LIMIT 1', [$providerId]);
        if (!$provider) {
            return ['ok' => false, 'message' => 'That provider could not be found.'];
        }
        if ($provider['status'] !== 'active') {
            return ['ok' => false, 'message' => 'Withdrawals are paused while this provider is ' . $provider['status'] . '.'];
        }

        $balance = $this->balance($providerId);

        if ($amount < $minimum) {
            return [
                'ok' => false,
                'message' => 'The smallest withdrawal is ' . money($minimum) . '. You have '
                    . money($balance['available']) . ' available.',
            ];
        }
        if ($amount > $balance['available']) {
            return [
                'ok' => false,
                'message' => 'You can withdraw up to ' . money($balance['available']) . ' right now.'
                    . ($balance['held'] > 0 ? ' ' . money($balance['held']) . ' is held against a withdrawal already in progress.' : ''),
            ];
        }

        // Settle what is owed to the platform before paying money out.
        $outstanding = (new PlatformInvoice())->outstandingTotal($providerId);
        if ($outstanding > 0 && ($balance['available'] - $amount) < $outstanding) {
            return [
                'ok' => false,
                'message' => 'You owe ' . money($outstanding) . ' in platform fees. Leave at least that much in the wallet, '
                    . 'or settle the invoice first.',
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

        // Selcom has its own account-number rules.
        if ($method === 'Selcom') {
            $digits = preg_replace('/\D+/', '', $account) ?? '';
            if (str_starts_with($digits, '0') || str_starts_with($digits, '255')) {
                return ['ok' => false, 'message' => 'For Selcom the account number must not start with 0 or 255.'];
            }
            if (strlen($digits) !== 9 && strlen($digits) <= 9) {
                return ['ok' => false, 'message' => 'For Selcom use a 9-digit phone number (7XXXXXXXX) or a longer card number.'];
            }
        }

        $withdrawals = new Withdrawal();
        $reference   = $withdrawals->nextReference();

        $this->db->beginTransaction();
        try {
            // Hold the funds.
            $this->db->execute(
                'UPDATE providers SET wallet_held = wallet_held + ? WHERE id = ?',
                [$amount, $providerId]
            );

            $withdrawalId = $this->db->insert('withdrawals', [
                'provider_id'    => $providerId,
                'reference'      => $reference,
                'amount'         => $amount,
                'currency'       => $provider['currency_code'] ?? 'TZS',
                'method'         => $method,
                'account_number' => $account,
                'account_name'   => $name,
                'status'         => 'pending',
                'requested_by'   => Auth::id(),
            ]);

            // Remember the destination for next time.
            $this->db->update('providers', [
                'payout_method'         => $method,
                'payout_account_number' => $account,
                'payout_account_name'   => $name,
            ], 'id = ?', [$providerId]);

            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollback();
            Logger::error('Withdrawal request failed: ' . $e->getMessage(), ['provider_id' => $providerId]);
            return ['ok' => false, 'message' => 'The withdrawal could not be created. Please try again.'];
        }

        AuditLog::record('withdrawal_request', 'withdrawal', $withdrawalId,
            'Requested ' . money($amount) . ' to ' . $method . ' ' . $account);

        Alert::raise('withdrawal_requested', 'info',
            'Withdrawal requested by ' . $provider['business_name'],
            money($amount) . ' to ' . $method . ' (' . $name . '). Reference ' . $reference . '.',
            'withdrawal', $withdrawalId);

        $needsApproval = (string)setting('withdrawal_requires_approval', '1') === '1';

        return [
            'ok'            => true,
            'withdrawal_id' => $withdrawalId,
            'message'       => $needsApproval
                ? 'Withdrawal requested. ' . money($amount) . ' is held while the platform reviews it.'
                : 'Withdrawal requested. ' . money($amount) . ' is being sent to your ' . $method . ' account.',
        ];
    }

    /**
     * Sends the payout to SonicPesa. Platform owner only.
     *
     * On success the held amount is debited for real; on refusal it is
     * released back to the available balance.
     */
    public function processWithdrawal(int $withdrawalId): array
    {
        $withdrawal = $this->db->fetchOne('SELECT * FROM withdrawals WHERE id = ? LIMIT 1', [$withdrawalId]);
        if (!$withdrawal) {
            return ['ok' => false, 'message' => 'That withdrawal could not be found.'];
        }
        if ($withdrawal['status'] !== 'pending') {
            return ['ok' => false, 'message' => 'That withdrawal has already been ' . $withdrawal['status'] . '.'];
        }

        $provider = new SonicPesaProvider();
        if (!$provider->isConfigured()) {
            return ['ok' => false, 'message' => 'SonicPesa is not configured, so no payout can be sent. Add the API key and secret under Settings → Payments.'];
        }

        /*
         * Claim the withdrawal before the gateway is called, in one statement
         * that only succeeds while it is still pending. Two administrators
         * approving the same payout at the same moment would otherwise both
         * read "pending" and both send real money.
         */
        $claimed = $this->db->execute(
            "UPDATE withdrawals SET status = 'processing', approved_by = ?
              WHERE id = ? AND status = 'pending'",
            [Auth::id(), $withdrawalId]
        );
        if ($claimed === 0) {
            return ['ok' => false, 'message' => 'Someone else is already sending that withdrawal.'];
        }

        $result = $provider->createPayout(
            (float)$withdrawal['amount'],
            (string)$withdrawal['method'],
            (string)$withdrawal['account_number'],
            (string)$withdrawal['account_name']
        );

        if (!$result['ok']) {
            $this->releaseHold($withdrawalId, 'failed', $result['message']);
            return ['ok' => false, 'message' => 'The payout was refused: ' . $result['message']];
        }

        $data      = $result['data'] ?? [];
        $payoutRef = isset($data['withdrawal_id']) ? trim((string)$data['withdrawal_id']) : '';

        $this->db->update('withdrawals', [
            'provider_ref' => $payoutRef !== '' ? $payoutRef : null,
            'fee'          => (float)($data['fee'] ?? 0),
            'net_amount'   => (float)($data['net_amount'] ?? $withdrawal['amount']),
            'raw_response' => mb_substr(json_encode($result['data']) ?: '', 0, 8000),
        ], 'id = ?', [$withdrawalId]);

        AuditLog::record('withdrawal_sent', 'withdrawal', $withdrawalId,
            'Sent ' . money((float)$withdrawal['amount']) . ' to ' . $withdrawal['method']);

        /*
         * Without a reference there is nothing to poll: reconcileWithdrawals()
         * only looks at rows that have one. The payout would sit in
         * "processing" for ever with the provider's money held, and nobody
         * would be told. Say so loudly instead.
         */
        if ($payoutRef === '') {
            Logger::error('SonicPesa accepted a payout but returned no withdrawal_id', [
                'withdrawal_id' => $withdrawalId, 'reference' => $withdrawal['reference'],
            ]);
            Alert::raise('withdrawal_unreferenced', 'warning',
                'A payout was sent without a SonicPesa reference',
                'Withdrawal ' . $withdrawal['reference'] . ' for ' . money((float)$withdrawal['amount'])
                . ' was accepted, but SonicPesa returned no withdrawal id, so WMS cannot poll it. '
                . 'Check the payout in the SonicPesa dashboard and close this withdrawal by hand.',
                'withdrawal', $withdrawalId);
        }

        // SonicPesa reports "pending" until it settles; the webhook or the
        // cron poll finishes the job.
        if (strtolower((string)($data['status'] ?? 'pending')) === 'completed') {
            $this->completeWithdrawal($withdrawalId);
            return ['ok' => true, 'message' => 'Payout completed.'];
        }

        return ['ok' => true, 'message' => 'Payout sent. It will complete once SonicPesa confirms it.'];
    }

    /**
     * The payout landed: turn the hold into a real debit.
     *
     * Reached from two directions at once - SonicPesa's payout.success
     * webhook and the cron reconciliation - so the row is claimed first, in
     * one statement. Whoever loses that race does nothing, instead of
     * releasing the hold and debiting the wallet a second time.
     */
    public function completeWithdrawal(int $withdrawalId): array
    {
        $withdrawal = $this->db->fetchOne('SELECT * FROM withdrawals WHERE id = ? LIMIT 1', [$withdrawalId]);
        if (!$withdrawal) {
            return ['ok' => false, 'message' => 'That withdrawal could not be found.'];
        }

        $wasStatus = (string)$withdrawal['status'];

        $claimed = $this->db->execute(
            "UPDATE withdrawals SET status = 'completed', completed_at = ?
              WHERE id = ? AND status IN ('pending','processing')",
            [date('Y-m-d H:i:s'), $withdrawalId]
        );
        if ($claimed === 0) {
            return ['ok' => true, 'message' => 'That withdrawal is already closed.'];
        }

        $providerId = (int)$withdrawal['provider_id'];
        $amount     = (float)$withdrawal['amount'];

        // Release the hold, then debit for real.
        $this->db->execute(
            'UPDATE providers SET wallet_held = GREATEST(0, wallet_held - ?) WHERE id = ?',
            [$amount, $providerId]
        );

        $result = $this->debit($providerId, $amount, 'withdrawal',
            'Withdrawal · ' . $withdrawal['reference'] . ' to ' . $withdrawal['method'],
            ['reference' => $withdrawal['reference'], 'withdrawal_id' => $withdrawalId, 'created_by' => null]);

        if (!$result['ok']) {
            // Put the hold and the status back rather than losing track of
            // the money: the payout is still in flight as far as WMS knows.
            $this->db->execute('UPDATE providers SET wallet_held = wallet_held + ? WHERE id = ?', [$amount, $providerId]);
            $this->db->update('withdrawals', ['status' => $wasStatus, 'completed_at' => null], 'id = ?', [$withdrawalId]);

            Alert::raise('withdrawal_debit_failed', 'danger',
                'A completed payout could not be debited',
                'Withdrawal ' . $withdrawal['reference'] . ' for ' . money($amount) . ' was paid out by SonicPesa '
                . 'but the wallet could not be debited: ' . $result['message'] . ' Please correct it manually.',
                'withdrawal', $withdrawalId);

            return $result;
        }

        AuditLog::record('withdrawal_complete', 'withdrawal', $withdrawalId,
            'Withdrawal ' . $withdrawal['reference'] . ' completed (' . money($amount) . ')');

        return ['ok' => true, 'message' => 'Withdrawal completed.'];
    }

    /**
     * The payout did not happen: give the money back.
     *
     * @param string $status failed|cancelled
     */
    public function releaseHold(int $withdrawalId, string $status = 'cancelled', string $reason = ''): array
    {
        $withdrawal = $this->db->fetchOne('SELECT * FROM withdrawals WHERE id = ? LIMIT 1', [$withdrawalId]);
        if (!$withdrawal) {
            return ['ok' => false, 'message' => 'That withdrawal could not be found.'];
        }

        /*
         * Close the row first, and only release the hold if this call is the
         * one that closed it. A failure webhook and the cron reconciliation
         * can both arrive; releasing twice would free money still held
         * against a different pending withdrawal.
         */
        $status  = in_array($status, ['failed', 'cancelled'], true) ? $status : 'cancelled';
        $claimed = $this->db->execute(
            "UPDATE withdrawals SET status = ?, failure_reason = ?
              WHERE id = ? AND status IN ('pending','processing')",
            [$status, mb_substr($reason, 0, 255) ?: null, $withdrawalId]
        );
        if ($claimed === 0) {
            return ['ok' => true, 'message' => 'That withdrawal is already closed.'];
        }

        $this->db->execute(
            'UPDATE providers SET wallet_held = GREATEST(0, wallet_held - ?) WHERE id = ?',
            [(float)$withdrawal['amount'], (int)$withdrawal['provider_id']]
        );

        AuditLog::record('withdrawal_' . $status, 'withdrawal', $withdrawalId,
            'Withdrawal ' . $withdrawal['reference'] . ' ' . $status . '. ' . $reason);

        if ($status === 'failed') {
            Alert::raise('withdrawal_failed', 'warning', 'A withdrawal failed',
                'Withdrawal ' . $withdrawal['reference'] . ' for ' . money((float)$withdrawal['amount'])
                . ' did not go through. The money has been returned to the wallet. ' . $reason,
                'withdrawal', $withdrawalId);
        }

        return ['ok' => true, 'message' => 'The held amount has been returned to the wallet.'];
    }

    /** Polls SonicPesa for payouts still in flight. Called by cron. */
    public function reconcileWithdrawals(int $limit = 25): array
    {
        $provider = new SonicPesaProvider();
        if (!$provider->isConfigured()) {
            return ['checked' => 0, 'settled' => 0];
        }

        $checked = 0;
        $settled = 0;

        foreach ((new Withdrawal())->inFlight($limit) as $withdrawal) {
            $checked++;
            $result = $provider->payoutStatus((string)$withdrawal['provider_ref']);
            if (!$result['ok']) {
                continue;
            }
            $status = strtolower((string)($result['data']['status'] ?? ''));

            if (in_array($status, ['completed', 'success', 'successful'], true)) {
                $this->completeWithdrawal((int)$withdrawal['id']);
                $settled++;
            } elseif (in_array($status, ['failed', 'rejected', 'cancelled'], true)) {
                $this->releaseHold((int)$withdrawal['id'], 'failed', 'SonicPesa reported: ' . $status);
                $settled++;
            }
        }

        return ['checked' => $checked, 'settled' => $settled];
    }
}
