-- 1. إعادة تسمية الجداول لتبسيطها وجعلها احترافية وواضحة
RENAME TABLE `cat` TO `categories`;
RENAME TABLE `user` TO `users`;
RENAME TABLE `allbuys` TO `purchases`;
RENAME TABLE `buys` TO `purchase_items`;
RENAME TABLE `sales_product` TO `sales_items`;
RENAME TABLE `box` TO `treasury`;
RENAME TABLE `boxx` TO `treasury_transactions`;
RENAME TABLE `bush` TO `supplier_payments`;
RENAME TABLE `mcust` TO `expenses`;
RENAME TABLE `mq` TO `receipts`;
RENAME TABLE `ms` TO `treasury_expenses`;

-- 2. إضافة حقل الباركود لجدول المنتجات
ALTER TABLE `products` ADD COLUMN `barcode` varchar(50) DEFAULT NULL AFTER `name`;
ALTER TABLE `products` ADD UNIQUE INDEX `idx_products_barcode` (`barcode`);

-- 3. إنشاء جدول الإعدادات العامة لتهيئة المتجر
CREATE TABLE IF NOT EXISTS `settings` (
  `id` int(11) NOT NULL PRIMARY KEY,
  `store_name` varchar(100) NOT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `currency` varchar(20) DEFAULT 'ريال يمني',
  `barcode_scanner` tinyint(1) DEFAULT 1,
  `printer_type` varchar(50) DEFAULT 'receipt_80mm',
  `tax_percent` double DEFAULT 0,
  `low_stock_threshold` int(11) DEFAULT 5,
  `receipt_footer` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. إدراج الإعدادات الافتراضية
INSERT INTO `settings` (`id`, `store_name`, `phone`, `address`, `currency`, `barcode_scanner`, `printer_type`, `tax_percent`, `low_stock_threshold`, `receipt_footer`) 
VALUES (1, 'تكنولوجيا فون', '777777777', 'اليمن - عدن', 'ريال يمني', 1, 'receipt_80mm', 0, 5, 'شكرًا لزيارتكم! البضاعة المباعة لا ترد ولا تستبدل إلا خلال 3 أيام بموجب الفاتورة.')
ON DUPLICATE KEY UPDATE id=id;
