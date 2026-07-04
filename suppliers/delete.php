<?php
$dir_prefix = '../';
require_once($dir_prefix . 'includes/auth.php');
require_once($dir_prefix . 'includes/connect.php');

if (isset($_GET['id'])) {
    $supp_id = intval($_GET['id']);
    
    // تحويل حالة الحذف إلى 1 (Soft Delete) للمحافظة على السجلات التاريخية والمشتريات
    $sql = "UPDATE Suppliers SET d_s = '1' WHERE supp_id = $supp_id";
    $conn->query($sql);
}

header('Location: index.php');
exit;
?>
