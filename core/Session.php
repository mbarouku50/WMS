<?php
/**
 * WMS - Session handling.
 *
 * Wraps PHP sessions with sane cookie flags, an idle timeout and small
 * helpers for flash messages.  Admin staff and portal customers are kept in
 * separate keys so one never grants the other's privileges.
 */
class Session
{
    public const ADMIN_KEY    = 'wms_user';
    public const CUSTOMER_KEY = 'wms_customer';

    /** Idle timeout for staff sessions, in seconds. */
    public const IDLE_TIMEOUT = 3600;

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $secure = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
            || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';

        session_name('WMSSESSID');
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => defined('BASE_PATH') ? BASE_PATH : '/',
            'domain'   => '',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');

        @session_start();

        // Idle timeout for signed-in staff.
        if (isset($_SESSION[self::ADMIN_KEY], $_SESSION['last_activity'])) {
            if (time() - (int)$_SESSION['last_activity'] > self::IDLE_TIMEOUT) {
                self::forget(self::ADMIN_KEY);
                self::flash('warning', 'Your session expired after a period of inactivity. Please sign in again.');
            }
        }
        $_SESSION['last_activity'] = time();
    }

    public static function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    public static function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    /** Regenerates the session id - called right after a successful login. */
    public static function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public static function destroy(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
    }

    /* --------------------------------------------------------------- flash */

    /**
     * Queues a one-shot message for the next page render.
     *
     * @param string $type success|error|warning|info
     */
    public static function flash(string $type, string $message): void
    {
        $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
    }

    /** @return array<int,array{type:string,message:string}> */
    public static function takeFlashes(): array
    {
        $flashes = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);
        return is_array($flashes) ? $flashes : [];
    }

    /** Remembers form input so a failed submission can be re-rendered. */
    public static function flashInput(array $input): void
    {
        unset($input['password'], $input['password_confirm'], $input['csrf_token']);
        $_SESSION['_old_input'] = $input;
    }

    public static function oldInput(string $key, mixed $default = ''): mixed
    {
        return $_SESSION['_old_input'][$key] ?? $default;
    }

    public static function clearOldInput(): void
    {
        unset($_SESSION['_old_input']);
    }
}
