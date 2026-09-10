<?php
/**
 * WMS - Subscriptions.
 *
 * Created when a payment succeeds (or a voucher is activated by an account
 * holder).  A subscription is what actually grants a customer access for a
 * period of time, with its own data counter and device allowance.
 */
class Subscription extends Model
{
    protected string $table = 'subscriptions';
    protected bool $tenantScoped = true;
    protected array $sortable = ['id', 'start_at', 'end_at', 'status'];

    /** Opens a subscription from a package definition. */
    public function open(int $customerId, array $package, ?int $voucherId = null, ?int $paymentId = null): int
    {
        $start = date('Y-m-d H:i:s');
        $end   = date('Y-m-d H:i:s', time() + Package::durationSeconds((int)$package['duration_value'], (string)$package['duration_unit']));

        return $this->create([
            'provider_id'   => (int)($package['provider_id'] ?? ProviderContext::providerId()),
            'customer_id'   => $customerId,
            'package_id'    => (int)$package['id'],
            'voucher_id'    => $voucherId,
            'payment_id'    => $paymentId,
            'start_at'      => $start,
            'end_at'        => $end,
            'data_limit_mb' => $package['data_limit_mb'] === null ? null : (int)$package['data_limit_mb'],
            'device_limit'  => (int)$package['device_limit'],
            'status'        => 'active',
        ]);
    }

    public function activeFor(int $customerId): ?array
    {
        [$scopeSql, $params] = $this->scope('s');
        return $this->db->fetchOne(
            "SELECT s.*, p.name AS package_name, p.download_kbps, p.upload_kbps
               FROM subscriptions s JOIN packages p ON p.id = s.package_id
              WHERE s.customer_id = ? AND s.status = 'active' AND s.end_at > NOW() AND $scopeSql
              ORDER BY s.end_at DESC LIMIT 1",
            array_merge([$customerId], $params)
        );
    }

    public function forCustomer(int $customerId, int $limit = 20): array
    {
        [$scopeSql, $params] = $this->scope('s');
        return $this->db->fetchAll(
            "SELECT s.*, p.name AS package_name FROM subscriptions s
               JOIN packages p ON p.id = s.package_id
              WHERE s.customer_id = ? AND $scopeSql ORDER BY s.start_at DESC LIMIT " . (int)$limit,
            array_merge([$customerId], $params)
        );
    }

    /** Adds usage and closes the subscription when the cap is reached. */
    public function addUsage(int $subscriptionId, float $megabytes): void
    {
        $this->db->execute('UPDATE subscriptions SET data_used_mb = data_used_mb + ? WHERE id = ?', [(int)round($megabytes), $subscriptionId]);
        $this->db->execute(
            "UPDATE subscriptions SET status = 'exhausted'
              WHERE id = ? AND data_limit_mb IS NOT NULL AND data_used_mb >= data_limit_mb AND status = 'active'",
            [$subscriptionId]
        );
    }

    /** Housekeeping: marks finished subscriptions as expired. */
    public function expireDue(): int
    {
        return $this->db->execute("UPDATE subscriptions SET status = 'expired' WHERE status = 'active' AND end_at <= NOW()");
    }

    public function endingSoon(int $hours = 24, int $limit = 20): array
    {
        // Unscoped: the reminder job runs platform-wide; each row carries
        // its own provider_id for the alert that gets raised.
        return $this->db->fetchAll(
            "SELECT s.*, s.provider_id, p.name AS package_name, c.full_name AS customer_name, c.phone
               FROM subscriptions s
               JOIN packages p ON p.id = s.package_id
               LEFT JOIN customers c ON c.id = s.customer_id
              WHERE s.status = 'active' AND s.end_at BETWEEN NOW() AND (NOW() + INTERVAL ? HOUR)
              ORDER BY s.end_at ASC LIMIT " . (int)$limit,
            [$hours]
        );
    }

    public function stats(): array
    {
        return [
            'active'    => $this->countAll("status = 'active' AND end_at > NOW()"),
            'expired'   => $this->countAll("status = 'expired'"),
            'exhausted' => $this->countAll("status = 'exhausted'"),
        ];
    }

    /** Remaining seconds / data for the customer status screen. */
    public static function remaining(array $subscription): array
    {
        return [
            'seconds' => seconds_until($subscription['end_at'] ?? null),
            'data_mb' => $subscription['data_limit_mb'] === null
                ? null
                : max(0, (float)$subscription['data_limit_mb'] - (float)$subscription['data_used_mb']),
        ];
    }
}
