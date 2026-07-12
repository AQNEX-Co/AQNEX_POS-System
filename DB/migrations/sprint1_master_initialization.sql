-- Sprint 1 - Master System Initialization (ERP DNA) Migration Script

-- 1. Company Profile & Localization Updates in settings table
ALTER TABLE `settings` 
  ADD COLUMN `industry_type` VARCHAR(100) DEFAULT NULL,
  ADD COLUMN `timezone` VARCHAR(100) DEFAULT 'Asia/Aden',
  ADD COLUMN `date_format` VARCHAR(50) DEFAULT 'Y-m-d',
  ADD COLUMN `decimal_precision` INT DEFAULT 4,
  ADD COLUMN `thousand_separator` VARCHAR(5) DEFAULT ',';

-- 2. Organizational Structure
CREATE TABLE IF NOT EXISTS `branches` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(150) NOT NULL,
  `location` VARCHAR(255) DEFAULT NULL,
  `contacts` VARCHAR(255) DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `warehouses` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `branch_id` INT NOT NULL,
  `name` VARCHAR(150) NOT NULL,
  `location` VARCHAR(255) DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 3. Currency Engine Precision Alignment
ALTER TABLE `currencies` MODIFY COLUMN `exchange_rate` DECIMAL(15,4) NOT NULL DEFAULT 1.0000;

-- 4. Fiscal Setup
CREATE TABLE IF NOT EXISTS `fiscal_years` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(50) NOT NULL,
  `start_date` DATE NOT NULL,
  `end_date` DATE NOT NULL,
  `is_closed` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 5. Advanced Security (RBAC)
CREATE TABLE IF NOT EXISTS `roles` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(50) NOT NULL UNIQUE,
  `description` VARCHAR(255) DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `permissions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `permission_key` VARCHAR(100) NOT NULL UNIQUE,
  `name` VARCHAR(150) NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `role_permissions` (
  `role_id` INT NOT NULL,
  `permission_id` INT NOT NULL,
  PRIMARY KEY (`role_id`, `permission_id`),
  FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Update users table to support RBAC links
ALTER TABLE `users`
  ADD COLUMN `role_id` INT DEFAULT NULL,
  ADD COLUMN `branch_id` INT DEFAULT NULL,
  ADD COLUMN `warehouse_id` INT DEFAULT NULL,
  ADD CONSTRAINT `fk_users_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_users_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_users_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`) ON DELETE SET NULL;

-- 6. Operational Rules
CREATE TABLE IF NOT EXISTS `business_rules` (
  `id` INT PRIMARY KEY,
  `allow_negative_stock` TINYINT(1) NOT NULL DEFAULT 0,
  `inventory_valuation_method` ENUM('FIFO', 'AVG') NOT NULL DEFAULT 'AVG',
  `max_discount_limit` DECIMAL(15,4) NOT NULL DEFAULT 100.0000,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 7. Seed Initial Core Data within migration
INSERT INTO `branches` (`id`, `name`, `location`, `contacts`) VALUES (1, 'الفرع الرئيسي', 'عدن - المعلا', '777777777')
ON DUPLICATE KEY UPDATE id=id;

INSERT INTO `warehouses` (`id`, `branch_id`, `name`, `location`) VALUES (1, 1, 'المستودع الرئيسي', 'عدن - المعلا')
ON DUPLICATE KEY UPDATE id=id;

INSERT INTO `roles` (`id`, `name`, `description`) VALUES 
(1, 'admin', 'مدير النظام'),
(2, 'cashier', 'كاشير مبيعات'),
(3, 'inventory', 'مسؤول مخازن')
ON DUPLICATE KEY UPDATE id=id;

INSERT INTO `permissions` (`id`, `permission_key`, `name`) VALUES 
(1, 'sales', 'إدارة المبيعات'),
(2, 'purchases', 'إدارة المشتريات'),
(3, 'products', 'إدارة المنتجات'),
(4, 'repair', 'إدارة الصيانة'),
(5, 'accounting', 'إدارة الحسابات واليومية'),
(6, 'settings', 'إدارة الإعدادات والتهيئة')
ON DUPLICATE KEY UPDATE id=id;

-- Link roles to permissions
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`) VALUES
(1, 1), (1, 2), (1, 3), (1, 4), (1, 5), (1, 6), -- Admin has all
(2, 1), (2, 4),                                 -- Cashier has sales, repair
(3, 3)                                          -- Inventory has products
;

-- Update existing users to match their positions in new RBAC
UPDATE `users` SET `role_id` = 1, `branch_id` = 1, `warehouse_id` = 1 WHERE `position` = 'admin';
UPDATE `users` SET `role_id` = 2, `branch_id` = 1, `warehouse_id` = 1 WHERE `position` = 'cashier';
UPDATE `users` SET `role_id` = 3, `branch_id` = 1, `warehouse_id` = 1 WHERE `position` = 'inventory' OR `position` = 'helper';

-- Seed initial fiscal year
INSERT INTO `fiscal_years` (`id`, `name`, `start_date`, `end_date`, `is_closed`) VALUES
(1, 'السنة المالية 2026', '2026-01-01', '2026-12-31', 0)
ON DUPLICATE KEY UPDATE id=id;

-- Seed default business rules
INSERT INTO `business_rules` (`id`, `allow_negative_stock`, `inventory_valuation_method`, `max_discount_limit`) VALUES
(1, 0, 'AVG', 15.0000)
ON DUPLICATE KEY UPDATE id=id;
