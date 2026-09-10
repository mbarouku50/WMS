<?php
/**
 * GET api/payments/status.php?id=123
 *
 * Polled by the portal while the customer approves the USSD prompt.
 *
 * Authorisation: the caller must either
 *   - have started this payment in their own browser session, or
 *   - be a signed-in staff member whose provider owns it.
 *
 * Without that, a visitor could walk the id space and read other people's
 * order status - and their voucher code. It answers with the minimum: a
 * status, an amount and a message.
 */

require_once dirname(__DIR__) . '/_bootstrap.php';

api_method('GET');

api_run(static function (): void {
    $paymentId = api_int('id');
    if ($paymentId <= 0) {
        Response::error('A payment id is required.', 422);
    }

    $payments = new Payment();

    // Unscoped read first, then an explicit ownership decision below.
    $payment = Database::getInstance()->fetchOne('SELECT * FROM payments WHERE id = ? LIMIT 1', [$paymentId]);
    if (!$payment) {
        Response::error('That payment could not be found.', 404);
    }

    $ownedBySession = in_array($paymentId, array_map('intval', (array)Session::get('wms_own_payments', [])), true);
    $ownedByStaff   = Auth::check() && ProviderContext::canAccess(
        $payment['provider_id'] === null ? null : (int)$payment['provider_id']
    );
    $ownedByCustomer = Auth::customerId() !== null
        && (int)$payment['customer_id'] === Auth::customerId();

    if (!$ownedBySession && !$ownedByStaff && !$ownedByCustomer) {
        Logger::payment('Blocked a payment status lookup', [
            'payment_id' => $paymentId,
            'ip'         => wms_client_ip(),
        ]);
        // Deliberately the same answer as a missing record.
        Response::error('That payment could not be found.', 404);
    }

    // The payment's own provider is the context for settling it.
    if (!empty($payment['provider_id'])) {
        ProviderContext::establishPortal((int)$payment['provider_id']);
    }

    if ($payment['status'] === 'pending') {
        $result  = (new PaymentService())->refreshStatus($paymentId);
        $payment = $payments->find($paymentId) ?? $payment;
        $message = $result['message'];
    } else {
        $message = 'Payment ' . $payment['status'] . '.';
    }

    // The voucher code only goes to whoever paid for it.
    $voucherCode = null;
    if ($payment['status'] === 'successful' && $payment['voucher_id'] && ($ownedBySession || $ownedByCustomer || $ownedByStaff)) {
        $voucher = (new Voucher())->findUnscoped((int)$payment['voucher_id']);
        $voucherCode = $voucher['code'] ?? null;
    }

    Response::success([
        'id'      => (int)$payment['id'],
        'status'  => $payment['status'],
        'amount'  => (float)$payment['amount'],
        'message' => $payment['failure_reason'] ?: $message,
        'voucher' => $voucherCode,
    ], $message);
});
