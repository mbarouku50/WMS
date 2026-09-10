<?php
/**
 * WMS - Audit trail.
 *
 * Records who did what, when and from where.  Called from services rather
 * than from pages so an action is logged wherever it is triggered.
 */
class AuditLog extends Model
{
    protected string $table = 'audit_logs';
    protected bool $tenantScoped = true;
    protected array $searchable = ['action', 'description', 'actor_name', 'entity_type'];
    protected array $sortable = ['id', 'action', 'created_at'];

    /**
     * Writes one audit entry.  Never throws - logging must not break a save.
     *
     * @param string      $action      e.g. voucher_generate
     * @param string|null $entityType  e.g. voucher
     * @param int|null    $entityId
     * @param string      $description human readable summary
     * @param string      $actorType   user|customer|system|api
     */
    public static function record(
        string $action,
        ?string $entityType = null,
        ?int $entityId = null,
        string $description = '',
        string $actorType = 'user',
        ?string $actorName = null
    ): void {
        try {
            $user = class_exists('Auth') ? Auth::user() : null;
            Database::getInstance()->insert('audit_logs', [
                'provider_id' => class_exists('ProviderContext') ? ProviderContext::providerId() : null,
                'user_id'     => $actorType === 'user' && $user ? (int)$user['id'] : null,
                'actor_type'  => $actorType,
                'actor_name'  => $actorName ?? ($user['full_name'] ?? 'System'),
                'action'      => mb_substr($action, 0, 80),
                'entity_type' => $entityType ? mb_substr($entityType, 0, 60) : null,
                'entity_id'   => $entityId,
                'description' => mb_substr($description, 0, 255),
                'ip_address'  => wms_client_ip(),
                'user_agent'  => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            ]);
        } catch (Throwable $e) {
            Logger::warning('Audit log write failed: ' . $e->getMessage(), ['action' => $action]);
        }
    }

    /** Paginated audit list with search and filters. */
    public function search(array $filters, int $page, int $perPage = 25): array
    {
        [$scopeSql, $scopeParams] = $this->scope('a');
        $where  = [$scopeSql];
        $params = $scopeParams;

        if (!empty($filters['q'])) {
            [$clause, $searchParams] = $this->searchClause((string)$filters['q'], 'a');
            if ($clause) {
                $where[]  = $clause;
                $params   = array_merge($params, $searchParams);
            }
        }
        if (!empty($filters['action'])) {
            $where[]  = 'a.action = ?';
            $params[] = $filters['action'];
        }
        if (!empty($filters['actor_type'])) {
            $where[]  = 'a.actor_type = ?';
            $params[] = $filters['actor_type'];
        }
        if (!empty($filters['date_from'])) {
            $where[]  = 'a.created_at >= ?';
            $params[] = $filters['date_from'] . ' 00:00:00';
        }
        if (!empty($filters['date_to'])) {
            $where[]  = 'a.created_at <= ?';
            $params[] = $filters['date_to'] . ' 23:59:59';
        }

        $whereSql = implode(' AND ', $where);

        return $this->paginate(
            "SELECT a.*, u.username FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id WHERE $whereSql ORDER BY a.created_at DESC, a.id DESC",
            "SELECT COUNT(*) FROM audit_logs a WHERE $whereSql",
            $params,
            $page,
            $perPage
        );
    }

    /** Distinct action names, for the filter dropdown. */
    public function actions(): array
    {
        [$scopeSql, $params] = $this->scope();
        return array_column(
            $this->db->fetchAll("SELECT DISTINCT action FROM audit_logs WHERE $scopeSql ORDER BY action", $params),
            'action'
        );
    }

    public function recent(int $limit = 10): array
    {
        [$scopeSql, $params] = $this->scope();
        return $this->db->fetchAll(
            "SELECT * FROM audit_logs WHERE $scopeSql ORDER BY created_at DESC LIMIT " . (int)$limit,
            $params
        );
    }

    /** Activity for one provider - the Super Admin's provider detail page. */
    public function forProvider(int $providerId, int $limit = 50): array
    {
        return $this->db->fetchAll(
            'SELECT a.*, u.username FROM audit_logs a
               LEFT JOIN users u ON u.id = a.user_id
              WHERE a.provider_id = ? ORDER BY a.created_at DESC LIMIT ' . (int)$limit,
            [$providerId]
        );
    }
}
