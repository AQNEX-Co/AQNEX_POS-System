<?php
$dir_prefix = '../';
require_once(__DIR__ . '/../includes/connect.php');
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once(__DIR__ . '/../includes/auth.php');
require_once(__DIR__ . '/../app/Services/AccountingService.php');

check_permission(['admin', 'cashier']);

header('Content-Type: application/json; charset=utf-8');

$name = isset($_GET['name']) ? trim($_GET['name']) : '';

if (empty($name)) {
    echo json_encode(['status' => 'error', 'message' => 'Missing customer name']);
    exit;
}

$name_esc = $conn->real_escape_string($name);
$res = $conn->query("SELECT cust_id, credit_limit FROM customers WHERE cust_name = '$name_esc' AND d_s = 0 LIMIT 1");
if ($res && $res->num_rows > 0) {
    $row = $res->fetch_assoc();
    $cust_id = intval($row['cust_id']);
    $credit_limit = doubleval($row['credit_limit']);
    
    // Calculate dynamic ledger balance
    $balance = \AQNEX\Services\AccountingService::getCustomerBalance($conn, $cust_id);
    
    echo json_encode([
        'status' => 'success',
        'cust_id' => $cust_id,
        'credit_limit' => $credit_limit,
        'balance' => $balance
    ]);
} else {
    echo json_encode(['status' => 'error', 'message' => 'Customer not found']);
}
exit;
