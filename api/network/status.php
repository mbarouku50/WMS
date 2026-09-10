<?php
/**
 * GET api/network/status.php
 * Live tiles for the dashboard. Returns counts only - never credentials.
 */

require_once dirname(__DIR__) . '/_bootstrap.php';

api_method('GET');
api_staff('view_dashboard');

api_run(static function (): void {
    $network = (new NetworkService())->overview();

    Response::success([
        'active_sessions' => number_format($network['active_sessions']),
        'devices_online'  => number_format($network['devices_online']),
        'routers_online'  => $network['routers']['online'] . '/' . $network['routers']['total'],
        'traffic_today'   => format_bytes($network['download_today'] + $network['upload_today']),
        'health'          => $network['health'] === null ? '—' : $network['health'] . '%',
        'demo'            => $network['demo'],
    ], 'Network status.');
});
