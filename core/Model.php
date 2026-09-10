<?php
/**
 * WMS - Minimal base model.
 *
 * Gives every entity class the same handful of CRUD + pagination helpers so
 * the individual model files stay focused on their own business rules.
 * Deliberately tiny: it is a helper, not an ORM.
 */
abstract class Model
{
    protected Database $db;

    /** Table this model reads from. */
    protected string $table = '';

    /** Columns the search box scans. */
    protected array $searchable = [];

    /** Upper bound on rows per query - high enough for CSV exports. */
    public const MAX_PER_PAGE = 10000;

    /** Columns allowed in ORDER BY (guards against injection). */
    protected array $sortable = ['id'];

    /**
     * True when this table carries a provider_id and must be scoped to the
     * current tenant. Setting it here means find(), updateById(),
     * deleteById(), countAll() and create() are all tenant-safe by default -
     * a cross-provider id simply does not resolve.
     */
    protected bool $tenantScoped = false;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    public function table(): string
    {
        return $this->table;
    }

    public function isTenantScoped(): bool
    {
        return $this->tenantScoped;
    }

    /**
     * The WHERE fragment that restricts a query to the current provider.
     * Returns "1=1" for platform scope (Super Admin) and for tables that
     * are not tenant-owned.
     *
     * @return array{0:string,1:array}
     */
    public function scope(string $alias = ''): array
    {
        if (!$this->tenantScoped) {
            return ['1=1', []];
        }
        return ProviderContext::clause($alias);
    }

    /**
     * A single row by primary key, scoped to the current provider.
     *
     * Because the scope is applied in SQL, asking for another tenant's id
     * returns null rather than their data - which is what closes the IDOR
     * hole across every page that looks a record up by id.
     */
    public function find(int $id): ?array
    {
        [$clause, $params] = $this->scope();
        return $this->db->fetchOne(
            'SELECT * FROM `' . $this->table . '` WHERE id = ? AND ' . $clause . ' LIMIT 1',
            array_merge([$id], $params)
        );
    }

    /**
     * Looks a row up ignoring tenant scope.
     * Only for genuinely global lookups (a captive portal resolving which
     * provider a voucher belongs to before anyone is signed in). Callers
     * must decide what the requester is then allowed to see.
     */
    public function findUnscoped(int $id): ?array
    {
        return $this->db->fetchOne('SELECT * FROM `' . $this->table . '` WHERE id = ? LIMIT 1', [$id]);
    }

    /** @return array|null a single row matching one column, tenant scoped */
    public function findBy(string $column, mixed $value): ?array
    {
        [$clause, $params] = $this->scope();
        return $this->db->fetchOne(
            'SELECT * FROM `' . $this->table . '` WHERE `' . str_replace('`', '', $column) . '` = ? AND ' . $clause . ' LIMIT 1',
            array_merge([$value], $params)
        );
    }

    public function all(string $orderBy = 'id DESC', int $limit = 500): array
    {
        [$clause, $params] = $this->scope();
        return $this->db->fetchAll(
            'SELECT * FROM `' . $this->table . '` WHERE ' . $clause
            . ' ORDER BY ' . $this->safeOrder($orderBy) . ' LIMIT ' . (int)$limit,
            $params
        );
    }

    /**
     * Inserts a row, stamping it with the current provider when the table is
     * tenant-owned and the caller has not set one explicitly.
     */
    public function create(array $data): int
    {
        if ($this->tenantScoped && !array_key_exists('provider_id', $data)) {
            $providerId = ProviderContext::providerId();
            if ($providerId !== null) {
                $data['provider_id'] = $providerId;
            }
        }
        return $this->db->insert($this->table, $data);
    }

    /** Updates one row - and only if it belongs to the current provider. */
    public function updateById(int $id, array $data): int
    {
        // provider_id is never reassigned through an ordinary update.
        unset($data['provider_id']);

        [$clause, $params] = $this->scope();
        return $this->db->update($this->table, $data, 'id = ? AND ' . $clause, array_merge([$id], $params));
    }

    /** Moves a record to another provider. Platform scope only. */
    public function reassignProvider(int $id, int $providerId): int
    {
        if (!ProviderContext::isGlobalScope()) {
            Logger::warning('Blocked a provider reassignment outside platform scope', [
                'table' => $this->table, 'id' => $id,
            ]);
            return 0;
        }
        return $this->db->update($this->table, ['provider_id' => $providerId], 'id = ?', [$id]);
    }

    public function deleteById(int $id): int
    {
        [$clause, $params] = $this->scope();
        return $this->db->delete($this->table, 'id = ? AND ' . $clause, array_merge([$id], $params));
    }

    public function countAll(string $where = '1', array $params = []): int
    {
        [$clause, $scopeParams] = $this->scope();
        return $this->db->count(
            'SELECT COUNT(*) FROM `' . $this->table . '` WHERE (' . $where . ') AND ' . $clause,
            array_merge($params, $scopeParams)
        );
    }

    /**
     * Runs a paginated query.
     *
     * @param string $selectSql full SELECT without LIMIT, e.g. "SELECT v.* FROM vouchers v WHERE ..."
     * @param string $countSql  matching COUNT(*) query
     * @return array{rows:array,total:int,page:int,pages:int,per_page:int,from:int,to:int}
     */
    public function paginate(string $selectSql, string $countSql, array $params, int $page, int $perPage = 20): array
    {
        $page = max(1, $page);

        // The cap is generous because CSV exports reuse this method and ask
        // for thousands of rows; on-screen listings only ever request a few
        // dozen. Clamping to 200 here silently truncated exports.
        $perPage = max(1, min(self::MAX_PER_PAGE, $perPage));
        $total   = $this->db->count($countSql, $params);
        $pages   = max(1, (int)ceil($total / $perPage));
        $page    = min($page, $pages);
        $offset  = ($page - 1) * $perPage;

        $rows = $this->db->fetchAll($selectSql . ' LIMIT ' . $perPage . ' OFFSET ' . $offset, $params);

        return [
            'rows'     => $rows,
            'total'    => $total,
            'page'     => $page,
            'pages'    => $pages,
            'per_page' => $perPage,
            'from'     => $total ? $offset + 1 : 0,
            'to'       => min($offset + $perPage, $total),
        ];
    }

    /** Validates an "column direction" string against the sortable whitelist. */
    protected function safeOrder(string $orderBy, string $fallback = 'id DESC'): string
    {
        $parts     = preg_split('/\s+/', trim($orderBy)) ?: [];
        $column    = $parts[0] ?? '';
        $direction = strtoupper($parts[1] ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC';

        if (!in_array($column, $this->sortable, true)) {
            return $fallback;
        }
        return '`' . $column . '` ' . $direction;
    }

    /**
     * Builds a "(col LIKE ? OR col LIKE ?)" fragment for the search box.
     *
     * @return array{0:string,1:array} [sql, params]
     */
    protected function searchClause(string $term, string $alias = ''): array
    {
        if ($term === '' || !$this->searchable) {
            return ['', []];
        }
        $prefix = $alias ? $alias . '.' : '';
        $parts  = [];
        $params = [];
        foreach ($this->searchable as $column) {
            $parts[]  = $prefix . '`' . $column . '` LIKE ?';
            $params[] = '%' . $term . '%';
        }
        return ['(' . implode(' OR ', $parts) . ')', $params];
    }
}
