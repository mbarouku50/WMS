<?php
/**
 * WMS - Voucher service.
 *
 * All voucher business rules live here: generation, activation, the state
 * machine and usage accounting.  Pages call this service; they never write
 * voucher rows directly.
 *
 *      available -> activated -> active -> expired | exhausted
 */
class VoucherService
{
    private Database $db;
    private Voucher $vouchers;
    private Package $packages;

    public function __construct()
    {
        $this->db       = Database::getInstance();
        $this->vouchers = new Voucher();
        $this->packages = new Package();
    }

    /* ==================================================================== */
    /* Generation                                                           */
    /* ==================================================================== */

    /**
     * Generates a batch of vouchers.
     *
     * @param array $options package_id, quantity, prefix, code_length,
     *                       router_id, price, valid_days, name, notes
     * @return array{ok:bool,message:string,batch_id?:int,codes?:array}
     */
    public function generateBatch(array $options): array
    {
        $package = $this->packages->find((int)($options['package_id'] ?? 0));
        if (!$package) {
            return ['ok' => false, 'message' => 'Choose a package for this batch.'];
        }

        $quantity = max(1, min(2000, (int)($options['quantity'] ?? 1)));
        $prefix   = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)($options['prefix'] ?? '')) ?? '');
        $length   = max(4, min(16, (int)($options['code_length'] ?? setting('voucher_code_length', 8))));
        $charset  = (string)setting('voucher_charset', 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789');
        $price    = isset($options['price']) && $options['price'] !== '' ? (float)$options['price'] : (float)$package['price'];
        $routerId = !empty($options['router_id']) ? (int)$options['router_id'] : null;
        $validDays = (int)($options['valid_days'] ?? setting('voucher_validity_days', 90));
        $validUntil = $validDays > 0 ? date('Y-m-d H:i:s', time() + $validDays * 86400) : null;

        // The tenant comes from the session, never from the form.
        $providerId = ProviderContext::providerId();
        if ($providerId === null) {
            $providerId = isset($options['provider_id']) && ProviderContext::isGlobalScope()
                ? (int)$options['provider_id']
                : null;
        }
        if ($providerId === null) {
            return ['ok' => false, 'message' => 'Choose which provider these vouchers belong to.'];
        }

        // The package must belong to that same provider.
        if ((int)($package['provider_id'] ?? 0) !== $providerId) {
            Logger::warning('Blocked cross-provider voucher generation', [
                'package_provider' => $package['provider_id'] ?? null,
                'acting_provider'  => $providerId,
            ]);
            return ['ok' => false, 'message' => 'That package belongs to a different provider.'];
        }

        $this->db->beginTransaction();
        try {
            $batchCode = $this->nextBatchCode();
            $batchId = $this->db->insert('voucher_batches', [
                'provider_id' => $providerId,
                'batch_code'  => $batchCode,
                'name'        => Validator::string($options['name'] ?? ('Batch ' . $batchCode), 140),
                'package_id'  => (int)$package['id'],
                'router_id'   => $routerId,
                'quantity'    => $quantity,
                'prefix'      => $prefix ?: null,
                'code_length' => $length,
                'price'       => $price,
                'valid_until' => $validUntil,
                'notes'       => Validator::string($options['notes'] ?? '', 255) ?: null,
                'created_by'  => Auth::id(),
            ]);

            $codes = [];
            for ($i = 0; $i < $quantity; $i++) {
                $code = $this->uniqueCode($prefix, $length, $charset);
                $this->db->insert('vouchers', [
                    'provider_id'    => $providerId,
                    'code'           => $code,
                    'batch_id'       => $batchId,
                    'package_id'     => (int)$package['id'],
                    'router_id'      => $routerId,
                    'price'          => $price,
                    'duration_value' => (int)$package['duration_value'],
                    'duration_unit'  => (string)$package['duration_unit'],
                    'data_limit_mb'  => $package['data_limit_mb'] === null ? null : (int)$package['data_limit_mb'],
                    'download_kbps'  => (int)$package['download_kbps'],
                    'upload_kbps'    => (int)$package['upload_kbps'],
                    'device_limit'   => (int)$package['device_limit'],
                    'status'         => 'available',
                    'valid_until'    => $validUntil,
                    'created_by'     => Auth::id(),
                ]);
                $codes[] = $code;
            }

            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollback();
            Logger::error('Voucher generation failed: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'The vouchers could not be generated. Please try again.'];
        }

        AuditLog::record('voucher_generate', 'voucher_batch', $batchId, "Generated $quantity vouchers in batch $batchCode");

        return [
            'ok'       => true,
            'message'  => $quantity . ' voucher' . ($quantity === 1 ? '' : 's') . ' generated successfully.',
            'batch_id' => $batchId,
            'codes'    => $codes,
        ];
    }

    /** Builds a code that is not already in the table. */
    private function uniqueCode(string $prefix, int $length, string $charset): string
    {
        $bodyLength = max(4, $length - ($prefix !== '' ? min(strlen($prefix) + 1, $length - 4) : 0));
        for ($attempt = 0; $attempt < 30; $attempt++) {
            $code = ($prefix !== '' ? $prefix . '-' : '') . random_code($bodyLength, $charset);
            if (!$this->vouchers->codeExists($code)) {
                return $code;
            }
        }
        // Extremely unlikely - widen the code rather than fail the batch.
        return ($prefix !== '' ? $prefix . '-' : '') . random_code($bodyLength + 3, $charset);
    }

    private function nextBatchCode(): string
    {
        $today = date('Ymd');
        $count = $this->db->count('SELECT COUNT(*) FROM voucher_batches WHERE DATE(created_at) = CURDATE()') + 1;
        do {
            $code = 'BAT-' . $today . '-' . str_pad((string)$count, 2, '0', STR_PAD_LEFT);
            $count++;
        } while ($this->db->count('SELECT COUNT(*) FROM voucher_batches WHERE batch_code = ?', [$code]) > 0);
        return $code;
    }

    /* ==================================================================== */
    /* Activation                                                           */
    /* ==================================================================== */

    /**
     * Activates a voucher for a device.
     *
     * @param string $code    the code the customer typed
     * @param array  $context mac, ip, user_agent, customer_id, phone, router_id
     * @return array{ok:bool,message:string,voucher?:array,session_id?:int,live?:bool}
     */
    public function activate(string $code, array $context = []): array
    {
        $code = strtoupper(trim($code));

        // Codes are globally unique, and a customer types theirs before any
        // tenant context exists - so the lookup is deliberately unscoped and
        // the voucher's own provider becomes the context for what follows.
        $voucher = $this->vouchers->findByCodeAnyProvider($code);

        if (!$voucher) {
            return ['ok' => false, 'message' => 'That voucher code was not found. Please check it and try again.'];
        }

        $voucherProvider = $voucher['provider_id'] === null ? null : (int)$voucher['provider_id'];

        // Staff may only activate vouchers belonging to their own provider.
        if (Auth::check() && !ProviderContext::canAccess($voucherProvider)) {
            Logger::warning('Blocked cross-provider voucher activation', [
                'voucher_provider' => $voucherProvider,
                'acting_provider'  => ProviderContext::providerId(),
            ]);
            return ['ok' => false, 'message' => 'That voucher code was not found. Please check it and try again.'];
        }

        // A portal visitor is pinned to the voucher's provider, so the
        // session, device and customer created next all land in one tenant.
        if (!Auth::check() && $voucherProvider !== null) {
            $portalProvider = ProviderContext::providerId();
            if ($portalProvider !== null && $portalProvider !== $voucherProvider) {
                return ['ok' => false, 'message' => 'That voucher belongs to a different Wi-Fi network.'];
            }
            ProviderContext::establishPortal($voucherProvider);
        }

        // The provider must be open for business.
        if ($voucherProvider !== null) {
            $provider = $this->db->fetchOne('SELECT status, business_name FROM providers WHERE id = ? LIMIT 1', [$voucherProvider]);
            if ($provider && $provider['status'] !== 'active') {
                return ['ok' => false, 'message' => 'This Wi-Fi service is temporarily unavailable. Please contact the operator.'];
            }

            /*
             * The operator has not paid their platform fee, so this network
             * has stopped selling. Refused on the server, not merely hidden
             * on the portal - a saved link or a direct POST arrives here too.
             *
             * Note what this does NOT do: a customer already online on a
             * package they paid for stays online. Their session is not cut,
             * because the debt is the operator's, not theirs.
             */
            if (BillingGuard::serviceStopped($voucherProvider)) {
                Logger::warning('Voucher activation refused - the network has stopped over an unpaid platform fee', [
                    'provider_id' => $voucherProvider,
                ]);
                return ['ok' => false, 'message' => BillingGuard::serviceStoppedMessage()];
            }
        }

        // Refresh derived state before judging it.
        $this->refreshState($voucher);
        $voucher = $this->vouchers->findByCodeAnyProvider($code) ?? $voucher;

        $blocked = $this->blockingReason($voucher);
        if ($blocked !== null) {
            return ['ok' => false, 'message' => $blocked, 'voucher' => $voucher];
        }

        $sessionService = new SessionService();
        $limit = $sessionService->checkVoucherDeviceLimit($voucher, $context['mac'] ?? null);
        if (!$limit['allowed']) {
            return ['ok' => false, 'message' => $limit['message'], 'voucher' => $voucher];
        }

        $customerId = $context['customer_id'] ?? $voucher['customer_id'] ?? null;
        if (!$customerId && !empty($context['phone'])) {
            $customer   = (new Customer())->findOrCreateByPhone(Validator::normalisePhone((string)$context['phone']));
            $customerId = (int)($customer['id'] ?? 0) ?: null;
        }

        $firstActivation = $voucher['activated_at'] === null;

        $this->db->beginTransaction();
        try {
            if ($firstActivation) {
                $expiresAt = date('Y-m-d H:i:s', time() + Package::durationSeconds((int)$voucher['duration_value'], (string)$voucher['duration_unit']));
                $this->vouchers->updateById((int)$voucher['id'], [
                    'status'       => 'active',
                    'activated_at' => date('Y-m-d H:i:s'),
                    'expires_at'   => $expiresAt,
                    'customer_id'  => $customerId,
                ]);
                $voucher['activated_at'] = date('Y-m-d H:i:s');
                $voucher['expires_at']   = $expiresAt;
                $voucher['status']       = 'active';
                $voucher['customer_id']  = $customerId;
            } elseif ($customerId && empty($voucher['customer_id'])) {
                $this->vouchers->updateById((int)$voucher['id'], ['customer_id' => $customerId]);
                $voucher['customer_id'] = $customerId;
            }

            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollback();
            Logger::error('Voucher activation failed: ' . $e->getMessage(), ['code' => $code]);
            return ['ok' => false, 'message' => 'We could not activate that voucher. Please try again.'];
        }

        // Open the session and ask the network layer to grant access.
        $session = $sessionService->startSession([
            'voucher'     => $voucher,
            'customer_id' => $customerId,
            'mac'         => $context['mac'] ?? null,
            'ip'          => $context['ip'] ?? wms_client_ip(),
            'user_agent'  => $context['user_agent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? null),
            'router_id'   => $voucher['router_id'] ?? ($context['router_id'] ?? null),
        ]);

        if (!$session['ok']) {
            return ['ok' => false, 'message' => $session['message'], 'voucher' => $voucher];
        }

        $grant = (new NetworkService())->grantVoucherAccess($voucher, $customerId ? (new Customer())->find($customerId) : null);

        AuditLog::record(
            'voucher_activate',
            'voucher',
            (int)$voucher['id'],
            'Voucher ' . $voucher['code'] . ' activated',
            Auth::check() ? 'user' : 'customer',
            $context['actor_name'] ?? 'Captive portal'
        );

        return [
            'ok'         => true,
            'message'    => $firstActivation
                ? 'You are online. Your ' . $voucher['package_name'] . ' access has started.'
                : 'Welcome back - this device is now connected.',
            'voucher'    => $this->vouchers->withPackage((int)$voucher['id']),
            'session_id' => $session['session_id'],
            'live'       => $grant['live'],
            'network'    => $grant['message'],
        ];
    }

    /** Human explanation of why a voucher cannot be used, or null when fine. */
    public function blockingReason(array $voucher): ?string
    {
        return match ($voucher['status']) {
            'expired'   => 'That voucher has expired and can no longer be used.',
            'exhausted' => 'That voucher has used all of its data allowance.',
            'suspended' => 'That voucher is suspended. Please contact support.',
            'cancelled' => 'That voucher has been cancelled.',
            default     => $this->sellByCheck($voucher),
        };
    }

    private function sellByCheck(array $voucher): ?string
    {
        if ($voucher['status'] === 'available'
            && !empty($voucher['valid_until'])
            && strtotime((string)$voucher['valid_until']) < time()) {
            return 'That voucher passed its activation deadline and is no longer valid.';
        }
        return null;
    }

    /* ==================================================================== */
    /* State transitions                                                    */
    /* ==================================================================== */

    /** Recomputes a single voucher's derived state (expiry / exhaustion). */
    public function refreshState(array $voucher): string
    {
        $status = $voucher['status'];
        if (!in_array($status, ['active', 'activated'], true)) {
            return $status;
        }

        if (!empty($voucher['expires_at']) && strtotime((string)$voucher['expires_at']) <= time()) {
            $this->vouchers->updateById((int)$voucher['id'], ['status' => 'expired']);
            return 'expired';
        }
        if ($voucher['data_limit_mb'] !== null && (float)$voucher['data_used_mb'] >= (float)$voucher['data_limit_mb']) {
            $this->vouchers->updateById((int)$voucher['id'], ['status' => 'exhausted']);
            return 'exhausted';
        }
        return $status;
    }

    /**
     * Housekeeping run: expires and exhausts vouchers in bulk.
     * Called on dashboard load and available to cron.
     */
    public function runHousekeeping(): array
    {
        $expired = $this->db->execute(
            "UPDATE vouchers SET status = 'expired'
              WHERE status IN ('active','activated') AND expires_at IS NOT NULL AND expires_at <= NOW()"
        );
        $exhausted = $this->db->execute(
            "UPDATE vouchers SET status = 'exhausted'
              WHERE status IN ('active','activated') AND data_limit_mb IS NOT NULL AND data_used_mb >= data_limit_mb"
        );
        $closed = (new SessionService())->closeStaleSessions();
        (new Subscription())->expireDue();

        return ['expired' => $expired, 'exhausted' => $exhausted, 'sessions_closed' => $closed];
    }

    /** Adds usage to a voucher and exhausts it when the cap is reached. */
    public function addUsage(int $voucherId, float $megabytes): void
    {
        $this->db->execute('UPDATE vouchers SET data_used_mb = data_used_mb + ? WHERE id = ?', [(int)round($megabytes), $voucherId]);
        $this->db->execute(
            "UPDATE vouchers SET status = 'exhausted'
              WHERE id = ? AND data_limit_mb IS NOT NULL AND data_used_mb >= data_limit_mb AND status IN ('active','activated')",
            [$voucherId]
        );
    }

    /**
     * Applies an administrative action to a voucher.
     *
     * @param string $action activate|suspend|resume|cancel|delete
     */
    public function applyAction(int $voucherId, string $action): array
    {
        $voucher = $this->vouchers->withPackage($voucherId);
        if (!$voucher) {
            return ['ok' => false, 'message' => 'That voucher no longer exists.'];
        }

        switch ($action) {
            case 'suspend':
                if (!in_array($voucher['status'], ['available', 'active', 'activated'], true)) {
                    return ['ok' => false, 'message' => 'Only available or active vouchers can be suspended.'];
                }
                $this->vouchers->updateById($voucherId, ['status' => 'suspended']);
                (new NetworkService())->revokeVoucherAccess($voucher);
                AuditLog::record('voucher_suspend', 'voucher', $voucherId, 'Suspended voucher ' . $voucher['code']);
                return ['ok' => true, 'message' => 'Voucher ' . $voucher['code'] . ' suspended.'];

            case 'resume':
                if ($voucher['status'] !== 'suspended') {
                    return ['ok' => false, 'message' => 'Only a suspended voucher can be resumed.'];
                }
                $status = $voucher['activated_at'] ? 'active' : 'available';
                $this->vouchers->updateById($voucherId, ['status' => $status]);
                AuditLog::record('voucher_resume', 'voucher', $voucherId, 'Resumed voucher ' . $voucher['code']);
                return ['ok' => true, 'message' => 'Voucher ' . $voucher['code'] . ' is ' . $status . ' again.'];

            case 'cancel':
                if (in_array($voucher['status'], ['cancelled'], true)) {
                    return ['ok' => false, 'message' => 'That voucher is already cancelled.'];
                }
                $this->vouchers->updateById($voucherId, ['status' => 'cancelled']);
                (new NetworkService())->revokeVoucherAccess($voucher);
                AuditLog::record('voucher_cancel', 'voucher', $voucherId, 'Cancelled voucher ' . $voucher['code']);
                return ['ok' => true, 'message' => 'Voucher ' . $voucher['code'] . ' cancelled.'];

            case 'delete':
                if ($voucher['activated_at'] !== null) {
                    return ['ok' => false, 'message' => 'A voucher that has been used cannot be deleted. Cancel it instead.'];
                }
                $this->vouchers->deleteById($voucherId);
                AuditLog::record('voucher_delete', 'voucher', $voucherId, 'Deleted voucher ' . $voucher['code']);
                return ['ok' => true, 'message' => 'Voucher deleted.'];

            default:
                return ['ok' => false, 'message' => 'That action is not supported.'];
        }
    }

    /** Bulk version of applyAction() for the voucher list checkboxes. */
    public function applyBulkAction(array $ids, string $action): array
    {
        $done = 0;
        $failed = 0;
        foreach (array_map('intval', $ids) as $id) {
            $result = $this->applyAction($id, $action);
            $result['ok'] ? $done++ : $failed++;
        }
        return [
            'ok'      => $done > 0,
            'message' => $done . ' voucher' . ($done === 1 ? '' : 's') . ' updated'
                . ($failed ? ', ' . $failed . ' skipped' : '') . '.',
        ];
    }

    /**
     * Issues a single voucher for a completed purchase.
     * Used by PaymentService when a customer buys a package on the portal.
     */
    public function issueForPurchase(array $package, ?int $customerId, ?int $routerId = null): array
    {
        $charset = (string)setting('voucher_charset', 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789');
        $prefix  = (string)setting('voucher_prefix', 'WMS');
        $length  = (int)setting('voucher_code_length', 8);
        $code    = $this->uniqueCode($prefix, $length, $charset);

        $id = $this->db->insert('vouchers', [
            'provider_id'    => (int)($package['provider_id'] ?? ProviderContext::requireProvider()),
            'code'           => $code,
            'package_id'     => (int)$package['id'],
            'router_id'      => $routerId,
            'customer_id'    => $customerId,
            'price'          => (float)$package['price'],
            'duration_value' => (int)$package['duration_value'],
            'duration_unit'  => (string)$package['duration_unit'],
            'data_limit_mb'  => $package['data_limit_mb'] === null ? null : (int)$package['data_limit_mb'],
            'download_kbps'  => (int)$package['download_kbps'],
            'upload_kbps'    => (int)$package['upload_kbps'],
            'device_limit'   => (int)$package['device_limit'],
            'status'         => 'available',
            'valid_until'    => date('Y-m-d H:i:s', time() + 30 * 86400),
        ]);

        return $this->vouchers->withPackage($id) ?? [];
    }
}
