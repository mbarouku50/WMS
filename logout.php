<?php
/**
 * WMS - Sign out (staff and customers).
 */

require_once __DIR__ . '/config/config.php';

$isCustomer = isset($_GET['portal']) || (Auth::customer() !== null && !Auth::check());

if ($isCustomer) {
    Auth::customerLogout();
    Session::flash('info', 'You have been signed out of the portal.');
    header('Location: ' . url('customer/index.php'));
    exit;
}

Auth::logout();
Session::start();
Session::flash('success', 'You have been signed out.');
header('Location: ' . url('login.php'));
exit;
