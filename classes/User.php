<?php
/**
 * WMS - Staff accounts.
 */
class User extends Model
{
    protected string $table = 'users';
    protected bool $tenantScoped = true;
    protected array $searchable = ['full_name', 'username', 'email', 'phone'];
    protected array $sortable = ['id', 'full_name', 'username', 'created_at', 'last_login_at'];

    /** Paginated staff list including the role name. */
    public function search(array $filters, int $page, int $perPage = 20): array
    {
        [$scopeSql, $scopeParams] = $this->scope('u');
        $where  = [$scopeSql];
        $params = $scopeParams;

        if (!empty($filters['q'])) {
            [$clause, $p] = $this->searchClause((string)$filters['q'], 'u');
            if ($clause) {
                $where[] = $clause;
                $params  = array_merge($params, $p);
            }
        }
        if (!empty($filters['role_id'])) {
            $where[]  = 'u.role_id = ?';
            $params[] = (int)$filters['role_id'];
        }
        if (!empty($filters['status'])) {
            $where[]  = 'u.status = ?';
            $params[] = $filters['status'];
        }

        $whereSql = implode(' AND ', $where);

        return $this->paginate(
            "SELECT u.*, r.name AS role_name, r.slug AS role_slug, r.scope AS role_scope,
                    pr.business_name AS provider_name
               FROM users u
               JOIN roles r ON r.id = u.role_id
               LEFT JOIN providers pr ON pr.id = u.provider_id
              WHERE $whereSql ORDER BY u.created_at DESC",
            "SELECT COUNT(*) FROM users u WHERE $whereSql",
            $params,
            $page,
            $perPage
        );
    }

    public function withRole(int $id): ?array
    {
        [$scopeSql, $params] = $this->scope('u');
        return $this->db->fetchOne(
            "SELECT u.*, r.name AS role_name, r.slug AS role_slug, r.scope AS role_scope
               FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ? AND $scopeSql LIMIT 1",
            array_merge([$id], $params)
        );
    }

    /** True when the username or email is already taken by another account. */
    public function isTaken(string $column, string $value, ?int $exceptId = null): bool
    {
        // Usernames and emails stay unique across the whole platform so that
        // sign-in never needs to ask which provider you meant.
        $column = in_array($column, ['username', 'email'], true) ? $column : 'username';
        $sql    = "SELECT COUNT(*) FROM users WHERE `$column` = ?";
        $params = [$value];
        if ($exceptId) {
            $sql .= ' AND id <> ?';
            $params[] = $exceptId;
        }
        return $this->db->count($sql, $params) > 0;
    }

    public function createUser(array $data, string $password): int
    {
        $data['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
        return $this->create($data);
    }

    public function changePassword(int $id, string $password): void
    {
        $this->updateById($id, ['password_hash' => password_hash($password, PASSWORD_DEFAULT)]);
    }

    /**
     * Roles this user may assign.
     *
     * A provider administrator only ever sees provider-scope roles, so they
     * cannot promote anybody to a platform role.
     */
    public function roles(?string $scope = null): array
    {
        $scope = $scope ?? (ProviderContext::isGlobalScope() ? null : 'provider');

        $where  = $scope ? 'r.scope = ?' : '1=1';
        $params = $scope ? [$scope] : [];

        return $this->db->fetchAll(
            "SELECT r.*, (SELECT COUNT(*) FROM users u WHERE u.role_id = r.id) AS user_count,
                    (SELECT COUNT(*) FROM role_permissions rp WHERE rp.role_id = r.id) AS permission_count
               FROM roles r WHERE $where ORDER BY r.scope DESC, r.id",
            $params
        );
    }

    /** True when the role is a platform-level role. */
    public function isPlatformRole(int $roleId): bool
    {
        $row = $this->db->fetchOne('SELECT scope FROM roles WHERE id = ? LIMIT 1', [$roleId]);
        return ($row['scope'] ?? 'provider') === 'platform';
    }

    public function role(int $id): ?array
    {
        return $this->db->fetchOne('SELECT * FROM roles WHERE id = ? LIMIT 1', [$id]);
    }

    /** Replaces the permission set of a role. */
    public function syncRolePermissions(int $roleId, array $permissionIds): void
    {
        $this->db->beginTransaction();
        try {
            $this->db->delete('role_permissions', 'role_id = ?', [$roleId]);
            foreach (array_unique(array_map('intval', $permissionIds)) as $pid) {
                if ($pid > 0) {
                    $this->db->insert('role_permissions', ['role_id' => $roleId, 'permission_id' => $pid]);
                }
            }
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    public function stats(): array
    {
        return [
            'total'     => $this->countAll(),
            'active'    => $this->countAll('status = ?', ['active']),
            'suspended' => $this->countAll('status = ?', ['suspended']),
        ];
    }
}
