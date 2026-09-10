<?php
/**
 * WMS - Customer profile.
 *
 * Everything about one customer on one screen: what they are on now, what
 * they have used, which devices they connect with, and what they paid.
 */

$requiredPermission = 'manage_customers';
require_once __DIR__ . '/../../includes/auth-check.php';
require_once INCLUDES_PATH . '/components.php';

$customers = new Customer();
$id        = (int)query('id');
$customer  = $customers->find($id);

if (!$customer) {
    Response::redirect('admin/customers/index.php', 'error', 'That customer could not be found.');
}

$subscription = $customers->currentSubscription($id);
$voucher      = $customers->currentVoucher($id);
$devices      = $customers->devices($id);
$sessions     = $customers->sessions($id, 8);
$payments     = $customers->payments($id, 8);
$vouchers     = $customers->vouchers($id, 8);
$totals       = $customers->totals($id);
$usageSeries  = $customers->usageSeries($id, 14);

$pageTitle   = $customer['full_name'];
$activeNav   = 'customers';
$breadcrumbs = [['label' => 'Customers', 'url' => 'admin/customers/index.php'], ['label' => $customer['full_name']]];
require INCLUDES_PATH . '/admin-header.php';

/* Work out what the customer currently has, from either source. */
$accessEnds   = $subscription['end_at'] ?? ($voucher['expires_at'] ?? null);
$dataLimitMb  = $subscription['data_limit_mb'] ?? ($voucher['data_limit_mb'] ?? null);
$dataUsedMb   = (float)($subscription['data_used_mb'] ?? ($voucher['data_used_mb'] ?? 0));
$packageName  = $subscription['package_name'] ?? ($voucher['package_name'] ?? null);
?>

<?= page_head($customer['full_name'],
    $customer['customer_code'] . ' · ' . label($customer['customer_type']) . ' · joined ' . format_date($customer['created_at'], 'd M Y'),
    '<a class="btn" href="' . e(url('admin/customers/edit.php?id=' . $id)) . '">' . icon('edit', 'ico--sm') . ' Edit</a>'
    . '<a class="btn" href="' . e(url('admin/network/sessions.php?customer_id=' . $id)) . '">' . icon('activity', 'ico--sm') . ' Sessions</a>'
    . '<a class="btn btn--primary" href="' . e(url('admin/payments/index.php?customer_id=' . $id)) . '">' . icon('card', 'ico--sm') . ' Payments</a>'
) ?>

<div class="grid grid--1-2 mb-3">
    <!-- ------------------------------------------------ identity card -->
    <section class="card">
        <div class="card__head">
            <h2 class="card__title"><?= icon('user') ?> Account</h2>
            <?= badge($customer['status']) ?>
        </div>
        <div class="card__body">
            <div class="flex items-center gap-2 mb-3">
                <?= avatar($customer['full_name'], 'avatar--ink') ?>
                <div>
                    <div class="strong"><?= e($customer['full_name']) ?></div>
                    <div class="small muted"><?= e($customer['phone']) ?></div>
                </div>
            </div>

            <?= key_value([
                'Customer code' => code_chip($customer['customer_code']),
                'Phone'         => e($customer['phone']),
                'Email'         => $customer['email'] ? e($customer['email']) : '',
                'Username'      => $customer['username'] ? code_chip($customer['username']) : '',
                'Type'          => e(label($customer['customer_type'])),
                'Address'       => e((string)$customer['address']),
                'Registered'    => e(format_date($customer['created_at'])),
            ]) ?>

            <?php if (!empty($customer['notes'])): ?>
                <div class="divider-label">Notes</div>
                <p class="small muted mb-0"><?= nl2br(e($customer['notes'])) ?></p>
            <?php endif; ?>
        </div>
        <div class="card__foot flex gap-1 flex-wrap">
            <?php if ($customer['status'] === 'active'): ?>
                <form method="post" action="<?= e(url('admin/customers/index.php')) ?>" data-confirm="Suspend this customer? Their access will be blocked.">
                    <?= CSRF::field() ?>
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <input type="hidden" name="action" value="suspend">
                    <button class="btn btn--sm btn--danger"><?= icon('block', 'ico--sm') ?> Suspend</button>
                </form>
            <?php else: ?>
                <form method="post" action="<?= e(url('admin/customers/index.php')) ?>">
                    <?= CSRF::field() ?>
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <input type="hidden" name="action" value="activate">
                    <button class="btn btn--sm btn--primary"><?= icon('check', 'ico--sm') ?> Activate</button>
                </form>
            <?php endif; ?>
        </div>
    </section>

    <!-- --------------------------------------------- current access -->
    <div>
        <section class="card mb-2">
            <div class="card__head">
                <h2 class="card__title"><?= icon('wifi') ?> Current access</h2>
                <?php if ($packageName): ?><?= badge($subscription['status'] ?? $voucher['status'] ?? 'active') ?><?php endif; ?>
            </div>
            <div class="card__body">
                <?php if (!$packageName): ?>
                    <?= empty_state([
                        'icon'  => 'wifi',
                        'title' => 'No active package',
                        'text'  => 'This customer has nothing running right now. Sell them a package or hand them a voucher.',
                        'action' => '<a class="btn btn--primary btn--sm" href="' . e(url('admin/vouchers/generate.php')) . '">Generate a voucher</a>',
                    ]) ?>
                <?php else: ?>
                    <div class="grid grid--3">
                        <div>
                            <div class="eyebrow">Package</div>
                            <div class="strong"><?= e($packageName) ?></div>
                            <?php if ($voucher): ?>
                                <div class="tiny muted mt-1">Voucher <?= e($voucher['code']) ?></div>
                            <?php endif; ?>
                        </div>
                        <div>
                            <div class="eyebrow">Time remaining</div>
                            <div class="strong" data-countdown="<?= (int)seconds_until($accessEnds) ?>">
                                <?= e(format_duration(seconds_until($accessEnds))) ?>
                            </div>
                            <div class="tiny muted mt-1">Ends <?= e(format_date($accessEnds)) ?></div>
                        </div>
                        <div>
                            <div class="eyebrow">Data remaining</div>
                            <div class="strong">
                                <?= $dataLimitMb === null ? 'Unlimited' : e(format_mb(max(0, (float)$dataLimitMb - $dataUsedMb))) ?>
                            </div>
                            <?php if ($dataLimitMb !== null): ?>
                                <div class="mt-1"><?= meter(percent($dataUsedMb, (float)$dataLimitMb, 1)) ?></div>
                                <div class="tiny muted mt-1"><?= e(format_mb($dataUsedMb)) ?> of <?= e(format_mb((float)$dataLimitMb)) ?> used</div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <div class="stat-grid">
            <?= stat_card(['label' => 'Lifetime spend', 'value' => money((float)$totals['spend']), 'icon' => 'money', 'tone' => 'success']) ?>
            <?= stat_card(['label' => 'Total data', 'value' => format_bytes((float)$totals['download'] + (float)$totals['upload']), 'icon' => 'signal', 'tone' => 'info',
                'meta' => '↓ ' . format_bytes((float)$totals['download']) . ' ↑ ' . format_bytes((float)$totals['upload'])]) ?>
            <?= stat_card(['label' => 'Time online', 'value' => format_duration((int)$totals['seconds']), 'icon' => 'clock', 'tone' => 'neutral',
                'meta' => number_format((int)$totals['sessions']) . ' sessions']) ?>
            <?= stat_card(['label' => 'Devices', 'value' => number_format(count($devices)), 'icon' => 'device', 'tone' => 'primary']) ?>
        </div>
    </div>
</div>

<!-- -------------------------------------------------------- usage -->
<section class="card mb-3">
    <div class="card__head">
        <h2 class="card__title"><?= icon('chart') ?> Usage, last 14 days</h2>
        <a class="btn btn--sm" href="<?= e(url('admin/usage/index.php?customer_id=' . $id)) ?>">Usage detail</a>
    </div>
    <div class="card__body">
        <?= chart([
            'type'   => 'line',
            'format' => 'bytes',
            'labels' => array_map(static fn($r) => date('d M', strtotime((string)$r['record_date'])), $usageSeries),
            'series' => [
                ['name' => 'Download', 'data' => array_map(static fn($r) => (float)$r['download'], $usageSeries)],
                ['name' => 'Upload',   'data' => array_map(static fn($r) => (float)$r['upload'], $usageSeries)],
            ],
        ], '200px') ?>
    </div>
</section>

<div class="grid grid--2">
    <!-- ---------------------------------------------------- devices -->
    <section class="card">
        <div class="card__head">
            <h2 class="card__title"><?= icon('device') ?> Devices</h2>
            <span class="small muted"><?= count($devices) ?> registered</span>
        </div>
        <div class="table-wrap">
            <?php if (!$devices): ?>
                <?= empty_state(['icon' => 'device', 'title' => 'No devices yet', 'text' => 'Devices are registered automatically the first time the customer connects.']) ?>
            <?php else: ?>
                <table class="table table--stack table--compact">
                    <thead><tr><th>Device</th><th>MAC</th><th>Last seen</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php foreach ($devices as $device): ?>
                        <tr>
                            <td data-label="Device">
                                <b><?= e($device['name'] ?: 'Unnamed device') ?></b>
                                <div class="tiny muted"><?= e(label($device['device_type'])) ?><?= $device['router_name'] ? ' · ' . e($device['router_name']) : '' ?></div>
                            </td>
                            <td data-label="MAC"><?= code_chip($device['mac_address']) ?></td>
                            <td data-label="Last seen" class="nowrap"><?= e(time_ago($device['last_seen_at'])) ?></td>
                            <td data-label="Status"><?= badge($device['status']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </section>

    <!-- --------------------------------------------------- sessions -->
    <section class="card">
        <div class="card__head">
            <h2 class="card__title"><?= icon('activity') ?> Recent sessions</h2>
            <a class="btn btn--sm btn--ghost" href="<?= e(url('admin/network/sessions.php?customer_id=' . $id)) ?>">All</a>
        </div>
        <div class="table-wrap">
            <?php if (!$sessions): ?>
                <?= empty_state(['icon' => 'activity', 'title' => 'No sessions recorded', 'text' => 'Connection history appears here once the customer goes online.']) ?>
            <?php else: ?>
                <table class="table table--stack table--compact">
                    <thead><tr><th>Started</th><th>Device</th><th class="text-right">Data</th><th>Duration</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php foreach ($sessions as $session): ?>
                        <tr>
                            <td data-label="Started" class="nowrap"><?= e(format_date($session['started_at'], 'd M H:i')) ?></td>
                            <td data-label="Device"><?= e($session['device_name'] ?? '—') ?></td>
                            <td data-label="Data" class="text-right nowrap"><?= e(format_bytes((float)$session['download_bytes'] + (float)$session['upload_bytes'])) ?></td>
                            <td data-label="Duration" class="nowrap"><?= e(format_duration((int)$session['duration_seconds'])) ?></td>
                            <td data-label="Status"><?= badge($session['status']) ?> <?= source_badge($session['source']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </section>

    <!-- --------------------------------------------------- payments -->
    <section class="card">
        <div class="card__head">
            <h2 class="card__title"><?= icon('card') ?> Payment history</h2>
            <a class="btn btn--sm btn--ghost" href="<?= e(url('admin/payments/index.php?customer_id=' . $id)) ?>">All</a>
        </div>
        <div class="table-wrap">
            <?php if (!$payments): ?>
                <?= empty_state(['icon' => 'card', 'title' => 'No payments yet', 'text' => 'Purchases made through the portal or recorded at the counter appear here.']) ?>
            <?php else: ?>
                <table class="table table--stack table--compact">
                    <thead><tr><th>Reference</th><th>Package</th><th class="text-right">Amount</th><th>Status</th><th>Date</th></tr></thead>
                    <tbody>
                    <?php foreach ($payments as $payment): ?>
                        <tr>
                            <td data-label="Reference"><?= code_chip($payment['transaction_ref']) ?></td>
                            <td data-label="Package"><?= e($payment['package_name'] ?? '—') ?></td>
                            <td data-label="Amount" class="text-right nowrap"><?= e(money((float)$payment['amount'])) ?></td>
                            <td data-label="Status"><?= badge($payment['status']) ?></td>
                            <td data-label="Date" class="nowrap"><?= e(format_date($payment['created_at'], 'd M Y')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </section>

    <!-- --------------------------------------------------- vouchers -->
    <section class="card">
        <div class="card__head">
            <h2 class="card__title"><?= icon('ticket') ?> Vouchers used</h2>
        </div>
        <div class="table-wrap">
            <?php if (!$vouchers): ?>
                <?= empty_state(['icon' => 'ticket', 'title' => 'No vouchers linked', 'text' => 'Vouchers this customer activates will be listed here.']) ?>
            <?php else: ?>
                <table class="table table--stack table--compact">
                    <thead><tr><th>Code</th><th>Package</th><th>Activated</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php foreach ($vouchers as $row): ?>
                        <tr>
                            <td data-label="Code"><?= code_chip($row['code'], true) ?></td>
                            <td data-label="Package"><?= e($row['package_name']) ?></td>
                            <td data-label="Activated" class="nowrap"><?= e(format_date($row['activated_at'], 'd M Y H:i')) ?></td>
                            <td data-label="Status"><?= badge($row['status']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </section>
</div>

<?php require INCLUDES_PATH . '/footer.php'; ?>
