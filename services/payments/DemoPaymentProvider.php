<?php
/**
 * WMS - Demo payment provider.
 *
 * Lets the whole purchase flow be exercised without moving real money.  An
 * order stays pending for a few seconds (so the polling UI can be seen doing
 * its job) and then reports success.  Every record it creates is stamped
 * provider = "demo" so demo money is never mistaken for real revenue.
 */
class DemoPaymentProvider implements PaymentProvider
{
    /** Seconds a demo order stays pending before it "succeeds". */
    private const SETTLE_SECONDS = 8;

    public function name(): string
    {
        return 'demo';
    }

    public function label(): string
    {
        return 'Demo provider (no real money)';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function createOrder(array $payment): array
    {
        $ref = 'demo_' . strtolower(random_code(10, 'abcdefghijklmnopqrstuvwxyz0123456789'));

        Logger::payment('Demo order created', ['reference' => $payment['reference'] ?? '', 'amount' => $payment['amount'] ?? 0]);

        return [
            'ok'           => true,
            'status'       => 'pending',
            'provider_ref' => $ref,
            'message'      => 'Demo mode: no USSD prompt was sent. This order settles automatically in a few seconds.',
            'raw'          => ['demo' => true, 'settles_in' => self::SETTLE_SECONDS],
        ];
    }

    public function checkStatus(string $providerRef): array
    {
        // The payment row's own age decides the outcome, so the result is
        // stable across polls rather than random.
        $payment = Database::getInstance()->fetchOne('SELECT * FROM payments WHERE provider_ref = ? LIMIT 1', [$providerRef]);
        $age = $payment ? time() - strtotime((string)$payment['created_at']) : self::SETTLE_SECONDS + 1;

        if ($age < self::SETTLE_SECONDS) {
            return [
                'ok'           => true,
                'status'       => 'pending',
                'provider_ref' => $providerRef,
                'txn_id'       => null,
                'channel'      => null,
                'message'      => 'Waiting for the demo payment to settle…',
                'raw'          => ['demo' => true, 'age' => $age],
            ];
        }

        return [
            'ok'           => true,
            'status'       => 'successful',
            'provider_ref' => $providerRef,
            'txn_id'       => 'DEMO' . strtoupper(substr(md5($providerRef), 0, 10)),
            'channel'      => 'DEMO',
            'message'      => 'Demo payment completed.',
            'raw'          => ['demo' => true],
        ];
    }

    /**
     * The demo provider has no signing secret, so it can only ever vouch for
     * a callback while it is genuinely the provider this installation is
     * running on. Anything else is refused rather than waved through.
     */
    public function verifyWebhook(string $rawBody, array $headers): bool
    {
        if ((string)setting('payment_provider', 'demo') !== 'demo') {
            Logger::payment('Demo webhook refused: a real provider is configured');
            return false;
        }
        return true;
    }

    public function parseWebhook(string $rawBody): array
    {
        $data = json_decode($rawBody, true) ?: [];
        return [
            'provider_ref' => $data['order_id'] ?? null,
            'status'       => 'successful',
            'txn_id'       => $data['transid'] ?? null,
            'channel'      => 'DEMO',
            'amount'       => isset($data['amount']) ? (float)$data['amount'] : null,
            'raw'          => $data,
        ];
    }
}
