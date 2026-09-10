<?php
/**
 * WMS - Platform fee enforcement.
 *
 * BillingService raises the invoices. This is what happens when one is not
 * paid, and it answers one question for the whole application:
 *
 *      what is this provider still allowed to do?
 *
 * There are four answers, and a provider moves through them in order:
 *
 *      CLEAR    nothing owed, or still inside the agreed grace period
 *      WARNING  an invoice is open. They are told, on every screen, with
 *               the amount and the date it must be settled by.
 *      LOCKED   the deadline passed. They can still sign in - a provider
 *               who cannot sign in can never pay - but the only screen
 *               they can reach is the one that takes the payment.
 *      STOPPED  still unpaid. Selling stops too: their customers cannot
 *               buy a package or activate a voucher on that network.
 *
 * Two dates drive all of it, both read from the oldest unpaid invoice:
 *
 *      lock_on  = invoice due date + platform_fee_lock_after_days
 *      stop_on  = lock_on         + platform_fee_stop_service_after_days
 *
 * The platform owner can suspend either behaviour in Settings, or hand one
 * provider an extension with grantGrace(), which outranks both dates.
 *
 * Why the lock is not on the login form
 * -------------------------------------
 * Refusing the password outright would be simpler and completely useless:
 * the provider would have no way to pay, and the platform would have no way
 * to be paid. So the credentials still work, and the account opens onto a
 * screen with the invoice on it and nothing else reachable.
 */
class BillingGuard
{
    /** Where a locked provider is sent, and the only page they may open. */
    public const PAY_PAGE = 'admin/billing/pay.php';

    private Database $db;
    private BillingService $billing;
    private PlatformInvoice $invoices;

    /** Per-request cache: this is consulted on every page load. */
    private static array $stateCache = [];

    /**
     * Providers currently mid-invoice-run.
     *
     * Raising an invoice can settle it from the wallet, which recomputes the
     * provider's standing, which comes back through here. Without this the
     * two would call each other.
     */
    private static array $invoicing = [];

    public function __construct()
    {
        $this->db       = Database::getInstance();
        $this->billing  = new BillingService();
        $this->invoices = new PlatformInvoice();
    }

    /* ================================================================= */
    /* The state of one provider                                         */
    /* ================================================================= */

    /**
     * Everything the application needs to know about a provider's standing.
     *
     * @return array{
     *   stage:string, locked:bool, stopped:bool, warn:bool, owed:float,
     *   invoices:array, oldest:?array, due_on:?string, lock_on:?string,
     *   stop_on:?string, days_to_lock:int, grace_until:?string,
     *   headline:string, detail:string
     * }
     */
    public function state(int $providerId, bool $useCache = true): array
    {
        if ($useCache && isset(self::$stateCache[$providerId])) {
            return self::$stateCache[$providerId];
        }

        $provider = $this->db->fetchOne('SELECT * FROM providers WHERE id = ? LIMIT 1', [$providerId]);
        if (!$provider) {
            return self::$stateCache[$providerId] = self::clearState();
        }

        // An invoice that has fallen due but was never raised - because cron
        // is not set up, or has not run yet - is raised here, so enforcement
        // never depends on a scheduler somebody forgot to configure.
        $this->ensureInvoiced($provider);

        if ($provider['billing_status'] === 'exempt') {
            return self::$stateCache[$providerId] = self::clearState('This account is exempt from the platform fee.');
        }

        // Re-read: ensureInvoiced() may have just changed the row.
        $provider = $this->db->fetchOne('SELECT * FROM providers WHERE id = ? LIMIT 1', [$providerId]) ?: $provider;

        $unpaid = $this->invoices->unpaidFor($providerId);
        $owed   = 0.0;
        foreach ($unpaid as $invoice) {
            $owed += (float)$invoice['amount'];
        }

        if (!$unpaid) {
            $this->applyState($providerId, false, false);
            $this->clearNotices($providerId);
            $next = $provider['billing_next_due_on'] ?? null;
            return self::$stateCache[$providerId] = self::clearState(
                $next ? 'Paid up. The next platform fee falls due ' . format_date($next, 'd M Y') . '.' : 'Nothing owed.'
            );
        }

        // The oldest unpaid invoice sets the clock: clearing a newer one
        // while an older one stands must not buy any more time.
        $oldest    = $unpaid[0];
        $dueOn     = (string)$oldest['due_on'];
        $lockDays  = max(0, (int)setting('platform_fee_lock_after_days', 0));
        $stopDays  = max(0, (int)setting('platform_fee_stop_service_after_days', 0));
        $lockOn    = date('Y-m-d', strtotime('+' . $lockDays . ' days', strtotime($dueOn)));
        $stopOn    = date('Y-m-d', strtotime('+' . $stopDays . ' days', strtotime($lockOn)));
        $today     = date('Y-m-d');

        $enforcing   = (string)setting('platform_fee_enforce', '1') === '1';
        $stopService = (string)setting('platform_fee_stop_service', '1') === '1';

        // A hand-granted extension outranks the dates entirely.
        $graceUntil = $provider['billing_grace_until'] ?? null;
        $inGrace    = $graceUntil !== null && $graceUntil >= $today;

        $locked  = $enforcing && !$inGrace && $today >= $lockOn;
        $stopped = $locked && $stopService && $today >= $stopOn;

        $this->applyState($providerId, $locked, $stopped);

        $daysToLock = (int)ceil((strtotime($lockOn) - strtotime($today)) / 86400);

        /*
         * The urgent window. Inside it the provider is reminded and, on
         * sign-in, taken straight to the pay screen. Outside it they are
         * told on their dashboard and otherwise left to work: an invoice
         * raised a month early is not a reason to interrupt anybody.
         */
        $warnDays = max(0, (int)setting('platform_fee_warn_days', 3));
        $dueSoon  = !$locked && !$inGrace && $daysToLock <= $warnDays;

        $stage = match (true) {
            $stopped  => 'stopped',
            $locked   => 'locked',
            $dueSoon  => 'due_soon',
            default   => 'warning',
        };

        $state = [
            'stage'        => $stage,
            'locked'       => $locked,
            'stopped'      => $stopped,
            'warn'         => true,
            'due_soon'     => $dueSoon,
            'urgent'       => $locked || $dueSoon,
            'warn_days'    => $warnDays,
            'owed'         => round($owed, 2),
            'invoices'     => $unpaid,
            'oldest'       => $oldest,
            'due_on'       => $dueOn,
            'lock_on'      => $lockOn,
            'stop_on'      => $stopOn,
            'days_to_lock' => max(0, $daysToLock),
            'grace_until'  => $inGrace ? $graceUntil : null,
            'headline'     => $this->headline($stage, $owed, $inGrace),
            'detail'       => $this->detail($stage, $owed, $lockOn, $stopOn, $daysToLock, $inGrace, $graceUntil, $stopService),
        ];

        $this->notify($providerId, $state);

        return self::$stateCache[$providerId] = $state;
    }

    private static function clearState(string $detail = 'Nothing owed.'): array
    {
        return [
            'stage' => 'clear', 'locked' => false, 'stopped' => false, 'warn' => false,
            'due_soon' => false, 'urgent' => false, 'warn_days' => 0,
            'owed' => 0.0, 'invoices' => [], 'oldest' => null,
            'due_on' => null, 'lock_on' => null, 'stop_on' => null,
            'days_to_lock' => 0, 'grace_until' => null,
            'headline' => 'Platform fee up to date', 'detail' => $detail,
        ];
    }

    private function headline(string $stage, float $owed, bool $inGrace): string
    {
        if ($inGrace) {
            return money($owed) . ' due - extension granted';
        }
        return match ($stage) {
            'stopped'  => 'Service stopped - ' . money($owed) . ' platform fee unpaid',
            'locked'   => 'Pay ' . money($owed) . ' to continue',
            'due_soon' => money($owed) . ' platform fee due very soon',
            default    => money($owed) . ' platform fee due',
        };
    }

    private function detail(string $stage, float $owed, string $lockOn, string $stopOn,
                            int $daysToLock, bool $inGrace, ?string $graceUntil, bool $stopService): string
    {
        if ($inGrace) {
            return 'The platform has given you until ' . format_date($graceUntil, 'd M Y')
                 . ' to settle ' . money($owed) . '. Your account works normally until then.';
        }

        if ($stage === 'stopped') {
            return 'Your account is locked and your network has stopped selling: customers cannot buy a package '
                 . 'or activate a voucher until the ' . money($owed) . ' is paid. Everything restarts the moment it is.';
        }

        if ($stage === 'locked') {
            return 'The payment deadline of ' . format_date($lockOn, 'd M Y') . ' has passed, so the rest of your '
                 . 'account is closed until the ' . money($owed) . ' is settled. Nothing has been deleted, and '
                 . 'everything reopens the moment the payment goes through'
                 . ($stopService ? ' - including your customers\' service, which stops on ' . format_date($stopOn, 'd M Y') . ' if this is left.' : '.');
        }

        return money($owed) . ' is owed by ' . format_date($lockOn, 'd M Y') . '. '
             . ($daysToLock <= 0
                 ? 'That is today.'
                 : 'That is ' . $daysToLock . ' day' . ($daysToLock === 1 ? '' : 's') . ' from now.')
             . ' After that the account is locked until it is paid.';
    }

    /* ================================================================= */
    /* Keeping the provider row honest                                   */
    /* ================================================================= */

    /**
     * Raises this provider's invoice if the billing date has arrived.
     *
     * runBilling() does the same thing for everybody on a schedule. This is
     * the on-demand version for one provider, so the first person to open a
     * page after the billing date sees the invoice immediately.
     */
    public function ensureInvoiced(array|int $provider): void
    {
        if (is_int($provider)) {
            $provider = $this->db->fetchOne('SELECT * FROM providers WHERE id = ? LIMIT 1', [$provider]);
        }
        if (!$provider
            || $provider['billing_status'] === 'exempt'
            || ($provider['status'] ?? '') === 'inactive'
            || empty($provider['billing_next_due_on'])
            || $provider['billing_next_due_on'] > date('Y-m-d')) {
            return;
        }

        $id = (int)$provider['id'];
        if (isset(self::$invoicing[$id])) {
            return;
        }
        self::$invoicing[$id] = true;

        try {
            // runBilling() is safe to call for one provider: it invoices only
            // what is due and will not raise the same period twice.
            $this->billing->runBilling($id);
        } catch (Throwable $e) {
            Logger::error('Could not raise a due platform invoice on demand: ' . $e->getMessage(),
                ['provider_id' => $id]);
        } finally {
            unset(self::$invoicing[$id]);
        }
    }

    /* ================================================================= */
    /* Telling the provider                                              */
    /* ================================================================= */

    /**
     * Rings the provider's own alert bell.
     *
     * Every one of these is addressed with raiseFor(), not raise(): the
     * reminder is usually raised by the scheduler or from inside the
     * platform owner's session, and plain raise() would file it against
     * whichever tenant that request belonged to - where the provider would
     * never see it.
     *
     * Each notice is raised once and then left alone until it is resolved,
     * so opening ten pages does not produce ten reminders, and the bell
     * keeps showing the same unread item rather than reshuffling itself.
     */
    private function notify(int $providerId, array $state): void
    {
        if ($state['locked']) {
            if (!Alert::exists($providerId, 'platform_fee_locked', 'provider', $providerId)) {
                Alert::raiseFor($providerId, 'platform_fee_locked', 'danger',
                    $state['stopped']
                        ? 'Your account is locked and your network has stopped'
                        : 'Your account is locked until the platform fee is paid',
                    $state['detail'],
                    'provider', $providerId);
            }
            // The countdown is over; a "3 days left" notice would be nonsense.
            Alert::clear('platform_fee_reminder', 'provider', $providerId);
            return;
        }

        Alert::clear('platform_fee_locked', 'provider', $providerId);

        if ($state['due_soon']) {
            if (!Alert::exists($providerId, 'platform_fee_reminder', 'provider', $providerId)) {
                $days = $state['days_to_lock'];
                Alert::raiseFor($providerId, 'platform_fee_reminder', 'warning',
                    $days <= 0
                        ? 'Your platform fee is due today'
                        : $days . ' day' . ($days === 1 ? '' : 's') . ' left to pay your platform fee',
                    money($state['owed']) . ' must be paid by ' . format_date($state['lock_on'], 'd M Y')
                    . '. After that you cannot use your account until it is settled.',
                    'provider', $providerId);
            }
        }
    }

    /** Takes down every fee notice for a provider who owes nothing. */
    private function clearNotices(int $providerId): void
    {
        Alert::clearTypes(
            ['platform_fee_reminder', 'platform_fee_locked', 'platform_fee_overdue'],
            'provider',
            $providerId
        );
    }

    /**
     * Writes the lock and suspension flags, and records the crossing.
     *
     * Only writes when something actually changed, so this can be called on
     * every page load without touching the database each time.
     */
    private function applyState(int $providerId, bool $locked, bool $stopped): void
    {
        $row = $this->db->fetchOne(
            'SELECT business_name, billing_locked_at, service_suspended_at FROM providers WHERE id = ? LIMIT 1',
            [$providerId]
        );
        if (!$row) {
            return;
        }

        $wasLocked  = $row['billing_locked_at'] !== null;
        $wasStopped = $row['service_suspended_at'] !== null;
        if ($wasLocked === $locked && $wasStopped === $stopped) {
            return;
        }

        $now     = date('Y-m-d H:i:s');
        $updates = [];
        if ($wasLocked !== $locked) {
            $updates['billing_locked_at'] = $locked ? $now : null;
        }
        if ($wasStopped !== $stopped) {
            $updates['service_suspended_at'] = $stopped ? $now : null;
        }

        $this->db->update('providers', $updates, 'id = ?', [$providerId]);

        $name = $row['business_name'] ?? ('provider #' . $providerId);

        // These two are the PLATFORM owner's business - raiseFor(null, ...)
        // puts them on their bell whoever happened to trigger the crossing.
        if ($locked && !$wasLocked) {
            AuditLog::record('billing_locked', 'provider', $providerId,
                $name . ' was locked out of their account over an unpaid platform fee', 'system', 'Billing');
            Alert::raiseFor(null, 'provider_locked', 'warning', 'Provider locked over an unpaid fee',
                $name . ' cannot use their account until the platform fee is settled.', 'provider', $providerId);
        }
        if (!$locked && $wasLocked) {
            AuditLog::record('billing_unlocked', 'provider', $providerId,
                $name . ' regained access - the platform fee is settled', 'system', 'Billing');
            Alert::clear('provider_locked', 'provider', $providerId);
        }
        if ($stopped && !$wasStopped) {
            AuditLog::record('service_suspended', 'provider', $providerId,
                'Customer service stopped for ' . $name . ' over an unpaid platform fee', 'system', 'Billing');
            Alert::raiseFor(null, 'service_suspended', 'danger', 'A network has stopped selling',
                $name . ' can no longer sell to customers - the platform fee is unpaid.', 'provider', $providerId);
        }
        if (!$stopped && $wasStopped) {
            AuditLog::record('service_restored', 'provider', $providerId,
                'Customer service restored for ' . $name, 'system', 'Billing');
            Alert::clear('service_suspended', 'provider', $providerId);
        }
    }

    /* ================================================================= */
    /* The guards themselves                                             */
    /* ================================================================= */

    /**
     * The gate on every admin page. A locked provider is redirected to the
     * pay screen and can reach nothing else.
     *
     * Platform staff are never locked: the fee is owed *to* them. A Super
     * Admin viewing as a provider is not locked either - they are there to
     * help, and trapping them on a payment screen would stop them doing it.
     */
    public function enforceAdmin(): void
    {
        if (!ProviderContext::isProviderUser()) {
            return;
        }
        $providerId = ProviderContext::providerId();
        if ($providerId === null) {
            return;
        }

        $state = $this->state($providerId);
        if (!$state['locked']) {
            return;
        }

        // The pay screen itself, and the way out, must stay reachable.
        $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
        foreach ([self::PAY_PAGE, 'logout.php'] as $allowed) {
            if (str_ends_with($script, '/' . $allowed) || str_ends_with($script, $allowed)) {
                return;
            }
        }

        if (wants_json()) {
            Response::json([
                'success' => false,
                'locked'  => true,
                'message' => $state['headline'] . '. ' . $state['detail'],
                'pay_url' => url(self::PAY_PAGE),
            ], 402);
        }

        Session::flash('warning', $state['headline'] . '. Settle it here to reopen your account.');
        header('Location: ' . url(self::PAY_PAGE));
        exit;
    }

    /**
     * Has this network stopped selling over an unpaid fee?
     *
     * Read from the stored flag rather than recomputed, because this is
     * called on customer-facing paths where a full billing evaluation on
     * every request would be wasteful. The flag is written by state(), by
     * cron, and whenever the provider opens their own account.
     */
    public static function serviceStopped(?int $providerId): bool
    {
        if ($providerId === null) {
            return false;
        }
        if ((string)setting('platform_fee_stop_service', '1') !== '1') {
            return false;
        }
        try {
            return Database::getInstance()->fetchColumn(
                'SELECT service_suspended_at FROM providers WHERE id = ? LIMIT 1',
                [$providerId]
            ) !== null;
        } catch (Throwable $e) {
            // A database hiccup must never take a paying network offline.
            return false;
        }
    }

    /** What a customer is told when the network they are on has stopped. */
    public static function serviceStoppedMessage(): string
    {
        return 'This Wi-Fi service is temporarily unavailable. Please ask the operator, or try again later.';
    }

    /* ================================================================= */
    /* Platform owner controls                                           */
    /* ================================================================= */

    /** Gives one provider more time, without waiving what they owe. */
    public function grantGrace(int $providerId, string $until, string $reason = ''): array
    {
        $date = date('Y-m-d', strtotime($until));
        if ($date < date('Y-m-d')) {
            return ['ok' => false, 'message' => 'An extension has to end in the future.'];
        }

        $this->db->update('providers', [
            'billing_grace_until'  => $date,
            'billing_locked_at'    => null,
            'service_suspended_at' => null,
        ], 'id = ?', [$providerId]);

        unset(self::$stateCache[$providerId]);
        Alert::clear('provider_locked', 'provider', $providerId);
        Alert::clear('service_suspended', 'provider', $providerId);

        AuditLog::record('billing_grace_granted', 'provider', $providerId,
            'Extended the platform fee deadline to ' . format_date($date, 'd M Y') . '. ' . $reason);

        return ['ok' => true, 'message' => 'Extension granted to ' . format_date($date, 'd M Y') . '.'];
    }

    /** Ends an extension early. */
    public function revokeGrace(int $providerId): array
    {
        $this->db->update('providers', ['billing_grace_until' => null], 'id = ?', [$providerId]);
        unset(self::$stateCache[$providerId]);
        $this->state($providerId, false);

        AuditLog::record('billing_grace_revoked', 'provider', $providerId, 'Ended the platform fee extension');

        return ['ok' => true, 'message' => 'Extension ended. The usual deadlines apply again.'];
    }

    /**
     * Re-evaluates every provider. Called by cron so a lock does not wait
     * for the provider to log in, and a paid provider is reopened promptly.
     *
     * @return array{checked:int,locked:int,stopped:int}
     */
    public function sweep(): array
    {
        $checked = $locked = $stopped = 0;

        $providers = $this->db->fetchAll(
            "SELECT id FROM providers WHERE billing_status <> 'exempt' AND status <> 'inactive'"
        );

        foreach ($providers as $row) {
            try {
                $state = $this->state((int)$row['id'], false);
                $checked++;
                $locked  += $state['locked'] ? 1 : 0;
                $stopped += $state['stopped'] ? 1 : 0;
            } catch (Throwable $e) {
                Logger::error('Fee enforcement sweep failed for a provider: ' . $e->getMessage(),
                    ['provider_id' => (int)$row['id']]);
            }
        }

        return ['checked' => $checked, 'locked' => $locked, 'stopped' => $stopped];
    }

    /** Drops the per-request cache after anything that changes the picture. */
    public static function forget(?int $providerId = null): void
    {
        if ($providerId === null) {
            self::$stateCache = [];
        } else {
            unset(self::$stateCache[$providerId]);
        }
    }
}
