<?php
/**
 * POST api/vouchers/activate.php  { code, mac, phone }
 *
 * The JSON route into voucher activation, for a captive portal splash page
 * that would rather not do a full form post. CSRF protected, because it is
 * called from a browser session.
 */

require_once dirname(__DIR__) . '/_bootstrap.php';

api_method('POST');
api_csrf();

api_run(static function (): void {
    $code = strtoupper(api_string('code'));
    if ($code === '') {
        Response::validationError(['code' => 'Enter your voucher code.']);
    }

    $result = (new VoucherService())->activate($code, [
        'mac'         => api_string('mac') ?: null,
        'ip'          => wms_client_ip(),
        'user_agent'  => $_SERVER['HTTP_USER_AGENT'] ?? null,
        'customer_id' => Auth::customerId(),
        'phone'       => api_string('phone'),
    ]);

    if (!$result['ok']) {
        Response::error($result['message'], 422);
    }

    $voucher = $result['voucher'] ?? [];
    Response::success([
        'code'       => $voucher['code'] ?? $code,
        'package'    => $voucher['package_name'] ?? null,
        'expires_at' => $voucher['expires_at'] ?? null,
        'data_limit' => $voucher['data_limit_mb'] ?? null,
        'live'       => (bool)($result['live'] ?? false),
    ], $result['message']);
});
