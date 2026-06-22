-- ======================================================
-- Patch v8: إضافة جدول مردود المشتريات وتصحيح مردود المبيعات
-- ======================================================

-- 1. إضافة عمود مصدر استرداد الأموال لجدول مردود المبيعات إن لم يكن موجوداً
ALTER TABLE `sales_returns`
  ADD COLUMN IF NOT EXISTS `refund_source` varchar(50) DEFAULT 'box' AFTER `refund_method`;

-- 2. إنشاء جدول مردود المشتريات
CREATE TABLE IF NOT EXISTS `purchase_returns` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `purchase_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `product_name` varchar(200) NOT NULL,
  `quantity` int(11) NOT NULL,
  `unit_price` double NOT NULL,
  `refund_amount` double NOT NULL,
  `reason` varchar(200) DEFAULT NULL,
  `return_date` date NOT NULL,
  `user` varchar(100) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `currency_code` varchar(10) DEFAULT 'YER',
  `exchange_rate` double DEFAULT 1,
  `status` enum('active','cancelled') DEFAULT 'active',
  `box_id` int(11) DEFAULT NULL,
  `refund_method` enum('cash','credit') DEFAULT 'cash',
  `refund_source` varchar(50) DEFAULT 'box',
  PRIMARY KEY (`id`),
  KEY `purchase_id` (`purchase_id`),
  KEY `box_id` (`box_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
