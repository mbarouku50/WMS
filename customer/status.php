<?php
/**
 * WMS - "What do I have left?"
 *
 * Shows the access this device or account currently holds: time remaining,
 * data remaining, the devices using it and the current session.
 */

require_once __DIR__ . '/../config/config.php';
require_once INCLUDES_PATH . '/components.php';

/* Establish the tenant before any query runs (see ProviderContext). */
ProviderContext::resolvePortalProvider();

$db          = Database::getInstance();
$customerId  = Auth::customerId();
$voucherCode = query('voucher');
$paymentId   = (int)query('payment');
$mac         = SessionService::syntheticMac(wms_client_ip());

$voucher = null;
$subscription = null;
$issuedVoucher = null;

try {
    /* A completed purchase hands over its voucher. */
    if ($paymentId) {
        // Only an order this browser placed (or the signed-in customer's own)
        // may reveal its voucher code here.
        $ownPayments = array_map('intval', (array)Session::get('wms_own_payments', []));
        $payment = (new Payment())->withRelationsUnscoped($paymentId);

        $maySee = $payment !== null && (
            in_array($paymentId, $ownPayments, true)
            || ($customerId !== null && (int)$payment['customer_id'] === $customerId)
        );

        if ($maySee && $payment['status'] === 'successful' && $payment['voucher_id']) {
            if (!empty($payment['provider_id'])) {
                ProviderContext::establishPortal((int)$payment['provider_id']);
            }
            $issuedVoucher = (new Voucher())->withPackageUnscoped((int)$payment['voucher_id']);
        }
    }

    if ($voucherCode) {
        $voucher = (new Voucher())->findByCode($voucherCode);
    }

    /* Otherwise fall back to whatever this device is currently using. */
    if (!$voucher) {
        $voucher = $db->fetchOne(
            "SELECT v.*, p.name AS package_name
               FROM sessions s
               JOIN vouchers v ON v.id = s.voucher_id
               JOIN packages p ON p.id = v.package_id
              WHERE s.mac_address = ? AND s.status = 'active'
              ORDER BY s.started_at DESC LIMIT 1",
            [$mac]
        );
    }

    if ($customerId) {
        $subscription = (new Customer())->currentSubscription($customerId);
    }

    $session = $db->fetchOne(
        "SELECT * FROM sessions WHERE mac_address = ? AND status = 'active' ORDER BY started_at DESC LIMIT 1",
        [$mac]
    );

    $devices = [];
    if ($voucher) {
        $devices = $db->fetchAll(
            "SELECT DISTINCT d.* FROM devices d
               JOIN sessions s ON s.device_id = d.id AND s.voucher_id = ? AND s.status = 'active'",
            [(int)$voucher['id']]
        );
    } elseif ($customerId) {
        $devices = (new Device())->activeForCustomer($customerId);
    }
} catch (Throwable $e) {
    Logger::error('Portal status failed: ' . $e->getMessage());
    $session = null;
    $devices = [];
}

/* Which record describes the current access? */
$access = $subscription ?: $voucher;
$isOnline = $access !== null && (
    ($subscription && $subscription['status'] === 'active') ||
    ($voucher && in_array($voucher['status'], ['active', 'activated'], true))
);

$endsAt     = $access['end_at'] ?? ($access['expires_at'] ?? null);
$dataLimit  = $access['data_limit_mb'] ?? null;
$dataUsed   = (float)($access['data_used_mb'] ?? 0);
$remaining  = $dataLimit === null ? null : max(0, (float)$dataLimit - $dataUsed);

$portalStatus = $isOnline
    ? ['tone' => 'online', 'icon' => 'wifi', 'title' => 'Connected', 'text' => ($access['package_name'] ?? 'Your package') . ' is active']
    : ['tone' => 'offline', 'icon' => 'alert', 'title' => 'Not connected', 'text' => 'Enter a voucher or buy a package to get online.'];

$pageTitle = 'My status';
$activeTab = 'status';
require __DIR__ . '/_header.php';
?>

<?php if ($issuedVoucher): ?>
    <div class="portal-card" style="border-color:var(--wms-primary)">
        <h1 class="portal-card__title">Payment received</h1>
        <p class="portal-card__sub">Here is your access code. Write it down - you can use it on another device later.</p>
        <div class="voucher-grid" style="grid-template-columns:1fr">
            <?= voucher_card($issuedVoucher) ?>
        </div>
        <form method="post" action="<?= e(url('customer/voucher.php')) ?>" class="mt-2">
            <?= CSRF::field() ?>
            <input type="hidden" name="code" value="<?= e($issuedVoucher['code']) ?>">
            <button class="btn btn--primary btn--lg btn--block"><?= icon('wifi', 'ico--sm') ?> Connect this device now</button>
        </form>
    </div>
<?php endif; ?>

<?php if ($access): ?>
    <div class="access-panel" data-status-refresh>
        <div class="access-panel__label">Time remaining</div>
        <div class="access-panel__value" data-countdown="<?= (int)seconds_until($endsAt) ?>">
            <?= e(format_duration(seconds_until($endsAt))) ?>
        </div>

        <?php if ($dataLimit !== null): ?>
            <div class="access-panel__label mt-2" style="margin-top:1rem">Data remaining</div>
            <div class="strong" style="font-size:1.1rem"><?= e(format_mb($remaining)) ?> of <?= e(format_mb((float)$dataLimit)) ?></div>
            <?= meter(percent($dataUsed, (float)$dataLimit, 1)) ?>
        <?php endif; ?>

        <div class="access-panel__row">
            <div class="access-panel__cell">
                <b><?= e($access['package_name'] ?? 'Package') ?></b>
                <span>Package</span>
            </div>
            <div class="access-panel__cell">
                <b><?= $dataLimit === null ? 'Unlimited' : e(format_mb((float)$dataLimit)) ?></b>
                <span>Allowance</span>
            </div>
            <div class="access-panel__cell">
                <b><?= (int)($access['device_limit'] ?? 1) ?></b>
                <span>Devices</span>
            </div>
        </div>
    </div>

    <?php if (!empty($voucher['code'])): ?>
        <div class="portal-card">
            <div class="eyebrow mb-1">Your voucher</div>
            <div class="flex items-center justify-between gap-1">
                <span class="code-chip" style="font-size:1rem"><?= e($voucher['code']) ?></span>
                <button class="btn btn--sm" data-copy="<?= e($voucher['code']) ?>"><?= icon('copy', 'ico--sm') ?> Copy</button>
            </div>
            <div class="small muted mt-2">
                Status <?= badge($voucher['status']) ?> · expires <?= e(format_date($voucher['expires_at'])) ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($session): ?>
        <div class="portal-card">
            <div class="eyebrow mb-1">This session</div>
            <?= key_value([
                'Connected'   => e(format_date($session['started_at'], 'd M H:i')),
                'Online for'  => '<span data-since="' . e($session['started_at']) . '">' . e(format_duration(time() - strtotime((string)$session['started_at']))) . '</span>',
                'Downloaded'  => e(format_bytes((float)$session['download_bytes'])),
                'Uploaded'    => e(format_bytes((float)$session['upload_bytes'])),
                'IP address'  => code_chip($session['ip_address']),
            ]) ?>
        </div>
    <?php endif; ?>

    <?php if ($devices): ?>
        <div class="portal-card">
            <div class="eyebrow mb-1">Connected devices (<?= count($devices) ?> of <?= (int)($access['device_limit'] ?? 1) ?>)</div>
            <?php foreach ($devices as $device): ?>
                <div class="device-row">
                    <span class="device-row__icon"><?= icon('device', 'ico--sm') ?></span>
                    <span class="device-row__text">
                        <b><?= e($device['name'] ?: 'Device') ?></b>
                        <span><?= e($device['mac_address']) ?></span>
                    </span>
                    <?= badge($device['status']) ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($portalCanBuy): ?>
        <a class="btn btn--block mb-1" href="<?= e(url('customer/packages.php')) ?>"><?= icon('package', 'ico--sm') ?> Buy more time</a>
    <?php endif; ?>

<?php else: ?>
    <div class="portal-card">
        <?= empty_state([
            'icon'  => 'wifi',
            'title' => 'No active package',
            'text'  => $portalCanBuy
                ? 'You are not connected right now. Enter a voucher code or buy a package to get online.'
                : 'You are not connected right now. Enter a voucher code to get online.',
            'action' => '<a class="btn btn--primary" href="' . e(url('customer/index.php')) . '">Enter a voucher</a>',
        ]) ?>
        <?php if ($portalCanBuy): ?>
            <a class="btn btn--block mt-2" href="<?= e(url('customer/packages.php')) ?>">Buy a package</a>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/_footer.php'; ?>
