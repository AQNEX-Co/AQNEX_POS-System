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
        $expense_date = $conn->real_escape_string($expense['sdate']);
        
        $conn->begin_transaction();
        try {
            // 1. إعادة المبلغ المخصوم إلى الصندوق المالي
            update_box_balance($conn, $box_id, $old_price, 'addition', "إلغاء سند صرف رقم #$sid - بند $expense_type", date('Y-m-d'));
            
            // 2. أرشفة السجلات والقيود اليومية المحاسبية إلى جداول التاريخ (History)
            $conn->query("INSERT INTO treasury_expenses_history SELECT * FROM treasury_expenses WHERE sid = $sid");
            $conn->query("INSERT INTO expenses_history SELECT * FROM expenses WHERE m_date = '$expense_date' AND sname = '$expense_type' AND m_price = $old_price LIMIT 1");
            $conn->query("INSERT INTO journal_entries_history SELECT * FROM journal_entries WHERE ref_type = 'expense' AND ref_id = $sid");
            $conn->query("INSERT INTO accounting_journal_history SELECT * FROM accounting_journal WHERE ref_type = 'expense' AND ref_id = $sid");
            
            // 3. حذف القيود المحاسبية من الجداول الفعالة (إصلاح قيد journal_entries المفقود)
            $conn->query("DELETE FROM accounting_journal_entries WHERE source_type = 'expense' AND source_id = $sid");
            $conn->query("DELETE FROM journal_entries WHERE ref_type = 'expense' AND ref_id = $sid");
            $conn->query("DELETE FROM accounting_journal WHERE ref_type = 'expense' AND ref_id = $sid");
            
            // 4. حذف من جدول المصاريف الموازي الفعال
            $conn->query("DELETE FROM expenses WHERE m_date = '$expense_date' AND sname = '$expense_type' AND m_price = $old_price LIMIT 1");
            
            // 5. حذف السند الفعال
            $conn->query("DELETE FROM treasury_expenses WHERE sid = $sid");
            
            $conn->commit();
        } catch (Exception $e) {
            $conn->rollback();
        }
    }
}

header('Location: index.php');
exit();
?>
