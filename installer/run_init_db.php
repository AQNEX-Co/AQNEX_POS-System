<?php
/**
 * سكربت تهيئة قاعدة البيانات واستيراد النسخة الاحتياطية أثناء التثبيت.
 */

// if (php_sapi_name() !== 'cli') {
//     die("هذا السكربت مخصص للتشغيل عبر CLI فقط أثناء التثبيت.");
// }

$installPath = isset($argv[1]) ? trim($argv[1]) : '';
if (empty($installPath)) {
    // محاولة استنتاج المسار من موقع السكربت الحالي {app}\installer\run_init_db.php
    $installPath = dirname(__DIR__);
}
$installPath = str_replace('\\', '/', $installPath);
$installPath = rtrim($installPath, '/');

$host = '127.0.0.1';
$port = 3307;
$user = 'root';
$pass = '';
$dbname = 'aqnex_pos';

echo "جاري الاتصال بخادم MariaDB على المنفذ $port...\n";

// محاولة الاتصال بالخادم
$mysqli = @new mysqli($host, $user, $pass, '', $port);
if ($mysqli->connect_error) {
    die("خطأ: فشل الاتصال بخادم قاعدة البيانات: " . $mysqli->connect_error . "\n");
}

echo "✓ تم الاتصال بنجاح. جاري إنشاء قاعدة البيانات `$dbname` إن لم تكن موجودة...\n";

// إنشاء قاعدة البيانات
if (!$mysqli->query("CREATE DATABASE IF NOT EXISTS `$dbname` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci")) {
    die("خطأ: فشل إنشاء قاعدة البيانات: " . $mysqli->error . "\n");
}

// تحديد قاعدة البيانات
if (!$mysqli->select_db($dbname)) {
    die("خطأ: فشل تحديد قاعدة البيانات: " . $mysqli->error . "\n");
}

$sqlFile = $installPath . '/app/DB/backup/aqnex_pos.sql';
if (!file_exists($sqlFile)) {
    die("خطأ: ملف قاعدة البيانات الاحتياطية غير موجود في: $sqlFile\n");
}

echo "جاري قراءة واستيراد جداول قاعدة البيانات من $sqlFile...\n";

$sqlContent = file_get_contents($sqlFile);
if ($sqlContent === false) {
    die("خطأ: فشل قراءة محتوى ملف SQL.\n");
}

// تقسيم الأوامر وتشغيلها
$queries = [];
$currentQuery = '';
$lines = explode("\n", $sqlContent);

foreach ($lines as $line) {
    $trimmed = trim($line);
    // تجاوز التعليقات والأسطر الفارغة
    if (empty($trimmed) || strpos($trimmed, '--') === 0 || strpos($trimmed, '/*') === 0 || strpos($trimmed, '#') === 0) {
        continue;
    }
    
    $currentQuery .= $line . "\n";
    
    // إذا انتهى السطر بفاصلة منقوطة، فهذا يعني نهاية الأمر
    if (substr($trimmed, -1) === ';') {
        $queries[] = $currentQuery;
        $currentQuery = '';
    }
}

$successCount = 0;
$failedCount = 0;

$mysqli->query("SET FOREIGN_KEY_CHECKS = 0");
foreach ($queries as $query) {
    $query = trim($query);
    if (empty($query)) continue;
    if ($mysqli->query($query)) {
        $successCount++;
    } else {
        $failedCount++;
        echo "خطأ في استعلام: " . $mysqli->error . "\n";
    }
}
$mysqli->query("SET FOREIGN_KEY_CHECKS = 1");

echo "✓ تم استيراد قاعدة البيانات بنجاح. الاستعلامات الناجحة: $successCount، الفاشلة: $failedCount.\n";
$mysqli->close();
?>
