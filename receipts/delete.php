<?php
$dir_prefix = '../';
$module = 'receipts';
require_once($dir_prefix . 'includes/connect.php');
require_once($dir_prefix . 'includes/auth.php');
require_once($dir_prefix . 'includes/accounting_helper.php');

check_permission(['admin']);

if (isset($_GET['id'])) {
    $qid = intval($_GET['id']);
    
    $conn->begin_transaction();
    try {
        // جلب معلومات السند لتسوية مديونية العميل والصندوق
        $sql_mq = "SELECT * FROM receipts WHERE qid = $qid";
        $res_mq = $conn->query($sql_mq);
        if ($res_mq && $receipt = $res_mq->fetch_assoc()) {
            $cust_name = $conn->real_escape_string($receipt['cust_name']);
            $amount = doubleval($receipt['q_price']);
            $box_id = intval($receipt['box_id']);
            
            // 1. إعادة إجمالي الدين لحساب العميل
            $sql_cust = "UPDATE customers SET cust_madeen = cust_madeen + $amount WHERE cust_name = '$cust_name'";
            if (!$conn->query($sql_cust)) {
                throw new Exception("فشل تحديث رصيد العميل");
            }
            
            // 2. خصم القيمة من الصندوق المالي المناسب
            update_box_balance($conn, $box_id, $amount, 'discount', "إلغاء سند قبض رقم #$qid للعميل $cust_name", date('Y-m-d'));
            
            // 3. أرشفة السند والقيود إلى التاريخ (History)
            if (!$conn->query("INSERT INTO receipts_history SELECT * FROM receipts WHERE qid = $qid") ||
                !$conn->query("INSERT INTO journal_entries_history SELECT * FROM journal_entries WHERE ref_type = 'receipt' AND ref_id = $qid") ||
                !$conn->query("INSERT INTO accounting_journal_history SELECT * FROM accounting_journal WHERE ref_type = 'receipt' AND ref_id = $qid")) {
                throw new Exception("فشل أرشفة السند والقيود إلى التاريخ");
            }
            
            // 4. حذف القيود اليومية المحاسبية من الجداول الفعالة
            if (!$conn->query("DELETE FROM accounting_journal_entries WHERE source_type = 'receipt' AND source_id = $qid")) {
                throw new Exception("فشل حذف قيود القيد المزدوج الحديثة");
            }
            if (!$conn->query("DELETE FROM journal_entries WHERE ref_type = 'receipt' AND ref_id = $qid")) {
                throw new Exception("فشل حذف القيود اليومية");
            }
            if (!$conn->query("DELETE FROM accounting_journal WHERE ref_type = 'receipt' AND ref_id = $qid")) {
                throw new Exception("فشل حذف قيود اليومية القديمة");
            }
            
            // 5. حذف السند من الجدول الفعال
            $sql_del = "DELETE FROM receipts WHERE qid = $qid";
            if (!$conn->query($sql_del)) {
                throw new Exception("فشل حذف السند");
            }
        }
        $conn->commit();
    } catch (Exception $e) {
        $conn->rollback();
    }
}

header('Location: index.php');
exit();
?>
