<?php
/**
 * POST api/devices/block.php  { id, action: block|unblock }
 */

require_once dirname(__DIR__) . '/_bootstrap.php';

api_method('POST');
api_staff('manage_devices');
api_csrf();

api_run(static function (): void {
    $id     = api_int('id');
    $action = api_string('action', 'block');

    if ($id <= 0) {
        Response::error('A device id is required.', 422);
    }
    if (!in_array($action, ['block', 'unblock'], true)) {
        Response::error('Action must be block or unblock.', 422);
    }

    $service = new SessionService();
    $result  = $action === 'block' ? $service->blockDevice($id) : $service->unblockDevice($id);

    if (!$result['ok']) {
        Response::error($result['message'], 422);
    }
    Response::success(null, $result['message']);
});
