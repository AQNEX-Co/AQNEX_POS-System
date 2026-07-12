<?php
$dir_prefix = '../';
require_once($dir_prefix . 'includes/connect.php');
require_once($dir_prefix . 'includes/auth.php');
require_once($dir_prefix . 'includes/accounting_helper.php');

// Verify session and admin status before outputting any HTML to prevent "headers already sent" warning
if (!\AQNEX\Services\AuthService::isAdmin()) {
    \AQNEX\Services\AuthService::denyAccess();
}

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: index.php');
    exit;
}

$sale_id = intval($_GET['id']);

// 1. جلب بيانات الفاتورة
$res_sale = $conn->query("SELECT * FROM sales WHERE id = $sale_id AND delete_status = 0");
if (!$res_sale || $res_sale->num_rows === 0) {
    header('Location: index.php');
    exit;
}
$sale = $res_sale->fetch_assoc();
$cust_name = $conn->real_escape_string($sale['cust_name']);
$remaining_total = doubleval($sale['remaining_total'] ?? 0);

$conn->begin_transaction();
try {
    // 2. استرجاع الكميات الخاصة بالفاتورة الأصلية والمردودات المرتبطة بها
    $res_items = $conn->query("SELECT * FROM sales_items WHERE sales_id = $sale_id");
    if ($res_items) {
        while ($item = $res_items->fetch_assoc()) {
            $qty = intval($item['quantity'] ?? 0);
            $parts = explode(' ', trim((string)($item['name'] ?? '')), 2);
            $product_id = intval($parts[0]);

            if ($product_id > 0) {
                $conn->query("UPDATE products SET quantity = quantity + $qty, total = quantity * buy_price WHERE id = $product_id");
            }
        }
    }

    $res_returns = $conn->query("SELECT product_id, quantity FROM sales_returns WHERE sales_id = $sale_id AND status = 'active'");
    if ($res_returns) {
        while ($ret = $res_returns->fetch_assoc()) {
            $ret_qty = intval($ret['quantity'] ?? 0);
            $ret_product_id = intval($ret['product_id'] ?? 0);
            if ($ret_product_id > 0 && $ret_qty > 0) {
                $conn->query("UPDATE products SET quantity = GREATEST(0, quantity - $ret_qty), total = quantity * buy_price WHERE id = $ret_product_id");
            }
        }
    }

    // 3. إعادة خصم مديونية العميل إذا كان هناك آجل
    if (!empty($cust_name) && $cust_name !== 'عميل نقدي' && $remaining_total > 0) {
        $conn->query("UPDATE customers SET cust_madeen = GREATEST(0, cust_madeen - $remaining_total) WHERE cust_name = '$cust_name'");
    }

    // 4. عكس تأثير الصندوق (خصم المبالغ المدفوعة نقداً)
    $paid_amount = doubleval($sale['total'] ?? 0) - $remaining_total;
    if ($paid_amount > 0) {
        $box_id = intval($sale['box_id'] ?? 1);
        if (!update_box_balance($conn, $box_id, $paid_amount, 'discount', "إلغاء فاتورة مبيعات رقم #$sale_id للعميل $cust_name", date('Y-m-d'))) {
            throw new Exception("فشل تحديث رصيد الصندوق (قد يكون الرصيد غير كافٍ)");
        }
    }

    // 5. أرشفة السجلات التاريخية والقيود إلى جداول التاريخ (History)
    $conn->query("INSERT INTO sales_history SELECT * FROM sales WHERE id = $sale_id");
    $conn->query("INSERT INTO sales_items_history SELECT * FROM sales_items WHERE sales_id = $sale_id");
    $conn->query("INSERT INTO sales_returns_history SELECT * FROM sales_returns WHERE sales_id = $sale_id");
    $conn->query("INSERT INTO journal_entries_history SELECT * FROM journal_entries WHERE (ref_type = 'sale' AND ref_id = $sale_id) OR (ref_type = 'return' AND ref_id IN (SELECT id FROM sales_returns WHERE sales_id = $sale_id))");
    $conn->query("INSERT INTO accounting_journal_history SELECT * FROM accounting_journal WHERE (ref_type = 'sale' AND ref_id = $sale_id) OR (ref_type = 'return' AND ref_id IN (SELECT id FROM sales_returns WHERE sales_id = $sale_id))");

    // 6. حذف السجلات والقيود المحاسبية من الجداول الفعالة
    $conn->query("DELETE FROM accounting_journal_entries WHERE (source_type = 'sale' AND source_id = $sale_id) OR (source_type = 'return' AND source_id IN (SELECT id FROM sales_returns WHERE sales_id = $sale_id))");
    $conn->query("DELETE FROM journal_entries WHERE (ref_type = 'sale' AND ref_id = $sale_id) OR (ref_type = 'return' AND ref_id IN (SELECT id FROM sales_returns WHERE sales_id = $sale_id))");
    $conn->query("DELETE FROM accounting_journal WHERE (ref_type = 'sale' AND ref_id = $sale_id) OR (ref_type = 'return' AND ref_id IN (SELECT id FROM sales_returns WHERE sales_id = $sale_id))");
    $conn->query("DELETE FROM sales_returns WHERE sales_id = $sale_id");
    $conn->query("DELETE FROM sales_items WHERE sales_id = $sale_id");
    $conn->query("DELETE FROM sales WHERE id = $sale_id");

    $conn->commit();
    header('Location: index.php?msg=deleted');
    exit;
} catch (Exception $e) {
    $conn->rollback();
    header('Location: index.php?msg=error');
    exit;
}
?>
