<?php
$dir_prefix = '../';
$module = 'receipts';
require_once($dir_prefix . 'includes/connect.php');
require_once($dir_prefix . 'includes/auth.php');
require_once($dir_prefix . 'includes/accounting_helper.php');

check_permission(['admin']);

if (isset($_GET['id'])) {
    $qid = intval($_GET['id']);
    
    // جلب معلومات السند لتسوية مديونية العميل والصندوق
    $sql_mq = "SELECT * FROM receipts WHERE qid = $qid";
    $res_mq = $conn->query($sql_mq);
    if ($res_mq && $receipt = $res_mq->fetch_assoc()) {
        $cust_name = $conn->real_escape_string($receipt['cust_name']);
        $amount = doubleval($receipt['q_price']);
        $box_id = intval($receipt['box_id']);
        
        // 1. إعادة إجمالي الدين لحساب العميل
        $sql_cust = "UPDATE customers SET cust_madeen = cust_madeen + $amount WHERE cust_name = '$cust_name'";
        $conn->query($sql_cust);
        
        // 2. خصم القيمة من الصندوق المالي المناسب
        update_box_balance($conn, $box_id, $amount, 'discount', "إلغاء سند قبض رقم #$qid للعميل $cust_name", date('Y-m-d'));
        
        // 3. حذف القيد اليومي المحاسبي
        $conn->query("DELETE FROM accounting_journal WHERE ref_type = 'receipt' AND ref_id = $qid");
        
        // 4. حذف السند
        $sql_del = "DELETE FROM receipts WHERE qid = $qid";
        $conn->query($sql_del);
    }
}

header('Location: index.php');
exit();
?>
