<?php
/**
 * WMS - CSRF protection.
 *
 * One token per session, compared with hash_equals().  Every state-changing
 * form and POST endpoint must call CSRF::verify() (or requirePost()).
 */
class CSRF
{
    private const SESSION_KEY = '_csrf_token';
    public const FIELD = 'csrf_token';
    public const HEADER = 'HTTP_X_CSRF_TOKEN';

    public static function token(): string
    {
        if (empty($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::SESSION_KEY];
    }

    /** Hidden input to drop inside every form. */
    public static function field(): string
    {
        return '<input type="hidden" name="' . self::FIELD . '" value="' . htmlspecialchars(self::token(), ENT_QUOTES, 'UTF-8') . '">';
    }

    /** Validates a token value (from a form field or the X-CSRF-Token header). */
    public static function check(?string $token): bool
    {
        $expected = $_SESSION[self::SESSION_KEY] ?? '';
        if ($expected === '' || $token === null || $token === '') {
            return false;
        }
        return hash_equals($expected, $token);
    }

    /** Reads the token from the current request, form field first. */
    public static function fromRequest(): ?string
    {
        if (!empty($_POST[self::FIELD])) {
            return (string)$_POST[self::FIELD];
        }
        if (!empty($_SERVER[self::HEADER])) {
            return (string)$_SERVER[self::HEADER];
        }
        $json = json_decode(file_get_contents('php://input') ?: '', true);
        if (is_array($json) && !empty($json[self::FIELD])) {
            return (string)$json[self::FIELD];
        }
        return null;
    }

    /**
     * Verifies the current request.  On failure the user is redirected back
     * with a friendly message (HTML) or receives a 419 JSON body (API).
     */
    public static function verify(bool $json = false): void
    {
        if (self::check(self::fromRequest())) {
            return;
        }

        Logger::warning('CSRF check failed', [
            'uri' => $_SERVER['REQUEST_URI'] ?? '',
            'ip'  => wms_client_ip(),
        ]);

        if ($json) {
            /*
             * 403, not 419. Apache does not recognise the unofficial 419 and
             * rewrites the status to 500, which makes a stale token look like
             * a server outage to the caller and to any monitoring.
             */
            Response::json(['success' => false, 'message' => 'Your session expired. Please refresh the page and try again.'], 403);
        }

        Session::flash('error', 'Your session expired or the form was invalid. Please try again.');
        $back = $_SERVER['HTTP_REFERER'] ?? BASE_PATH;
        header('Location: ' . $back);
        exit;
    }

    /** Rotates the token, e.g. after login. */
    public static function rotate(): void
    {
        unset($_SESSION[self::SESSION_KEY]);
        self::token();
    }
}
