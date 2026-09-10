<?php
/**
 * POST api/payments/webhook.php
 *
 * Provider callback (SonicPesa). The HMAC signature is verified before any
 * state changes, and the raw body is written to the transactions ledger.
 *
 * No CSRF token here - this is a server-to-server call authenticated by its
 * signature, not by a browser session.
 */

require_once dirname(__DIR__) . '/_bootstrap.php';

api_method('POST');

api_run(static function (): void {
    $raw = file_get_contents('php://input') ?: '';

    if ($raw === '') {
        Response::error('Empty payload.', 400);
    }
    if (strlen($raw) > 65536) {
        Response::error('Payload too large.', 413);
    }

    $headers = [];
    foreach ($_SERVER as $key => $value) {
        if (str_starts_with($key, 'HTTP_')) {
            $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
            $headers[$name] = $value;
        }
    }

    Logger::payment('Webhook received', ['bytes' => strlen($raw), 'ip' => wms_client_ip()]);

    $result = (new PaymentService())->handleWebhook($raw, $headers, api_string('provider') ?: null);

    if (!$result['ok']) {
        Response::error($result['message'], $result['code']);
    }
    Response::success(null, $result['message']);
});
