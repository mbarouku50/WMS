-- =====================================================================
--  WMS - Multi-Provider / Multi-Tenant upgrade
--  ---------------------------------------------------------------------
--  Turns a single-business WMS into a platform hosting many independent
--  Wi-Fi providers, WITHOUT losing any existing data.
--
--  Safe to run more than once: every ALTER is guarded by a check against
--  information_schema, so re-running adds nothing twice.
--
--  Order of operations
--    1. providers table
--    2. role/permission scope columns + the new platform role & permissions
--    3. provider_id on every tenant-owned table (+ indexes, + foreign keys)
--    4. settings reshaped so a provider can hold its own values
--    5. a "Default Wi-Fi Provider" created and given every existing record
--    6. existing staff attached to that provider; super admins left global
--
--  Back up your database before running this.
--    mysqldump -u USER -p DBNAME > wms-backup.sql
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- Idempotency helpers
-- ---------------------------------------------------------------------

DROP PROCEDURE IF EXISTS wms_add_column;
DROP PROCEDURE IF EXISTS wms_add_index;
DROP PROCEDURE IF EXISTS wms_add_fk;
DROP PROCEDURE IF EXISTS wms_drop_index;

DELIMITER $$

CREATE PROCEDURE wms_add_column(IN t VARCHAR(64), IN c VARCHAR(64), IN ddl TEXT)
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                    WHERE table_schema = DATABASE() AND table_name = t AND column_name = c) THEN
        SET @sql = CONCAT('ALTER TABLE `', t, '` ADD COLUMN ', ddl);
        PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
    END IF;
END$$

CREATE PROCEDURE wms_add_index(IN t VARCHAR(64), IN idx VARCHAR(64), IN cols TEXT)
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.statistics
                    WHERE table_schema = DATABASE() AND table_name = t AND index_name = idx) THEN
        SET @sql = CONCAT('ALTER TABLE `', t, '` ADD INDEX `', idx, '` (', cols, ')');
        PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
    END IF;
END$$

CREATE PROCEDURE wms_drop_index(IN t VARCHAR(64), IN idx VARCHAR(64))
BEGIN
    IF EXISTS (SELECT 1 FROM information_schema.statistics
                WHERE table_schema = DATABASE() AND table_name = t AND index_name = idx) THEN
        SET @sql = CONCAT('ALTER TABLE `', t, '` DROP INDEX `', idx, '`');
        PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
    END IF;
END$$

CREATE PROCEDURE wms_add_fk(IN t VARCHAR(64), IN fk VARCHAR(64), IN ddl TEXT)
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.table_constraints
                    WHERE table_schema = DATABASE() AND table_name = t AND constraint_name = fk) THEN
        SET @sql = CONCAT('ALTER TABLE `', t, '` ADD CONSTRAINT `', fk, '` ', ddl);
        PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
    END IF;
END$$

DELIMITER ;

-- =====================================================================
-- 1. PROVIDERS
-- =====================================================================

CREATE TABLE IF NOT EXISTS `providers` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `provider_code` VARCHAR(30)  NOT NULL,
  `business_name` VARCHAR(160) NOT NULL,
  `business_type` VARCHAR(60)  NOT NULL DEFAULT 'hotspot',
  `owner_name`    VARCHAR(140) DEFAULT NULL,
  `phone`         VARCHAR(30)  DEFAULT NULL,
  `email`         VARCHAR(160) DEFAULT NULL,
  `address`       VARCHAR(255) DEFAULT NULL,
  `city`          VARCHAR(80)  DEFAULT NULL,
  `region`        VARCHAR(80)  DEFAULT NULL,
  `country`       VARCHAR(80)  NOT NULL DEFAULT 'Tanzania',
  `logo`          VARCHAR(255) DEFAULT NULL,
  `description`   TEXT         DEFAULT NULL,
  `status`        ENUM('active','suspended','inactive') NOT NULL DEFAULT 'active',
  `timezone`      VARCHAR(60)  NOT NULL DEFAULT 'Africa/Dar_es_Salaam',
  `currency`      VARCHAR(8)   NOT NULL DEFAULT 'TSh',
  `currency_code` VARCHAR(8)   NOT NULL DEFAULT 'TZS',
  -- Future-ready only: no billing logic is built on these yet.
  `plan`                    VARCHAR(40) DEFAULT NULL,
  `subscription_status`     VARCHAR(40) DEFAULT NULL,
  `subscription_started_at` DATETIME    DEFAULT NULL,
  `subscription_expires_at` DATETIME    DEFAULT NULL,
  `notes`         TEXT         DEFAULT NULL,
  `created_by`    INT UNSIGNED DEFAULT NULL,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_providers_code` (`provider_code`),
  KEY `idx_providers_status` (`status`),
  KEY `idx_providers_name` (`business_name`),
  KEY `idx_providers_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- 2. ROLE / PERMISSION SCOPE
--    Roles and permissions are platform-level records, but each one now
--    declares whether it belongs to the platform or to a provider.
-- =====================================================================

CALL wms_add_column('roles', 'scope',
    "`scope` ENUM('platform','provider') NOT NULL DEFAULT 'provider' AFTER `slug`");
CALL wms_add_column('permissions', 'scope',
    "`scope` ENUM('platform','provider') NOT NULL DEFAULT 'provider' AFTER `slug`");
CALL wms_add_index('roles', 'idx_roles_scope', '`scope`');
CALL wms_add_index('permissions', 'idx_permissions_scope', '`scope`');

-- The platform owner role stays global; the operating roles become
-- provider-level, because that is where the day-to-day work happens.
UPDATE `roles` SET `scope` = 'platform' WHERE `slug` = 'super_admin';
UPDATE `roles` SET `scope` = 'provider'
 WHERE `slug` IN ('network_admin','sales_admin','support','provider_admin');

INSERT INTO `roles` (`name`,`slug`,`scope`,`description`,`is_system`) VALUES
  ('Provider Administrator','provider_admin','provider','Runs one Wi-Fi provider: customers, sales, network and their own staff.',1)
ON DUPLICATE KEY UPDATE `scope` = 'provider', `description` = VALUES(`description`);

-- Platform-only permissions.
INSERT INTO `permissions` (`slug`,`scope`,`name`,`group_name`) VALUES
  ('manage_providers','platform','Manage providers','Platform'),
  ('view_global_reports','platform','View platform-wide reports','Platform'),
  ('manage_all_routers','platform','Manage every router','Platform'),
  ('manage_all_users','platform','Manage every user account','Platform'),
  ('impersonate_provider','platform','View the system as a provider','Platform'),
  ('manage_platform_settings','platform','Manage platform settings','Platform')
ON DUPLICATE KEY UPDATE `scope` = 'platform', `name` = VALUES(`name`), `group_name` = VALUES(`group_name`);

-- Provider-level permissions that did not exist before.
INSERT INTO `permissions` (`slug`,`scope`,`name`,`group_name`) VALUES
  ('manage_provider_users','provider','Manage provider staff','Administration'),
  ('manage_provider_settings','provider','Manage provider settings','Administration')
ON DUPLICATE KEY UPDATE `scope` = 'provider';

-- Everything that already existed is provider-level work.
UPDATE `permissions` SET `scope` = 'provider'
 WHERE `slug` IN ('view_dashboard','manage_customers','manage_packages','manage_vouchers',
                  'manage_payments','manage_routers','manage_devices','manage_sessions',
                  'manage_reports','manage_alerts','view_audit_logs');
UPDATE `permissions` SET `scope` = 'platform'
 WHERE `slug` IN ('manage_staff','manage_settings');

-- Super Admin keeps everything.
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id FROM `roles` r CROSS JOIN `permissions` p WHERE r.slug = 'super_admin';

-- Provider Administrator gets the full provider-level set.
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id FROM `roles` r CROSS JOIN `permissions` p
 WHERE r.slug = 'provider_admin' AND p.scope = 'provider';

-- Provider staff must never hold platform permissions.
DELETE rp FROM `role_permissions` rp
  JOIN `roles` r ON r.id = rp.role_id
  JOIN `permissions` p ON p.id = rp.permission_id
 WHERE r.scope = 'provider' AND p.scope = 'platform';

-- =====================================================================
-- 3. provider_id ON TENANT-OWNED TABLES
--    users.provider_id NULL means "platform level" (the Super Admin).
--    Everywhere else NULL only exists transiently during migration.
-- =====================================================================

CALL wms_add_column('users',              'provider_id', '`provider_id` INT UNSIGNED DEFAULT NULL AFTER `id`');
CALL wms_add_column('customers',          'provider_id', '`provider_id` INT UNSIGNED DEFAULT NULL AFTER `id`');
CALL wms_add_column('packages',           'provider_id', '`provider_id` INT UNSIGNED DEFAULT NULL AFTER `id`');
CALL wms_add_column('bandwidth_profiles', 'provider_id', '`provider_id` INT UNSIGNED DEFAULT NULL AFTER `id`');
CALL wms_add_column('voucher_batches',    'provider_id', '`provider_id` INT UNSIGNED DEFAULT NULL AFTER `id`');
CALL wms_add_column('vouchers',           'provider_id', '`provider_id` INT UNSIGNED DEFAULT NULL AFTER `id`');
CALL wms_add_column('subscriptions',      'provider_id', '`provider_id` INT UNSIGNED DEFAULT NULL AFTER `id`');
CALL wms_add_column('routers',            'provider_id', '`provider_id` INT UNSIGNED DEFAULT NULL AFTER `id`');
CALL wms_add_column('access_points',      'provider_id', '`provider_id` INT UNSIGNED DEFAULT NULL AFTER `id`');
CALL wms_add_column('devices',            'provider_id', '`provider_id` INT UNSIGNED DEFAULT NULL AFTER `id`');
CALL wms_add_column('sessions',           'provider_id', '`provider_id` INT UNSIGNED DEFAULT NULL AFTER `id`');
CALL wms_add_column('usage_records',      'provider_id', '`provider_id` INT UNSIGNED DEFAULT NULL AFTER `id`');
CALL wms_add_column('payments',           'provider_id', '`provider_id` INT UNSIGNED DEFAULT NULL AFTER `id`');
CALL wms_add_column('transactions',       'provider_id', '`provider_id` INT UNSIGNED DEFAULT NULL AFTER `id`');
CALL wms_add_column('alerts',             'provider_id', '`provider_id` INT UNSIGNED DEFAULT NULL AFTER `id`');
CALL wms_add_column('notifications',      'provider_id', '`provider_id` INT UNSIGNED DEFAULT NULL AFTER `id`');
CALL wms_add_column('audit_logs',         'provider_id', '`provider_id` INT UNSIGNED DEFAULT NULL AFTER `id`');

-- Plain provider_id indexes.
CALL wms_add_index('users',              'idx_users_provider',    '`provider_id`');
CALL wms_add_index('customers',          'idx_cust_provider',     '`provider_id`');
CALL wms_add_index('packages',           'idx_pkg_provider',      '`provider_id`');
CALL wms_add_index('bandwidth_profiles', 'idx_bp_provider',       '`provider_id`');
CALL wms_add_index('voucher_batches',    'idx_batch_provider',    '`provider_id`');
CALL wms_add_index('vouchers',           'idx_vouch_provider',    '`provider_id`');
CALL wms_add_index('subscriptions',      'idx_subs_provider',     '`provider_id`');
CALL wms_add_index('routers',            'idx_router_provider',   '`provider_id`');
CALL wms_add_index('access_points',      'idx_ap_provider',       '`provider_id`');
CALL wms_add_index('devices',            'idx_dev_provider',      '`provider_id`');
CALL wms_add_index('sessions',           'idx_sess_provider',     '`provider_id`');
CALL wms_add_index('usage_records',      'idx_usage_provider',    '`provider_id`');
CALL wms_add_index('payments',           'idx_pay_provider',      '`provider_id`');
CALL wms_add_index('transactions',       'idx_txn_provider',      '`provider_id`');
CALL wms_add_index('alerts',             'idx_alert_provider',    '`provider_id`');
CALL wms_add_index('notifications',      'idx_notif_provider',    '`provider_id`');
CALL wms_add_index('audit_logs',         'idx_audit_provider',    '`provider_id`');

-- Composite indexes matching the queries the app actually runs.
CALL wms_add_index('customers',     'idx_cust_provider_status',   '`provider_id`,`status`');
CALL wms_add_index('customers',     'idx_cust_provider_created',  '`provider_id`,`created_at`');
CALL wms_add_index('packages',      'idx_pkg_provider_status',    '`provider_id`,`status`');
CALL wms_add_index('vouchers',      'idx_vouch_provider_status',  '`provider_id`,`status`');
CALL wms_add_index('vouchers',      'idx_vouch_provider_created', '`provider_id`,`created_at`');
CALL wms_add_index('payments',      'idx_pay_provider_status',    '`provider_id`,`status`');
CALL wms_add_index('payments',      'idx_pay_provider_created',   '`provider_id`,`created_at`');
CALL wms_add_index('sessions',      'idx_sess_provider_status',   '`provider_id`,`status`');
CALL wms_add_index('sessions',      'idx_sess_provider_started',  '`provider_id`,`started_at`');
CALL wms_add_index('devices',       'idx_dev_provider_status',    '`provider_id`,`status`');
CALL wms_add_index('usage_records', 'idx_usage_provider_date',    '`provider_id`,`record_date`');
CALL wms_add_index('usage_records', 'idx_usage_provider_cust',    '`provider_id`,`customer_id`');
CALL wms_add_index('routers',       'idx_router_provider_status', '`provider_id`,`status`');
CALL wms_add_index('alerts',        'idx_alert_provider_state',   '`provider_id`,`is_resolved`');
CALL wms_add_index('audit_logs',    'idx_audit_provider_created', '`provider_id`,`created_at`');

-- =====================================================================
-- 4. SETTINGS: platform values and per-provider values in one table
--    provider_id 0 means "platform". A real provider id means that
--    provider's own value, which overrides the platform default.
-- =====================================================================

CALL wms_add_column('settings', 'provider_id',
    '`provider_id` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `id`');
CALL wms_drop_index('settings', 'uq_settings_key');
CALL wms_add_index('settings', 'idx_settings_provider', '`provider_id`');

-- Composite uniqueness: one value per key per provider.
SET @has_uq := (SELECT COUNT(*) FROM information_schema.statistics
                 WHERE table_schema = DATABASE() AND table_name = 'settings'
                   AND index_name = 'uq_settings_provider_key');
SET @sql := IF(@has_uq = 0,
    'ALTER TABLE `settings` ADD UNIQUE KEY `uq_settings_provider_key` (`provider_id`,`setting_key`)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- =====================================================================
-- 5. DEFAULT PROVIDER + EXISTING DATA MIGRATION
--    Nothing is deleted. Every existing tenant record is handed to a
--    single "Default Wi-Fi Provider" so the system keeps working exactly
--    as it did, now inside a tenant boundary.
-- =====================================================================

INSERT INTO `providers`
  (`provider_code`,`business_name`,`business_type`,`owner_name`,`status`,`description`)
SELECT 'PRV-0001', 'Default Wi-Fi Provider', 'hotspot', 'Platform owner', 'active',
       'Created by the multi-provider upgrade. It owns every record that existed before the platform became multi-tenant. Rename it to your own business.'
WHERE NOT EXISTS (SELECT 1 FROM `providers` WHERE `provider_code` = 'PRV-0001');

SET @default_provider := (SELECT id FROM `providers` WHERE `provider_code` = 'PRV-0001' LIMIT 1);

UPDATE `customers`          SET `provider_id` = @default_provider WHERE `provider_id` IS NULL;
UPDATE `packages`           SET `provider_id` = @default_provider WHERE `provider_id` IS NULL;
UPDATE `bandwidth_profiles` SET `provider_id` = @default_provider WHERE `provider_id` IS NULL;
UPDATE `voucher_batches`    SET `provider_id` = @default_provider WHERE `provider_id` IS NULL;
UPDATE `vouchers`           SET `provider_id` = @default_provider WHERE `provider_id` IS NULL;
UPDATE `subscriptions`      SET `provider_id` = @default_provider WHERE `provider_id` IS NULL;
UPDATE `routers`            SET `provider_id` = @default_provider WHERE `provider_id` IS NULL;
UPDATE `access_points`      SET `provider_id` = @default_provider WHERE `provider_id` IS NULL;
UPDATE `devices`            SET `provider_id` = @default_provider WHERE `provider_id` IS NULL;
UPDATE `sessions`           SET `provider_id` = @default_provider WHERE `provider_id` IS NULL;
UPDATE `usage_records`      SET `provider_id` = @default_provider WHERE `provider_id` IS NULL;
UPDATE `payments`           SET `provider_id` = @default_provider WHERE `provider_id` IS NULL;
UPDATE `transactions`       SET `provider_id` = @default_provider WHERE `provider_id` IS NULL;
UPDATE `alerts`             SET `provider_id` = @default_provider WHERE `provider_id` IS NULL;

-- Staff: super admins stay global (provider_id NULL); everyone else joins
-- the default provider and becomes a provider user.
UPDATE `users` u
  JOIN `roles` r ON r.id = u.role_id
   SET u.`provider_id` = @default_provider
 WHERE u.`provider_id` IS NULL AND r.`slug` <> 'super_admin';

-- Audit history belongs to whoever wrote it.
UPDATE `audit_logs` a
  JOIN `users` u ON u.id = a.user_id
   SET a.`provider_id` = u.`provider_id`
 WHERE a.`provider_id` IS NULL AND u.`provider_id` IS NOT NULL;

UPDATE `notifications` n
  JOIN `users` u ON u.id = n.user_id
   SET n.`provider_id` = u.`provider_id`
 WHERE n.`provider_id` IS NULL AND u.`provider_id` IS NOT NULL;

-- Existing settings become the platform defaults (provider_id 0), which is
-- already the column default - nothing to move.

-- =====================================================================
-- 6. FOREIGN KEYS
--    Added last, once every row holds a valid provider_id.
--    Tenant records cascade with their provider; users are only detached.
-- =====================================================================

CALL wms_add_fk('users',              'fk_users_provider',    'FOREIGN KEY (`provider_id`) REFERENCES `providers` (`id`) ON DELETE SET NULL');
CALL wms_add_fk('customers',          'fk_cust_provider',     'FOREIGN KEY (`provider_id`) REFERENCES `providers` (`id`) ON DELETE CASCADE');
CALL wms_add_fk('packages',           'fk_pkg_provider',      'FOREIGN KEY (`provider_id`) REFERENCES `providers` (`id`) ON DELETE CASCADE');
CALL wms_add_fk('bandwidth_profiles', 'fk_bp_provider',       'FOREIGN KEY (`provider_id`) REFERENCES `providers` (`id`) ON DELETE CASCADE');
CALL wms_add_fk('voucher_batches',    'fk_batch_provider',    'FOREIGN KEY (`provider_id`) REFERENCES `providers` (`id`) ON DELETE CASCADE');
CALL wms_add_fk('vouchers',           'fk_vouch_provider',    'FOREIGN KEY (`provider_id`) REFERENCES `providers` (`id`) ON DELETE CASCADE');
CALL wms_add_fk('subscriptions',      'fk_subs_provider',     'FOREIGN KEY (`provider_id`) REFERENCES `providers` (`id`) ON DELETE CASCADE');
CALL wms_add_fk('routers',            'fk_router_provider',   'FOREIGN KEY (`provider_id`) REFERENCES `providers` (`id`) ON DELETE CASCADE');
CALL wms_add_fk('access_points',      'fk_ap_provider',       'FOREIGN KEY (`provider_id`) REFERENCES `providers` (`id`) ON DELETE CASCADE');
CALL wms_add_fk('devices',            'fk_dev_provider',      'FOREIGN KEY (`provider_id`) REFERENCES `providers` (`id`) ON DELETE CASCADE');
CALL wms_add_fk('sessions',           'fk_sess_provider',     'FOREIGN KEY (`provider_id`) REFERENCES `providers` (`id`) ON DELETE CASCADE');
CALL wms_add_fk('usage_records',      'fk_usage_provider',    'FOREIGN KEY (`provider_id`) REFERENCES `providers` (`id`) ON DELETE CASCADE');
CALL wms_add_fk('payments',           'fk_pay_provider',      'FOREIGN KEY (`provider_id`) REFERENCES `providers` (`id`) ON DELETE CASCADE');
CALL wms_add_fk('alerts',             'fk_alert_provider',    'FOREIGN KEY (`provider_id`) REFERENCES `providers` (`id`) ON DELETE CASCADE');
CALL wms_add_fk('audit_logs',         'fk_audit_provider',    'FOREIGN KEY (`provider_id`) REFERENCES `providers` (`id`) ON DELETE SET NULL');
CALL wms_add_fk('providers',          'fk_providers_creator', 'FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL');

-- ---------------------------------------------------------------------
-- Clean up the helpers
-- ---------------------------------------------------------------------

DROP PROCEDURE IF EXISTS wms_add_column;
DROP PROCEDURE IF EXISTS wms_add_index;
DROP PROCEDURE IF EXISTS wms_add_fk;
DROP PROCEDURE IF EXISTS wms_drop_index;

-- ---------------------------------------------------------------------
-- Verification (read the output before you trust the migration)
-- ---------------------------------------------------------------------

SELECT 'providers'  AS what, COUNT(*) AS rows_ FROM providers
UNION ALL SELECT 'customers without a provider', COUNT(*) FROM customers WHERE provider_id IS NULL
UNION ALL SELECT 'packages without a provider',  COUNT(*) FROM packages  WHERE provider_id IS NULL
UNION ALL SELECT 'vouchers without a provider',  COUNT(*) FROM vouchers  WHERE provider_id IS NULL
UNION ALL SELECT 'routers without a provider',   COUNT(*) FROM routers   WHERE provider_id IS NULL
UNION ALL SELECT 'payments without a provider',  COUNT(*) FROM payments  WHERE provider_id IS NULL
UNION ALL SELECT 'platform users (super admin)', COUNT(*) FROM users     WHERE provider_id IS NULL
UNION ALL SELECT 'provider users',               COUNT(*) FROM users     WHERE provider_id IS NOT NULL;
