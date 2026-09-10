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

// Light housekeeping so expiry never depends on a cron job being set up.
if (random_int(1, 12) === 1) {
    try {
        (new VoucherService())->runHousekeeping();
    } catch (Throwable $e) {
        Logger::warning('Background housekeeping failed: ' . $e->getMessage());
    }
}
