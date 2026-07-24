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

// محاولة الاتصال بالخادم مع إضافة حلقة انتظار (Retry loop)
$mysqli = null;
$maxRetries = 10;
$retryCount = 0;

while ($retryCount < $maxRetries) {
    $mysqli = @new mysqli($host, $user, $pass, '', $port);
    if (!$mysqli->connect_error) {
        break;
    }
    echo "انتظار بدء تشغيل MariaDB... (" . ($retryCount + 1) . "/$maxRetries)\n";
    sleep(2);
    $retryCount++;
}

if ($mysqli->connect_error) {
    die("خطأ: فشل الاتصال بخادم قاعدة البيانات بعد محاولات متعددة: " . $mysqli->connect_error . "\n");
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

// التحقق من كلا الحالتين:
//   1) عندما $installPath = {app}     → ملف SQL في {app}/app/DB/backup/
//   2) عندما $installPath = {app}/app → ملف SQL في {app}/app/DB/backup/
$sqlCandidates = [
    $installPath . '/app/DB/backup/aqnex_pos.sql', // المثبّت يمرر {app}
    $installPath . '/DB/backup/aqnex_pos.sql',      // dirname(__DIR__) يعطي {app}/app
];
$sqlFile = null;
foreach ($sqlCandidates as $candidate) {
    if (file_exists($candidate)) {
        $sqlFile = $candidate;
        break;
    }
}
if ($sqlFile === null) {
    die("خطأ: ملف SQL غير موجود. تم البحث في:\n  " . implode("\n  ", $sqlCandidates) . "\n");
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

$mysqli->query("SET FOREIGN_KEY_CHECKS = 1");

echo "✓ تم استيراد قاعدة البيانات الأساسية بنجاح. الاستعلامات الناجحة: $successCount، الفاشلة: $failedCount.\n";

// ─── تشغيل كافة ملفات الهجرة والتطوير المتبقية (Migrations) ────────────────
$migrationsPath = '';
foreach ([$installPath . '/app/DB/migrations', $installPath . '/DB/migrations'] as $candidatePath) {
    if (is_dir($candidatePath)) {
        $migrationsPath = $candidatePath;
        break;
    }
}

if (!empty($migrationsPath)) {
    echo "جاري فحص وتطبيق الهجرات الإضافية من المجلد: $migrationsPath...\n";
    $files = scandir($migrationsPath);
    $sqlFiles = [];
    foreach ($files as $file) {
        if (pathinfo($file, PATHINFO_EXTENSION) === 'sql') {
            $sqlFiles[] = $file;
        }
    }
    // ترتيب ملفات الهجرة أبجدياً (أو بالترتيب الزمني) لضمان تسلسل سليم (sprint1 -> sprint2 -> sprint3)
    sort($sqlFiles);

    foreach ($sqlFiles as $sqlFile) {
        $fullPath = $migrationsPath . '/' . $sqlFile;
        echo "جاري تطبيق الهجرة: $sqlFile...\n";
        $migrationContent = file_get_contents($fullPath);
        if ($migrationContent !== false) {
            $mysqli->query("SET FOREIGN_KEY_CHECKS = 0");
            $queries = [];
            $currentQuery = '';
            $lines = explode("\n", $migrationContent);
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

            $mSuccess = 0;
            $mFailed = 0;
            foreach ($queries as $q) {
                $q = trim($q);
                if (empty($q)) continue;
                if ($mysqli->query($q)) {
                    $mSuccess++;
                } else {
                    $mFailed++;
                    // لا تطبع الأخطاء المعتادة مثل تكرار الأعمدة أو الجداول
                    if (strpos($mysqli->error, 'Duplicate column') === false && strpos($mysqli->error, 'already exists') === false) {
                        echo "  [خطأ في الاستعلام]: " . $mysqli->error . "\n";
                    }
                }
            }
            $mysqli->query("SET FOREIGN_KEY_CHECKS = 1");
            echo "  ✓ تم الانتهاء: الناجحة $mSuccess، الفاشلة $mFailed.\n";
        }
    }
} else {
    echo "⚠ مجلد الهجرات غير موجود أو تعذر العثور عليه.\n";
}

$mysqli->close();
?>
