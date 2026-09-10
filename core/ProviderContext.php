<?php
/**
 * WMS - Provider (tenant) context.
 *
 * Answers one question for the whole application: *whose data is this
 * request allowed to touch?*
 *
 * There are exactly two answers:
 *
 *   GLOBAL   - a platform Super Admin, who sees every provider.
 *   PROVIDER - everybody else, pinned to one provider id.
 *
 * The provider id is established at login from the user's own row and kept
 * in the session. It is NEVER read from the query string, a form field or a
 * JSON body: a request cannot choose which tenant it belongs to.
 *
 * A Super Admin may temporarily *view as* a provider. That is a session
 * flag, is audit logged, and never alters the Super Admin's own account.
 */
class ProviderContext
{
    private const SESSION_KEY   = 'wms_provider_id';
    private const IMPERSONATING = 'wms_impersonating';
    private const PORTAL_KEY    = 'wms_portal_provider';

    /**
     * Set when a visitor has asked to be shown the network chooser again.
     *
     * Clearing PORTAL_KEY on its own is not enough: resolvePortalProvider()
     * would immediately re-derive the same tenant from this device's history
     * and the chooser would never appear. This flag suppresses the guessing
     * steps for exactly as long as it takes the visitor to pick.
     */
    private const PORTAL_CHOOSE  = 'wms_portal_choose';

    /** Per-request cache of the loaded provider row. */
    private static ?array $cached = null;
    private static ?int $cachedId = null;

    /**
     * System scope: this process is the scheduler, not a person.
     *
     * The scheduled sync has to reach every provider's routers, but it runs
     * with nobody signed in - so without this it would be treated as an
     * anonymous request and refused access to all of them.
     *
     * This is a process-local flag, never a session value: it cannot be set
     * by, or leak into, a browser request. establishSystem() additionally
     * refuses to run unless the caller has already authenticated the job.
     */
    private static bool $systemScope = false;

    /* ================================================================= */
    /* Establishing context                                              */
    /* ================================================================= */

    /**
     * Called by Auth immediately after a successful sign-in.
     * The provider id comes from the user record, not from the request.
     */
    public static function establish(?int $providerId): void
    {
        if ($providerId === null) {
            Session::forget(self::SESSION_KEY);
        } else {
            Session::set(self::SESSION_KEY, $providerId);
        }
        Session::forget(self::IMPERSONATING);
        self::$cached = null;
        self::$cachedId = null;
    }

    /**
     * Marks this process as the scheduler, giving it platform scope.
     *
     * Only cron.php calls this, and only after it has established that the
     * run is legitimate - the command line, or an HTTP request carrying the
     * cron token. Passing false is how a caller says "not authenticated",
     * and the request is then left in its ordinary anonymous scope.
     */
    public static function establishSystem(bool $authenticated): void
    {
        if (!$authenticated) {
            Logger::warning('Refused to establish system scope for an unauthenticated job');
            return;
        }
        self::$systemScope = true;
        self::$cached      = null;
        self::$cachedId    = null;
    }

    /** True while this process is running as the scheduler. */
    public static function isSystemScope(): bool
    {
        return self::$systemScope;
    }

    public static function clear(): void
    {
        self::$systemScope = false;
        Session::forget(self::SESSION_KEY);
        Session::forget(self::IMPERSONATING);
        Session::forget(self::PORTAL_KEY);
        Session::forget(self::PORTAL_CHOOSE);
        self::$cached = null;
        self::$cachedId = null;
    }

    /* ================================================================= */
    /* Who am I?                                                         */
    /* ================================================================= */

    /** True when the signed-in user is a platform-level administrator. */
    public static function isSuperAdmin(): bool
    {
        $user = Auth::user();
        return $user !== null && ($user['role_scope'] ?? '') === 'platform';
    }

    /** True when the request is operating across all providers. */
    public static function isGlobalScope(): bool
    {
        // The scheduler acts for the whole platform: it must be able to poll
        // every provider's routers, with nobody signed in.
        return self::$systemScope || (self::isSuperAdmin() && !self::isImpersonating());
    }

    public static function isProviderUser(): bool
    {
        return Auth::check() && !self::isSuperAdmin();
    }

    public static function isImpersonating(): bool
    {
        return self::isSuperAdmin() && (bool)Session::get(self::IMPERSONATING, false);
    }

    /**
     * The provider this request acts as, or null for global scope.
     * This is the value every scoped query must use.
     *
     * Resolution order, all server side:
     *   1. a signed-in staff member's own provider (null = platform)
     *   2. a signed-in portal customer's provider
     *   3. the provider the captive portal resolved from the network
     */
    public static function providerId(): ?int
    {
        if (self::$systemScope) {
            return null;                        // platform scope: every tenant
        }
        if (Auth::check()) {
            if (self::isGlobalScope()) {
                return null;
            }
            $id = Session::get(self::SESSION_KEY);
            return $id === null ? null : (int)$id;
        }

        $customer = Auth::customer();
        if ($customer && !empty($customer['provider_id'])) {
            return (int)$customer['provider_id'];
        }

        $portal = Session::get(self::PORTAL_KEY);
        return $portal === null ? null : (int)$portal;
    }

    /* ================================================================= */
    /* Captive portal context                                            */
    /* ================================================================= */

    /**
     * Pins an anonymous portal visitor to one provider.
     *
     * Without this a visitor has no tenant, and scoped queries would fall
     * back to "everything" - which would show one provider's packages to
     * another provider's customers.
     */
    public static function establishPortal(int $providerId): void
    {
        Session::set(self::PORTAL_KEY, $providerId);
        self::$cached = null;
        self::$cachedId = null;
    }

    /**
     * Lets a visitor choose their network again.
     *
     * Only meaningful where the visitor picked it themselves. A router that
     * identifies itself still wins on the next request - that identification
     * is authoritative and this flag does not override it.
     */
    public static function forgetPortalProvider(): void
    {
        Session::forget(self::PORTAL_KEY);
        Session::set(self::PORTAL_CHOOSE, true);
        self::$cached = null;
        self::$cachedId = null;
    }

    /** Whether the visitor is currently being asked to pick a network. */
    public static function isChoosingPortal(): bool
    {
        return (bool)Session::get(self::PORTAL_CHOOSE, false);
    }

    /**
     * Works out which provider is serving this visitor.
     *
     * A MikroTik hotspot login page can pass its own identity along, so the
     * router - not a URL parameter a visitor could edit - decides the tenant.
     * The candidates are checked against the routers table, so an invented
     * value resolves to nothing.
     *
     * @return int|null the provider id, or null when it cannot be decided
     */
    public static function resolvePortalProvider(): ?int
    {
        // 1. Already resolved for this session.
        $existing = self::providerId();
        if ($existing !== null) {
            return $existing;
        }

        try {
            $db = Database::getInstance();

            // 2. The router told us who it is (MikroTik hotspot variables).
            $routerIp  = $_GET['nasid'] ?? $_GET['router'] ?? ($_SERVER['HTTP_X_ROUTER_IP'] ?? '');
            $routerMac = $_GET['nasmac'] ?? '';

            if ($routerIp !== '' && filter_var($routerIp, FILTER_VALIDATE_IP)) {
                $router = $db->fetchOne('SELECT provider_id FROM routers WHERE ip_address = ? LIMIT 1', [$routerIp]);
                if ($router && $router['provider_id'] !== null) {
                    self::establishPortal((int)$router['provider_id']);
                    return (int)$router['provider_id'];
                }
            }

            if ($routerMac !== '') {
                $mac = normalise_mac($routerMac);
                if ($mac !== null) {
                    $ap = $db->fetchOne('SELECT provider_id FROM access_points WHERE mac_address = ? LIMIT 1', [$mac]);
                    if ($ap && $ap['provider_id'] !== null) {
                        self::establishPortal((int)$ap['provider_id']);
                        return (int)$ap['provider_id'];
                    }
                }
            }

            /*
             * The visitor asked to change network. Everything below this
             * point is a guess, and guessing is exactly what they rejected -
             * so stop here and let the chooser do its job.
             */
            if (self::isChoosingPortal()) {
                return null;
            }

            // 3. This device has connected before - reuse that provider.
            $mac = SessionService::syntheticMac(wms_client_ip());
            $device = $db->fetchOne(
                'SELECT provider_id FROM devices WHERE mac_address = ? AND provider_id IS NOT NULL
                  ORDER BY last_seen_at DESC LIMIT 1',
                [$mac]
            );
            if ($device && $device['provider_id'] !== null) {
                self::establishPortal((int)$device['provider_id']);
                return (int)$device['provider_id'];
            }

            // 4. Single-tenant installation: there is only one answer.
            $active = $db->fetchAll("SELECT id FROM providers WHERE status = 'active' LIMIT 2");
            if (count($active) === 1) {
                self::establishPortal((int)$active[0]['id']);
                return (int)$active[0]['id'];
            }
        } catch (Throwable $e) {
            Logger::warning('Could not resolve the portal provider: ' . $e->getMessage());
        }

        // 5. Undecided - the portal will ask the visitor which network they are on.
        return null;
    }

    /**
     * Pins the portal to a provider the visitor picked from a list.
     * The id is validated against active providers, so a hand-edited value
     * cannot reach a suspended or non-existent tenant.
     */
    public static function selectPortalProvider(int $providerId): bool
    {
        try {
            $row = Database::getInstance()->fetchOne(
                "SELECT id FROM providers WHERE id = ? AND status = 'active' LIMIT 1",
                [$providerId]
            );
        } catch (Throwable $e) {
            return false;
        }
        if (!$row) {
            return false;
        }
        Session::forget(self::PORTAL_CHOOSE);
        self::establishPortal($providerId);
        return true;
    }

    /** Active providers a portal visitor may choose between. */
    public static function portalChoices(): array
    {
        try {
            return Database::getInstance()->fetchAll(
                "SELECT id, business_name, city FROM providers WHERE status = 'active' ORDER BY business_name"
            );
        } catch (Throwable $e) {
            return [];
        }
    }

    /** The provider row, or null in global scope. */
    public static function provider(): ?array
    {
        $id = self::providerId();
        if ($id === null) {
            return null;
        }
        if (self::$cached !== null && self::$cachedId === $id) {
            return self::$cached;
        }
        try {
            self::$cached = Database::getInstance()->fetchOne('SELECT * FROM providers WHERE id = ? LIMIT 1', [$id]);
            self::$cachedId = $id;
        } catch (Throwable $e) {
            Logger::warning('Could not load the provider context: ' . $e->getMessage());
            return null;
        }
        return self::$cached;
    }

    /** "Platform" or the provider's business name - shown in the topbar. */
    public static function scopeLabel(): string
    {
        if (self::isGlobalScope()) {
            return 'Platform';
        }
        $provider = self::provider();
        return $provider['business_name'] ?? 'Unknown provider';
    }

    /* ================================================================= */
    /* Guards                                                            */
    /* ================================================================= */

    /**
     * The provider id for a write, insisting there is one.
     * Use when creating tenant-owned records.
     */
    public static function requireProvider(bool $json = false): int
    {
        $id = self::providerId();
        if ($id !== null) {
            return $id;
        }

        // A Super Admin acting globally has no single provider to write to;
        // they must pick one first (the UI offers a provider selector).
        if ($json) {
            Response::json([
                'success' => false,
                'message' => 'Choose a provider before creating this record.',
            ], 422);
        }
        Session::flash('warning', 'Choose which provider this belongs to first.');
        header('Location: ' . url('admin/providers/index.php'));
        exit;
    }

    /** May this request touch records belonging to $providerId? */
    public static function canAccess(?int $providerId): bool
    {
        if (self::isGlobalScope()) {
            return true;
        }
        $mine = self::providerId();
        return $mine !== null && $providerId !== null && (int)$providerId === $mine;
    }

    /* ================================================================= */
    /* Viewing as a provider (Super Admin only)                          */
    /* ================================================================= */

    public static function startImpersonation(int $providerId): bool
    {
        if (!self::isSuperAdmin() || !Permission::has('impersonate_provider')) {
            Logger::warning('Blocked an impersonation attempt', ['user_id' => Auth::id(), 'provider_id' => $providerId]);
            return false;
        }

        $provider = Database::getInstance()->fetchOne('SELECT * FROM providers WHERE id = ? LIMIT 1', [$providerId]);
        if (!$provider) {
            return false;
        }

        Session::set(self::SESSION_KEY, $providerId);
        Session::set(self::IMPERSONATING, true);
        self::$cached = null;
        self::$cachedId = null;

        AuditLog::record('provider_impersonate_start', 'provider', $providerId,
            'Started viewing the system as ' . $provider['business_name']);

        return true;
    }

    public static function stopImpersonation(): void
    {
        if (!self::isImpersonating()) {
            return;
        }
        $providerId = self::providerId();
        Session::forget(self::SESSION_KEY);
        Session::forget(self::IMPERSONATING);
        self::$cached = null;
        self::$cachedId = null;

        AuditLog::record('provider_impersonate_stop', 'provider', $providerId,
            'Returned to the platform view');
    }

    /* ================================================================= */
    /* Query helper                                                      */
    /* ================================================================= */

    /**
     * The WHERE fragment that scopes a query to the current tenant.
     *
     *      [$clause, $params] = ProviderContext::clause('c');
     *      $sql = "SELECT * FROM customers c WHERE $clause";
     *
     * In global scope this is "1=1", so a Super Admin sees everything.
     *
     * @return array{0:string,1:array}
     */
    public static function clause(string $alias = '', string $column = 'provider_id'): array
    {
        $id = self::providerId();
        if ($id === null) {
            return ['1=1', []];
        }
        $prefix = $alias !== '' ? $alias . '.' : '';
        return [$prefix . '`' . $column . '` = ?', [$id]];
    }
}
