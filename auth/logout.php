<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (isset($_SESSION['SESS_MEMBER_ID'])) {
    $dir_prefix = '../';
    require_once(__DIR__ . '/../includes/connect.php');
    require_once(__DIR__ . '/../includes/accounting_helper.php');
    $user_id = intval($_SESSION['SESS_MEMBER_ID']);
    $user_name = $_SESSION['SESS_FIRST_NAME'] ?? 'Unknown User';
    
    // Get user's box ID and transfer pending sales
    $box_id = get_user_box_id($conn, $user_id);
    if ($box_id > 0) {
        // تم إلغاء ترحيل المبيعات عند تسجيل الخروج كونه يتم التعامل المباشر مع الصناديق في القيود
        // transfer_sales_to_box($conn, $box_id, $user_name);
        
        // EOD Shift Closing for Maintenance Fund (box_id = 2)
        if ($box_id === 2) {
            $conn->begin_transaction();
            try {
                // Load AccountingService
                require_once(__DIR__ . '/../app/Services/AccountingService.php');
                
                // Get Maintenance Fund balance (box_id = 2)
                $mt_balance = \AQNEX\Services\AccountingService::getFundBalance($conn, 2);
                if ($mt_balance > 0) {
                    $today_date = date('Y-m-d');
                    
                    // Deduct from Maintenance Fund
                    update_box_balance($conn, 2, $mt_balance, 'discount', "ترحيل رصيد نهاية الوردية إلى الصندوق الرئيسي - مستخدم: $user_name", $today_date);
                    
                    // Add to Main Fund
                    update_box_balance($conn, 1, $mt_balance, 'addition', "استلام رصيد نهاية الوردية من صندوق الصيانة - مستخدم: $user_name", $today_date);
                    
                    // Double-entry posting using AccountingService
                    \AQNEX\Services\AccountingService::post(
                        $conn,
                        'adjustment',
                        2, // Ref ID representing Maintenance Fund
                        'الصندوق - الصندوق الرئيسي',
                        'الصندوق - صندوق مركز الصيانة',
                        $mt_balance,
                        "ترحيل رصيد نهاية الوردية من صندوق الصيانة إلى الصندوق الرئيسي - مستخدم: $user_name",
                        $user_name,
                        1,
                        'fund',
                        1,
                        'fund',
                        2
                    );
                }
                $conn->commit();
            } catch (Exception $e) {
                $conn->rollback();
            }
        }
    }
}

$_SESSION = array();

if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

session_destroy();
header("Location: login.php");
exit();
?>
