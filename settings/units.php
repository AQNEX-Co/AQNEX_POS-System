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

<title>تهيئة وحدات القياس - AQNEX POS</title>
<link rel="stylesheet" href="<?php echo $prefix; ?>assets/css/settings.css">

<div class="settings-shell">
    <div class="row mb-3 no-print align-items-center">
        <div class="col-md-7">
            <span class="eyebrow">إعدادات النظام وإدارة الموارد</span>
            <h3 class="mb-1">
                <span class="icon-chip"><i class="bi bi-tags"></i></span>
                تهيئة وإدارة وحدات القياس
            </h3>
            <p class="text-muted small mb-0">إضافة وتعديل وحدات القياس المعتمدة (مثل: حبة، كرتون، درزن، كيلو) واستخدامها في إدارة المخزون.</p>
        </div>
        <div class="col-md-5 text-left">
            <a href="../products/index.php" class="btn-formal-secondary text-decoration-none">
                <i class="bi bi-boxes ml-1"></i> إدارة البضائع والأصناف
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

            <!-- Shared Sub-Navigation Menu -->
            <?php 
            $active_tab = 'units'; 
            require_once 'settings_nav.php'; 
            ?>

            <div class="tab-content tab-content-custom mb-5">
                <div class="tab-pane-inner">
                    <h5 class="section-heading">الوحدات العامة المعتمدة في الأصناف</h5>
                    
                    <div class="row">
                        <!-- Left Panel: Table of Units -->
                        <div class="col-lg-8 mb-4">
                            <div class="formal-card">
                                <div class="formal-card-head">
                                    <i class="bi bi-list-stars ml-1"></i> قائمة الوحدات المسجلة بالنظام
                                </div>
                                <div class="formal-card-body p-0">
                                    <div class="table-responsive">
                                        <table class="table-formal">
                                            <thead>
                                                <tr>
                                                    <th style="width: 15%;" class="text-center">كود</th>
                                                    <th>اسم وحدة القياس</th>
                                                    <th class="text-center" style="width: 25%;">إجراءات</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php if (empty($units_list)): ?>
                                                    <tr>
                                                        <td colspan="3" class="text-center text-muted p-4">لا توجد وحدات قياس مهيأة بالنظام حالياً. قم بإضافة وحدة من النموذج الجانبي.</td>
                                                    </tr>
                                                <?php else: ?>
                                                    <?php foreach ($units_list as $u): ?>
                                                        <tr>
                                                            <td class="text-center font-weight-bold text-muted">#<?php echo $u['id']; ?></td>
                                                            <td class="font-weight-bold text-dark"><?php echo htmlspecialchars($u['name']); ?></td>
                                                            <td class="text-center">
                                                                <a href="units.php?edit_unit=<?php echo $u['id']; ?>" class="btn btn-sm btn-outline-primary py-1 px-2 font-weight-bold" style="font-size:0.75rem;">
                                                                    <i class="bi bi-pencil ml-1"></i> تعديل
                                                                </a>
                                                                <a href="units.php?del_unit=<?php echo $u['id']; ?>" onclick="return confirm('هل أنت متأكد من حذف هذه الوحدة؟')" class="btn btn-sm btn-outline-danger py-1 px-2 font-weight-bold mr-1" style="font-size:0.75rem;">
                                                                    <i class="bi bi-trash ml-1"></i> حذف
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

                        <!-- Right Panel: Add/Edit Unit Form -->
                        <div class="col-lg-4 mb-4">
                            <div class="formal-card">
                                <div class="formal-card-head is-accent">
                                    <i class="bi <?php echo $edit_unit ? 'bi-pencil-square' : 'bi-plus-circle'; ?> ml-1"></i>
                                    <?php echo $edit_unit ? 'تعديل وحدة قياس' : 'إضافة وحدة قياس جديدة'; ?>
                                </div>
                                <div class="formal-card-body">
                                    <form method="POST" action="units.php">
                                        <?php if ($edit_unit): ?>
                                            <input type="hidden" name="unit_id" value="<?php echo $edit_unit['id']; ?>">
                                        <?php endif; ?>

                                        <div class="form-group mb-3">
                                            <label class="field-label">اسم وحدة القياس *</label>
                                            <input type="text" name="unit_name" class="form-control rounded-0 font-weight-bold" 
                                                   value="<?php echo $edit_unit ? htmlspecialchars($edit_unit['name']) : ''; ?>" 
                                                   placeholder="مثال: كرتون، حبة، درزن، شوال..." required>
                                            <span class="field-hint">تستخدم وحدات القياس لتحديد طريقة تعبئة وبيج الأصناف.</span>
                                        </div>

                                        <button type="submit" name="btn_save_unit" class="btn-formal-primary btn-block justify-content-center">
                                            <i class="bi <?php echo $edit_unit ? 'bi-check2-circle' : 'bi-plus-lg'; ?> ml-1"></i>
                                            <?php echo $edit_unit ? 'حفظ التعديلات' : 'إضافة الوحدة العامة'; ?>
                                        </button>

                                        <?php if ($edit_unit): ?>
                                            <a href="units.php" class="btn-formal-secondary btn-block text-center mt-2 justify-content-center">
                                                إلغاء التعديل
                                            </a>
                                        <?php endif; ?>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once($dir_prefix . 'includes/footer.php'); ?>
