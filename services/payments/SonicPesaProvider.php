<?php
/**
 * WMS - SonicPesa payment provider.
 *
 * Mobile money collections for Tanzania (M-Pesa, Tigo Pesa, Airtel Money,
 * Halopesa) through the SonicPesa API v1.
 *
 *   POST /payment/create_order         - create order + send Push USSD
 *   POST /payment/create_order_simple  - same, but waits up to 45s
 *   POST /payment/order_status         - poll one order
 *   POST /transactions/readbyId        - list transactions
 *   POST /payouts/create               - send money out
 *   GET  /payouts/status/{id}          - payout state
 *
 * Credentials come from the settings table (sonicpesa_api_key /
 * sonicpesa_api_secret).  They stay on the server: they are never rendered
 * into HTML, never sent to JavaScript and are masked in logs.
 */
class SonicPesaProvider implements PaymentProvider
{
    private string $baseUrl;
    private string $apiKey;
    private string $apiSecret;
    private int $timeout;

    public function __construct(?string $apiKey = null, ?string $apiSecret = null)
    {
        $this->baseUrl   = rtrim((string)setting('sonicpesa_base_url', 'https://api.sonicpesa.com/api/v1'), '/');
        $this->apiKey    = $apiKey    ?? (string)setting('sonicpesa_api_key', '');
        $this->apiSecret = $apiSecret ?? (string)setting('sonicpesa_api_secret', '');
        $this->timeout   = (bool)setting('sonicpesa_use_simple', false) ? 60 : 25;
    }

    public function name(): string
    {
        return 'sonicpesa';
    }

    public function label(): string
    {
        return 'SonicPesa (mobile money)';
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '';
    }

    /* ------------------------------------------------------------ charges */

    public function createOrder(array $payment): array
    {
        if (!$this->isConfigured()) {
            return [
                'ok'           => false,
                'status'       => 'failed',
                'provider_ref' => null,
                'message'      => 'Mobile money is not configured yet. Add your SonicPesa API key under Settings → Payments.',
                'raw'          => null,
            ];
        }

        $phone = self::formatPhone((string)($payment['payer_phone'] ?? ''));
        if ($phone === '') {
            return [
                'ok'           => false,
                'status'       => 'failed',
                'provider_ref' => null,
                'message'      => 'Enter a valid Tanzanian mobile number, for example 0754 000 000.',
                'raw'          => null,
            ];
        }

        $endpoint = (bool)setting('sonicpesa_use_simple', false) ? '/payment/create_order_simple' : '/payment/create_order';

        $response = $this->request($endpoint, [
            'buyer_email' => self::buyerEmail($payment, $phone),
            'buyer_name'  => $payment['payer_name'] ?: 'WMS customer',
            'buyer_phone' => $phone,
            'amount'      => (int)round((float)$payment['amount']),
            'currency'    => $payment['currency'] ?? (string)setting('payment_currency', 'TZS'),
        ]);

        if (!$response['ok']) {
            return [
                'ok'           => false,
                'status'       => 'failed',
                'provider_ref' => null,
                'message'      => $response['message'],
                'raw'          => $response['raw'],
            ];
        }

        $data   = $response['body']['data'] ?? [];
        $status = self::mapStatus((string)($data['payment_status'] ?? $data['status'] ?? 'PENDING'));

        return [
            'ok'           => true,
            'status'       => $status,
            'provider_ref' => $data['order_id'] ?? null,
            'txn_id'       => $data['transid'] ?? null,
            'channel'      => $data['channel'] ?? null,
            'message'      => $status === 'successful'
                ? 'Payment received.'
                : 'Check your phone and enter your mobile money PIN to approve the payment.',
            'raw'          => $response['body'],
        ];
    }

    public function checkStatus(string $providerRef): array
    {
        if (!$this->isConfigured()) {
            return $this->statusFailure($providerRef, 'Mobile money is not configured.');
        }

        $response = $this->request('/payment/order_status', ['order_id' => $providerRef]);

        if (!$response['ok']) {
            // A failed poll is not a failed payment - stay pending and retry.
            return [
                'ok'           => false,
                'status'       => 'pending',
                'provider_ref' => $providerRef,
                'txn_id'       => null,
                'channel'      => null,
                'message'      => $response['message'],
                'raw'          => $response['raw'],
            ];
        }

        $data = $response['body']['data'] ?? [];

        return [
            'ok'           => true,
            'status'       => self::mapStatus((string)($data['payment_status'] ?? 'PENDING')),
            'provider_ref' => $data['order_id'] ?? $providerRef,
            'txn_id'       => $data['transid'] ?? null,
            'channel'      => $data['channel'] ?? null,
            'message'      => (string)($response['body']['message'] ?? 'Status retrieved.'),
            'raw'          => $response['body'],
        ];
    }

    /** Lists the merchant's transactions (used by the settings diagnostics). */
    public function listTransactions(): array
    {
        $response = $this->request('/transactions/readbyId', []);
        return [
            'ok'      => $response['ok'],
            'data'    => $response['body']['data'] ?? [],
            'message' => $response['message'],
        ];
    }

    /* ------------------------------------------------------------ payouts */

    /**
     * Sends money from the merchant balance to a bank or wallet.
     * Requires both the API key and the API secret.
     */
    public function createPayout(float $amount, string $method, string $accountNumber, string $accountName): array
    {
        if ($this->apiSecret === '') {
            return ['ok' => false, 'message' => 'Payouts need the SonicPesa API secret to be configured.', 'data' => []];
        }

        $response = $this->request('/payouts/create', [
            'amount'         => (int)round($amount),
            'method'         => $method,
            'account_number' => $accountNumber,
            'account_name'   => $accountName,
        ], true);

        return [
            'ok'      => $response['ok'],
            'message' => $response['ok'] ? (string)($response['body']['message'] ?? 'Payout requested.') : $response['message'],
            'data'    => $response['body']['data'] ?? [],
        ];
    }

    /**
     * Reads the state of one payout.
     *
     * The reference is whatever SonicPesa handed back at creation and is
     * stored as text, so it is passed through as text: forcing it to an
     * integer turned any non-numeric id into 0 and left the withdrawal
     * stuck in "processing" with the provider's money held for ever.
     */
    public function payoutStatus(string $withdrawalId): array
    {
        $reference = trim($withdrawalId);
        if ($reference === '') {
            return ['ok' => false, 'message' => 'That payout has no SonicPesa reference to check.', 'data' => []];
        }

        $response = $this->request('/payouts/status/' . rawurlencode($reference), null, true, 'GET');
        return [
            'ok'      => $response['ok'],
            'message' => $response['message'],
            'data'    => $response['body']['data'] ?? [],
        ];
    }

    /* ----------------------------------------------------------- webhooks */

    public function verifyWebhook(string $rawBody, array $headers): bool
    {
        if ($this->apiSecret === '') {
            Logger::payment('Webhook rejected: no API secret configured');
            return false;
        }

        $signature = '';
        foreach ($headers as $key => $value) {
            if (strcasecmp((string)$key, 'X-SonicPesa-Signature') === 0) {
                $signature = (string)$value;
                break;
            }
        }
        if ($signature === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $rawBody, $this->apiSecret);
        return hash_equals($expected, $signature);
    }

    public function parseWebhook(string $rawBody): array
    {
        $data = json_decode($rawBody, true) ?: [];
        return [
            'provider_ref' => $data['order_id'] ?? null,
            'status'       => self::mapStatus((string)($data['status'] ?? $data['payment_status'] ?? 'PENDING')),
            'txn_id'       => $data['transid'] ?? null,
            'channel'      => $data['channel'] ?? null,
            'amount'       => isset($data['amount']) ? (float)$data['amount'] : null,
            'event'        => $data['event'] ?? null,
            'raw'          => $data,
        ];
    }

    /* ----------------------------------------------------------- internals */

    /**
     * Performs an HTTP call against the SonicPesa API.
     *
     * @return array{ok:bool,body:array,message:string,raw:?string,http:int}
     */
    private function request(string $path, ?array $payload, bool $withSecret = false, string $method = 'POST'): array
    {
        $url = $this->baseUrl . $path;

        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
            'X-API-KEY: ' . $this->apiKey,
        ];
        if ($withSecret && $this->apiSecret !== '') {
            $headers[] = 'X-API-SECRET: ' . $this->apiSecret;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        if ($method !== 'GET' && $payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        }

        $raw   = curl_exec($ch);
        $http  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        // The payload is logged without the phone number's last digits and
        // never with credentials (Logger masks *key*/*secret* automatically).
        Logger::payment('SonicPesa ' . $method . ' ' . $path, ['http' => $http, 'ok' => $raw !== false]);

        if ($raw === false) {
            return [
                'ok'      => false,
                'body'    => [],
                'message' => 'We could not reach the payment service. Please check your connection and try again.',
                'raw'     => $error,
                'http'    => 0,
            ];
        }

        $body = json_decode((string)$raw, true);
        if (!is_array($body)) {
            return [
                'ok'      => false,
                'body'    => [],
                'message' => 'The payment service returned an unexpected response.',
                'raw'     => (string)$raw,
                'http'    => $http,
            ];
        }

        if ($http >= 400 || ($body['status'] ?? '') === 'error') {
            return [
                'ok'      => false,
                'body'    => $body,
                'message' => self::httpMessage($http, (string)($body['message'] ?? '')),
                'raw'     => (string)$raw,
                'http'    => $http,
            ];
        }

        return [
            'ok'      => true,
            'body'    => $body,
            'message' => (string)($body['message'] ?? 'OK'),
            'raw'     => (string)$raw,
            'http'    => $http,
        ];
    }

    private function statusFailure(string $ref, string $message): array
    {
        return [
            'ok'           => false,
            'status'       => 'pending',
            'provider_ref' => $ref,
            'txn_id'       => null,
            'channel'      => null,
            'message'      => $message,
            'raw'          => null,
        ];
    }

    /** Turns the documented error codes into wording a customer can act on. */
    private static function httpMessage(int $http, string $providerMessage): string
    {
        $base = match ($http) {
            400     => 'The payment request was rejected. Please check the amount and phone number.',
            401     => 'The payment service rejected our credentials. Please check the API key in Settings.',
            403     => 'This account is not allowed to perform that payment action.',
            404     => 'That payment order could not be found.',
            500     => 'The payment service had an internal problem. Please try again shortly.',
            default => 'The payment could not be completed right now.',
        };
        return $providerMessage !== '' ? $base . ' (' . str_limit($providerMessage, 120) . ')' : $base;
    }

    /** Maps SonicPesa payment statuses onto WMS statuses. */
    public static function mapStatus(string $providerStatus): string
    {
        return match (strtoupper(trim($providerStatus))) {
            'SUCCESS', 'SUCCESSFUL', 'COMPLETED'     => 'successful',
            'PENDING', 'INPROGRESS', 'IN_PROGRESS'   => 'pending',
            'CANCELLED', 'USERCANCELLED', 'CANCELED' => 'cancelled',
            'REJECTED', 'FAILED', 'FAILURE'          => 'failed',
            default                                  => 'pending',
        };
    }

    /**
     * Picks the address sent as buyer_email.
     *
     * SonicPesa insists on a syntactically valid address and refuses the whole
     * order with "Parameter buyer_email is invalid" when it does not get one.
     * A host-derived address such as customer@localhost or customer@192.168.1.5
     * is exactly that kind of rejection, so every candidate is validated here
     * before it leaves the server and a well-formed, reserved placeholder is
     * used as the last resort rather than a broken address.
     */
    private static function buyerEmail(array $payment, string $phone): string
    {
        $candidates = [
            (string)($payment['payer_email'] ?? ''),
            (string)setting('sonicpesa_buyer_email', ''),
            (string)setting('support_email', ''),
        ];

        // Only usable when the WMS is actually served from a real domain.
        $host = self::mailableHost((string)parse_url(APP_URL, PHP_URL_HOST));
        if ($host !== '') {
            $candidates[] = 'payments@' . $host;
        }

        foreach ($candidates as $candidate) {
            $candidate = trim($candidate);
            if ($candidate !== '' && self::isMailable($candidate)) {
                return $candidate;
            }
        }

        /*
         * Valid, unique per payer, and never deliverable: example.com is
         * reserved by RFC 2606 for precisely this use. Set the buyer email
         * fallback under Settings -> Payments to send a real address instead.
         */
        return ($phone !== '' ? $phone : 'customer') . '@example.com';
    }

    /** True when the address is well formed and sits on a public-looking domain. */
    private static function isMailable(string $email): bool
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        $at = strrchr($email, '@');
        return $at !== false && self::mailableHost(substr($at, 1)) !== '';
    }

    /** Returns the host when it looks like an internet domain, otherwise ''. */
    private static function mailableHost(string $host): string
    {
        $host = strtolower(trim($host, " \t\n\r\0\x0B."));
        if ($host === '' || !str_contains($host, '.')) {
            return '';                                  // localhost, bare hostname
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return '';                                  // 192.168.1.5
        }
        $tld = strtolower(substr((string)strrchr($host, '.'), 1));
        $reserved = ['local', 'localhost', 'localdomain', 'internal', 'lan', 'home', 'test', 'invalid', 'example'];

        return in_array($tld, $reserved, true) ? '' : $host;
    }

    /** Normalises a Tanzanian number to the 255XXXXXXXXX form the API wants. */
    public static function formatPhone(string $phone): string
    {
        $normalised = Validator::normalisePhone($phone);
        return preg_match('/^255[67]\d{8}$/', $normalised) ? $normalised : '';
    }
}
