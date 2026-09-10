<?php
/**
 * WMS - File logger.
 *
 * Writes one dated file per day into logs/.  Technical detail belongs here,
 * never on screen.  Values that look like secrets are masked before writing.
 */
class Logger
{
    private const LEVELS = ['debug', 'info', 'warning', 'error'];

    /** Written at the top of every log file so it cannot be served. */
    private const GUARD = "<?php exit; /* WMS log - not web readable */ ?>\n";

    /** Keys whose values are masked in the context payload. */
    private const SENSITIVE = [
        'password', 'password_hash', 'pass', 'secret', 'api_key', 'api_secret',
        'token', 'x-api-key', 'authorization', 'card', 'pin',
    ];

    public static function debug(string $message, array $context = []): void
    {
        if (defined('ENVIRONMENT') && ENVIRONMENT === 'development') {
            self::write('debug', $message, $context);
        }
    }

    public static function info(string $message, array $context = []): void
    {
        self::write('info', $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::write('warning', $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::write('error', $message, $context);
    }

    /** Dedicated channel for payment provider traffic. */
    public static function payment(string $message, array $context = []): void
    {
        self::write('info', $message, $context, 'payment');
    }

    /** Dedicated channel for router / network traffic. */
    public static function network(string $message, array $context = []): void
    {
        self::write('info', $message, $context, 'network');
    }

    private static function write(string $level, string $message, array $context, string $channel = 'app'): void
    {
        if (!in_array($level, self::LEVELS, true)) {
            $level = 'info';
        }

        $dir = defined('LOGS_PATH') ? LOGS_PATH : __DIR__ . '/../logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        if (!is_writable($dir)) {
            return;
        }

        $line = sprintf(
            "[%s] %s.%s: %s%s%s",
            date('Y-m-d H:i:s'),
            $channel,
            strtoupper($level),
            $message,
            $context ? ' ' . self::encodeContext($context) : '',
            PHP_EOL
        );

        // Logs are named .log.php and start with an exit guard, so that even
        // on a host that ignores .htaccess (nginx, or Apache with
        // AllowOverride off) requesting the file directly returns nothing.
        $file = $dir . '/' . $channel . '-' . date('Y-m-d') . '.log.php';
        if (!is_file($file)) {
            @file_put_contents($file, self::GUARD, LOCK_EX);
            @chmod($file, 0640);
        }

        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }

    private static function encodeContext(array $context): string
    {
        $safe = self::mask($context);
        $json = json_encode($safe, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return $json === false ? '[uncodable context]' : $json;
    }

    /** Recursively replaces sensitive values with a placeholder. */
    private static function mask(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            $lower = strtolower((string)$key);
            $isSecret = false;
            foreach (self::SENSITIVE as $needle) {
                if (str_contains($lower, $needle)) {
                    $isSecret = true;
                    break;
                }
            }
            if ($isSecret) {
                $out[$key] = '***';
            } elseif (is_array($value)) {
                $out[$key] = self::mask($value);
            } elseif (is_scalar($value) || $value === null) {
                $out[$key] = $value;
            } else {
                $out[$key] = '[' . get_debug_type($value) . ']';
            }
        }
        return $out;
    }
}
