<?php
/**
 * WMS - Admin page shell (top half).
 *
 * Pages set $pageTitle, $pageSubtitle, $activeNav and $breadcrumbs before
 * including this, then include footer.php at the end.
 */

$pageTitle    = $pageTitle ?? 'Dashboard';
$pageSubtitle = $pageSubtitle ?? '';
$breadcrumbs  = $breadcrumbs ?? [];
$currentUser  = Auth::user() ?? [];

try {
    $unreadAlerts = $unreadAlerts ?? (new Alert())->unreadCount();
} catch (Throwable $e) {
    $unreadAlerts = 0;
}
?>
<!DOCTYPE html>
<html lang="en" data-base="<?= e(BASE_PATH) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="csrf-token" content="<?= e(CSRF::token()) ?>">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#0d1a20">
<title><?= e($pageTitle) ?> · <?= e(ProviderContext::isGlobalScope() ? setting('platform_name', 'WMS') : ProviderContext::scopeLabel()) ?></title>
<link rel="icon" href="data:image/svg+xml,<?= rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16"><rect width="16" height="16" rx="3" fill="#4ed7f1"/><g fill="none" stroke="#0d1a20" stroke-width="1.6" stroke-linecap="round"><path d="M3.5 6.6a6.5 6.5 0 0 1 9 0"/><path d="M5.2 8.8a4 4 0 0 1 5.6 0"/><path d="M7 11a1.7 1.7 0 0 1 2 0"/></g></svg>') ?>">
<link rel="stylesheet" href="<?= e(asset('css/style.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/admin.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/net.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/responsive.css')) ?>">
</head>
<body class="wms-admin has-tabbar">
<?= net_backdrop('quiet') ?>
<div class="shell">

<?php require INCLUDES_PATH . '/sidebar.php'; ?>

<div class="main">

    <?php if (ProviderContext::isImpersonating()): ?>
        <!-- A platform administrator is operating inside a tenant. Loud on
             purpose, and the way out is right here. -->
        <div class="impersonation-bar">
            <span>
                <?= icon('eye', 'ico--sm') ?>
                Viewing as <b><?= e(ProviderContext::scopeLabel()) ?></b> — every action you take is recorded against your platform account.
            </span>
            <form method="post" action="<?= e(url('admin/providers/index.php')) ?>">
                <?= CSRF::field() ?>
                <input type="hidden" name="action" value="stop_impersonation">
                <button type="submit" class="btn btn--sm">
                    <?= icon('logout', 'ico--sm') ?> Back to the platform
                </button>
            </form>
        </div>
    <?php endif; ?>

    <header class="topbar">
        <button type="button" class="icon-btn topbar__toggle" data-sidebar-toggle aria-label="Open navigation">
            <?= icon('menu') ?>
        </button>

        <nav class="topbar__crumbs" aria-label="Breadcrumb">
            <a href="<?= e(url('admin/index.php')) ?>" class="crumb-hide">Control centre</a>
            <?php foreach ($breadcrumbs as $crumb): ?>
                <span class="sep crumb-hide">/</span>
                <?php if (!empty($crumb['url'])): ?>
                    <a href="<?= e(url($crumb['url'])) ?>" class="crumb-hide"><?= e($crumb['label']) ?></a>
                <?php else: ?>
                    <b><?= e($crumb['label']) ?></b>
                <?php endif; ?>
            <?php endforeach; ?>
            <?php if (!$breadcrumbs): ?>
                <span class="sep crumb-hide">/</span><b><?= e($pageTitle) ?></b>
            <?php endif; ?>
        </nav>

        <div class="topbar__spacer"></div>

        <?php if (ProviderContext::isGlobalScope()): ?>
            <span class="pill" title="You are working across every provider">
                <?= icon('globe', 'ico--sm') ?> Platform scope
            </span>
        <?php else: ?>
            <span class="pill" title="Everything on this screen is limited to this provider">
                <?= icon('building', 'ico--sm') ?> <?= e(str_limit(ProviderContext::scopeLabel(), 24)) ?>
            </span>
        <?php endif; ?>

        <?php if (Permission::has('manage_vouchers')): ?>
        <form class="topbar__search" method="get" action="<?= e(url('admin/vouchers/index.php')) ?>" role="search">
            <?= icon('search', 'ico--sm') ?>
            <input type="search" class="input" name="q" placeholder="Find a voucher code…" aria-label="Search vouchers">
        </form>
        <?php endif; ?>

        <?php if (Permission::has('manage_alerts')): ?>
        <a class="icon-btn" href="<?= e(url('admin/alerts/index.php')) ?>" aria-label="Alerts" title="Alerts">
            <?= icon('bell') ?>
            <?php if ($unreadAlerts > 0): ?>
                <span class="icon-btn__dot"><?= $unreadAlerts > 9 ? '9+' : (int)$unreadAlerts ?></span>
            <?php endif; ?>
        </a>
        <?php endif; ?>

        <div class="dropdown">
            <button type="button" class="user-chip" data-dropdown="user-menu" aria-haspopup="true">
                <?= avatar($currentUser['full_name'] ?? '', 'avatar--ink') ?>
                <span class="user-chip__meta">
                    <b><?= e($currentUser['full_name'] ?? 'Account') ?></b>
                    <span><?= e($currentUser['role_name'] ?? '') ?></span>
                </span>
                <?= icon('chevron-down', 'ico--sm') ?>
            </button>
            <div class="dropdown__menu" id="user-menu">
                <div class="dropdown__label"><?= e($currentUser['email'] ?? '') ?></div>
                <?php if (!empty($currentUser['provider_name'])): ?>
                    <div class="dropdown__item" style="pointer-events:none;color:var(--wms-text-muted)">
                        <?= icon('building', 'ico--sm') ?> <?= e($currentUser['provider_name']) ?>
                    </div>
                <?php endif; ?>
                <?php if (Permission::has('manage_providers')): ?>
                    <a class="dropdown__item" href="<?= e(url('admin/providers/index.php')) ?>">
                        <?= icon('building', 'ico--sm') ?> Providers
                    </a>
                <?php endif; ?>
                <a class="dropdown__item" href="<?= e(url('admin/administration/users.php?profile=1')) ?>">
                    <?= icon('user', 'ico--sm') ?> My profile
                </a>
                <?php if (Permission::has('manage_settings')): ?>
                <a class="dropdown__item" href="<?= e(url('admin/settings/index.php')) ?>">
                    <?= icon('settings', 'ico--sm') ?> Settings
                </a>
                <?php endif; ?>
                <a class="dropdown__item" href="<?= e(url('index.php')) ?>" target="_blank" rel="noopener">
                    <?= icon('external', 'ico--sm') ?> View public site
                </a>
                <div class="dropdown__divider"></div>
                <a class="dropdown__item dropdown__item--danger" href="<?= e(url('logout.php')) ?>">
                    <?= icon('logout', 'ico--sm') ?> Sign out
                </a>
            </div>
        </div>
    </header>

    <main class="page">
        <?= flash_messages() ?>
