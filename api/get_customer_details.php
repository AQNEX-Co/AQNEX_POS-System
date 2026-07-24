<?php
$dir_prefix = '../';
require_once(__DIR__ . '/../includes/connect.php');
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once(__DIR__ . '/../includes/auth.php');

// محاولة استدعاء الخدمة المحاسبية، مع توفير بديل آمن في حال لم تكن متوفرة
if (file_exists(__DIR__ . '/../app/Services/AccountingService.php')) {
    require_once(__DIR__ . '/../app/Services/AccountingService.php');
}

check_permission(['admin', 'cashier']);

header('Content-Type: application/json; charset=utf-8');

$cust_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$name = isset($_GET['name']) ? trim($_GET['name']) : '';

if ($cust_id <= 0 && empty($name)) {
    echo json_encode(['status' => 'error', 'message' => 'يجب توفير معرف العميل أو اسمه']);
    exit;
}

if ($cust_id > 0) {
    $res = $conn->query("SELECT cust_id, cust_name, credit_limit, cust_madeen FROM customers WHERE cust_id = $cust_id AND d_s = 0 LIMIT 1");
} else {
    $name_esc = $conn->real_escape_string($name);
    $res = $conn->query("SELECT cust_id, cust_name, credit_limit, cust_madeen FROM customers WHERE cust_name = '$name_esc' AND d_s = 0 LIMIT 1");
}

if ($res && $res->num_rows > 0) {
    $row = $res->fetch_assoc();
    $id = intval($row['cust_id']);
    $credit_limit = doubleval($row['credit_limit']);
    
    // حساب الرصيد: استخدام الخدمة المحاسبية إن وجدت، أو الاعتماد على حقل cust_madeen مباشرة كبديل آمن
    if (class_exists('\\AQNEX\\Services\\AccountingService') && method_exists('\\AQNEX\\Services\\AccountingService', 'getCustomerBalance')) {
        $balance = \AQNEX\Services\AccountingService::getCustomerBalance($conn, $id);
    } else {
        $balance = doubleval($row['cust_madeen']);
    }
    
    echo json_encode([
        'status' => 'success',
        'cust_id' => $id,
        'cust_name' => $row['cust_name'],
        'credit_limit' => $credit_limit,
        'balance' => $balance
    ]);
} else {
    echo json_encode(['status' => 'error', 'message' => 'العميل غير موجود أو تم حذفه']);
}
exit;
?>