-- ======================================================
-- Patch v5: إدارة الصناديق المتعددة وتحسين القيود المحاسبية
-- ======================================================

-- 1. تحديث جدول الخزينة (الصناديق) لدعم الصناديق المتعددة
ALTER TABLE `treasury`
  ADD COLUMN IF NOT EXISTS `name` VARCHAR(100) NOT NULL DEFAULT 'الصندوق الرئيسي' AFTER `box_id`,
  ADD COLUMN IF NOT EXISTS `user_id` INT(11) NULL DEFAULT NULL AFTER `remark`,
  ADD COLUMN IF NOT EXISTS `is_active` TINYINT(1) NOT NULL DEFAULT 1 AFTER `user_id`;

-- التأكد من تحديث الصندوق الرئيسي الأول
UPDATE `treasury` SET `name` = 'الصندوق الرئيسي' WHERE `box_id` = 1;

-- 2. إضافة حقل box_id للجداول المالية والتجارية لربط العمليات بالصندوق المحدد
ALTER TABLE `treasury_transactions` ADD COLUMN IF NOT EXISTS `box_id` INT(11) NOT NULL DEFAULT 1;
ALTER TABLE `receipts` ADD COLUMN IF NOT EXISTS `box_id` INT(11) NOT NULL DEFAULT 1;
ALTER TABLE `treasury_expenses` ADD COLUMN IF NOT EXISTS `box_id` INT(11) NOT NULL DEFAULT 1;
ALTER TABLE `sales` ADD COLUMN IF NOT EXISTS `box_id` INT(11) NOT NULL DEFAULT 1;
ALTER TABLE `purchases` ADD COLUMN IF NOT EXISTS `box_id` INT(11) NOT NULL DEFAULT 1;
ALTER TABLE `accounting_journal` ADD COLUMN IF NOT EXISTS `box_id` INT(11) NULL DEFAULT NULL;

ALTER TABLE `sales_returns` 
  ADD COLUMN IF NOT EXISTS `box_id` INT(11) NOT NULL DEFAULT 1,
  ADD COLUMN IF NOT EXISTS `refund_method` ENUM('cash', 'credit') NOT NULL DEFAULT 'cash';

-- 3. إنشاء جدول عمليات إقفال الصناديق والترحيل اليومي
CREATE TABLE IF NOT EXISTS `treasury_closings` (
  `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `box_id` INT(11) NOT NULL,
  `close_date` DATE NOT NULL,
  `expected_balance` DOUBLE NOT NULL,
  `actual_balance` DOUBLE NOT NULL,
  `difference` DOUBLE NOT NULL,
  `transferred_amount` DOUBLE NOT NULL DEFAULT 0,
  `user` VARCHAR(100) NOT NULL,
  `notes` VARCHAR(500) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. فهارس لتحسين أداء التقارير والاستعلام بالصناديق
CREATE INDEX IF NOT EXISTS `idx_treasury_user` ON `treasury` (`user_id`);
CREATE INDEX IF NOT EXISTS `idx_treasury_trans_box` ON `treasury_transactions` (`box_id`);
CREATE INDEX IF NOT EXISTS `idx_receipts_box` ON `receipts` (`box_id`);
CREATE INDEX IF NOT EXISTS `idx_expenses_box` ON `treasury_expenses` (`box_id`);
CREATE INDEX IF NOT EXISTS `idx_sales_box` ON `sales` (`box_id`);
CREATE INDEX IF NOT EXISTS `idx_purchases_box` ON `purchases` (`box_id`);
CREATE INDEX IF NOT EXISTS `idx_returns_box` ON `sales_returns` (`box_id`);
