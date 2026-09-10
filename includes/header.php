<?php
/**
 * WMS - Public page shell (landing page, portal, login, installer).
 *
 * Pages set, before including this:
 *   $pageTitle       page title
 *   $pageDescription meta description
 *   $bodyClass       wms-public | wms-portal | wms-auth
 *   $netTone         'light' (default) | 'ink' | 'quiet' - backdrop key
 *   $hasTabbar       true when the page ends with a bottom tab bar, so the
 *                    body reserves room for it rather than hiding content
 *   $hasActionBar    true when a primary action is pinned above that bar
 */
$pageTitle       = $pageTitle ?? setting('app_name', 'WMS');
$pageDescription = $pageDescription ?? 'Wi-Fi management for hotspot operators: customers, packages, vouchers, payments and network monitoring.';
$bodyClass       = $bodyClass ?? 'wms-public';
$netTone         = $netTone ?? 'light';

$shellClass = trim($bodyClass
    . (!empty($hasTabbar)    ? ' has-tabbar'    : '')
    . (!empty($hasActionBar) ? ' has-actionbar' : ''));
?>
<!DOCTYPE html>
<html lang="en" data-base="<?= e(BASE_PATH) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="csrf-token" content="<?= e(CSRF::token()) ?>">
<meta name="description" content="<?= e($pageDescription) ?>">
<meta name="theme-color" content="#0d1a20">
<title><?= e($pageTitle) ?></title>
<link rel="icon" href="data:image/svg+xml,<?= rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16"><rect width="16" height="16" rx="3" fill="#4ed7f1"/><g fill="none" stroke="#0d1a20" stroke-width="1.6" stroke-linecap="round"><path d="M3.5 6.6a6.5 6.5 0 0 1 9 0"/><path d="M5.2 8.8a4 4 0 0 1 5.6 0"/><path d="M7 11a1.7 1.7 0 0 1 2 0"/></g></svg>') ?>">
<link rel="stylesheet" href="<?= e(asset('css/style.css')) ?>">
<?php foreach ($extraStyles ?? [] as $style): ?>
<link rel="stylesheet" href="<?= e(asset($style)) ?>">
<?php endforeach; ?>
<link rel="stylesheet" href="<?= e(asset('css/net.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/responsive.css')) ?>">
</head>
<body class="<?= e($shellClass) ?>">
<?= net_backdrop($netTone) ?>
