<?php
/**
 * واجهة برمجية لجلب الأرقام التسلسلية المتاحة لمنتج معين في المخزن
 */
$dir_prefix = '../';
require_once(__DIR__ . '/../includes/connect.php');
require_once(__DIR__ . '/../includes/auth.php');

check_permission(['admin', 'cashier']);

header('Content-Type: application/json; charset=utf-8');

$product_id = isset($_GET['product_id']) ? intval($_GET['product_id']) : 0;
if ($product_id <= 0) {
    echo json_encode([]);
    exit;
}

$serials = [];
$stmt = $conn->prepare("
    SELECT id, serial_number, imei_1, imei_2 
    FROM product_serials 
    WHERE product_id = ? AND status = 'in_stock' AND d_s = '0'
    ORDER BY id ASC
");

if ($stmt) {
    $stmt->bind_param("i", $product_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $serials[] = $row;
    }
    $stmt->close();
}

echo json_encode($serials);
exit;
?>
