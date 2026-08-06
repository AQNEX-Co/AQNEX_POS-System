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
    'admin'     => ['label' => 'مدير النظام',      'class' => 'badge-formal is-danger'],
    'cashier'   => ['label' => 'كاشير / بائع',     'class' => 'badge-formal is-success'],
    'inventory' => ['label' => 'أمين المستودع',    'class' => 'badge-formal is-info'],
];
?>
<title>إدارة المستخدمين والصلاحيات - <?php echo htmlspecialchars($global_settings['store_name'] ?? 'النظام'); ?></title>
<link rel="stylesheet" href="<?php echo $prefix; ?>assets/css/settings.css">

<div class="settings-shell">
    <div class="row mb-3 no-print align-items-center">
        <div class="col-md-7">
            <span class="eyebrow">الموارد البشرية والأمان</span>
            <h3 class="mb-1">
                <span class="icon-chip"><i class="bi bi-shield-lock"></i></span>
                إدارة حسابات الموظفين والصلاحيات
            </h3>
            <p class="text-muted small mb-0">إضافة وتعديل وحذف حسابات الموظفين، وضبط صلاحيات الوصول لكل قسم بالنظام.</p>
        </div>
        <div class="col-md-5 text-left">
            <a href="create.php" class="btn-formal-success text-decoration-none">
                <i class="bi bi-person-plus ml-1"></i> إضافة موظف جديد
            </a>
            <a href="../home.php" class="btn-formal-secondary text-decoration-none mr-1">
                <i class="bi bi-arrow-right-short ml-1"></i> الرئيسية
            </a>
        </div>
    </div>

    <div class="row justify-content-center no-print">
        <div class="col-lg-12">
            
            <?php if (!empty($success)): ?>
                <div class="alert-formal is-success mb-4"><i class="bi bi-check-circle ml-1"></i> <?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>
            <?php if (!empty($error)): ?>
                <div class="alert-formal is-error mb-4"><i class="bi bi-exclamation-triangle ml-1"></i> <?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <div class="formal-card mb-4">
                <div class="formal-card-head d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-people ml-1"></i> قائمة الموظفين والمستخدمين (<?php echo count($all_users); ?> حساب)</span>
                    <a href="create.php" class="btn btn-sm btn-light py-0 font-weight-bold" style="font-size:0.75rem;">+ موظف جديد</a>
                </div>
                <div class="formal-card-body p-0">
                    <div class="table-responsive">
                        <table class="table-formal">
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
                                    <td class="text-secondary font-weight-bold">#<?php echo $u['userid']; ?></td>
                                    <td class="font-weight-bold text-dark">
                                        <div style="display:inline-block;vertical-align:middle;">
                                            <div class="font-weight-bold">
                                                <i class="bi bi-person-circle ml-1 text-primary"></i>
                                                <?php echo htmlspecialchars(!empty($u['full_name']) ? $u['full_name'] : $u['username']); ?>
                                                <?php if ($u['userid'] == 1): ?>
                                                    <span class="badge-formal is-info mr-1" style="font-size:0.65rem;">مؤسس</span>
                                                <?php endif; ?>
                                            </div>
                                            <small class="text-muted"><?php echo htmlspecialchars($u['username']); ?></small>
                                        </div>
                                    </td>
                                    <td class="text-secondary"><?php echo !empty($u['phone']) ? htmlspecialchars($u['phone']) : '<span class="text-muted">—</span>'; ?></td>
                                    <td>
                                        <?php 
                                        $role = $u['position'] ?? 'admin';
                                        $rl = $role_labels[$role] ?? ['label' => $role, 'class' => 'badge-formal is-info'];
                                        ?>
                                        <span class="<?php echo $rl['class']; ?>">
                                            <?php echo $rl['label']; ?>
                                        </span>
                                    </td>
                                    <td class="text-muted small">
                                        <?php
                                        $cp = trim($u['custom_permissions'] ?? '');
                                        if ($role === 'admin') {
                                            echo '<span class="text-danger font-weight-bold"><i class="bi bi-shield-check ml-1"></i> صلاحية كاملة</span>';
                                        } elseif (!empty($cp)) {
                                            $cp_arr = explode(',', $cp);
                                            echo '<span class="badge-formal is-info">' . count($cp_arr) . ' صلاحية مخصصة</span>';
                                        } else {
                                            echo '<span class="text-muted">حسب الدور الافتراضي</span>';
                                        }
                                        ?>
                                    </td>
                                    <td class="no-print">
                                        <a href="edit.php?id=<?php echo $u['userid']; ?>" class="btn btn-sm btn-outline-primary py-1 px-2 font-weight-bold ml-1" style="font-size:0.75rem;">
                                            <i class="bi bi-pencil ml-1"></i> تعديل وصلاحيات
                                        </a>
                                        <?php if ($u['userid'] != 1): ?>
                                        <a href="index.php?delete=<?php echo $u['userid']; ?>" class="btn btn-sm btn-outline-danger py-1 px-2 font-weight-bold" onclick="return confirm('هل أنت متأكد من حذف حساب <?php echo htmlspecialchars($u['username']); ?>؟')" style="font-size:0.75rem;">
                                            <i class="bi bi-trash ml-1"></i> حذف
                                        </a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($all_users)): ?>
                                <tr>
                                    <td colspan="6" class="text-center text-muted py-4">
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
            <div class="alert-formal is-info mb-4">
                <i class="bi bi-info-circle ml-1"></i>
                <strong>ملاحظة:</strong> لإضافة صلاحيات مخصصة لموظف معين، اضغط على زر <strong>"تعديل وصلاحيات"</strong> بجانب اسمه، حيث يمكنك تخصيص وصوله لكل قسم منفصل.
            </div>
        </div>
    </div>
</div>

<?php require_once($dir_prefix . 'includes/footer.php'); ?>
