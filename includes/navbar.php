<?php
/**
 * WMS - Public site navigation.
 *
 * Deliberately narrow: a brand, five anchors, a live status chip and the two
 * doors people actually came for. On a phone the anchors collapse into a
 * sheet and the two doors move to the bottom tab bar, where a thumb is.
 */
/* The platform's own name, never the provider the session happens to be
   pinned to - see platform_setting(). */
$navName = $navName ?? (string)platform_setting('app_name', 'WMS');
?>
<header class="site-header site-header--ink" id="top">
    <div class="site-wrap site-header__inner">
        <a class="site-brand" href="<?= e(url('index.php')) ?>">
            <span class="brand-mark brand-mark--lg"><?= icon('wifi', 'ico--lg') ?></span>
            <span class="brand-text">
                <b><?= e($navName) ?></b>
                <span>Wi-Fi management</span>
            </span>
        </a>

        <nav class="site-nav" aria-label="Sections">
            <a href="#connect">How it works</a>
            <a href="#modules">What it runs</a>
            <a href="#tariffs">Packages</a>
            <a href="#network">Network</a>
            <a href="#trust">Security</a>
        </nav>

        <div class="site-header__actions">
            <span class="net-chip" title="This installation is answering requests">
                <span class="live-dot"></span> System online
            </span>
            <a class="btn btn--ghost" href="<?= e(url('customer/index.php')) ?>">Get online</a>
            <a class="btn btn--primary" href="<?= e(url('login.php')) ?>"><?= icon('lock', 'ico--sm') ?> Staff sign in</a>
        </div>

        <button type="button" class="icon-btn site-nav__toggle" data-site-nav
                aria-label="Menu" aria-expanded="false"><?= icon('menu') ?></button>
    </div>
</header>
