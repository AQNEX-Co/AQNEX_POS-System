-- ======================================================
-- Patch v4: القيود المحاسبية ومردودات المبيعات المحسّنة
-- ======================================================

-- جدول القيود المحاسبية (دفتر اليومية)
CREATE TABLE IF NOT EXISTS `accounting_journal` (
  `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `ref_type` enum('sale','return','purchase','expense','receipt','adjustment') NOT NULL COMMENT 'نوع المرجع',
  `ref_id` int(11) DEFAULT NULL COMMENT 'رقم المرجع (الفاتورة أو العملية)',
  `account_debit` varchar(100) NOT NULL COMMENT 'الحساب المدين',
  `account_credit` varchar(100) NOT NULL COMMENT 'الحساب الدائن',
  `amount` double NOT NULL DEFAULT 0 COMMENT 'المبلغ (ر.ي)',
  `description` varchar(500) DEFAULT NULL COMMENT 'وصف القيد',
  `currency_code` varchar(10) DEFAULT 'YER',
  `exchange_rate` double DEFAULT 1.0,
  `amount_foreign` double DEFAULT 0 COMMENT 'المبلغ بالعملة الأجنبية',
  `user` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='دفتر اليومية المحاسبية';

-- تحسين جدول مردودات المبيعات
ALTER TABLE `sales_returns`
  ADD COLUMN IF NOT EXISTS `sales_item_id` int(11) DEFAULT NULL COMMENT 'رقم البند في الفاتورة',
  ADD COLUMN IF NOT EXISTS `original_unit_price` double DEFAULT 0 COMMENT 'سعر البيع الأصلي من الفاتورة',
  ADD COLUMN IF NOT EXISTS `original_buy_price` double DEFAULT 0 COMMENT 'سعر الشراء الأصلي',
  ADD COLUMN IF NOT EXISTS `profit_impact` double DEFAULT 0 COMMENT 'تأثير المرتجع على الربح (سالب)',
  ADD COLUMN IF NOT EXISTS `currency_code` varchar(10) DEFAULT 'YER',
  ADD COLUMN IF NOT EXISTS `exchange_rate` double DEFAULT 1.0,
  ADD COLUMN IF NOT EXISTS `status` enum('active','cancelled') DEFAULT 'active';

-- ربط المرتجع بالفاتورة الأصلية - تحديث حقل sales_id
ALTER TABLE `sales_returns` MODIFY COLUMN `sales_id` int(11) NOT NULL DEFAULT 0;

-- جدول ملاحظات لإلغاء وتعديل الفواتير
CREATE TABLE IF NOT EXISTS `sales_cancellations` (
  `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `sales_id` int(11) NOT NULL,
  `reason` varchar(500) DEFAULT NULL,
  `user` varchar(100) DEFAULT NULL,
  `cancelled_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- إضافة حقل remaining_total في sales إن لم يكن موجودا
ALTER TABLE `sales` ADD COLUMN IF NOT EXISTS `remaining_total` double DEFAULT 0;
ALTER TABLE `sales` ADD COLUMN IF NOT EXISTS `profit_total` double DEFAULT 0 COMMENT 'الربح الفعلي بعد المردودات';

-- فهارس لتحسين الأداء
CREATE INDEX IF NOT EXISTS `idx_journal_ref` ON `accounting_journal` (`ref_type`, `ref_id`);
CREATE INDEX IF NOT EXISTS `idx_returns_sales` ON `sales_returns` (`sales_id`);
