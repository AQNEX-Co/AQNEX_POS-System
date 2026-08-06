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

$sale_id = intval($_GET['id']);

// 1. البحث في جدول الماستر الجديد sales_invoices_mst أولاً ثم الجدول القديم sales
$mst_invoice = null;
$old_invoice = null;

$chk_mst = $conn->query("SHOW TABLES LIKE 'sales_invoices_mst'");
if ($chk_mst && $chk_mst->num_rows > 0) {
    $res_mst = $conn->query("SELECT * FROM sales_invoices_mst WHERE id = $sale_id AND d_s = 0 LIMIT 1");
    if ($res_mst && $res_mst->num_rows > 0) {
        $mst_invoice = $res_mst->fetch_assoc();
    }
}

if (!$mst_invoice) {
    $chk_old = $conn->query("SHOW TABLES LIKE 'sales'");
    if ($chk_old && $chk_old->num_rows > 0) {
        $res_old = $conn->query("SELECT * FROM sales WHERE id = $sale_id AND delete_status = 0 LIMIT 1");
        if ($res_old && $res_old->num_rows > 0) {
            $old_invoice = $res_old->fetch_assoc();
        }
    }
}

if (!$mst_invoice && !$old_invoice) {
    header('Location: index.php?msg=notfound');
    exit;
}

$conn->begin_transaction();
try {
    if ($mst_invoice) {
        // --- أ. الحذف من الهيكل الموحد الجديد (sales_invoices_mst & dtl) ---
        $cust_id = intval($mst_invoice['cust_id'] ?? 0);
        $cust_name = $conn->real_escape_string($mst_invoice['cust_name']);
        $net_total = floatval($mst_invoice['net_amount']);
        $paid_amount = floatval($mst_invoice['paid_amount']);
        $rem_amount = floatval($mst_invoice['remaining_amount']);
        $box_id = intval($mst_invoice['box_id'] ?? 1);

        // 1. استرجاع الكميات للمخزون من جدول تفاصيل المبيعات الجديد
        $res_dtl = $conn->query("SELECT * FROM sales_invoices_dtl WHERE invoice_id = $sale_id AND d_s = 0");
        if ($res_dtl && $res_dtl->num_rows > 0) {
            while ($dtl = $res_dtl->fetch_assoc()) {
                $p_id = intval($dtl['product_id'] ?? 0);
                $qty  = floatval($dtl['quantity'] ?? 0);
                if ($p_id > 0 && $qty > 0) {
                    $conn->query("UPDATE products SET quantity = quantity + $qty, total = quantity * buy_price WHERE id = $p_id");
                    $conn->query("INSERT INTO inventory_log (product_id, product_name, type, qty_change, new_qty, reason, user)
                                  SELECT id, name, 'sale_delete', $qty, quantity, 'حذف فاتورة مبيعات رقم #$sale_id', '" . ($_SESSION['SESS_FIRST_NAME'] ?? 'المدير') . "'
                                  FROM products WHERE id = $p_id LIMIT 1");
                }
            }
        }

        // 2. عكس مديونية العميل إذا كان هناك مبلغ آجل
        if ($rem_amount > 0) {
            if ($cust_id > 0) {
                $conn->query("UPDATE customers SET cust_madeen = GREATEST(0, cust_madeen - $rem_amount) WHERE cust_id = $cust_id");
            } elseif (!empty($cust_name) && $cust_name !== 'عميل نقدي') {
                $conn->query("UPDATE customers SET cust_madeen = GREATEST(0, cust_madeen - $rem_amount) WHERE cust_name = '$cust_name'");
            }
        }

        // 3. عكس تأثير الصندوق (خصم المبلغ المدفوع من الصندوق)
        if ($paid_amount > 0 && $box_id > 0) {
            update_box_balance($conn, $box_id, $paid_amount, 'discount', "إلغاء دفعة فاتورة مبيعات رقم #$sale_id للعميل $cust_name", date('Y-m-d'));
        }

        // 4. أرشفة وحذف السجلات
        @$conn->query("INSERT INTO sales_invoices_mst_history SELECT * FROM sales_invoices_mst WHERE id = $sale_id");
        @$conn->query("INSERT INTO sales_invoices_dtl_history SELECT * FROM sales_invoices_dtl WHERE invoice_id = $sale_id");
        @$conn->query("INSERT INTO journal_entries_history SELECT * FROM journal_entries WHERE ref_type = 'sale' AND ref_id = $sale_id");

        $conn->query("DELETE FROM sales_invoices_dtl WHERE invoice_id = $sale_id");
        $conn->query("DELETE FROM sales_invoices_mst WHERE id = $sale_id");
        $conn->query("DELETE FROM accounting_journal_entries WHERE source_type = 'sale' AND source_id = $sale_id");
        $conn->query("DELETE FROM journal_entries WHERE ref_type = 'sale' AND ref_id = $sale_id");
        $conn->query("DELETE FROM accounting_journal WHERE ref_type = 'sale' AND ref_id = $sale_id");

    } else {
        // --- ب. الحذف من الهيكل التقليدي (sales & sales_items) ---
        $cust_name = $conn->real_escape_string($old_invoice['cust_name']);
        $remaining_total = doubleval($old_invoice['remaining_total'] ?? 0);
        $paid_amount = doubleval($old_invoice['total'] ?? 0) - $remaining_total;

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

        if (!empty($cust_name) && $cust_name !== 'عميل نقدي' && $remaining_total > 0) {
            $conn->query("UPDATE customers SET cust_madeen = GREATEST(0, cust_madeen - $remaining_total) WHERE cust_name = '$cust_name'");
        }

        if ($paid_amount > 0) {
            $box_id = intval($old_invoice['box_id'] ?? 1);
            update_box_balance($conn, $box_id, $paid_amount, 'discount', "إلغاء فاتورة مبيعات رقم #$sale_id للعميل $cust_name", date('Y-m-d'));
        }

        @$conn->query("INSERT INTO sales_history SELECT * FROM sales WHERE id = $sale_id");
        @$conn->query("INSERT INTO sales_items_history SELECT * FROM sales_items WHERE sales_id = $sale_id");
        @$conn->query("INSERT INTO journal_entries_history SELECT * FROM journal_entries WHERE ref_type = 'sale' AND ref_id = $sale_id");

        $conn->query("DELETE FROM sales_items WHERE sales_id = $sale_id");
        $conn->query("DELETE FROM sales WHERE id = $sale_id");
        $conn->query("DELETE FROM journal_entries WHERE ref_type = 'sale' AND ref_id = $sale_id");
        $conn->query("DELETE FROM accounting_journal WHERE ref_type = 'sale' AND ref_id = $sale_id");
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
