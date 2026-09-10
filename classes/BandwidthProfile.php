<?php
/**
 * WMS - Bandwidth profiles.
 *
 * A profile describes a rate limit that packages reuse and that the network
 * layer can push to MikroTik as a queue / rate-limit definition.
 */
class BandwidthProfile extends Model
{
    protected string $table = 'bandwidth_profiles';
    protected bool $tenantScoped = true;
    protected array $searchable = ['name', 'description', 'mikrotik_name'];
    protected array $sortable = ['id', 'name', 'download_kbps', 'priority'];

    public function search(array $filters, int $page, int $perPage = 20): array
    {
        [$scopeSql, $scopeParams] = $this->scope('b');
        $where  = [$scopeSql];
        $params = $scopeParams;

        if (!empty($filters['q'])) {
            [$clause, $p] = $this->searchClause((string)$filters['q'], 'b');
            if ($clause) {
                $where[] = $clause;
                $params  = array_merge($params, $p);
            }
        }
        if (!empty($filters['status'])) {
            $where[]  = 'b.status = ?';
            $params[] = $filters['status'];
        }
        $whereSql = implode(' AND ', $where);

        return $this->paginate(
            "SELECT b.*, (SELECT COUNT(*) FROM packages p WHERE p.bandwidth_profile_id = b.id) AS package_count
               FROM bandwidth_profiles b WHERE $whereSql ORDER BY b.priority ASC, b.download_kbps DESC",
            "SELECT COUNT(*) FROM bandwidth_profiles b WHERE $whereSql",
            $params,
            $page,
            $perPage
        );
    }

    public function active(): array
    {
        [$scopeSql, $params] = $this->scope();
        return $this->db->fetchAll(
            "SELECT * FROM bandwidth_profiles WHERE status = 'active' AND $scopeSql ORDER BY priority ASC, name ASC",
            $params
        );
    }

    /** RouterOS rate-limit string: "rx/tx" plus burst values when enabled. */
    public static function rateLimit(array $profile): string
    {
        $down = (int)$profile['download_kbps'];
        $up   = (int)$profile['upload_kbps'];
        $base = $up . 'k/' . $down . 'k';

        if (!empty($profile['burst_enabled']) && !empty($profile['burst_download_kbps'])) {
            $burstUp   = (int)($profile['burst_upload_kbps'] ?: $up);
            $burstDown = (int)$profile['burst_download_kbps'];
            $time      = (int)($profile['burst_time'] ?: 8);
            // rx/tx  burst-rx/burst-tx  threshold-rx/threshold-tx  time/time
            $base .= ' ' . $burstUp . 'k/' . $burstDown . 'k'
                   . ' ' . (int)($burstUp * 0.8) . 'k/' . (int)($burstDown * 0.8) . 'k'
                   . ' ' . $time . '/' . $time;
        }
        return $base;
    }

    public function isNameTaken(string $name, ?int $exceptId = null): bool
    {
        [$scopeSql, $scopeParams] = $this->scope();
        $sql    = "SELECT COUNT(*) FROM bandwidth_profiles WHERE name = ? AND $scopeSql";
        $params = array_merge([$name], $scopeParams);
        if ($exceptId) {
            $sql     .= ' AND id <> ?';
            $params[] = $exceptId;
        }
        return $this->db->count($sql, $params) > 0;
    }
}
