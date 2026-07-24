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

// التأكد من وجود جدول الموديولات وإنشاءه أو تهيئته إذا كان مفقوداً
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

// تأكد من وجود صف الصيانة إذا كانت الجداول موجودة بالفعل
$checkRepair = $conn->query("SELECT 1 FROM `system_modules` WHERE `module_key` = 'repair_service' LIMIT 1");
if (!$checkRepair || $checkRepair->num_rows == 0) {
    $conn->query("INSERT INTO `system_modules` (`module_key`, `module_name`, `is_enabled`) VALUES ('repair_service', 'وحدة الصيانة', 0)");
}

// جلب قائمة الموديولات الحالية
$modules_list = [];
$res_m = $conn->query("SELECT * FROM `system_modules` ORDER BY `id` ASC");
if ($res_m) {
    while ($row = $res_m->fetch_assoc()) {
        $modules_list[] = $row;
    }
}
?>

<title>لوحة تفعيل الموديولات - تكنولوجيا فون</title>

<?php
$active_tab = 'modules';
require_once 'settings_nav.php';
?>

<div class="card-flat">
    <div class="card-header">
        <h5><?php echo get_icon('cog', 'ml-2 text-primary'); ?> تخصيص وتفعيل موديولات النظام قطاعياً</h5>
    </div>
    
    <div class="card-body">
        <?php if (!empty($error)): ?>
            <div class="alert alert-danger rounded-0 mb-4"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <?php if (!empty($success)): ?>
            <div class="alert alert-success rounded-0 mb-4"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <!-- قسم التخصيص السريع حسب نوع النشاط التجاري -->
        <div class="card bg-light border rounded-0 p-4 mb-4">
            <h6 class="font-weight-bold mb-3 text-primary"><i class="fa fa-magic ml-2"></i>معالج التخصيص السريع (قوالب الأنشطة التجارية)</h6>
            <p class="text-muted small">اختر نوع النشاط التجاري للعميل لتفعيل الميزات المتطابقة وتعطيل الميزات غير الضرورية بضغطة واحدة:</p>
            
            <form method="POST" class="form-inline mt-3">
                <div class="form-group mb-2">
                    <select name="preset" class="form-control rounded-0 font-weight-bold" style="min-width: 250px;" required>
                        <option value="">-- اختر قالب النشاط --</option>
                        <option value="grocery">بقالة وسوبرماركت / مواد غذائية</option>
                        <option value="electronics">معرض جوالات وإلكترونيات وصيانة</option>
                        <option value="general">نشاط تجاري عام / تجارة عامة / خدمات</option>
                    </select>
                </div>
                <button type="submit" name="btn_apply_preset" class="btn btn-primary rounded-0 mb-2 mr-3 font-weight-bold px-4" onclick="return confirm('هل أنت متأكد من تطبيق القالب؟ سيؤدي ذلك إلى تعديل ميزات الموديولات الحالية لتناسب النشاط المختار.')">
                    <i class="fa fa-flash ml-1"></i> تطبيق القالب المختار
                </button>
            </form>
        </div>

        <form method="POST">
            <div class="alert alert-info rounded-0 mb-4">
                <?php echo get_icon('info-circle', 'ml-2'); ?>
                يمكنك هنا تفعيل أو تعطيل الميزات المتقدمة للنظام بناءً على نوع نشاط العميل التجاري لتبسيط واجهات المستخدم وتفادي التعقيد.
            </div>

            <div class="row">
                <?php if (empty($modules_list)): ?>
                    <div class="col-12">
                        <div class="alert alert-warning rounded-0">
                            لا توجد موديولات متاحة حالياً. تأكد من أن قاعدة البيانات متصلة بشكل صحيح وأن جدول `system_modules` موجود ومهيأ.
                        </div>
                    </div>
                <?php endif; ?>

                <?php foreach ($modules_list as $mod): ?>
                    <div class="col-md-6 mb-4">
                        <div class="card border rounded-0 h-100 shadow-sm transition-all hover-shadow">
                            <div class="card-body d-flex flex-column justify-content-between">
                                <div>
                                    <div class="d-flex justify-content-between align-items-center mb-3">
                                        <h6 class="font-weight-bold text-dark mb-0">
                                            <?php 
                                            // أيقونة حسب نوع الموديول
                                            switch($mod['module_key']) {
                                                case 'barcode_units': echo get_icon('tags', 'ml-2 text-primary'); break;
                                                case 'expiry_tracking': echo get_icon('calendar', 'ml-2 text-danger'); break;
                                                case 'serial_imei_tracking': echo get_icon('bolt', 'ml-2 text-warning'); break;
                                                case 'repair_service': echo get_icon('briefcase', 'ml-2 text-info'); break;
                                                case 'installments': echo get_icon('money', 'ml-2 text-success'); break;
                                                case 'thermal_printing': echo get_icon('print', 'ml-2 text-secondary'); break;
                                                case 'label_printing': echo get_icon('archive', 'ml-2 text-dark'); break;
                                            }
                                            echo htmlspecialchars($mod['module_name']); 
                                            ?>
                                        </h6>
                                        <div class="custom-control custom-switch">
                                            <input type="checkbox" name="modules[<?php echo htmlspecialchars($mod['module_key']); ?>]" 
                                                   class="custom-control-input" 
                                                   id="switch_<?php echo htmlspecialchars($mod['module_key']); ?>" 
                                                   value="1" 
                                                   <?php echo ($mod['is_enabled'] == 1) ? 'checked' : ''; ?>>
                                            <label class="custom-control-label font-weight-bold" 
                                                   for="switch_<?php echo htmlspecialchars($mod['module_key']); ?>">
                                                <?php echo ($mod['is_enabled'] == 1) ? 'نشط' : 'معطل'; ?>
                                            </label>
                                        </div>
                                    </div>
                                    <p class="text-muted small">
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
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="text-left mt-4 border-top pt-3">
                <button type="submit" name="btn_save_modules" class="btn-flat btn-flat-primary btn-lg px-5">
                    <?php echo get_icon('check', 'ml-1'); ?> حفظ وتحديث موديولات النظام
                </button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function() {
    // تحديث النصوص التوضيحية عند التغيير المباشر للمفاتيح
    document.querySelectorAll(".custom-control-input").forEach(sw => {
        sw.addEventListener("change", function() {
            const label = this.nextElementSibling;
            if (this.checked) {
                label.textContent = "نشط";
                label.classList.add("text-success");
                label.classList.remove("text-muted");
            } else {
                label.textContent = "معطل";
                label.classList.remove("text-success");
                label.classList.add("text-muted");
            }
        });
    });
});
</script>

<?php require_once($dir_prefix . 'includes/footer.php'); ?>
