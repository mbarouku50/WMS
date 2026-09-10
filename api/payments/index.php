<?php
/**
 * GET api/payments/index.php?status=&from=&to=
 */

require_once dirname(__DIR__) . '/_bootstrap.php';

api_method('GET');
api_staff('manage_payments');

api_run(static function (): void {
    $page = (new Payment())->search([
        'q'         => api_string('q'),
        'status'    => api_string('status'),
        'provider'  => api_string('provider'),
        'date_from' => api_string('from'),
        'date_to'   => api_string('to'),
    ], max(1, api_int('page', 1)), min(100, max(5, api_int('per_page', 25))));

    $rows = array_map(static fn($p) => [
        'id'        => (int)$p['id'],
        'reference' => $p['transaction_ref'],
        'customer'  => $p['customer_name'] ?? $p['payer_name'],
        'phone'     => $p['payer_phone'],
        'package'   => $p['package_name'],
        'amount'    => (float)$p['amount'],
        'currency'  => $p['currency'],
        'method'    => $p['method'],
        'provider'  => $p['provider'],
        'status'    => $p['status'],
        'created_at'   => $p['created_at'],
        'completed_at' => $p['completed_at'],
    ], $page['rows']);

    Response::success($rows, 'Payments.', [
        'meta' => ['page' => $page['page'], 'pages' => $page['pages'], 'total' => $page['total']],
    ]);
});
