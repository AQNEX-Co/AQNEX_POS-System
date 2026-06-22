<?php
$dir_prefix = '../';
$module = 'changeuser';
require_once($dir_prefix . "includes/header.php");

$success_msg = '';
$error_msg = '';

// جلب البيانات الحالية للمستخدم (ID = 1)
$sql_user = "SELECT * FROM users WHERE userid=1 LIMIT 1";
$res_user = $conn->query($sql_user);
$user_data = ($res_user && $res_user->num_rows > 0) ? $res_user->fetch_assoc() : null;

if (isset($_POST['btn'])) {
    $username_val = $conn->real_escape_string($_POST['username']);
    $password_val = $conn->real_escape_string($_POST['password']);
    $phone_val = $conn->real_escape_string($_POST['phone']);
    $code_val = $conn->real_escape_string($_POST['code']);
    
    $sql_update = "UPDATE users SET username='$username_val', password='$password_val', phone='$phone_val', code='$code_val' WHERE userid=1";
    
    if ($conn->query($sql_update)) {
        $_SESSION['SESS_FIRST_NAME'] = $username_val;
        echo "<script>alert('تم تعديل بيانات الحساب بنجاح.'); window.location='../home.php';</script>";
        exit;
    } else {
        $error_msg = "خطأ أثناء تحديث البيانات: " . $conn->error;
    }
}
?>
<title>تعديل بيانات المستخدم - تكنولوجيا فون</title>

<div class="row no-print mb-4">
    <div class="col-md-6">
        <h3 class="text-secondary font-weight-bold">
            <i class="fa fa-cog ml-2"></i>إعدادات الحساب
        </h3>
        <p class="text-muted small mb-0">تغيير اسم المستخدم، كلمة المرور، رقم الهاتف، ورمز التحقق لحماية حسابك.</p>
    </div>
    <div class="col-md-6 text-left">
        <a href="../home.php" class="btn-flat btn-flat-secondary btn-sm">
            <i class="fa fa-arrow-left ml-1"></i>عودة للرئيسية
        </a>
    </div>
</div>

<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="card-flat">
            <div class="card-header bg-light">
                <h5>بيانات حساب المسؤول الحالية</h5>
            </div>
            <div class="card-body">
                <?php if (!empty($error_msg)): ?>
                    <div class="alert alert-danger rounded-0 mb-3"><?php echo $error_msg; ?></div>
                <?php endif; ?>

                <form method="POST">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <div class="form-group">
                                <label class="font-weight-bold text-secondary">اسم المستخدم <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="username" value="<?php echo htmlspecialchars($user_data ? $user_data['username'] : ''); ?>" placeholder="أدخل اسم المستخدم الجديد" required>
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <div class="form-group">
                                <label class="font-weight-bold text-secondary">كلمة المرور الجديدة <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="password" value="<?php echo htmlspecialchars($user_data ? $user_data['password'] : ''); ?>" placeholder="أدخل كلمة المرور الجديدة" required>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <div class="form-group">
                                <label class="font-weight-bold text-secondary">رقم الجوال للتحقق <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="phone" value="<?php echo htmlspecialchars($user_data ? $user_data['phone'] : ''); ?>" placeholder="أدخل رقم الجوال لاستعادة كلمة المرور" required>
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <div class="form-group">
                                <label class="font-weight-bold text-secondary">رمز التحقق السري <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="code" value="<?php echo htmlspecialchars($user_data ? $user_data['code'] : ''); ?>" placeholder="رمز التحقق الخاص بالاستعادة" required>
                            </div>
                        </div>
                    </div>
                    
                    <hr class="my-4">
                    
                    <div class="form-group mb-0 text-left">
                        <button type="submit" class="btn-flat btn-flat-success" name="btn">
                            <i class="fa fa-save ml-1"></i>حفظ البيانات الجديدة
                        </button>
                        <a href="../home.php" class="btn-flat btn-flat-secondary mr-2">إلغاء</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php
require_once($dir_prefix . "includes/footer.php");
?>
