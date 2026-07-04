<?php
/**
 * واجهة برمجية لجلب الدفعات/التشغيلات المتاحة لمنتج معين وتاريخ صلاحيتها
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

$batches = [];
$stmt = $conn->prepare("
    SELECT id, batch_number, expiry_date, quantity 
    FROM product_batches 
    WHERE product_id = ? AND quantity > 0 AND d_s = '0'
    ORDER BY expiry_date ASC, id ASC
");

if ($stmt) {
    $stmt->bind_param("i", $product_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $batches[] = $row;
    }
    $stmt->close();
}

echo json_encode($batches);
exit;
?>
