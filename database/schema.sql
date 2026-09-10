-- =====================================================================
--  WMS - Wi-Fi Management System
--  Database schema (MySQL 5.7+ / MariaDB 10.3+)
--  Engine: InnoDB   Charset: utf8mb4
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- ACCESS CONTROL
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `roles` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`        VARCHAR(80)  NOT NULL,
  `slug`        VARCHAR(80)  NOT NULL,
  `description` VARCHAR(255) DEFAULT NULL,
  `is_system`   TINYINT(1)   NOT NULL DEFAULT 0,
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_roles_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `permissions` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `slug`        VARCHAR(80)  NOT NULL,
  `name`        VARCHAR(120) NOT NULL,
  `group_name`  VARCHAR(60)  NOT NULL DEFAULT 'General',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_permissions_slug` (`slug`),
  KEY `idx_permissions_group` (`group_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `role_permissions` (
  `role_id`       INT UNSIGNED NOT NULL,
  `permission_id` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`role_id`,`permission_id`),
  KEY `idx_rp_permission` (`permission_id`),
  CONSTRAINT `fk_rp_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_rp_permission` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `users` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `role_id`        INT UNSIGNED NOT NULL,
  `full_name`      VARCHAR(120) NOT NULL,
  `username`       VARCHAR(60)  NOT NULL,
  `email`          VARCHAR(160) NOT NULL,
  `phone`          VARCHAR(30)  DEFAULT NULL,
  `password_hash`  VARCHAR(255) NOT NULL,
  `status`         ENUM('active','suspended','inactive') NOT NULL DEFAULT 'active',
  `avatar`         VARCHAR(255) DEFAULT NULL,
  `remember_token` VARCHAR(255) DEFAULT NULL,
  `remember_expires_at` DATETIME DEFAULT NULL,
  `last_login_at`  DATETIME     DEFAULT NULL,
  `last_login_ip`  VARCHAR(45)  DEFAULT NULL,
  `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_username` (`username`),
  UNIQUE KEY `uq_users_email` (`email`),
  KEY `idx_users_status` (`status`),
  KEY `idx_users_role` (`role_id`),
  CONSTRAINT `fk_users_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Brute force / rate limiting
CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `identifier` VARCHAR(160) NOT NULL,
  `ip_address` VARCHAR(45)  NOT NULL,
  `scope`      ENUM('admin','customer') NOT NULL DEFAULT 'admin',
  `success`    TINYINT(1)   NOT NULL DEFAULT 0,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_la_identifier` (`identifier`,`created_at`),
  KEY `idx_la_ip` (`ip_address`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- CUSTOMERS
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `customers` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `customer_code` VARCHAR(30)  NOT NULL,
  `full_name`     VARCHAR(140) NOT NULL,
  `phone`         VARCHAR(30)  NOT NULL,
  `email`         VARCHAR(160) DEFAULT NULL,
  `username`      VARCHAR(60)  DEFAULT NULL,
  `password_hash` VARCHAR(255) DEFAULT NULL,
  `status`        ENUM('active','suspended','pending','blocked') NOT NULL DEFAULT 'active',
  `customer_type` ENUM('individual','business','hotspot','staff') NOT NULL DEFAULT 'individual',
  `address`       VARCHAR(255) DEFAULT NULL,
  `notes`         TEXT         DEFAULT NULL,
  `created_by`    INT UNSIGNED DEFAULT NULL,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_customers_code` (`customer_code`),
  UNIQUE KEY `uq_customers_username` (`username`),
  KEY `idx_customers_phone` (`phone`),
  KEY `idx_customers_email` (`email`),
  KEY `idx_customers_status` (`status`),
  KEY `idx_customers_type` (`customer_type`),
  KEY `idx_customers_created` (`created_at`),
  CONSTRAINT `fk_customers_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- NETWORK EQUIPMENT
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `routers` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`           VARCHAR(120) NOT NULL,
  `ip_address`     VARCHAR(45)  NOT NULL,
  `api_port`       SMALLINT UNSIGNED NOT NULL DEFAULT 8728,
  `use_tls`        TINYINT(1)   NOT NULL DEFAULT 0,
  `api_username`   VARCHAR(80)  NOT NULL,
  `api_password`   VARBINARY(512) DEFAULT NULL,
  `hotspot_server` VARCHAR(80)  DEFAULT NULL,
  `hotspot_profile` VARCHAR(80) DEFAULT NULL,
  `default_user_profile` VARCHAR(80) DEFAULT NULL,
  `routeros_version` VARCHAR(40) DEFAULT NULL,
  `identity`       VARCHAR(120) DEFAULT NULL,
  `board`          VARCHAR(80)  DEFAULT NULL,
  `location`       VARCHAR(160) DEFAULT NULL,
  `mode`           ENUM('live','demo') NOT NULL DEFAULT 'demo',
  `status`         ENUM('online','offline','unknown','degraded','disabled','retired') NOT NULL DEFAULT 'unknown',
  `cpu_load`       TINYINT UNSIGNED DEFAULT NULL,
  `memory_used_pct` TINYINT UNSIGNED DEFAULT NULL,
  `uptime`         VARCHAR(60)  DEFAULT NULL,
  `active_users`   INT UNSIGNED NOT NULL DEFAULT 0,
  `last_seen_at`   DATETIME     DEFAULT NULL,
  `last_sync_at`   DATETIME     DEFAULT NULL,
  `failed_checks`  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `last_error`     VARCHAR(255) DEFAULT NULL,
  `notes`          TEXT         DEFAULT NULL,
  `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at`     DATETIME     DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_routers_status` (`status`),
  KEY `idx_routers_ip` (`ip_address`),
  KEY `idx_routers_last_seen` (`last_seen_at`),
  KEY `idx_routers_deleted` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `access_points` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `router_id`       INT UNSIGNED DEFAULT NULL,
  `name`            VARCHAR(120) NOT NULL,
  `location`        VARCHAR(160) DEFAULT NULL,
  `ip_address`      VARCHAR(45)  DEFAULT NULL,
  `mac_address`     VARCHAR(20)  DEFAULT NULL,
  `ssid`            VARCHAR(80)  DEFAULT NULL,
  `model`           VARCHAR(80)  DEFAULT NULL,
  `status`          ENUM('online','offline','unknown','disabled','retired') NOT NULL DEFAULT 'unknown',
  `monitoring_source` ENUM('router','snmp','controller','vendor_api','manual') NOT NULL DEFAULT 'manual',
  `health`          TINYINT UNSIGNED NOT NULL DEFAULT 100,
  `connected_users` INT UNSIGNED NOT NULL DEFAULT 0,
  `last_seen_at`    DATETIME     DEFAULT NULL,
  `last_status_change_at` DATETIME DEFAULT NULL,
  `notes`           TEXT         DEFAULT NULL,
  `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at`      DATETIME     DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ap_router` (`router_id`),
  KEY `idx_ap_status` (`status`),
  KEY `idx_ap_mac` (`mac_address`),
  KEY `idx_ap_last_seen` (`last_seen_at`),
  KEY `idx_ap_deleted` (`deleted_at`),
  CONSTRAINT `fk_ap_router` FOREIGN KEY (`router_id`) REFERENCES `routers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `bandwidth_profiles` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`           VARCHAR(100) NOT NULL,
  `download_kbps`  INT UNSIGNED NOT NULL,
  `upload_kbps`    INT UNSIGNED NOT NULL,
  `burst_enabled`  TINYINT(1)   NOT NULL DEFAULT 0,
  `burst_download_kbps` INT UNSIGNED DEFAULT NULL,
  `burst_upload_kbps`   INT UNSIGNED DEFAULT NULL,
  `burst_time`     SMALLINT UNSIGNED NOT NULL DEFAULT 8,
  `priority`       TINYINT UNSIGNED NOT NULL DEFAULT 8,
  `description`    VARCHAR(255) DEFAULT NULL,
  `mikrotik_name`  VARCHAR(80)  DEFAULT NULL,
  `synced_at`      DATETIME     DEFAULT NULL,
  `status`         ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_bp_name` (`name`),
  KEY `idx_bp_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- PACKAGES
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `packages` (
  `id`                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`                 VARCHAR(120) NOT NULL,
  `code`                 VARCHAR(40)  NOT NULL,
  `price`                DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `duration_value`       INT UNSIGNED NOT NULL DEFAULT 1,
  `duration_unit`        ENUM('minutes','hours','days','months') NOT NULL DEFAULT 'hours',
  `data_limit_mb`        BIGINT UNSIGNED DEFAULT NULL COMMENT 'NULL = unlimited data',
  `download_kbps`        INT UNSIGNED NOT NULL DEFAULT 2048,
  `upload_kbps`          INT UNSIGNED NOT NULL DEFAULT 1024,
  `device_limit`         TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `bandwidth_profile_id` INT UNSIGNED DEFAULT NULL,
  `description`          VARCHAR(255) DEFAULT NULL,
  `is_featured`          TINYINT(1)   NOT NULL DEFAULT 0,
  `sort_order`           SMALLINT     NOT NULL DEFAULT 0,
  `status`               ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_at`           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_packages_code` (`code`),
  KEY `idx_packages_status` (`status`),
  KEY `idx_packages_price` (`price`),
  CONSTRAINT `fk_packages_bp` FOREIGN KEY (`bandwidth_profile_id`) REFERENCES `bandwidth_profiles` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- VOUCHERS
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `voucher_batches` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `batch_code`  VARCHAR(40)  NOT NULL,
  `name`        VARCHAR(140) DEFAULT NULL,
  `package_id`  INT UNSIGNED NOT NULL,
  `router_id`   INT UNSIGNED DEFAULT NULL,
  `quantity`    INT UNSIGNED NOT NULL DEFAULT 0,
  `prefix`      VARCHAR(12)  DEFAULT NULL,
  `code_length` TINYINT UNSIGNED NOT NULL DEFAULT 8,
  `price`       DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `valid_until` DATETIME     DEFAULT NULL,
  `notes`       VARCHAR(255) DEFAULT NULL,
  `created_by`  INT UNSIGNED DEFAULT NULL,
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_batches_code` (`batch_code`),
  KEY `idx_batches_package` (`package_id`),
  KEY `idx_batches_created` (`created_at`),
  CONSTRAINT `fk_batches_package` FOREIGN KEY (`package_id`) REFERENCES `packages` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_batches_router` FOREIGN KEY (`router_id`) REFERENCES `routers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_batches_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `vouchers` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`           VARCHAR(40)  NOT NULL,
  `batch_id`       INT UNSIGNED DEFAULT NULL,
  `package_id`     INT UNSIGNED NOT NULL,
  `router_id`      INT UNSIGNED DEFAULT NULL,
  `customer_id`    INT UNSIGNED DEFAULT NULL,
  `price`          DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `duration_value` INT UNSIGNED NOT NULL DEFAULT 1,
  `duration_unit`  ENUM('minutes','hours','days','months') NOT NULL DEFAULT 'hours',
  `data_limit_mb`  BIGINT UNSIGNED DEFAULT NULL,
  `download_kbps`  INT UNSIGNED NOT NULL DEFAULT 2048,
  `upload_kbps`    INT UNSIGNED NOT NULL DEFAULT 1024,
  `device_limit`   TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `data_used_mb`   BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `status`         ENUM('available','activated','active','expired','exhausted','suspended','cancelled') NOT NULL DEFAULT 'available',
  `activated_at`   DATETIME     DEFAULT NULL,
  `expires_at`     DATETIME     DEFAULT NULL,
  `valid_until`    DATETIME     DEFAULT NULL COMMENT 'Must be activated before this date',
  `created_by`     INT UNSIGNED DEFAULT NULL,
  `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_vouchers_code` (`code`),
  KEY `idx_vouchers_status` (`status`),
  KEY `idx_vouchers_batch` (`batch_id`),
  KEY `idx_vouchers_package` (`package_id`),
  KEY `idx_vouchers_customer` (`customer_id`),
  KEY `idx_vouchers_created` (`created_at`),
  KEY `idx_vouchers_expires` (`expires_at`),
  CONSTRAINT `fk_vouchers_batch` FOREIGN KEY (`batch_id`) REFERENCES `voucher_batches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_vouchers_package` FOREIGN KEY (`package_id`) REFERENCES `packages` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_vouchers_router` FOREIGN KEY (`router_id`) REFERENCES `routers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_vouchers_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- SUBSCRIPTIONS / PAYMENTS
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `payments` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `transaction_ref` VARCHAR(60)  NOT NULL,
  `customer_id`     INT UNSIGNED DEFAULT NULL,
  `package_id`      INT UNSIGNED DEFAULT NULL,
  `voucher_id`      BIGINT UNSIGNED DEFAULT NULL,
  `subscription_id` BIGINT UNSIGNED DEFAULT NULL,
  `amount`          DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `currency`        VARCHAR(8)   NOT NULL DEFAULT 'TZS',
  `method`          VARCHAR(40)  NOT NULL DEFAULT 'mobile_money',
  `provider`        VARCHAR(40)  NOT NULL DEFAULT 'demo',
  `provider_ref`    VARCHAR(80)  DEFAULT NULL COMMENT 'Provider order id',
  `provider_txn_id` VARCHAR(80)  DEFAULT NULL,
  `channel`         VARCHAR(40)  DEFAULT NULL,
  `payer_name`      VARCHAR(140) DEFAULT NULL,
  `payer_phone`     VARCHAR(30)  DEFAULT NULL,
  `payer_email`     VARCHAR(160) DEFAULT NULL,
  `status`          ENUM('pending','successful','failed','cancelled','refunded') NOT NULL DEFAULT 'pending',
  `failure_reason`  VARCHAR(255) DEFAULT NULL,
  `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `completed_at`    DATETIME     DEFAULT NULL,
  `updated_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_payments_ref` (`transaction_ref`),
  KEY `idx_payments_provider_ref` (`provider_ref`),
  KEY `idx_payments_status` (`status`),
  KEY `idx_payments_customer` (`customer_id`),
  KEY `idx_payments_created` (`created_at`),
  KEY `idx_payments_phone` (`payer_phone`),
  CONSTRAINT `fk_payments_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_payments_package` FOREIGN KEY (`package_id`) REFERENCES `packages` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `subscriptions` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `customer_id`   INT UNSIGNED DEFAULT NULL,
  `package_id`    INT UNSIGNED NOT NULL,
  `voucher_id`    BIGINT UNSIGNED DEFAULT NULL,
  `payment_id`    BIGINT UNSIGNED DEFAULT NULL,
  `start_at`      DATETIME     NOT NULL,
  `end_at`        DATETIME     NOT NULL,
  `data_limit_mb` BIGINT UNSIGNED DEFAULT NULL,
  `data_used_mb`  BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `device_limit`  TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `status`        ENUM('active','expired','exhausted','suspended','cancelled') NOT NULL DEFAULT 'active',
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_subs_customer` (`customer_id`),
  KEY `idx_subs_status` (`status`),
  KEY `idx_subs_end` (`end_at`),
  KEY `idx_subs_voucher` (`voucher_id`),
  CONSTRAINT `fk_subs_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_subs_package` FOREIGN KEY (`package_id`) REFERENCES `packages` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_subs_voucher` FOREIGN KEY (`voucher_id`) REFERENCES `vouchers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_subs_payment` FOREIGN KEY (`payment_id`) REFERENCES `payments` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `transactions` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `payment_id`    BIGINT UNSIGNED DEFAULT NULL,
  `type`          ENUM('charge','callback','status_check','refund','payout') NOT NULL DEFAULT 'charge',
  `provider`      VARCHAR(40)  NOT NULL DEFAULT 'demo',
  `provider_ref`  VARCHAR(80)  DEFAULT NULL,
  `amount`        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `status`        VARCHAR(40)  NOT NULL DEFAULT 'pending',
  `message`       VARCHAR(255) DEFAULT NULL,
  `raw_response`  TEXT         DEFAULT NULL,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_txn_payment` (`payment_id`),
  KEY `idx_txn_ref` (`provider_ref`),
  KEY `idx_txn_created` (`created_at`),
  CONSTRAINT `fk_txn_payment` FOREIGN KEY (`payment_id`) REFERENCES `payments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- DEVICES / SESSIONS / USAGE
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `devices` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `customer_id`     INT UNSIGNED DEFAULT NULL,
  `voucher_id`      BIGINT UNSIGNED DEFAULT NULL,
  `name`            VARCHAR(120) DEFAULT NULL,
  `device_type`     ENUM('phone','laptop','tablet','desktop','smart_tv','other') NOT NULL DEFAULT 'other',
  `mac_address`     VARCHAR(20)  NOT NULL,
  `ip_address`      VARCHAR(45)  DEFAULT NULL,
  `router_id`       INT UNSIGNED DEFAULT NULL,
  `access_point_id` INT UNSIGNED DEFAULT NULL,
  `user_agent`      VARCHAR(255) DEFAULT NULL,
  `status`          ENUM('active','idle','blocked') NOT NULL DEFAULT 'active',
  `source`          ENUM('live','demo') NOT NULL DEFAULT 'demo',
  `first_seen_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_seen_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_devices_mac_customer` (`mac_address`,`customer_id`),
  KEY `idx_devices_mac` (`mac_address`),
  KEY `idx_devices_ip` (`ip_address`),
  KEY `idx_devices_customer` (`customer_id`),
  KEY `idx_devices_status` (`status`),
  KEY `idx_devices_voucher` (`voucher_id`),
  CONSTRAINT `fk_devices_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_devices_voucher` FOREIGN KEY (`voucher_id`) REFERENCES `vouchers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_devices_router` FOREIGN KEY (`router_id`) REFERENCES `routers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_devices_ap` FOREIGN KEY (`access_point_id`) REFERENCES `access_points` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sessions` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `customer_id`     INT UNSIGNED DEFAULT NULL,
  `voucher_id`      BIGINT UNSIGNED DEFAULT NULL,
  `subscription_id` BIGINT UNSIGNED DEFAULT NULL,
  `device_id`       BIGINT UNSIGNED DEFAULT NULL,
  `username`        VARCHAR(80)  DEFAULT NULL,
  `mac_address`     VARCHAR(20)  DEFAULT NULL,
  `ip_address`      VARCHAR(45)  DEFAULT NULL,
  `router_id`       INT UNSIGNED DEFAULT NULL,
  `access_point_id` INT UNSIGNED DEFAULT NULL,
  `started_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ended_at`        DATETIME     DEFAULT NULL,
  `duration_seconds` INT UNSIGNED NOT NULL DEFAULT 0,
  `download_bytes`  BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `upload_bytes`    BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `status`          ENUM('active','closed','blocked','disconnected') NOT NULL DEFAULT 'active',
  `source`          ENUM('live','demo') NOT NULL DEFAULT 'demo',
  `terminate_cause` VARCHAR(80)  DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_sessions_status` (`status`),
  KEY `idx_sessions_customer` (`customer_id`),
  KEY `idx_sessions_voucher` (`voucher_id`),
  KEY `idx_sessions_mac` (`mac_address`),
  KEY `idx_sessions_ip` (`ip_address`),
  KEY `idx_sessions_started` (`started_at`),
  KEY `idx_sessions_router` (`router_id`),
  CONSTRAINT `fk_sessions_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_sessions_voucher` FOREIGN KEY (`voucher_id`) REFERENCES `vouchers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_sessions_device` FOREIGN KEY (`device_id`) REFERENCES `devices` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_sessions_router` FOREIGN KEY (`router_id`) REFERENCES `routers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_sessions_ap` FOREIGN KEY (`access_point_id`) REFERENCES `access_points` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `usage_records` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `customer_id`    INT UNSIGNED DEFAULT NULL,
  `voucher_id`     BIGINT UNSIGNED DEFAULT NULL,
  `subscription_id` BIGINT UNSIGNED DEFAULT NULL,
  `session_id`     BIGINT UNSIGNED DEFAULT NULL,
  `router_id`      INT UNSIGNED DEFAULT NULL,
  `record_date`    DATE         NOT NULL,
  `download_bytes` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `upload_bytes`   BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `total_bytes`    BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `duration_seconds` INT UNSIGNED NOT NULL DEFAULT 0,
  `source`         ENUM('live','demo') NOT NULL DEFAULT 'demo',
  `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_usage_date` (`record_date`),
  KEY `idx_usage_customer` (`customer_id`,`record_date`),
  KEY `idx_usage_voucher` (`voucher_id`),
  KEY `idx_usage_session` (`session_id`),
  CONSTRAINT `fk_usage_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_usage_session` FOREIGN KEY (`session_id`) REFERENCES `sessions` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- ALERTS / NOTIFICATIONS / AUDIT / SETTINGS
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `alerts` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `type`        VARCHAR(60)  NOT NULL,
  `severity`    ENUM('info','warning','danger','critical') NOT NULL DEFAULT 'info',
  `title`       VARCHAR(160) NOT NULL,
  `message`     TEXT         DEFAULT NULL,
  `source_type` VARCHAR(40)  DEFAULT NULL,
  `source_id`   BIGINT UNSIGNED DEFAULT NULL,
  `is_read`     TINYINT(1)   NOT NULL DEFAULT 0,
  `is_resolved` TINYINT(1)   NOT NULL DEFAULT 0,
  `resolved_by` INT UNSIGNED DEFAULT NULL,
  `resolved_at` DATETIME     DEFAULT NULL,
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_alerts_severity` (`severity`),
  KEY `idx_alerts_read` (`is_read`),
  KEY `idx_alerts_resolved` (`is_resolved`),
  KEY `idx_alerts_created` (`created_at`),
  KEY `idx_alerts_type` (`type`),
  CONSTRAINT `fk_alerts_user` FOREIGN KEY (`resolved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `notifications` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    INT UNSIGNED DEFAULT NULL,
  `title`      VARCHAR(160) NOT NULL,
  `message`    VARCHAR(255) DEFAULT NULL,
  `type`       VARCHAR(40)  NOT NULL DEFAULT 'info',
  `link`       VARCHAR(255) DEFAULT NULL,
  `is_read`    TINYINT(1)   NOT NULL DEFAULT 0,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_notif_user` (`user_id`,`is_read`),
  CONSTRAINT `fk_notif_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `audit_logs` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     INT UNSIGNED DEFAULT NULL,
  `actor_type`  ENUM('user','customer','system','api') NOT NULL DEFAULT 'user',
  `actor_name`  VARCHAR(120) DEFAULT NULL,
  `action`      VARCHAR(80)  NOT NULL,
  `entity_type` VARCHAR(60)  DEFAULT NULL,
  `entity_id`   BIGINT UNSIGNED DEFAULT NULL,
  `description` VARCHAR(255) DEFAULT NULL,
  `ip_address`  VARCHAR(45)  DEFAULT NULL,
  `user_agent`  VARCHAR(255) DEFAULT NULL,
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_audit_user` (`user_id`),
  KEY `idx_audit_action` (`action`),
  KEY `idx_audit_entity` (`entity_type`,`entity_id`),
  KEY `idx_audit_created` (`created_at`),
  CONSTRAINT `fk_audit_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `settings` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `setting_key`   VARCHAR(80)  NOT NULL,
  `setting_value` TEXT         DEFAULT NULL,
  `setting_group` VARCHAR(40)  NOT NULL DEFAULT 'general',
  `value_type`    ENUM('string','int','bool','json','secret') NOT NULL DEFAULT 'string',
  `label`         VARCHAR(140) DEFAULT NULL,
  `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_settings_key` (`setting_key`),
  KEY `idx_settings_group` (`setting_group`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- SYSTEM DATA
-- Roles and permissions are part of the schema, not demo data: the
-- application cannot authorise anybody without them. Fixed ids are used
-- so later migrations and seeds can reference them reliably.
--
-- The multi-provider upgrade adds the `scope` column, the platform-only
-- permissions and the Provider Administrator role on top of these.
-- ---------------------------------------------------------------------

INSERT INTO `roles` (`id`,`name`,`slug`,`description`,`is_system`) VALUES
  (1,'Super Admin','super_admin','Platform owner. Full, unrestricted access to every provider.',1),
  (2,'Network Administrator','network_admin','Routers, access points, sessions, devices and bandwidth.',1),
  (3,'Sales Administrator','sales_admin','Packages, vouchers, customers, payments and revenue reports.',1),
  (4,'Support','support','Read-mostly access for helping customers get online.',1)
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

INSERT INTO `permissions` (`id`,`slug`,`name`,`group_name`) VALUES
  (1,'view_dashboard','View dashboard','Dashboard'),
  (2,'manage_customers','Manage customers','Customers'),
  (3,'manage_packages','Manage packages','Sales'),
  (4,'manage_vouchers','Manage vouchers','Sales'),
  (5,'manage_payments','Manage payments','Sales'),
  (6,'manage_routers','Manage routers & access points','Network'),
  (7,'manage_devices','Manage devices','Network'),
  (8,'manage_sessions','Manage sessions','Network'),
  (9,'manage_reports','View & export reports','Reports'),
  (10,'manage_alerts','Manage alerts','Reports'),
  (11,'view_audit_logs','View audit logs','Administration'),
  (12,'manage_staff','Manage staff & roles','Administration'),
  (13,'manage_settings','Manage settings','Administration')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- Super Admin holds everything; the operating roles hold their own slice.
INSERT IGNORE INTO `role_permissions` (`role_id`,`permission_id`)
SELECT 1, id FROM `permissions`;

INSERT IGNORE INTO `role_permissions` (`role_id`,`permission_id`)
SELECT 2, id FROM `permissions`
 WHERE slug IN ('view_dashboard','manage_customers','manage_routers','manage_devices',
                'manage_sessions','manage_reports','manage_alerts');

INSERT IGNORE INTO `role_permissions` (`role_id`,`permission_id`)
SELECT 3, id FROM `permissions`
 WHERE slug IN ('view_dashboard','manage_customers','manage_packages','manage_vouchers',
                'manage_payments','manage_reports');

INSERT IGNORE INTO `role_permissions` (`role_id`,`permission_id`)
SELECT 4, id FROM `permissions`
 WHERE slug IN ('view_dashboard','manage_customers','manage_vouchers','manage_sessions','manage_alerts');

SET FOREIGN_KEY_CHECKS = 1;
