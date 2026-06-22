<?php
$dir_prefix = '../';
require_once($dir_prefix . 'includes/connect.php');

if (isset($_GET['id'])) {
    $sale_id = intval($_GET['id']);
    
    // 1. جلب العميل وتفاصيل المنتجات في الفاتورة قبل الحذف لإعادة ضبط المخازن
    $sql_sale = "SELECT * FROM sales WHERE id = $sale_id";
    $res_sale = $conn->query($sql_sale);
    if ($res_sale && $sale = $res_sale->fetch_assoc()) {
        $cust_name = $conn->real_escape_string($sale['cust_name']);
        
        // 2. جلب المنتجات المباعة في هذه الفاتورة
        $sql_items = "SELECT * FROM sales_items WHERE sales_id = $sale_id";
        $res_items = $conn->query($sql_items);
        if ($res_items) {
            while ($item = $res_items->fetch_assoc()) {
                $product_name_full = $item['name'];
                $qty = intval($item['quantity']);
                $remaining_due = doubleval($item['dis']); // dis يحمل المتبقي
                
                // جلب معرف المنتج من الاسم (تنسيق: "ID Name")
                $parts = explode(' ', $product_name_full);
                $product_id = intval($parts[0]);
                
                // إعادة الكمية للمستودع
                if ($product_id > 0) {
                    $sql_update_qty = "UPDATE products SET quantity = quantity + $qty WHERE id = $product_id";
                    $conn->query($sql_update_qty);
                }
                
                // إعادة خصم مديونية العميل
                if ($cust_name && $cust_name !== 'عميل نقدي' && $remaining_due > 0) {
                    $sql_update_cust = "UPDATE customers SET cust_madeen = cust_madeen - $remaining_due WHERE cust_name = '$cust_name'";
                    $conn->query($sql_update_cust);
                }
            }
        }
        
        // 3. حذف المنتجات وتفاصيل الفاتورة من الجداول
        $sql_del_products = "DELETE FROM sales_items WHERE sales_id = $sale_id";
        $conn->query($sql_del_products);
        
        $sql_del_sale = "DELETE FROM sales WHERE id = $sale_id";
        $conn->query($sql_del_sale);
    }
}

header('Location: index.php');
exit();
?>
