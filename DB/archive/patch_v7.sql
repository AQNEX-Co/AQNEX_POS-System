-- ======================================================
-- Patch v7: إضافة الاسم الكامل للمستخدمين
-- ======================================================

-- إضافة عمود الاسم الكامل لجدول المستخدمين
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `full_name` varchar(150) DEFAULT NULL COMMENT 'الاسم الكامل للموظف' AFTER `username`;

-- تحديث الاسم الكامل الافتراضي من اسم المستخدم للموظفين الحاليين
UPDATE `users` SET `full_name` = `username` WHERE `full_name` IS NULL OR `full_name` = '';
