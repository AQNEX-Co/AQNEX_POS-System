<?php
$dir_prefix = '../';
require_once($dir_prefix . 'includes/connect.php');
require_once($dir_prefix . 'includes/auth.php');
require_once($dir_prefix . 'includes/accounting_helper.php');

check_permission(['admin', 'cashier', 'inventory']);

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: create.php?msg=invalid');
    exit;
}

$invoice_id = intval($_GET['id']);

// 1. البحث في جدول الماستر الجديد purchase_invoices_mst والجدول القديم purchases
$mst_invoice = null;
$old_invoice = null;

$chk_mst = $conn->query("SHOW TABLES LIKE 'purchase_invoices_mst'");
if ($chk_mst && $chk_mst->num_rows > 0) {
    $res_mst = $conn->query("SELECT * FROM purchase_invoices_mst WHERE id = $invoice_id AND d_s = 0 LIMIT 1");
    if ($res_mst && $res_mst->num_rows > 0) {
        $mst_invoice = $res_mst->fetch_assoc();
    }
}

if (!$mst_invoice) {
    $chk_old = $conn->query("SHOW TABLES LIKE 'purchases'");
    if ($chk_old && $chk_old->num_rows > 0) {
        $res_old = $conn->query("SELECT * FROM purchases WHERE id = $invoice_id LIMIT 1");
        if ($res_old && $res_old->num_rows > 0) {
            $old_invoice = $res_old->fetch_assoc();
        }
    }
}

if (!$mst_invoice && !$old_invoice) {
    header('Location: create.php?msg=notfound');
    exit;
}

$conn->begin_transaction();
try {
    if ($mst_invoice) {
        // --- أ. الحذف من الهيكل الموحد الجديد (purchase_invoices_mst & dtl) ---
        $supplier_id = intval($mst_invoice['supp_id'] ?? 0);
        $supplier_name = $conn->real_escape_string($mst_invoice['supp_name']);
        $inv_box_id = intval($mst_invoice['box_id'] ?? 1);
        $total_paid_base = doubleval($mst_invoice['paid_amount']);
        $total_remaining_base = doubleval($mst_invoice['remaining_amount']);
        $inv_date = $mst_invoice['invoice_date'] ?? date('Y-m-d');

        // 1. استرجاع الكميات للمخزون (خصم الكمية المشتراة وتحديث إجمالي القيمة)
        $res_dtl = $conn->query("SELECT * FROM purchase_invoices_dtl WHERE invoice_id = $invoice_id AND d_s = 0");
        if ($res_dtl && $res_dtl->num_rows > 0) {
            while ($dtl = $res_dtl->fetch_assoc()) {
                $p_id = intval($dtl['product_id'] ?? 0);
                $qty  = doubleval($dtl['quantity'] ?? 0);
                if ($p_id > 0 && $qty > 0) {
                    $conn->query("UPDATE products SET quantity = GREATEST(0, quantity - $qty), total = quantity * buy_price WHERE id = $p_id");
                    $conn->query("INSERT INTO inventory_log (product_id, product_name, type, qty_change, new_qty, reason, user)
                                  SELECT id, name, 'purchase_delete', -$qty, quantity, 'حذف فاتورة مشتريات رقم #$invoice_id', '" . ($_SESSION['SESS_FIRST_NAME'] ?? 'المدير') . "'
                                  FROM products WHERE id = $p_id LIMIT 1");
                }
            }
        }

        // 2. عكس دائنية المورد (إنقاص الدين الذي أضافته هذه الفاتورة)
        if ($total_remaining_base > 0) {
            if ($supplier_id > 0) {
                $conn->query("UPDATE suppliers SET supp_daain = GREATEST(0, supp_daain - $total_remaining_base) WHERE supp_id = $supplier_id");
            } elseif (!empty($supplier_name)) {
                $conn->query("UPDATE suppliers SET supp_daain = GREATEST(0, supp_daain - $total_remaining_base) WHERE supp_name = '$supplier_name'");
            }
        }

        // 3. استرجاع المبلغ المدفوع من الصندوق (إعادة إضافة المبلغ للصندوق)
        if ($total_paid_base > 0 && $inv_box_id > 0) {
            update_box_balance($conn, $inv_box_id, $total_paid_base, 'addition', "إلغاء دفعة فاتورة مشتريات رقم #$invoice_id للمورد $supplier_name", $inv_date);
        }

        // 4. أرشفة وحذف السجلات
        @$conn->query("INSERT INTO purchase_invoices_mst_history SELECT * FROM purchase_invoices_mst WHERE id = $invoice_id");
        @$conn->query("INSERT INTO purchase_invoices_dtl_history SELECT * FROM purchase_invoices_dtl WHERE invoice_id = $invoice_id");
        @$conn->query("INSERT INTO journal_entries_history SELECT * FROM journal_entries WHERE ref_type = 'purchase' AND ref_id = $invoice_id");

        $conn->query("DELETE FROM purchase_invoices_dtl WHERE invoice_id = $invoice_id");
        $conn->query("DELETE FROM purchase_invoices_mst WHERE id = $invoice_id");
        $conn->query("DELETE FROM accounting_journal_entries WHERE source_type = 'purchase' AND source_id = $invoice_id");
        $conn->query("DELETE FROM journal_entries WHERE ref_type = 'purchase' AND ref_id = $invoice_id");
        $conn->query("DELETE FROM accounting_journal WHERE ref_type = 'purchase' AND ref_id = $invoice_id");

    } else {
        // --- ب. الحذف من الهيكل القديم (purchases) ---
        $supplier_id = intval($old_invoice['supp_id'] ?? 0);
        $supplier_name = $conn->real_escape_string($old_invoice['supp_name']);
        $total_val = doubleval($old_invoice['total'] ?? 0);
        $total_remaining_base = doubleval($old_invoice['remaining_total'] ?? 0);
        $total_paid_base = $total_val - $total_remaining_base;
        $inv_box_id = intval($old_invoice['box_id'] ?? 1);
        $inv_date = $old_invoice['date'] ?? date('Y-m-d');

        if ($total_remaining_base > 0 && $supplier_id > 0) {
            $conn->query("UPDATE suppliers SET supp_daain = GREATEST(0, supp_daain - $total_remaining_base) WHERE supp_id = $supplier_id");
        }

        if ($total_paid_base > 0 && $inv_box_id > 0) {
            update_box_balance($conn, $inv_box_id, $total_paid_base, 'addition', "إلغاء فاتورة مشتريات رقم #$invoice_id", $inv_date);
        }

        @$conn->query("INSERT INTO journal_entries_history SELECT * FROM journal_entries WHERE ref_type = 'purchase' AND ref_id = $invoice_id");

        $conn->query("DELETE FROM purchases WHERE id = $invoice_id");
        $conn->query("DELETE FROM journal_entries WHERE ref_type = 'purchase' AND ref_id = $invoice_id");
        $conn->query("DELETE FROM accounting_journal WHERE ref_type = 'purchase' AND ref_id = $invoice_id");
    }

    $conn->commit();
    header('Location: create.php?msg=deleted');
    exit;
} catch (Exception $e) {
    $conn->rollback();
    header('Location: create.php?msg=error');
    exit;
}
?>