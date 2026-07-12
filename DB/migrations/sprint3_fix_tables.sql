-- ============================================================
-- Sprint 3 – Fix Missing Tables & Sequences
-- AQNEX POS System
-- ============================================================
-- Safe to run multiple times (uses IF NOT EXISTS / INSERT IGNORE)
-- ============================================================

-- 1. Formal Double-Entry Journal – Header Table
CREATE TABLE IF NOT EXISTS `accounting_journal_entries` (
  `id`           INT          NOT NULL AUTO_INCREMENT,
  `entry_date`   DATE         NOT NULL,
  `reference_no` VARCHAR(50)  NOT NULL DEFAULT '',
  `description`  VARCHAR(500) NOT NULL DEFAULT '',
  `source_type`  VARCHAR(50)  NOT NULL DEFAULT 'manual',
  `source_id`    INT          NULL     DEFAULT NULL,
  `status`       ENUM('draft','posted','voided') NOT NULL DEFAULT 'posted',
  `created_by`   VARCHAR(100) NOT NULL DEFAULT '',
  `approved_by`  VARCHAR(100) NULL     DEFAULT NULL,
  `created_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_entry_date`   (`entry_date`),
  KEY `idx_source`       (`source_type`, `source_id`),
  KEY `idx_status`       (`status`),
  KEY `idx_reference_no` (`reference_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 2. Formal Double-Entry Journal – Line Items Table
CREATE TABLE IF NOT EXISTS `accounting_journal_items` (
  `id`            INT            NOT NULL AUTO_INCREMENT,
  `entry_id`      INT            NOT NULL,
  `account_id`    INT            NOT NULL,
  `debit`         DECIMAL(15,4)  NOT NULL DEFAULT 0.0000,
  `credit`        DECIMAL(15,4)  NOT NULL DEFAULT 0.0000,
  `currency_id`   INT            NULL     DEFAULT NULL,
  `exchange_rate` DECIMAL(15,4)  NOT NULL DEFAULT 1.0000,
  `memo`          VARCHAR(255)   NOT NULL DEFAULT '',
  `created_at`    TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_entry_id`   (`entry_id`),
  KEY `idx_account_id` (`account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 3. Vouchers Table
CREATE TABLE IF NOT EXISTS `accounting_vouchers` (
  `id`               INT            NOT NULL AUTO_INCREMENT,
  `voucher_type`     ENUM('receipt','payment') NOT NULL,
  `voucher_no`       VARCHAR(50)    NOT NULL DEFAULT '',
  `voucher_date`     DATE           NOT NULL,
  `party_type`       ENUM('customer','supplier','other') NOT NULL DEFAULT 'other',
  `party_id`         INT            NULL DEFAULT NULL,
  `party_name`       VARCHAR(150)   NOT NULL DEFAULT '',
  `cash_account_id`  INT            NOT NULL,
  `contra_account_id`INT            NOT NULL,
  `amount`           DECIMAL(15,4)  NOT NULL DEFAULT 0.0000,
  `currency_id`      INT            NULL DEFAULT NULL,
  `exchange_rate`    DECIMAL(15,4)  NOT NULL DEFAULT 1.0000,
  `description`      VARCHAR(500)   NOT NULL DEFAULT '',
  `status`           ENUM('draft','posted','voided') NOT NULL DEFAULT 'posted',
  `journal_entry_id` INT            NULL DEFAULT NULL,
  `created_by`       VARCHAR(100)   NOT NULL DEFAULT '',
  `created_at`       TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_voucher_date` (`voucher_date`),
  KEY `idx_voucher_type` (`voucher_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 4. Audit Trail
CREATE TABLE IF NOT EXISTS `accounting_journal_audit` (
  `id`         INT          NOT NULL AUTO_INCREMENT,
  `entry_id`   INT          NOT NULL,
  `action`     ENUM('create','edit','void','approve') NOT NULL,
  `changed_by` VARCHAR(100) NOT NULL DEFAULT '',
  `changed_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `old_data`   TEXT         NULL,
  `new_data`   TEXT         NULL,
  `reason`     VARCHAR(500) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `idx_audit_entry` (`entry_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 5. Auto-number sequences
CREATE TABLE IF NOT EXISTS `accounting_sequences` (
  `seq_key` VARCHAR(50) NOT NULL,
  `last_no` INT         NOT NULL DEFAULT 0,
  PRIMARY KEY (`seq_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO `accounting_sequences` (`seq_key`, `last_no`) VALUES
  ('receipt', 0),
  ('payment', 0),
  ('journal', 0);

-- 6. Treasury Closings table (for daily closing)
CREATE TABLE IF NOT EXISTS `treasury_closings` (
  `id`                INT            NOT NULL AUTO_INCREMENT,
  `box_id`            INT            NOT NULL,
  `close_date`        DATE           NOT NULL,
  `expected_balance`  DECIMAL(15,4)  NOT NULL DEFAULT 0.0000,
  `actual_balance`    DECIMAL(15,4)  NOT NULL DEFAULT 0.0000,
  `difference`        DECIMAL(15,4)  NOT NULL DEFAULT 0.0000,
  `transferred_amount`DECIMAL(15,4)  NOT NULL DEFAULT 0.0000,
  `user`              VARCHAR(100)   NOT NULL DEFAULT '',
  `notes`             TEXT           NULL,
  `created_at`        TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_box_date` (`box_id`, `close_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 7. Extend accounting_accounts if needed
ALTER TABLE `accounting_accounts`
  ADD COLUMN IF NOT EXISTS `is_reconcilable` TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `is_active`        TINYINT(1) NOT NULL DEFAULT 1;
