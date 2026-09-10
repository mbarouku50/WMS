<?php
/**
 * POST api/network/test.php  { router_id }
 *
 * Deprecated alias of api/network/router-test.php, kept so any bookmark,
 * script or older cached page that still calls it keeps working.
 *
 * It runs exactly the same code path - one implementation, one set of
 * ownership checks - and returns the same payload.
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
