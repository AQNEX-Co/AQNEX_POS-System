<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// تحميل ملفات النواة الحساسة
require_once(__DIR__ . '/Licensing.php');
require_once(__DIR__ . '/AntiTamper.php');

// تحديد المسار الأساسي للمشروع ديناميكياً لتجنب مشاكل المجلدات الفرعية
$projectRoot = str_replace('\\', '/', realpath(__DIR__ . '/../'));
$docRoot = str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']);
$baseUrl = '';
if (strpos($projectRoot, $docRoot) === 0) {
    $baseUrl = substr($projectRoot, strlen($docRoot));
}
$baseUrl = '/' . ltrim(str_replace('\\', '/', $baseUrl), '/');
$baseUrl = rtrim($baseUrl, '/');

// المسار الحالي للصفحة المطلوبة
$currentUri = str_replace('\\', '/', $_SERVER['SCRIPT_NAME']);

// استثناء ملفات الأصول (Assets) والـ AJAX لتجنب مقاطعة الاتصالات الخلفية
$isAsset = preg_match('/\.(css|js|png|jpg|jpeg|gif|ico|svg|woff|woff2|ttf|eot)$/i', $currentUri);
$isAjax = (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') 
          || (strpos($currentUri, '/ajax/') !== false);

if ($isAsset || $isAjax) {
    return; // عدم إجراء التحقق على الأصول أو طلبات AJAX الخلفية
}

// الصفحات الحساسة الخاصة بمعالجات النظام
$activateUrl = $baseUrl . '/auth/activate.php';
$tamperingUrl = $baseUrl . '/auth/tampering.php';
$setupUrl = $baseUrl . '/auth/setup_wizard.php';
$loginUrl = $baseUrl . '/auth/login.php';

// تهيئة الكلاسات الأساسية للتحقق
$licensing = new \AQNEX\Core\Licensing();
$antiTamper = new \AQNEX\Core\AntiTamper($conn);

// 1. التحقق من ملف الترخيص
$verify = $licensing->verifyLicense();
if (!$verify['status']) {
    // الترخيص غير صالح أو غير موجود
    if ($currentUri !== $activateUrl) {
        header("Location: " . $activateUrl);
        exit();
    }
} else {
    // الترخيص صالح. فحص كشف التلاعب بالوقت
    $isTimeValid = $antiTamper->checkSystemTime();
    $isLocked = $antiTamper->isLocked();
    
    if (!$isTimeValid || $isLocked) {
        if ($currentUri !== $tamperingUrl) {
            header("Location: " . $tamperingUrl);
            exit();
        }
    } else {
        // التأكد من وجود جدول الإعدادات وإنشائه إن كان مفقوداً
        $checkTable = $conn->query("SHOW TABLES LIKE 'settings'");
        if ($checkTable && $checkTable->num_rows == 0) {
            $conn->query("CREATE TABLE `settings` (
              `id` int(11) NOT NULL PRIMARY KEY,
              `store_name` varchar(100) NOT NULL,
              `phone` varchar(50) DEFAULT NULL,
              `address` text DEFAULT NULL,
              `commercial_register` varchar(100) DEFAULT NULL,
              `tax_number` varchar(100) DEFAULT NULL,
              `currency` varchar(20) DEFAULT 'ريال يمني',
              `barcode_scanner` tinyint(1) DEFAULT 1,
              `printer_type` varchar(50) DEFAULT 'receipt_80mm',
              `tax_percent` double DEFAULT 0,
              `low_stock_threshold` int(11) DEFAULT 5,
              `receipt_footer` text DEFAULT NULL,
              `logo` varchar(255) DEFAULT NULL,
              `is_configured` tinyint(1) NOT NULL DEFAULT 0
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            
            $conn->query("INSERT INTO `settings` (`id`, `store_name`, `phone`, `address`, `currency`, `barcode_scanner`, `printer_type`, `tax_percent`, `low_stock_threshold`, `receipt_footer`, `is_configured`) 
                VALUES (1, 'تكنولوجيا فون', '777777777', 'اليمن - عدن', 'ريال يمني', 1, 'receipt_80mm', 0, 5, 'شكرًا لزيارتكم!', 0)
                ON DUPLICATE KEY UPDATE id=id");
        } else {
            // التأكد من وجود الأعمدة الجديدة في جدول الإعدادات لتفادي مشاكل الترقية أو التهيئة الأولى
            $checkCol = $conn->query("SHOW COLUMNS FROM `settings` LIKE 'is_configured'");
            if ($checkCol && $checkCol->num_rows == 0) {
                $conn->query("ALTER TABLE `settings` ADD COLUMN `commercial_register` varchar(100) DEFAULT NULL AFTER `address`");
                $conn->query("ALTER TABLE `settings` ADD COLUMN `tax_number` varchar(100) DEFAULT NULL AFTER `commercial_register`");
                $conn->query("ALTER TABLE `settings` ADD COLUMN `logo` varchar(255) DEFAULT NULL AFTER `receipt_footer`");
                $conn->query("ALTER TABLE `settings` ADD COLUMN `is_configured` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'تحديد إذا ما تم تشغيل معالج الإعداد الأول'");
            }
        }

        // الترخيص والوقت سليمان. فحص معالج الإعداد الأول
        $settingsRes = $conn->query("SELECT is_configured FROM settings WHERE id = 1");
        $settings = $settingsRes ? $settingsRes->fetch_assoc() : null;
        $isConfigured = $settings ? intval($settings['is_configured']) : 0;
        
        if ($isConfigured === 0) {
            if ($currentUri !== $setupUrl && $currentUri !== $activateUrl) {
                header("Location: " . $setupUrl);
                exit();
            }
        } else {
            // النظام مفعل ومعد بالكامل. منع الدخول لصفحات التهيئة والتلاعب وإعادتهم للرئيسية
            if ($currentUri === $setupUrl || $currentUri === $tamperingUrl) {
                header("Location: " . $baseUrl . "/home.php");
                exit();
            }
        }
}}
?>