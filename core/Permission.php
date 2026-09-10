<?php
/**
 * WMS - Role based access control.
 *
 * Permissions are read from the database once per request and cached in the
 * session.  Every protected page calls Permission::require('slug'); hiding a
 * button in HTML is never treated as access control.
 */
class Permission
{
    private const CACHE_KEY = 'wms_permissions';

    /** Loads (and caches) the permission slugs of the signed-in user. */
    public static function forCurrentUser(bool $refresh = false): array
    {
        $user = Auth::user();
        if (!$user) {
            return [];
        }

        $cached = Session::get(self::CACHE_KEY);
        if (!$refresh && is_array($cached) && ($cached['role_id'] ?? null) === (int)$user['role_id']) {
            return $cached['slugs'];
        }

        $slugs = self::forRole((int)$user['role_id']);
        Session::set(self::CACHE_KEY, ['role_id' => (int)$user['role_id'], 'slugs' => $slugs]);
        return $slugs;
    }

    /** @return string[] */
    public static function forRole(int $roleId): array
    {
        try {
            $rows = Database::getInstance()->fetchAll(
                'SELECT p.slug
                   FROM role_permissions rp
                   JOIN permissions p ON p.id = rp.permission_id
                  WHERE rp.role_id = ?
                  ORDER BY p.slug',
                [$roleId]
            );
        } catch (Throwable $e) {
            Logger::error('Unable to load role permissions: ' . $e->getMessage());
            return [];
        }
        return array_column($rows, 'slug');
    }

    /** Permission slugs that only a platform-level account may ever hold. */
    public const PLATFORM_ONLY = [
        'manage_providers', 'view_global_reports', 'manage_all_routers',
        'manage_all_users', 'impersonate_provider', 'manage_platform_settings',
        'manage_staff', 'manage_settings',
    ];

    /** True when the current user holds the permission. */
    public static function has(string $slug): bool
    {
        $user = Auth::user();
        if (!$user) {
            return false;
        }

        $isPlatformUser = ($user['role_scope'] ?? 'provider') === 'platform';

        // A provider account can never hold a platform permission, whatever
        // the role_permissions table happens to say.
        if (!$isPlatformUser && in_array($slug, self::PLATFORM_ONLY, true)) {
            return false;
        }

        // Super Admin always passes - it is the recovery role.
        if (($user['role_slug'] ?? '') === 'super_admin' && $isPlatformUser) {
            return true;
        }

        return in_array($slug, self::forCurrentUser(), true);
    }

    /** True when the signed-in account is a platform administrator. */
    public static function isPlatform(): bool
    {
        $user = Auth::user();
        return $user !== null && ($user['role_scope'] ?? 'provider') === 'platform';
    }

    /**
     * Stops the request unless this is a platform-level account.
     * Used by everything under admin/providers/ and platform settings.
     */
    public static function requirePlatform(bool $json = false): void
    {
        if (self::isPlatform()) {
            return;
        }

        Logger::warning('Blocked provider access to a platform page', [
            'user_id' => Auth::id(),
            'uri'     => $_SERVER['REQUEST_URI'] ?? '',
        ]);

        if ($json) {
            Response::json(['success' => false, 'message' => 'This action is restricted to platform administrators.'], 403);
        }

        Session::flash('error', 'That area is restricted to platform administrators.');
        header('Location: ' . url('admin/index.php'));
        exit;
    }

    /** True when the user holds at least one of the given permissions. */
    public static function hasAny(array $slugs): bool
    {
        foreach ($slugs as $slug) {
            if (self::has($slug)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Stops the request unless the permission is held.
     * HTML requests get a friendly "no access" page; API requests get JSON.
     */
    public static function require(string $slug, bool $json = false): void
    {
        if (self::has($slug)) {
            return;
        }

        $user = Auth::user();
        Logger::warning('Permission denied', [
            'permission' => $slug,
            'user_id'    => $user['id'] ?? null,
            'uri'        => $_SERVER['REQUEST_URI'] ?? '',
        ]);

        if ($json) {
            Response::json(['success' => false, 'message' => 'You do not have permission to perform this action.'], 403);
        }

        Session::flash('error', 'You do not have permission to open that page.');
        header('Location: ' . url('admin/index.php'));
        exit;
    }

    /**
     * Permissions available for the roles screen.
     *
     * A provider administrator editing their own staff roles only ever sees
     * provider-scope permissions, so a platform permission cannot be handed
     * out from inside a tenant.
     */
    public static function all(?string $scope = null): array
    {
        $scope = $scope ?? (self::isPlatform() ? null : 'provider');
        try {
            if ($scope === null) {
                return Database::getInstance()->fetchAll('SELECT * FROM permissions ORDER BY scope DESC, group_name, id');
            }
            return Database::getInstance()->fetchAll(
                'SELECT * FROM permissions WHERE scope = ? ORDER BY group_name, id',
                [$scope]
            );
        } catch (Throwable $e) {
            return [];
        }
    }
}
