<?php
require_once(__DIR__ . '/../includes/connect.php');

header('Content-Type: application/json');

if (isset($_POST['barcode'])) {
    $barcode = $conn->real_escape_string(trim($_POST['barcode']));
    if (empty($barcode)) {
        echo json_encode(['success' => false, 'message' => 'الباركود فارغ']);
        exit;
    }
    
    $sql = "SELECT id, name, buy_price, sale_price, quantity FROM products WHERE barcode = '$barcode' AND delete_status = 0 LIMIT 1";
    $result = $conn->query($sql);
    
    if ($result && $row = $result->fetch_assoc()) {
        $row['id'] = intval($row['id']);
        $row['buy_price'] = doubleval($row['buy_price']);
        $row['sale_price'] = doubleval($row['sale_price']);
        $row['quantity'] = intval($row['quantity']);
        echo json_encode([
            'success' => true,
            'product' => $row
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'المنتج غير موجود أو تم حذفه'
        ]);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'طلب غير صالح']);
exit;
