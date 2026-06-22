<?php
// تعطيل الاستثناءات التلقائية لـ mysqli لتفادي توقف الصفحة وعرض تفاصيل حساسة عند فشل الاتصال
mysqli_report(MYSQLI_REPORT_OFF);

$servername = "localhost";
$username = "root";
$password = "";
$dbname = "aq_pos";

// إنشاء الاتصال
$conn = new mysqli($servername, $username, $password, $dbname, 3307);

// التحقق من الاتصال
if ($conn->connect_error) {
    die("فشل الاتصال بقاعدة البيانات: " . $conn->connect_error);
}

// ضبط الترميز للغة العربية
$conn->set_charset("utf8mb4");

// تضمين النواة الأمنية والتحقق من التراخيص
require_once(__DIR__ . '/../core/bootstrap.php');
?>
