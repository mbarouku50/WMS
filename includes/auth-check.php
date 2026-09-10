<?php
/**
 * WMS - Guard for protected pages.
 *
 * Include this at the very top of every admin page:
 *
 *      $requiredPermission = 'manage_vouchers';
 *      require_once __DIR__ . '/../../includes/auth-check.php';
 *
 * It checks, in order: installed -> signed in -> session valid ->
 * permission held.  Anything short of all four redirects.
 */

if (!defined('WMS_BOOTSTRAPPED')) {
    require_once dirname(__DIR__) . '/config/config.php';
}

// Not installed yet: send everyone to the installer.
if (!WMS_INSTALLED && !str_contains($_SERVER['SCRIPT_NAME'] ?? '', 'install.php')) {
    header('Location: ' . url('install.php'));
    exit;
}

Auth::requireLogin();

/*
 * Platform-only pages (everything under admin/providers/, platform settings)
 * set $requirePlatform = true before including this file.
 */
if (!empty($requirePlatform)) {
    Permission::requirePlatform();
}

if (!empty($requiredPermission)) {
    Permission::require($requiredPermission);
}

/*
 * A provider user must always have a tenant context. If the session somehow
 * lost it, sign them out rather than letting the request run unscoped.
 */
if (ProviderContext::isProviderUser() && ProviderContext::providerId() === null) {
    Logger::warning('Provider user without a tenant context - signing out', ['user_id' => Auth::id()]);
    Auth::logout();
    Session::start();
    Session::flash('error', 'Your session lost its provider context. Please sign in again.');
    header('Location: ' . url('login.php'));
    exit;
}

/*
 * The platform fee.
 *
 * A provider who has not paid past the deadline still gets through the door
 * - they have to, or they could never pay - but the only screen they can
 * reach is the one that takes the payment. Everything else redirects there
 * until the invoice is settled.
 *
 * Platform staff are never held here: the fee is owed to them.
 */
try {
    (new BillingGuard())->enforceAdmin();
} catch (Throwable $e) {
    // A failure to evaluate the fee must never lock a paying provider out of
    // their own business. Log it and let the request through.
    Logger::error('Platform fee enforcement could not run: ' . $e->getMessage(), ['user_id' => Auth::id()]);
}

// Light housekeeping so expiry never depends on a cron job being set up.
if (random_int(1, 12) === 1) {
    try {
        (new VoucherService())->runHousekeeping();
    } catch (Throwable $e) {
        Logger::warning('Background housekeeping failed: ' . $e->getMessage());
    }
}
