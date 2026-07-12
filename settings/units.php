<?php
$dir_prefix = '../';
$module = 'settings';
require_once($dir_prefix . 'includes/header.php');
check_permission(['admin']);

$error = '';
$success = '';

// ==========================================
// معالجة إضافة وتعديل الوحدات العامة
// ==========================================
if (isset($_POST['btn_save_unit'])) {
    $unit_id = isset($_POST['unit_id']) ? intval($_POST['unit_id']) : 0;
    $unit_name = $conn->real_escape_string(trim($_POST['unit_name']));

    if (empty($unit_name)) {
        $error = 'اسم الوحدة حقل إجباري.';
    } else {
        if ($unit_id > 0) {
            // تعديل اسم الوحدة
            $stmt = $conn->prepare("UPDATE `units` SET `name` = ? WHERE `id` = ?");
            $stmt->bind_param("si", $unit_name, $unit_id);
            if ($stmt->execute()) {
                $success = 'تم تحديث وحدة القياس بنجاح.';
            } else {
                $error = 'فشل تحديث وحدة القياس: ' . $conn->error;
            }
            $stmt->close();
        } else {
            // إضافة وحدة جديدة
            // التحقق من عدم التكرار
            $chk = $conn->query("SELECT id FROM `units` WHERE `name` = '$unit_name' AND `d_s` = '0' LIMIT 1");
            if ($chk && $chk->num_rows > 0) {
                $error = 'اسم الوحدة مضاف مسبقاً.';
            } else {
                $stmt = $conn->prepare("INSERT INTO `units` (`name`, `d_s`) VALUES (?, '0')");
                $stmt->bind_param("s", $unit_name);
                if ($stmt->execute()) {
                    $success = 'تمت إضافة وحدة القياس بنجاح.';
                } else {
                    $error = 'فشل إضافة وحدة القياس: ' . $conn->error;
                }
                $stmt->close();
            }
        }
    }
}

// ==========================================
// معالجة حذف وحدة قياس (Soft Delete)
// ==========================================
if (isset($_GET['del_unit']) && is_numeric($_GET['del_unit'])) {
    $unit_id = intval($_GET['del_unit']);
    if ($conn->query("UPDATE `units` SET `d_s` = '1' WHERE `id` = $unit_id")) {
        $success = 'تم حذف وحدة القياس بنجاح.';
    } else {
        $error = 'فشل حذف وحدة القياس: ' . $conn->error;
    }
}

// جلب قائمة الوحدات العامة
$units_list = [];
$res_u = $conn->query("SELECT * FROM `units` WHERE `d_s` = '0' ORDER BY `id` DESC");
if ($res_u) {
    while ($row = $res_u->fetch_assoc()) {
        $units_list[] = $row;
    }
}

// جلب بيانات وحدة للتعديل
$edit_unit = null;
if (isset($_GET['edit_unit']) && is_numeric($_GET['edit_unit'])) {
    $edit_id = intval($_GET['edit_unit']);
    $res_edit = $conn->query("SELECT * FROM `units` WHERE `id` = $edit_id AND `d_s` = '0' LIMIT 1");
    if ($res_edit && $res_edit->num_rows > 0) {
        $edit_unit = $res_edit->fetch_assoc();
    }
}
?>

<title>تهيئة وإعداد الوحدات العامة - تكنولوجيا فون</title>

<?php
$active_tab = 'units';
require_once 'settings_nav.php';
?>

<div class="row no-print mb-4">
    <div class="col-md-6 text-right">
        <h3 class="text-secondary font-weight-bold">
            <i class="fa fa-tags ml-2 text-primary"></i> تهيئة وحدات قياس الأصناف
        </h3>
        <p class="text-muted small mb-0">قم بإضافة وتعديل أسماء الوحدات العامة للنظام (مثل: حبة، كرتون، شوال، درزن) ليتم استخدامها لاحقاً داخل صفحة تفاصيل الأصناف.</p>
    </div>
    <div class="col-md-6 text-left">
        <a href="../products/index.php" class="btn-flat btn-flat-secondary btn-sm text-decoration-none">
            <i class="fa fa-cubes ml-1"></i> إدارة البضائع والأصناف
        </a>
    </div>
</div>

<?php if (!empty($success)): ?>
    <div class="alert alert-success rounded-0 mb-4 text-right"><?php echo htmlspecialchars($success); ?></div>
<?php endif; ?>
<?php if (!empty($error)): ?>
    <div class="alert alert-danger rounded-0 mb-4 text-right"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<div class="row text-right" dir="rtl">
    <!-- الجانب الأيمن: عرض الوحدات المضافة -->
    <div class="col-lg-8 mb-4">
        <div class="card-flat">
            <div class="card-header bg-light">
                <h5 class="mb-0 text-dark font-weight-bold"><i class="fa fa-list ml-2 text-primary"></i> قائمة الوحدات العامة المسجلة بالنظام</h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table-flat mb-0">
                        <thead>
                            <tr>
                                <th style="width: 15%;" class="text-center">الرقم</th>
                                <th>اسم وحدة القياس</th>
                                <th class="no-print text-center" style="width: 25%;">إجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($units_list)): ?>
                                <tr>
                                    <td colspan="3" class="text-center text-muted p-4">لا توجد وحدات قياس مهيأة بالنظام حالياً. قم بإضافة أول وحدة من الجانب الأيسر.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($units_list as $u): ?>
                                    <tr>
                                        <td class="text-center text-secondary font-weight-bold">#<?php echo $u['id']; ?></td>
                                        <td class="font-weight-bold text-primary" style="font-size: 1.1rem;"><?php echo htmlspecialchars($u['name']); ?></td>
                                        <td class="no-print text-center">
                                            <a href="units.php?edit_unit=<?php echo $u['id']; ?>" class="btn-flat btn-flat-primary btn-sm py-1 px-3 ml-2">
                                                <i class="fa fa-edit ml-1"></i> تعديل
                                            </a>
                                            <a href="units.php?del_unit=<?php echo $u['id']; ?>" onclick="return confirm('هل أنت متأكد من حذف هذه الوحدة؟')" class="btn-flat btn-flat-danger btn-sm py-1 px-3">
                                                <i class="fa fa-trash ml-1"></i> حذف
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- الجانب الأيسر: إضافة/تعديل الوحدة -->
    <div class="col-lg-4 mb-4">
        <div class="card-flat border border-primary">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0 font-weight-bold">
                    <i class="fa <?php echo $edit_unit ? 'fa-edit' : 'fa-plus'; ?> ml-2"></i>
                    <?php echo $edit_unit ? 'تعديل اسم الوحدة' : 'إضافة وحدة قياس جديدة'; ?>
                </h5>
            </div>
            <div class="card-body">
                <form method="POST" action="units.php">
                    <?php if ($edit_unit): ?>
                        <input type="hidden" name="unit_id" value="<?php echo $edit_unit['id']; ?>">
                    <?php endif; ?>

                    <div class="form-group mb-4">
                        <label class="font-weight-bold text-secondary mb-1">اسم وحدة القياس *</label>
                        <input type="text" name="unit_name" class="form-control rounded-0 font-weight-bold text-right" 
                               value="<?php echo $edit_unit ? htmlspecialchars($edit_unit['name']) : ''; ?>" 
                               placeholder="مثال: كرتون، حبة، درزن، شوال..." required>
                    </div>

                    <button type="submit" name="btn_save_unit" class="btn-flat btn-flat-primary btn-block py-2 font-weight-bold">
                        <i class="fa <?php echo $edit_unit ? 'fa-save' : 'fa-plus'; ?> ml-1"></i>
                        <?php echo $edit_unit ? 'حفظ التعديلات' : 'إضافة الوحدة العامة'; ?>
                    </button>

                    <?php if ($edit_unit): ?>
                        <a href="units.php" class="btn-flat btn-flat-secondary btn-block text-center py-2 mt-2 font-weight-bold text-decoration-none">
                            إلغاء التعديل
                        </a>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once($dir_prefix . 'includes/footer.php'); ?>
