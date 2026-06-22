-- ======================================================
-- Patch v3: إصلاحات شاملة للمشاكل المكتشفة
-- تاريخ: 2026-06-19
-- ======================================================

-- 1. توسيع عمود كلمة المرور في جدول المستخدمين (كان varchar(20) فقط!)
ALTER TABLE `users` MODIFY COLUMN `password` varchar(100) NOT NULL DEFAULT '';

-- 2. توسيع عمود اسم المنتج في فواتير المبيعات (كان varchar(30) يتجاوز الأسماء الطويلة)
ALTER TABLE `sales_items` MODIFY COLUMN `name` varchar(200) DEFAULT NULL;

-- 3. إضافة عمود الصلاحيات الفردية (custom) على مستوى المستخدم
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `custom_permissions` text DEFAULT NULL COMMENT 'صلاحيات مخصصة للمستخدم تتجاوز صلاحيات الدور';

-- 4. إضافة عمود remaining في sales_items إن لم يكن موجوداً (ر صريح للمديونية)
ALTER TABLE `sales_items` ADD COLUMN IF NOT EXISTS `remaining` double DEFAULT 0 COMMENT 'المتبقي للعميل على هذا البند';

-- 5. إضافة عمود currency_code و exchange_rate في sales_items للتوافق مع تعدد العملات
ALTER TABLE `sales_items` ADD COLUMN IF NOT EXISTS `currency_code` varchar(10) DEFAULT 'YER';
ALTER TABLE `sales_items` ADD COLUMN IF NOT EXISTS `exchange_rate` double DEFAULT 1.0;

-- 6. توسيع عمود username في users (كان varchar(20) محدوداً)
ALTER TABLE `users` MODIFY COLUMN `username` varchar(100) NOT NULL DEFAULT '';

-- 7. إضافة عمود rem في sales الجدول الرئيسي لمتابعة المديونية الكلية للفاتورة
ALTER TABLE `sales` ADD COLUMN IF NOT EXISTS `remaining_total` double DEFAULT 0;

-- 8. إنشاء جدول مردودات المبيعات (Sales Returns)
CREATE TABLE IF NOT EXISTS `sales_returns` (
  `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `sales_id` int(11) DEFAULT NULL COMMENT 'رقم الفاتورة الأصلية',
  `product_id` int(11) DEFAULT NULL,
  `product_name` varchar(200) DEFAULT NULL,
  `quantity` int(11) DEFAULT 1,
  `unit_price` double DEFAULT 0,
  `refund_amount` double DEFAULT 0,
  `reason` varchar(200) DEFAULT NULL,
  `return_date` date DEFAULT NULL,
  `user` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 9. التأكد من وجود بيانات افتراضية في جدول العملات
INSERT IGNORE INTO `currencies` (`id`, `name`, `code`, `symbol`, `exchange_rate`, `is_base`)
VALUES (1, 'ريال يمني', 'YER', 'ر.ي', 1.0, 1);

-- 10. التأكد من وجود صف الإعدادات
INSERT INTO `settings` (`id`, `store_name`, `phone`, `address`, `currency`, `barcode_scanner`, `printer_type`, `tax_percent`, `low_stock_threshold`, `receipt_footer`, `cashier_permissions`, `inventory_permissions`)
VALUES (1, 'تكنولوجيا فون', '777777777', 'اليمن - عدن', 'ريال يمني', 1, 'receipt_80mm', 0, 5, 'شكراً لزيارتكم!', 'sales,customers,receipts', 'products,categories,purchases,suppliers')
ON DUPLICATE KEY UPDATE id=id;
