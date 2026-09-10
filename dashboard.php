<?php
/**
 * WMS - Convenience redirect.
 *
 * /WMS/dashboard.php sends staff to the control centre and customers to
 * their status page, so a bookmark of either always lands somewhere useful.
 */

require_once __DIR__ . '/config/config.php';

if (Auth::check()) {
    header('Location: ' . url('admin/index.php'));
    exit;
}
if (Auth::customer() !== null) {
    header('Location: ' . url('customer/status.php'));
    exit;
}

header('Location: ' . url('login.php'));
exit;
