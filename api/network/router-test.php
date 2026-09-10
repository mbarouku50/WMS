<?php
/**
 * POST api/network/router-test.php  { router_id }
 *
 * Contacts one router and reports, item by item, what answered.
 *
 * Enforced here, not merely in the interface:
 *   authentication -> permission -> CSRF -> input validation
 *   -> provider ownership (inside NetworkService, in SQL)
 *
 * The response never contains a credential: NetworkService::testPayload()
 * is an allow-list, so nothing secret can reach the browser by being added
 * to the result array upstream.
 *
 * A failed test is a 200 with success=false, because "the router is down" is
 * a valid answer, not a server error.
 */

require_once dirname(__DIR__) . '/_bootstrap.php';

api_method('POST');
api_staff('manage_routers');
api_csrf();

api_run(static function (): void {
    $routerId = api_int('router_id');
    if ($routerId <= 0) {
        Response::error('Choose a router to test.', 422);
    }

    $result  = (new NetworkService())->testConnection($routerId);
    $payload = NetworkService::testPayload($result);

    if (!$result['ok']) {
        Response::json(['success' => false, 'message' => $result['message'], 'data' => $payload], 200);
    }

    Response::success($payload, $result['message']);
});
