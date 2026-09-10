<?php
/**
 * WMS - Tenant authorisation.
 *
 * One place that answers "does this record belong to the caller?", so the
 * check is not re-implemented (and eventually got wrong) in fifty pages.
 *
 * The pattern on a page is:
 *
 *      $customer = $customers->find($id);          // already scoped
 *      TenantGuard::ensureRecord($customer, 'customer');
 *
 * Model::find() applies the scope in SQL, so a cross-tenant id simply comes
 * back as null. TenantGuard turns that null - or a stray unscoped row - into
 * a clean refusal, and records the attempt.
 */
class TenantGuard
{
    /**
     * Stops the request unless the current context may act on $providerId.
     */
    public static function ensureAccess(?int $providerId, string $entity = 'record', bool $json = false): void
    {
        if (ProviderContext::canAccess($providerId)) {
            return;
        }
        self::refuse($entity, $json, ['target_provider' => $providerId]);
    }

    /**
     * Checks a fetched row: it must exist and belong to this tenant.
     *
     * @param array|null $record the row, or null when the scoped query missed
     */
    public static function ensureRecord(?array $record, string $entity = 'record', bool $json = false): array
    {
        if ($record === null) {
            self::refuse($entity, $json, ['reason' => 'not found or out of scope']);
        }

        // Belt and braces: if the row carries a provider_id, re-check it here
        // even though the query was already scoped.
        if (array_key_exists('provider_id', $record) && !ProviderContext::canAccess(
            $record['provider_id'] === null ? null : (int)$record['provider_id']
        )) {
            self::refuse($entity, $json, ['reason' => 'cross-provider access']);
        }

        return $record;
    }

    /** A router must belong to the caller before any RouterOS command runs. */
    public static function ensureRouter(int $routerId, bool $json = false): array
    {
        $router = Database::getInstance()->fetchOne('SELECT * FROM routers WHERE id = ? LIMIT 1', [$routerId]);
        if (!$router) {
            self::refuse('router', $json, ['router_id' => $routerId]);
        }
        return self::ensureRecord($router, 'router', $json);
    }

    public static function ensureCustomer(int $customerId, bool $json = false): array
    {
        $row = Database::getInstance()->fetchOne('SELECT * FROM customers WHERE id = ? LIMIT 1', [$customerId]);
        return self::ensureRecord($row, 'customer', $json);
    }

    public static function ensureVoucher(int $voucherId, bool $json = false): array
    {
        $row = Database::getInstance()->fetchOne('SELECT * FROM vouchers WHERE id = ? LIMIT 1', [$voucherId]);
        return self::ensureRecord($row, 'voucher', $json);
    }

    public static function ensurePayment(int $paymentId, bool $json = false): array
    {
        $row = Database::getInstance()->fetchOne('SELECT * FROM payments WHERE id = ? LIMIT 1', [$paymentId]);
        return self::ensureRecord($row, 'payment', $json);
    }

    public static function ensureSession(int $sessionId, bool $json = false): array
    {
        $row = Database::getInstance()->fetchOne('SELECT * FROM sessions WHERE id = ? LIMIT 1', [$sessionId]);
        return self::ensureRecord($row, 'session', $json);
    }

    public static function ensureDevice(int $deviceId, bool $json = false): array
    {
        $row = Database::getInstance()->fetchOne('SELECT * FROM devices WHERE id = ? LIMIT 1', [$deviceId]);
        return self::ensureRecord($row, 'device', $json);
    }

    /**
     * Confirms a set of records all belong to the same provider - used when
     * a voucher, package, customer and router are combined in one action.
     */
    public static function ensureSameProvider(array $records, string $what = 'records', bool $json = false): void
    {
        $seen = null;
        foreach ($records as $record) {
            if (!is_array($record) || !array_key_exists('provider_id', $record)) {
                continue;
            }
            $id = $record['provider_id'] === null ? null : (int)$record['provider_id'];
            if ($seen === null) {
                $seen = $id;
            } elseif ($seen !== $id) {
                self::refuse($what, $json, ['reason' => 'records span two providers']);
            }
        }
        if ($seen !== null) {
            self::ensureAccess($seen, $what, $json);
        }
    }

    /**
     * Logs the attempt and ends the request.
     */
    private static function refuse(string $entity, bool $json, array $context = []): never
    {
        $user = Auth::user();
        Logger::warning('Tenant boundary refusal', array_merge([
            'entity'      => $entity,
            'user_id'     => $user['id'] ?? null,
            'provider_id' => ProviderContext::providerId(),
            'uri'         => $_SERVER['REQUEST_URI'] ?? '',
            'ip'          => wms_client_ip(),
        ], $context));

        AuditLog::record('access_denied', $entity, null,
            'Blocked an attempt to reach a ' . $entity . ' outside the current provider');

        if ($json) {
            Response::json([
                'success' => false,
                'message' => 'That ' . $entity . ' was not found.',
            ], 404);
        }

        Session::flash('error', 'That ' . $entity . ' could not be found.');
        header('Location: ' . url('admin/index.php'));
        exit;
    }
}
