<?php
$dir_prefix = '../';
require_once($dir_prefix . 'includes/connect.php');

if (isset($_GET['id'])) {
    $cust_name = $conn->real_escape_string($_GET['id']);
    
    $sql = "DELETE FROM customers WHERE cust_name = '$cust_name'";
    $conn->query($sql);
    
    $sqlx = "DELETE FROM receipts WHERE cust_name = '$cust_name'";
    $conn->query($sqlx);
    
    $sqlxx = "DELETE FROM sales_items WHERE cust_name = '$cust_name'";
    $conn->query($sqlxx);
}

header('Location: index.php');
exit();
?>
