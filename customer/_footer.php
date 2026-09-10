<?php
/**
 * WMS - Captive portal chrome (bottom).
 *
 * Closes the portal and hangs the bottom tab bar under it. The bar is the
 * portal's navigation on a phone: home, packages, the connect action,
 * status and the account - always visible, never more than a thumb away.
 *
 * "Buy" is only offered where the network actually accepts mobile money;
 * the same condition is enforced server side in PaymentService.
 */

$activeTab    = $activeTab ?? '';
$portalCanBuy = $portalCanBuy ?? false;


$tabs = [['key' => 'home', 'label' => 'Home', 'icon' => 'wifi', 'href' => 'customer/index.php']];

if ($portalCanBuy) {
    $tabs[] = ['key' => 'buy', 'label' => 'Packages', 'icon' => 'package', 'href' => 'customer/packages.php'];
}

/* The raised centre button is whatever this network sells. */
$tabs[] = $portalCanBuy
    ? ['key' => 'connect', 'label' => 'Connect', 'icon' => 'wifi', 'href' => 'customer/voucher.php', 'action' => true]
    : ['key' => 'connect', 'label' => 'Connect', 'icon' => 'ticket', 'href' => 'customer/index.php', 'action' => true];

$tabs[] = ['key' => 'status',  'label' => 'My status', 'icon' => 'activity', 'href' => 'customer/status.php'];
$tabs[] = ['key' => 'account', 'label' => Auth::customer() ? 'Account' : 'Sign in', 'icon' => 'user',
           'href' => Auth::customer() ? 'customer/status.php' : 'customer/login.php'];
?>
    <div class="portal__foot">
        <?php $supportPhone = (string)setting('support_phone', ''); ?>
        <?php if ($supportPhone !== ''): ?>
            Need help? Call <a href="tel:<?= e(preg_replace('/\s+/', '', $supportPhone)) ?>"><?= e($supportPhone) ?></a><br>
        <?php endif; ?>
        <a href="<?= e(url('customer/index.php')) ?>">Portal home</a> ·
        <a href="<?= e(url('index.php')) ?>">Main website</a><br>

        <?= e(setting('company_name', 'WMS')) ?> · <?= e(setting('app_name', 'WMS')) ?> v<?= e(WMS_VERSION) ?>
    </div>
</div><!-- /.portal -->

<?= app_tabbar($tabs, $activeTab) ?>

<script src="<?= e(asset('js/app.js')) ?>"></script>
<script src="<?= e(asset('js/customer.js')) ?>"></script>
</body>
</html>
