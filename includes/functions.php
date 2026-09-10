<?php
/**
 * WMS - Shared helper functions.
 *
 * Small, dependency-free helpers used by every page: URL building, output
 * escaping, and the formatters that keep money, data volumes and durations
 * looking the same everywhere in the product.
 */

/* ------------------------------------------------------------------ URLs */

/** Builds an absolute-from-root URL for a path inside the application. */
function url(string $path = ''): string
{
    return BASE_PATH . ltrim($path, '/');
}

/** URL for a file inside assets/, cache-busted by file modification time. */
function asset(string $path): string
{
    $path = ltrim($path, '/');
    $file = WMS_ROOT . '/assets/' . $path;
    $version = is_file($file) ? '?v=' . filemtime($file) : '';
    return ASSET_URL . $path . $version;
}

/** True when the current request URI matches the given path fragment. */
function is_current(string $fragment): bool
{
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    return $fragment !== '' && str_contains($uri, $fragment);
}

/** Best-effort client IP, honouring one level of proxy header. */
function wms_client_ip(): string
{
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
        if (!empty($_SERVER[$key])) {
            $ip = trim(explode(',', (string)$_SERVER[$key])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }
    return '0.0.0.0';
}

/* -------------------------------------------------------------- escaping */

/** Escapes a value for HTML output. */
function e(mixed $value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Escapes a value for use inside a JavaScript/JSON context. */
function ejs(mixed $value): string
{
    return htmlspecialchars(json_encode($value, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?: 'null', ENT_QUOTES, 'UTF-8');
}

/* ------------------------------------------------------------ formatting */

/** Formats an amount using the configured currency symbol, e.g. "TSh 1,000". */
function money(float|int|string|null $amount, bool $withSymbol = true): string
{
    $amount = (float)($amount ?? 0);
    $symbol = setting('currency', 'TSh');
    $formatted = number_format($amount, fmod($amount, 1.0) === 0.0 ? 0 : 2);
    return $withSymbol ? $symbol . ' ' . $formatted : $formatted;
}

/** Human readable data volume from bytes. */
function format_bytes(int|float|null $bytes, int $precision = 1): string
{
    $bytes = max(0, (float)($bytes ?? 0));
    if ($bytes < 1024) {
        return number_format($bytes) . ' B';
    }
    $units = ['KB', 'MB', 'GB', 'TB', 'PB'];
    $i = -1;
    do {
        $bytes /= 1024;
        $i++;
    } while ($bytes >= 1024 && $i < count($units) - 1);
    return number_format($bytes, $bytes >= 100 ? 0 : $precision) . ' ' . $units[$i];
}

/** Human readable data volume from megabytes (null = unlimited). */
function format_mb(int|float|null $mb, string $unlimitedLabel = 'Unlimited'): string
{
    if ($mb === null) {
        return $unlimitedLabel;
    }
    return format_bytes((float)$mb * 1024 * 1024);
}

/** Formats kilobits per second as Mbps/Kbps. */
function format_speed(int|float|null $kbps): string
{
    $kbps = (float)($kbps ?? 0);
    if ($kbps <= 0) {
        return '—';
    }
    return $kbps >= 1024
        ? rtrim(rtrim(number_format($kbps / 1024, 1), '0'), '.') . ' Mbps'
        : number_format($kbps) . ' Kbps';
}

/** "2h 15m" style duration from seconds. */
function format_duration(int|float|null $seconds, bool $short = true): string
{
    $seconds = (int)max(0, (int)($seconds ?? 0));
    if ($seconds < 60) {
        return $seconds . 's';
    }
    $days    = intdiv($seconds, 86400);
    $hours   = intdiv($seconds % 86400, 3600);
    $minutes = intdiv($seconds % 3600, 60);

    $parts = [];
    if ($days) {
        $parts[] = $days . 'd';
    }
    if ($hours) {
        $parts[] = $hours . 'h';
    }
    if ($minutes && !$days) {
        $parts[] = $minutes . 'm';
    }
    if (!$parts) {
        $parts[] = $minutes . 'm';
    }
    return implode(' ', $short ? array_slice($parts, 0, 2) : $parts);
}

/** Renders a package/voucher duration such as "24 Hours" or "30 Days". */
function format_package_duration(int $value, string $unit): string
{
    $label = WMS_DURATION_UNITS[$unit] ?? ucfirst($unit);
    if ($value === 1) {
        $label = rtrim($label, 's');
    }
    return $value . ' ' . $label;
}

/** Formats a datetime for display; returns a dash when empty. */
function format_date(?string $datetime, string $format = 'd M Y, H:i'): string
{
    if (!$datetime || $datetime === '0000-00-00 00:00:00') {
        return '—';
    }
    $ts = strtotime($datetime);
    return $ts ? date($format, $ts) : '—';
}

/** "3 minutes ago" / "in 2 hours" relative time. */
function time_ago(?string $datetime): string
{
    if (!$datetime) {
        return '—';
    }
    $ts = strtotime($datetime);
    if (!$ts) {
        return '—';
    }
    $diff   = time() - $ts;
    $future = $diff < 0;
    $diff   = abs($diff);

    $units = [31536000 => 'year', 2592000 => 'month', 604800 => 'week', 86400 => 'day', 3600 => 'hour', 60 => 'minute'];
    foreach ($units as $seconds => $label) {
        if ($diff >= $seconds) {
            $count = (int)floor($diff / $seconds);
            $text  = $count . ' ' . $label . ($count > 1 ? 's' : '');
            return $future ? 'in ' . $text : $text . ' ago';
        }
    }
    return $future ? 'in a moment' : 'just now';
}

/** Seconds remaining until a datetime, or 0 when it has passed. */
function seconds_until(?string $datetime): int
{
    if (!$datetime) {
        return 0;
    }
    $ts = strtotime($datetime);
    return $ts ? max(0, $ts - time()) : 0;
}

/** Maps a status string to one of the five semantic tones. */
function status_tone(?string $status): string
{
    $status = strtolower((string)$status);
    return WMS_STATUS_TONES[$status] ?? 'neutral';
}

/** Title-cases a status/enum value for display. */
function label(?string $value): string
{
    return $value === null || $value === '' ? '—' : ucwords(str_replace('_', ' ', $value));
}

/** Initials for an avatar bubble. */
function initials(?string $name): string
{
    $name = trim((string)$name);
    if ($name === '') {
        return '?';
    }
    $parts = preg_split('/\s+/', $name) ?: [];
    $first = mb_substr($parts[0] ?? '', 0, 1);
    $last  = count($parts) > 1 ? mb_substr(end($parts), 0, 1) : '';
    return mb_strtoupper($first . $last);
}

/** Percentage helper that never divides by zero. */
function percent(float|int $part, float|int $total, int $precision = 0): float
{
    if ($total <= 0) {
        return 0.0;
    }
    return round(($part / $total) * 100, $precision);
}

/* ------------------------------------------------------------- settings */

/** Reads a value from the settings table (cached for the request). */
function setting(string $key, mixed $default = null): mixed
{
    return Setting::get($key, $default);
}

/**
 * Reads a setting from the platform's own scope, ignoring whichever provider
 * the session happens to be pinned to.
 *
 * The public pages - the landing page and the staff sign in - are the
 * platform's front door, not a tenant's. Plain setting() answers from the
 * current provider scope, so once a visitor had picked a network in the
 * captive portal the landing page started introducing itself with that
 * operator's name. These pages ask for the platform's answer explicitly.
 */
function platform_setting(string $key, mixed $default = null): mixed
{
    return Setting::forProvider(Setting::PLATFORM, $key, $default);
}

/** True when the system is running without live network equipment. */
function demo_mode(): bool
{
    return (string)setting('demo_mode', '1') === '1';
}

/* --------------------------------------------------------------- inputs */

/** Reads a GET parameter as a trimmed string. */
function query(string $key, string $default = ''): string
{
    $value = $_GET[$key] ?? $default;
    return is_scalar($value) ? trim((string)$value) : $default;
}

/** Reads a POST parameter as a trimmed string. */
function post(string $key, string $default = ''): string
{
    $value = $_POST[$key] ?? $default;
    return is_scalar($value) ? trim((string)$value) : $default;
}

/** Current page number for paginated lists. */
function current_page(): int
{
    return max(1, (int)($_GET['page'] ?? 1));
}

/** Rebuilds the current query string with some parameters replaced. */
function query_string(array $overrides = [], array $remove = []): string
{
    $params = array_merge($_GET, $overrides);
    foreach ($remove as $key) {
        unset($params[$key]);
    }
    $params = array_filter($params, static fn($v) => $v !== '' && $v !== null);
    return $params ? '?' . http_build_query($params) : '';
}

/** True when the request is a POST. */
function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

/** True when the request expects JSON (fetch/XHR). */
function wants_json(): bool
{
    return str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
        || strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
}

/* ----------------------------------------------------------------- misc */

/** Generates a random code from an unambiguous alphabet. */
function random_code(int $length = 8, string $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'): string
{
    $max  = strlen($alphabet) - 1;
    $code = '';
    for ($i = 0; $i < $length; $i++) {
        $code .= $alphabet[random_int(0, $max)];
    }
    return $code;
}

/** Normalises a MAC address to AA:BB:CC:DD:EE:FF. */
function normalise_mac(?string $mac): ?string
{
    if (!$mac) {
        return null;
    }
    $clean = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', $mac) ?? '');
    if (strlen($clean) !== 12) {
        return null;
    }
    return implode(':', str_split($clean, 2));
}

/** Guesses a device type from a browser user agent string. */
function guess_device_type(?string $userAgent): string
{
    $ua = strtolower((string)$userAgent);
    return match (true) {
        str_contains($ua, 'ipad') || str_contains($ua, 'tablet')   => 'tablet',
        str_contains($ua, 'mobile') || str_contains($ua, 'android'),
        str_contains($ua, 'iphone')                                 => 'phone',
        str_contains($ua, 'smart-tv') || str_contains($ua, 'tv')    => 'smart_tv',
        str_contains($ua, 'macintosh') || str_contains($ua, 'linux'),
        str_contains($ua, 'windows')                                => 'laptop',
        default                                                     => 'other',
    };
}

/** Truncates a string for table cells. */
function str_limit(?string $value, int $limit = 60): string
{
    $value = (string)$value;
    return mb_strlen($value) > $limit ? mb_substr($value, 0, $limit - 1) . '…' : $value;
}

/** Masks a secret, showing only the last few characters. */
function mask_secret(?string $secret, int $visible = 4): string
{
    $secret = (string)$secret;
    if ($secret === '') {
        return 'Not set';
    }
    if (mb_strlen($secret) <= $visible) {
        return str_repeat('•', 8);
    }
    return str_repeat('•', 12) . mb_substr($secret, -$visible);
}
