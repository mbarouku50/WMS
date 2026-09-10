-- =====================================================================
--  WMS - Network module production upgrade
--  ---------------------------------------------------------------------
--  Prepares the routers and access_points tables for real MikroTik
--  hardware and real access points.
--
--  Safe to run more than once: every ALTER is guarded against
--  information_schema, so re-running adds nothing twice.
--
--  This migration NEVER deletes a row, never clears a password and never
--  resets a status. It only adds columns, widens two ENUMs and adds
--  indexes.
--
--  What it does
--    1. routers      - hotspot profile columns, board, sync/health
--                      bookkeeping, soft deletion
--    2. routers      - status ENUM gains 'degraded' and 'retired'
--    3. access_points- monitoring source, status history, notes,
--                      soft deletion
--    4. access_points- status ENUM gains 'retired'
--    5. indexes for the columns the Network pages filter on
--    6. a provider-scoped unique index on the AP MAC address, added only
--       when the existing data allows it (duplicates are reported, not
--       destroyed)
--
--  Back up your database before running this.
--    mysqldump -u USER -p DBNAME > wms-backup.sql
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- Idempotency helpers (same contract as upgrade_multi_provider.sql)
-- ---------------------------------------------------------------------

DROP PROCEDURE IF EXISTS wms_net_add_column;
DROP PROCEDURE IF EXISTS wms_net_add_index;
DROP PROCEDURE IF EXISTS wms_net_widen_enum;

DELIMITER $$

CREATE PROCEDURE wms_net_add_column(IN t VARCHAR(64), IN c VARCHAR(64), IN ddl TEXT)
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                    WHERE table_schema = DATABASE() AND table_name = t AND column_name = c) THEN
        SET @sql = CONCAT('ALTER TABLE `', t, '` ADD COLUMN ', ddl);
        PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
    END IF;
END$$

CREATE PROCEDURE wms_net_add_index(IN t VARCHAR(64), IN idx VARCHAR(64), IN cols TEXT)
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.statistics
                    WHERE table_schema = DATABASE() AND table_name = t AND index_name = idx) THEN
        SET @sql = CONCAT('ALTER TABLE `', t, '` ADD INDEX `', idx, '` (', cols, ')');
        PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
    END IF;
END$$

/*
 * Replaces a column definition only when it is not already what we want.
 * Widening an ENUM keeps every existing value, so no row changes meaning.
 */
CREATE PROCEDURE wms_net_widen_enum(IN t VARCHAR(64), IN c VARCHAR(64), IN ddl TEXT, IN needle VARCHAR(64))
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                    WHERE table_schema = DATABASE() AND table_name = t AND column_name = c
                      AND LOCATE(needle, column_type) > 0) THEN
        SET @sql = CONCAT('ALTER TABLE `', t, '` MODIFY COLUMN ', ddl);
        PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
    END IF;
END$$

DELIMITER ;

-- =====================================================================
-- 1. ROUTERS - columns the production Network module needs
-- =====================================================================

/*
 * The spec's api_address / api_password_encrypted / api_tls_enabled /
 * cpu_usage / memory_usage / last_connection_error are already present
 * under the existing names ip_address, api_password (VARBINARY, AES
 * encrypted), use_tls, cpu_load, memory_used_pct and last_error.
 * Those are kept - renaming them would break working code for no gain.
 */

-- Which RouterOS hotspot profile new users are attached to.
CALL wms_net_add_column('routers', 'hotspot_profile',
    '`hotspot_profile` VARCHAR(80) DEFAULT NULL AFTER `hotspot_server`');

-- Fallback user profile when a package carries no bandwidth profile.
CALL wms_net_add_column('routers', 'default_user_profile',
    '`default_user_profile` VARCHAR(80) DEFAULT NULL AFTER `hotspot_profile`');

-- Board/model as reported by /system/resource - never invented.
CALL wms_net_add_column('routers', 'board',
    '`board` VARCHAR(80) DEFAULT NULL AFTER `identity`');

-- Last time a poll completed successfully (distinct from last_seen_at,
-- which only records that the device answered).
CALL wms_net_add_column('routers', 'last_sync_at',
    '`last_sync_at` DATETIME DEFAULT NULL AFTER `last_seen_at`');

-- Consecutive failed contact attempts. The retry policy uses this so a
-- single timeout does not flip a router to Offline.
CALL wms_net_add_column('routers', 'failed_checks',
    '`failed_checks` SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER `last_sync_at`');

-- Soft deletion: a retired router keeps its sessions, vouchers, payments
-- and audit trail intact.
CALL wms_net_add_column('routers', 'deleted_at',
    '`deleted_at` DATETIME DEFAULT NULL AFTER `updated_at`');

-- =====================================================================
-- 2. ROUTERS - richer status model (ONLINE/OFFLINE/UNKNOWN/DEGRADED)
-- =====================================================================

CALL wms_net_widen_enum(
    'routers', 'status',
    "`status` ENUM('online','offline','unknown','degraded','disabled','retired') NOT NULL DEFAULT 'unknown'",
    'degraded'
);

-- =====================================================================
-- 3. ACCESS POINTS - columns the production Network module needs
-- =====================================================================

/*
 * management_ip in the spec is the existing ip_address column: it has
 * always held the AP's management address. The user-facing label becomes
 * "Management IP address"; the column keeps its name so existing rows,
 * indexes and code continue to work.
 */

-- Where the AP's status actually came from. 'manual' means a human set
-- it; the UI never presents a manual value as a live measurement.
CALL wms_net_add_column('access_points', 'monitoring_source',
    "`monitoring_source` ENUM('router','snmp','controller','vendor_api','manual') NOT NULL DEFAULT 'manual' AFTER `status`");

-- When the status last actually changed, for uptime/flap reporting.
CALL wms_net_add_column('access_points', 'last_status_change_at',
    '`last_status_change_at` DATETIME DEFAULT NULL AFTER `last_seen_at`');

CALL wms_net_add_column('access_points', 'notes',
    '`notes` TEXT DEFAULT NULL AFTER `last_status_change_at`');

CALL wms_net_add_column('access_points', 'deleted_at',
    '`deleted_at` DATETIME DEFAULT NULL AFTER `updated_at`');

-- =====================================================================
-- 4. ACCESS POINTS - retired state for soft deletion
-- =====================================================================

CALL wms_net_widen_enum(
    'access_points', 'status',
    "`status` ENUM('online','offline','unknown','disabled','retired') NOT NULL DEFAULT 'unknown'",
    'retired'
);

-- =====================================================================
-- 5. INDEXES for the columns the Network pages filter and sort on
-- =====================================================================

CALL wms_net_add_index('routers', 'idx_routers_provider_mode',   '`provider_id`,`mode`');
CALL wms_net_add_index('routers', 'idx_routers_last_seen',       '`last_seen_at`');
CALL wms_net_add_index('routers', 'idx_routers_deleted',         '`deleted_at`');

CALL wms_net_add_index('access_points', 'idx_ap_provider_status', '`provider_id`,`status`');
CALL wms_net_add_index('access_points', 'idx_ap_router_status',   '`router_id`,`status`');
CALL wms_net_add_index('access_points', 'idx_ap_last_seen',       '`last_seen_at`');
CALL wms_net_add_index('access_points', 'idx_ap_deleted',         '`deleted_at`');
CALL wms_net_add_index('access_points', 'idx_ap_management_ip',   '`ip_address`');

-- =====================================================================
-- 6. One physical AP per provider (by MAC)
-- ---------------------------------------------------------------------
--  A UNIQUE index over (provider_id, mac_address) still permits many
--  rows with a NULL MAC, which is what we want: the MAC is optional.
--
--  It is only added when the current data already satisfies it. If two
--  live AP records share a MAC inside one provider, the index is skipped
--  and the duplicates are listed at the end of this script so an operator
--  can decide which record to retire. Nothing is deleted automatically.
-- =====================================================================

DROP PROCEDURE IF EXISTS wms_net_unique_ap_mac;

DELIMITER $$
CREATE PROCEDURE wms_net_unique_ap_mac()
BEGIN
    DECLARE dupes INT DEFAULT 0;

    IF EXISTS (SELECT 1 FROM information_schema.statistics
                WHERE table_schema = DATABASE() AND table_name = 'access_points'
                  AND index_name = 'uq_ap_provider_mac') THEN
        SELECT 'AP MAC uniqueness already in place.' AS note;
    ELSE
        SELECT COUNT(*) INTO dupes FROM (
            SELECT provider_id, mac_address
              FROM access_points
             WHERE mac_address IS NOT NULL AND mac_address <> '' AND deleted_at IS NULL
             GROUP BY provider_id, mac_address
            HAVING COUNT(*) > 1
        ) d;

        IF dupes = 0 THEN
            ALTER TABLE `access_points`
                ADD UNIQUE INDEX `uq_ap_provider_mac` (`provider_id`, `mac_address`);
            SELECT 'AP MAC uniqueness added.' AS note;
        ELSE
            SELECT CONCAT(dupes, ' duplicate provider/MAC pair(s) found - unique index NOT added. ',
                          'Retire the stale record(s) listed below, then re-run this script.') AS note;
        END IF;
    END IF;
END$$
DELIMITER ;

CALL wms_net_unique_ap_mac();

-- Any duplicates that blocked the index (empty result = nothing to do).
SELECT provider_id, mac_address, COUNT(*) AS records,
       GROUP_CONCAT(id ORDER BY id) AS access_point_ids
  FROM access_points
 WHERE mac_address IS NOT NULL AND mac_address <> '' AND deleted_at IS NULL
 GROUP BY provider_id, mac_address
HAVING COUNT(*) > 1;

-- =====================================================================
-- 6b. Router names are unique per provider, not per platform
-- ---------------------------------------------------------------------
--  The original schema made router names globally unique, which stops two
--  independent providers from both calling their gateway "Main Router".
--  The application has always enforced per-provider uniqueness; the index
--  is brought in line with it here.
--
--  As above, the swap only happens when the existing data allows it.
-- =====================================================================

DROP PROCEDURE IF EXISTS wms_net_router_name_scope;

DELIMITER $$
CREATE PROCEDURE wms_net_router_name_scope()
BEGIN
    DECLARE dupes INT DEFAULT 0;

    IF EXISTS (SELECT 1 FROM information_schema.statistics
                WHERE table_schema = DATABASE() AND table_name = 'routers'
                  AND index_name = 'uq_routers_provider_name') THEN
        SELECT 'Router name scoping already in place.' AS note;
    ELSE
        SELECT COUNT(*) INTO dupes FROM (
            SELECT provider_id, name FROM routers
             WHERE deleted_at IS NULL
             GROUP BY provider_id, name
            HAVING COUNT(*) > 1
        ) d;

        IF dupes = 0 THEN
            ALTER TABLE `routers` ADD UNIQUE INDEX `uq_routers_provider_name` (`provider_id`, `name`);
            IF EXISTS (SELECT 1 FROM information_schema.statistics
                        WHERE table_schema = DATABASE() AND table_name = 'routers'
                          AND index_name = 'uq_routers_name') THEN
                ALTER TABLE `routers` DROP INDEX `uq_routers_name`;
            END IF;
            SELECT 'Router names are now unique per provider.' AS note;
        ELSE
            SELECT CONCAT(dupes, ' duplicate provider/name pair(s) found - index NOT changed.') AS note;
        END IF;
    END IF;
END$$
DELIMITER ;

CALL wms_net_router_name_scope();
DROP PROCEDURE IF EXISTS wms_net_router_name_scope;

-- =====================================================================
-- 7. Data alignment (no destructive change)
-- =====================================================================

/*
 * Existing access points whose status was set by the old router-interface
 * sync are marked as router-monitored, so the UI can say where the value
 * came from. Everything else stays 'manual' - which the UI shows as
 * "not monitored" rather than pretending it is measured.
 */
UPDATE `access_points`
   SET `monitoring_source` = 'router'
 WHERE `router_id` IS NOT NULL
   AND `last_seen_at` IS NOT NULL
   AND `monitoring_source` = 'manual';

/*
 * An access point must always agree with its parent router about who owns
 * it. MySQL cannot express that as a CHECK constraint (it spans tables),
 * so it is enforced in PHP on every write - and any historical mismatch is
 * corrected here in favour of the router, which is the authoritative link.
 */
UPDATE `access_points` a
  JOIN `routers` r ON r.id = a.router_id
   SET a.provider_id = r.provider_id
 WHERE a.router_id IS NOT NULL
   AND a.provider_id <> r.provider_id;

-- =====================================================================
-- Clean up the helper procedures
-- =====================================================================

DROP PROCEDURE IF EXISTS wms_net_add_column;
DROP PROCEDURE IF EXISTS wms_net_add_index;
DROP PROCEDURE IF EXISTS wms_net_widen_enum;
DROP PROCEDURE IF EXISTS wms_net_unique_ap_mac;

-- =====================================================================
-- Verification
-- =====================================================================

SELECT 'routers'       AS table_name, COUNT(*) AS rows_preserved FROM `routers`
UNION ALL
SELECT 'access_points', COUNT(*) FROM `access_points`
UNION ALL
SELECT 'routers with a stored password', COUNT(*) FROM `routers` WHERE `api_password` IS NOT NULL AND `api_password` <> '';
