<?php
/**
 * WMS - Scheduled housekeeping.
 *
 * Run this every few minutes so expiry, router polling and payment
 * reconciliation do not depend on someone having a browser tab open.
 *
 *   Command line (preferred):
 *       * /5 * * * * /usr/bin/php /path/to/WMS/cron.php
 *
 *   Shared hosting without CLI cron - call it over HTTP with the token
 *   from Settings, e.g.
 *       https://example.com/WMS/cron.php?token=YOUR_CRON_TOKEN
 *
 * The token is generated on first run and shown once in the output; it is
 * stored in the settings table thereafter.
 */

require_once __DIR__ . '/config/config.php';

$isCli = PHP_SAPI === 'cli';

if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');

    $token = (string)setting('cron_token', '');
    if ($token === '') {
        $token = bin2hex(random_bytes(16));
        Setting::set('cron_token', $token, 'general', 'secret');
        echo "A cron token has been generated. Add it to your scheduler URL and keep it private:\n";
        echo $token . "\n";
        exit;
    }

    $supplied = (string)($_GET['token'] ?? '');
    if ($supplied === '' || !hash_equals($token, $supplied)) {
        http_response_code(403);
        Logger::warning('Cron called with a bad token', ['ip' => wms_client_ip()]);
        echo "Forbidden.\n";
        exit;
    }
}

/*
 * Everything above has established that this run is legitimate: either it is
 * the command line, or it arrived over HTTP with the correct cron token.
 * Only now does the process take platform scope, so the sweep can reach every
 * provider's routers even though nobody is signed in.
 */
ProviderContext::establishSystem(true);

$started = microtime(true);
$report  = [];

/* ---------------------------------------------------- voucher lifecycle */
try {
    $housekeeping = (new VoucherService())->runHousekeeping();
    $report[] = sprintf(
        'Vouchers: %d expired, %d exhausted, %d sessions closed.',
        $housekeeping['expired'],
        $housekeeping['exhausted'],
        $housekeeping['sessions_closed']
    );
} catch (Throwable $e) {
    Logger::error('Cron voucher housekeeping failed: ' . $e->getMessage());
    $report[] = 'Vouchers: failed (see the log).';
}

/* ------------------------------------------------------- router polling */
try {
    $network = new NetworkService();
    if ($network->isDemoMode()) {
        $report[] = 'Routers: skipped - demo mode, nothing is contacted.';
    } else {
        /*
         * The scheduled sweep. Every live router is polled once, the result is
         * stored, and the pages then read the WMS database instead of opening
         * their own connection to MikroTik on every request.
         *
         * Each router is isolated: an unreachable one is recorded as a failed
         * attempt and the sweep moves on, so neither the other routers nor the
         * other providers are affected.
         */
        $summary = $network->runScheduledSync();
        $report[] = sprintf(
            'Routers: %d live, %d online, %d degraded, %d not answering, %d skipped.',
            $summary['routers'], $summary['online'], $summary['degraded'], $summary['failed'], $summary['skipped']
        );

        // Session sync only where the router actually answered this run.
        foreach (Database::getInstance()->fetchAll(
            "SELECT * FROM routers
              WHERE mode = 'live' AND status IN ('online','degraded') AND deleted_at IS NULL"
        ) as $router) {
            try {
                $sync = (new SessionService())->syncFromRouter($router);
                $report[] = ' - ' . $router['name'] . ': ' . $sync['message'];
            } catch (Throwable $e) {
                // One router's session sync failing must not stop the others.
                Logger::error('Session sync failed for router: ' . $e->getMessage(), ['router' => $router['name']]);
                $report[] = ' - ' . $router['name'] . ': session sync failed (see the log).';
            }
        }
    }
} catch (Throwable $e) {
    Logger::error('Cron router poll failed: ' . $e->getMessage());
    $report[] = 'Routers: failed (see the log).';
}

/* -------------------------------------------------- payment reconciling */
try {
    $reconciled = (new PaymentService())->reconcilePending(30);
    $report[] = sprintf('Payments: %d checked, %d settled.', $reconciled['checked'], $reconciled['settled']);
} catch (Throwable $e) {
    Logger::error('Cron payment reconciliation failed: ' . $e->getMessage());
    $report[] = 'Payments: failed (see the log).';
}

/* --------------------------------------------------- platform billing */
try {
    $billing = (new BillingService())->runBilling();
    $report[] = sprintf(
        'Billing: %d invoice(s) raised, %d settled from wallets, %d left unpaid.',
        $billing['raised'],
        $billing['collected'],
        $billing['unpaid']
    );
} catch (Throwable $e) {
    Logger::error('Cron billing run failed: ' . $e->getMessage());
    $report[] = 'Billing: failed (see the log).';
}

/* ------------------------------------------------------- payouts ------ */
try {
    $payouts = (new WalletService())->reconcileWithdrawals(25);
    $report[] = sprintf('Payouts: %d checked, %d settled.', $payouts['checked'], $payouts['settled']);
} catch (Throwable $e) {
    Logger::error('Cron payout reconciliation failed: ' . $e->getMessage());
    $report[] = 'Payouts: failed (see the log).';
}

/* -------------------------------------------------------------- alerts */
try {
    $threshold = (float)setting('alert_high_usage_gb', 8);
    foreach ((new Usage())->heavyUsersToday($threshold) as $heavy) {
        Alert::raise(
            'high_bandwidth',
            'warning',
            'High usage by ' . $heavy['full_name'],
            $heavy['full_name'] . ' has used ' . format_bytes((float)$heavy['total']) . ' today, above the ' . $threshold . ' GB threshold.',
            'customer',
            (int)$heavy['customer_id']
        );
    }

    foreach ((new Subscription())->endingSoon(24, 50) as $ending) {
        Alert::raise(
            'package_expiring',
            'info',
            'Package ending soon for ' . ($ending['customer_name'] ?? 'a customer'),
            ($ending['package_name'] ?? 'A package') . ' ends ' . format_date($ending['end_at']) . '.',
            'subscription',
            (int)$ending['id']
        );
    }
    $report[] = 'Alerts: usage and expiry checks complete.';
} catch (Throwable $e) {
    Logger::error('Cron alert checks failed: ' . $e->getMessage());
    $report[] = 'Alerts: failed (see the log).';
}

/* -------------------------------------------------------- tidy up rows */
try {
    Auth::pruneAttempts();
    Database::getInstance()->execute('DELETE FROM audit_logs WHERE created_at < (NOW() - INTERVAL 365 DAY)');
    $report[] = 'Maintenance: old login attempts and audit rows pruned.';
} catch (Throwable $e) {
    Logger::warning('Cron maintenance failed: ' . $e->getMessage());
}

$elapsed = round((microtime(true) - $started) * 1000);
$report[] = 'Finished in ' . $elapsed . ' ms.';

AuditLog::record('cron_run', 'system', null, 'Scheduled housekeeping completed in ' . $elapsed . ' ms', 'system', 'Scheduler');

echo implode(PHP_EOL, $report) . PHP_EOL;
