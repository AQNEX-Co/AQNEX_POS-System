-- ============================================================
-- Sprint 2 – Core Accounting & General Ledger Migration
-- AQNEX POS System
-- ============================================================
-- Safe to run: uses IF NOT EXISTS / ADD COLUMN IF NOT EXISTS
-- Does NOT touch existing data or modify legacy tables
-- ============================================================

-- ---------------------------------------------------------------
-- 1. Extend accounting_accounts: add is_reconcilable & is_active
-- ---------------------------------------------------------------
ALTER TABLE `accounting_accounts`
  ADD COLUMN IF NOT EXISTS `is_reconcilable` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'يسمح بالتسوية',
  ADD COLUMN IF NOT EXISTS `is_active`        TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'الحساب مفعّل';

-- Set reconcilable=1 for leaf (non-parent) accounts by default
UPDATE `accounting_accounts` SET `is_reconcilable` = 1 WHERE `is_parent` = 0;

-- ---------------------------------------------------------------
-- 2. Formal Double-Entry Journal – Header Table
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `accounting_journal_entries` (
  `id`           INT          NOT NULL AUTO_INCREMENT,
  `entry_date`   DATE         NOT NULL,
  `reference_no` VARCHAR(50)  NOT NULL DEFAULT '',
  `description`  VARCHAR(500) NOT NULL DEFAULT '',
  `source_type`  VARCHAR(50)  NOT NULL DEFAULT 'manual'
                   COMMENT 'manual | sale | purchase | voucher | expense | receipt',
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='رؤوس قيود اليومية المزدوجة';

-- ---------------------------------------------------------------
-- 3. Formal Double-Entry Journal – Line Items Table
-- ---------------------------------------------------------------
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
  KEY `idx_account_id` (`account_id`),
  CONSTRAINT `fk_ji_entry`   FOREIGN KEY (`entry_id`)   REFERENCES `accounting_journal_entries` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ji_account` FOREIGN KEY (`account_id`) REFERENCES `accounting_accounts`         (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='بنود قيود اليومية المزدوجة';

-- ---------------------------------------------------------------
-- 4. Vouchers Table (Receipt سند قبض / Payment سند صرف)
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `accounting_vouchers` (
  `id`               INT            NOT NULL AUTO_INCREMENT,
  `voucher_type`     ENUM('receipt','payment') NOT NULL COMMENT 'receipt=قبض | payment=صرف',
  `voucher_no`       VARCHAR(50)    NOT NULL DEFAULT '',
  `voucher_date`     DATE           NOT NULL,
  `party_type`       ENUM('customer','supplier','other') NOT NULL DEFAULT 'other',
  `party_id`         INT            NULL DEFAULT NULL,
  `party_name`       VARCHAR(150)   NOT NULL DEFAULT '',
  `cash_account_id`  INT            NOT NULL COMMENT 'حساب الصندوق/البنك',
  `contra_account_id`INT            NOT NULL COMMENT 'حساب المقابل',
  `amount`           DECIMAL(15,4)  NOT NULL DEFAULT 0.0000,
  `currency_id`      INT            NULL DEFAULT NULL,
  `exchange_rate`    DECIMAL(15,4)  NOT NULL DEFAULT 1.0000,
  `description`      VARCHAR(500)   NOT NULL DEFAULT '',
  `status`           ENUM('draft','posted','voided') NOT NULL DEFAULT 'posted',
  `journal_entry_id` INT            NULL DEFAULT NULL COMMENT 'FK to accounting_journal_entries',
  `created_by`       VARCHAR(100)   NOT NULL DEFAULT '',
  `created_at`       TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_voucher_no`      (`voucher_no`, `voucher_type`),
  KEY `idx_voucher_date`          (`voucher_date`),
  KEY `idx_voucher_type`          (`voucher_type`),
  KEY `idx_party`                 (`party_type`, `party_id`),
  CONSTRAINT `fk_vou_cash_acc`    FOREIGN KEY (`cash_account_id`)   REFERENCES `accounting_accounts` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_vou_contra_acc`  FOREIGN KEY (`contra_account_id`) REFERENCES `accounting_accounts` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_vou_je`          FOREIGN KEY (`journal_entry_id`)  REFERENCES `accounting_journal_entries` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='سندات القبض والصرف';

-- ---------------------------------------------------------------
-- 5. Audit Trail for Journal Corrections (Support Mode)
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `accounting_journal_audit` (
  `id`         INT          NOT NULL AUTO_INCREMENT,
  `entry_id`   INT          NOT NULL,
  `action`     ENUM('create','edit','void','approve') NOT NULL,
  `changed_by` VARCHAR(100) NOT NULL DEFAULT '',
  `changed_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `old_data`   JSON         NULL,
  `new_data`   JSON         NULL,
  `reason`     VARCHAR(500) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `idx_audit_entry` (`entry_id`),
  CONSTRAINT `fk_audit_entry` FOREIGN KEY (`entry_id`) REFERENCES `accounting_journal_entries` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='سجل تدقيق قيود اليومية';

-- ---------------------------------------------------------------
-- 6. Auto-number sequences for vouchers
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `accounting_sequences` (
  `seq_key` VARCHAR(50) NOT NULL,
  `last_no` INT         NOT NULL DEFAULT 0,
  PRIMARY KEY (`seq_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO `accounting_sequences` (`seq_key`, `last_no`) VALUES
  ('receipt', 0),
  ('payment', 0),
  ('journal', 0);
