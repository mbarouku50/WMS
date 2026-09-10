<?php
/**
 * WMS - Application constants.
 *
 * Paths, version information and the fixed vocabularies (statuses, units,
 * device types) that the rest of the application shares.  Nothing here talks
 * to the database, so this file is safe to include very early.
 */

if (!defined('WMS_ROOT')) {
    define('WMS_ROOT', dirname(__DIR__));
}

define('WMS_VERSION', '1.0.0');
define('WMS_NAME', 'WMS');

/* ------------------------------------------------------------------ paths */
define('CONFIG_PATH',   WMS_ROOT . '/config');
define('CORE_PATH',     WMS_ROOT . '/core');
define('CLASSES_PATH',  WMS_ROOT . '/classes');
define('SERVICES_PATH', WMS_ROOT . '/services');
define('INCLUDES_PATH', WMS_ROOT . '/includes');
define('UPLOADS_PATH',  WMS_ROOT . '/uploads');
define('LOGS_PATH',     WMS_ROOT . '/logs');
define('DATABASE_PATH', WMS_ROOT . '/database');
define('LOCAL_CONFIG_FILE', CONFIG_PATH . '/app.local.php');
define('INSTALL_LOCK_FILE', CONFIG_PATH . '/installed.lock');

/* ------------------------------------------------------------- vocabulary */

/** Duration units a package or voucher may use. */
const WMS_DURATION_UNITS = [
    'minutes' => 'Minutes',
    'hours'   => 'Hours',
    'days'    => 'Days',
    'months'  => 'Months',
];

/** Voucher lifecycle. */
const WMS_VOUCHER_STATUSES = [
    'available' => 'Available',
    'activated' => 'Activated',
    'active'    => 'Active',
    'expired'   => 'Expired',
    'exhausted' => 'Exhausted',
    'suspended' => 'Suspended',
    'cancelled' => 'Cancelled',
];

const WMS_CUSTOMER_STATUSES = [
    'active'    => 'Active',
    'suspended' => 'Suspended',
    'pending'   => 'Pending',
    'blocked'   => 'Blocked',
];

const WMS_CUSTOMER_TYPES = [
    'individual' => 'Individual',
    'business'   => 'Business',
    'hotspot'    => 'Hotspot walk-in',
    'staff'      => 'Staff',
];

const WMS_DEVICE_TYPES = [
    'phone'    => 'Phone',
    'laptop'   => 'Laptop',
    'tablet'   => 'Tablet',
    'desktop'  => 'Desktop',
    'smart_tv' => 'Smart TV',
    'other'    => 'Other',
];

const WMS_PAYMENT_STATUSES = [
    'pending'    => 'Pending',
    'successful' => 'Successful',
    'failed'     => 'Failed',
    'cancelled'  => 'Cancelled',
    'refunded'   => 'Refunded',
];

const WMS_SESSION_STATUSES = [
    'active'       => 'Active',
    'closed'       => 'Closed',
    'blocked'      => 'Blocked',
    'disconnected' => 'Disconnected',
];

const WMS_ALERT_SEVERITIES = [
    'info'     => 'Info',
    'warning'  => 'Warning',
    'danger'   => 'Danger',
    'critical' => 'Critical',
];

/**
 * Maps a domain status onto one of the five semantic UI tones so status
 * colours stay consistent across every screen.
 * Tones: success | warning | danger | info | neutral
 */
const WMS_STATUS_TONES = [
    'active'       => 'success',
    'available'    => 'info',
    'activated'    => 'success',
    'successful'   => 'success',
    'online'       => 'success',
    'degraded'     => 'warning',
    'stale'        => 'warning',
    'retired'      => 'neutral',
    'closed'       => 'neutral',
    'idle'         => 'neutral',
    'inactive'     => 'neutral',
    'unknown'      => 'neutral',
    'pending'      => 'warning',
    'suspended'    => 'warning',
    'expiring'     => 'warning',
    'exhausted'    => 'warning',
    'expired'      => 'danger',
    'failed'       => 'danger',
    'blocked'      => 'danger',
    'offline'      => 'danger',
    'cancelled'    => 'neutral',
    'disconnected' => 'neutral',
    'refunded'     => 'info',
    'disabled'     => 'neutral',
];

/** Permission slugs used by Permission::check(). */
const WMS_PERMISSIONS = [
    'view_dashboard', 'manage_customers', 'manage_packages', 'manage_vouchers',
    'manage_payments', 'manage_routers', 'manage_devices', 'manage_sessions',
    'manage_reports', 'manage_alerts', 'view_audit_logs', 'manage_staff',
    'manage_settings',
];
