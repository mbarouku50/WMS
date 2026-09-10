<?php
/**
 * POST api/network/poll.php
 * Polls every router and refreshes their cached status.
 * Safe to call from cron: php api/network/poll.php is not supported, use a
 * scheduled HTTP request from an authenticated session or a cron token.
 */

require_once dirname(__DIR__) . '/_bootstrap.php';

api_method('POST');
api_staff('manage_routers');
api_csrf();

api_run(static function (): void {
    $results = (new NetworkService())->pollAll();
    $online  = count(array_filter($results, static fn($r) => ($r['status'] ?? '') === 'online'));

    Response::success(
        array_map(static fn($r) => ['status' => $r['status'] ?? 'unknown', 'source' => $r['source'] ?? 'demo'], $results),
        count($results) . ' router(s) polled, ' . $online . ' online.'
    );
});
