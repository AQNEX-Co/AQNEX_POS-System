<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

// Path prefix to reach bootstrap
$dir_prefix = '../';
require_once(__DIR__ . '/../includes/connect.php');
require_once(__DIR__ . '/../app/Services/AuthService.php');
require_once(__DIR__ . '/../app/Services/BranchService.php');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Ensure user is logged in
if (empty($_SESSION['SESS_MEMBER_ID'])) {
    echo json_encode(['status' => 'error', 'message' => 'غير مصرح بالوصول، يرجى تسجيل الدخول.']);
    exit();
}

$branchId = isset($_POST['branch_id']) ? (int)$_POST['branch_id'] : 0;
$warehouseId = isset($_POST['warehouse_id']) ? (int)$_POST['warehouse_id'] : 0;

if ($branchId > 0) {
    \AQNEX\Services\BranchService::setCurrentBranchId($branchId);
    
    // Get warehouses for the new branch to send back in response
    $warehouses = \AQNEX\Services\BranchService::getWarehousesForBranch($branchId);
    $activeWarehouseId = \AQNEX\Services\BranchService::getCurrentWarehouseId();
    
    echo json_encode([
        'status' => 'success',
        'message' => 'تم تغيير الفرع بنجاح',
        'active_branch_id' => $branchId,
        'active_warehouse_id' => $activeWarehouseId,
        'warehouses' => $warehouses
    ]);
    exit();
}

if ($warehouseId > 0) {
    \AQNEX\Services\BranchService::setCurrentWarehouseId($warehouseId);
    echo json_encode([
        'status' => 'success',
        'message' => 'تم تغيير المخزن بنجاح',
        'active_warehouse_id' => $warehouseId
    ]);
    exit();
}

echo json_encode(['status' => 'error', 'message' => 'بيانات الطلب غير صالحة.']);
exit();
