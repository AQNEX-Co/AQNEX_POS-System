<?php
$dir_prefix = '../';
$module = 'expenses';
require_once($dir_prefix . 'includes/connect.php');
require_once($dir_prefix . 'includes/auth.php');
require_once($dir_prefix . 'includes/accounting_helper.php');

check_permission(['admin']);

if (isset($_GET['id'])) {
    $sid = intval($_GET['id']);
    
    // جلب القيمة والمعلومات لتسوية الصندوق
    $sql_old = "SELECT * FROM treasury_expenses WHERE sid = $sid";
    $res_old = $conn->query($sql_old);
    if ($res_old && $expense = $res_old->fetch_assoc()) {
        $old_price = doubleval($expense['sprice']);
        $box_id = intval($expense['box_id']);
        $expense_type = $conn->real_escape_string($expense['st']);
        
        // 1. إعادة المبلغ المخصوم إلى الصندوق المالي
        update_box_balance($conn, $box_id, $old_price, 'addition', "إلغاء سند صرف رقم #$sid - بند $expense_type", date('Y-m-d'));
        
        // 2. حذف القيد اليومي المحاسبي
        $conn->query("DELETE FROM accounting_journal WHERE ref_type = 'expense' AND ref_id = $sid");
        
        // 3. حذف من جدول المصاريف الموازي
        $conn->query("DELETE FROM expenses WHERE m_date = '{$expense['sdate']}' AND sname = '$expense_type' AND m_price = $old_price LIMIT 1");
        
        // 4. حذف السند
        $sql = "DELETE FROM treasury_expenses WHERE sid = $sid";
        $conn->query($sql);
    }
}

header('Location: index.php');
exit();
?>
