<?php
/**
 * WMS - Wi-Fi providers (tenants).
 *
 * A provider is one independent Wi-Fi business running on this platform.
 * This model is deliberately NOT tenant-scoped: only the Super Admin
 * touches it, and every page that does checks the manage_providers
 * permission first.
 */
class Provider extends Model
{
    protected string $table = 'providers';
    protected bool $tenantScoped = false;
    protected array $searchable = ['provider_code', 'business_name', 'owner_name', 'phone', 'email', 'city'];
    protected array $sortable = ['id', 'business_name', 'status', 'created_at'];

    public const STATUSES = [
        'active'    => 'Active',
        'suspended' => 'Suspended',
        'inactive'  => 'Inactive',
    ];

    public const BUSINESS_TYPES = [
        'hotspot'    => 'Public hotspot',
        'isp'        => 'Internet service provider',
        'hotel'      => 'Hotel / guest house',
        'campus'     => 'School / campus',
        'apartment'  => 'Apartments / estate',
        'cafe'       => 'Cafe / restaurant',
        'other'      => 'Other',
    ];

    /**
     * Provider list with the figures the Super Admin actually needs:
     * how big each tenant is and what it is earning.
     */
    public function search(array $filters, int $page, int $perPage = 20): array
    {
        $where  = ['1=1'];
        $params = [];

        if (!empty($filters['q'])) {
            [$clause, $p] = $this->searchClause((string)$filters['q'], 'pr');
            if ($clause) {
                $where[] = $clause;
                $params  = array_merge($params, $p);
            }
        }
        if (!empty($filters['status'])) {
            $where[]  = 'pr.status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['business_type'])) {
            $where[]  = 'pr.business_type = ?';
            $params[] = $filters['business_type'];
        }
        if (!empty($filters['date_from'])) {
            $where[]  = 'pr.created_at >= ?';
            $params[] = $filters['date_from'] . ' 00:00:00';
        }
        if (!empty($filters['date_to'])) {
            $where[]  = 'pr.created_at <= ?';
            $params[] = $filters['date_to'] . ' 23:59:59';
        }

        $whereSql = implode(' AND ', $where);
        $sort     = $this->safeOrder((string)($filters['sort'] ?? 'created_at DESC'), 'created_at DESC');

        return $this->paginate(
            "SELECT pr.*,
                    (SELECT COUNT(*) FROM users u      WHERE u.provider_id = pr.id) AS user_count,
                    (SELECT COUNT(*) FROM customers c  WHERE c.provider_id = pr.id) AS customer_count,
                    (SELECT COUNT(*) FROM routers r    WHERE r.provider_id = pr.id) AS router_count,
                    (SELECT COUNT(*) FROM routers r    WHERE r.provider_id = pr.id AND r.status = 'online') AS routers_online,
                    (SELECT COUNT(*) FROM sessions s   WHERE s.provider_id = pr.id AND s.status = 'active') AS active_sessions,
                    (SELECT COALESCE(SUM(p.amount),0) FROM payments p
                      WHERE p.provider_id = pr.id AND p.status = 'successful') AS revenue,
                    (SELECT COALESCE(SUM(i.amount),0) FROM platform_invoices i
                      WHERE i.provider_id = pr.id AND i.status = 'unpaid') AS fees_outstanding
               FROM providers pr
              WHERE $whereSql
              ORDER BY pr.$sort",
            "SELECT COUNT(*) FROM providers pr WHERE $whereSql",
            $params,
            $page,
            $perPage
        );
    }

    /** Providers for a dropdown. */
    public function listAll(bool $activeOnly = false): array
    {
        $sql = 'SELECT id, provider_code, business_name, status FROM providers';
        if ($activeOnly) {
            $sql .= " WHERE status = 'active'";
        }
        return $this->db->fetchAll($sql . ' ORDER BY business_name');
    }

    public function findByCode(string $code): ?array
    {
        return $this->db->fetchOne('SELECT * FROM providers WHERE provider_code = ? LIMIT 1', [$code]);
    }

    /** Generates the next sequential provider code, e.g. PRV-0007. */
    public function nextCode(): string
    {
        $last = $this->db->fetchColumn("SELECT provider_code FROM providers WHERE provider_code LIKE 'PRV-%' ORDER BY id DESC LIMIT 1");
        $n    = $last ? (int)substr((string)$last, 4) + 1 : 1;
        do {
            $code = 'PRV-' . str_pad((string)$n, 4, '0', STR_PAD_LEFT);
            $n++;
        } while ($this->findByCode($code) !== null);
        return $code;
    }

    public function isCodeTaken(string $code, ?int $exceptId = null): bool
    {
        $sql    = 'SELECT COUNT(*) FROM providers WHERE provider_code = ?';
        $params = [$code];
        if ($exceptId) {
            $sql     .= ' AND id <> ?';
            $params[] = $exceptId;
        }
        return $this->db->count($sql, $params) > 0;
    }

    /* ------------------------------------------------------------ figures */

    /** Everything the provider detail page shows at the top. */
    public function statistics(int $providerId): array
    {
        // MySQLi uses positional parameters, so the provider id is bound once
        // per sub-select rather than named.
        $sql = "SELECT
                (SELECT COUNT(*) FROM customers WHERE provider_id = ?) AS customers,
                (SELECT COUNT(*) FROM customers WHERE provider_id = ? AND status = 'active') AS customers_active,
                (SELECT COUNT(*) FROM users WHERE provider_id = ?) AS users,
                (SELECT COUNT(*) FROM packages WHERE provider_id = ?) AS packages,
                (SELECT COUNT(*) FROM vouchers WHERE provider_id = ?) AS vouchers,
                (SELECT COUNT(*) FROM vouchers WHERE provider_id = ? AND status = 'available') AS vouchers_available,
                (SELECT COUNT(*) FROM routers WHERE provider_id = ?) AS routers,
                (SELECT COUNT(*) FROM routers WHERE provider_id = ? AND status = 'online') AS routers_online,
                (SELECT COUNT(*) FROM access_points WHERE provider_id = ?) AS access_points,
                (SELECT COUNT(*) FROM devices WHERE provider_id = ?) AS devices,
                (SELECT COUNT(*) FROM sessions WHERE provider_id = ? AND status = 'active') AS sessions_active,
                (SELECT COALESCE(SUM(amount),0) FROM payments WHERE provider_id = ? AND status = 'successful') AS revenue_total,
                (SELECT COALESCE(SUM(total_bytes),0) FROM usage_records WHERE provider_id = ?) AS data_total";

        $row = $this->db->fetchOne($sql, array_fill(0, 13, $providerId)) ?? [];

        $row['revenue_today'] = (float)$this->db->fetchColumn(
            "SELECT COALESCE(SUM(amount),0) FROM payments
              WHERE provider_id = ? AND status = 'successful' AND DATE(created_at) = CURDATE()",
            [$providerId],
            0
        );
        $row['wallet_balance']  = (float)$this->db->fetchColumn('SELECT wallet_balance FROM providers WHERE id = ?', [$providerId], 0);
        $row['wallet_held']     = (float)$this->db->fetchColumn('SELECT wallet_held FROM providers WHERE id = ?', [$providerId], 0);
        $row['fees_outstanding'] = (float)$this->db->fetchColumn(
            "SELECT COALESCE(SUM(amount),0) FROM platform_invoices WHERE provider_id = ? AND status = 'unpaid'",
            [$providerId], 0
        );

        $row['revenue_month'] = (float)$this->db->fetchColumn(
            "SELECT COALESCE(SUM(amount),0) FROM payments
              WHERE provider_id = ? AND status = 'successful' AND created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')",
            [$providerId],
            0
        );

        return $row;
    }

    /** Platform-wide totals for the Super Admin dashboard. */
    public function platformTotals(): array
    {
        $row = $this->db->fetchOne(
            "SELECT
                (SELECT COUNT(*) FROM providers) AS providers,
                (SELECT COUNT(*) FROM providers WHERE status = 'active') AS providers_active,
                (SELECT COUNT(*) FROM providers WHERE status = 'suspended') AS providers_suspended,
                (SELECT COUNT(*) FROM customers) AS customers,
                (SELECT COUNT(*) FROM routers) AS routers,
                (SELECT COUNT(*) FROM routers WHERE status = 'online') AS routers_online,
                (SELECT COUNT(*) FROM sessions WHERE status = 'active') AS sessions_active,
                (SELECT COUNT(*) FROM vouchers) AS vouchers,
                (SELECT COALESCE(SUM(total_bytes),0) FROM usage_records) AS data_total,
                (SELECT COALESCE(SUM(amount),0) FROM payments WHERE status = 'successful' AND DATE(created_at) = CURDATE()) AS revenue_today,
                (SELECT COALESCE(SUM(amount),0) FROM payments WHERE status = 'successful' AND created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')) AS revenue_month,
                (SELECT COALESCE(SUM(amount),0) FROM payments WHERE status = 'successful') AS revenue_total"
        );
        return $row ?? [];
    }

    /** Per-provider performance table on the platform dashboard. */
    public function performance(int $limit = 10): array
    {
        return $this->db->fetchAll(
            "SELECT pr.id, pr.business_name, pr.provider_code, pr.status,
                    (SELECT COUNT(*) FROM customers c WHERE c.provider_id = pr.id) AS customers,
                    (SELECT COUNT(*) FROM routers r WHERE r.provider_id = pr.id) AS routers,
                    (SELECT COUNT(*) FROM routers r WHERE r.provider_id = pr.id AND r.status = 'online') AS routers_online,
                    (SELECT COUNT(*) FROM sessions s WHERE s.provider_id = pr.id AND s.status = 'active') AS sessions,
                    (SELECT COALESCE(SUM(u.total_bytes),0) FROM usage_records u WHERE u.provider_id = pr.id) AS data_used,
                    (SELECT COALESCE(SUM(p.amount),0) FROM payments p
                      WHERE p.provider_id = pr.id AND p.status = 'successful'
                        AND p.created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')) AS revenue_month,
                    pr.wallet_balance, pr.mobile_money_enabled, pr.billing_status
               FROM providers pr
              ORDER BY revenue_month DESC, customers DESC
              LIMIT " . (int)$limit
        );
    }

    /** Staff belonging to one provider. */
    public function users(int $providerId): array
    {
        return $this->db->fetchAll(
            'SELECT u.*, r.name AS role_name, r.slug AS role_slug
               FROM users u JOIN roles r ON r.id = u.role_id
              WHERE u.provider_id = ? ORDER BY u.created_at',
            [$providerId]
        );
    }

    /** Turns mobile-money selling on or off for one provider. */
    public function setMobileMoney(int $providerId, bool $enabled): void
    {
        $this->updateById($providerId, [
            'mobile_money_enabled'    => $enabled ? 1 : 0,
            'mobile_money_changed_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** True when this provider may take mobile money from customers. */
    public function acceptsMobileMoney(int $providerId): bool
    {
        return (int)$this->db->fetchColumn(
            'SELECT mobile_money_enabled FROM providers WHERE id = ? LIMIT 1',
            [$providerId],
            0
        ) === 1;
    }

    /** Keeps the provider record's logo in step with its settings. */
    public function reassignProviderLogo(int $providerId, string $path): void
    {
        $this->db->update('providers', ['logo' => $path], 'id = ?', [$providerId]);
    }

    /** Changes status without ever deleting the tenant or its history. */
    public function setStatus(int $id, string $status): void
    {
        if (!array_key_exists($status, self::STATUSES)) {
            return;
        }
        $this->updateById($id, ['status' => $status]);
    }

    /* ================================================================= */
    /* Deleting a tenant                                                 */
    /* ================================================================= */

    /**
     * What would be destroyed with this provider, and anything that says it
     * should not be. Shown to the Super Admin before they confirm.
     *
     * @return array{counts:array<string,int>,blockers:array<int,string>,wallet:float,held:float}
     */
    public function deletionImpact(int $providerId): array
    {
        $count = fn(string $table): int => $this->db->count(
            'SELECT COUNT(*) FROM `' . $table . '` WHERE provider_id = ?', [$providerId]
        );

        $counts = [
            'staff users'   => $count('users'),
            'customers'     => $count('customers'),
            'packages'      => $count('packages'),
            'vouchers'      => $count('vouchers'),
            'payments'      => $count('payments'),
            'routers'       => $count('routers'),
            'sessions'      => $count('sessions'),
            'wallet entries'=> $count('wallet_transactions'),
            'invoices'      => $count('platform_invoices'),
        ];

        $provider = $this->db->fetchOne(
            'SELECT wallet_balance, wallet_held FROM providers WHERE id = ? LIMIT 1', [$providerId]
        ) ?? [];
        $balance = (float)($provider['wallet_balance'] ?? 0);
        $held    = (float)($provider['wallet_held'] ?? 0);

        /*
         * Money is the one thing deleting cannot undo. A wallet with a
         * balance is money this platform owes a real business, and a payout
         * still in flight is money already moving at SonicPesa - deleting
         * the row it belongs to would leave nothing to reconcile it against.
         */
        $blockers = [];
        if ($balance > 0) {
            $blockers[] = 'Their wallet still holds ' . money($balance)
                . '. Pay it out, or adjust it to zero, before deleting them.';
        }
        $inFlight = $this->db->count(
            "SELECT COUNT(*) FROM withdrawals WHERE provider_id = ? AND status IN ('pending','processing')",
            [$providerId]
        );
        if ($inFlight > 0) {
            $blockers[] = $inFlight . ' withdrawal' . ($inFlight === 1 ? ' is' : 's are')
                . ' still in progress' . ($held > 0 ? ' (' . money($held) . ' held)' : '')
                . '. Let them finish or cancel them first.';
        }

        return ['counts' => $counts, 'blockers' => $blockers, 'wallet' => $balance, 'held' => $held];
    }

    /**
     * Deletes a provider and everything belonging to it.
     *
     * Most tenant tables cascade from the foreign key, but three things do
     * not and have to be dealt with here:
     *
     *   users        - the key is ON DELETE SET NULL, and a NULL provider_id
     *                  is how ProviderContext spells "platform scope". Left
     *                  alone, deleting a tenant would promote its staff into
     *                  administrators of the whole platform.
     *   settings,
     *   notifications,
     *   transactions - no foreign key at all, so their rows would simply be
     *                  orphaned against an id that no longer exists.
     *
     * @return array{ok:bool,message:string}
     */
    public function deleteProvider(int $providerId, bool $force = false): array
    {
        $provider = $this->find($providerId);
        if (!$provider) {
            return ['ok' => false, 'message' => 'That provider could not be found.'];
        }

        $impact = $this->deletionImpact($providerId);
        if ($impact['blockers'] && !$force) {
            return ['ok' => false, 'message' => implode(' ', $impact['blockers'])];
        }

        $name = (string)$provider['business_name'];
        $code = (string)$provider['provider_code'];

        $this->db->beginTransaction();
        try {
            // Staff first, for the reason above.
            $this->db->execute('DELETE FROM users WHERE provider_id = ?', [$providerId]);

            // Then the tables the schema does not cascade for us.
            foreach (['settings', 'notifications', 'transactions'] as $table) {
                $this->db->execute('DELETE FROM `' . $table . '` WHERE provider_id = ?', [$providerId]);
            }

            // The provider row itself; the foreign keys take the rest.
            $this->db->execute('DELETE FROM providers WHERE id = ?', [$providerId]);

            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollback();
            Logger::error('Provider deletion failed: ' . $e->getMessage(), ['provider_id' => $providerId]);
            return ['ok' => false, 'message' => 'That provider could not be deleted. The technical detail is in the log.'];
        }

        Setting::flush();

        // The audit row outlives the tenant: audit_logs.provider_id is set to
        // NULL by the schema, so this is written against the platform.
        AuditLog::record('provider_delete', 'provider', null,
            'Deleted provider "' . $name . '" (' . $code . ') and all of its data');

        Logger::warning('A provider was deleted', [
            'provider_code' => $code, 'business_name' => $name, 'by_user' => Auth::id(),
        ]);

        return ['ok' => true, 'message' => $name . ' and all of its data have been deleted.'];
    }

    public function counts(): array
    {
        return [
            'total'     => $this->countAll(),
            'active'    => $this->countAll("status = 'active'"),
            'suspended' => $this->countAll("status = 'suspended'"),
            'inactive'  => $this->countAll("status = 'inactive'"),
        ];
    }
}
