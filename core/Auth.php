<?php
/**
 * WMS - Authentication.
 *
 * Handles staff (admin) and customer (portal) sign-in, remember-me tokens and
 * simple brute-force throttling backed by the login_attempts table.
 */
class Auth
{
    /** Failed attempts allowed per identifier/IP inside the window. */
    public const MAX_ATTEMPTS   = 5;
    public const LOCKOUT_MINUTES = 15;
    public const REMEMBER_DAYS   = 14;
    private const REMEMBER_COOKIE = 'wms_remember';

    /* ================================================================= */
    /* Staff                                                             */
    /* ================================================================= */

    /**
     * Attempts a staff login.
     *
     * @return array{ok:bool,message:string,user?:array}
     */
    public static function login(string $identifier, string $password, bool $remember = false): array
    {
        $identifier = trim($identifier);
        $db = Database::getInstance();

        if (self::isLockedOut($identifier, 'admin')) {
            return ['ok' => false, 'message' => 'Too many failed attempts. Please wait ' . self::LOCKOUT_MINUTES . ' minutes and try again.'];
        }

        $user = $db->fetchOne(
            'SELECT u.*, r.slug AS role_slug, r.name AS role_name, r.scope AS role_scope,
                    p.business_name AS provider_name, p.status AS provider_status,
                    p.provider_code AS provider_code
               FROM users u
               JOIN roles r ON r.id = u.role_id
               LEFT JOIN providers p ON p.id = u.provider_id
              WHERE u.username = ? OR u.email = ?
              LIMIT 1',
            [$identifier, $identifier]
        );

        if (!$user || !password_verify($password, $user['password_hash'])) {
            self::recordAttempt($identifier, 'admin', false);
            return ['ok' => false, 'message' => 'The username or password is incorrect.'];
        }

        if ($user['status'] !== 'active') {
            self::recordAttempt($identifier, 'admin', false);
            return ['ok' => false, 'message' => 'This account is not active. Please contact an administrator.'];
        }

        // A provider user cannot sign in while their provider is suspended.
        // The data stays untouched; only access is withheld.
        if ($user['provider_id'] !== null && ($user['provider_status'] ?? 'active') !== 'active') {
            self::recordAttempt($identifier, 'admin', false);
            Logger::warning('Sign-in blocked: provider not active', [
                'provider_id' => $user['provider_id'],
                'status'      => $user['provider_status'] ?? '',
            ]);
            return [
                'ok' => false,
                'message' => 'This Wi-Fi provider account is currently ' . ($user['provider_status'] ?? 'unavailable')
                    . '. Please contact the platform administrator.',
            ];
        }

        // Re-hash if PHP's default cost has moved on.
        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            $db->update('users', ['password_hash' => password_hash($password, PASSWORD_DEFAULT)], 'id = ?', [(int)$user['id']]);
        }

        self::recordAttempt($identifier, 'admin', true);
        self::establishSession($user);

        if ($remember) {
            self::issueRememberToken((int)$user['id']);
        }

        $db->update('users', [
            'last_login_at' => date('Y-m-d H:i:s'),
            'last_login_ip' => wms_client_ip(),
        ], 'id = ?', [(int)$user['id']]);

        AuditLog::record('login', 'user', (int)$user['id'], 'Signed in to the control centre');

        return ['ok' => true, 'message' => 'Welcome back, ' . $user['full_name'] . '.', 'user' => $user];
    }

    /** Puts the authenticated user into the session (no password material). */
    private static function establishSession(array $user): void
    {
        Session::regenerate();
        CSRF::rotate();
        Session::set(Session::ADMIN_KEY, [
            'id'            => (int)$user['id'],
            'full_name'     => $user['full_name'],
            'username'      => $user['username'],
            'email'         => $user['email'],
            'avatar'        => $user['avatar'] ?? null,
            'role_id'       => (int)$user['role_id'],
            'role_slug'     => $user['role_slug'] ?? '',
            'role_name'     => $user['role_name'] ?? '',
            'role_scope'    => $user['role_scope'] ?? 'provider',
            'provider_id'   => $user['provider_id'] === null ? null : (int)$user['provider_id'],
            'provider_name' => $user['provider_name'] ?? null,
            'provider_code' => $user['provider_code'] ?? null,
            'login_time'    => time(),
        ]);
        Session::forget('wms_permissions');

        // The tenant context comes from the user's own row - never from the
        // request - and is fixed for the life of the session.
        ProviderContext::establish($user['provider_id'] === null ? null : (int)$user['provider_id']);

        Permission::forCurrentUser(true);
    }

    public static function logout(): void
    {
        $user = self::user();
        if ($user) {
            AuditLog::record('logout', 'user', (int)$user['id'], 'Signed out');
            self::clearRememberToken((int)$user['id']);
        }
        ProviderContext::clear();
        Session::destroy();
    }

    /** @return array|null the signed-in staff user, or null */
    public static function user(): ?array
    {
        $user = Session::get(Session::ADMIN_KEY);
        return is_array($user) ? $user : null;
    }

    public static function id(): ?int
    {
        $user = self::user();
        return $user ? (int)$user['id'] : null;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    /** Redirects to the login page unless a staff member is signed in. */
    public static function requireLogin(bool $json = false): void
    {
        if (self::check()) {
            return;
        }
        if (self::loginFromRememberCookie()) {
            return;
        }

        if ($json) {
            Response::json(['success' => false, 'message' => 'Authentication required.'], 401);
        }

        $target = $_SERVER['REQUEST_URI'] ?? '';
        Session::set('redirect_after_login', $target);
        Session::flash('warning', 'Please sign in to continue.');
        header('Location: ' . url('login.php'));
        exit;
    }

    /* ------------------------------------------------------- remember me */

    private static function issueRememberToken(int $userId): void
    {
        $selector  = bin2hex(random_bytes(8));
        $validator = bin2hex(random_bytes(32));
        $expires   = date('Y-m-d H:i:s', time() + self::REMEMBER_DAYS * 86400);

        Database::getInstance()->update('users', [
            'remember_token'      => $selector . ':' . hash('sha256', $validator),
            'remember_expires_at' => $expires,
        ], 'id = ?', [$userId]);

        setcookie(self::REMEMBER_COOKIE, $selector . ':' . $validator, [
            'expires'  => time() + self::REMEMBER_DAYS * 86400,
            'path'     => BASE_PATH,
            'secure'   => !empty($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private static function clearRememberToken(int $userId): void
    {
        try {
            Database::getInstance()->update('users', [
                'remember_token' => null, 'remember_expires_at' => null,
            ], 'id = ?', [$userId]);
        } catch (Throwable $e) {
            // A failed cleanup must never block sign-out.
        }
        setcookie(self::REMEMBER_COOKIE, '', ['expires' => time() - 3600, 'path' => BASE_PATH]);
    }

    /** Restores a session from a valid remember-me cookie. */
    private static function loginFromRememberCookie(): bool
    {
        $cookie = $_COOKIE[self::REMEMBER_COOKIE] ?? '';
        if (!str_contains($cookie, ':')) {
            return false;
        }
        [$selector, $validator] = explode(':', $cookie, 2);

        try {
            $user = Database::getInstance()->fetchOne(
                'SELECT u.*, r.slug AS role_slug, r.name AS role_name, r.scope AS role_scope,
                        p.business_name AS provider_name, p.status AS provider_status,
                        p.provider_code AS provider_code
                   FROM users u
                   JOIN roles r ON r.id = u.role_id
                   LEFT JOIN providers p ON p.id = u.provider_id
                  WHERE u.remember_token LIKE ?
                    AND u.remember_expires_at > NOW()
                    AND u.status = "active"
                  LIMIT 1',
                [$selector . ':%']
            );
        } catch (Throwable $e) {
            return false;
        }

        if (!$user) {
            return false;
        }

        $expectedHash = explode(':', (string)$user['remember_token'], 2)[1] ?? '';
        if (!hash_equals($expectedHash, hash('sha256', $validator))) {
            self::clearRememberToken((int)$user['id']);
            return false;
        }

        // A remembered session is still refused while the provider is suspended.
        if ($user['provider_id'] !== null && ($user['provider_status'] ?? 'active') !== 'active') {
            self::clearRememberToken((int)$user['id']);
            return false;
        }

        self::establishSession($user);
        return true;
    }

    /* ================================================================= */
    /* Customers (captive portal)                                        */
    /* ================================================================= */

    /**
     * @return array{ok:bool,message:string,customer?:array}
     */
    public static function customerLogin(string $identifier, string $password): array
    {
        $identifier = trim($identifier);

        if (self::isLockedOut($identifier, 'customer')) {
            return ['ok' => false, 'message' => 'Too many attempts. Please wait a few minutes before trying again.'];
        }

        $customer = Database::getInstance()->fetchOne(
            'SELECT * FROM customers WHERE (username = ? OR phone = ? OR email = ?) LIMIT 1',
            [$identifier, $identifier, $identifier]
        );

        if (!$customer || empty($customer['password_hash']) || !password_verify($password, $customer['password_hash'])) {
            self::recordAttempt($identifier, 'customer', false);
            return ['ok' => false, 'message' => 'Those details did not match an account.'];
        }

        if (in_array($customer['status'], ['blocked', 'suspended'], true)) {
            return ['ok' => false, 'message' => 'This account is ' . $customer['status'] . '. Please contact support.'];
        }

        self::recordAttempt($identifier, 'customer', true);
        Session::regenerate();
        Session::set(Session::CUSTOMER_KEY, [
            'id'          => (int)$customer['id'],
            'full_name'   => $customer['full_name'],
            'phone'       => $customer['phone'],
            'code'        => $customer['customer_code'],
            'provider_id' => (int)$customer['provider_id'],
        ]);

        AuditLog::record('customer_login', 'customer', (int)$customer['id'], 'Customer signed in to the portal', 'customer', $customer['full_name']);

        return ['ok' => true, 'message' => 'Signed in.', 'customer' => $customer];
    }

    public static function customer(): ?array
    {
        $customer = Session::get(Session::CUSTOMER_KEY);
        return is_array($customer) ? $customer : null;
    }

    public static function customerId(): ?int
    {
        $customer = self::customer();
        return $customer ? (int)$customer['id'] : null;
    }

    public static function customerLogout(): void
    {
        Session::forget(Session::CUSTOMER_KEY);
    }

    public static function requireCustomer(): void
    {
        if (self::customer() === null) {
            Session::flash('warning', 'Please sign in to view your account.');
            header('Location: ' . url('customer/login.php'));
            exit;
        }
    }

    /* ================================================================= */
    /* Throttling                                                        */
    /* ================================================================= */

    public static function recordAttempt(string $identifier, string $scope, bool $success): void
    {
        try {
            Database::getInstance()->insert('login_attempts', [
                'identifier' => mb_substr($identifier, 0, 160),
                'ip_address' => wms_client_ip(),
                'scope'      => $scope,
                'success'    => $success ? 1 : 0,
            ]);
        } catch (Throwable $e) {
            Logger::warning('Could not record login attempt: ' . $e->getMessage());
        }
    }

    /** True when either the identifier or the IP has failed too often. */
    public static function isLockedOut(string $identifier, string $scope = 'admin'): bool
    {
        try {
            $since = date('Y-m-d H:i:s', time() - self::LOCKOUT_MINUTES * 60);
            $count = Database::getInstance()->count(
                'SELECT COUNT(*) FROM login_attempts
                  WHERE success = 0 AND scope = ? AND created_at > ?
                    AND (identifier = ? OR ip_address = ?)',
                [$scope, $since, $identifier, wms_client_ip()]
            );
            return $count >= self::MAX_ATTEMPTS;
        } catch (Throwable $e) {
            return false; // never lock everyone out because of a DB hiccup
        }
    }

    /** Remaining attempts, for the "x attempts left" hint on the login form. */
    public static function attemptsLeft(string $identifier, string $scope = 'admin'): int
    {
        try {
            $since = date('Y-m-d H:i:s', time() - self::LOCKOUT_MINUTES * 60);
            $count = Database::getInstance()->count(
                'SELECT COUNT(*) FROM login_attempts
                  WHERE success = 0 AND scope = ? AND created_at > ? AND (identifier = ? OR ip_address = ?)',
                [$scope, $since, $identifier, wms_client_ip()]
            );
            return max(0, self::MAX_ATTEMPTS - $count);
        } catch (Throwable $e) {
            return self::MAX_ATTEMPTS;
        }
    }

    /** Housekeeping - drops attempt rows older than a day. */
    public static function pruneAttempts(): void
    {
        try {
            Database::getInstance()->execute('DELETE FROM login_attempts WHERE created_at < (NOW() - INTERVAL 1 DAY)');
        } catch (Throwable $e) {
            // best effort only
        }
    }
}
