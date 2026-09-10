<?php
/**
 * WMS - Admin page shell (bottom half).
 *
 * Closes the layout and, on a phone, hands the operator a bottom tab bar so
 * the four screens they actually live in are one thumb away instead of
 * behind a drawer. The bar is permission-filtered exactly like the sidebar:
 * a support-only account never sees a tab it cannot open.
 */

$activeNav = $activeNav ?? '';

$tabs = [];
if (Permission::has('view_dashboard')) {
    $tabs[] = ['key' => 'dashboard', 'label' => 'Home', 'icon' => 'dashboard', 'href' => 'admin/index.php'];
}
if (Permission::has('manage_customers')) {
    $tabs[] = ['key' => 'customers', 'label' => 'Customers', 'icon' => 'users', 'href' => 'admin/customers/index.php'];
}
if (Permission::has('manage_vouchers')) {
    $tabs[] = ['key' => 'vouchers', 'label' => 'Vouchers', 'icon' => 'ticket', 'href' => 'admin/vouchers/index.php', 'action' => true];
}
if (Permission::has('manage_routers')) {
    $tabs[] = ['key' => 'network-overview', 'label' => 'Network', 'icon' => 'router', 'href' => 'admin/network/index.php'];
} elseif (Permission::has('manage_payments')) {
    $tabs[] = ['key' => 'payments', 'label' => 'Payments', 'icon' => 'card', 'href' => 'admin/payments/index.php'];
}
?>
    </main>

    <?php if ($tabs): ?>
    <nav class="app-tabbar" aria-label="Quick navigation">
        <?php foreach ($tabs as $tab): ?>
            <?php $isActive = $activeNav === $tab['key']; ?>
            <a class="app-tab<?= !empty($tab['action']) ? ' app-tab--action' : '' ?><?= $isActive ? ' is-active' : '' ?>"
               href="<?= e(url($tab['href'])) ?>"<?= $isActive ? ' aria-current="page"' : '' ?>>
                <?php if (!empty($tab['action'])): ?>
                    <span class="app-tab__mark"><?= icon($tab['icon']) ?></span>
                <?php else: ?>
                    <?= icon($tab['icon']) ?>
                <?php endif; ?>
                <span class="app-tab__label"><?= e($tab['label']) ?></span>
            </a>
        <?php endforeach; ?>

        <!-- Everything else stays in the drawer; this is the way into it. -->
        <button type="button" class="app-tab" data-sidebar-toggle aria-label="Open the full menu">
            <?= icon('menu') ?>
            <?php if (!empty($unreadAlerts)): ?>
                <span class="app-tab__dot"><?= $unreadAlerts > 9 ? '9+' : (int)$unreadAlerts ?></span>
            <?php endif; ?>
            <span class="app-tab__label">Menu</span>
        </button>
    </nav>
    <?php endif; ?>

</div><!-- /.main -->
</div><!-- /.shell -->

<script src="<?= e(asset('js/app.js')) ?>"></script>
<script src="<?= e(asset('js/admin.js')) ?>"></script>
<?php foreach ($extraScripts ?? [] as $script): ?>
<script src="<?= e(asset($script)) ?>"></script>
<?php endforeach; ?>
<?php if (!empty($inlineScript)): ?>
<script><?= $inlineScript ?></script>
<?php endif; ?>
</body>
</html>
