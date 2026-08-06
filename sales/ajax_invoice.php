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

// جلب قائمة الفواتير للبحث (Lookup)
if (isset($_GET['action']) && $_GET['action'] === 'list') {
    $search_term = $conn->real_escape_string($_GET['search'] ?? '');
    $search_term = $conn->real_escape_string($_GET['search'] ?? '');
    $sql = "SELECT id, invoice_no, cust_name, total_amount, invoice_date FROM sales_invoices_mst WHERE d_s = 0";
    if (!empty($search_term)) {
        $sql .= " AND (id LIKE '%$search_term%' OR invoice_no LIKE '%$search_term%' OR cust_name LIKE '%$search_term%')";
    }
    $sql .= " ORDER BY id DESC LIMIT 50";
    $res = $conn->query($sql);
    $list = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $list[] = [
                'id' => intval($row['id']),
                'invoice_no' => $row['invoice_no'],
                'cust_name' => $row['cust_name'] ?: 'عميل نقدي',
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

// جلب الفاتورة الرئيسية من Master
$res_inv = $conn->query("SELECT s.*, c.cust_madeen
                          FROM sales_invoices_mst s
                          LEFT JOIN customers c ON (s.cust_id = c.supp_id OR s.cust_name = c.cust_name)
                          WHERE (s.id = $invoice_id OR s.invoice_no = 'INV-" . str_pad($invoice_id, 6, '0', STR_PAD_LEFT) . "') AND s.d_s = 0
                          LIMIT 1");

if (!$res_inv || $res_inv->num_rows === 0) {
    // Fallback to legacy sales table
    $res_inv = $conn->query("SELECT s.*, s.total AS total_amount, s.build_date AS invoice_date FROM sales s WHERE s.id = $invoice_id AND s.delete_status = 0 LIMIT 1");
}

if (!$res_inv || $res_inv->num_rows === 0) {
    echo json_encode(['error' => "لا توجد فاتورة بالرقم #{$invoice_id}"]);
    exit;
}

$invoice = $res_inv->fetch_assoc();
$actual_invoice_id = intval($invoice['id']);

// جلب بنود الفاتورة من Detail
$res_items = $conn->query("SELECT dtl.*, p.buy_price AS current_buy_price
                            FROM sales_invoices_dtl dtl
                            LEFT JOIN products p ON p.id = dtl.product_id
                            WHERE dtl.invoice_id = $actual_invoice_id AND dtl.d_s = 0");

$items = [];
if ($res_items && $res_items->num_rows > 0) {
    while ($row = $res_items->fetch_assoc()) {
        $product_id = intval($row['product_id']);
        $clean_name = $row['product_name'];

        // حساب الكمية الممكن إرجاعها من sales_returns_mst & dtl
        $ret_res = $conn->query("SELECT COALESCE(SUM(dtl.quantity), 0) AS returned 
                                  FROM sales_returns_dtl dtl 
                                  JOIN sales_returns_mst mst ON dtl.return_id = mst.id 
                                  WHERE mst.original_sale_id = $actual_invoice_id AND dtl.product_id = $product_id AND dtl.d_s = 0");
        $already_returned = $ret_res ? floatval($ret_res->fetch_assoc()['returned']) : 0;
        $can_return = floatval($row['quantity']) - $already_returned;

        $items[] = [
            'item_id'         => $product_id,
            'product_id'      => $product_id,
            'name'            => $clean_name,
            'quantity'        => floatval($row['quantity']),
            'can_return'      => max(0, $can_return),
            'already_returned'=> $already_returned,
            'unit_price'      => doubleval($row['unit_price']),
            'buy_price'       => doubleval($row['current_buy_price'] ?? 0),
            'line_total'      => doubleval($row['total_price']),
            'unit_name'       => $row['unit_name'] ?: 'حبة',
        ];
    }
} else {
    // Fallback to sales_items
    $res_items_legacy = $conn->query("SELECT si.*, p.buy_price AS current_buy_price, p.id AS product_db_id FROM sales_items si LEFT JOIN products p ON p.id = si.id WHERE si.sales_id = $actual_invoice_id");
    if ($res_items_legacy) {
        while ($row = $res_items_legacy->fetch_assoc()) {
            $product_id = $row['product_db_id'] ?: $row['id'];
            $clean_name = preg_replace('/^\d+\s+/', '', trim($row['name']));
            $ret_res = $conn->query("SELECT COALESCE(SUM(quantity),0) AS returned FROM sales_returns WHERE sales_id=$actual_invoice_id AND product_id=$product_id AND status='active'");
            $already_returned = $ret_res ? floatval($ret_res->fetch_assoc()['returned']) : 0;
            $can_return = floatval($row['quantity']) - $already_returned;

            $items[] = [
                'item_id'         => intval($row['p_id']),
                'product_id'      => intval($product_id),
                'name'            => $clean_name,
                'quantity'        => floatval($row['quantity']),
                'can_return'      => max(0, $can_return),
                'already_returned'=> $already_returned,
                'unit_price'      => doubleval($row['unit_price']),
                'buy_price'       => doubleval($row['current_buy_price'] ?? 0),
                'line_total'      => doubleval($row['all_tot']),
                'unit_name'       => $row['unit_name'] ?: 'حبة',
            ];
        }
    }
}



// المرتجعات السابقة لهذه الفاتورة
$ret_history = [];
$res_ret = $conn->query("SELECT dtl.*, mst.return_date, mst.reason 
                          FROM sales_returns_dtl dtl 
                          JOIN sales_returns_mst mst ON dtl.return_id = mst.id 
                          WHERE mst.original_sale_id = $actual_invoice_id AND dtl.d_s = 0 
                          ORDER BY dtl.id DESC");

if ($res_ret && $res_ret->num_rows > 0) {
    while($r = $res_ret->fetch_assoc()) {
        $ret_history[] = [
            'id'          => $r['id'],
            'product'     => $r['product_name'],
            'qty'         => $r['quantity'],
            'refund'      => $r['total_amount'],
            'reason'      => $r['reason'],
            'date'        => $r['return_date'],
        ];
    }
} else {
    $res_ret_legacy = $conn->query("SELECT * FROM sales_returns WHERE sales_id=$actual_invoice_id AND status='active' ORDER BY id DESC");
    if ($res_ret_legacy) {
        while($r = $res_ret_legacy->fetch_assoc()) {
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
