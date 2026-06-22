<?php
$dir_prefix = '../';
$module = 'users';
require_once($dir_prefix . 'includes/header.php');
check_permission(['admin']);

$error = '';
$success = '';

if (isset($_POST['btn_save'])) {
    $username = $conn->real_escape_string(trim($_POST['username']));
    $password = $conn->real_escape_string(trim($_POST['password']));
    $phone = $conn->real_escape_string(trim($_POST['phone']));
    $code = $conn->real_escape_string(trim($_POST['code']));
    $position = $conn->real_escape_string(trim($_POST['position']));

    if (empty($username) || empty($password) || empty($position)) {
        $error = 'الرجاء تعبئة الحقول الإجبارية (الاسم، كلمة المرور، والدور الوظيفي).';
    } else {
        // التحقق من تكرار اسم المستخدم
        $sql_chk = "SELECT userid FROM users WHERE username = '$username'";
        $res_chk = $conn->query($sql_chk);
        if ($res_chk && $res_chk->num_rows > 0) {
            $error = 'اسم المستخدم هذا مسجل بالفعل لآخر، الرجاء اختيار اسم آخر.';
        } else {
            $sql_ins = "INSERT INTO users (username, password, phone, code, position) 
                        VALUES ('$username', '$password', '$phone', '$code', '$position')";
            if ($conn->query($sql_ins)) {
                echo "<script>window.location='index.php';</script>";
                exit;
            } else {
                $error = 'حدث خطأ أثناء إضافة المستخدم: ' . $conn->error;
            }
        }
    }
}
?>
<title>إضافة موظف جديد - تكنولوجيا فون</title>

<div class="row mb-4">
    <div class="col-md-6">
        <h3 class="text-secondary font-weight-bold">
            <?php echo get_icon('plus', 'ml-2 text-primary'); ?> إضافة حساب موظف جديد
        </h3>
    </div>
    <div class="col-md-6 text-left">
        <a href="index.php" class="btn-flat btn-flat-secondary btn-sm text-decoration-none">
            <?php echo get_icon('logout', 'ml-1'); ?> عودة للقائمة
        </a>
    </div>
</div>

<div class="row justify-content-center">
    <div class="col-md-8">
        <?php if (!empty($error)): ?>
            <div class="alert alert-danger rounded-0 mb-4"><?php echo $error; ?></div>
        <?php endif; ?>

        <div class="card-flat">
            <div class="card-header bg-light">
                <h5>بيانات الحساب الجديد والصلاحيات</h5>
            </div>
            <div class="card-body">
                <form method="POST">
                    <div class="row">
                        <!-- اسم المستخدم -->
                        <div class="col-md-6 mb-3">
                            <label class="font-weight-bold text-secondary mb-2">اسم المستخدم / الموظف *</label>
                            <input type="text" name="username" class="form-control rounded-0" placeholder="أدخل اسم الموظف للدخول" required>
                        </div>
                        
                        <!-- كلمة المرور -->
                        <div class="col-md-6 mb-3">
                            <label class="font-weight-bold text-secondary mb-2">كلمة المرور للدخول *</label>
                            <input type="password" name="password" class="form-control rounded-0" placeholder="أدخل كلمة مرور قوية" required>
                        </div>

                        <!-- رقم الهاتف -->
                        <div class="col-md-6 mb-3">
                            <label class="font-weight-bold text-secondary mb-2">رقم الهاتف</label>
                            <input type="text" name="phone" class="form-control rounded-0" placeholder="أدخل رقم الهاتف">
                        </div>

                        <!-- رمز الأمان المفقود -->
                        <div class="col-md-6 mb-3">
                            <label class="font-weight-bold text-secondary mb-2">كود استعادة كلمة المرور</label>
                            <input type="text" name="code" class="form-control rounded-0" placeholder="رقم أو كلمة سرية للاستعادة">
                        </div>

                        <!-- الدور والصلاحية -->
                        <div class="col-md-12 mb-4">
                            <label class="font-weight-bold text-secondary mb-2">الدور الوظيفي والصلاحيات *</label>
                            <select name="position" class="form-control rounded-0" required>
                                <option value="">-- اختر الدور الصلاحية للموظف --</option>
                                <option value="admin">مدير النظام (صلاحية كاملة لكل شيء)</option>
                                <option value="cashier">كاشير / بائع (صلاحية المبيعات والعملاء فقط)</option>
                                <option value="inventory">أمين مستودع (صلاحية المخزن والموردين والمشتريات فقط)</option>
                            </select>
                        </div>
                    </div>

                    <hr class="my-4">

                    <div class="text-left">
                        <button type="submit" name="btn_save" class="btn-flat btn-flat-primary px-5">
                            <?php echo get_icon('check', 'ml-1'); ?> حفظ الموظف وتثبيته
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php
require_once($dir_prefix . 'includes/footer.php');
?>
