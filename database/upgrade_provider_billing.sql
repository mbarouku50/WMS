-- =====================================================================
--  WMS - Provider wallets, withdrawals and platform billing
--  ---------------------------------------------------------------------
--  Adds the money layer on top of the multi-provider platform:
--
--    * every provider has a wallet, credited when their customers pay
--    * providers can withdraw their balance to a bank or mobile wallet
--    * the platform charges each provider a recurring fee, with a
--      start date agreed per provider (a grace period)
--    * mobile-money selling is OFF by default: a provider sells through
--      vouchers until the platform owner allows it
--
--  Money model
--  -----------
--  Customer payments land in the PLATFORM's SonicPesa merchant account,
--  because the API keys belong to the platform. WMS then credits the
--  selling provider's wallet, and settles to them on withdrawal. The
--  platform fee is taken from the same wallet.
--
--  Safe to run more than once.
--  Run AFTER upgrade_multi_provider.sql.
-- =====================================================================

SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS wms_bill_add_column;
DROP PROCEDURE IF EXISTS wms_bill_add_index;

DELIMITER $$

CREATE PROCEDURE wms_bill_add_column(IN t VARCHAR(64), IN c VARCHAR(64), IN ddl TEXT)
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                    WHERE table_schema = DATABASE() AND table_name = t AND column_name = c) THEN
        SET @sql = CONCAT('ALTER TABLE `', t, '` ADD COLUMN ', ddl);
        PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
    END IF;
END$$

CREATE PROCEDURE wms_bill_add_index(IN t VARCHAR(64), IN idx VARCHAR(64), IN cols TEXT)
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.statistics
                    WHERE table_schema = DATABASE() AND table_name = t AND index_name = idx) THEN
        SET @sql = CONCAT('ALTER TABLE `', t, '` ADD INDEX `', idx, '` (', cols, ')');
        PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
    END IF;
END$$

DELIMITER ;

-- ---------------------------------------------------------------------
-- 1. PROVIDER COLUMNS
-- ---------------------------------------------------------------------

-- Selling channel: vouchers always work; mobile money is a privilege the
-- platform owner grants, and can withdraw again.
CALL wms_bill_add_column('providers', 'mobile_money_enabled',
    "`mobile_money_enabled` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '0 = vouchers only, 1 = customers may pay by mobile money' AFTER `status`");
CALL wms_bill_add_column('providers', 'mobile_money_changed_at',
    "`mobile_money_changed_at` DATETIME DEFAULT NULL AFTER `mobile_money_enabled`");

-- Wallet. balance is a cached running total; wallet_transactions is the
-- authority and can always be replayed to rebuild it.
CALL wms_bill_add_column('providers', 'wallet_balance',
    "`wallet_balance` DECIMAL(14,2) NOT NULL DEFAULT 0.00 COMMENT 'Available to withdraw' AFTER `currency_code`");
CALL wms_bill_add_column('providers', 'wallet_held',
    "`wallet_held` DECIMAL(14,2) NOT NULL DEFAULT 0.00 COMMENT 'Reserved against pending withdrawals' AFTER `wallet_balance`");
CALL wms_bill_add_column('providers', 'lifetime_earned',
    "`lifetime_earned` DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER `wallet_held`");
CALL wms_bill_add_column('providers', 'lifetime_withdrawn',
    "`lifetime_withdrawn` DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER `lifetime_earned`");

-- Platform fee, agreed per provider when they are registered.
CALL wms_bill_add_column('providers', 'platform_fee_amount',
    "`platform_fee_amount` DECIMAL(12,2) NOT NULL DEFAULT 10000.00 COMMENT 'Charged each cycle' AFTER `lifetime_withdrawn`");
CALL wms_bill_add_column('providers', 'platform_fee_cycle_months',
    "`platform_fee_cycle_months` TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '1 = monthly' AFTER `platform_fee_amount`");
CALL wms_bill_add_column('providers', 'billing_starts_on',
    "`billing_starts_on` DATE DEFAULT NULL COMMENT 'First day the fee applies - the agreed grace period ends here' AFTER `platform_fee_cycle_months`");
CALL wms_bill_add_column('providers', 'billing_next_due_on',
    "`billing_next_due_on` DATE DEFAULT NULL COMMENT 'When the next invoice will be raised' AFTER `billing_starts_on`");
CALL wms_bill_add_column('providers', 'billing_status',
    "`billing_status` ENUM('grace','current','due','overdue','exempt') NOT NULL DEFAULT 'grace' AFTER `billing_next_due_on`");

-- Where this provider wants their money sent.
CALL wms_bill_add_column('providers', 'payout_method',
    "`payout_method` VARCHAR(40) DEFAULT NULL COMMENT 'M-Pesa, Tigo Pesa, CRDB Bank, ...' AFTER `billing_status`");
CALL wms_bill_add_column('providers', 'payout_account_number',
    "`payout_account_number` VARCHAR(60) DEFAULT NULL AFTER `payout_method`");
CALL wms_bill_add_column('providers', 'payout_account_name',
    "`payout_account_name` VARCHAR(140) DEFAULT NULL AFTER `payout_account_number`");

CALL wms_bill_add_index('providers', 'idx_providers_billing', '`billing_status`,`billing_next_due_on`');
CALL wms_bill_add_index('providers', 'idx_providers_momo', '`mobile_money_enabled`');

-- ---------------------------------------------------------------------
-- 2. WALLET LEDGER
--    Append only. Every movement of a provider's money is a row here,
--    carrying the balance it produced, so the wallet can be audited.
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `wallet_transactions` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `provider_id`   INT UNSIGNED NOT NULL,
  `type`          ENUM('sale','platform_fee','withdrawal','withdrawal_reversal','refund','adjustment')
                  NOT NULL,
  `direction`     ENUM('credit','debit') NOT NULL,
  `amount`        DECIMAL(14,2) NOT NULL,
  `balance_after` DECIMAL(14,2) NOT NULL,
  `currency`      VARCHAR(8) NOT NULL DEFAULT 'TZS',
  `reference`     VARCHAR(60)  DEFAULT NULL COMMENT 'Payment ref, withdrawal id, invoice number',
  `payment_id`    BIGINT UNSIGNED DEFAULT NULL,
  `withdrawal_id` BIGINT UNSIGNED DEFAULT NULL,
  `invoice_id`    BIGINT UNSIGNED DEFAULT NULL,
  `description`   VARCHAR(255) DEFAULT NULL,
  `created_by`    INT UNSIGNED DEFAULT NULL COMMENT 'Staff member for manual adjustments',
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_wt_provider` (`provider_id`,`created_at`),
  KEY `idx_wt_type` (`provider_id`,`type`),
  KEY `idx_wt_payment` (`payment_id`),
  KEY `idx_wt_created` (`created_at`),
  CONSTRAINT `fk_wt_provider` FOREIGN KEY (`provider_id`) REFERENCES `providers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_wt_payment` FOREIGN KEY (`payment_id`) REFERENCES `payments` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_wt_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 3. WITHDRAWALS
--    A provider asking for their money. Funds move from balance to held
--    on request, and leave entirely once the payout succeeds.
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `withdrawals` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `provider_id`     INT UNSIGNED NOT NULL,
  `reference`       VARCHAR(40) NOT NULL,
  `amount`          DECIMAL(14,2) NOT NULL,
  `fee`             DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `net_amount`      DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `currency`        VARCHAR(8) NOT NULL DEFAULT 'TZS',
  `method`          VARCHAR(40) NOT NULL,
  `account_number`  VARCHAR(60) NOT NULL,
  `account_name`    VARCHAR(140) NOT NULL,
  `status`          ENUM('pending','processing','completed','failed','cancelled') NOT NULL DEFAULT 'pending',
  `provider_ref`    VARCHAR(60) DEFAULT NULL COMMENT 'SonicPesa withdrawal_id',
  `failure_reason`  VARCHAR(255) DEFAULT NULL,
  `requested_by`    INT UNSIGNED DEFAULT NULL,
  `approved_by`     INT UNSIGNED DEFAULT NULL,
  `raw_response`    TEXT DEFAULT NULL,
  `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `completed_at`    DATETIME DEFAULT NULL,
  `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_withdrawals_ref` (`reference`),
  KEY `idx_wd_provider` (`provider_id`,`status`),
  KEY `idx_wd_status` (`status`),
  KEY `idx_wd_created` (`created_at`),
  KEY `idx_wd_provider_ref` (`provider_ref`),
  CONSTRAINT `fk_wd_provider` FOREIGN KEY (`provider_id`) REFERENCES `providers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_wd_requester` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_wd_approver` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 4. PLATFORM INVOICES
--    What each provider owes the platform, per cycle.
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `platform_invoices` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `provider_id`    INT UNSIGNED NOT NULL,
  `invoice_number` VARCHAR(40) NOT NULL,
  `period_start`   DATE NOT NULL,
  `period_end`     DATE NOT NULL,
  `amount`         DECIMAL(12,2) NOT NULL,
  `currency`       VARCHAR(8) NOT NULL DEFAULT 'TZS',
  `due_on`         DATE NOT NULL,
  `status`         ENUM('unpaid','paid','waived','cancelled') NOT NULL DEFAULT 'unpaid',
  `paid_at`        DATETIME DEFAULT NULL,
  `paid_from`      ENUM('wallet','manual','waived') DEFAULT NULL,
  `notes`          VARCHAR(255) DEFAULT NULL,
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_invoice_number` (`invoice_number`),
  UNIQUE KEY `uq_invoice_period` (`provider_id`,`period_start`),
  KEY `idx_inv_provider` (`provider_id`,`status`),
  KEY `idx_inv_status` (`status`,`due_on`),
  CONSTRAINT `fk_inv_provider` FOREIGN KEY (`provider_id`) REFERENCES `providers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 5. PERMISSIONS
-- ---------------------------------------------------------------------

INSERT INTO `permissions` (`slug`,`scope`,`name`,`group_name`) VALUES
  ('view_wallet','provider','View the provider wallet','Money'),
  ('request_withdrawal','provider','Request a withdrawal','Money'),
  ('manage_billing','platform','Manage provider fees and payouts','Platform')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `group_name` = VALUES(`group_name`), `scope` = VALUES(`scope`);

-- Super Admin gets everything, as always.
INSERT IGNORE INTO `role_permissions` (`role_id`,`permission_id`)
SELECT r.id, p.id FROM `roles` r CROSS JOIN `permissions` p WHERE r.slug = 'super_admin';

-- A Provider Administrator sees the wallet and may ask for their money.
INSERT IGNORE INTO `role_permissions` (`role_id`,`permission_id`)
SELECT r.id, p.id FROM `roles` r CROSS JOIN `permissions` p
 WHERE r.slug = 'provider_admin' AND p.slug IN ('view_wallet','request_withdrawal');

-- Sales staff may look at the balance but not move money.
INSERT IGNORE INTO `role_permissions` (`role_id`,`permission_id`)
SELECT r.id, p.id FROM `roles` r CROSS JOIN `permissions` p
 WHERE r.slug = 'sales_admin' AND p.slug = 'view_wallet';

-- Provider roles must never hold a platform permission.
DELETE rp FROM `role_permissions` rp
  JOIN `roles` r ON r.id = rp.role_id
  JOIN `permissions` p ON p.id = rp.permission_id
 WHERE r.scope = 'provider' AND p.scope = 'platform';

-- ---------------------------------------------------------------------
-- 6. PLATFORM SETTINGS
-- ---------------------------------------------------------------------

INSERT INTO `settings` (`provider_id`,`setting_key`,`setting_value`,`setting_group`,`value_type`,`label`) VALUES
  (0,'platform_fee_default','10000','billing','string','Default platform fee per cycle'),
  (0,'platform_fee_cycle_months','1','billing','int','Default billing cycle in months'),
  (0,'platform_fee_grace_months','1','billing','int','Default months before a new provider starts paying'),
  (0,'platform_fee_due_days','7','billing','int','Days a provider has to settle an invoice'),
  (0,'withdrawal_minimum','5000','billing','string','Smallest withdrawal a provider may request'),
  (0,'withdrawal_requires_approval','1','billing','bool','Platform must approve withdrawals before payout'),
  (0,'auto_charge_fee_from_wallet','1','billing','bool','Take the platform fee from the wallet automatically')
ON DUPLICATE KEY UPDATE `label` = VALUES(`label`);

-- ---------------------------------------------------------------------
-- 7. EXISTING PROVIDERS
--    Give them sensible billing terms rather than leaving NULLs around.
-- ---------------------------------------------------------------------

UPDATE `providers`
   SET `billing_starts_on` = DATE_ADD(DATE(`created_at`), INTERVAL 1 MONTH)
 WHERE `billing_starts_on` IS NULL;

UPDATE `providers`
   SET `billing_next_due_on` = `billing_starts_on`
 WHERE `billing_next_due_on` IS NULL;

UPDATE `providers`
   SET `billing_status` = IF(`billing_starts_on` > CURDATE(), 'grace', 'current')
 WHERE `billing_status` = 'grace';

DROP PROCEDURE IF EXISTS wms_bill_add_column;
DROP PROCEDURE IF EXISTS wms_bill_add_index;

-- ---------------------------------------------------------------------
-- Verification
-- ---------------------------------------------------------------------

SELECT id, business_name,
       mobile_money_enabled AS momo,
       wallet_balance, platform_fee_amount,
       billing_starts_on, billing_next_due_on, billing_status
  FROM providers;
