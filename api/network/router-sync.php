<?php
/**
 * POST api/network/router-sync.php  { router_id }  - one router
 * POST api/network/router-sync.php  { }            - every router in scope
 *
 * Re-reads a router's health and stores it, so the pages that follow read
 * the WMS database instead of opening their own connection to MikroTik.
 *
 * One router being unreachable is recorded and reported; it never stops the
 * others in the sweep and never fails the request.
 */

require_once dirname(__DIR__) . '/_bootstrap.php';

api_method('POST');
api_staff('manage_routers');
api_csrf();

api_run(static function (): void {
    $network  = new NetworkService();
    $routerId = api_int('router_id');

    if ($routerId > 0) {
        // Tenant scoped: another provider's id is simply not found.
        $router = (new Router())->find($routerId);
        if (!$router) {
            Response::error('That router could not be found.', 404);
        }

        $status = $network->pollRouter($router);
        $fresh  = (new Router())->find($routerId) ?? $router;

        Response::success([
            'router'       => $router['name'],
            'status'       => $status['status'] ?? 'unknown',
            'source'       => $status['source'] ?? 'demo',
            'last_seen'    => $fresh['last_seen_at'] ? time_ago($fresh['last_seen_at']) : null,
            'last_sync'    => $fresh['last_sync_at'] ? time_ago($fresh['last_sync_at']) : null,
            'active_users' => $fresh['active_users'],
            'cpu'          => $fresh['cpu_load'],
            'memory'       => $fresh['memory_used_pct'],
            'stale'        => Router::isStale($fresh),
            'error'        => $fresh['last_error'],
        ], ($status['status'] ?? '') === 'online'
            ? $router['name'] . ' answered.'
            : $router['name'] . ': ' . ($status['message'] ?? 'status is ' . ($status['status'] ?? 'unknown') . '.'));
    }

    $results = $network->pollAll();
    $online  = count(array_filter($results, static fn($r) => ($r['status'] ?? '') === 'online'));

    Response::success(
        array_map(static fn($r) => [
            'status' => $r['status'] ?? 'unknown',
            'source' => $r['source'] ?? 'demo',
        ], $results),
        count($results) . ' router(s) synced, ' . $online . ' online.'
    );
});
