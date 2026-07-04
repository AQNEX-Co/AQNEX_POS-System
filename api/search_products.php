<?php
$dir_prefix = '../';
require_once(__DIR__ . '/../includes/connect.php');
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once(__DIR__ . '/../includes/auth.php');
require_once(__DIR__ . '/../includes/modules.php');

check_permission(['admin', 'cashier', 'inventory']);

header('Content-Type: application/json; charset=utf-8');

$q = isset($_GET['q']) ? trim($_GET['q']) : '';
$q_esc = $conn->real_escape_string($q);
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
if ($page <= 0) $page = 1;
$limit = 50;
$offset = ($page - 1) * $limit;

$where_clause = "p.delete_status = 0";
if (!empty($q)) {
    $where_clause .= " AND (p.name LIKE '%$q_esc%' OR p.barcode LIKE '%$q_esc%' OR p.id IN (SELECT DISTINCT product_id FROM product_barcodes WHERE barcode LIKE '%$q_esc%' AND d_s = '0'))";
}

$products_list = [];

// جلب المنتجات الأساسية المطابقة للبحث مع مراعاة الترقيم والحد الأقصى
$sql_prod = "
    SELECT p.id, p.name, p.barcode, p.quantity, p.buy_price, p.sale_price, p.has_multiple_units, p.track_expiry, c.requires_serial 
    FROM products p
    LEFT JOIN categories c ON p.catid = c.catid
    WHERE $where_clause
    ORDER BY p.id DESC
    LIMIT $limit OFFSET $offset
";
$res_prod = $conn->query($sql_prod);

$matching_ids = [];
$matched_rows = [];
if ($res_prod && $res_prod->num_rows > 0) {
    while($row = $res_prod->fetch_assoc()) {
        $matched_rows[] = $row;
        $matching_ids[] = intval($row['id']);
    }
}

$units_list = [];
if (!empty($matching_ids) && is_module_enabled('barcode_units')) {
    $ids_str = implode(',', $matching_ids);
    $res_units = $conn->query("SELECT * FROM product_units WHERE product_id IN ($ids_str) AND d_s = '0'");
    if ($res_units) {
        while($u = $res_units->fetch_assoc()) {
            $units_list[$u['product_id']][] = $u;
        }
    }
}

foreach ($matched_rows as $row) {
    // المنتج الأساسي
    $products_list[] = [
        'id' => intval($row['id']),
        'name' => $row['name'],
        'barcode' => $row['barcode'],
        'quantity' => intval($row['quantity']),
        'buy_price' => doubleval($row['buy_price']),
        'sale_price' => doubleval($row['sale_price']),
        'has_multiple_units' => intval($row['has_multiple_units']),
        'track_expiry' => intval($row['track_expiry']),
        'requires_serial' => intval($row['requires_serial']),
        'is_unit' => 0,
        'unit_id' => null,
        'unit_name' => 'الوحدة الأساسية',
        'conversion_factor' => 1.0000
    ];
    
    // إضافة الوحدات الفرعية
    if (is_module_enabled('barcode_units') && isset($units_list[$row['id']])) {
        foreach ($units_list[$row['id']] as $u) {
            $u_barcode = '';
            $res_bc = $conn->query("SELECT barcode FROM product_barcodes WHERE product_id = " . $row['id'] . " AND unit_id = " . $u['id'] . " AND d_s = '0' LIMIT 1");
            if ($res_bc && $res_bc->num_rows > 0) {
                $u_barcode = $res_bc->fetch_assoc()['barcode'];
            }
            
            $products_list[] = [
                'id' => intval($row['id']),
                'name' => $row['name'] . ' (' . $u['unit_name'] . ')',
                'barcode' => $u_barcode,
                'quantity' => intval($row['quantity']),
                'buy_price' => doubleval($u['purchase_price']),
                'sale_price' => doubleval($u['sale_price']),
                'has_multiple_units' => intval($row['has_multiple_units']),
                'track_expiry' => intval($row['track_expiry']),
                'requires_serial' => intval($row['requires_serial']),
                'is_unit' => 1,
                'unit_id' => intval($u['id']),
                'unit_name' => $u['unit_name'],
                'conversion_factor' => doubleval($u['conversion_factor'])
            ];
        }
    }
}

echo json_encode($products_list);
exit;
