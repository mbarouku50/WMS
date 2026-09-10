<?php
/**
 * GET api/devices/index.php
 */

require_once dirname(__DIR__) . '/_bootstrap.php';

api_method('GET');
api_staff('manage_devices');

api_run(static function (): void {
    $page = (new Device())->search([
        'q'           => api_string('q'),
        'status'      => api_string('status'),
        'customer_id' => api_int('customer_id') ?: '',
    ], max(1, api_int('page', 1)), min(100, max(5, api_int('per_page', 25))));

    $rows = array_map(static fn($d) => [
        'id'           => (int)$d['id'],
        'name'         => $d['name'],
        'type'         => $d['device_type'],
        'mac'          => $d['mac_address'],
        'ip'           => $d['ip_address'],
        'customer'     => $d['customer_name'],
        'router'       => $d['router_name'],
        'status'       => $d['status'],
        'last_seen_at' => $d['last_seen_at'],
        'online'       => (int)$d['active_sessions'] > 0,
    ], $page['rows']);

    Response::success($rows, 'Devices.', [
        'meta' => ['page' => $page['page'], 'pages' => $page['pages'], 'total' => $page['total']],
    ]);
});
