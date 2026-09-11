<?php
/**
 * WMS - System settings, platform and per provider.
 *
 * One table holds both levels:
 *
 *   provider_id = 0   the platform default (set by the Super Admin)
 *   provider_id = N   provider N's own value, which wins for that provider
 *
 * So `setting('currency')` returns the provider's currency when a provider
 * user asks, and the platform default when nobody has overridden it. Which
 * keys a provider may override is deliberately limited - a tenant cannot
 * change platform security or system configuration.
 *
 * Values typed "secret" (payment keys) are never rendered by the UI; only
 * Setting::get() returns them, and only server side.
 */
class Setting
{
    /** provider_id used for platform-level values. */
    public const PLATFORM = 0;

    /** Cached per provider scope for the life of the request. */
    private static array $cache = [];

    /**
     * Keys a provider is allowed to hold its own value for.
     * Everything else always comes from the platform.
     */
    public const PROVIDER_KEYS = [
        'app_name', 'company_name', 'company_tagline', 'support_phone', 'support_email',
        'logo_path', 'timezone', 'currency', 'currency_code',
        'voucher_prefix', 'voucher_code_length', 'voucher_charset', 'voucher_validity_days',
        'voucher_print_note', 'portal_ssid', 'portal_welcome',
        'payment_currency',
        'alert_router_offline', 'alert_payment_failed', 'alert_high_usage_gb',
        'session_idle_timeout', 'records_per_page',
    ];

    /** Keys only the platform may set. */
    public const PLATFORM_ONLY_KEYS = [
        'demo_mode', 'router_poll_seconds', 'router_timeout_seconds', 'cron_token',
        'platform_name',
        /*
         * The payment gateway belongs to the platform, not to each tenant:
         * customer money lands in the platform's merchant account and is
         * settled to providers through their wallet. A provider must never
         * be able to read or replace these.
         */
        'payment_provider', 'sonicpesa_api_key', 'sonicpesa_api_secret',
        'sonicpesa_base_url', 'sonicpesa_use_simple', 'sonicpesa_buyer_email',
        'platform_fee_default', 'platform_fee_cycle_months', 'platform_fee_grace_months',
        'platform_fee_due_days', 'withdrawal_minimum', 'withdrawal_requires_approval',
        'platform_withdrawal_minimum', 'auto_charge_fee_from_wallet',
    ];

    /* ------------------------------------------------------------ loading */

    /** Loads every row for one scope, once per request. */
    private static function load(int $providerId): array
    {
        if (isset(self::$cache[$providerId])) {
            return self::$cache[$providerId];
        }
        self::$cache[$providerId] = [];
        try {
            $rows = Database::getInstance()->fetchAll(
                'SELECT setting_key, setting_value, value_type, setting_group, label
                   FROM settings WHERE provider_id = ?',
                [$providerId]
            );
            foreach ($rows as $row) {
                self::$cache[$providerId][$row['setting_key']] = $row;
            }
        } catch (Throwable $e) {
            // Before installation, or during a database outage, fall back to
            // the built-in defaults rather than failing the page.
            Logger::debug('Settings unavailable: ' . $e->getMessage());
        }
        return self::$cache[$providerId];
    }

    /** The provider whose settings apply to this request (0 = platform). */
    private static function currentScope(): int
    {
        if (!class_exists('ProviderContext')) {
            return self::PLATFORM;
        }
        $id = ProviderContext::providerId();
        return $id === null ? self::PLATFORM : $id;
    }

    /* ------------------------------------------------------------ reading */

    /**
     * Reads a setting: the current provider's value if it has one, otherwise
     * the platform default, otherwise the built-in fallback.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $scope = self::currentScope();

        if ($scope !== self::PLATFORM && !in_array($key, self::PLATFORM_ONLY_KEYS, true)) {
            $row = self::load($scope)[$key] ?? null;
            if ($row !== null && $row['setting_value'] !== null && $row['setting_value'] !== '') {
                return self::cast($row);
            }
        }

        $row = self::load(self::PLATFORM)[$key] ?? null;
        if ($row !== null && $row['setting_value'] !== null && $row['setting_value'] !== '') {
            return self::cast($row);
        }

        return self::$defaults[$key] ?? $default;
    }

    /** Reads a setting for one specific provider, ignoring the session. */
    public static function forProvider(int $providerId, string $key, mixed $default = null): mixed
    {
        $row = self::load($providerId)[$key] ?? null;
        if ($row !== null && $row['setting_value'] !== null && $row['setting_value'] !== '') {
            return self::cast($row);
        }
        $row = self::load(self::PLATFORM)[$key] ?? null;
        if ($row !== null && $row['setting_value'] !== null && $row['setting_value'] !== '') {
            return self::cast($row);
        }
        return self::$defaults[$key] ?? $default;
    }

    private static function cast(array $row): mixed
    {
        $value = $row['setting_value'];
        return match ($row['value_type']) {
            'int'  => (int)$value,
            'bool' => (string)$value === '1',
            'json' => json_decode((string)$value, true),
            default => $value,
        };
    }

    /* ------------------------------------------------------------ writing */

    /**
     * Writes a setting into the caller's own scope.
     *
     * A provider user always writes to their own provider row; they can
     * never reach the platform row or another provider's row.
     */
    public static function set(string $key, mixed $value, string $group = 'general', string $type = 'string'): void
    {
        $scope = self::currentScope();

        // Platform-only keys are ignored when a provider tries to set them.
        if ($scope !== self::PLATFORM && in_array($key, self::PLATFORM_ONLY_KEYS, true)) {
            Logger::warning('A provider tried to change a platform setting', [
                'key' => $key, 'provider_id' => $scope,
            ]);
            return;
        }

        self::write($scope, $key, $value, $group, $type);
    }

    /** Writes a platform-level default. Callers must check permission first. */
    public static function setPlatform(string $key, mixed $value, string $group = 'general', string $type = 'string'): void
    {
        self::write(self::PLATFORM, $key, $value, $group, $type);
    }

    /** Writes a value for one provider. Used by the Super Admin screens. */
    public static function setForProvider(int $providerId, string $key, mixed $value, string $group = 'general', string $type = 'string'): void
    {
        self::write($providerId, $key, $value, $group, $type);
    }

    private static function write(int $providerId, string $key, mixed $value, string $group, string $type): void
    {
        if (is_bool($value)) {
            $value = $value ? '1' : '0';
        }
        if (is_array($value)) {
            $value = json_encode($value);
        }

        $db = Database::getInstance();
        $existing = $db->fetchOne(
            'SELECT id FROM settings WHERE provider_id = ? AND setting_key = ? LIMIT 1',
            [$providerId, $key]
        );

        if ($existing) {
            $db->update('settings', ['setting_value' => (string)$value], 'id = ?', [(int)$existing['id']]);
        } else {
            $db->insert('settings', [
                'provider_id'   => $providerId,
                'setting_key'   => $key,
                'setting_value' => (string)$value,
                'setting_group' => $group,
                'value_type'    => $type,
            ]);
        }
        unset(self::$cache[$providerId]);
    }

    /** Bulk update inside the caller's own scope. */
    public static function setMany(array $values): void
    {
        foreach ($values as $key => $value) {
            self::set($key, $value);
        }
    }

    /* ------------------------------------------------------------- helpers */

    /** All settings in a group for the current scope, keyed by setting_key. */
    public static function group(string $group): array
    {
        $out = [];
        foreach (self::load(self::currentScope()) as $key => $row) {
            if ($row['setting_group'] === $group) {
                $out[$key] = $row;
            }
        }
        return $out;
    }

    /** True when a secret has been configured, without revealing it. */
    public static function hasSecret(string $key): bool
    {
        return (string)self::get($key, '') !== '';
    }

    /** True when the value comes from this provider rather than the platform. */
    public static function isOverridden(string $key): bool
    {
        $scope = self::currentScope();
        if ($scope === self::PLATFORM) {
            return false;
        }
        $row = self::load($scope)[$key] ?? null;
        return $row !== null && $row['setting_value'] !== null && $row['setting_value'] !== '';
    }

    public static function flush(): void
    {
        self::$cache = [];
    }

    /** Fallbacks used before the settings table exists. */
    private static array $defaults = [
        'app_name'            => 'WMS',
        'platform_name'       => 'WMS',
        'company_name'        => 'WMS Networks',
        'currency'            => 'TSh',
        'currency_code'       => 'TZS',
        'timezone'            => 'Africa/Dar_es_Salaam',
        'demo_mode'           => '1',
        'records_per_page'    => 20,
        'voucher_prefix'      => 'WMS',
        'voucher_code_length' => 8,
        'voucher_charset'     => 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789',
        'portal_ssid'         => 'WMS-Hotspot',
        'payment_provider'    => 'demo',
        'sonicpesa_base_url'  => 'https://api.sonicpesa.com/api/v1',
        'withdrawal_minimum'          => 30000,
        'platform_withdrawal_minimum' => 30000,
    ];
}
