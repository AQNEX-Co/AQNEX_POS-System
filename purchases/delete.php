<?php
$dir_prefix = '../';
require_once($dir_prefix . 'includes/header.php');

check_permission(['admin']);

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
    // 1. استرجاع الكميات للمخزون قبل الحذف
    $res_items = $conn->query("SELECT * FROM purchase_items WHERE buys_date = '" . $conn->real_escape_string($inv_date) . "' AND supp_name = '" . $conn->real_escape_string($inv_supplier) . "'");
    if ($res_items && $res_items->num_rows > 0) {
        while ($item = $res_items->fetch_assoc()) {
            $qty = intval($item['quantity']);
            $item_name = $conn->real_escape_string($item['name']);
            // تخفيض الكمية في المخزون (عكس الشراء)
            $conn->query("UPDATE products SET quantity = GREATEST(0, quantity - $qty) WHERE name = '$item_name' LIMIT 1");
        }
    }

    // 2. حذف بنود الفاتورة من purchase_items
    $conn->query("DELETE FROM purchase_items WHERE buys_date = '" . $conn->real_escape_string($inv_date) . "' AND supp_name = '" . $conn->real_escape_string($inv_supplier) . "'");

    // 3. حذف القيود المحاسبية المرتبطة
    $conn->query("DELETE FROM accounting_journal WHERE ref_type = 'purchase' AND ref_id = $invoice_id");

    // 4. استرجاع المبلغ من الصندوق إذا كان هناك مدفوعات
    // (لا نعدل الصندوق لتجنب التعقيد - القيود المحاسبية كافية)

    // 5. حذف الفاتورة الرئيسية
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
