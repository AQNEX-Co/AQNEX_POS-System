<?php
/**
 * سكربت تهيئة قاعدة البيانات واستيراد كافة المكونات
 * يتم تشغيله تلقائياً بعد تثبيت البرنامج عبر Inno Setup.
 */

if (php_sapi_name() !== 'cli') {
    die("هذا السكربت مخصص للتشغيل عبر CLI فقط.");
}

// تعطيل الاستثناءات التلقائية لـ mysqli لتفادي توقف السكربت عند محاولة الاتصال الأولى قبل بدء الخدمة
mysqli_report(MYSQLI_REPORT_OFF);

$host = '127.0.0.1';
$user = 'root';
$pass = '';
$port = 3307; // المنفذ التجاري الموزع الافتراضي

// 1. الانتظار حتى تبدأ خدمة MariaDB بالعمل (مهم جداً لأن الخدمة قد تستغرق بضع ثوان للبدء بعد الأمر sc start)
echo "جاري محاولة الاتصال بخدمة قاعدة البيانات MariaDB...\n";
$connected = false;
$maxRetries = 10;
$retryInterval = 2; // ثواني

for ($i = 1; $i <= $maxRetries; $i++) {
    $conn = @new \mysqli($host, $user, $pass, '', $port);
    if ($conn->connect_error) {
        echo "محاولة $i من $maxRetries فشلت. إعادة المحاولة خلال $retryInterval ثانية...\n";
        sleep($retryInterval);
    } else {
        $connected = true;
        break;
    }
}

if (!$connected) {
    die("❌ خطأ حرج: تعذر الاتصال بخدمة قاعدة البيانات MariaDB بعد عدة محاولات.\n");
}

echo "✓ تم الاتصال بنجاح بخدمة MariaDB.\n";

// 2. إنشاء قاعدة البيانات بشكل جديد في كل تثبيت وتجنب تداخل البيانات السابقة
if (!$conn->query("DROP DATABASE IF EXISTS `aq_pos`")) {
    die("❌ خطأ في حذف قاعدة البيانات القديمة: " . $conn->error . "\n");
}
if (!$conn->query("CREATE DATABASE `aq_pos` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")) {
    die("❌ خطأ في إنشاء قاعدة البيانات الجديد: " . $conn->error . "\n");
}
$conn->select_db('aq_pos');
$conn->set_charset('utf8mb4');

// 3. قائمة الملفات المطلوب استيرادها بالترتيب الزمني الصحيح
$dbDir = __DIR__ . '/';
$sqlFiles = [
    'database_init.sql'
];

foreach ($sqlFiles as $file) {
    $filePath = $dbDir . $file;
    if (file_exists($filePath)) {
        echo "جاري استيراد الملف: $file ... ";
        $sqlContent = file_get_contents($filePath);
        
        // استخدام multi_query لاستيراد ملفات الـ SQL الكبيرة
        if ($conn->multi_query($sqlContent)) {
            do {
                if ($result = $conn->store_result()) {
                    $result->free();
                }
            } while ($conn->next_result());
            echo "✓ تم بنجاح.\n";
        } else {
            echo "❌ فشل: " . $conn->error . "\n";
        }
    } else {
        echo "⚠ ملف غير موجود، تخطي: $file\n";
    }
}

// 4. تصفير أي تراخيص سابقة نشطة وتثبيت قيم الإعدادات لتبدأ من جديد
$conn->query("DELETE FROM system_licensing");
$conn->query("UPDATE settings SET is_configured = 0 WHERE id = 1");

echo "✓ تم تهيئة قاعدة البيانات وإستيراد كافة جداول الحماية والمشروع التجاري بنجاح!\n";
$conn->close();
?>
