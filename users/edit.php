<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

$dir_prefix = '../';
$module = 'users';
require_once($dir_prefix . 'includes/header.php');
check_permission(['admin']);

$settings = $global_settings;

$error = '';
$success = '';

if (!isset($_GET['id']) || empty($_GET['id'])) {
    echo "<div class='alert alert-danger rounded-0 m-4'>خطأ: لم يتم تحديد معرف المستخدم.</div>";
    require_once($dir_prefix . 'includes/footer.php');
    exit;
}

$user_id = intval($_GET['id']);

// جلب تفاصيل المستخدم الحالي
$sql_get = "SELECT * FROM users WHERE userid = $user_id";
$res = $conn->query($sql_get);
$user = ($res) ? $res->fetch_assoc() : null;

if (!$user) {
    echo "<div class='alert alert-danger rounded-0 m-4'>خطأ: الموظف غير موجود بالنظام. (ID: $user_id)</div>";
    require_once($dir_prefix . 'includes/footer.php');
    exit;
}

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

if (isset($_POST['btn_update'])) {
    $username = $conn->real_escape_string(trim($_POST['username']));
    $full_name= $conn->real_escape_string(trim($_POST['full_name'] ?? ''));
    $new_pass = trim($_POST['password']);
    $phone    = $conn->real_escape_string(trim($_POST['phone']));
    $code     = $conn->real_escape_string(trim($_POST['code']));
    $position = ($user_id === 1) ? 'admin' : $conn->real_escape_string(trim($_POST['position']));
    $custom_perms = isset($_POST['custom_perms']) && is_array($_POST['custom_perms']) ? implode(',', $_POST['custom_perms']) : '';

    if (empty($username)) {
        $error = 'اسم المستخدم حقل إجباري.';
    } else {
        $res_chk = $conn->query("SELECT userid FROM users WHERE username = '$username' AND userid != $user_id");
        if ($res_chk && $res_chk->num_rows > 0) {
            $error = 'اسم المستخدم هذا مستخدم من قبل موظف آخر.';
        } else {
            $pass_part = '';
            if (!empty($new_pass)) {
                $pass_esc = $conn->real_escape_string($new_pass);
                $pass_part = ", password = '$pass_esc'";
            }
            $sql_up = "UPDATE users SET 
                       username  = '$username',
                       full_name = '$full_name'
                       $pass_part,
                       phone    = '$phone',
                       code     = '$code',
                       position = '$position',
                       custom_permissions = '$custom_perms'
                       WHERE userid = $user_id";
            if ($conn->query($sql_up)) {
                $success = 'تم تحديث بيانات الحساب والصلاحيات بنجاح!';
                $res = $conn->query($sql_get);
                $user = $res ? $res->fetch_assoc() : $user;
            } else {
                $error = 'حدث خطأ أثناء التحديث: ' . $conn->error;
            }
        }
    }
}

// حفظ الصلاحيات فقط
 if (isset($_POST['btn_perms'])) {
    $custom_perms = isset($_POST['custom_perms']) && is_array($_POST['custom_perms']) ? implode(',', $_POST['custom_perms']) : '';
    $escaped = $conn->real_escape_string($custom_perms);
    if ($conn->query("UPDATE users SET custom_permissions = '$escaped' WHERE userid = $user_id")) {
        $success = 'تم حفظ الصلاحيات بنجاح وتطبيقها على النظام!';
        $res = $conn->query($sql_get);
        $user = $res ? $res->fetch_assoc() : $user;
    } else {
        $error = 'حدث خطأ أثناء حفظ الصلاحيات: ' . $conn->error;
    }
}

// بناء قائمة الصلاحيات الفردية الحالية
$custom_active = [];
if (!empty(trim($user['custom_permissions'] ?? ''))) {
    $custom_active = array_map('trim', explode(',', $user['custom_permissions']));
}
?>
<title>تعديل حساب الموظف - <?php echo htmlspecialchars($settings['store_name'] ?? 'النظام'); ?></title>

<style>
.perm-check-row { padding: 6px 10px; border-bottom: 1px solid #f1f5f9; }
.perm-check-row:last-child { border-bottom: none; }
</style>

<div class="row mb-4">
    <div class="col-md-6">
        <h3 class="text-secondary font-weight-bold">
            <?php echo get_icon('edit', 'ml-2 text-primary'); ?>
            تعديل حساب: <span class="text-primary"><?php echo htmlspecialchars($user['username']); ?></span>
        </h3>
    </div>
    <div class="col-md-6 text-left">
        <a href="index.php" class="btn-flat btn-flat-secondary btn-sm text-decoration-none">
            <?php echo get_icon('logout', 'ml-1'); ?> عودة للمستخدمين
        </a>
    </div>
</div>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger rounded-0 mb-4"><?php echo $error; ?></div>
<?php endif; ?>
<?php if (!empty($success)): ?>
    <div class="alert alert-success rounded-0 mb-4"><?php echo get_icon('check', 'ml-1'); ?> <?php echo $success; ?></div>
<?php endif; ?>

<form method="POST">
<div class="row">
    <!-- بيانات الحساب -->
    <div class="col-md-5 mb-4">
        <div class="card-flat">
            <div class="card-header">
                <h5><i class="fa fa-user ml-2"></i> بيانات الحساب الوظيفي</h5>
            </div>
            <div class="card-body">
                <div class="form-group mb-3">
                    <label class="font-weight-bold text-secondary mb-2">اسم المستخدم / لوجين *</label>
                    <input type="text" name="username" class="form-control rounded-0"
                           value="<?php echo htmlspecialchars($user['username']); ?>" required>
                    <small class="text-muted">يُستخدم للدخول فقط</small>
                </div>

                <div class="form-group mb-3">
                    <label class="font-weight-bold text-secondary mb-2">الاسم الكامل للموظف</label>
                    <input type="text" name="full_name" class="form-control rounded-0"
                           value="<?php echo htmlspecialchars($user['full_name'] ?? $user['username']); ?>"
                           placeholder="مثال: أحمد محمد علي">
                    <small class="text-muted">يظهر في الهيدر بعد تسجيل الدخول</small>
                </div>

                <div class="form-group mb-3">
                    <label class="font-weight-bold text-secondary mb-2">كلمة المرور الجديدة</label>
                    <input type="text" name="password" class="form-control rounded-0"
                           placeholder="اتركه فارغاً للإبقاء على كلمة المرور الحالية">
                    <small class="text-muted">كلمة المرور الحالية محفوظة. أدخل كلمة مرور جديدة فقط إذا أردت تغييرها.</small>
                </div>

                <div class="form-group mb-3">
                    <label class="font-weight-bold text-secondary mb-2">رقم الهاتف</label>
                    <input type="text" name="phone" class="form-control rounded-0"
                           value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>">
                </div>

                <div class="form-group mb-3">
                    <label class="font-weight-bold text-secondary mb-2">كود استعادة كلمة المرور</label>
                    <input type="text" name="code" class="form-control rounded-0"
                           value="<?php echo htmlspecialchars($user['code'] ?? ''); ?>">
                </div>

                <div class="form-group mb-4">
                    <label class="font-weight-bold text-secondary mb-2">الدور الوظيفي *</label>
                    <?php if ($user_id === 1): ?>
                        <input type="text" class="form-control rounded-0 bg-light" readonly
                               value="مدير النظام (حساب التأسيس — لا يمكن تغيير دوره)">
                        <input type="hidden" name="position" value="admin">
                    <?php else: ?>
                        <select name="position" class="form-control rounded-0" required>
                            <option value="admin"     <?php echo ($user['position'] === 'admin')     ? 'selected' : ''; ?>>مدير النظام (صلاحية كاملة)</option>
                            <option value="cashier"   <?php echo ($user['position'] === 'cashier')   ? 'selected' : ''; ?>>كاشير / بائع</option>
                            <option value="inventory" <?php echo ($user['position'] === 'inventory') ? 'selected' : ''; ?>>أمين مستودع</option>
                        </select>
                    <?php endif; ?>
                </div>

                <button type="submit" name="btn_update" class="btn-flat btn-flat-primary btn-block py-2">
                    <?php echo get_icon('check', 'ml-1'); ?> حفظ بيانات الحساب والصلاحيات
                </button>
            </div>
        </div>
    </div>

    <!-- الصلاحيات الفردية -->
    <div class="col-md-7 mb-4">
        <div class="card-flat">
            <div class="card-header">
                <h5><i class="fa fa-shield ml-2"></i> الصلاحيات الفردية المخصصة</h5>
            </div>
            <div class="card-body p-0">
                <?php if ($user_id === 1 || ($user['position'] ?? '') === 'admin'): ?>
                    <div class="p-4 text-center text-muted">
                        <i class="fa fa-lock fa-2x mb-2 d-block"></i>
                        حساب المدير له صلاحية كاملة ولا يحتاج لصلاحيات مخصصة.
                    </div>
                <?php else: ?>
                    <div class="p-3 bg-light border-bottom">
                        <small class="text-muted">
                            <i class="fa fa-info-circle ml-1"></i>
                            إذا حددت صلاحيات هنا، ستُطبَّق <strong>بدلاً من</strong> صلاحيات دور الموظف العام.
                            اتركها كلها فارغة ليعمل الموظف بصلاحيات دوره المعينة.
                        </small>
                    </div>
                    <?php foreach ($system_modules as $key => $mod): ?>
                    <div class="perm-check-row">
                        <div class="custom-control custom-checkbox">
                            <input type="checkbox" class="custom-control-input"
                                name="custom_perms[]"
                                value="<?php echo $key; ?>"
                                id="ep_<?php echo $key; ?>"
                                <?php echo in_array($key, $custom_active) ? 'checked' : ''; ?>>
                            <label class="custom-control-label" for="ep_<?php echo $key; ?>">
                                <i class="fa <?php echo $mod['icon']; ?> ml-1 text-muted"></i>
                                <?php echo $mod['label']; ?>
                            </label>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <div class="p-3 border-top">
                        <button type="submit" name="btn_perms" class="btn-flat btn-flat-primary btn-block py-2">
                            <?php echo get_icon('check', 'ml-1'); ?> حفظ الصلاحيات وتطبيقها
                        </button>
                        <div class="mt-2 d-flex gap-2">
                        <button type="button" class="btn-flat btn-flat-secondary btn-sm"
                            onclick="document.querySelectorAll('[name=\"custom_perms[]\"]').forEach(c=>c.checked=false)">
                            <i class="fa fa-times ml-1"></i> مسح كل الصلاحيات
                        </button>
                        <button type="button" class="btn-flat btn-flat-success btn-sm mr-2"
                            onclick="document.querySelectorAll('[name=\"custom_perms[]\"]').forEach(c=>c.checked=true)">
                            <i class="fa fa-check-square ml-1"></i> تحديد الكل
                        </button>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
</form>

<?php
require_once($dir_prefix . 'includes/footer.php');
?>
