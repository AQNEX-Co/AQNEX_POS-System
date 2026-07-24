<?php
/**
 * AJAX: جلب بنود فاتورة مشتريات بواسطة الرقم
 * يُستدعى من purchases/returns.php عبر fetch() في JavaScript
 */
header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['SESS_MEMBER_ID'])) {
    echo json_encode(['error' => 'غير مخول']);
    exit;
}

require_once(__DIR__ . '/../includes/connect.php');

// جلب قائمة فواتير المشتريات للبحث (Lookup)
if (isset($_GET['action']) && $_GET['action'] === 'list') {
    $search_term = $conn->real_escape_string($_GET['search'] ?? '');
    // تم التحديث لاستخدام جدول الماستر الجديد
    $sql = "SELECT id, invoice_no, supp_name, total_amount, invoice_date FROM purchase_invoices_mst WHERE d_s = 0";
    if (!empty($search_term)) {
        $sql .= " AND (id LIKE '%$search_term%' OR invoice_no LIKE '%$search_term%' OR supp_name LIKE '%$search_term%')";
    }
    $sql .= " ORDER BY id DESC LIMIT 50";
    $res = $conn->query($sql);
    $list = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $list[] = [
                'id' => intval($row['id']),
                'invoice_no' => $row['invoice_no'],
                'supp_name' => $row['supp_name'] ?: 'مورد عام',
                'total' => doubleval($row['total_amount']),
                'date' => $row['invoice_date']
            ];
        }
    }
    echo json_encode(['invoices' => $list], JSON_UNESCAPED_UNICODE);
    exit;
}

$invoice_id = intval($_GET['invoice_id'] ?? 0);
if ($invoice_id <= 0) {
    echo json_encode(['error' => 'رقم فاتورة غير صالح']);
    exit;
}

// جلب الفاتورة الرئيسية من جدول الماستر الجديد
$res_inv = $conn->query("SELECT p.*, s.supp_daain, s.supp_id as supplier_id
                          FROM purchase_invoices_mst p
                          LEFT JOIN suppliers s ON p.supp_id = s.supp_id AND s.d_s = 0
                          WHERE p.id = $invoice_id AND p.d_s = 0
                          LIMIT 1");

if (!$res_inv || $res_inv->num_rows === 0) {
    echo json_encode(['error' => "لا توجد فاتورة مشتريات بالرقم #{$invoice_id}"]);
    exit;
}

$invoice = $res_inv->fetch_assoc();
$build_date = $invoice['invoice_date'];
$supplier_name = $invoice['supp_name'];
$exchange_rate = doubleval($invoice['exchange_rate'] ?? 1.0);
if ($exchange_rate <= 0) $exchange_rate = 1.0;

// جلب بنود الفاتورة من جدول التفاصيل الجديد
$res_items = $conn->query("SELECT * FROM purchase_invoices_dtl WHERE invoice_id = $invoice_id AND d_s = 0 ORDER BY id ASC");

$items = [];
if ($res_items) {
    while ($row = $res_items->fetch_assoc()) {
        $p_name = $row['product_name'];
        $product_id = intval($row['product_id']);
        
        // جلب تفاصيل المنتج من جدول المنتجات لمطابقة المعرف والكمية المتوفرة
        $current_stock = 0;
        if ($product_id > 0) {
            $res_p = $conn->query("SELECT quantity FROM products WHERE id = $product_id LIMIT 1");
            if ($res_p && $res_p->num_rows > 0) {
                $current_stock = intval($res_p->fetch_assoc()['quantity']);
            }
        }
        
        $qty_purchased = doubleval($row['quantity']);
        $unit_buy_price_base = doubleval($row['unit_cost']);
        $line_total_base = doubleval($row['total_cost']);
        
        // حساب الكمية الممكن إرجاعها (الكمية المشتراة - الكميات المرجعة سابقاً)
        $already_returned = 0;
        if ($product_id > 0) {
            // ملاحظة: يُفضل تحديث جدول purchase_returns ليستخدم invoice_id بدلاً من purchase_id مستقبلاً
            $ret_res = $conn->query("SELECT COALESCE(SUM(quantity),0) AS returned FROM purchase_returns 
                                      WHERE purchase_id = $invoice_id AND product_id = $product_id AND status = 'active'");
            $already_returned = $ret_res ? doubleval($ret_res->fetch_assoc()['returned']) : 0;
        }
        
        $can_return = $qty_purchased - $already_returned;
        $can_return = min($can_return, $current_stock);

        $unit_name = !empty($row['unit_name']) ? $row['unit_name'] : 'حبة';
        
        $items[] = [
            'item_id'          => intval($row['id']), // معرف البند في جدول التفاصيل
            'product_id'       => $product_id,
            'name'             => $p_name,
            'quantity'         => $qty_purchased,
            'can_return'       => max(0, $can_return),
            'already_returned' => $already_returned,
            'unit_price'       => $unit_buy_price_base, // سعر الوحدة
            'line_total'       => $line_total_base,     // إجمالي البند
            'current_stock'    => $current_stock,
            'unit_name'        => $unit_name
        ];
    }
}

// المرتجعات السابقة لهذه الفاتورة
$ret_history = [];
$res_ret = $conn->query("SELECT * FROM purchase_returns WHERE purchase_id = $invoice_id AND status = 'active' ORDER BY id DESC");
if ($res_ret) {
    while ($r = $res_ret->fetch_assoc()) {
        $ret_history[] = [
            'id'     => $r['id'],
            'product'=> $r['product_name'],
            'qty'    => $r['quantity'],
            'refund' => $r['refund_amount'],
            'reason' => $r['reason'],
            'date'   => $r['return_date'],
        ];
    }
}

echo json_encode([
    'invoice' => [
        'id'            => intval($invoice['id']),
        'invoice_no'    => $invoice['invoice_no'],
        'supp_name'     => $invoice['supp_name'],
        'total'         => doubleval($invoice['total_amount']),
        'date'          => $invoice['invoice_date'],
        'currency_code' => $invoice['currency_code'] ?? 'YER',
        'exchange_rate' => $exchange_rate,
        'invoice_type'  => $invoice['invoice_type'] ?? (doubleval($invoice['remaining_amount']) > 0 ? 'credit' : 'cash'),
    ],
    'items'   => $items,
    'returns_history' => $ret_history,
], JSON_UNESCAPED_UNICODE);
?>