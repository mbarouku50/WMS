<?php
/**
 * WMS - Operational alerts.
 *
 * Alerts are raised by services (network polling, payments, voucher expiry)
 * and cleared by staff.  Duplicate open alerts of the same type/source are
 * folded together so the list stays useful.
 */
class Alert extends Model
{
    protected string $table = 'alerts';
    protected bool $tenantScoped = true;
    protected array $searchable = ['title', 'message', 'type'];
    protected array $sortable = ['id', 'severity', 'created_at'];

    /**
     * Raises an alert unless an identical unresolved one already exists.
     */
    public static function raise(
        string $type,
        string $severity,
        string $title,
        string $message = '',
        ?string $sourceType = null,
        ?int $sourceId = null
    ): void {
        try {
            $db = Database::getInstance();
            $existing = $db->fetchOne(
                'SELECT id FROM alerts
                  WHERE type = ? AND is_resolved = 0
                    AND (source_type <=> ?) AND (source_id <=> ?)
                  LIMIT 1',
                [$type, $sourceType, $sourceId]
            );
            if ($existing) {
                $db->update('alerts', ['created_at' => date('Y-m-d H:i:s'), 'message' => mb_substr($message, 0, 60000)], 'id = ?', [(int)$existing['id']]);
                return;
            }
            $db->insert('alerts', [
                'provider_id' => ProviderContext::providerId(),
                'type'        => mb_substr($type, 0, 60),
                'severity'    => array_key_exists($severity, WMS_ALERT_SEVERITIES) ? $severity : 'info',
                'title'       => mb_substr($title, 0, 160),
                'message'     => $message,
                'source_type' => $sourceType,
                'source_id'   => $sourceId,
            ]);
        } catch (Throwable $e) {
            Logger::warning('Could not raise alert: ' . $e->getMessage(), ['type' => $type]);
        }
    }

    /** Resolves every open alert of a type/source - e.g. a router came back. */
    public static function clear(string $type, ?string $sourceType = null, ?int $sourceId = null): void
    {
        try {
            Database::getInstance()->execute(
                'UPDATE alerts SET is_resolved = 1, resolved_at = NOW()
                  WHERE type = ? AND is_resolved = 0 AND (source_type <=> ?) AND (source_id <=> ?)',
                [$type, $sourceType, $sourceId]
            );
        } catch (Throwable $e) {
            Logger::warning('Could not clear alerts: ' . $e->getMessage());
        }
    }

    public function search(array $filters, int $page, int $perPage = 25): array
    {
        [$scopeSql, $scopeParams] = $this->scope('a');
        $where  = [$scopeSql];
        $params = $scopeParams;

        if (!empty($filters['q'])) {
            [$clause, $p] = $this->searchClause((string)$filters['q'], 'a');
            if ($clause) {
                $where[] = $clause;
                $params  = array_merge($params, $p);
            }
        }
        if (!empty($filters['severity'])) {
            $where[]  = 'a.severity = ?';
            $params[] = $filters['severity'];
        }
        if (!empty($filters['type'])) {
            $where[]  = 'a.type = ?';
            $params[] = $filters['type'];
        }
        if (($filters['state'] ?? '') === 'open') {
            $where[] = 'a.is_resolved = 0';
        } elseif (($filters['state'] ?? '') === 'resolved') {
            $where[] = 'a.is_resolved = 1';
        } elseif (($filters['state'] ?? '') === 'unread') {
            $where[] = 'a.is_read = 0';
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
            "SELECT a.*, u.full_name AS resolved_by_name
               FROM alerts a LEFT JOIN users u ON u.id = a.resolved_by
              WHERE $whereSql
              ORDER BY a.is_resolved ASC,
                       FIELD(a.severity,'critical','danger','warning','info'),
                       a.created_at DESC",
            "SELECT COUNT(*) FROM alerts a WHERE $whereSql",
            $params,
            $page,
            $perPage
        );
    }

    public function open(int $limit = 8): array
    {
        [$scopeSql, $params] = $this->scope();
        return $this->db->fetchAll(
            "SELECT * FROM alerts WHERE is_resolved = 0 AND $scopeSql
              ORDER BY FIELD(severity,'critical','danger','warning','info'), created_at DESC
              LIMIT " . (int)$limit,
            $params
        );
    }

    public function unreadCount(): int
    {
        return $this->countAll('is_read = 0 AND is_resolved = 0');
    }

    public function counts(): array
    {
        return [
            'open'     => $this->countAll('is_resolved = 0'),
            'critical' => $this->countAll("is_resolved = 0 AND severity IN ('critical','danger')"),
            'warning'  => $this->countAll("is_resolved = 0 AND severity = 'warning'"),
            'resolved' => $this->countAll('is_resolved = 1'),
        ];
    }

    public function markRead(int $id): void
    {
        $this->updateById($id, ['is_read' => 1]);
    }

    public function markAllRead(): int
    {
        [$scopeSql, $params] = $this->scope();
        return $this->db->execute("UPDATE alerts SET is_read = 1 WHERE is_read = 0 AND $scopeSql", $params);
    }

    public function resolve(int $id, ?int $userId): void
    {
        $this->updateById($id, [
            'is_resolved' => 1,
            'is_read'     => 1,
            'resolved_by' => $userId,
            'resolved_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function reopen(int $id): void
    {
        $this->updateById($id, ['is_resolved' => 0, 'resolved_by' => null, 'resolved_at' => null]);
    }

    public function types(): array
    {
        [$scopeSql, $params] = $this->scope();
        return array_column(
            $this->db->fetchAll("SELECT DISTINCT type FROM alerts WHERE $scopeSql ORDER BY type", $params),
            'type'
        );
    }
}
