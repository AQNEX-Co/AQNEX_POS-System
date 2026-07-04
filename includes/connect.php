<?php
// تضمين البنية الجديدة للتهيئة قبل إعداد الاتصال
require_once(__DIR__ . '/../app/Core/Bootstrap.php');
\AQNEX\Core\Bootstrap::initialize();

// تحميل إعدادات الاتصال من البنية المهيكلة
require_once(__DIR__ . '/../app/Config/Database.php');

$conn = \AQNEX\Config\Database::createMysqli();
if (!$conn) {
    die('فشل الاتصال بقاعدة البيانات. يرجى التحقق من إعدادات الاتصال.');
}

// تضمين النواة الأمنية الحالية للحفاظ على آلية التفعيل كما هي
require_once(__DIR__ . '/../core/bootstrap.php');
?>
