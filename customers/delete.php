<?php
$dir_prefix = '../';
require_once($dir_prefix . 'includes/connect.php');

if (isset($_GET['id'])) {
    $cust_id = intval($_GET['id']);
    
    // تحويل حالة الحذف إلى 1 (Soft Delete) للمحافظة على السجلات التاريخية والمالية
    $sql = "UPDATE customers SET d_s = '1' WHERE cust_id = $cust_id";
    $conn->query($sql);
}

header('Location: index.php');
exit();
?>
