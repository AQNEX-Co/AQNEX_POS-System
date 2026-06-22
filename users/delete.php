<?php
$dir_prefix = '../';
$module = 'users';
require_once($dir_prefix . 'includes/header.php');
check_permission(['admin']);

if (isset($_GET['id']) && !empty($_GET['id'])) {
    $del_id = intval($_GET['id']);
    
    // منع حذف المدير الرئيسي رقم 1
    if ($del_id === 1) {
        echo "<script>alert('خطأ: لا يمكن حذف المدير الرئيسي المؤسس للنظام!'); window.location='index.php';</script>";
        exit;
    }
    
    // منع المستخدم من حذف حسابه الشخصي الذي سجل الدخول به حالياً
    $current_logged_id = intval($_SESSION['SESS_MEMBER_ID']);
    if ($del_id === $current_logged_id) {
        echo "<script>alert('خطأ: لا يمكن حذف حسابك الشخصي الذي تستخدمه حالياً لتسجيل الدخول!'); window.location='index.php';</script>";
        exit;
    }
    
    $sql_del = "DELETE FROM users WHERE userid = $del_id";
    if ($conn->query($sql_del)) {
        echo "<script>window.location='index.php';</script>";
        exit;
    } else {
        echo "<script>alert('حدث خطأ أثناء الحذف: " . $conn->real_escape_string($conn->error) . "'); window.location='index.php';</script>";
        exit;
    }
} else {
    echo "<script>window.location='index.php';</script>";
    exit;
}
?>
