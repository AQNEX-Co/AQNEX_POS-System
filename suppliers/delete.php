<?php
$dir_prefix = '../';
require_once($dir_prefix . 'includes/auth.php');
require_once($dir_prefix . 'includes/connect.php');

if (isset($_GET['id'])) {
    $supp_name = $conn->real_escape_string($_GET['id']);
    
    // تنفيذ الاستعلامات لحذف المورد والمشتريات والمدفوعات الخاصة به
    $sql1 = "DELETE FROM Suppliers WHERE supp_name='$supp_name'";
    $conn->query($sql1);
    
    $sql2 = "DELETE FROM purchase_items WHERE supp_name='$supp_name'";
    $conn->query($sql2);
    
    $sql3 = "DELETE FROM supplier_payments WHERE supp_name='$supp_name'";
    $conn->query($sql3);
}

header('Location: index.php');
exit;
?>
