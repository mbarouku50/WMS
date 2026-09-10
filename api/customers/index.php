<?php
/**
 * GET api/customers/index.php?q=&status=
 */

require_once dirname(__DIR__) . '/_bootstrap.php';

api_method('GET');
api_staff('manage_customers');

api_run(static function (): void {
    $page = (new Customer())->search([
        'q'      => api_string('q'),
        'status' => api_string('status'),
    ], max(1, api_int('page', 1)), min(100, max(5, api_int('per_page', 25))));

    $rows = array_map(static fn($c) => [
        'id'       => (int)$c['id'],
        'code'     => $c['customer_code'],
        'name'     => $c['full_name'],
        'phone'    => $c['phone'],
        'email'    => $c['email'],
        'type'     => $c['customer_type'],
        'status'   => $c['status'],
        'devices'  => (int)$c['device_count'],
        'online'   => (int)$c['active_sessions'] > 0,
        'spend'    => (float)$c['total_spend'],
        'joined'   => $c['created_at'],
    ], $page['rows']);

    Response::success($rows, 'Customers.', [
        'meta' => ['page' => $page['page'], 'pages' => $page['pages'], 'total' => $page['total']],
    ]);
});
