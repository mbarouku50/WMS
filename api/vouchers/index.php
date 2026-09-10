<?php
/**
 * GET api/vouchers/index.php?q=&status=&page=
 * Paginated voucher list for staff tooling.
 */

require_once dirname(__DIR__) . '/_bootstrap.php';

api_method('GET');
api_staff('manage_vouchers');

api_run(static function (): void {
    $page = (new Voucher())->search([
        'q'          => api_string('q'),
        'status'     => api_string('status'),
        'package_id' => api_int('package_id') ?: '',
        'batch_id'   => api_int('batch_id') ?: '',
    ], max(1, api_int('page', 1)), min(100, max(5, api_int('per_page', 25))));

    $rows = array_map(static fn($v) => [
        'id'         => (int)$v['id'],
        'code'       => $v['code'],
        'package'    => $v['package_name'],
        'price'      => (float)$v['price'],
        'status'     => $v['status'],
        'data_limit_mb' => $v['data_limit_mb'] === null ? null : (int)$v['data_limit_mb'],
        'data_used_mb'  => (int)$v['data_used_mb'],
        'device_limit'  => (int)$v['device_limit'],
        'activated_at'  => $v['activated_at'],
        'expires_at'    => $v['expires_at'],
        'customer'      => $v['customer_name'],
    ], $page['rows']);

    Response::success($rows, 'Vouchers.', [
        'meta' => ['page' => $page['page'], 'pages' => $page['pages'], 'total' => $page['total']],
    ]);
});
