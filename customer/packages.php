<?php
/**
 * WMS - Package chooser.
 */

require_once __DIR__ . '/../config/config.php';
require_once INCLUDES_PATH . '/components.php';

/* Establish the tenant before any query runs (see ProviderContext). */
ProviderContext::resolvePortalProvider();

try {
    $packages = (new Package())->active();
} catch (Throwable $e) {
    Logger::error('Portal package list failed: ' . $e->getMessage());
    $packages = [];
}

$pageTitle    = 'Packages';
$activeTab    = 'buy';
$hasActionBar = true;
require __DIR__ . '/_header.php';
?>

<?= portal_tabs('buy', $portalCanBuy) ?>

<?php if (!$portalCanBuy): ?>
    <div class="portal-card">
        <?= empty_state([
            'icon'  => 'ticket',
            'title' => 'This network sells vouchers',
            'text'  => 'You cannot pay by mobile money here yet. Buy a voucher from the operator and enter the code to get online.',
            'action' => '<a class="btn btn--primary" href="' . e(url('customer/index.php')) . '">' . icon('wifi', 'ico--sm') . ' Enter a voucher</a>',
        ]) ?>
    </div>
    <?php require __DIR__ . '/_footer.php'; ?>
    <?php return; ?>
<?php endif; ?>

<h1 class="mb-1" style="font-size:1.2rem">Choose a package</h1>
<p class="small muted mb-2">Pay with mobile money. Your code arrives the moment the payment goes through.</p>

<?php if (!$packages): ?>
    <div class="portal-card">
        <?= empty_state([
            'icon' => 'package',
            'title' => 'No packages on sale',
            'text' => 'Nothing is available to buy at the moment. Please ask at the counter, or try a voucher.',
            'action' => '<a class="btn btn--primary" href="' . e(url('customer/index.php')) . '">Use a voucher instead</a>',
        ]) ?>
    </div>
<?php else: ?>
    <form method="get" action="<?= e(url('customer/purchase.php')) ?>">
        <div class="package-list">
            <?php foreach ($packages as $i => $package): ?>
                <label class="package-card<?= $i === 0 ? ' is-selected' : '' ?>" data-package-card>
                    <input type="radio" name="package" value="<?= (int)$package['id'] ?>" <?= $i === 0 ? 'checked' : '' ?>>
                    <?php if (!empty($package['is_featured'])): ?><span class="package-card__flag">Popular</span><?php endif; ?>
                    <div class="package-card__head">
                        <span class="package-card__name"><?= e($package['name']) ?></span>
                        <span class="package-card__price"><?= e(money((float)$package['price'])) ?></span>
                    </div>
                    <div class="package-card__specs">
                        <span><?= icon('database', 'ico--sm') ?><?= e(format_mb($package['data_limit_mb'] === null ? null : (int)$package['data_limit_mb'])) ?></span>
                        <span><?= icon('clock', 'ico--sm') ?><?= e(format_package_duration((int)$package['duration_value'], $package['duration_unit'])) ?></span>
                        <span><?= icon('signal', 'ico--sm') ?><?= e(format_speed((int)$package['download_kbps'])) ?></span>
                        <span><?= icon('device', 'ico--sm') ?><?= (int)$package['device_limit'] ?> device<?= (int)$package['device_limit'] === 1 ? '' : 's' ?></span>
                    </div>
                    <?php if ($package['description']): ?>
                        <div class="package-card__desc"><?= e($package['description']) ?></div>
                    <?php endif; ?>
                </label>
            <?php endforeach; ?>
        </div>

        <div class="app-actionbar">
            <button type="submit" class="btn btn--primary btn--lg" data-package-submit>
                Continue <?= icon('chevron', 'ico--sm') ?>
            </button>
        </div>
    </form>
<?php endif; ?>

<?php require __DIR__ . '/_footer.php'; ?>
