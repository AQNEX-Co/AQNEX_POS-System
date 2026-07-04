<?php
$dir_prefix = '../';
require_once($dir_prefix . 'includes/connect.php');
require_once($dir_prefix . 'includes/auth.php');

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

    // 3. استرجاع الكميات للمخزون (عكس الشراء: طرح البضاعة المشتراة وتحديث القيمة الكلية)
    foreach ($items_rows as $item) {
        $qty = intval($item['quantity'] ?? 0);
        $item_name = trim((string)($item['name'] ?? ''));
        $parts = explode(' ', $item_name, 2);
        $product_id = intval($parts[0]);

        if ($product_id > 0) {
            $conn->query("UPDATE products SET quantity = GREATEST(0, quantity - $qty), total = quantity * buy_price WHERE id = $product_id");
        } elseif (!empty($item_name)) {
            $item_name_esc = $conn->real_escape_string($item_name);
            $conn->query("UPDATE products SET quantity = GREATEST(0, quantity - $qty), total = quantity * buy_price WHERE name = '$item_name_esc' LIMIT 1");
        }
    }

    // 4. عكس تأثير المردودات المرتبطة بهذه الفاتورة إذا كانت موجودة (إضافة كمية المردودات الملغاة للمخزون)
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

    // 5. حذف بنود الفاتورة من purchase_items
    if ($matched_by_purchase_id) {
        $conn->query("DELETE FROM purchase_items WHERE purchase_id = $invoice_id");
    } else {
        $conn->query("DELETE FROM purchase_items WHERE buys_date = '" . $conn->real_escape_string($inv_date) . "' AND supp_name = '" . $conn->real_escape_string($inv_supplier) . "'");
    }

    // 6. حذف قيود المردودات المرتبطة قبل حذف سجلات المردودات
    $conn->query("DELETE FROM journal_entries WHERE ref_type = 'return' AND ref_id IN (SELECT id FROM purchase_returns WHERE purchase_id = $invoice_id)");
    $conn->query("DELETE FROM accounting_journal WHERE ref_type = 'return' AND ref_id IN (SELECT id FROM purchase_returns WHERE purchase_id = $invoice_id)");
    $conn->query("DELETE FROM purchase_returns WHERE purchase_id = $invoice_id");
    $conn->query("DELETE FROM journal_entries WHERE ref_type = 'purchase' AND ref_id = $invoice_id");
    $conn->query("DELETE FROM accounting_journal WHERE ref_type = 'purchase' AND ref_id = $invoice_id");

    // 7. عكس مديونية المورد - إنقاص المتبقي الذي كانت هذه الفاتورة أضافته
    //    (هذا هو الجزء الذي كان مفقوداً ويسبب بقاء "مديونية" بعد حذف الفاتورة)
    if (!empty($inv_supplier) && $total_remaining_base > 0) {
        $inv_supplier_esc = $conn->real_escape_string($inv_supplier);
        $conn->query("UPDATE suppliers SET supp_daain = GREATEST(0, supp_daain - $total_remaining_base) WHERE supp_name = '$inv_supplier_esc'");
    }

    // 7. استرجاع المبلغ المدفوع إلى الصندوق إذا كانت الفاتورة قد خُصمت منه أصلاً
    if ($box_paid_base > 0 && $inv_box_id > 0) {
        $conn->query("UPDATE treasury SET mony = mony + $box_paid_base WHERE box_id = $inv_box_id");
    }

    // 8. حذف الفاتورة الرئيسية
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