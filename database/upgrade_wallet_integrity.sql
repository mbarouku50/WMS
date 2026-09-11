-- ---------------------------------------------------------------------------
-- WMS - Wallet and payout integrity.
--
-- Every guard in this file exists because the same money movement can be
-- triggered from more than one direction at the same moment:
--
--   a sale    - the SonicPesa webhook, the customer's status poll and the
--               cron reconciliation all call PaymentService::fulfil()
--   a payout  - the payout.success webhook and the cron reconciliation both
--               call WalletService::completeWithdrawal()
--   a fee     - the provider's "Pay now" button and the cron auto-collection
--               both call BillingService::payFromWallet()
--
-- The PHP checks "has this already happened?" before acting, but a check and
-- a write that are not one atomic step can both pass. This index is the last
-- line: the database itself refuses the second sale credit for a payment.
--
-- Safe to run more than once.
-- ---------------------------------------------------------------------------

-- One credit per payment per type. payment_id is NULL for fees, withdrawals
-- and adjustments, and MySQL allows any number of NULLs in a unique index,
-- so those rows are unaffected.
SET @exists := (SELECT COUNT(*) FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME   = 'wallet_transactions'
                   AND INDEX_NAME   = 'uq_wt_payment_type');

SET @sql := IF(@exists = 0,
    'ALTER TABLE wallet_transactions ADD UNIQUE KEY uq_wt_payment_type (payment_id, type)',
    'SELECT "uq_wt_payment_type already present"');

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
