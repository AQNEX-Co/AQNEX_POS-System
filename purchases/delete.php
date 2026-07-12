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
    header('Location: index.php?msg=invalid');
    exit;
}

$invoice_id = intval($_GET['id']);

// جلب بيانات الفاتورة أولاً
$res_inv = $conn->query("SELECT * FROM purchases WHERE id = $invoice_id");
if (!$res_inv || $res_inv->num_rows === 0) {
    header('Location: index.php?msg=notfound');
    exit;
}
$invoice = $res_inv->fetch_assoc();
$inv_date = $invoice['date'];
$inv_supplier = $invoice['supp_name'];
$inv_box_id = intval($invoice['box_id'] ?? 1);

// بدء المعاملة
$conn->begin_transaction();
try {
    // 1. منع الحذف إذا كانت هناك مرتجعات مشتريات نشطة مرتبطة بالفاتورة
    $res_active_returns = $conn->query("SELECT COUNT(*) AS cnt FROM purchase_returns WHERE purchase_id = $invoice_id AND status = 'active'");
    if ($res_active_returns && intval($res_active_returns->fetch_assoc()['cnt']) > 0) {
        throw new Exception('لا يمكن حذف الفاتورة لأنها تحتوي على مرتجعات مشتريات نشطة. قم بإلغاء المرتجعات أولاً.');
    }

    // 2. جلب بنود الفاتورة أولاً (بـ purchase_id، مع fallback للبيانات القديمة)
    $res_items = $conn->query("SELECT * FROM purchase_items WHERE purchase_id = $invoice_id");
    $matched_by_purchase_id = ($res_items && $res_items->num_rows > 0);
    if (!$matched_by_purchase_id) {
        $res_items = $conn->query("SELECT * FROM purchase_items WHERE buys_date = '" . $conn->real_escape_string($inv_date) . "' AND supp_name = '" . $conn->real_escape_string($inv_supplier) . "'");
    }

    // 3. حساب إجمالي المدفوع وإجمالي المتبقي لهذه الفاتورة قبل حذف البنود
    $total_paid_base = 0;
    $total_remaining_base = 0;
    $items_rows = [];
    if ($res_items && $res_items->num_rows > 0) {
        while ($item = $res_items->fetch_assoc()) {
            $items_rows[] = $item;
            $total_paid_base += doubleval($item['pushtosupp']);
            $total_remaining_base += doubleval($item['total_d']);
        }
    }

    // 4. تحديد ما إذا كانت الفاتورة قد دُفعت من الصندوق باستخدام قيد اليومية
    $box_paid_base = 0;
    $res_box = $conn->query("SELECT COALESCE(SUM(amount),0) AS paid FROM accounting_journal WHERE ref_type = 'purchase' AND ref_id = $invoice_id AND credit_acc LIKE 'الصندوق - %'");
    if ($res_box && $row_box = $res_box->fetch_assoc()) {
        $box_paid_base = doubleval($row_box['paid']);
    }

    // 5. استرجاع الكميات للمخزون (عكس الشراء: طرح البضاعة المشتراة وتحديث القيمة الكلية)
    foreach ($items_rows as $item) {
        $qty = intval($item['quantity'] ?? 0);
        $item_name = trim((string)($item['name'] ?? ''));
        if (empty($item_name)) continue;

        $item_name_esc = $conn->real_escape_string($item_name);
        // البحث عن المنتج بالاسم الفعلي بدلاً من الـ ID غير الدقيق
        $prod_res = $conn->query("SELECT id FROM products WHERE name = '$item_name_esc' AND delete_status = 0 LIMIT 1");
        if ($prod_res && $prod_res->num_rows > 0) {
            $product_id = intval($prod_res->fetch_assoc()['id']);
            $conn->query("UPDATE products SET quantity = GREATEST(0, quantity - $qty), total = quantity * buy_price WHERE id = $product_id");
            // إضافة سجل حركة مخزون عكسي
            $conn->query("INSERT INTO inventory_log (product_id, product_name, type, qty_change, new_qty, reason, user)
                          SELECT id, name, 'manual', -$qty, quantity, 'حذف فاتورة مشتريات رقم #$invoice_id', '" . $_SESSION['SESS_FIRST_NAME'] . "'
                          FROM products WHERE id = $product_id LIMIT 1");
        }
    }

    // 6. عكس تأثير المردودات المرتبطة بهذه الفاتورة إذا كانت موجودة (إضافة كمية المردودات الملغاة للمخزون)
    $res_returns = $conn->query("SELECT product_id, quantity FROM purchase_returns WHERE purchase_id = $invoice_id AND status = 'active'");
    if ($res_returns) {
        while ($ret = $res_returns->fetch_assoc()) {
            $ret_qty = intval($ret['quantity'] ?? 0);
            $ret_product_id = intval($ret['product_id'] ?? 0);
            if ($ret_product_id > 0 && $ret_qty > 0) {
                $conn->query("UPDATE products SET quantity = quantity + $ret_qty, total = quantity * buy_price WHERE id = $ret_product_id");
            }
        }
    }

    // 5. أرشفة السجلات التاريخية والقيود إلى جداول التاريخ (History)
    $conn->query("INSERT INTO purchases_history SELECT * FROM purchases WHERE id = $invoice_id");
    if ($matched_by_purchase_id) {
        $conn->query("INSERT INTO purchase_items_history SELECT * FROM purchase_items WHERE purchase_id = $invoice_id");
    } else {
        $inv_date_esc = $conn->real_escape_string($inv_date);
        $inv_supp_esc = $conn->real_escape_string($inv_supplier);
        $conn->query("INSERT INTO purchase_items_history SELECT * FROM purchase_items WHERE buys_date = '$inv_date_esc' AND supp_name = '$inv_supp_esc'");
    }
    $conn->query("INSERT INTO purchase_returns_history SELECT * FROM purchase_returns WHERE purchase_id = $invoice_id");
    $conn->query("INSERT INTO journal_entries_history SELECT * FROM journal_entries WHERE (ref_type = 'purchase' AND ref_id = $invoice_id) OR (ref_type = 'return' AND ref_id IN (SELECT id FROM purchase_returns WHERE purchase_id = $invoice_id))");
    $conn->query("INSERT INTO accounting_journal_history SELECT * FROM accounting_journal WHERE (ref_type = 'purchase' AND ref_id = $invoice_id) OR (ref_type = 'return' AND ref_id IN (SELECT id FROM purchase_returns WHERE purchase_id = $invoice_id))");

    // 6. حذف بنود الفاتورة من purchase_items
    if ($matched_by_purchase_id) {
        $conn->query("DELETE FROM purchase_items WHERE purchase_id = $invoice_id");
    } else {
        $conn->query("DELETE FROM purchase_items WHERE buys_date = '" . $conn->real_escape_string($inv_date) . "' AND supp_name = '" . $conn->real_escape_string($inv_supplier) . "'");
    }

    // 7. حذف سجلات المردودات والقيود والنشطة من الجداول الفعالة
    $conn->query("DELETE FROM accounting_journal_entries WHERE (source_type = 'purchase' AND source_id = $invoice_id) OR (source_type = 'return' AND source_id IN (SELECT id FROM purchase_returns WHERE purchase_id = $invoice_id))");
    $conn->query("DELETE FROM journal_entries WHERE ref_type = 'return' AND ref_id IN (SELECT id FROM purchase_returns WHERE purchase_id = $invoice_id)");
    $conn->query("DELETE FROM accounting_journal WHERE ref_type = 'return' AND ref_id IN (SELECT id FROM purchase_returns WHERE purchase_id = $invoice_id)");
    $conn->query("DELETE FROM purchase_returns WHERE purchase_id = $invoice_id");
    $conn->query("DELETE FROM journal_entries WHERE ref_type = 'purchase' AND ref_id = $invoice_id");
    $conn->query("DELETE FROM accounting_journal WHERE ref_type = 'purchase' AND ref_id = $invoice_id");

    // 8. عكس مديونية المورد - إنقاص المتبقي الذي كانت هذه الفاتورة أضافته
    if (!empty($inv_supplier) && $total_remaining_base > 0) {
        $inv_supplier_esc = $conn->real_escape_string($inv_supplier);
        $conn->query("UPDATE suppliers SET supp_daain = GREATEST(0, supp_daain - $total_remaining_base) WHERE supp_name = '$inv_supplier_esc'");
    }

    // 9. استرجاع المبلغ المدفوع إلى الصندوق إذا كانت الفاتورة قد خُصمت منه أصلاً
    if ($box_paid_base > 0 && $inv_box_id > 0) {
        update_box_balance($conn, $inv_box_id, $box_paid_base, 'addition', "إلغاء دفعة فاتورة مشتريات رقم #$invoice_id بسبب حذف الفاتورة", $inv_date);
    }

    // 10. حذف الفاتورة الرئيسية
    $conn->query("DELETE FROM purchases WHERE id = $invoice_id");

    $conn->commit();
    header('Location: index.php?msg=deleted');
    exit;
} catch (Exception $e) {
    $conn->rollback();
    header('Location: index.php?msg=error');
    exit;
}
?>