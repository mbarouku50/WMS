<?php
/**
 * WMS - Public landing page.
 *
 * The front door (WMS/index.php - there is no public/ folder). Where the
 * system is installed it shows the operator's own live package list and its
 * own counts rather than invented marketing numbers; where it is not, every
 * example is labelled as one.
 *
 * The page is built as a network diagram first and a brochure second: the
 * hero carries a working map of what the product connects, the figures
 * scroll past like a wallboard, and the modules are sized by how much of the
 * operator's day they take rather than dropped into nine identical boxes.
 */

require_once __DIR__ . '/config/config.php';
require_once INCLUDES_PATH . '/components.php';

$installed = WMS_INSTALLED;
$packages  = [];
$figures   = ['packages' => 0, 'routers' => 0, 'vouchers' => 0, 'customers' => 0, 'sessions' => 0];

if ($installed) {
    try {
        $db = Database::getInstance();

        /*
         * Whose packages belong on the platform's own front page?
         *
         * On a single-tenant installation this page IS that operator's page,
         * so their real tariffs are the honest thing to show. Where several
         * operators share the platform there is no single answer - and using
         * whichever one the visitor last picked in the captive portal would
         * put one tenant's prices on the platform's home page - so the page
         * falls back to the labelled examples instead.
         */
        $activeProviders = $db->fetchAll("SELECT id FROM providers WHERE status = 'active' LIMIT 2");
        if (count($activeProviders) === 1) {
            $packages = array_slice(
                (new Package())->activeForProvider((int)$activeProviders[0]['id']),
                0,
                4
            );
        }

        $figures['packages']  = $db->count("SELECT COUNT(*) FROM packages WHERE status = 'active'");
        $figures['routers']   = $db->count('SELECT COUNT(*) FROM routers');
        $figures['vouchers']  = $db->count("SELECT COUNT(*) FROM vouchers WHERE status = 'available'");
        $figures['customers'] = $db->count('SELECT COUNT(*) FROM customers');
        $figures['sessions']  = $db->count("SELECT COUNT(*) FROM sessions WHERE status = 'active'");
    } catch (Throwable $e) {
        Logger::warning('Landing page could not read the database: ' . $e->getMessage());
        $installed = false;
    }
}

/** A figure the installation can vouch for, or an honest dash. */
$fig = static fn(string $key): string => $installed ? number_format($figures[$key]) : '—';

/*
 * The landing page belongs to the platform, not to whichever provider the
 * session is pinned to. Read the platform's own names explicitly, or a
 * visitor who has picked a network in the portal is met by that operator's
 * branding on the way back to the front door.
 */
$company   = (string)platform_setting('company_name', 'WMS Networks');
$appName   = (string)platform_setting('app_name', 'WMS');
$pageTitle = $company . ' · Wi-Fi Management System';
$bodyClass = 'wms-public';
$netTone   = 'light';
$hasTabbar = true;
$navName   = $appName;

require INCLUDES_PATH . '/header.php';
require INCLUDES_PATH . '/navbar.php';
?>

<?php if (!$installed): ?>
<div style="background:var(--wms-warning-soft);border-bottom:1px solid var(--wms-border)">
    <div class="site-wrap" style="padding:.7rem 1.25rem;font-size:.8125rem">
        <b>Not installed yet.</b> Run the installer to create the database and your administrator account.
        <a href="<?= e(url('install.php')) ?>" style="font-weight:600">Open the installer →</a>
    </div>
</div>
<?php endif; ?>

<!-- ============================================================ HERO -->
<section class="nhero">
    <!-- The atmosphere is local to the hero so it can be dark while the rest
         of the page is light, without two backdrops fighting each other. -->
    <div class="nhero__fx" aria-hidden="true">
        <div class="net-bg net-bg--ink net-bg--inline">
            <div class="net-bg__layer net-bg__bloom"></div>
            <div class="net-bg__layer net-bg__dots"></div>
            <div class="net-bg__layer net-bg__dots--fine"></div>
            <div class="net-bg__scan"></div>
            <div class="net-bg__waves">
                <div class="net-bg__wave net-bg__wave--1"></div>
                <div class="net-bg__wave net-bg__wave--2"></div>
                <div class="net-bg__wave net-bg__wave--3"></div>
            </div>
        </div>
    </div>

    <div class="site-wrap nhero__inner nhero__inner--map">
        <!-- A short headline says what this is; the diagram underneath says
             how it fits together. Between them the visitor knows within a
             couple of seconds whether they are in the right place. -->
        <div class="nhero__head">
            <span class="nhero__kicker">Hotspot control · MikroTik RouterOS</span>
            <h1><em>Sell Internet</em> by the hour.</h1>
            <p class="nhero__lead">
                Vouchers, mobile money and the routers that carry the traffic — one system,
            </p>
        </div>

        <?= net_map([
            ['icon' => 'router', 'label' => 'Gateway', 'value' => 'RouterOS 7'],
            ['icon' => 'signal', 'label' => 'Shaping', 'value' => 'per package'],
            ['icon' => 'card',   'label' => 'Paid by', 'value' => 'mobile money'],
        ]) ?>

        <div class="nhero__cta">
            <a class="btn btn--primary btn--lg" href="<?= e(url('customer/index.php')) ?>">
                <?= icon('wifi', 'ico--sm') ?> Get online now
            </a>
            <a class="btn btn--ghost btn--lg" href="<?= e(url('login.php')) ?>">
                <?= icon('dashboard', 'ico--sm') ?> Staff sign in
            </a>
        </div>
    </div>
</section>

<!-- ========================================================== TICKER -->
<div class="ticker" aria-hidden="true">
    <div class="ticker__track">
        <?php
        /* Doubled so the loop is seamless: the animation slides exactly one
           copy's width and starts over. */
        $ticks = [
            ['signal',   'Live packages',    $fig('packages')],
            ['ticket',   'Vouchers ready',   $fig('vouchers')],
            ['router',   'Routers managed',  $fig('routers')],
            ['users',    'Customers',        $fig('customers')],
            ['activity', 'Sessions open',    $fig('sessions')],
            ['card',     'Mobile money',     'M-Pesa · Tigo · Airtel · Halopesa'],
            ['shield',   'Every action',     'audit logged'],
            ['clock',    'Session accounting', 'to the second'],
        ];
        for ($pass = 0; $pass < 2; $pass++) {
            foreach ($ticks as [$ico, $label, $value]) {
                echo '<span class="ticker__item">' . icon($ico, 'ico--sm')
                    . e($label) . ' <b>' . e($value) . '</b></span>';
            }
        }
        ?>
    </div>
</div>

<!-- ======================================================== HOW IT WORKS -->
<section class="section" id="connect">
    <div class="site-wrap">
        <div class="eyebrow-row" data-reveal>
            <div class="eyebrow-row__title">
                <span class="section-label">The path of one customer</span>
                <h2>Phone to router in five steps, and the money lands in the middle</h2>
                <p>
                    Nothing here is theoretical - this is the exact sequence the system performs, in
                    order, for every single person who connects.
                </p>
            </div>
            <div class="eyebrow-row__aside">/ 01 — connect</div>
        </div>

        <div class="grid grid--1-2" style="gap:2.5rem;align-items:start">
            <!-- The portal, shown as it really looks. Not a mock-up of a
                 dashboard nobody will ever see - the screen the customer meets. -->
            <div class="card" style="overflow:hidden" data-reveal>
                <div class="card__head">
                    <h3 class="card__title"><?= icon('device') ?> The customer's screen</h3>
                </div>
                <div class="card__body">
                    <div style="border:1px solid var(--wms-border);border-radius:var(--wms-radius);padding:1rem;background:var(--wms-surface-alt)">
                        <div class="eyebrow mb-1">Enter your voucher</div>
                        <div style="font-family:var(--wms-mono);font-size:1.15rem;font-weight:700;letter-spacing:.16em;text-align:center;padding:.7rem;border:2px dashed var(--wms-border-strong);border-radius:var(--wms-radius);color:var(--wms-text-faint)">
                            XXXX-XXXX
                        </div>
                        <div class="btn btn--primary btn--block mt-2" style="pointer-events:none">
                            <?= icon('wifi', 'ico--sm') ?> Connect me
                        </div>
                    </div>
                    <p class="small muted mt-2 mb-0">
                        One field and one button. On a captive portal the customer has not paid for data
                        yet, so the page carries no fonts, no frameworks and no tracking.
                    </p>
                    <a class="btn btn--block mt-2" href="<?= e(url('customer/index.php')) ?>">
                        Open the real portal <?= icon('chevron', 'ico--sm') ?>
                    </a>
                </div>
            </div>

            <div class="rail">
                <span class="rail__pulse" aria-hidden="true"></span>
                <?php
                $steps = [
                    ['Device joins the Wi-Fi', 'The router hands out an address and redirects anything unpaid to the portal. WMS never has to guess who arrived - the router tells it.', 'mac · ip · access point', false],
                    ['Voucher, account or purchase', 'Three ways in, one outcome: a voucher code, a customer sign-in, or a package bought on the spot with mobile money.', 'portal / customer', true],
                    ['Money settles before access', 'The USSD prompt goes to the payer\'s phone. Access is granted when the provider confirms the payment - not when the request was sent.', 'sonicpesa · webhook', true],
                    ['Rules pushed to the router', 'A hotspot user and a queue are created on the MikroTik with the speed, data cap and device count that package was sold under.', 'routeros api', false],
                    ['Every megabyte accounted', 'Session time, download, upload and daily totals come back from the router and land against the customer, the voucher and the router.', 'sessions · usage', false],
                ];
                foreach ($steps as $i => [$title, $text, $meta, $accent]) {
                    echo '<div class="rail__step' . ($accent ? ' rail__step--accent' : '') . '"'
                    . ' data-reveal>'
                        . '<span class="rail__num">' . str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT) . '</span>'
                        . '<div class="rail__body"><h3>' . e($title) . '</h3><p>' . e($text) . '</p>'
                        . '<span class="rail__meta">' . icon('link', 'ico--sm') . e($meta) . '</span></div></div>';
                }
                ?>
            </div>
        </div>
    </div>
</section>

<?= wave_sep('rgba(243, 247, 249, .72)') ?>

<!-- ========================================================== MODULES -->
<section class="section section--alt" id="modules">
    <div class="site-wrap">
        <div class="eyebrow-row" data-reveal>
            <div class="eyebrow-row__title">
                <span class="section-label">What it runs</span>
                <h2>Nine jobs an operator does every day, and where each one lives</h2>
                <p>Sized by how much of your week they take, not by how they fit a grid.</p>
            </div>
            <div class="eyebrow-row__aside">/ 02 — modules</div>
        </div>

        <div class="bento">
            <!-- The two that consume the most time get the most room. -->
            <article class="bento__cell bento__cell--wide bento__cell--ink" data-reveal>
                <div class="bento__icon"><?= icon('ticket', 'ico--lg') ?></div>
                <h3>Voucher engine</h3>
                <p>
                    Generate one card or two thousand. Each voucher carries a snapshot of the package
                    it was sold under, so editing a package tomorrow never changes what somebody
                    already paid for.
                </p>
                <ul class="bento__list">
                    <li><?= icon('check', 'ico--sm') ?><span>Batches with your own prefix, length and unique-code guarantee</span></li>
                    <li><?= icon('check', 'ico--sm') ?><span>Available → active → expired / exhausted / suspended / cancelled</span></li>
                    <li><?= icon('check', 'ico--sm') ?><span>Printable cards with a reserved QR area</span></li>
                </ul>
                <div class="bento__bars" aria-hidden="true"><i></i><i></i><i></i><i></i></div>
            </article>

            <article class="bento__cell bento__cell--wide" data-reveal>
                <div class="bento__icon"><?= icon('router', 'ico--lg') ?></div>
                <h3>Multi-router control</h3>
                <p>
                    Several MikroTik routers and their access points, each with its own credentials
                    stored encrypted. Status, CPU, memory, uptime and interfaces are read from RouterOS
                    itself — when a router cannot be reached the system says so and raises an alert
                    instead of filling the gap with a number it made up.
                </p>
                <ul class="bento__list">
                    <li><?= icon('check', 'ico--sm') ?><span>Live session control — view, disconnect, block a device</span></li>
                    <li><?= icon('check', 'ico--sm') ?><span>Demo mode for evaluating everything before any hardware exists</span></li>
                </ul>
                <div class="bento__bars" aria-hidden="true"><i></i><i></i><i></i><i></i></div>
            </article>

            <article class="bento__cell" data-reveal>
                <div class="bento__icon"><?= icon('users', 'ico--lg') ?></div>
                <h3>Customers</h3>
                <p>Accounts, devices, sessions, usage and payment history in one profile. Suspend, activate or investigate without leaving the page.</p>
            </article>

            <article class="bento__cell" data-reveal>
                <div class="bento__icon"><?= icon('package', 'ico--lg') ?></div>
                <h3>Packages</h3>
                <p>Price, duration, data cap, speeds and how many devices may share it. Nothing hard-coded — build the plans your market actually buys.</p>
            </article>

            <article class="bento__cell" data-reveal>
                <div class="bento__icon"><?= icon('card', 'ico--lg') ?></div>
                <h3>Mobile money</h3>
                <p>A provider layer with SonicPesa built in for M-Pesa, Tigo Pesa, Airtel Money and Halopesa — plus a demo provider for testing.</p>
            </article>

            <article class="bento__cell" data-reveal>
                <div class="bento__icon"><?= icon('activity', 'ico--lg') ?></div>
                <h3>Sessions</h3>
                <p>Who is online, on which device, through which access point, using how much — with the disconnect button on the same screen.</p>
            </article>

            <article class="bento__cell" data-reveal>
                <div class="bento__icon"><?= icon('report', 'ico--lg') ?></div>
                <h3>Reporting</h3>
                <p>Revenue, customer growth, voucher performance and network load. On screen, printable, CSV ready, over any date range.</p>
            </article>

            <article class="bento__cell" data-reveal>
                <div class="bento__icon"><?= icon('signal', 'ico--lg') ?></div>
                <h3>Usage accounting</h3>
                <p>Download, upload, connected time and daily totals — per customer, per voucher and per router.</p>
            </article>

            <article class="bento__cell bento__cell--full" data-reveal>
                <div class="bento__icon"><?= icon('shield', 'ico--lg') ?></div>
                <h3>Roles and an audit trail</h3>
                <p>
                    Four roles out of the box, each a set of permissions checked on the server rather
                    than hidden in the interface — and a log of who did what, kept whether or not
                    anyone is watching.
                </p>
                <ul class="bento__list" style="grid-template-columns:repeat(auto-fit,minmax(200px,1fr));display:grid">
                    <li><?= icon('check', 'ico--sm') ?><span><b>Super admin</b> — the whole platform</span></li>
                    <li><?= icon('check', 'ico--sm') ?><span><b>Network</b> — routers, sessions, devices</span></li>
                    <li><?= icon('check', 'ico--sm') ?><span><b>Sales</b> — packages, vouchers, payments</span></li>
                    <li><?= icon('check', 'ico--sm') ?><span><b>Support</b> — customers, read-mostly</span></li>
                </ul>
                <div class="bento__bars" aria-hidden="true"><i></i><i></i><i></i><i></i></div>
            </article>
        </div>
    </div>
</section>

<?= wave_sep('var(--wms-surface)', true) ?>

<!-- ========================================================== TARIFFS -->
<section class="section" id="tariffs">
    <div class="site-wrap">
        <div class="eyebrow-row" data-reveal>
            <div class="eyebrow-row__title">
                <span class="section-label">Packages</span>
                <h2><?= $packages ? 'On sale right now, read from this installation' : 'Tariffs you write yourself' ?></h2>
                <p>
                    <?= $packages
                        ? 'What you configure is exactly what the customer sees — this list comes out of the same table the portal reads.'
                        : 'Minutes, hours, days or months. Capped or unlimited. One device or eight. The four below are examples, not products.' ?>
                </p>
            </div>
            <div class="eyebrow-row__aside">/ 03 — tariffs</div>
        </div>

        <div class="tariff">
            <?php if ($packages): ?>
                <?php foreach ($packages as $package): ?>
                    <article class="tariff__card<?= !empty($package['is_featured']) ? ' tariff__card--featured' : '' ?>" data-reveal>
                        <?php if (!empty($package['is_featured'])): ?><span class="tariff__flag">Popular</span><?php endif; ?>
                        <div class="tariff__name"><?= e($package['name']) ?></div>
                        <div class="tariff__price"><?= e(money((float)$package['price'])) ?></div>
                        <div class="tariff__for"><?= e(format_package_duration((int)$package['duration_value'], (string)$package['duration_unit'])) ?> of access</div>
                        <ul class="tariff__specs">
                            <li><?= icon('database', 'ico--sm') ?><?= e(format_mb($package['data_limit_mb'] === null ? null : (int)$package['data_limit_mb'])) ?></li>
                            <li><?= icon('signal', 'ico--sm') ?><?= e(format_speed((int)$package['download_kbps'])) ?> down</li>
                            <li><?= icon('device', 'ico--sm') ?><?= (int)$package['device_limit'] ?> device<?= (int)$package['device_limit'] === 1 ? '' : 's' ?></li>
                        </ul>
                        <a class="btn btn--primary btn--block" href="<?= e(url('customer/purchase.php?package=' . (int)$package['id'])) ?>">Buy this package</a>
                    </article>
                <?php endforeach; ?>
            <?php else: ?>
                <?php
                $examplePackages = [
                    ['Quick Hour',      'TSh 500',   'Unlimited', '1 hour',   '3 Mbps', 1, false],
                    ['Daily 5GB',       'TSh 1,000', '5 GB',      '24 hours', '5 Mbps', 1, true],
                    ['Daily Unlimited', 'TSh 2,000', 'Unlimited', '24 hours', '5 Mbps', 2, false],
                    ['Weekly',          'TSh 9,000', 'Unlimited', '7 days',   '5 Mbps', 3, false],
                ];
                foreach ($examplePackages as [$name, $price, $data, $duration, $speed, $devices, $featured]) {
                    echo '<article class="tariff__card' . ($featured ? ' tariff__card--featured' : '') . '"'
                        . ' data-reveal>'
                        . ($featured ? '<span class="tariff__flag">Example</span>' : '')
                        . '<div class="tariff__name">' . e($name) . '</div>'
                        . '<div class="tariff__price">' . e($price) . '</div>'
                        . '<div class="tariff__for">' . e($duration) . ' of access</div>'
                        . '<ul class="tariff__specs">'
                        . '<li>' . icon('database', 'ico--sm') . e($data) . '</li>'
                        . '<li>' . icon('signal', 'ico--sm') . e($speed) . ' down</li>'
                        . '<li>' . icon('device', 'ico--sm') . $devices . ' device' . ($devices === 1 ? '' : 's') . '</li>'
                        . '</ul>'
                        . '<span class="btn btn--block" style="pointer-events:none;opacity:.6">Example tariff</span>'
                        . '</article>';
                }
                ?>
            <?php endif; ?>
        </div>

        <!-- Vouchers, shown as the physical thing they become. -->
        <div class="grid grid--1-2 mt-3" style="gap:2rem;align-items:center" data-reveal>
            <div>
                <span class="section-label">Off the same tariff</span>
                <h3 style="font-size:1.2rem">Print a batch in the morning, sell it all day</h3>
                <p class="muted small">
                    A voucher is a package that has already been paid for. It carries its own speed,
                    cap, expiry and device count, so an agent at a counter with no connection can still
                    hand one over and be sure of what they sold.
                </p>
                <a class="btn mt-1" href="<?= e(url('customer/index.php')) ?>">
                    <?= icon('wifi', 'ico--sm') ?> Redeem a code
                </a>
            </div>
            <div class="voucher-grid">
                <?php
                foreach ([
                    ['code' => 'WMS-7KD4P2', 'price' => 1000, 'data_limit_mb' => 5120, 'duration_value' => 24, 'duration_unit' => 'hours', 'download_kbps' => 5120],
                    ['code' => 'WMS-QX93MT', 'price' => 2000, 'data_limit_mb' => null, 'duration_value' => 24, 'duration_unit' => 'hours', 'download_kbps' => 5120],
                ] as $sample) {
                    /* Samples, so they carry the platform's own name. */
                    echo voucher_card($sample, true, $company, (string)platform_setting('portal_ssid', 'WMS-Hotspot'));
                }
                ?>
            </div>
        </div>
    </div>
</section>

<?= wave_sep('rgba(243, 247, 249, .72)') ?>

<!-- ========================================================== NETWORK -->
<section class="section section--alt" id="network">
    <div class="site-wrap">
        <div class="eyebrow-row" data-reveal>
            <div class="eyebrow-row__title">
                <span class="section-label">Network</span>
                <h2>See the network, not a guess about it</h2>
                <p>Router commands live in one service. Adding or swapping equipment means one provider class, not forty pages.</p>
            </div>
            <div class="eyebrow-row__aside">/ 04 — network</div>
        </div>

        <div class="bento">
            <article class="bento__cell bento__cell--wide" data-reveal>
                <div class="bento__icon"><?= icon('sliders', 'ico--lg') ?></div>
                <h3>One path to the hardware</h3>
                <p class="mb-2">Every screen asks the same service, and the service asks the provider.</p>
                <div class="flow flow--stack">
                    <div class="flow__node"><b>Admin page</b><span>a view, with no router code in it</span></div>
                    <div class="flow__node"><b>NetworkService</b><span>business rules, alerts, freshness</span></div>
                    <div class="flow__node flow__node--accent"><b>NetworkProvider</b><span>MikroTik · Demo</span></div>
                    <div class="flow__node"><b>RouterOS API</b><span>hotspot users, queues, sessions</span></div>
                </div>
            </article>

            <article class="bento__cell bento__cell--wide" data-reveal>
                <div class="bento__icon"><?= icon('antenna', 'ico--lg') ?></div>
                <h3>What comes back, and what does not</h3>
                <ul class="bento__list" style="font-size:.8125rem">
                    <li><?= icon('check', 'ico--sm') ?><span><b>Read live:</b> status, CPU and memory load, uptime, interfaces, hotspot sessions, traffic counters.</span></li>
                    <li><?= icon('check', 'ico--sm') ?><span><b>Derived:</b> access-point health, from the parent router's interface state.</span></li>
                    <li><?= icon('check', 'ico--sm') ?><span><b>Never invented:</b> an unreachable router shows as unreachable, and the reading is labelled with its age.</span></li>
                    <li><?= icon('check', 'ico--sm') ?><span><b>Encrypted at rest:</b> per-router credentials, never in HTML or JavaScript.</span></li>
                </ul>
                <div class="bento__bars" aria-hidden="true"><i></i><i></i><i></i><i></i></div>
            </article>
        </div>
    </div>
</section>

<!-- ========================================================== SECURITY -->
<section class="section section--ink" id="trust" style="border-radius:0">
    <div class="site-wrap">
        <div class="eyebrow-row" data-reveal style="border-bottom-color:rgba(255,255,255,.1)">
            <div class="eyebrow-row__title">
                <span class="section-label" style="color:var(--wms-brand)">Security</span>
                <h2 style="color:#fff">Plumbing, not a feature list</h2>
                <p style="color:#a9b5c3">You are holding other people's money and other people's traffic. These are the defaults, not the upgrades.</p>
            </div>
            <div class="eyebrow-row__aside" style="color:#5d7280">/ 05 — trust</div>
        </div>

        <div class="grid grid--2" style="gap:1rem 3rem" data-reveal>
            <ul class="check-list">
                <li><?= icon('lock', 'ico--sm') ?><span><b>Passwords</b> hashed with password_hash(), never stored or logged in the clear.</span></li>
                <li><?= icon('database', 'ico--sm') ?><span><b>Prepared statements</b> for every query, without exception.</span></li>
                <li><?= icon('shield', 'ico--sm') ?><span><b>CSRF tokens</b> on every form and POST endpoint.</span></li>
            </ul>
            <ul class="check-list">
                <li><?= icon('key', 'ico--sm') ?><span><b>Router and payment credentials</b> stay server side, always.</span></li>
                <li><?= icon('block', 'ico--sm') ?><span><b>Login throttling</b> against brute force, per account and per address.</span></li>
                <li><?= icon('history', 'ico--sm') ?><span><b>Audit logging</b> of logins, vouchers, payments, routers, staff and settings.</span></li>
            </ul>
        </div>
    </div>
</section>

<!-- ============================================================= BAND -->
<section class="band">
    <div class="band__fx" aria-hidden="true">
        <div class="net-bg net-bg--ink net-bg--inline">
            <div class="net-bg__layer net-bg__dots"></div>
            <div class="net-bg__waves">
                <div class="net-bg__wave net-bg__wave--1"></div>
                <div class="net-bg__wave net-bg__wave--3"></div>
            </div>
        </div>
    </div>
    <div class="site-wrap band__inner" data-reveal>
        <div>
            <h2>Two doors. Pick the one you came for.</h2>
            <p>Customers get online in three taps. Staff get the control centre, with everything above it in one place.</p>
        </div>
        <div class="flex gap-1 flex-wrap">
            <a class="btn btn--primary btn--lg" href="<?= e(url('customer/index.php')) ?>">
                <?= icon('wifi', 'ico--sm') ?> Customer portal
            </a>
            <a class="btn btn--ghost btn--lg" style="color:#d6e5ec;border-color:rgba(255,255,255,.24)" href="<?= e(url('login.php')) ?>">
                <?= icon('lock', 'ico--sm') ?> Control centre
            </a>
        </div>
    </div>
</section>

<!-- ========================================================== FOOTER -->
<footer class="site-footer">
    <div class="site-wrap">
        <div class="site-footer__grid">
            <div>
                <a class="site-brand" href="<?= e(url('index.php')) ?>" style="color:#fff">
                    <span class="brand-mark"><?= icon('wifi') ?></span>
                    <span class="brand-text"><b style="color:#fff"><?= e($appName) ?></b><span>Wi-Fi management</span></span>
                </a>
                <p class="mt-2" style="max-width:26rem"><?= e(platform_setting('company_tagline', 'Connectivity you can account for.')) ?></p>
                <span class="net-chip net-chip--light" style="margin-top:.5rem">
                    <span class="live-dot"></span> <?= demo_mode() ? 'Demo mode' : 'Live mode' ?>
                </span>
            </div>
            <div>
                <h4>Product</h4>
                <a href="#connect">How it works</a>
                <a href="#modules">Modules</a>
                <a href="#tariffs">Packages</a>
                <a href="#network">Network</a>
            </div>
            <div>
                <h4>Access</h4>
                <a href="<?= e(url('login.php')) ?>">Staff sign in</a>
                <a href="<?= e(url('customer/index.php')) ?>">Captive portal</a>
                <a href="<?= e(url('customer/login.php')) ?>">Customer account</a>
                <a href="<?= e(url('customer/packages.php')) ?>">Buy a package</a>
            </div>
            <div>
                <h4>Support</h4>
                <a href="tel:<?= e(preg_replace('/\s+/', '', (string)platform_setting('support_phone', ''))) ?>"><?= e(platform_setting('support_phone', '—')) ?></a>
                <a href="mailto:<?= e(platform_setting('support_email', '')) ?>"><?= e(platform_setting('support_email', '—')) ?></a>
            </div>
        </div>
        <div class="site-footer__bottom">
            <span>&copy; <?= date('Y') ?> <?= e($company) ?>. All rights reserved.</span>
            <span><?= e($appName) ?> v<?= e(WMS_VERSION) ?></span>
        </div>
    </div>
</footer>

<?php
/* On a phone the two doors from the header move down here, within thumb
   reach, and stay there while the page scrolls. */
echo app_tabbar([
    ['key' => 'home',    'label' => 'Home',     'icon' => 'globe',   'href' => 'index.php'],
    ['key' => 'tariffs', 'label' => 'Packages', 'icon' => 'package', 'href' => 'customer/packages.php'],
    ['key' => 'connect', 'label' => 'Connect',  'icon' => 'wifi',    'href' => 'customer/index.php', 'action' => true],
    ['key' => 'status',  'label' => 'My status','icon' => 'activity','href' => 'customer/status.php'],
    ['key' => 'staff',   'label' => 'Staff',    'icon' => 'lock',    'href' => 'login.php'],
], 'home');
?>

<script src="<?= e(asset('js/app.js')) ?>"></script>
<script>
/* The only script this page needs: the mobile menu sheet. */
document.addEventListener('click', function (e) {
    var toggle = e.target.closest('[data-site-nav]');
    var nav = document.querySelector('.site-nav');
    if (!nav) { return; }

    if (toggle) {
        var open = nav.classList.toggle('is-open');
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        return;
    }
    /* Tapping a link, or anywhere outside, puts the sheet away again. */
    if (nav.classList.contains('is-open') && (e.target.closest('.site-nav a') || !e.target.closest('.site-header'))) {
        nav.classList.remove('is-open');
        var btn = document.querySelector('[data-site-nav]');
        if (btn) { btn.setAttribute('aria-expanded', 'false'); }
    }
});
</script>
</body>
</html>
