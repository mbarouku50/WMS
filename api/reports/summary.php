<?php
/**
 * GET api/reports/summary.php?type=revenue&from=&to=
 */

require_once dirname(__DIR__) . '/_bootstrap.php';

api_method('GET');
api_staff('manage_reports');

api_run(static function (): void {
    $type = api_string('type', 'revenue');
    if (!array_key_exists($type, ReportService::types())) {
        Response::error('Unknown report type. Choose one of: ' . implode(', ', array_keys(ReportService::types())), 422);
    }

    [$from, $to] = Usage::range(api_string('range', 'last30'), api_string('from'), api_string('to'));
    $report = (new ReportService())->build($type, $from, $to);

    Response::success([
        'title'   => $report['title'],
        'from'    => $from,
        'to'      => $to,
        'summary' => $report['summary'],
        'rows'    => $report['rows'],
        'columns' => $report['columns'],
        'chart'   => $report['chart'],
    ], $report['title'] . ' generated.');
});
