<?php
$dir_prefix = '../';
require_once($dir_prefix . 'includes/connect.php');

if (isset($_GET['id'])) {
    $amount = doubleval($_GET['id']);
    
    // ترحيل المبلغ وتحديث الرصيد الحالي للصندوق
    $sql = "UPDATE treasury SET mony = $amount WHERE box_id = '1'";
    $conn->query($sql);
    
    // تسجيل عملية ترحيل اليوم
    $today = date("Y-m-d H:i:s");
    $sql_log = "INSERT INTO treasury_transactions(mony, statue, remark, datte) VALUES ($amount, 'addition', 'ترحيل الصافي لليوم', '$today')";
    $conn->query($sql_log);
}

header('Location: index.php');
exit();
?>
