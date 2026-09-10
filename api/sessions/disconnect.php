<?php
/**
 * POST api/sessions/disconnect.php  { id }
 */

require_once dirname(__DIR__) . '/_bootstrap.php';

api_method('POST');
api_staff('manage_sessions');
api_csrf();

api_run(static function (): void {
    $id = api_int('id');
    if ($id <= 0) {
        Response::error('A session id is required.', 422);
    }

    $result = (new SessionService())->disconnect($id, 'Disconnected through the API by ' . (Auth::user()['username'] ?? 'staff'));

    if (!$result['ok']) {
        Response::error($result['message'], 422);
    }
    Response::success(['live' => (bool)($result['live'] ?? false)], $result['message']);
});
