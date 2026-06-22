-- 1. إضافة حقول الصلاحيات المخصصة للأدوار في جدول الإعدادات
ALTER TABLE `settings` 
ADD COLUMN `cashier_permissions` text DEFAULT NULL,
ADD COLUMN `inventory_permissions` text DEFAULT NULL;

-- 2. تحديث صلاحيات الكاشير وأمين المستودع الافتراضية
UPDATE `settings` SET 
`cashier_permissions` = 'sales,customers,receipts', 
`inventory_permissions` = 'products,categories,purchases,suppliers' 
WHERE id = 1;

-- 3. إضافة حقول العملة المحددة وسعر الصرف المعتمد لفواتير المبيعات والمشتريات
ALTER TABLE `sales` 
ADD COLUMN `currency_code` varchar(10) DEFAULT 'YER', 
ADD COLUMN `exchange_rate` double DEFAULT 1.0;

ALTER TABLE `purchases` 
ADD COLUMN `currency_code` varchar(10) DEFAULT 'YER', 
ADD COLUMN `exchange_rate` double DEFAULT 1.0;

-- 4. إنشاء جدول العملات لإدارة أسعار الصرف بالنسبة للعملة الأساسية (YER)
CREATE TABLE IF NOT EXISTS `currencies` (
  `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `name` varchar(100) NOT NULL,
  `code` varchar(10) NOT NULL UNIQUE,
  `symbol` varchar(10) NOT NULL,
  `exchange_rate` double NOT NULL DEFAULT 1.0,
  `is_base` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 5. إدراج العملات الثلاث الرئيسية
INSERT INTO `currencies` (`name`, `code`, `symbol`, `exchange_rate`, `is_base`) VALUES
('ريال يمني', 'YER', 'ر.ي', 1.0, 1),
('دولار أمريكي', 'USD', '$', 530.0, 0),
('ريال سعودي', 'SAR', 'ر.س', 140.0, 0)
ON DUPLICATE KEY UPDATE id=id;

-- 6. إنشاء جدول سجل حركة المخزون يدعم جرد المنتجات والتسويات اليدوية
CREATE TABLE IF NOT EXISTS `inventory_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `product_id` int(11) NOT NULL,
  `product_name` varchar(255) NOT NULL,
  `type` enum('purchase', 'sale', 'manual') NOT NULL,
  `qty_change` int(11) NOT NULL,
  `new_qty` int(11) NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `user` varchar(100) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
