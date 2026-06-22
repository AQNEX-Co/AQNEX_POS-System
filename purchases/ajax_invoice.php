<?php
/**
 * AJAX: جلب بنود فاتورة مشتريات بواسطة الرقم
 * يُستدعى من purchases/returns.php عبر fetch() في JavaScript
 */
header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) session_start();

// التحقق من الجلسة
if (!isset($_SESSION['SESS_MEMBER_ID'])) {
    echo json_encode(['error' => 'غير مخول']);
    exit;
}

require_once(__DIR__ . '/../includes/connect.php');

$invoice_id = intval($_GET['invoice_id'] ?? 0);
if ($invoice_id <= 0) {
    echo json_encode(['error' => 'رقم فاتورة غير صالح']);
    exit;
}

// جلب الفاتورة الرئيسية
$res_inv = $conn->query("SELECT p.*, s.supp_daain
                          FROM purchases p
                          LEFT JOIN suppliers s ON p.supp_name = s.supp_name
                          WHERE p.id = $invoice_id
                          LIMIT 1");

if (!$res_inv || $res_inv->num_rows === 0) {
    echo json_encode(['error' => "لا توجد فاتورة مشتريات بالرقم #{$invoice_id}"]);
    exit;
}

$invoice = $res_inv->fetch_assoc();
$build_date = $invoice['date'];
$supplier_name = $invoice['supp_name'];
$exchange_rate = doubleval($invoice['exchange_rate'] ?? 1.0);
if ($exchange_rate <= 0) $exchange_rate = 1.0;

// جلب بنود الفاتورة
$res_items = $conn->query("SELECT * FROM purchase_items 
                            WHERE buys_date = '" . $conn->real_escape_string($build_date) . "' 
                            AND supp_name = '" . $conn->real_escape_string($supplier_name) . "'");

$items = [];
if ($res_items) {
    while ($row = $res_items->fetch_assoc()) {
        $p_name = $row['name'];
        
        // جلب تفاصيل المنتج من جدول المنتجات لمطابقة المعرف والكمية المتوفرة
        $p_esc = $conn->real_escape_string($p_name);
        $res_p = $conn->query("SELECT id, quantity, buy_price FROM products WHERE name = '$p_esc' LIMIT 1");
        $product_id = 0;
        $current_stock = 0;
        
        if ($res_p && $res_p->num_rows > 0) {
            $p_row = $res_p->fetch_assoc();
            $product_id = intval($p_row['id']);
            $current_stock = intval($p_row['quantity']);
        }
        
        // حساب إجمالي السطر وسعر الشراء الفردي في الفاتورة (بالعملة الأساسية YER)
        $line_total_base = doubleval($row['buy_price']); // buy_price يحمل إجمالي السطر بالريال اليمني
        $qty_purchased = intval($row['quantity']);
        $unit_buy_price_base = $qty_purchased > 0 ? ($line_total_base / $qty_purchased) : 0;
        
        // حساب الكمية الممكن إرجاعها (الكمية المشتراة - الكميات المرجعة سابقاً)
        $already_returned = 0;
        if ($product_id > 0) {
            $ret_res = $conn->query("SELECT COALESCE(SUM(quantity),0) AS returned FROM purchase_returns 
                                      WHERE purchase_id = $invoice_id AND product_id = $product_id AND status = 'active'");
            $already_returned = $ret_res ? intval($ret_res->fetch_assoc()['returned']) : 0;
        }
        
        $can_return = $qty_purchased - $already_returned;
        
        // لا يمكن إرجاع كمية أكبر من المتوفر حالياً في المخزن
        $can_return = min($can_return, $current_stock);
        
        $items[] = [
            'item_id'          => intval($row['buyid']),
            'product_id'       => $product_id,
            'name'             => $p_name,
            'quantity'         => $qty_purchased,
            'can_return'       => max(0, $can_return),
            'already_returned' => $already_returned,
            'unit_price'       => $unit_buy_price_base, // بالعملة الأساسية YER
            'line_total'       => $line_total_base,
            'current_stock'    => $current_stock
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
        'supp_name'     => $invoice['supp_name'],
        'total'         => doubleval($invoice['total']), // بالعملة الأساسية YER
        'date'          => $invoice['date'],
        'currency_code' => $invoice['currency_code'] ?? 'YER',
        'exchange_rate' => $exchange_rate,
    ],
    'items'   => $items,
    'returns_history' => $ret_history,
], JSON_UNESCAPED_UNICODE);
?>
