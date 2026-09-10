<?php
/**
 * WMS - Router provisioning and readiness check.
 *
 * The screen you use when connecting a real MikroTik: it shows the exact
 * RouterOS commands to run, then interrogates the router and tells you, in
 * plain language, whether WMS can actually grant internet access through it.
 *
 * Nothing here is guessed. Every green tick is something WMS just did or
 * read on the device.
 */

$requiredPermission = 'manage_routers';
require_once __DIR__ . '/../../includes/auth-check.php';
require_once INCLUDES_PATH . '/components.php';

$routers = new Router();
$id      = (int)query('id');

// Tenant scoped: another provider's router simply is not found here.
$router = $id ? $routers->find($id) : null;
if (!$router) {
    Response::redirect('admin/network/routers.php', 'error', 'That router could not be found.');
}

$network     = new NetworkService();
$diagnostics = null;

if (is_post()) {
    CSRF::verify();

    if (post('action') === 'diagnose') {
        $withCredentials = $routers->withCredentials($id) ?? $router;
        $provider = $network->providerFor($withCredentials);
        $diagnostics = $provider->diagnose();
        $provider->disconnect();

        AuditLog::record('router_diagnose', 'router', $id,
            'Ran the readiness check on ' . $router['name'] . ' — ' . ($diagnostics['ok'] ? 'ready' : 'needs attention'));

        /*
         * Record what we actually found, so the router list agrees with this
         * page rather than showing a stale "unknown".
         */
        $reachable = !empty($diagnostics['checks'][0]['ok']);

        if ($reachable) {
            $liveProvider = $network->providerFor($withCredentials);
            $status = $liveProvider->getRouterStatus();
            $liveProvider->disconnect();
            $routers->recordStatus($id, $status);
            Alert::clear('router_offline', 'router', $id);
        } else {
            $routers->recordStatus($id, [
                'status' => 'offline',
                'error'  => $diagnostics['checks'][0]['detail'] ?? 'Unreachable',
            ]);
            Alert::raise('router_offline', 'danger',
                'Router ' . $router['name'] . ' is unreachable',
                (string)($diagnostics['checks'][0]['detail'] ?? 'The readiness check could not reach it.'),
                'router', $id);
        }

        $router = $routers->find($id) ?? $router;
    }

    if (post('action') === 'go_live') {
        $routers->updateById($id, ['mode' => 'live']);
        AuditLog::record('router_mode', 'router', $id, 'Switched ' . $router['name'] . ' to Live mode');
        Response::back('success', $router['name'] . ' is now in Live mode. WMS will poll it and push hotspot users to it.');
    }

    if (post('action') === 'go_demo') {
        $routers->updateById($id, ['mode' => 'demo']);
        AuditLog::record('router_mode', 'router', $id, 'Switched ' . $router['name'] . ' to Demo mode');
        Response::back('warning', $router['name'] . ' is back in Demo mode. It will not be contacted.');
    }
}

$providerName = ProviderContext::isGlobalScope()
    ? (string)(Database::getInstance()->fetchColumn('SELECT business_name FROM providers WHERE id = ?', [(int)$router['provider_id']]) ?: 'Unassigned')
    : ProviderContext::scopeLabel();

/* The address the router should allow, and the portal URL it should redirect to. */
$serverIp  = $_SERVER['SERVER_ADDR'] ?? 'YOUR.WMS.SERVER.IP';
$portalUrl = rtrim(APP_URL, '/') . '/customer/';

$pageTitle   = 'Set up ' . $router['name'];
$activeNav   = 'routers';
$breadcrumbs = [
    ['label' => 'Network'],
    ['label' => 'Routers', 'url' => 'admin/network/routers.php'],
    ['label' => $router['name']],
];
require INCLUDES_PATH . '/admin-header.php';
?>

<?= page_head('Set up ' . $router['name'],
    $providerName . ' · ' . $router['ip_address'] . ':' . (int)$router['api_port'],
    '<a class="btn" href="' . e(url('admin/network/routers.php')) . '">' . icon('chevron', 'ico--sm') . ' All routers</a>'
    . '<form method="post" style="display:inline">' . CSRF::field()
    . '<input type="hidden" name="action" value="diagnose">'
    . '<button class="btn btn--primary">' . icon('activity', 'ico--sm') . ' Run readiness check</button></form>'
) ?>

<!-- ====================================================== mode banner -->
<?php if ($router['mode'] !== 'live'): ?>
    <div class="alert alert--warning">
        <?= icon('alert', 'alert__icon') ?>
        <div class="alert__body">
            <div class="alert__title">This router is in Demo mode</div>
            WMS will not contact it, and voucher activations here will not create hotspot users.
            Switch it to Live once the checks below pass.
        </div>
        <form method="post"><?= CSRF::field() ?>
            <input type="hidden" name="action" value="go_live">
            <button class="btn btn--sm btn--primary"><?= icon('power', 'ico--sm') ?> Switch to Live</button>
        </form>
    </div>
<?php else: ?>
    <div class="alert alert--success">
        <?= icon('check', 'alert__icon') ?>
        <div class="alert__body">
            <div class="alert__title">Live mode</div>
            WMS polls this router and pushes real hotspot users to it when vouchers are activated.
        </div>
        <form method="post" data-confirm="Put this router back in Demo mode? WMS will stop contacting it and vouchers will no longer create hotspot users on it.">
            <?= CSRF::field() ?>
            <input type="hidden" name="action" value="go_demo">
            <button class="btn btn--sm"><?= icon('power', 'ico--sm') ?> Back to Demo</button>
        </form>
    </div>
<?php endif; ?>

<div class="grid grid--3-2">
    <!-- ================================================ the checks -->
    <div>
        <?php if ($diagnostics === null): ?>
            <section class="card mb-3">
                <div class="card__body">
                    <?= empty_state([
                        'icon'  => 'activity',
                        'title' => 'Readiness check not run yet',
                        'text'  => 'WMS will connect to this router and check that it can read its identity, find a hotspot server and an IP pool, and — most importantly — create and remove a hotspot user. Nothing is left behind.',
                        'action' => '<form method="post">' . CSRF::field()
                            . '<input type="hidden" name="action" value="diagnose">'
                            . '<button class="btn btn--primary">' . icon('activity', 'ico--sm') . ' Run the check now</button></form>',
                    ]) ?>
                </div>
            </section>
        <?php else: ?>
            <section class="card mb-3">
                <div class="card__head">
                    <div>
                        <h2 class="card__title"><?= icon('activity') ?> Readiness check</h2>
                        <p class="card__subtitle"><?= e(date('d M Y H:i')) ?></p>
                    </div>
                    <?= $diagnostics['ok']
                        ? '<span class="badge badge--success">Ready</span>'
                        : '<span class="badge badge--warning">Needs attention</span>' ?>
                </div>
                <div class="card__body">
                    <?= alert_box($diagnostics['ok'] ? 'success' : 'warning', $diagnostics['summary']) ?>

                    <?php foreach ($diagnostics['checks'] as $check): ?>
                        <div class="node mb-1" style="align-items:flex-start">
                            <span class="node__icon node__icon--<?= $check['ok'] ? 'online' : 'offline' ?>">
                                <?= icon($check['ok'] ? 'check' : 'x') ?>
                            </span>
                            <span class="node__text">
                                <b><?= e($check['label']) ?></b>
                                <span style="white-space:normal"><?= e($check['detail']) ?></span>
                                <?php if (!empty($check['fix'])): ?>
                                    <div class="mt-1">
                                        <div class="tiny eyebrow mb-1">Run this on the router</div>
                                        <pre class="code-chip" style="display:block;padding:.5rem .6rem;white-space:pre-wrap;line-height:1.5"><?= e($check['fix']) ?></pre>
                                    </div>
                                <?php endif; ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <!-- ============================================ router facts -->
        <section class="card">
            <div class="card__head"><h2 class="card__title"><?= icon('router') ?> What WMS knows</h2></div>
            <div class="card__body">
                <?= key_value([
                    'Provider'     => e($providerName),
                    'API address'  => code_chip($router['ip_address'] . ':' . (int)$router['api_port']),
                    'API user'     => code_chip($router['api_username']),
                    'API password' => !empty($router['api_password'])
                        ? '<span class="badge badge--success">Stored, encrypted</span>'
                        : '<span class="badge badge--danger">Not set</span>',
                    'TLS'          => !empty($router['use_tls']) ? 'Yes (api-ssl)' : 'No (plain api)',
                    'Mode'         => $router['mode'] === 'live'
                        ? '<span class="badge badge--live">Live</span>'
                        : '<span class="badge badge--demo">Demo</span>',
                    'Status'       => badge($router['status']),
                    'Identity'     => e((string)$router['identity']),
                    'RouterOS'     => e((string)$router['routeros_version']),
                    'Uptime'       => e((string)$router['uptime']),
                    'Last answered'=> $router['last_seen_at'] ? e(format_date($router['last_seen_at'])) : '<span class="faint">Never</span>',
                    'Last error'   => e((string)$router['last_error']),
                ]) ?>
                <div class="mt-2">
                    <a class="btn btn--sm" href="<?= e(url('admin/network/routers.php?edit=' . $id)) ?>"><?= icon('edit', 'ico--sm') ?> Edit connection details</a>
                </div>
            </div>
        </section>
    </div>

    <!-- ============================================ the instructions -->
    <div>
        <section class="card mb-3">
            <div class="card__head"><h2 class="card__title"><?= icon('clipboard') ?> On the MikroTik</h2></div>
            <div class="card__body">
                <p class="small muted">Paste these into the router's terminal (Winbox → New Terminal, or SSH). Adjust the interface and address range to match your network.</p>

                <div class="divider-label">1 · Let WMS in</div>
                <pre class="code-chip" style="display:block;padding:.6rem;white-space:pre-wrap;line-height:1.6"><?= e(
"/ip service enable api
/ip service set api port=" . (int)$router['api_port'] . " address=" . $serverIp . "/32

/user group add name=wms policy=api,read,write,test,winbox
/user add name=" . ($router['api_username'] ?: 'wms-api') . " group=wms password=YOUR-STRONG-PASSWORD") ?></pre>
                <p class="tiny muted">The <code>address=</code> line restricts the API to this server. Leave it off only while testing.</p>

                <div class="divider-label">2 · Create the hotspot</div>
                <pre class="code-chip" style="display:block;padding:.6rem;white-space:pre-wrap;line-height:1.6"><?= e(
"/ip pool add name=hs-pool ranges=10.5.50.2-10.5.50.254
/ip address add address=10.5.50.1/24 interface=bridge-hotspot
/ip hotspot setup") ?></pre>
                <p class="tiny muted">The setup wizard asks for the interface, address pool, certificate (none), DNS and a first user. Answer them and it builds the server, profile and DHCP for you.</p>

                <div class="divider-label">3 · Point the login page at WMS</div>
                <pre class="code-chip" style="display:block;padding:.6rem;white-space:pre-wrap;line-height:1.6"><?= e(
"/ip hotspot walled-garden
add dst-host=" . parse_url(APP_URL, PHP_URL_HOST) . " comment=\"WMS portal\"
add dst-host=api.sonicpesa.com comment=\"Mobile money\"") ?></pre>
                <p class="tiny muted">
                    The walled garden lets an unauthenticated customer reach the portal and pay before they have access.
                    Add your mobile-money provider's host too, or payments will fail at the last step.
                </p>

                <div class="divider-label">4 · Send customers to the portal</div>
                <p class="small muted mb-1">In <code>/ip hotspot profile</code>, set the login page to redirect here:</p>
                <?= code_chip($portalUrl, true) ?>
                <p class="tiny muted mt-1">
                    Or edit <code>hotspot/login.html</code> on the router to redirect to that URL, passing
                    <code>?nasid=$(server-address)</code> so WMS knows which provider the customer is on.
                </p>
            </div>
        </section>

        <section class="card">
            <div class="card__head"><h2 class="card__title"><?= icon('info') ?> How access is granted</h2></div>
            <div class="card__body">
                <ul class="timeline">
                    <li class="is-success"><b>Customer enters a voucher</b><div class="muted">On the portal, through this router.</div></li>
                    <li><b>WMS validates it</b><div class="muted">Status, expiry, data left, device limit — all server side.</div></li>
                    <li><b>WMS creates a hotspot user</b><div class="muted">On this router, with the voucher's uptime and data limits.</div></li>
                    <li><b>The router lets them online</b><div class="muted">And enforces the speed and the caps itself.</div></li>
                    <li><b>WMS reads the counters back</b><div class="muted">Each poll updates usage and closes finished sessions.</div></li>
                </ul>
                <p class="tiny muted mb-0 mt-2">
                    WMS decides <b>who may</b> connect. The router decides <b>what actually happens on the wire</b>.
                    If this router is unreachable, WMS says so rather than pretending.
                </p>
            </div>
        </section>
    </div>
</div>

<?php require INCLUDES_PATH . '/footer.php'; ?>
