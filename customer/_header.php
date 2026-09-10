<?php
/**
 * WMS - Captive portal chrome (top).
 *
 * Pages set, before including:
 *   $pageTitle    the title
 *   $portalStatus ['tone' => online|offline, 'icon', 'title', 'text']
 *   $activeTab    home | buy | status | account - marks the bottom tab bar
 *   $portalBack   url to go back to; defaults to the portal home
 *
 * On a phone this is an application shell, not a web page: a fixed bottom
 * tab bar carries navigation, and the way home is always one tap away in
 * the top-left corner. Both are rendered at every width and simply relax
 * into an ordinary layout on a desktop.
 */

if (!defined('WMS_BOOTSTRAPPED')) {
    require_once dirname(__DIR__) . '/config/config.php';
}
require_once INCLUDES_PATH . '/components.php';

/*
 * Resolve the tenant before anything is read.
 *
 * Without this an anonymous visitor has no provider, scoped queries would
 * fall back to "everything", and one provider's packages could be shown to
 * another provider's customers. resolvePortalProvider() decides from the
 * router that redirected them, the device's history, or - on a single-tenant
 * installation - the only active provider.
 */
$portalProviderId = ProviderContext::resolvePortalProvider();
$portalProvider   = $portalProviderId !== null ? ProviderContext::provider() : null;
$portalChoices    = $portalProviderId === null ? ProviderContext::portalChoices() : [];

/*
 * Whether this network sells by mobile money at all. When the platform has
 * not granted it, the Buy option is hidden here AND refused server side in
 * PaymentService - the hidden button is a courtesy, not the control.
 */
$portalCanBuy = $portalProvider !== null && (int)($portalProvider['mobile_money_enabled'] ?? 0) === 1;

/*
 * The operator behind this network has not paid their platform fee, so the
 * network has stopped selling. Buying and activating both disappear, and the
 * visitor is told plainly rather than left pressing a button that fails.
 *
 * As everywhere else in the portal, hiding is the courtesy: the refusal
 * itself lives in PaymentService and VoucherService, where a saved link or a
 * hand-made POST also has to pass.
 */
$portalServiceStopped = BillingGuard::serviceStopped($portalProviderId);
if ($portalServiceStopped) {
    $portalCanBuy = false;
}

$activeTab    = $activeTab ?? '';
$portalBack   = $portalBack ?? null;

/*
 * May this visitor change which network they are on?
 *
 * Only when the choice was theirs to make: more than one network runs here,
 * and they are not signed in to an account that belongs to one of them -
 * letting a customer hop tenants would put another operator's packages in
 * front of their account. On a real captive portal the router names itself
 * on the next request, so switching there simply resolves straight back.
 */
$portalMaySwitch = Auth::customer() === null
    && $portalProviderId !== null
    && count(ProviderContext::portalChoices()) > 1;
$pageTitle    = ($pageTitle ?? 'Wi-Fi') . ' · ' . setting('app_name', 'WMS');
$bodyClass    = 'wms-portal';
$netTone      = 'light';
$hasTabbar    = true;
$extraStyles  = ['css/customer.css'];
require INCLUDES_PATH . '/header.php';

$portalCustomer = Auth::customer();
?>
<div class="portal">
    <div class="portal__top">
        <?php if ($activeTab !== 'home'): ?>
            <!-- The way out. Every screen in the portal has one, in the same
                 corner, going to the same place. -->
            <a class="round-btn round-btn--back" href="<?= e(url($portalBack ?? 'customer/index.php')) ?>"
               aria-label="Back to the start" title="Back to the start">
                <?= icon('chevron', 'ico--sm') ?>
            </a>
        <?php endif; ?>

        <a class="portal__brand" href="<?= e(url('customer/index.php')) ?>">
            <?php if (!empty($portalProvider['logo']) && is_file(WMS_ROOT . '/' . $portalProvider['logo'])): ?>
                <span class="brand-mark brand-mark--lg" style="background:none;padding:0;overflow:hidden">
                    <img src="<?= e(url($portalProvider['logo'])) ?>" alt="" style="width:100%;height:100%;object-fit:contain">
                </span>
            <?php else: ?>
                <span class="brand-mark brand-mark--lg"><?= icon('wifi', 'ico--lg') ?></span>
            <?php endif; ?>
            <span>
                <b><?= e($portalProvider['business_name'] ?? setting('company_name', 'WMS')) ?></b>
                <span><?= e(setting('portal_ssid', 'WMS-Hotspot')) ?></span>
            </span>
        </a>

        <?php if ($portalCustomer): ?>
            <div class="dropdown" style="margin-left:auto">
                <button type="button" class="user-chip" data-dropdown="portal-menu">
                    <?= avatar($portalCustomer['full_name'], 'avatar--ink') ?>
                    <?= icon('chevron-down', 'ico--sm') ?>
                </button>
                <div class="dropdown__menu" id="portal-menu">
                    <div class="dropdown__label"><?= e($portalCustomer['full_name']) ?></div>
                    <a class="dropdown__item" href="<?= e(url('customer/index.php')) ?>"><?= icon('wifi', 'ico--sm') ?> Portal home</a>
                    <a class="dropdown__item" href="<?= e(url('customer/status.php')) ?>"><?= icon('activity', 'ico--sm') ?> My status</a>
                    <a class="dropdown__item" href="<?= e(url('customer/packages.php')) ?>"><?= icon('package', 'ico--sm') ?> Buy a package</a>
                    <div class="dropdown__divider"></div>
                    <a class="dropdown__item dropdown__item--danger" href="<?= e(url('logout.php?portal=1')) ?>"><?= icon('logout', 'ico--sm') ?> Sign out</a>
                </div>
            </div>
        <?php else: ?>
            <a class="btn btn--sm" style="margin-left:auto" href="<?= e(url('customer/login.php')) ?>"><?= icon('user', 'ico--sm') ?> Sign in</a>
        <?php endif; ?>
    </div>

    <?php if ($portalMaySwitch): ?>
        <!-- Which network this visitor is being served by, and the way to
             change it. Clearing the choice alone is not enough - the resolver
             would re-derive the same tenant from this device's history - so
             the POST sets a flag that suppresses the guess exactly once. -->
        <form class="portal-net" method="post" action="<?= e(url('customer/index.php')) ?>">
            <?= CSRF::field() ?>
            <input type="hidden" name="action" value="change_network">
            <span class="portal-net__text">
                <?= icon('antenna', 'ico--sm') ?>
                Network: <b><?= e($portalProvider['business_name'] ?? '') ?></b>
            </span>
            <button type="submit" class="link-btn"><?= icon('refresh', 'ico--sm') ?> Change</button>
        </form>
    <?php endif; ?>

    <?php /* While the visitor is still picking a network, the connection
             panel would answer a question they have not asked yet - and it
             cannot honestly mention buying before we know whether this
             network sells. */ ?>
    <?php $portalPicking = $portalProviderId === null && $portalChoices; ?>

    <?php if (!empty($portalStatus) && !$portalPicking): ?>
        <!-- The connection panel: the one thing the customer opened this page
             to find out, answered before anything else on the screen. -->
        <div class="portal-hero portal-hero--<?= e($portalStatus['tone']) ?>">
            <div class="portal-hero__fx" aria-hidden="true">
                <div class="net-bg net-bg--ink net-bg--inline">
                    <div class="net-bg__layer net-bg__dots"></div>
                    <div class="net-bg__waves">
                        <div class="net-bg__wave net-bg__wave--1"></div>
                        <div class="net-bg__wave net-bg__wave--3"></div>
                    </div>
                </div>
            </div>
            <div class="portal-hero__body">
                <span class="portal-hero__mark">
                    <?= icon($portalStatus['icon'] ?? 'wifi', 'ico--lg') ?>
                    <?php if (($portalStatus['tone'] ?? '') === 'online'): ?>
                        <span class="signal-rings" aria-hidden="true"><i></i><i></i><i></i></span>
                    <?php endif; ?>
                </span>
                <span class="portal-hero__text">
                    <b><?= e($portalStatus['title']) ?></b>
                    <span><?= e($portalStatus['text']) ?></span>
                </span>
            </div>
        </div>
    <?php endif; ?>

    <?= flash_messages() ?>

    <?php if ($portalServiceStopped): ?>
        <?= alert_box('warning',
            'This network is not selling or activating vouchers at the moment. If you are already online you will '
            . 'stay online until your package runs out. Please ask the operator when it will be back.',
            'Temporarily unavailable') ?>
    <?php endif; ?>

    <?php if ($portalPicking): ?>
        <!-- More than one provider runs on this platform and the router did
             not identify itself, so the visitor picks their network. The id
             is validated server side before it is accepted. -->
        <form class="portal-card" method="post" action="<?= e(url('customer/index.php')) ?>">
            <?= CSRF::field() ?>
            <input type="hidden" name="action" value="select_provider">
            <h1 class="portal-card__title">Which network are you on?</h1>
            <p class="portal-card__sub">Choose the Wi-Fi service you are connecting through.</p>
            <div class="package-list">
                <?php foreach ($portalChoices as $choice): ?>
                    <button type="submit" name="provider_id" value="<?= (int)$choice['id'] ?>" class="package-card" style="text-align:left;width:100%;font:inherit">
                        <div class="package-card__head">
                            <span class="package-card__name"><?= e($choice['business_name']) ?></span>
                            <?= icon('chevron', 'ico--sm') ?>
                        </div>
                        <?php if (!empty($choice['city'])): ?>
                            <div class="package-card__desc"><?= e($choice['city']) ?></div>
                        <?php endif; ?>
                    </button>
                <?php endforeach; ?>
            </div>
        </form>
        <?php require __DIR__ . '/_footer.php'; ?>
        <?php exit; ?>
    <?php endif; ?>
