<?php
/**
 * WMS - Network sessions.
 *
 * A session row records one device's spell of connectivity.  Rows created
 * while no live router is attached are flagged source = 'demo' so the UI can
 * label them honestly.
 */
class SessionModel extends Model
{
    protected string $table = 'sessions';
    protected bool $tenantScoped = true;
    protected array $searchable = ['username', 'mac_address', 'ip_address'];
    protected array $sortable = ['id', 'started_at', 'duration_seconds', 'download_bytes'];

    public function search(array $filters, int $page, int $perPage = 25): array
    {
        [$scopeSql, $scopeParams] = $this->scope('s');
        $where  = [$scopeSql];
        $params = $scopeParams;

        if (!empty($filters['q'])) {
            $where[] = '(s.username LIKE ? OR s.mac_address LIKE ? OR s.ip_address LIKE ? OR c.full_name LIKE ? OR v.code LIKE ?)';
            $term    = '%' . $filters['q'] . '%';
            $params  = array_merge($params, array_fill(0, 5, $term));
        }
        foreach (['status' => 's.status', 'source' => 's.source'] as $key => $column) {
            if (!empty($filters[$key])) {
                $where[]  = "$column = ?";
                $params[] = $filters[$key];
            }
        }
        if (!empty($filters['router_id'])) {
            $where[]  = 's.router_id = ?';
            $params[] = (int)$filters['router_id'];
        }
        if (!empty($filters['customer_id'])) {
            $where[]  = 's.customer_id = ?';
            $params[] = (int)$filters['customer_id'];
        }
        if (!empty($filters['date_from'])) {
            $where[]  = 's.started_at >= ?';
            $params[] = $filters['date_from'] . ' 00:00:00';
        }
        if (!empty($filters['date_to'])) {
            $where[]  = 's.started_at <= ?';
            $params[] = $filters['date_to'] . ' 23:59:59';
        }

        $whereSql = implode(' AND ', $where);

        return $this->paginate(
            "SELECT s.*, c.full_name AS customer_name, c.customer_code, v.code AS voucher_code,
                    d.name AS device_name, d.device_type, r.name AS router_name, a.name AS ap_name
               FROM sessions s
               LEFT JOIN customers c ON c.id = s.customer_id
               LEFT JOIN vouchers v ON v.id = s.voucher_id
               LEFT JOIN devices d ON d.id = s.device_id
               LEFT JOIN routers r ON r.id = s.router_id
               LEFT JOIN access_points a ON a.id = s.access_point_id
              WHERE $whereSql ORDER BY (s.status = 'active') DESC, s.started_at DESC",
            "SELECT COUNT(*) FROM sessions s
               LEFT JOIN customers c ON c.id = s.customer_id
               LEFT JOIN vouchers v ON v.id = s.voucher_id
              WHERE $whereSql",
            $params,
            $page,
            $perPage
        );
    }

    public function withRelations(int $id): ?array
    {
        [$scopeSql, $params] = $this->scope('s');
        return $this->db->fetchOne(
            "SELECT s.*, c.full_name AS customer_name, v.code AS voucher_code, d.name AS device_name,
                    r.name AS router_name, a.name AS ap_name
               FROM sessions s
               LEFT JOIN customers c ON c.id = s.customer_id
               LEFT JOIN vouchers v ON v.id = s.voucher_id
               LEFT JOIN devices d ON d.id = s.device_id
               LEFT JOIN routers r ON r.id = s.router_id
               LEFT JOIN access_points a ON a.id = s.access_point_id
              WHERE s.id = ? AND $scopeSql LIMIT 1",
            array_merge([$id], $params)
        );
    }

    public function activeCount(): int
    {
        return $this->countAll("status = 'active'");
    }

    public function activeSessions(int $limit = 20): array
    {
        [$scopeSql, $params] = $this->scope('s');
        return $this->db->fetchAll(
            "SELECT s.*, c.full_name AS customer_name, v.code AS voucher_code, d.name AS device_name, r.name AS router_name
               FROM sessions s
               LEFT JOIN customers c ON c.id = s.customer_id
               LEFT JOIN vouchers v ON v.id = s.voucher_id
               LEFT JOIN devices d ON d.id = s.device_id
               LEFT JOIN routers r ON r.id = s.router_id
              WHERE s.status = 'active' AND $scopeSql ORDER BY s.started_at DESC LIMIT " . (int)$limit,
            $params
        );
    }

    /** Opens a session row. */
    public function open(array $data): int
    {
        return $this->create(array_merge([
            'started_at' => date('Y-m-d H:i:s'),
            'status'     => 'active',
            'source'     => 'demo',
        ], $data));
    }

    /** Closes a session and returns the row as it now stands. */
    public function close(int $id, string $cause = 'closed', string $status = 'closed'): ?array
    {
        $session = $this->find($id);
        if (!$session || $session['status'] !== 'active') {
            return $session;
        }
        $duration = max(0, time() - strtotime((string)$session['started_at']));
        $this->updateById($id, [
            'ended_at'         => date('Y-m-d H:i:s'),
            'duration_seconds' => $duration,
            'status'           => $status,
            'terminate_cause'  => mb_substr($cause, 0, 80),
        ]);
        return $this->find($id);
    }

    /** Adds traffic counters to a live session. */
    public function addTraffic(int $id, int $downloadBytes, int $uploadBytes): void
    {
        $this->db->execute(
            'UPDATE sessions SET download_bytes = download_bytes + ?, upload_bytes = upload_bytes + ?,
                    duration_seconds = GREATEST(duration_seconds, TIMESTAMPDIFF(SECOND, started_at, NOW()))
              WHERE id = ?',
            [$downloadBytes, $uploadBytes, $id]
        );
    }

    /** Live sessions whose voucher or subscription has run out. */
    public function staleActive(int $limit = 100): array
    {
        return $this->db->fetchAll(
            "SELECT s.*, v.expires_at, v.status AS voucher_status
               FROM sessions s
               LEFT JOIN vouchers v ON v.id = s.voucher_id
              WHERE s.status = 'active'
                AND ((v.expires_at IS NOT NULL AND v.expires_at <= NOW())
                     OR v.status IN ('expired','exhausted','cancelled','suspended'))
              LIMIT " . (int)$limit
        );
    }

    /** Totals for the dashboard traffic tiles. */
    public function trafficTotals(string $from, string $to): array
    {
        [$scopeSql, $scopeParams] = $this->scope();
        $row = $this->db->fetchOne(
            "SELECT COALESCE(SUM(download_bytes),0) AS download, COALESCE(SUM(upload_bytes),0) AS upload,
                    COUNT(*) AS sessions, COALESCE(SUM(duration_seconds),0) AS seconds
               FROM sessions WHERE started_at BETWEEN ? AND ? AND $scopeSql",
            array_merge([$from, $to], $scopeParams)
        );
        return [
            'download' => (float)($row['download'] ?? 0),
            'upload'   => (float)($row['upload'] ?? 0),
            'sessions' => (int)($row['sessions'] ?? 0),
            'seconds'  => (int)($row['seconds'] ?? 0),
        ];
    }

    /** Hourly session counts for the last 24 hours. */
    public function hourlySeries(): array
    {
        [$scopeSql, $params] = $this->scope();
        return $this->db->fetchAll(
            "SELECT DATE_FORMAT(started_at, '%H:00') AS hour, COUNT(*) AS sessions,
                    COALESCE(SUM(download_bytes + upload_bytes),0) AS traffic
               FROM sessions
              WHERE started_at >= (NOW() - INTERVAL 24 HOUR) AND $scopeSql
              GROUP BY DATE_FORMAT(started_at, '%H:00'), DATE(started_at)
              ORDER BY MIN(started_at)",
            $params
        );
    }
}
