-- ======================================================
-- Patch v6: جداول النظام التجاري والترخيص والأمان
-- ======================================================

-- 1. جدول سجل الترخيص والتفعيل
CREATE TABLE IF NOT EXISTS `system_licensing` (
  `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `machine_id` varchar(255) NOT NULL UNIQUE COMMENT 'المعرف الفريد للجهاز المولد من الهاردوير',
  `company_name` varchar(150) NOT NULL COMMENT 'اسم المنشأة المرخصة',
  `owner_name` varchar(100) NOT NULL,
  `phone` varchar(50) NOT NULL,
  `city` varchar(50) NOT NULL,
  `license_type` enum('trial', 'daily', 'weekly', 'monthly', 'yearly', 'lifetime') NOT NULL,
  `start_date` date NOT NULL,
  `expiry_date` date NOT NULL,
  `modules_enabled` text NOT NULL COMMENT 'قائمة الوحدات المفعلة مفصولة بفاصلة',
  `max_users` int(11) NOT NULL DEFAULT 1,
  `max_branches` int(11) NOT NULL DEFAULT 1,
  `license_key` text NOT NULL COMMENT 'محتوى الترخيص المشفر والموقع رقمياً',
  `activation_status` tinyint(1) NOT NULL DEFAULT 0,
  `tampering_lock` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'قفل أمني عند اكتشاف تلاعب بالتوقيت',
  `activated_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. جدول حماية التوقيت وكشف تلاعب التاريخ
CREATE TABLE IF NOT EXISTS `system_time_check` (
  `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `last_run_date` date NOT NULL COMMENT 'آخر تاريخ تم التحقق فيه من النظام',
  `last_run_time` datetime NOT NULL COMMENT 'آخر تاريخ ووقت دقيق تم تسجيله للنظام',
  `client_ip` varchar(45) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. جدول سجل النسخ الاحتياطي
CREATE TABLE IF NOT EXISTS `system_backups` (
  `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `backup_name` varchar(150) NOT NULL COMMENT 'اسم ملف النسخة الاحتياطية',
  `file_path` varchar(255) NOT NULL,
  `backup_type` enum('manual', 'daily', 'weekly') NOT NULL,
  `file_size` bigint(20) NOT NULL COMMENT 'حجم الملف بالبايت',
  `created_by` varchar(100) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. جدول سجل التحديثات والنظام
CREATE TABLE IF NOT EXISTS `system_updates` (
  `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `version` varchar(30) NOT NULL COMMENT 'رقم الإصدار (e.g. 2.1.0)',
  `released_date` date DEFAULT NULL,
  `applied_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `description` text DEFAULT NULL,
  `status` enum('success', 'failed') NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 5. توسيع جدول الإعدادات العامة لإضافة حقول الشركة الإضافية لـ Setup Wizard
ALTER TABLE `settings` 
  ADD COLUMN IF NOT EXISTS `commercial_register` varchar(100) DEFAULT NULL AFTER `address`,
  ADD COLUMN IF NOT EXISTS `tax_number` varchar(100) DEFAULT NULL AFTER `commercial_register`,
  ADD COLUMN IF NOT EXISTS `logo` varchar(255) DEFAULT NULL AFTER `receipt_footer`,
  ADD COLUMN IF NOT EXISTS `is_configured` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'تحديد إذا ما تم تشغيل معالج الإعداد الأول';
