<?php
$dir_prefix = '../';
$module = 'users';
require_once($dir_prefix . 'includes/header.php');
check_permission(['admin']);

$success = '';
$error   = '';

// حذف مستخدم
if (isset($_GET['delete']) && intval($_GET['delete']) > 1) {
    $del_id = intval($_GET['delete']);
    if ($conn->query("DELETE FROM users WHERE userid = $del_id")) {
        $success = 'تم حذف حساب المستخدم بنجاح.';
    } else {
        $error = 'فشل الحذف: ' . $conn->error;
    }
}

// جلب قائمة المستخدمين
$res_users = $conn->query("SELECT * FROM users ORDER BY userid ASC");
$all_users = [];
if ($res_users) {
    while ($row = $res_users->fetch_assoc()) {
        $all_users[] = $row;
    }
}

$role_labels = [
    'admin'     => ['label' => 'مدير النظام',      'class' => 'badge-danger'],
    'cashier'   => ['label' => 'كاشير / بائع',     'class' => 'badge-success'],
    'inventory' => ['label' => 'أمين المستودع',    'class' => 'badge-info'],
];
?>
<title>إدارة المستخدمين والصلاحيات - <?php echo htmlspecialchars($global_settings['store_name'] ?? 'النظام'); ?></title>

<div class="row mb-4 no-print">
    <div class="col-md-7">
        <h3 class="text-secondary font-weight-bold">
            <?php echo get_icon('users', 'ml-2 text-primary'); ?> إدارة حسابات الموظفين والصلاحيات
        </h3>
        <p class="text-muted small mb-0">إضافة وتعديل وحذف حسابات الموظفين وضبط صلاحياتهم بالنظام.</p>
    </div>
    <div class="col-md-5 text-left">
        <a href="create.php" class="btn btn-success btn-sm rounded-0 ml-2 text-decoration-none">
            <i class="fa fa-plus ml-1"></i> إضافة موظف جديد
        </a>
        <a href="../home.php" class="btn btn-secondary btn-sm rounded-0 text-decoration-none">
            <i class="fa fa-arrow-left ml-1"></i> عودة للرئيسية
        </a>
    </div>
</div>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger rounded-0 mb-4"><?php echo $error; ?></div>
<?php endif; ?>
<?php if (!empty($success)): ?>
    <div class="alert alert-success rounded-0 mb-4"><?php echo get_icon('check', 'ml-1'); ?> <?php echo $success; ?></div>
<?php endif; ?>

<div class="card-flat">
    <div class="card-header bg-light">
        <h5 class="mb-0"><?php echo get_icon('users', 'ml-2'); ?> قائمة الموظفين والمستخدمين (<?php echo count($all_users); ?> حساب)</h5>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table-flat mb-0 w-100">
                <thead>
                    <tr>
                        <th style="width:5%">#</th>
                        <th>اسم الموظف</th>
                        <th>رقم الهاتف</th>
                        <th>الدور الوظيفي</th>
                        <th>الصلاحيات</th>
                        <th style="width:20%" class="no-print">الإجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($all_users as $u): ?>
                    <tr>
                        <td class="text-secondary font-weight-bold"><?php echo $u['userid']; ?></td>
                        <td class="font-weight-bold text-dark">
                            <i class="fa fa-user-circle ml-1 text-primary" style="font-size:1.1rem"></i>
                            <div style="display:inline-block;vertical-align:middle;">
                                <div class="font-weight-bold">
                                    <?php echo htmlspecialchars(!empty($u['full_name']) ? $u['full_name'] : $u['username']); ?>
                                    <?php if ($u['userid'] == 1): ?>
                                        <span class="badge badge-dark px-1 py-0 mr-1" style="font-size:0.65rem;">مؤسس</span>
                                    <?php endif; ?>
                                </div>
                                <small class="text-muted"><?php echo htmlspecialchars($u['username']); ?></small>
                            </div>
                        </td>
                        <td class="text-secondary"><?php echo !empty($u['phone']) ? htmlspecialchars($u['phone']) : '<span class="text-muted">—</span>'; ?></td>
                        <td>
                            <?php 
                            $role = $u['position'] ?? 'admin';
                            $rl = $role_labels[$role] ?? ['label' => $role, 'class' => 'badge-secondary'];
                            ?>
                            <span class="badge <?php echo $rl['class']; ?> px-2 py-1 font-weight-normal">
                                <?php echo $rl['label']; ?>
                            </span>
                        </td>
                        <td class="text-muted small">
                            <?php
                            $cp = trim($u['custom_permissions'] ?? '');
                            if ($role === 'admin') {
                                echo '<span class="text-danger font-weight-bold">صلاحية كاملة</span>';
                            } elseif (!empty($cp)) {
                                $cp_arr = explode(',', $cp);
                                echo '<span class="badge badge-light border px-1 py-0" style="font-size:0.7rem;">' . count($cp_arr) . ' صلاحية مخصصة</span>';
                            } else {
                                echo '<span class="text-muted">حسب الدور الافتراضي</span>';
                            }
                            ?>
                        </td>
                        <td class="no-print">
                            <a href="edit.php?id=<?php echo $u['userid']; ?>" 
                               class="btn btn-primary btn-sm rounded-0 py-1 px-2 text-decoration-none ml-1">
                                <?php echo get_icon('edit', 'ml-1'); ?> تعديل وصلاحيات
                            </a>
                            <?php if ($u['userid'] != 1): ?>
                            <a href="index.php?delete=<?php echo $u['userid']; ?>"
                               class="btn btn-danger btn-sm rounded-0 py-1 px-2 text-decoration-none"
                               onclick="return confirm('هل أنت متأكد من حذف حساب <?php echo htmlspecialchars($u['username']); ?>؟ لا يمكن التراجع.')">
                                <?php echo get_icon('trash', 'ml-1'); ?> حذف
                            </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($all_users)): ?>
                    <tr>
                        <td colspan="6" class="text-center text-muted py-4">
                            <i class="fa fa-users fa-2x mb-2 d-block"></i>
                            لا يوجد مستخدمون مسجلون بعد.
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ملاحظة توضيحية -->
<div class="alert rounded-0 mt-4" style="background:#f0f9ff; border:1px solid #bae6fd; color:#0369a1;">
    <i class="fa fa-info-circle ml-2"></i>
    <strong>ملاحظة:</strong> لإضافة صلاحيات مخصصة لموظف معين، اضغط على زر <strong>"تعديل وصلاحيات"</strong> بجانب اسمه.
    يمكنك تخصيص وصوله لكل قسم بشكل منفصل بدلاً من الاعتماد على الدور الافتراضي.
</div>

<?php
require_once($dir_prefix . 'includes/footer.php');
?>
