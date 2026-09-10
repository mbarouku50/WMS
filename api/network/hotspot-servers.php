<?php
/**
 * POST api/network/hotspot-servers.php  { router_id }
 *
 * Asks a live router which hotspot servers it actually has, so the router
 * form can offer real names instead of assuming "hotspot1" exists.
 *
 * When the router has none, that is what comes back - an empty list and a
 * plain explanation. WMS never invents a hotspot server.
 */

require_once dirname(__DIR__) . '/_bootstrap.php';

api_method('POST');
api_staff('manage_routers');
api_csrf();

api_run(static function (): void {
    $routerId = api_int('router_id');
    if ($routerId <= 0) {
        Response::error('Save the router first, then its hotspot servers can be read.', 422);
    }

    // Ownership is enforced inside the service, in SQL.
    $result = (new NetworkService())->discoverHotspotServers($routerId);

    if (!$result['ok']) {
        Response::json([
            'success' => false,
            'message' => $result['message'],
            'data'    => ['servers' => [], 'profiles' => [], 'live' => $result['live']],
        ], 200);
    }

    Response::success([
        'servers'  => $result['servers'],
        'profiles' => $result['profiles'] ?? [],
        'live'     => true,
    ], $result['message']);
});
