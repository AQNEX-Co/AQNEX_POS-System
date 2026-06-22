<?php
/**
 * سكربت تحديث قاعدة بيانات العميل تلقائياً عند تشغيل ملف الترقية (Inno Setup Patch)
 */

// جلب مسار البرنامج الرئيسي الممرر من معالج التنصيب
$appPath = $argv[1] ?? 'C:/AQNEX_POS';
$connectPath = $appPath . '/app/includes/connect.php';

if (file_exists($connectPath)) {
    // تعطيل مخرجات bootstrap لتجنب مقاطعة السكربت
    ob_start();
    require_once($connectPath);
    ob_end_clean();

    if (isset($conn) && !$conn->connect_error) {
        // التحقق من وجود حقل التهيئة is_configured
        $checkCol = $conn->query("SHOW COLUMNS FROM `settings` LIKE 'is_configured'");
        if ($checkCol && $checkCol->num_rows == 0) {
            // إضافة الحقول المفقودة لتفادي انهيار معالج الإعداد
            $conn->query("ALTER TABLE `settings` ADD COLUMN `commercial_register` varchar(100) DEFAULT NULL AFTER `address`");
            $conn->query("ALTER TABLE `settings` ADD COLUMN `tax_number` varchar(100) DEFAULT NULL AFTER `commercial_register`");
            $conn->query("ALTER TABLE `settings` ADD COLUMN `logo` varchar(255) DEFAULT NULL AFTER `receipt_footer`");
            $conn->query("ALTER TABLE `settings` ADD COLUMN `is_configured` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'تحديد إذا ما تم تشغيل معالج الإعداد الأول'");
        }
    }
}
?>
