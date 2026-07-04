<?php
$dir_prefix = '../';
$module = 'users';
require_once($dir_prefix . 'includes/header.php');
check_permission(['admin']);

$error = '';
$success = '';

// قائمة الموديولات
$system_modules = [
    'sales'      => ['label' => 'إدارة المبيعات',       'icon' => 'fa-shopping-cart'],
    'purchases'  => ['label' => 'إدارة المشتريات',      'icon' => 'fa-truck'],
    'products'   => ['label' => 'جرد المخزون والتسويات','icon' => 'fa-cubes'],
    'categories' => ['label' => 'أصناف المنتجات',       'icon' => 'fa-tags'],
    'box'        => ['label' => 'الصندوق المالي',       'icon' => 'fa-money'],
    'receipts'   => ['label' => 'سندات المقبوضات',      'icon' => 'fa-arrow-circle-down'],
    'expenses'   => ['label' => 'سندات الصرف',          'icon' => 'fa-arrow-circle-up'],
    'customers'  => ['label' => 'حسابات العملاء',       'icon' => 'fa-users'],
    'suppliers'  => ['label' => 'حسابات الموردين',      'icon' => 'fa-industry'],
    'reports'    => ['label' => 'التقارير والأرباح',    'icon' => 'fa-bar-chart'],
    'users'      => ['label' => 'إدارة المستخدمين',     'icon' => 'fa-user-shield'],
];

if (isset($_POST['btn_save'])) {
    $username = $conn->real_escape_string(trim($_POST['username']));
    $password = $conn->real_escape_string(trim($_POST['password']));
    $phone = $conn->real_escape_string(trim($_POST['phone']));
    $code = $conn->real_escape_string(trim($_POST['code']));
    $position = $conn->real_escape_string(trim($_POST['position']));
    $custom_perms = isset($_POST['custom_perms']) && is_array($_POST['custom_perms']) ? implode(',', $_POST['custom_perms']) : '';
    $custom_perms_esc = $conn->real_escape_string($custom_perms);

    if (empty($username) || empty($password) || empty($position)) {
        $error = 'الرجاء تعبئة الحقول الإجبارية (الاسم، كلمة المرور، والدور الوظيفي).';
    } else {
        // التحقق من تكرار اسم المستخدم
        $sql_chk = "SELECT userid FROM users WHERE username = '$username'";
        $res_chk = $conn->query($sql_chk);
        if ($res_chk && $res_chk->num_rows > 0) {
            $error = 'اسم المستخدم هذا مسجل بالفعل لآخر، الرجاء اختيار اسم آخر.';
        } else {
            $sql_ins = "INSERT INTO users (username, password, phone, code, position, custom_permissions) 
                        VALUES ('$username', '$password', '$phone', '$code', '$position', '$custom_perms_esc')";
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
                            <select name="position" id="user_position" class="form-control rounded-0" onchange="togglePermissions()" required>
                                <option value="">-- اختر الدور الصلاحية للموظف --</option>
                                <option value="admin">مدير النظام (صلاحية كاملة لكل شيء)</option>
                                <option value="cashier">كاشير / بائع (صلاحية المبيعات والعملاء فقط)</option>
                                <option value="inventory">أمين مستودع (صلاحية المخزن والموردين والمشتريات فقط)</option>
                            </select>
                        </div>

                        <!-- الصلاحيات المخصصة -->
                        <div class="col-md-12 mb-4" id="custom_perms_section" style="display: none;">
                            <div class="card border">
                                <div class="card-header bg-light py-2">
                                    <h6 class="mb-0 text-secondary font-weight-bold"><i class="fa fa-shield ml-2"></i>منح صلاحيات مخصصة (اختياري)</h6>
                                </div>
                                <div class="card-body p-0">
                                    <div class="p-3 bg-light border-bottom">
                                        <small class="text-muted">
                                            <i class="fa fa-info-circle ml-1"></i>
                                            حدد الصلاحيات الفردية أدناه إذا كنت تريد تخصيص وصول هذا الموظف. في حال عدم تحديد أي شيء، سيتم تطبيق الصلاحيات الافتراضية للدور الوظيفي.
                                        </small>
                                    </div>
                                    <div class="row no-gutters">
                                        <?php foreach ($system_modules as $key => $mod): ?>
                                        <div class="col-md-6 border-bottom border-right p-2" style="border-bottom: 1px solid #f1f5f9; border-left: 1px solid #f1f5f9;">
                                            <div class="form-check ml-3">
                                                <input type="checkbox" class="form-check-input"
                                                    name="custom_perms[]"
                                                    value="<?php echo $key; ?>"
                                                    id="ep_<?php echo $key; ?>">
                                                <label class="form-check-label font-weight-bold text-secondary mr-4" style="font-size: 0.85rem;" for="ep_<?php echo $key; ?>">
                                                    <i class="fa <?php echo $mod['icon']; ?> ml-1 text-primary"></i>
                                                    <?php echo $mod['label']; ?>
                                                </label>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <div class="p-3 d-flex gap-2">
                                        <button type="button" class="btn btn-secondary btn-sm rounded-0"
                                            onclick="document.querySelectorAll('[name=\'custom_perms[]\']').forEach(c=>c.checked=false)">
                                            <i class="fa fa-times ml-1"></i> مسح كل الصلاحيات
                                        </button>
                                        <button type="button" class="btn btn-success btn-sm rounded-0 mr-2"
                                            onclick="document.querySelectorAll('[name=\'custom_perms[]\']').forEach(c=>c.checked=true)">
                                            <i class="fa fa-check-square ml-1"></i> تحديد الكل
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <script>
                    function togglePermissions() {
                        var pos = document.getElementById("user_position").value;
                        var section = document.getElementById("custom_perms_section");
                        if (pos === "admin" || pos === "") {
                            section.style.display = "none";
                        } else {
                            section.style.display = "block";
                        }
                    }
                    </script>

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
