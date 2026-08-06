<?php
$dir_prefix = '../';
$module = 'settings';
require_once($dir_prefix . 'includes/header.php');
check_permission(['admin']);

$error = '';
$success = '';

// تحديث الموديولات البرمجية عند الإرسال
if (isset($_POST['btn_save_modules'])) {
    $modules = isset($_POST['modules']) ? $_POST['modules'] : [];
    
    // أولاً نعطل جميع الموديولات
    $conn->query("UPDATE `system_modules` SET `is_enabled` = 0");
    
    // نفعل فقط الموديولات المحددة
    if (!empty($modules)) {
        foreach ($modules as $mod_key => $val) {
            $mod_key_clean = $conn->real_escape_string($mod_key);
            $conn->query("UPDATE `system_modules` SET `is_enabled` = 1 WHERE `module_key` = '$mod_key_clean'");
        }
    }
    $success = 'تم حفظ حالة الموديولات وتحديث النظام بنجاح.';
}

// تطبيق قوالب التخصيص السريع للنشاط التجاري
if (isset($_POST['btn_apply_preset'])) {
    $preset = $_POST['preset'] ?? '';
    
    if ($preset === 'grocery') {
        $conn->query("UPDATE `system_modules` SET `is_enabled` = 1 WHERE `module_key` IN ('barcode_units', 'expiry_tracking', 'thermal_printing', 'label_printing')");
        $conn->query("UPDATE `system_modules` SET `is_enabled` = 0 WHERE `module_key` IN ('serial_imei_tracking', 'repair_service', 'installments')");
        $success = 'تم تطبيق قالب (بقالة وسوبرماركت) وتحديث الموديولات بنجاح.';
    } elseif ($preset === 'electronics') {
        $conn->query("UPDATE `system_modules` SET `is_enabled` = 1 WHERE `module_key` IN ('barcode_units', 'serial_imei_tracking', 'repair_service', 'installments', 'thermal_printing', 'label_printing')");
        $conn->query("UPDATE `system_modules` SET `is_enabled` = 0 WHERE `module_key` IN ('expiry_tracking')");
        $success = 'تم تطبيق قالب (جوالات ومتجر إلكترونيات) وتحديث الموديولات بنجاح.';
    } elseif ($preset === 'general') {
        $conn->query("UPDATE `system_modules` SET `is_enabled` = 1 WHERE `module_key` IN ('barcode_units', 'thermal_printing')");
        $conn->query("UPDATE `system_modules` SET `is_enabled` = 0 WHERE `module_key` IN ('expiry_tracking', 'serial_imei_tracking', 'repair_service', 'installments', 'label_printing')");
        $success = 'تم تطبيق القالب العام بنجاح وتعطيل الميزات المتخصصة.';
    }
}

// التأكد من وجود جدول الموديولات وإنشاءه إذا كان مفقوداً
$checkModules = $conn->query("SHOW TABLES LIKE 'system_modules'");
if (!$checkModules || $checkModules->num_rows == 0) {
    $conn->query("CREATE TABLE IF NOT EXISTS `system_modules` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `module_key` VARCHAR(50) NOT NULL UNIQUE,
        `module_name` VARCHAR(100) NOT NULL DEFAULT '',
        `is_enabled` TINYINT(1) NOT NULL DEFAULT 0,
        `config_json` TEXT DEFAULT NULL,
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("INSERT INTO `system_modules` (`module_key`, `module_name`, `is_enabled`) VALUES
        ('barcode_units', 'وحدات متعددة وباركودات متعددة', 1),
        ('expiry_tracking', 'تتبع تواريخ الصلاحية', 0),
        ('serial_imei_tracking', 'تتبع الأرقام التسلسلية / IMEI', 0),
        ('repair_service', 'وحدة الصيانة', 0),
        ('installments', 'البيع بالتقسيط', 0),
        ('thermal_printing', 'الطباعة الحرارية', 1),
        ('label_printing', 'طباعة ملصقات الباركود', 1)");
}

$checkRepair = $conn->query("SELECT 1 FROM `system_modules` WHERE `module_key` = 'repair_service' LIMIT 1");
if (!$checkRepair || $checkRepair->num_rows == 0) {
    $conn->query("INSERT INTO `system_modules` (`module_key`, `module_name`, `is_enabled`) VALUES ('repair_service', 'وحدة الصيانة', 0)");
}

$modules_list = [];
$res_m = $conn->query("SELECT * FROM `system_modules` ORDER BY `id` ASC");
if ($res_m) {
    while ($row = $res_m->fetch_assoc()) {
        $modules_list[] = $row;
    }
}
?>

<title>لوحة تفعيل الموديولات - AQNEX POS</title>
<link rel="stylesheet" href="<?php echo $prefix; ?>assets/css/settings.css">

<div class="settings-shell">
    <div class="row mb-3 no-print align-items-center">
        <div class="col-md-7">
            <span class="eyebrow">إعدادات النظام وإدارة الموارد</span>
            <h3 class="mb-1">
                <span class="icon-chip"><i class="bi bi-cpu"></i></span>
                تخصيص وتفعيل موديولات النظام
            </h3>
            <p class="text-muted small mb-0">تفعيل أو تعطيل الأقسام والميزات المتقدمة (مثل تتبع تواريخ الصلاحية، السيريال، التقسيط، والصيانة) حسب نشاط المنشأة.</p>
        </div>
        <div class="col-md-5 text-left">
            <a href="../home.php" class="btn-formal-secondary text-decoration-none">
                <i class="bi bi-arrow-right-short ml-1"></i> العودة للرئيسية
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
            $active_tab = 'modules'; 
            require_once 'settings_nav.php'; 
            ?>

            <div class="tab-content tab-content-custom mb-5">
                <div class="tab-pane-inner">
                    
                    <!-- Quick Presets Formal Card -->
                    <div class="formal-card mb-4">
                        <div class="formal-card-head is-accent">
                            <i class="bi bi-magic ml-1 text-primary"></i> معالج التخصيص السريع (قوالب الأنشطة التجارية)
                        </div>
                        <div class="formal-card-body">
                            <p class="small text-muted mb-3">اختر نوع النشاط التجاري لتشغيل القواعد والتفعيلات الموصى بها تلقائياً بضغطة زر واحدة:</p>
                            <form method="POST" class="form-inline">
                                <div class="form-group mb-2">
                                    <select name="preset" class="form-control rounded-0 font-weight-bold" style="min-width: 280px;" required>
                                        <option value="">-- اختر قالب النشاط --</option>
                                        <option value="grocery">بقالة وسوبرماركت / مواد غذائية</option>
                                        <option value="electronics">معرض جوالات وإلكترونيات وصيانة</option>
                                        <option value="general">نشاط تجاري عام / تجارة عامة / خدمات</option>
                                    </select>
                                </div>
                                <button type="submit" name="btn_apply_preset" class="btn-formal-primary mb-2 mr-3" onclick="return confirm('هل أنت متأكد من تطبيق القالب المختار؟')">
                                    <i class="bi bi-lightning-charge ml-1"></i> تطبيق القالب المختار
                                </button>
                            </form>
                        </div>
                    </div>

                    <h5 class="section-heading">قائمة الموديولات والميزات المتاحة بالنظام</h5>

                    <form method="POST">
                        <div class="row">
                            <?php foreach ($modules_list as $mod): ?>
                                <div class="col-md-6 mb-4">
                                    <div class="formal-card h-100">
                                        <div class="formal-card-head d-flex justify-content-between align-items-center">
                                            <span>
                                                <?php 
                                                switch($mod['module_key']) {
                                                    case 'barcode_units': echo '<i class="bi bi-tags ml-1"></i>'; break;
                                                    case 'expiry_tracking': echo '<i class="bi bi-calendar-x ml-1"></i>'; break;
                                                    case 'serial_imei_tracking': echo '<i class="bi bi-upc-scan ml-1"></i>'; break;
                                                    case 'repair_service': echo '<i class="bi bi-tools ml-1"></i>'; break;
                                                    case 'installments': echo '<i class="bi bi-wallet2 ml-1"></i>'; break;
                                                    case 'thermal_printing': echo '<i class="bi bi-printer ml-1"></i>'; break;
                                                    case 'label_printing': echo '<i class="bi bi-aspect-ratio ml-1"></i>'; break;
                                                }
                                                echo htmlspecialchars($mod['module_name']); 
                                                ?>
                                            </span>
                                            <div class="custom-control custom-switch">
                                                <input type="checkbox" name="modules[<?php echo htmlspecialchars($mod['module_key']); ?>]" 
                                                       class="custom-control-input" 
                                                       id="switch_<?php echo htmlspecialchars($mod['module_key']); ?>" 
                                                       value="1" 
                                                       <?php echo ($mod['is_enabled'] == 1) ? 'checked' : ''; ?>>
                                                <label class="custom-control-label font-weight-bold text-white" 
                                                       for="switch_<?php echo htmlspecialchars($mod['module_key']); ?>">
                                                    <?php echo ($mod['is_enabled'] == 1) ? 'مفعل' : 'معطل'; ?>
                                                </label>
                                            </div>
                                        </div>
                                        <div class="formal-card-body small text-muted">
                                            <?php 
                                            switch($mod['module_key']) {
                                                case 'barcode_units': 
                                                    echo 'يتيح إدارة الباركودات المتعددة وتعيين أسعار مختلفة لنفس المنتج حسب وحدات القياس (كرتون، حبة، باكت) مع دعم الاحتساب التلقائي للمخزون.'; 
                                                    break;
                                                case 'expiry_tracking': 
                                                    echo 'تتبع فترات وتواريخ انتهاء الصلاحية للمنتجات الغذائية والطبية، وتنبيه الإدارة عند اقتراب تواريخ الانتهاء لتفادي الخسائر المالية.'; 
                                                    break;
                                                case 'serial_imei_tracking': 
                                                    echo 'خاص بمتاجر الجوالات والإلكترونيات. يتيح تتبع الرقم التسلسلي الفريد (Serial Number / IMEI) للأجهزة الخلوية ومتابعة الضمان والقطع بشكل دقيق.'; 
                                                    break;
                                                case 'repair_service': 
                                                    echo 'موديول استلام وصيانة الأجهزة المعطلة، ومتابعة حالة الفحص والصيانة وسحب قطع الغيار من المخزن وحساب التكاليف والربحية تلقائياً.'; 
                                                    break;
                                                case 'installments': 
                                                    echo 'نظام بيع الأجهزة بالتقسيط المالي مع جدولة الدفعات وتواريخ استحقاقها وإرسال إشعارات السداد وتحصيل الأقساط بطريقة محاسبية سليمة.'; 
                                                    break;
                                                case 'thermal_printing': 
                                                    echo 'تشغيل الطباعة الحرارية المباشرة (Silent Print) للفواتير والإيصالات دون الحوار التفاعلي للمتصفح عبر السوكيت والشبكة المحلية.'; 
                                                    break;
                                                case 'label_printing': 
                                                    echo 'إنشاء وتصميم طباعة الملصقات اللاصقة (Labels/ZPL) لباركود السلع وربطها المباشر بطابعات الباركود المتاحة.'; 
                                                    break;
                                            }
                                            ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="text-left mt-3">
                            <button type="submit" name="btn_save_modules" class="btn-formal-primary">
                                <i class="bi bi-check2-circle ml-1"></i> حفظ وتحديث موديولات النظام
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function() {
    document.querySelectorAll(".custom-control-input").forEach(sw => {
        sw.addEventListener("change", function() {
            const label = this.nextElementSibling;
            if (this.checked) {
                label.textContent = "مفعل";
            } else {
                label.textContent = "معطل";
            }
        });
    });
});
</script>

<?php require_once($dir_prefix . 'includes/footer.php'); ?>
