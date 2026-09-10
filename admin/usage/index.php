<?php
/**
 * WMS - Usage monitoring.
 */

$requiredPermission = 'manage_reports';
require_once __DIR__ . '/../../includes/auth-check.php';
require_once INCLUDES_PATH . '/components.php';

$usage = new Usage();

$filters = [
    'range'       => query('range', 'last7'),
    'date_from'   => query('from'),
    'date_to'     => query('to'),
    'customer_id' => query('customer_id'),
    'router_id'   => query('router_id'),
    'source'      => query('source'),
];

[$from, $to] = Usage::range($filters['range'], $filters['date_from'], $filters['date_to']);

if (query('export') === 'csv') {
    $rows = array_map(static fn($r) => [
        $r['record_date'], $r['customer_name'] ?? '', $r['customer_code'] ?? '', $r['voucher_code'] ?? '',
        format_bytes((float)$r['download_bytes']), format_bytes((float)$r['upload_bytes']),
        format_bytes((float)$r['total_bytes']), format_duration((int)$r['duration_seconds']), $r['source'],
    ], $usage->search(array_merge($filters, ['range' => $filters['range']]), 1, 5000)['rows']);
    Response::csv('wms-usage-' . date('Ymd-His') . '.csv',
        ['Date', 'Customer', 'Code', 'Voucher', 'Download', 'Upload', 'Total', 'Duration', 'Source'], $rows);
}

$totals   = $usage->totals($from, $to, $filters);
$daily    = $usage->dailySeries($from, $to, $filters);
$topUsers = $usage->topCustomers($from, $to, 8);
$topVouchers = $usage->topVouchers($from, $to, 8);
$page     = $usage->search($filters, current_page(), (int)setting('records_per_page', 25));
$routers  = (new Router())->listAll();

$pageTitle    = 'Usage';
$pageSubtitle = 'Data and time consumed across the network';
$activeNav    = 'usage';
$breadcrumbs  = [['label' => 'Usage']];
require INCLUDES_PATH . '/admin-header.php';
?>

<?= page_head($pageTitle, $pageSubtitle . ' · ' . format_date($from, 'd M Y') . ' to ' . format_date($to, 'd M Y'),
    '<a class="btn" href="' . e(query_string(['export' => 'csv'])) . '">' . icon('download', 'ico--sm') . ' Export CSV</a>'
    . '<a class="btn" href="' . e(url('admin/reports/index.php?type=usage')) . '">' . icon('report', 'ico--sm') . ' Usage report</a>'
) ?>

<?= demo_banner('session') ?>

<section class="card mb-3">
    <form class="filter-bar" method="get" action="">
        <?= filter_select('range', $filters['range'], Usage::rangeLabels(), 'Period') ?>
        <?= filter_date('from', $filters['date_from'], 'From') ?>
        <?= filter_date('to', $filters['date_to'], 'To') ?>
        <?= filter_select('router_id', $filters['router_id'], array_column($routers, 'name', 'id'), 'Any router') ?>
        <?= filter_select('source', $filters['source'], ['live' => 'Live', 'demo' => 'Demo'], 'Any source') ?>
        <div class="filter-bar__actions">
            <button class="btn" type="submit"><?= icon('filter', 'ico--sm') ?> Apply</button>
            <a class="btn btn--ghost" href="<?= e(url('admin/usage/index.php')) ?>">Reset</a>
        </div>
    </form>
    <div class="card__body">
        <div class="stat-grid mb-3">
            <?= stat_card(['label' => 'Total data', 'value' => format_bytes($totals['total']), 'icon' => 'database', 'tone' => 'primary']) ?>
            <?= stat_card(['label' => 'Download', 'value' => format_bytes($totals['download']), 'icon' => 'download', 'tone' => 'info']) ?>
            <?= stat_card(['label' => 'Upload', 'value' => format_bytes($totals['upload']), 'icon' => 'upload', 'tone' => 'neutral']) ?>
            <?= stat_card(['label' => 'Time online', 'value' => format_duration($totals['seconds']), 'icon' => 'clock', 'tone' => 'success',
                'meta' => number_format($totals['customers']) . ' customers · ' . number_format($totals['records']) . ' records']) ?>
        </div>

        <?= chart([
            'type'   => 'line',
            'format' => 'bytes',
            'labels' => array_map(static fn($r) => date('d M', strtotime((string)$r['record_date'])), $daily),
            'series' => [
                ['name' => 'Download', 'data' => array_map(static fn($r) => (float)$r['download'], $daily)],
                ['name' => 'Upload',   'data' => array_map(static fn($r) => (float)$r['upload'], $daily)],
            ],
        ], '240px') ?>
    </div>
</section>

<div class="grid grid--2 mb-3">
    <section class="card">
        <div class="card__head"><h2 class="card__title"><?= icon('users') ?> Heaviest customers</h2></div>
        <div class="table-wrap">
            <?php if (!$topUsers): ?>
                <?= empty_state(['icon' => 'users', 'title' => 'No usage in this period', 'text' => 'Pick a wider date range, or wait for customers to come online.']) ?>
            <?php else: ?>
                <?php $maxUser = max(array_map(static fn($u) => (float)$u['total'], $topUsers)) ?: 1; ?>
                <table class="table table--compact table--stack">
                    <thead><tr><th>Customer</th><th style="min-width:120px">Share</th><th class="text-right">Data</th><th class="text-right">Time</th></tr></thead>
                    <tbody>
                    <?php foreach ($topUsers as $row): ?>
                        <tr>
                            <td data-label="Customer">
                                <a href="<?= e(url('admin/customers/view.php?id=' . (int)$row['customer_id'])) ?>"><?= e($row['full_name']) ?></a>
                                <div class="tiny muted"><?= e($row['customer_code']) ?></div>
                            </td>
                            <td data-label="Share"><?= meter(percent((float)$row['total'], $maxUser, 1), 'success') ?></td>
                            <td data-label="Data" class="text-right nowrap"><?= e(format_bytes((float)$row['total'])) ?></td>
                            <td data-label="Time" class="text-right nowrap"><?= e(format_duration((int)$row['seconds'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </section>

    <section class="card">
        <div class="card__head"><h2 class="card__title"><?= icon('ticket') ?> Heaviest vouchers</h2></div>
        <div class="table-wrap">
            <?php if (!$topVouchers): ?>
                <?= empty_state(['icon' => 'ticket', 'title' => 'No voucher usage yet', 'text' => 'Usage attributed to vouchers appears here.']) ?>
            <?php else: ?>
                <table class="table table--compact table--stack">
                    <thead><tr><th>Voucher</th><th>Package</th><th class="text-right">Data</th><th class="text-right">Time</th></tr></thead>
                    <tbody>
                    <?php foreach ($topVouchers as $row): ?>
                        <tr>
                            <td data-label="Voucher"><?= code_chip($row['code']) ?></td>
                            <td data-label="Package"><?= e($row['package_name']) ?></td>
                            <td data-label="Data" class="text-right nowrap"><?= e(format_bytes((float)$row['total'])) ?></td>
                            <td data-label="Time" class="text-right nowrap"><?= e(format_duration((int)$row['seconds'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </section>
</div>

<section class="card">
    <div class="card__head">
        <h2 class="card__title"><?= icon('history') ?> Usage records</h2>
        <span class="small muted"><?= number_format($page['total']) ?> records</span>
    </div>
    <div class="table-wrap">
        <?php if (!$page['rows']): ?>
            <?= empty_state(['icon' => 'signal', 'title' => 'No usage records', 'text' => 'A record is written whenever a session closes or a router reports accounting data.']) ?>
        <?php else: ?>
            <table class="table table--stack">
                <thead>
                    <tr><th>Date</th><th>Customer</th><th>Voucher</th><th class="text-right">Download</th><th class="text-right">Upload</th><th class="text-right">Total</th><th class="text-right">Duration</th><th>Source</th></tr>
                </thead>
                <tbody>
                <?php foreach ($page['rows'] as $row): ?>
                    <tr>
                        <td data-label="Date" class="nowrap"><?= e(format_date($row['record_date'], 'd M Y')) ?></td>
                        <td data-label="Customer">
                            <?php if ($row['customer_id']): ?>
                                <a href="<?= e(url('admin/customers/view.php?id=' . (int)$row['customer_id'])) ?>"><?= e($row['customer_name']) ?></a>
                            <?php else: ?>
                                <span class="faint">Guest</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Voucher"><?= $row['voucher_code'] ? code_chip($row['voucher_code']) : '<span class="faint">—</span>' ?></td>
                        <td data-label="Download" class="text-right nowrap"><?= e(format_bytes((float)$row['download_bytes'])) ?></td>
                        <td data-label="Upload" class="text-right nowrap"><?= e(format_bytes((float)$row['upload_bytes'])) ?></td>
                        <td data-label="Total" class="text-right nowrap strong"><?= e(format_bytes((float)$row['total_bytes'])) ?></td>
                        <td data-label="Duration" class="text-right nowrap"><?= e(format_duration((int)$row['duration_seconds'])) ?></td>
                        <td data-label="Source"><?= source_badge($row['source']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <?= pagination($page, 'records') ?>
</section>

<?php require INCLUDES_PATH . '/footer.php'; ?>
