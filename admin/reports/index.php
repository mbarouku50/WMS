<?php
/**
 * WMS - Reports.
 *
 * One screen, five reports, any date range. Everything on screen is also
 * printable and exportable as CSV.
 */

$requiredPermission = 'manage_reports';
require_once __DIR__ . '/../../includes/auth-check.php';
require_once INCLUDES_PATH . '/components.php';

$service = new ReportService();
$type    = query('type', 'revenue');
if (!array_key_exists($type, ReportService::types())) {
    $type = 'revenue';
}

$preset = query('range', 'last30');
[$from, $to] = Usage::range($preset, query('from'), query('to'));

/* A platform administrator may narrow the whole report to one provider. */
$providerFilter = ProviderContext::isGlobalScope() ? ((int)query('provider_id') ?: null) : null;
$providerList   = ProviderContext::isGlobalScope() ? (new Provider())->listAll() : [];

$report = $service->forProvider($providerFilter)->build($type, $from, $to);

if (query('export') === 'csv') {
    AuditLog::record('report_export', 'report', null, 'Exported the ' . $type . ' report (' . $from . ' to ' . $to . ')');
    Response::csv(
        'wms-' . $type . '-' . $from . '-to-' . $to . '.csv',
        $report['columns'],
        $service->toCsv($report)
    );
}

$pageTitle    = 'Reports';
$scopeName    = $providerFilter
    ? (string)(Database::getInstance()->fetchColumn('SELECT business_name FROM providers WHERE id = ?', [$providerFilter]) ?: 'Provider')
    : ProviderContext::scopeLabel();
$pageSubtitle = $report['title'] . ' · ' . $scopeName . ' · ' . format_date($from, 'd M Y') . ' to ' . format_date($to, 'd M Y');
$activeNav    = 'reports';
$breadcrumbs  = [['label' => 'Reports'], ['label' => $report['title']]];
require INCLUDES_PATH . '/admin-header.php';
?>

<?= page_head('Reports', $pageSubtitle,
    '<a class="btn" href="' . e(query_string(['export' => 'csv'])) . '">' . icon('download', 'ico--sm') . ' Export CSV</a>'
    . '<button class="btn btn--primary" data-print>' . icon('print', 'ico--sm') . ' Print</button>'
) ?>

<div class="print-header">
    <b style="font-size:1.15rem"><?= e(setting('company_name', 'WMS')) ?></b>
    <div class="small"><?= e($report['title']) ?> · <?= e($scopeName) ?> · <?= e(format_date($from, 'd M Y')) ?> to <?= e(format_date($to, 'd M Y')) ?> · printed <?= e(date('d M Y H:i')) ?></div>
</div>

<div class="tabs mb-3 no-print">
    <?php foreach (ReportService::types() as $key => $label): ?>
        <a class="tab<?= $type === $key ? ' is-active' : '' ?>" href="<?= e(query_string(['type' => $key], ['page'])) ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
</div>

<section class="card mb-3">
    <form class="filter-bar no-print" method="get" action="">
        <input type="hidden" name="type" value="<?= e($type) ?>">
        <?php if (ProviderContext::isGlobalScope()): ?>
            <?= filter_select('provider_id', (string)($providerFilter ?? ''), array_column($providerList, 'business_name', 'id'), 'All providers') ?>
        <?php endif; ?>
        <?= filter_select('range', $preset, Usage::rangeLabels(), 'Period') ?>
        <?= filter_date('from', query('from'), 'From') ?>
        <?= filter_date('to', query('to'), 'To') ?>
        <div class="filter-bar__actions">
            <button class="btn" type="submit"><?= icon('filter', 'ico--sm') ?> Apply</button>
        </div>
    </form>

    <div class="card__body">
        <div class="stat-grid mb-3">
            <?php foreach ($report['summary'] as $tile): ?>
                <?= stat_card(['label' => $tile['label'], 'value' => $tile['value'], 'tone' => $tile['tone'] ?? 'neutral']) ?>
            <?php endforeach; ?>
        </div>

        <?php if (!empty($report['chart']['labels'])): ?>
            <?= chart($report['chart'], '260px') ?>
        <?php endif; ?>
    </div>
</section>

<section class="card mb-3">
    <div class="card__head">
        <h2 class="card__title"><?= icon('report') ?> <?= e($report['title']) ?></h2>
        <span class="small muted"><?= count($report['rows']) ?> rows</span>
    </div>
    <div class="table-wrap">
        <?php if (!$report['rows']): ?>
            <?= empty_state([
                'icon' => 'report', 'title' => 'Nothing in this period',
                'text' => 'There is no data between ' . format_date($from, 'd M Y') . ' and ' . format_date($to, 'd M Y') . '. Try a wider range.',
            ]) ?>
        <?php else: ?>
            <table class="table table--stack">
                <thead><tr><?php foreach ($report['columns'] as $column): ?><th><?= e($column) ?></th><?php endforeach; ?></tr></thead>
                <tbody>
                <?php foreach ($report['rows'] as $row): ?>
                    <tr>
                        <?php foreach (array_values((array)$row) as $i => $cell): ?>
                            <td data-label="<?= e($report['columns'][$i] ?? '') ?>"><?= e((string)$cell) ?></td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <?php if (!empty($report['note'])): ?>
        <div class="card__foot muted"><?= e($report['note']) ?></div>
    <?php endif; ?>
</section>

<?php foreach (($report['extra'] ?? []) as $title => $rows): ?>
    <section class="card mb-3">
        <div class="card__head"><h2 class="card__title"><?= icon('chart') ?> <?= e($title) ?></h2></div>
        <div class="table-wrap">
            <?php if (!$rows): ?>
                <?= empty_state(['icon' => 'chart', 'title' => 'No data', 'text' => 'Nothing to break down for this period.']) ?>
            <?php else: ?>
                <table class="table table--stack table--compact">
                    <thead><tr><?php foreach (($report['extra_columns'][$title] ?? []) as $column): ?><th><?= e($column) ?></th><?php endforeach; ?></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <?php foreach (array_values((array)$row) as $i => $cell): ?>
                                <td data-label="<?= e($report['extra_columns'][$title][$i] ?? '') ?>"><?= e((string)$cell) ?></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </section>
<?php endforeach; ?>

<?php require INCLUDES_PATH . '/footer.php'; ?>
