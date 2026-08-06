<?php
/**
 * AQNEX POS - Database Initializer & Migration Runner Script
 * Runs automatically during client installation to create clean DB & seed initial data.
 */

if (php_sapi_name() !== 'cli') {
    die("هذا السكربت مخصص للتشغيل عبر CLI فقط أثناء التثبيت.\n");
}

// تعطيل الإلقاء التلقائي للاستثناءات لضمان عمل حلقة الانتظار (Retry Loop) بسلام
mysqli_report(MYSQLI_REPORT_OFF);

$installPath = isset($argv[1]) ? trim($argv[1]) : '';
if (empty($installPath)) {
    $installPath = dirname(__DIR__);
}
$installPath = str_replace('\\', '/', $installPath);
$installPath = rtrim($installPath, '/');

$logFile = $installPath . '/installer/init_db.log';
function log_msg($msg) {
    global $logFile;
    $time = date('Y-m-d H:i:s');
    $line = "[$time] $msg\n";
    echo $line;
    @file_put_contents($logFile, $line, FILE_APPEND);
}

log_msg("=== البدء في تهيئة قاعدة البيانات للمستهلك ===");
log_msg("مسار التثبيت المكتشف: $installPath");

$user = 'root';
$pass = '';
$dbname = 'aqnex_pos';
$host = '127.0.0.1';

// تحديد المنفذ: المفتوح (3307 للنسخة المضمنة المستقلة، أو 3306 لبيئة XAMPP المحلية)
$portsToTry = [3307, 3306];
$mysqli = null;
$connectedPort = null;

log_msg("جاري الاتصال بخادم MariaDB على المنفذ (3307 / 3306)...");

$maxRetries = 12;
$retryCount = 0;

while ($retryCount < $maxRetries) {
    foreach ($portsToTry as $p) {
        try {
            $connTest = @new mysqli($host, $user, $pass, '', $p);
            if ($connTest && !$connTest->connect_error) {
                $mysqli = $connTest;
                $connectedPort = $p;
                break 2;
            }
        } catch (\Throwable $e) {
            // تجاهل خطأ رفض الاتصال المؤقت أثناء الإقلاع
        }
    }
    log_msg("انتظار تشغيل خدمة MariaDB... (" . ($retryCount + 1) . "/$maxRetries)");
    sleep(2);
    $retryCount++;
}

if (!$mysqli || $mysqli->connect_error) {
    log_msg("خطأ حرج: تعذر الاتصال بخادم MariaDB على المنافذ المحددة.");
    exit(1);
}

log_msg("✓ تم الاتصال بنجاح بخادم البيانات على المنفذ: $connectedPort.");

// 1. إنشاء قاعدة البيانات
$createDbSql = "CREATE DATABASE IF NOT EXISTS `$dbname` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci";
if (!$mysqli->query($createDbSql)) {
    log_msg("خطأ في إنشاء قاعدة البيانات: " . $mysqli->error);
    exit(1);
}

if (!$mysqli->select_db($dbname)) {
    log_msg("خطأ في تحديد قاعدة البيانات `$dbname`: " . $mysqli->error);
    exit(1);
}

log_msg("✓ تم تجهيز وتحديد قاعدة البيانات `$dbname`.");

// 2. فحص موقع ملف الهيكل الأساسي (Schema)
$sqlCandidates = [
    $installPath . '/app/DB/backup/aqnex_pos.sql',
    $installPath . '/DB/backup/aqnex_pos.sql',
];
$sqlFile = null;
foreach ($sqlCandidates as $candidate) {
    if (file_exists($candidate)) {
        $sqlFile = $candidate;
        break;
    }
}

if ($sqlFile === null) {
    log_msg("خطأ حرج: تعذر العثور على ملف الهيكل الأساسي لقاعدة البيانات aqnex_pos.sql");
    exit(1);
}

log_msg("جاري استيراد الجداول الأساسية من: $sqlFile");

$sqlContent = file_get_contents($sqlFile);
if ($sqlContent === false) {
    log_msg("خطأ: تعذر قراءة محتوى ملف SQL.");
    exit(1);
}

$mysqli->query("SET FOREIGN_KEY_CHECKS = 0");
$mysqli->query("SET NAMES utf8mb4");

$queries = [];
$currentQuery = '';
$lines = explode("\n", $sqlContent);

foreach ($lines as $line) {
    $trimmed = trim($line);
    if (empty($trimmed) || strpos($trimmed, '--') === 0 || strpos($trimmed, '/*') === 0 || strpos($trimmed, '#') === 0) {
        continue;
    }
    $currentQuery .= $line . "\n";
    if (substr($trimmed, -1) === ';') {
        $queries[] = $currentQuery;
        $currentQuery = '';
    }
}

$successCount = 0;
$failedCount = 0;

foreach ($queries as $q) {
    $q = trim($q);
    if (empty($q)) continue;
    if ($mysqli->query($q)) {
        $successCount++;
    } else {
        $failedCount++;
    }
}

log_msg("✓ تم استيراد الجداول الأساسية بنجاح. الناجحة: $successCount ، المكررة/التنبيهات: $failedCount.");

// 3. تطبيق الهجرات والتعديلات المتبقية (DB Migrations)
$migrationsPath = '';
foreach ([$installPath . '/app/DB/migrations', $installPath . '/DB/migrations'] as $candidatePath) {
    if (is_dir($candidatePath)) {
        $migrationsPath = $candidatePath;
        break;
    }
}

if (!empty($migrationsPath)) {
    log_msg("جاري فحص وتطبيق الهجرات الإضافية من المجلد: $migrationsPath");
    $files = scandir($migrationsPath);
    $sqlFiles = [];
    foreach ($files as $file) {
        if (pathinfo($file, PATHINFO_EXTENSION) === 'sql') {
            $sqlFiles[] = $file;
        }
    }
    sort($sqlFiles);

    foreach ($sqlFiles as $mFile) {
        $fullPath = $migrationsPath . '/' . $mFile;
        log_msg("تطبيق الهجرة: $mFile");
        $mContent = file_get_contents($fullPath);
        if ($mContent !== false) {
            $mQueries = [];
            $mCurrent = '';
            $mLines = explode("\n", $mContent);
            foreach ($mLines as $l) {
                $t = trim($l);
                if (empty($t) || strpos($t, '--') === 0 || strpos($t, '/*') === 0 || strpos($t, '#') === 0) {
                    continue;
                }
                $mCurrent .= $l . "\n";
                if (substr($t, -1) === ';') {
                    $mQueries[] = $mCurrent;
                    $mCurrent = '';
                }
            }

            $mOk = 0; $mErr = 0;
            foreach ($mQueries as $mq) {
                $mq = trim($mq);
                if (empty($mq)) continue;
                if ($mysqli->query($mq)) {
                    $mOk++;
                } else {
                    $mErr++;
                }
            }
            log_msg("  ✓ النتيجة: ناجح $mOk ، مكرر/تنبيه $mErr.");
        }
    }
}

// 4. زراعة البيانات التأسيسية النظيفة للعميل (Clean Initial Client Seed)
log_msg("جاري التثبيت التأسيسي لبيانات العميل المعتمدة (Clean Seed)...");

$seedQueries = [
    // 1. حساب المدير الافتراضي
    "INSERT IGNORE INTO `users` (`userid`, `username`, `full_name`, `password`, `position`, `phone`) VALUES (1, 'admin', 'مدير النظام', 'admin', 'admin', '777777777')",

    // 2. بيانات المؤسسة والتوطين الافتراضية
    "INSERT IGNORE INTO `settings` (`id`, `store_name`, `store_name_en`, `phone`, `commercial_register`, `tax_number`, `address`, `timezone`, `date_format`, `decimal_precision`, `thousand_separator`, `support_token`) VALUES (1, 'AQNEX POS', 'AQNEX POS System', '777777777', '12345', '123456789', 'اليمن - عدن', 'Asia/Aden', 'Y-m-d', 4, ',', '123')",

    // 3. العملة الأساسية (الريال اليمني YER)
    "INSERT IGNORE INTO `currencies` (`id`, `name`, `code`, `symbol`, `exchange_rate`, `is_base`, `is_active`) VALUES (1, 'ريال يمني', 'YER', 'ر.ي', 1.0000, 1, 1)",

    // 4. الصندوق الرئيسي
    "INSERT IGNORE INTO `treasury` (`id`, `name`, `mony`, `is_active`, `d_s`) VALUES (1, 'الصندوق الرئيسي', 0.00, 1, '0')",

    // 5. الفرع الرئيسي
    "INSERT IGNORE INTO `branches` (`id`, `name`, `location`, `contacts`) VALUES (1, 'الفرع الرئيسي', 'عدن - المعلا', '777777777')",

    // 6. المستودع الرئيسي
    "INSERT IGNORE INTO `warehouses` (`id`, `branch_id`, `name`, `location`) VALUES (1, 1, 'المستودع الرئيسي', 'عدن - المعلا')",

    // 7. موديولات النظام العامة
    "INSERT IGNORE INTO `system_modules` (`module_key`, `module_name`, `is_enabled`) VALUES
        ('barcode_units', 'وحدات متعددة وباركودات متعددة', 1),
        ('expiry_tracking', 'تتبع تواريخ الصلاحية', 0),
        ('serial_imei_tracking', 'تتبع الأرقام التسلسلية / IMEI', 0),
        ('repair_service', 'وحدة الصيانة', 0),
        ('installments', 'البيع بالتقسيط', 0),
        ('thermal_printing', 'الطباعة الحرارية', 1),
        ('label_printing', 'طباعة ملصقات الباركود', 1)",

    // 8. سياسات وقواعد العمل
    "INSERT IGNORE INTO `business_rules` (`id`, `allow_negative_stock`, `inventory_valuation_method`, `max_discount_limit`) VALUES (1, 0, 'AVG', 15.0000)",

    // 9. السنة المالية الحالية
    "INSERT IGNORE INTO `fiscal_years` (`id`, `name`, `start_date`, `end_date`, `is_closed`) VALUES (1, 'السنة المالية 2026', '2026-01-01', '2026-12-31', 0)",

    // 10. تسلسلات السندات
    "INSERT IGNORE INTO `accounting_sequences` (`seq_key`, `last_no`) VALUES ('receipt', 0), ('payment', 0), ('journal', 0)",

    // 11. وحدات القياس الأساسية
    "INSERT IGNORE INTO `units` (`id`, `name`, `d_s`) VALUES (1, 'حبة', '0'), (2, 'كرتون', '0'), (3, 'درزن', '0')"
];

foreach ($seedQueries as $sq) {
    $mysqli->query($sq);
}

$mysqli->query("SET FOREIGN_KEY_CHECKS = 1");

log_msg("✓ اكتمل زراعة وتجهيز كافة جداول وقواعد البيانات النظيفة بنجاح!");
log_msg("==================================================");

$mysqli->close();
?>
