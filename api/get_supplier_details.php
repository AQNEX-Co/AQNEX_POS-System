<?php
$dir_prefix = '../';
require_once(__DIR__ . '/../includes/connect.php');
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once(__DIR__ . '/../includes/auth.php');

check_permission(['admin', 'cashier', 'inventory', 'reports']);

header('Content-Type: application/json; charset=utf-8');

$supp_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$name = isset($_GET['name']) ? trim($_GET['name']) : '';

if ($supp_id <= 0 && empty($name)) {
    echo json_encode(['status' => 'error', 'message' => 'يجب توفير معرف المورد أو اسمه']);
    exit;
}

if ($supp_id > 0) {
    $res = $conn->query("SELECT supp_id, supp_name, phone, COALESCE(supp_daain, 0) as supp_daain, COALESCE(supp_madeen, 0) as supp_madeen FROM suppliers WHERE supp_id = $supp_id AND d_s = 0 LIMIT 1");
} else {
    $name_esc = $conn->real_escape_string($name);
    $res = $conn->query("SELECT supp_id, supp_name, phone, COALESCE(supp_daain, 0) as supp_daain, COALESCE(supp_madeen, 0) as supp_madeen FROM suppliers WHERE supp_name = '$name_esc' AND d_s = 0 LIMIT 1");
}

if ($res && $res->num_rows > 0) {
    $row = $res->fetch_assoc();
    $id = intval($row['supp_id']);
    $daain = floatval($row['supp_daain']);
    $madeen = floatval($row['supp_madeen']);
    $net_balance = $daain - $madeen;
    
    echo json_encode([
        'status' => 'success',
        'supp_id' => $id,
        'supp_name' => $row['supp_name'],
        'phone' => $row['phone'],
        'daain' => $daain,
        'madeen' => madeen,
        'balance' => $net_balance
    ]);
} else {
    echo json_encode(['status' => 'error', 'message' => 'المورد غير موجود']);
}
exit;
?>
