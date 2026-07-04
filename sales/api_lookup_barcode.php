<?php
/**
 * واجهة برمجية للبحث عن المنتجات بواسطة الباركود (البحث المتعدد)
 * تدعم البحث بالباركود الأساسي للمنتج أو بالباركودات الفرعية التابعة للوحدات المخصصة،
 * كما تدعم البحث بالرقم التسلسلي / IMEI في حال تفعيل الموديول الخاص به.
 */
$dir_prefix = '../';
require_once(__DIR__ . '/../includes/connect.php');
require_once(__DIR__ . '/../includes/auth.php');
require_once(__DIR__ . '/../includes/modules.php');

// التحقق من صلاحية الوصول للمبيعات
check_permission(['admin', 'cashier']);

header('Content-Type: application/json; charset=utf-8');

$barcode = isset($_GET['barcode']) ? trim($_GET['barcode']) : '';
$result = \AQNEX\Services\SaleService::lookupProductByBarcode($conn, $barcode);

if (!$result['found']) {
    echo json_encode(['found' => false, 'scanned_code' => $barcode, 'message' => $result['message'] ?? 'الرمز غير مسجل بقاعدة البيانات.']);
    exit;
}

echo json_encode($result);
exit;
?>
