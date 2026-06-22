<?php
/**
 * AJAX: جلب بنود فاتورة مبيعات بواسطة الرقم
 * يُستدعى من sales/returns.php عبر fetch() في JavaScript
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
$res_inv = $conn->query("SELECT s.*, c.cust_madeen
                          FROM sales s
                          LEFT JOIN customers c ON s.cust_name = c.cust_name
                          WHERE s.id = $invoice_id AND s.delete_status = 0
                          LIMIT 1");

if (!$res_inv || $res_inv->num_rows === 0) {
    echo json_encode(['error' => "لا توجد فاتورة بالرقم #{$invoice_id}"]);
    exit;
}

$invoice = $res_inv->fetch_assoc();

// جلب بنود الفاتورة
$res_items = $conn->query("SELECT si.*,
                            p.buy_price AS current_buy_price,
                            p.id AS product_db_id
                            FROM sales_items si
                            LEFT JOIN products p ON p.id = si.id
                            WHERE si.sales_id = $invoice_id");

$items = [];
if ($res_items) {
    while ($row = $res_items->fetch_assoc()) {
        // استخراج معرف المنتج من حقل name إذا كان مدمجاً (ID اسم_المنتج)
        $name_field = trim($row['name']);
        $product_id = $row['product_db_id'] ?: $row['id'];
        // إزالة الـ id من أول الاسم إذا كان بصيغة "123 اسم المنتج"
        $clean_name = preg_replace('/^\d+\s+/', '', $name_field);

        // حساب الكمية الممكن إرجاعها (الكمية الأصلية - الكميات المرتجعة سابقاً)
        $ret_res = $conn->query("SELECT COALESCE(SUM(quantity),0) AS returned FROM sales_returns WHERE sales_id=$invoice_id AND product_id=$product_id AND status='active'");
        $already_returned = $ret_res ? intval($ret_res->fetch_assoc()['returned']) : 0;
        $can_return = intval($row['quantity']) - $already_returned;

        $items[] = [
            'item_id'         => intval($row['p_id']),
            'product_id'      => intval($product_id),
            'name'            => $clean_name,
            'quantity'        => intval($row['quantity']),
            'can_return'      => max(0, $can_return),
            'already_returned'=> $already_returned,
            'unit_price'      => doubleval($row['unit_price']),  // بالعملة الأساسية YER
            'buy_price'       => doubleval($row['current_buy_price'] ?? 0),
            'line_total'      => doubleval($row['all_tot']),
        ];
    }
}

// المرتجعات السابقة لهذه الفاتورة
$ret_history = [];
$res_ret = $conn->query("SELECT * FROM sales_returns WHERE sales_id=$invoice_id AND status='active' ORDER BY id DESC");
if ($res_ret) {
    while($r = $res_ret->fetch_assoc()) {
        $ret_history[] = [
            'id'          => $r['id'],
            'product'     => $r['product_name'],
            'qty'         => $r['quantity'],
            'refund'      => $r['refund_amount'],
            'reason'      => $r['reason'],
            'date'        => $r['return_date'],
        ];
    }
}

echo json_encode([
    'invoice' => [
        'id'         => intval($invoice['id']),
        'cust_name'  => $invoice['cust_name'],
        'total'      => doubleval($invoice['total']),
        'prifet'     => doubleval($invoice['prifet']),
        'build_date' => $invoice['build_date'],
        'currency_code'   => $invoice['currency_code'] ?? 'YER',
        'exchange_rate'   => doubleval($invoice['exchange_rate'] ?? 1.0),
    ],
    'items'   => $items,
    'returns_history' => $ret_history,
], JSON_UNESCAPED_UNICODE);
