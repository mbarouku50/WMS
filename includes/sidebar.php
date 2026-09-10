<?php
/**
 * WMS - Admin sidebar navigation.
 *
 * Items are declared once, filtered by permission, and grouped so the list
 * stays short.  $activeNav (set by each page) marks the current item.
 */

$activeNav = $activeNav ?? '';

try {
    $unreadAlerts = (new Alert())->unreadCount();
} catch (Throwable $e) {
    $unreadAlerts = 0;
}

/**
 * Navigation is built from two maps: what a platform administrator sees, and
 * what a provider sees. Items are still filtered by permission, so a provider
 * with a narrow role gets a shorter list again.
 *
 * A Super Admin who is "viewing as" a provider gets the provider navigation,
 * because that is the scope their requests are running in.
 */
$isPlatform = ProviderContext::isGlobalScope();

$platformNav = [
    '' => [
        ['key' => 'dashboard', 'label' => 'Platform', 'icon' => 'dashboard', 'href' => 'admin/index.php', 'perm' => 'view_dashboard'],
        ['key' => 'providers', 'label' => 'Providers', 'icon' => 'building', 'href' => 'admin/providers/index.php', 'perm' => 'manage_providers'],
        ['key' => 'billing', 'label' => 'Billing', 'icon' => 'money', 'href' => 'admin/billing/index.php', 'perm' => 'manage_billing'],
    ],
    'Across all providers' => [
        ['key' => 'customers', 'label' => 'Customers', 'icon' => 'users', 'href' => 'admin/customers/index.php', 'perm' => 'manage_customers'],
        ['key' => 'packages', 'label' => 'Packages', 'icon' => 'package', 'href' => 'admin/packages/index.php', 'perm' => 'manage_packages'],
        ['key' => 'vouchers', 'label' => 'Vouchers', 'icon' => 'ticket', 'href' => 'admin/vouchers/index.php', 'perm' => 'manage_vouchers'],
        ['key' => 'payments', 'label' => 'Payments', 'icon' => 'card', 'href' => 'admin/payments/index.php', 'perm' => 'manage_payments'],
    ],
    'Network' => [
        ['key' => 'network-overview', 'label' => 'Overview', 'icon' => 'dashboard', 'href' => 'admin/network/index.php', 'perm' => 'manage_routers'],
        ['key' => 'routers', 'label' => 'Routers', 'icon' => 'router', 'href' => 'admin/network/routers.php', 'perm' => 'manage_routers'],
        ['key' => 'access-points', 'label' => 'Access points', 'icon' => 'antenna', 'href' => 'admin/network/access-points.php', 'perm' => 'manage_routers'],
        ['key' => 'sessions', 'label' => 'Sessions', 'icon' => 'activity', 'href' => 'admin/network/sessions.php', 'perm' => 'manage_sessions'],
        ['key' => 'devices', 'label' => 'Devices', 'icon' => 'device', 'href' => 'admin/network/devices.php', 'perm' => 'manage_devices'],
        ['key' => 'bandwidth', 'label' => 'Bandwidth', 'icon' => 'sliders', 'href' => 'admin/network/bandwidth.php', 'perm' => 'manage_routers'],
    ],
    'Insight' => [
        ['key' => 'usage', 'label' => 'Usage', 'icon' => 'signal', 'href' => 'admin/usage/index.php', 'perm' => 'manage_reports'],
        ['key' => 'reports', 'label' => 'Reports', 'icon' => 'report', 'href' => 'admin/reports/index.php', 'perm' => 'manage_reports'],
        ['key' => 'alerts', 'label' => 'Alerts', 'icon' => 'bell', 'href' => 'admin/alerts/index.php', 'perm' => 'manage_alerts', 'badge' => $unreadAlerts],
    ],
    'Administration' => [
        ['key' => 'users', 'label' => 'All users', 'icon' => 'user', 'href' => 'admin/administration/users.php', 'perm' => 'manage_staff'],
        ['key' => 'roles', 'label' => 'Roles', 'icon' => 'shield', 'href' => 'admin/administration/roles.php', 'perm' => 'manage_staff'],
        ['key' => 'audit', 'label' => 'Audit logs', 'icon' => 'history', 'href' => 'admin/administration/audit-logs.php', 'perm' => 'view_audit_logs'],
        ['key' => 'settings', 'label' => 'Platform settings', 'icon' => 'settings', 'href' => 'admin/settings/index.php', 'perm' => 'manage_settings'],
    ],
];

$providerNav = [
    '' => [
        ['key' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'dashboard', 'href' => 'admin/index.php', 'perm' => 'view_dashboard'],
        ['key' => 'customers', 'label' => 'Customers', 'icon' => 'users', 'href' => 'admin/customers/index.php', 'perm' => 'manage_customers'],
    ],
    'Sales' => [
        ['key' => 'packages', 'label' => 'Packages', 'icon' => 'package', 'href' => 'admin/packages/index.php', 'perm' => 'manage_packages'],
        ['key' => 'vouchers', 'label' => 'Vouchers', 'icon' => 'ticket', 'href' => 'admin/vouchers/index.php', 'perm' => 'manage_vouchers'],
        ['key' => 'payments', 'label' => 'Payments', 'icon' => 'card', 'href' => 'admin/payments/index.php', 'perm' => 'manage_payments'],
        ['key' => 'wallet', 'label' => 'Wallet', 'icon' => 'money', 'href' => 'admin/wallet/index.php', 'perm' => 'view_wallet'],
    ],
    'Network' => [
        ['key' => 'network-overview', 'label' => 'Overview', 'icon' => 'dashboard', 'href' => 'admin/network/index.php', 'perm' => 'manage_routers'],
        ['key' => 'sessions', 'label' => 'Sessions', 'icon' => 'activity', 'href' => 'admin/network/sessions.php', 'perm' => 'manage_sessions'],
        ['key' => 'devices', 'label' => 'Devices', 'icon' => 'device', 'href' => 'admin/network/devices.php', 'perm' => 'manage_devices'],
        ['key' => 'routers', 'label' => 'Routers', 'icon' => 'router', 'href' => 'admin/network/routers.php', 'perm' => 'manage_routers'],
        ['key' => 'access-points', 'label' => 'Access points', 'icon' => 'antenna', 'href' => 'admin/network/access-points.php', 'perm' => 'manage_routers'],
        ['key' => 'bandwidth', 'label' => 'Bandwidth', 'icon' => 'sliders', 'href' => 'admin/network/bandwidth.php', 'perm' => 'manage_routers'],
    ],
    'Insight' => [
        ['key' => 'usage', 'label' => 'Usage', 'icon' => 'signal', 'href' => 'admin/usage/index.php', 'perm' => 'manage_reports'],
        ['key' => 'reports', 'label' => 'Reports', 'icon' => 'report', 'href' => 'admin/reports/index.php', 'perm' => 'manage_reports'],
        ['key' => 'alerts', 'label' => 'Alerts', 'icon' => 'bell', 'href' => 'admin/alerts/index.php', 'perm' => 'manage_alerts', 'badge' => $unreadAlerts],
    ],
    'Administration' => [
        ['key' => 'users', 'label' => 'Staff', 'icon' => 'user', 'href' => 'admin/administration/users.php', 'perm' => 'manage_provider_users'],
        ['key' => 'audit', 'label' => 'Activity log', 'icon' => 'history', 'href' => 'admin/administration/audit-logs.php', 'perm' => 'view_audit_logs'],
        ['key' => 'settings', 'label' => 'Settings', 'icon' => 'settings', 'href' => 'admin/settings/index.php', 'perm' => 'manage_provider_settings'],
    ],
];

$navGroups = $isPlatform ? $platformNav : $providerNav;
?>
<aside class="sidebar" id="wms-sidebar">
    <a class="sidebar__brand" href="<?= e(url('admin/index.php')) ?>">
        <span class="brand-mark"><?= icon('wifi') ?></span>
        <span class="brand-text">
            <b><?= e($isPlatform ? setting('platform_name', 'WMS') : ProviderContext::scopeLabel()) ?></b>
            <span><?= $isPlatform ? 'Platform' : 'Provider' ?></span>
        </span>
    </a>

    <?php if (!$isPlatform): ?>
        <!-- Which tenant this session is operating inside. -->
        <div class="scope-chip">
            <?= icon('building', 'ico--sm') ?>
            <span class="scope-chip__text">
                <b><?= e(ProviderContext::scopeLabel()) ?></b>
                <span><?= e(ProviderContext::provider()['provider_code'] ?? '') ?></span>
            </span>
        </div>
    <?php endif; ?>

    <nav class="sidebar__nav" aria-label="Main">
        <?php foreach ($navGroups as $groupLabel => $items): ?>
            <?php
            $visible = array_filter($items, static fn($item) => Permission::has($item['perm']));
            if (!$visible) {
                continue;
            }
            ?>
            <div class="nav-group">
                <?php if ($groupLabel !== ''): ?>
                    <div class="nav-group__label"><?= e($groupLabel) ?></div>
                <?php endif; ?>
                <?php foreach ($visible as $item): ?>
                    <a class="nav-link<?= $activeNav === $item['key'] ? ' is-active' : '' ?>" href="<?= e(url($item['href'])) ?>">
                        <?= icon($item['icon']) ?>
                        <span class="nav-link__text"><?= e($item['label']) ?></span>
                        <?php if (!empty($item['badge'])): ?>
                            <span class="nav-badge"><?= (int)$item['badge'] ?></span>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    </nav>

    <div class="sidebar__foot">
        <div class="sidebar__mode">
            <span><?= demo_mode() ? 'Demo mode' : 'Live mode' ?></span>
            <?= demo_mode() ? '<span class="badge badge--demo">Demo</span>' : '<span class="badge badge--live">Live</span>' ?>
        </div>
        <?= e(setting('platform_name', 'WMS')) ?> v<?= e(WMS_VERSION) ?>
    </div>
</aside>
<div class="sidebar__backdrop" aria-hidden="true"></div>
