<?php
/**
 * WMS - Payment service.
 *
 * Owns the money flow and everything that must happen when money lands:
 *
 *      Payment successful
 *          -> issue voucher
 *          -> open subscription
 *          -> grant access through NetworkService
 *
 * Providers are pluggable (see services/payments/).  Switching provider is a
 * settings change, not a rewrite.
 */
class PaymentService
{
    private Database $db;
    private Payment $payments;
    private Package $packages;

    public function __construct()
    {
        $this->db       = Database::getInstance();
        $this->payments = new Payment();
        $this->packages = new Package();
    }

    /* ------------------------------------------------------------ provider */

    /** The provider named in settings, falling back to Demo when unusable. */
    public function provider(?string $name = null): PaymentProvider
    {
        $name = $name ?: (string)setting('payment_provider', 'demo');

        $provider = match ($name) {
            'sonicpesa' => new SonicPesaProvider(),
            default     => new DemoPaymentProvider(),
        };

        if (!$provider->isConfigured()) {
            Logger::payment('Provider not configured, falling back to demo', ['provider' => $name]);
            return new DemoPaymentProvider();
        }
        return $provider;
    }

    /**
     * Resolves a provider WITHOUT the demo fallback.
     *
     * Used for anything security-sensitive - above all webhook signature
     * checking, where falling back to a provider that trusts every caller
     * would turn a misconfiguration into an open door.
     */
    public function providerStrict(?string $name = null): ?PaymentProvider
    {
        $name = $name ?: (string)setting('payment_provider', 'demo');

        $provider = match ($name) {
            'sonicpesa' => new SonicPesaProvider(),
            'demo'      => new DemoPaymentProvider(),
            default     => null,
        };

        return ($provider && $provider->isConfigured()) ? $provider : null;
    }

    /** Providers offered in the settings screen. */
    public function availableProviders(): array
    {
        return [
            'demo'      => (new DemoPaymentProvider())->label(),
            'sonicpesa' => (new SonicPesaProvider())->label(),
        ];
    }

    /* ------------------------------------------------------------ charging */

    /**
     * Starts a package purchase.
     *
     * @param array $input package_id, phone, name, email, customer_id
     * @return array{ok:bool,message:string,payment?:array,status?:string}
     */
    public function purchasePackage(array $input): array
    {
        // Package::find() is tenant scoped, so a package id from another
        // provider does not resolve here at all.
        $package = $this->packages->find((int)($input['package_id'] ?? 0));
        if (!$package || $package['status'] !== 'active') {
            return ['ok' => false, 'message' => 'That package is not available. Please choose another one.'];
        }

        // Refuse to take money for a provider that is not trading.
        $provider = $this->db->fetchOne(
            'SELECT status, business_name, mobile_money_enabled FROM providers WHERE id = ? LIMIT 1',
            [(int)$package['provider_id']]
        );
        if ($provider && $provider['status'] !== 'active') {
            return ['ok' => false, 'message' => 'This Wi-Fi service is temporarily unavailable. Please try again later.'];
        }

        /*
         * Selling by mobile money is a privilege the platform owner grants
         * per provider. Until they do, the provider sells through vouchers
         * only - so this is refused on the server, not merely hidden.
         */
        if ($provider && (int)$provider['mobile_money_enabled'] !== 1) {
            Logger::payment('Mobile money purchase refused - not enabled for this provider', [
                'provider_id' => (int)$package['provider_id'],
            ]);
            return [
                'ok' => false,
                'message' => 'This network does not accept mobile money payments yet. Please buy a voucher from the operator.',
            ];
        }

        $phone = Validator::normalisePhone((string)($input['phone'] ?? ''));
        if ($phone === '') {
            return ['ok' => false, 'message' => 'Enter the mobile number that will pay for this package.'];
        }

        $customerId  = isset($input['customer_id']) && $input['customer_id'] ? (int)$input['customer_id'] : null;
        $customers   = new Customer();
        $customerRow = null;
        if (!$customerId) {
            $customerRow = $customers->findOrCreateByPhone($phone, $input['name'] ?? null);
            $customerId  = (int)($customerRow['id'] ?? 0) ?: null;
        } else {
            $customerRow = $customers->find($customerId);
        }

        /*
         * The gateway needs a valid buyer email. Prefer what the payer typed,
         * fall back to the address already on their customer record, and let
         * the provider decide what to send when neither is usable.
         */
        $payerEmail = Validator::string($input['email'] ?? '', 160);
        if ($payerEmail === '' || !filter_var($payerEmail, FILTER_VALIDATE_EMAIL)) {
            $payerEmail = (string)($customerRow['email'] ?? '');
        }
        if (!filter_var($payerEmail, FILTER_VALIDATE_EMAIL)) {
            $payerEmail = '';
        }

        $provider  = $this->provider();
        $reference = $this->payments->nextReference();

        $paymentId = $this->payments->create([
            'provider_id'     => (int)($package['provider_id'] ?? ProviderContext::requireProvider()),
            'transaction_ref' => $reference,
            'customer_id'     => $customerId,
            'package_id'      => (int)$package['id'],
            'amount'          => (float)$package['price'],
            'currency'        => (string)setting('payment_currency', 'TZS'),
            'method'          => 'mobile_money',
            'provider'        => $provider->name(),
            'payer_name'      => Validator::string($input['name'] ?? '', 140) ?: null,
            'payer_phone'     => $phone,
            'payer_email'     => $payerEmail ?: null,
            'status'          => 'pending',
        ]);

        $result = $provider->createOrder([
            'amount'      => (float)$package['price'],
            'currency'    => (string)setting('payment_currency', 'TZS'),
            'reference'   => $reference,
            'payer_name'  => $input['name'] ?? '',
            'payer_phone' => $phone,
            'payer_email' => $payerEmail,
            'description' => $package['name'],
        ]);

        $this->payments->logTransaction(
            $paymentId,
            'charge',
            $provider->name(),
            $result['provider_ref'] ?? null,
            (float)$package['price'],
            $result['status'] ?? 'pending',
            $result['message'] ?? '',
            $result['raw'] ?? null
        );

        if (!$result['ok']) {
            $this->payments->updateById($paymentId, [
                'status'         => 'failed',
                'failure_reason' => mb_substr((string)$result['message'], 0, 255),
            ]);
            Alert::raise('payment_failed', 'warning', 'Payment could not be started',
                $result['message'] . ' (reference ' . $reference . ')', 'payment', $paymentId);
            return ['ok' => false, 'message' => $result['message'], 'payment' => $this->payments->find($paymentId)];
        }

        $this->payments->updateById($paymentId, [
            'provider_ref'    => $result['provider_ref'],
            'provider_txn_id' => $result['txn_id'] ?? null,
            'channel'         => $result['channel'] ?? null,
            'status'          => $result['status'],
        ]);

        // create_order_simple can come back already settled.
        if (($result['status'] ?? 'pending') === 'successful') {
            $this->fulfil((int)$paymentId);
        }

        AuditLog::record('payment_start', 'payment', $paymentId, 'Started payment ' . $reference . ' for ' . $package['name'], Auth::check() ? 'user' : 'customer');

        // Remember the order against this browser session, so the public
        // status endpoint can only be asked about orders this visitor placed.
        $own = Session::get('wms_own_payments', []);
        $own[] = $paymentId;
        Session::set('wms_own_payments', array_slice(array_unique($own), -20));

        return [
            'ok'      => true,
            'message' => $result['message'],
            'status'  => $result['status'],
            'payment' => $this->payments->withRelations($paymentId),
        ];
    }

    /**
     * Polls the provider for a payment and applies any state change.
     *
     * @return array{ok:bool,status:string,message:string,payment:?array}
     */
    public function refreshStatus(int $paymentId): array
    {
        $payment = $this->payments->find($paymentId);
        if (!$payment) {
            return ['ok' => false, 'status' => 'unknown', 'message' => 'That payment could not be found.', 'payment' => null];
        }
        if ($payment['status'] !== 'pending') {
            return ['ok' => true, 'status' => $payment['status'], 'message' => 'Payment already ' . $payment['status'] . '.', 'payment' => $payment];
        }
        if (empty($payment['provider_ref'])) {
            return ['ok' => false, 'status' => 'pending', 'message' => 'This payment has no provider reference yet.', 'payment' => $payment];
        }

        $provider = $this->provider($payment['provider']);
        $result   = $provider->checkStatus((string)$payment['provider_ref']);

        $this->payments->logTransaction(
            $paymentId,
            'status_check',
            $provider->name(),
            (string)$payment['provider_ref'],
            (float)$payment['amount'],
            $result['status'],
            $result['message'],
            $result['raw'] ?? null
        );

        if ($result['status'] === 'successful') {
            $this->payments->updateById($paymentId, [
                'provider_txn_id' => $result['txn_id'] ?? null,
                'channel'         => $result['channel'] ?? null,
            ]);
            $fulfilment = $this->fulfil($paymentId);
            return [
                'ok'      => true,
                'status'  => 'successful',
                'message' => $fulfilment['message'],
                'payment' => $this->payments->withRelations($paymentId),
            ];
        }

        if (in_array($result['status'], ['failed', 'cancelled'], true)) {
            $this->markFailed($paymentId, $result['status'], $result['message']);
        }

        return [
            'ok'      => $result['ok'],
            'status'  => $result['status'],
            'message' => $result['message'],
            'payment' => $this->payments->withRelations($paymentId),
        ];
    }

    /* ---------------------------------------------------------- fulfilment */

    /**
     * Everything that happens once money has actually arrived: mark the
     * payment successful, issue the voucher, open the subscription.
     * Safe to call twice - it will not issue two vouchers.
     */
    public function fulfil(int $paymentId): array
    {
        $payment = $this->payments->find($paymentId);
        if (!$payment) {
            return ['ok' => false, 'message' => 'That payment could not be found.'];
        }
        if ($payment['status'] === 'successful' && !empty($payment['voucher_id'])) {
            return ['ok' => true, 'message' => 'This payment was already completed.', 'voucher_id' => (int)$payment['voucher_id']];
        }

        $package = $payment['package_id'] ? $this->packages->find((int)$payment['package_id']) : null;

        $this->db->beginTransaction();
        try {
            $this->payments->updateById($paymentId, [
                'status'         => 'successful',
                'completed_at'   => date('Y-m-d H:i:s'),
                'failure_reason' => null,
            ]);

            $voucherId = $payment['voucher_id'] ? (int)$payment['voucher_id'] : null;
            $subscriptionId = $payment['subscription_id'] ? (int)$payment['subscription_id'] : null;

            if ($package && !$voucherId) {
                $voucher   = (new VoucherService())->issueForPurchase($package, $payment['customer_id'] ? (int)$payment['customer_id'] : null);
                $voucherId = (int)($voucher['id'] ?? 0) ?: null;
            }

            if ($package && $payment['customer_id'] && !$subscriptionId) {
                $subscriptionId = (new Subscription())->open(
                    (int)$payment['customer_id'],
                    $package,
                    $voucherId,
                    $paymentId
                );
            }

            $this->payments->updateById($paymentId, [
                'voucher_id'      => $voucherId,
                'subscription_id' => $subscriptionId,
            ]);

            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollback();
            Logger::error('Payment fulfilment failed: ' . $e->getMessage(), ['payment_id' => $paymentId]);
            Alert::raise('payment_fulfilment', 'danger', 'A paid order could not be fulfilled',
                'Payment ' . $payment['transaction_ref'] . ' was paid but the voucher could not be issued. Please issue it manually.', 'payment', $paymentId);
            return ['ok' => false, 'message' => 'Your payment was received but we could not finish setting up your access. Our team has been notified.'];
        }

        Alert::clear('payment_failed', 'payment', $paymentId);

        /*
         * The money arrived in the PLATFORM's merchant account, because the
         * gateway keys belong to the platform. Credit the selling provider's
         * wallet so they can withdraw it. creditSale() is idempotent, so a
         * webhook and a status poll arriving together credit it once.
         */
        $credit = (new WalletService())->creditSale($this->payments->find($paymentId) ?? []);
        if (!$credit['ok']) {
            Logger::error('Sale could not be credited to the provider wallet', [
                'payment_id' => $paymentId,
                'reason'     => $credit['message'],
            ]);
            Alert::raise('wallet_credit_failed', 'danger',
                'A paid sale was not credited to the provider wallet',
                'Payment ' . $payment['transaction_ref'] . ' succeeded but the wallet credit failed: '
                . $credit['message'] . ' Please correct it with a manual adjustment.',
                'payment', $paymentId);
        }

        AuditLog::record('payment_complete', 'payment', $paymentId,
            'Payment ' . $payment['transaction_ref'] . ' completed (' . money((float)$payment['amount']) . ')',
            Auth::check() ? 'user' : 'system');

        $voucher = $voucherId ? (new Voucher())->withPackage($voucherId) : null;

        return [
            'ok'         => true,
            'message'    => $voucher
                ? 'Payment received. Your access code is ' . $voucher['code'] . '.'
                : 'Payment received.',
            'voucher_id' => $voucherId,
            'voucher'    => $voucher,
        ];
    }

    /** Marks a payment failed/cancelled and raises an alert. */
    public function markFailed(int $paymentId, string $status, string $reason): void
    {
        $payment = $this->payments->find($paymentId);
        if (!$payment || $payment['status'] === 'successful') {
            return;
        }

        $this->payments->updateById($paymentId, [
            'status'         => in_array($status, ['failed', 'cancelled'], true) ? $status : 'failed',
            'failure_reason' => mb_substr($reason, 0, 255),
        ]);

        if ((string)setting('alert_payment_failed', '1') === '1') {
            Alert::raise('payment_failed', 'warning', 'Payment ' . $status,
                'Payment ' . $payment['transaction_ref'] . ' ' . $status . '. ' . $reason, 'payment', $paymentId);
        }
        AuditLog::record('payment_failed', 'payment', $paymentId, 'Payment ' . $payment['transaction_ref'] . ' ' . $status, 'system');
    }

    /* ------------------------------------------------------------ webhooks */

    /**
     * Handles a provider callback.  The signature is verified before any
     * state changes, and the raw body is stored in the transactions ledger.
     */
    public function handleWebhook(string $rawBody, array $headers, ?string $providerName = null): array
    {
        // Never fall back to the demo provider here: it trusts every caller.
        $provider = $this->providerStrict($providerName);

        if ($provider === null) {
            Logger::payment('Webhook rejected: no configured provider to verify it', ['provider' => $providerName]);
            return ['ok' => false, 'code' => 503, 'message' => 'No payment provider is configured to verify this callback.'];
        }

        if (!$provider->verifyWebhook($rawBody, $headers)) {
            Logger::payment('Webhook signature rejected', ['provider' => $provider->name()]);
            return ['ok' => false, 'code' => 401, 'message' => 'Invalid signature.'];
        }

        $event = $provider->parseWebhook($rawBody);

        /*
         * SonicPesa sends payout events on the same webhook as payments.
         * They carry a withdrawal_id rather than an order_id, so they are
         * routed to the wallet instead of the payment ledger.
         */
        $eventName = (string)($event['event'] ?? '');
        if (str_starts_with($eventName, 'payout.')) {
            return $this->handlePayoutWebhook($rawBody, $eventName);
        }

        if (empty($event['provider_ref'])) {
            return ['ok' => false, 'code' => 400, 'message' => 'Missing order reference.'];
        }

        $payment = $this->payments->findByProviderRef((string)$event['provider_ref']);
        if (!$payment) {
            Logger::payment('Webhook for an unknown order', ['ref' => $event['provider_ref']]);
            return ['ok' => false, 'code' => 404, 'message' => 'Unknown order.'];
        }

        // A callback arrives with no session, so the payment's own tenant
        // becomes the context for issuing the voucher and subscription.
        if (!empty($payment['provider_id'])) {
            ProviderContext::establishPortal((int)$payment['provider_id']);
        }

        $this->payments->logTransaction(
            (int)$payment['id'],
            'callback',
            $provider->name(),
            (string)$event['provider_ref'],
            (float)$payment['amount'],
            $event['status'],
            'Provider callback',
            $event['raw'] ?? null
        );

        if ($event['status'] === 'successful') {
            $this->payments->updateById((int)$payment['id'], [
                'provider_txn_id' => $event['txn_id'] ?? null,
                'channel'         => $event['channel'] ?? null,
            ]);
            $this->fulfil((int)$payment['id']);
        } elseif (in_array($event['status'], ['failed', 'cancelled'], true)) {
            $this->markFailed((int)$payment['id'], $event['status'], 'Reported by the payment provider.');
        }

        return ['ok' => true, 'code' => 200, 'message' => 'Processed.'];
    }

    /**
     * Handles payout.pending / payout.success / payout.failed.
     *
     * The signature has already been verified by the caller.
     */
    private function handlePayoutWebhook(string $rawBody, string $eventName): array
    {
        $body = json_decode($rawBody, true) ?: [];
        $data = $body['data'] ?? [];
        $withdrawalId = $data['withdrawal_id'] ?? null;

        if ($withdrawalId === null) {
            return ['ok' => false, 'code' => 400, 'message' => 'Missing withdrawal id.'];
        }

        $withdrawal = (new Withdrawal())->findByProviderRef((string)$withdrawalId);
        if (!$withdrawal) {
            Logger::payment('Payout webhook for an unknown withdrawal', ['withdrawal_id' => $withdrawalId]);
            return ['ok' => false, 'code' => 404, 'message' => 'Unknown withdrawal.'];
        }

        $wallet = new WalletService();

        if ($eventName === 'payout.success') {
            $wallet->completeWithdrawal((int)$withdrawal['id']);
            Logger::payment('Payout completed by webhook', ['reference' => $withdrawal['reference']]);
            return ['ok' => true, 'code' => 200, 'message' => 'Payout completed.'];
        }

        if ($eventName === 'payout.failed') {
            $wallet->releaseHold((int)$withdrawal['id'], 'failed',
                'SonicPesa reported the payout as failed. The money is back in the wallet.');
            return ['ok' => true, 'code' => 200, 'message' => 'Payout marked failed and refunded.'];
        }

        // payout.pending - nothing to change, but record the fee it quoted.
        $this->db->update('withdrawals', [
            'fee'        => (float)($data['fee'] ?? $withdrawal['fee']),
            'net_amount' => (float)($data['net_amount'] ?? $withdrawal['net_amount']),
        ], 'id = ?', [(int)$withdrawal['id']]);

        return ['ok' => true, 'code' => 200, 'message' => 'Payout acknowledged.'];
    }

    /* ------------------------------------------------------- admin actions */

    /** Records an off-platform payment (cash, bank transfer). */
    public function recordManualPayment(array $input): array
    {
        $package = $this->packages->find((int)($input['package_id'] ?? 0));
        if (!$package) {
            return ['ok' => false, 'message' => 'Choose the package that was paid for.'];
        }
        $amount = isset($input['amount']) && $input['amount'] !== '' ? (float)$input['amount'] : (float)$package['price'];

        $paymentId = $this->payments->create([
            'provider_id'     => (int)($package['provider_id'] ?? ProviderContext::requireProvider()),
            'transaction_ref' => $this->payments->nextReference(),
            'customer_id'     => !empty($input['customer_id']) ? (int)$input['customer_id'] : null,
            'package_id'      => (int)$package['id'],
            'amount'          => $amount,
            'currency'        => (string)setting('payment_currency', 'TZS'),
            'method'          => Validator::string($input['method'] ?? 'cash', 40),
            'provider'        => 'manual',
            'payer_name'      => Validator::string($input['payer_name'] ?? '', 140) ?: null,
            'payer_phone'     => Validator::normalisePhone((string)($input['payer_phone'] ?? '')) ?: null,
            'status'          => 'pending',
        ]);

        $result = $this->fulfil($paymentId);
        AuditLog::record('payment_manual', 'payment', $paymentId, 'Recorded a manual payment of ' . money($amount));
        return ['ok' => $result['ok'], 'message' => $result['message'], 'payment_id' => $paymentId];
    }

    /** Marks a successful payment as refunded (bookkeeping only). */
    public function refund(int $paymentId, string $reason = ''): array
    {
        $payment = $this->payments->find($paymentId);
        if (!$payment) {
            return ['ok' => false, 'message' => 'That payment could not be found.'];
        }
        if ($payment['status'] !== 'successful') {
            return ['ok' => false, 'message' => 'Only a successful payment can be refunded.'];
        }

        $this->payments->updateById($paymentId, ['status' => 'refunded', 'failure_reason' => mb_substr($reason, 0, 255) ?: null]);
        $this->payments->logTransaction($paymentId, 'refund', (string)$payment['provider'], $payment['provider_ref'], (float)$payment['amount'], 'refunded', $reason);

        // Take the credit back out of the provider's wallet.
        $reversal = (new WalletService())->reverseSale($payment);
        if (!$reversal['ok']) {
            Logger::warning('Refund could not be taken back from the wallet: ' . $reversal['message'], [
                'payment_id' => $paymentId,
            ]);
        }

        if (!empty($payment['voucher_id'])) {
            (new VoucherService())->applyAction((int)$payment['voucher_id'], 'cancel');
        }

        AuditLog::record('payment_refund', 'payment', $paymentId, 'Refunded ' . money((float)$payment['amount']) . '. ' . $reason);
        return ['ok' => true, 'message' => 'Payment marked as refunded.'];
    }

    /**
     * Polls pending payments that have gone quiet - useful from cron so a
     * missed webhook never leaves a paying customer without access.
     */
    public function reconcilePending(int $limit = 20): array
    {
        $checked = 0;
        $settled = 0;
        foreach ($this->payments->stalePending(2, $limit) as $payment) {
            // Each payment is settled inside its own tenant context.
            if (!empty($payment['provider_id'])) {
                ProviderContext::establishPortal((int)$payment['provider_id']);
            }
            $result = $this->refreshStatus((int)$payment['id']);
            $checked++;
            if ($result['status'] === 'successful') {
                $settled++;
            }
        }
        return ['checked' => $checked, 'settled' => $settled];
    }
}
