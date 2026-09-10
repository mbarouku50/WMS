<?php
/**
 * WMS - API bootstrap.
 *
 * Shared by every endpoint under api/. Sets the JSON contract, and gives each
 * endpoint one-line guards for method, authentication, permission and CSRF.
 *
 * The API is deliberately small: no router, no framework - just plain files
 * that validate, authorise, act and return a consistent envelope.
 */

require_once dirname(__DIR__) . '/config/config.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

/** Rejects anything other than the listed HTTP methods. */
function api_method(string ...$methods): void
{
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array($method, $methods, true)) {
        Response::error('This endpoint accepts ' . implode(' or ', $methods) . ' requests only.', 405);
    }
}

/** Requires a signed-in staff member, and optionally a permission. */
function api_staff(?string $permission = null): array
{
    Auth::requireLogin(true);
    if ($permission !== null) {
        Permission::require($permission, true);
    }
    return Auth::user() ?? [];
}

/** Requires a valid CSRF token (all state-changing endpoints). */
function api_csrf(): void
{
    CSRF::verify(true);
}

/** Reads the JSON (or form) body as an array. */
function api_input(): array
{
    if (!empty($_POST)) {
        return $_POST;
    }
    $raw = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

/** Reads an integer from the query string or body. */
function api_int(string $key, int $default = 0): int
{
    $input = api_input();
    $value = $_GET[$key] ?? ($input[$key] ?? $default);
    return is_numeric($value) ? (int)$value : $default;
}

/** Reads a trimmed string from the query string or body. */
function api_string(string $key, string $default = ''): string
{
    $input = api_input();
    $value = $_GET[$key] ?? ($input[$key] ?? $default);
    return is_scalar($value) ? trim((string)$value) : $default;
}

/**
 * Wraps an endpoint body so an unexpected exception becomes a clean 500
 * instead of a PHP error page.
 */
function api_run(callable $handler): void
{
    try {
        $handler();
    } catch (Throwable $e) {
        Logger::error('API failure: ' . $e->getMessage(), ['uri' => $_SERVER['REQUEST_URI'] ?? '']);
        Response::error('Something went wrong while processing your request. Please try again.', 500);
    }
}
