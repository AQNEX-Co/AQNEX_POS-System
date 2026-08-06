-- ============================================================
-- Sprint 4 – Master-Detail Tables Structure & Unification
-- AQNEX POS System
-- ============================================================

-- 1. Sales Invoices Master & Detail
CREATE TABLE IF NOT EXISTS `sales_invoices_mst` (
  `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `invoice_no` VARCHAR(50) NOT NULL,
  `cust_id` INT(11) DEFAULT NULL,
  `cust_name` VARCHAR(150) NOT NULL DEFAULT 'عميل نقدي',
  `invoice_date` DATE NOT NULL,
  `total_amount` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `discount_amount` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `net_amount` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `paid_amount` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `remaining_amount` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `invoice_type` ENUM('cash','credit','account') NOT NULL DEFAULT 'cash',
  `payment_method` VARCHAR(50) DEFAULT 'cash',
  `wallet_type` VARCHAR(100) DEFAULT NULL,
  `profit_total` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `box_id` INT(11) NOT NULL DEFAULT 1,
  `sector_id` INT(11) DEFAULT NULL,
  `currency_code` VARCHAR(10) DEFAULT 'YER',
  `exchange_rate` DECIMAL(15,4) DEFAULT 1.0000,
  `remark` TEXT DEFAULT NULL,
  `user_id` INT(11) DEFAULT NULL,
  `d_s` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_sales_inv_no` (`invoice_no`),
  KEY `idx_sales_cust_id` (`cust_id`),
  KEY `idx_sales_inv_date` (`invoice_date`),
  KEY `idx_sales_box_id` (`box_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='رأس فاتورة المبيعات';

CREATE TABLE IF NOT EXISTS `sales_invoices_dtl` (
  `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `invoice_id` INT(10) UNSIGNED NOT NULL,
  `product_id` INT(10) UNSIGNED NOT NULL,
  `product_name` VARCHAR(255) NOT NULL,
  `barcode` VARCHAR(100) DEFAULT NULL,
  `unit_name` VARCHAR(50) DEFAULT 'الوحدة الأساسية',
  `quantity` DECIMAL(15,4) NOT NULL DEFAULT 1.0000,
  `unit_price` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `unit_cost` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `discount` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `total_price` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `d_s` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sales_dtl_inv` (`invoice_id`),
  KEY `idx_sales_dtl_prod` (`product_id`),
  CONSTRAINT `fk_sal_inv_dtl_mst` FOREIGN KEY (`invoice_id`) REFERENCES `sales_invoices_mst` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='تفاصيل أصناف فاتورة المبيعات';

-- 2. Sales Returns Master & Detail
CREATE TABLE IF NOT EXISTS `sales_returns_mst` (
  `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `return_no` VARCHAR(50) NOT NULL,
  `original_sale_id` INT(10) UNSIGNED DEFAULT NULL,
  `cust_id` INT(11) DEFAULT NULL,
  `cust_name` VARCHAR(150) NOT NULL DEFAULT 'عميل نقدي',
  `return_date` DATE NOT NULL,
  `total_amount` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `refund_method` ENUM('cash','credit') NOT NULL DEFAULT 'cash',
  `box_id` INT(11) NOT NULL DEFAULT 1,
  `sector_id` INT(11) DEFAULT NULL,
  `currency_code` VARCHAR(10) DEFAULT 'YER',
  `exchange_rate` DECIMAL(15,4) DEFAULT 1.0000,
  `reason` VARCHAR(255) DEFAULT NULL,
  `user_id` INT(11) DEFAULT NULL,
  `d_s` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_sales_ret_no` (`return_no`),
  KEY `idx_sal_ret_orig_sale` (`original_sale_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='رأس مردود المبيعات';

CREATE TABLE IF NOT EXISTS `sales_returns_dtl` (
  `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `return_id` INT(10) UNSIGNED NOT NULL,
  `product_id` INT(10) UNSIGNED NOT NULL,
  `product_name` VARCHAR(255) NOT NULL,
  `quantity` DECIMAL(15,4) NOT NULL DEFAULT 1.0000,
  `unit_price` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `unit_cost` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `total_amount` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `d_s` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sal_ret_dtl_ret` (`return_id`),
  KEY `idx_sal_ret_dtl_prod` (`product_id`),
  CONSTRAINT `fk_sal_ret_dtl_mst` FOREIGN KEY (`return_id`) REFERENCES `sales_returns_mst` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='تفاصيل أصناف مردود المبيعات';

-- 3. Purchase Invoices Master & Detail
CREATE TABLE IF NOT EXISTS `purchase_invoices_mst` (
  `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `invoice_no` VARCHAR(50) NOT NULL,
  `supp_id` INT(11) DEFAULT NULL,
  `supp_name` VARCHAR(150) NOT NULL,
  `invoice_date` DATE NOT NULL,
  `total_amount` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `discount_amount` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `net_amount` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `paid_amount` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `remaining_amount` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `invoice_type` ENUM('cash','credit','account') NOT NULL DEFAULT 'cash',
  `payment_method` VARCHAR(50) DEFAULT 'cash',
  `wallet_type` VARCHAR(100) DEFAULT NULL,
  `box_id` INT(11) NOT NULL DEFAULT 1,
  `sector_id` INT(11) DEFAULT NULL,
  `currency_code` VARCHAR(10) DEFAULT 'YER',
  `exchange_rate` DECIMAL(15,4) DEFAULT 1.0000,
  `remark` TEXT DEFAULT NULL,
  `user_id` INT(11) DEFAULT NULL,
  `d_s` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_pur_inv_no` (`invoice_no`),
  KEY `idx_pur_supp_id` (`supp_id`),
  KEY `idx_pur_inv_date` (`invoice_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='رأس فاتورة المشتريات';

CREATE TABLE IF NOT EXISTS `purchase_invoices_dtl` (
  `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `invoice_id` INT(10) UNSIGNED NOT NULL,
  `product_id` INT(10) UNSIGNED DEFAULT NULL,
  `product_name` VARCHAR(255) NOT NULL,
  `barcode` VARCHAR(100) DEFAULT NULL,
  `unit_name` VARCHAR(50) DEFAULT 'الوحدة الأساسية',
  `quantity` DECIMAL(15,4) NOT NULL DEFAULT 1.0000,
  `unit_cost` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `total_cost` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `d_s` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_pur_dtl_inv` (`invoice_id`),
  KEY `idx_pur_dtl_prod` (`product_id`),
  CONSTRAINT `fk_pur_inv_dtl_mst` FOREIGN KEY (`invoice_id`) REFERENCES `purchase_invoices_mst` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='تفاصيل أصناف فاتورة المشتريات';

-- 4. Purchase Returns Master & Detail
CREATE TABLE IF NOT EXISTS `purchase_returns_mst` (
  `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `return_no` VARCHAR(50) NOT NULL,
  `original_purchase_id` INT(10) UNSIGNED DEFAULT NULL,
  `supp_id` INT(11) DEFAULT NULL,
  `supp_name` VARCHAR(150) NOT NULL,
  `return_date` DATE NOT NULL,
  `total_amount` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `refund_method` ENUM('cash','credit') NOT NULL DEFAULT 'cash',
  `box_id` INT(11) NOT NULL DEFAULT 1,
  `sector_id` INT(11) DEFAULT NULL,
  `currency_code` VARCHAR(10) DEFAULT 'YER',
  `exchange_rate` DECIMAL(15,4) DEFAULT 1.0000,
  `reason` VARCHAR(255) DEFAULT NULL,
  `user_id` INT(11) DEFAULT NULL,
  `d_s` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_pur_ret_no` (`return_no`),
  KEY `idx_pur_ret_orig` (`original_purchase_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='رأس مردود المشتريات';

CREATE TABLE IF NOT EXISTS `purchase_returns_dtl` (
  `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `return_id` INT(10) UNSIGNED NOT NULL,
  `product_id` INT(10) UNSIGNED NOT NULL,
  `product_name` VARCHAR(255) NOT NULL,
  `quantity` DECIMAL(15,4) NOT NULL DEFAULT 1.0000,
  `unit_cost` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `total_amount` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `d_s` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_pur_ret_dtl_ret` (`return_id`),
  KEY `idx_pur_ret_dtl_prod` (`product_id`),
  CONSTRAINT `fk_pur_ret_dtl_mst` FOREIGN KEY (`return_id`) REFERENCES `purchase_returns_mst` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='تفاصيل أصناف مردود المشتريات';

-- 5. Receipt Vouchers Master & Detail
CREATE TABLE IF NOT EXISTS `receipt_vouchers_mst` (
  `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `voucher_no` VARCHAR(50) NOT NULL,
  `voucher_date` DATE NOT NULL,
  `party_type` ENUM('customer','supplier','other') NOT NULL DEFAULT 'customer',
  `party_id` INT(11) DEFAULT NULL,
  `party_name` VARCHAR(150) NOT NULL,
  `total_amount` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `payment_method` VARCHAR(50) DEFAULT 'cash',
  `box_id` INT(11) NOT NULL DEFAULT 1,
  `sector_id` INT(11) DEFAULT NULL,
  `currency_code` VARCHAR(10) DEFAULT 'YER',
  `exchange_rate` DECIMAL(15,4) DEFAULT 1.0000,
  `remark` TEXT DEFAULT NULL,
  `user_id` INT(11) DEFAULT NULL,
  `d_s` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_rec_voucher_no` (`voucher_no`),
  KEY `idx_rec_party` (`party_type`,`party_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='رأس سند القبض';

CREATE TABLE IF NOT EXISTS `receipt_vouchers_dtl` (
  `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `voucher_id` INT(10) UNSIGNED NOT NULL,
  `reference_type` VARCHAR(50) DEFAULT NULL,
  `reference_id` INT(11) DEFAULT NULL,
  `amount` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `remark` VARCHAR(255) DEFAULT NULL,
  `d_s` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_rec_dtl_vouch` (`voucher_id`),
  CONSTRAINT `fk_rec_vou_dtl_mst` FOREIGN KEY (`voucher_id`) REFERENCES `receipt_vouchers_mst` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='تفاصيل سند القبض';

-- 6. Payment Vouchers Master & Detail
CREATE TABLE IF NOT EXISTS `payment_vouchers_mst` (
  `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `voucher_no` VARCHAR(50) NOT NULL,
  `voucher_date` DATE NOT NULL,
  `party_type` ENUM('supplier','employee','other') NOT NULL DEFAULT 'supplier',
  `party_id` INT(11) DEFAULT NULL,
  `party_name` VARCHAR(150) NOT NULL,
  `total_amount` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `payment_method` VARCHAR(50) DEFAULT 'cash',
  `box_id` INT(11) NOT NULL DEFAULT 1,
  `sector_id` INT(11) DEFAULT NULL,
  `currency_code` VARCHAR(10) DEFAULT 'YER',
  `exchange_rate` DECIMAL(15,4) DEFAULT 1.0000,
  `remark` TEXT DEFAULT NULL,
  `user_id` INT(11) DEFAULT NULL,
  `d_s` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_pay_voucher_no` (`voucher_no`),
  KEY `idx_pay_party` (`party_type`,`party_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='رأس سند الصرف';

CREATE TABLE IF NOT EXISTS `payment_vouchers_dtl` (
  `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `voucher_id` INT(10) UNSIGNED NOT NULL,
  `expense_account_id` INT(11) DEFAULT NULL,
  `amount` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `remark` VARCHAR(255) DEFAULT NULL,
  `d_s` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_pay_dtl_vouch` (`voucher_id`),
  CONSTRAINT `fk_pay_vou_dtl_mst` FOREIGN KEY (`voucher_id`) REFERENCES `payment_vouchers_mst` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='تفاصيل سند الصرف';
