<?php
/**
 * GET api/sessions/index.php?status=active
 */

require_once dirname(__DIR__) . '/_bootstrap.php';

api_method('GET');
api_staff('manage_sessions');

api_run(static function (): void {
    $page = (new SessionModel())->search([
        'q'         => api_string('q'),
        'status'    => api_string('status'),
        'router_id' => api_int('router_id') ?: '',
    ], max(1, api_int('page', 1)), min(100, max(5, api_int('per_page', 25))));

    $rows = array_map(static fn($s) => [
        'id'         => (int)$s['id'],
        'customer'   => $s['customer_name'],
        'voucher'    => $s['voucher_code'],
        'device'     => $s['device_name'],
        'mac'        => $s['mac_address'],
        'ip'         => $s['ip_address'],
        'router'     => $s['router_name'],
        'started_at' => $s['started_at'],
        'duration'   => (int)$s['duration_seconds'],
        'download'   => (int)$s['download_bytes'],
        'upload'     => (int)$s['upload_bytes'],
        'status'     => $s['status'],
        'source'     => $s['source'],
    ], $page['rows']);

    Response::success($rows, 'Sessions.', [
        'meta' => ['page' => $page['page'], 'pages' => $page['pages'], 'total' => $page['total']],
    ]);
});
