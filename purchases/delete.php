<?php
$dir_prefix = '../';
require_once($dir_prefix . 'includes/connect.php');
require_once($dir_prefix . 'includes/auth.php');
require_once($dir_prefix . 'includes/accounting_helper.php');

// التحقق من صلاحيات المدير قبل إخراج أي HTML لمنع خطأ "headers already sent"
if (!\AQNEX\Services\AuthService::isAdmin()) {
    \AQNEX\Services\AuthService::denyAccess();
}

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: index.php?msg=invalid');
    exit;
}

$invoice_id = intval($_GET['id']);

// 1. جلب بيانات الفاتورة من جدول الماستر الجديد
$res_inv = $conn->query("SELECT * FROM purchase_invoices_mst WHERE id = $invoice_id AND d_s = 0");
if (!$res_inv || $res_inv->num_rows === 0) {
    header('Location: index.php?msg=notfound');
    exit;
}
$invoice = $res_inv->fetch_assoc();

$inv_date = $invoice['invoice_date'];
$supplier_id = intval($invoice['supp_id'] ?? 0);
$supplier_name = $invoice['supp_name'];
$inv_box_id = intval($invoice['box_id'] ?? 1);
$total_paid_base = doubleval($invoice['paid_amount']);
$total_remaining_base = doubleval($invoice['remaining_amount']);

// بدء المعاملة
$conn->begin_transaction();
try {
    // 2. منع الحذف إذا كانت هناك مرتجعات مشتريات نشطة مرتبطة بالفاتورة
    $res_active_returns = $conn->query("
        SELECT COUNT(*) AS cnt 
        FROM purchase_returns_mst m 
        JOIN purchase_returns_dtl d ON m.id = d.return_id 
        WHERE m.original_purchase_id = $invoice_id AND m.d_s = 0
    ");
    if ($res_active_returns && intval($res_active_returns->fetch_assoc()['cnt']) > 0) {
        throw new Exception('لا يمكن حذف الفاتورة لأنها تحتوي على مرتجعات مشتريات نشطة. قم بإلغاء المرتجعات أولاً.');
    }

    // 3. جلب بنود الفاتورة من جدول التفاصيل الجديد
    $res_items = $conn->query("SELECT * FROM purchase_invoices_dtl WHERE invoice_id = $invoice_id AND d_s = 0");
    $items_rows = [];
    if ($res_items && $res_items->num_rows > 0) {
        while ($item = $res_items->fetch_assoc()) {
            $items_rows[] = $item;
        }
    }

    // 4. استرجاع الكميات للمخزون (عكس الشراء: طرح البضاعة المشتراة وتحديث القيمة الكلية)
    foreach ($items_rows as $item) {
        $qty = doubleval($item['quantity'] ?? 0);
        $product_id = intval($item['product_id'] ?? 0);
        $unit_name = $item['unit_name'] ?: 'حبة';
        
        if ($qty > 0 && $product_id > 0) {
            // جلب معامل التحويل للوحدة المخزنة لضمان دقة عكس الكمية
            $conv_factor = 1.0;
            $u_res = $conn->query("SELECT conversion_factor FROM product_units WHERE product_id = $product_id AND unit_name = '" . $conn->real_escape_string($unit_name) . "' LIMIT 1");
            if ($u_res && $u_res->num_rows > 0) {
                $conv_factor = doubleval($u_res->fetch_assoc()['conversion_factor']);
            }
            
            $base_qty = $qty * $conv_factor;
            $item_name_esc = $conn->real_escape_string($item['product_name']);

            // عكس الكمية في جدول المنتجات
            $conn->query("UPDATE products SET quantity = GREATEST(0, quantity - $base_qty), total = quantity * buy_price WHERE id = $product_id");
            
            // إضافة سجل حركة مخزون عكسي
            $conn->query("INSERT INTO inventory_log (product_id, product_name, type, qty_change, new_qty, reason, user)
                          SELECT id, name, 'purchase_delete', -$base_qty, quantity, 'حذف فاتورة مشتريات رقم #$invoice_id', '" . $_SESSION['SESS_FIRST_NAME'] . "'
                          FROM products WHERE id = $product_id LIMIT 1");
        }
    }

    // 5. أرشفة السجلات إلى جداول التاريخ (History)
    // ملاحظة: تأكد من إنشاء جداول _history لهذه الجداول الجديدة إذا لم تكن موجودة
    $conn->query("INSERT INTO purchase_invoices_mst_history SELECT * FROM purchase_invoices_mst WHERE id = $invoice_id");
    $conn->query("INSERT INTO purchase_invoices_dtl_history SELECT * FROM purchase_invoices_dtl WHERE invoice_id = $invoice_id");
    
    // أرشفة القيود المحاسبية المرتبطة
    $conn->query("INSERT INTO journal_entries_history SELECT * FROM journal_entries WHERE ref_type = 'purchase' AND ref_id = $invoice_id");
    $conn->query("INSERT INTO accounting_journal_history SELECT * FROM accounting_journal WHERE ref_type = 'purchase' AND ref_id = $invoice_id");

    // 6. حذف بنود الفاتورة من جدول التفاصيل
    $conn->query("DELETE FROM purchase_invoices_dtl WHERE invoice_id = $invoice_id");

    // 7. حذف القيود المحاسبية والنشطة من الجداول الفعالة
    $conn->query("DELETE FROM accounting_journal_entries WHERE source_type = 'purchase' AND source_id = $invoice_id");
    $conn->query("DELETE FROM journal_entries WHERE ref_type = 'purchase' AND ref_id = $invoice_id");
    $conn->query("DELETE FROM accounting_journal WHERE ref_type = 'purchase' AND ref_id = $invoice_id");

    // 8. عكس مديونية المورد (إنقاص المتبقي الذي كانت هذه الفاتورة قد أضافته)
    // نستخدم supp_id للدقة، مع fallback لاسم المورد إذا كان المعرف صفراً
    if ($total_remaining_base > 0) {
        if ($supplier_id > 0) {
            $conn->query("UPDATE suppliers SET supp_daain = GREATEST(0, supp_daain - $total_remaining_base) WHERE supp_id = $supplier_id");
        } elseif (!empty($supplier_name)) {
            $supplier_name_esc = $conn->real_escape_string($supplier_name);
            $conn->query("UPDATE suppliers SET supp_daain = GREATEST(0, supp_daain - $total_remaining_base) WHERE supp_name = '$supplier_name_esc'");
        }
    }

    // 9. استرجاع المبلغ المدفوع إلى الصندوق إذا كانت الفاتورة قد خُصمت منه أصلاً
    if ($total_paid_base > 0 && $inv_box_id > 0) {
        update_box_balance($conn, $inv_box_id, $total_paid_base, 'addition', "إلغاء دفعة فاتورة مشتريات رقم #$invoice_id بسبب حذف الفاتورة", $inv_date);
    }

    // 10. حذف الفاتورة الرئيسية (أو يمكن استخدام UPDATE ... SET d_s = 1 للحذف الناعم)
    $conn->query("DELETE FROM purchase_invoices_mst WHERE id = $invoice_id");

    $conn->commit();
    header('Location: index.php?msg=deleted');
    exit;
} catch (Exception $e) {
    $conn->rollback();
    // يمكن تسجيل الخطأ في ملف log هنا
    error_log("Error deleting purchase invoice $invoice_id: " . $e->getMessage());
    header('Location: index.php?msg=error');
    exit;
}
?>