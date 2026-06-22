<?php
require_once('includes/connect.php');
if (isset($conn) && !$conn->connect_error) {
    $res = $conn->query("SELECT username, password FROM users WHERE position = 'admin' LIMIT 1");
    if ($res && $res->num_rows > 0) {
        $row = $res->fetch_assoc();
        echo "<div style='direction:rtl; text-align:right; font-family:tahoma; padding:20px;'>";
        echo "<h3>بيانات مدير النظام الرئيسي:</h3>";
        echo "<b>اسم المستخدم:</b> " . htmlspecialchars($row['username']) . "<br>";
        echo "<b>كلمة المرور الحالية:</b> <span style='color:red; font-weight:bold;'>" . htmlspecialchars($row['password']) . "</span><br><br>";
        echo "<hr>";
        echo "<b>لتغيير كلمة المرور:</b> أضف للرابط: <code style='background:#eee; padding:2px;'>?new_pass=كلمة_المرور_الجديدة</code>";
        
        if (isset($_GET['new_pass']) && !empty($_GET['new_pass'])) {
            $new = $conn->real_escape_string($_GET['new_pass']);
            $conn->query("UPDATE users SET password = '$new' WHERE position = 'admin'");
            echo "<br><br><b style='color:green;'>✓ تم تحديث كلمة المرور بنجاح إلى: $new</b>";
        }
        echo "</div>";
    } else {
        echo "لم يتم العثور على حساب مسؤول بالنظام.";
    }
} else {
    echo "فشل الاتصال بقاعدة البيانات.";
}
?>
