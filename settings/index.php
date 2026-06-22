<?php
$dir_prefix = '../';
$module = 'settings';

if (isset($_POST['ajax_update_license'])) {
    require_once($dir_prefix . 'includes/connect.php');
    header('Content-Type: application/json; charset=utf-8');
    
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (!isset($_SESSION['SESS_LAST_NAME']) || trim($_SESSION['SESS_LAST_NAME']) !== 'admin') {
        echo json_encode(['status' => 'error', 'message' => 'غير مصرح لك بإجراء هذه العملية.']);
        exit();
    }
    
    $activationCode = trim($_POST['activation_code'] ?? '');
    if (empty($activationCode)) {
        echo json_encode(['status' => 'error', 'message' => 'يرجى إدخال كود التفعيل أولاً.']);
        exit();
    }
    
    require_once(__DIR__ . '/../core/Licensing.php');
    $lic = new \AQNEX\Core\Licensing();
    
    $fileContent = @base64_decode($activationCode);
    $licenseData = @json_decode($fileContent, true);
    
    if (!$licenseData || !isset($licenseData['payload']) || !isset($licenseData['signature'])) {
        echo json_encode(['status' => 'error', 'message' => 'كود التفعيل المدخل غير صالح أو تالف.']);
        exit();
    }
    
    $destPath = $dir_prefix . 'license/license.AQNEX';
    if (!is_dir(dirname($destPath))) {
        @mkdir(dirname($destPath), 0777, true);
    }
    
    $backupContent = '';
    $hasBackup = file_exists($destPath);
    if ($hasBackup) {
        $backupContent = file_get_contents($destPath);
    }
    
    file_put_contents($destPath, $fileContent);
    
    $verify = $lic->verifyLicense();
    if ($verify['status']) {
        $payload = $verify['data'];
        
        $conn->query("DELETE FROM system_licensing");
        $stmt = $conn->prepare("INSERT INTO system_licensing 
            (machine_id, company_name, owner_name, phone, city, license_type, start_date, expiry_date, modules_enabled, max_users, max_branches, license_key, activation_status, activated_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())");
        
        $city_val = 'غير محدد';
        $expiry_date_val = $payload['expiry_date'];
        if (empty($expiry_date_val) || trim($expiry_date_val) === '') {
            $expiry_date_val = '2099-12-31';
        }
        
        $stmt->bind_param(
            "sssssssssiis", 
            $payload['machine_id'], 
            $payload['company_name'], 
            $payload['owner_name'], 
            $payload['phone'], 
            $city_val, 
            $payload['license_type'], 
            $payload['start_date'], 
            $expiry_date_val, 
            $payload['modules_enabled'], 
            $payload['max_users'], 
            $payload['max_branches'], 
            $fileContent
        );
        
        if ($stmt->execute()) {
            $conn->query("UPDATE system_licensing SET tampering_lock = 0 WHERE id = 1");
            echo json_encode(['status' => 'success', 'message' => '✓ تم تجديد وتحديث ترخيص النظام التجاري بنجاح!']);
            exit();
        } else {
            if ($hasBackup) {
                file_put_contents($destPath, $backupContent);
            } else {
                @unlink($destPath);
            }
            echo json_encode(['status' => 'error', 'message' => 'فشل حفظ الترخيص المجدد بقاعدة البيانات: ' . $conn->error]);
            exit();
        }
    } else {
        if ($hasBackup) {
            file_put_contents($destPath, $backupContent);
        } else {
            @unlink($destPath);
        }
        echo json_encode(['status' => 'error', 'message' => 'كود التفعيل غير صالح أو مخصص لجهاز آخر: ' . $verify['message']]);
        exit();
    }
}

require_once($dir_prefix . 'includes/header.php');
check_permission(['admin']);

$error = '';
$success = '';

// جلب الإعدادات الحالية من قاعدة البيانات
$sql = "SELECT * FROM settings WHERE id = 1";
$res = $conn->query($sql);
$settings = ($res) ? $res->fetch_assoc() : null;

// إذا لم تكن الإعدادات موجودة، يتم إنشاؤها
if (!$settings) {
    $conn->query("INSERT INTO settings (id, store_name, phone, address, currency, barcode_scanner, printer_type, tax_percent, low_stock_threshold, receipt_footer, cashier_permissions, inventory_permissions) 
                  VALUES (1, 'تكنولوجيا فون', '777777777', 'اليمن - عدن', 'ريال يمني', 1, 'receipt_80mm', 0, 5, 'شكرًا لزيارتكم!', 'sales,customers,receipts', 'products,categories,purchases,suppliers')");
    $res = $conn->query($sql);
    $settings = $res->fetch_assoc();
}

// 1. معالجة حفظ بيانات المتجر والطباعة
if (isset($_POST['btn_save_store'])) {
    $store_name = $conn->real_escape_string(trim($_POST['store_name']));
    $phone = $conn->real_escape_string(trim($_POST['phone']));
    $address = $conn->real_escape_string(trim($_POST['address']));
    $currency = $conn->real_escape_string(trim($_POST['currency']));
    $printer_type = $conn->real_escape_string(trim($_POST['printer_type']));
    $receipt_footer = $conn->real_escape_string(trim($_POST['receipt_footer']));

    if (empty($store_name)) {
        $error = 'اسم المتجر حقل إجباري.';
    } else {
        $logo_path = isset($settings['logo']) ? $settings['logo'] : '';
        if (isset($_FILES['logo_file']) && $_FILES['logo_file']['error'] == UPLOAD_ERR_OK) {
            $file_tmp = $_FILES['logo_file']['tmp_name'];
            $file_name = $_FILES['logo_file']['name'];
            $ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
            $allowed_exts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'ico'];
            if (in_array($ext, $allowed_exts)) {
                $upload_dir = $dir_prefix . 'uploads/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                $new_logo_name = 'logo_' . time() . '.' . $ext;
                $dest_path = $upload_dir . $new_logo_name;
                if (move_uploaded_file($file_tmp, $dest_path)) {
                    if (!empty($logo_path) && file_exists($dir_prefix . $logo_path)) {
                        @unlink($dir_prefix . $logo_path);
                    }
                    $logo_path = 'uploads/' . $new_logo_name;
                }
            } else {
                $error = 'امتداد ملف الشعار غير مسموح به. الامتدادات المسموحة: jpg, jpeg, png, gif, webp, ico';
            }
        }

        if (empty($error)) {
            $sql_up = "UPDATE settings SET 
                       store_name = '$store_name', 
                       phone = '$phone', 
                       address = '$address', 
                       currency = '$currency', 
                       printer_type = '$printer_type', 
                       receipt_footer = '$receipt_footer',
                       logo = '$logo_path' 
                       WHERE id = 1";
            if ($conn->query($sql_up)) {
                $success = 'تم حفظ بيانات المتجر والطباعة بنجاح!';
                // تحديث العملة الأساسية في جدول العملات
                $conn->query("UPDATE currencies SET name = '$currency' WHERE id = 1");
                // إعادة جلب البيانات
                $res = $conn->query($sql);
                $settings = $res->fetch_assoc();
                // تحديث المتغير العام
                $global_settings = $settings;
                $logo_url = !empty($global_settings['logo']) ? $prefix . htmlspecialchars($global_settings['logo']) : $prefix . 'icon/tec.jpg';
            } else {
                $error = 'حدث خطأ أثناء الحفظ: ' . $conn->error;
            }
        }
    }
}

// 2. معالجة السياسات والباركود
if (isset($_POST['btn_save_policies'])) {
    $barcode_scanner = intval($_POST['barcode_scanner']);
    $tax_percent = doubleval($_POST['tax_percent']);
    $low_stock_threshold = intval($_POST['low_stock_threshold']);

    $sql_up = "UPDATE settings SET 
               barcode_scanner = '$barcode_scanner', 
               tax_percent = '$tax_percent', 
               low_stock_threshold = '$low_stock_threshold' 
               WHERE id = 1";
    if ($conn->query($sql_up)) {
        $success = 'تم حفظ سياسات الضرائب والباركود بنجاح!';
        $res = $conn->query($sql);
        $settings = $res->fetch_assoc();
    } else {
        $error = 'حدث خطأ أثناء حفظ السياسات: ' . $conn->error;
    }
}

// 3. معالجة أسعار الصرح والعملات
if (isset($_POST['btn_update_rates'])) {
    if (isset($_POST['rate']) && is_array($_POST['rate'])) {
        foreach ($_POST['rate'] as $curr_id => $rate_val) {
            $curr_id = intval($curr_id);
            $rate_val = doubleval($rate_val);
            if ($curr_id > 1) { // ر.ي الأساسي دائماً 1.0
                $conn->query("UPDATE currencies SET exchange_rate = $rate_val WHERE id = $curr_id");
            }
        }
        $success = 'تم تحديث أسعار صرف العملات بنجاح!';
    }
}

if (isset($_POST['btn_add_currency'])) {
    $curr_name = $conn->real_escape_string(trim($_POST['curr_name']));
    $curr_code = $conn->real_escape_string(trim($_POST['curr_code']));
    $curr_symbol = $conn->real_escape_string(trim($_POST['curr_symbol']));
    $curr_rate = doubleval($_POST['curr_rate']);

    if (empty($curr_name) || empty($curr_code) || empty($curr_symbol) || $curr_rate <= 0) {
        $error = 'الرجاء تعبئة كافة حقول العملة الجديدة بقيم صحيحة.';
    } else {
        $sql_ins = "INSERT INTO currencies (name, code, symbol, exchange_rate, is_base) 
                    VALUES ('$curr_name', '$curr_code', '$curr_symbol', $curr_rate, 0)";
        if ($conn->query($sql_ins)) {
            $success = 'تم إضافة العملة الجديدة بنجاح!';
        } else {
            $error = 'فشل إضافة العملة (ربما رمز العملة مكرر): ' . $conn->error;
        }
    }
}

if (isset($_GET['del_curr'])) {
    $curr_id = intval($_GET['del_curr']);
    if ($curr_id > 1) {
        $conn->query("DELETE FROM currencies WHERE id = $curr_id");
        $success = 'تم إزالة العملة بنجاح!';
    }
}

// 4. معالجة صلاحيات الأدوار
if (isset($_POST['btn_save_permissions'])) {
    $cashier_perms = isset($_POST['cashier_perms']) && is_array($_POST['cashier_perms']) ? implode(',', $_POST['cashier_perms']) : '';
    $inventory_perms = isset($_POST['inventory_perms']) && is_array($_POST['inventory_perms']) ? implode(',', $_POST['inventory_perms']) : '';

    $sql_up = "UPDATE settings SET 
               cashier_permissions = '$cashier_perms', 
               inventory_permissions = '$inventory_perms' 
               WHERE id = 1";
    if ($conn->query($sql_up)) {
        $success = 'تم تعميم وتحديث صلاحيات المجموعات بنجاح!';
        $res = $conn->query($sql);
        $settings = $res->fetch_assoc();
    } else {
        $error = 'حدث خطأ أثناء حفظ الصلاحيات: ' . $conn->error;
    }
}

// جلب العملات
$currencies_list = [];
$res_curr = $conn->query("SELECT * FROM currencies ORDER BY id ASC");
if ($res_curr) {
    while($c = $res_curr->fetch_assoc()) {
        $currencies_list[] = $c;
    }
}

// قائمة موديولات النظام المتاحة للصلاحيات
$system_modules = [
    'sales' => 'إدارة المبيعات وفواتيرها',
    'purchases' => 'إدارة المشتريات والتوريدات',
    'products' => 'جرد المخازن والتسويات الجردية',
    'categories' => 'أصناف وتصنيفات المنتجات',
    'box' => 'الصندوق المالي وحركة الخزينة',
    'receipts' => 'سندات المقبوضات (القبض)',
    'expenses' => 'سندات الصرف والمصروفات',
    'customers' => 'إدارة حسابات العملاء ماليًا',
    'suppliers' => 'إدارة حسابات الموردين ماليًا',
    'reports' => 'التقارير اليومية والدورية والأرباح',
    'users' => 'إدارة المستخدمين والموظفين والصلاحيات'
];

// 5. معالجة النسخ الاحتياطي اليدوي
if (isset($_POST['btn_create_backup'])) {
    require_once($dir_prefix . 'core/BackupManager.php');
    $bm = new \AQNEX\Core\BackupManager($conn);
    $user_name = $_SESSION['SESS_FIRST_NAME'] ?? 'admin';
    $res_bk = $bm->createBackup('manual', $user_name);
    if ($res_bk['status']) {
        $success = 'تم إنشاء نسخة احتياطية كاملة وضغطها بنجاح باسم: ' . $res_bk['filename'];
    } else {
        $error = 'فشل النسخ الاحتياطي: ' . $res_bk['message'];
    }
}

// 6. معالجة رفع وتطبيق التحديث
if (isset($_POST['btn_apply_update'])) {
    if (isset($_FILES['update_file']) && $_FILES['update_file']['error'] === UPLOAD_ERR_OK) {
        require_once($dir_prefix . 'core/UpdateManager.php');
        require_once($dir_prefix . 'core/BackupManager.php');
        $um = new \AQNEX\Core\UpdateManager($conn);
        
        // التأكد من وجود مجلد storage
        if (!is_dir($dir_prefix . 'storage')) {
            mkdir($dir_prefix . 'storage', 0777, true);
        }
        
        $tempZip = $dir_prefix . 'storage/temp_update_' . time() . '.zip';
        if (move_uploaded_file($_FILES['update_file']['tmp_name'], $tempZip)) {
            $res_up = $um->applyUpdate($tempZip);
            if ($res_up['status']) {
                $success = $res_up['message'];
            } else {
                $error = 'فشل الترقية والتحديث: ' . $res_up['message'];
            }
        } else {
            $error = 'فشل رفع ملف التحديث مؤقتاً.';
        }
    } else {
        $error = 'يرجى اختيار ملف التحديث المعتمد أولاً.';
    }
}

// 7. معالجة تحديث الترخيص من لوحة التحكم (تم تحويلها لـ AJAX بالأعلى)

// جلب سجل النسخ الاحتياطية
$backups_list = [];
$res_bk_list = $conn->query("SELECT * FROM system_backups ORDER BY id DESC LIMIT 10");
if ($res_bk_list) {
    while($row = $res_bk_list->fetch_assoc()) {
        $backups_list[] = $row;
    }
}

// جلب سجل التحديثات
$updates_list = [];
$res_up_list = $conn->query("SELECT * FROM system_updates ORDER BY id DESC LIMIT 10");
if ($res_up_list) {
    while($row = $res_up_list->fetch_assoc()) {
        $updates_list[] = $row;
    }
}

// جلب بيانات الترخيص الحالية
$license_info = null;
$res_lic = $conn->query("SELECT * FROM system_licensing LIMIT 1");
if ($res_lic && $res_lic->num_rows > 0) {
    $license_info = $res_lic->fetch_assoc();
}
require_once($dir_prefix . 'core/Licensing.php');
$machineId = \AQNEX\Core\Licensing::generateMachineID();
?>
<title>إدارة النظام وتفضيلات المتجر - تكنولوجيا فون</title>

<style>
.nav-tabs-custom {
    border-bottom: 2px solid var(--secondary);
    margin-bottom: 25px;
}
.nav-tabs-custom .nav-link {
    border-radius: 0 !important;
    border: none;
    font-weight: bold;
    color: var(--text-muted);
    padding: 12px 25px;
    transition: all 0.2s ease-in-out;
}
.nav-tabs-custom .nav-link:hover {
    color: var(--secondary);
    background-color: #f8f9fa;
}
.nav-tabs-custom .nav-link.active {
    background-color: var(--secondary) !important;
    color: #fff !important;
}
.tab-content-custom {
    background: #fff;
    padding: 25px;
    border: 1px solid #e2e8f0;
}
.permission-checkbox-card {
    border: 1px solid #e2e8f0;
    padding: 15px;
    background: #fdfdfd;
}
</style>

<div class="row mb-4 no-print">
    <div class="col-md-6">
        <h3 class="text-secondary font-weight-bold">
            <?php echo get_icon('cog', 'ml-2 text-primary'); ?> إدارة النظام وتفضيلات المتجر
        </h3>
        <p class="text-muted small mb-0">تهيئة إعدادات المتجر العامة، الضرائب، الباركود، الصرف، وصلاحيات المجموعات.</p>
    </div>
    <div class="col-md-6 text-left">
        <a href="../home.php" class="btn-flat btn-flat-secondary btn-sm text-decoration-none">
            <?php echo get_icon('logout', 'ml-1'); ?> عودة للرئيسية
        </a>
    </div>
</div>

<div class="row justify-content-center no-print">
    <div class="col-lg-11">
        <?php if (!empty($error)): ?>
            <div class="alert alert-danger rounded-0 mb-4"><?php echo $error; ?></div>
        <?php endif; ?>

        <?php if (!empty($success)): ?>
            <div class="alert alert-success rounded-0 mb-4"><?php echo $success; ?></div>
        <?php endif; ?>

        <!-- تبويبات إعدادات النظام -->
        <ul class="nav nav-tabs nav-tabs-custom" id="settingsTabs" role="tablist">
            <li class="nav-item">
                <a class="nav-link active" id="store-tab" data-toggle="tab" href="#store" role="tab" aria-selected="true">
                    <?php echo get_icon('home', 'ml-1'); ?> بيانات المتجر والطباعة
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" id="policies-tab" data-toggle="tab" href="#policies" role="tab" aria-selected="false">
                    <?php echo get_icon('bolt', 'ml-1'); ?> السياسات والباركود
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" id="currencies-tab" data-toggle="tab" href="#currencies" role="tab" aria-selected="false">
                    <?php echo get_icon('money', 'ml-1'); ?> العملات وأسعار الصرف
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" id="permissions-tab" data-toggle="tab" href="#permissions" role="tab" aria-selected="false">
                    <?php echo get_icon('users', 'ml-1'); ?> صلاحيات الموظفين
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" id="backups-tab" data-toggle="tab" href="#backups" role="tab" aria-selected="false">
                    <i class="fa fa-hdd-o ml-1"></i> النسخ الاحتياطي
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" id="updates-tab" data-toggle="tab" href="#updates" role="tab" aria-selected="false">
                    <i class="fa fa-refresh ml-1"></i> الترخيص والتحديثات
                </a>
            </li>
        </ul>

        <div class="tab-content tab-content-custom mb-5">
            <!-- 1. تبويب بيانات المتجر والطباعة -->
            <div class="tab-pane fade show active" id="store" role="tabpanel">
                <form method="POST" enctype="multipart/form-data">
                    <h5 class="text-secondary font-weight-bold border-bottom pb-2 mb-4">بيانات الفواتير والطباعة</h5>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="font-weight-bold mb-2 text-secondary">اسم المتجر / الشركة *</label>
                            <input type="text" name="store_name" class="form-control rounded-0" value="<?php echo htmlspecialchars($settings['store_name']); ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="font-weight-bold mb-2 text-secondary">رقم هاتف الفاتورة</label>
                            <input type="text" name="phone" class="form-control rounded-0" value="<?php echo htmlspecialchars($settings['phone']); ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="font-weight-bold mb-2 text-secondary">شعار المتجر / الشركة (Logo)</label>
                            <input type="file" name="logo_file" class="form-control rounded-0">
                            <?php if (!empty($settings['logo'])): ?>
                                <div class="mt-2">
                                    <img src="<?php echo $prefix . htmlspecialchars($settings['logo']); ?>" style="max-height: 80px; border: 1px solid #cbd5e1; padding: 4px; background: #fff;" class="rounded">
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="font-weight-bold mb-2 text-secondary">العملة الافتراضية للنظام (الأساسية)</label>
                            <input type="text" name="currency" class="form-control rounded-0 bg-light" value="<?php echo htmlspecialchars($settings['currency']); ?>" readonly>
                            <small class="text-muted">العملة المرجعية الأساسية لكل حسابات الخزينة وتقارير الأرباح.</small>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="font-weight-bold mb-2 text-secondary">تنسيق فواتير المبيعات</label>
                            <select name="printer_type" class="form-control rounded-0">
                                <option value="receipt_80mm" <?php echo ($settings['printer_type'] === 'receipt_80mm') ? 'selected' : ''; ?>>فاتورة حرارية POS قياس 80 مم (عرض متناسق وضيق)</option>
                                <option value="receipt_58mm" <?php echo ($settings['printer_type'] === 'receipt_58mm') ? 'selected' : ''; ?>>فاتورة حرارية POS قياس 58 مم (عرض ضيق جداً)</option>
                                <option value="standard_a4" <?php echo ($settings['printer_type'] === 'standard_a4') ? 'selected' : ''; ?>>ورق قياسي A4 (كامل عرض الصفحة للتقارير)</option>
                            </select>
                        </div>
                        <div class="col-md-12 mb-3">
                            <label class="font-weight-bold mb-2 text-secondary">عنوان المتجر (يظهر في ترويسة الفاتورة)</label>
                            <input type="text" name="address" class="form-control rounded-0" value="<?php echo htmlspecialchars($settings['address']); ?>">
                        </div>
                        <div class="col-md-12 mb-3">
                            <label class="font-weight-bold mb-2 text-secondary">رسالة تذييل الفاتورة (تظهر أسفل فاتورة العميل)</label>
                            <textarea name="receipt_footer" class="form-control rounded-0" rows="3"><?php echo htmlspecialchars($settings['receipt_footer']); ?></textarea>
                        </div>
                    </div>
                    <div class="text-left mt-3">
                        <button type="submit" name="btn_save_store" class="btn-flat btn-flat-primary px-4">
                            <?php echo get_icon('check', 'ml-1'); ?> حفظ تفضيلات المتجر
                        </button>
                    </div>
                </form>
            </div>

            <!-- 2. تبويب السياسات والباركود -->
            <div class="tab-pane fade" id="policies" role="tabpanel">
                <form method="POST">
                    <h5 class="text-secondary font-weight-bold border-bottom pb-2 mb-4">السياسات المالية والباركود</h5>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="font-weight-bold mb-2 text-secondary">نسبة الضريبة المضافة والـ VAT (%)</label>
                            <input type="number" step="any" min="0" name="tax_percent" class="form-control rounded-0" value="<?php echo htmlspecialchars($settings['tax_percent']); ?>">
                            <small class="text-muted">تضاف تلقائياً لحساب مبيعات التجزئة عند تسجيل فواتير بيع جديدة.</small>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="font-weight-bold mb-2 text-secondary">حد تنبيه نقص الكميات بالمخزن</label>
                            <input type="number" min="1" name="low_stock_threshold" class="form-control rounded-0" value="<?php echo htmlspecialchars($settings['low_stock_threshold']); ?>">
                            <small class="text-muted">يقوم النظام بتنبيه أمين المستودع باللون الأحمر إذا نقصت كمية المنتج عن هذا الرقم.</small>
                        </div>
                        <div class="col-md-12 mb-3">
                            <label class="font-weight-bold mb-2 text-secondary">قارئ الباركود الإلكتروني في المبيعات</label>
                            <select name="barcode_scanner" class="form-control rounded-0">
                                <option value="1" <?php echo ($settings['barcode_scanner'] == 1) ? 'selected' : ''; ?>>مفعل (فتح حقل المسح التلقائي والفوري للمنتج في الفاتورة)</option>
                                <option value="0" <?php echo ($settings['barcode_scanner'] == 0) ? 'selected' : ''; ?>>معطل</option>
                            </select>
                        </div>
                    </div>
                    <div class="text-left mt-3">
                        <button type="submit" name="btn_save_policies" class="btn-flat btn-flat-primary px-4">
                            <?php echo get_icon('check', 'ml-1'); ?> حفظ سياسات النظام
                        </button>
                    </div>
                </form>
            </div>

            <!-- 3. تبويب العملات والصرف -->
            <div class="tab-pane fade" id="currencies" role="tabpanel">
                <div class="row">
                    <!-- تعديل أسعار الصرف الحالية -->
                    <div class="col-md-7 mb-4">
                        <form method="POST">
                            <h5 class="text-secondary font-weight-bold border-bottom pb-2 mb-4">قائمة العملات وأسعار الصرف الحالية</h5>
                            <div class="table-responsive">
                                <table class="table-flat border">
                                    <thead>
                                        <tr>
                                            <th>العملة</th>
                                            <th>الرمز</th>
                                            <th>سعر الصرف (قيمة الوحدة بـ ر.ي)</th>
                                            <th>الحالة</th>
                                            <th>إجراء</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($currencies_list as $curr): ?>
                                            <tr>
                                                <td class="font-weight-bold"><?php echo htmlspecialchars($curr['name']); ?> (<?php echo htmlspecialchars($curr['code']); ?>)</td>
                                                <td><?php echo htmlspecialchars($curr['symbol']); ?></td>
                                                <td>
                                                    <?php if ($curr['is_base'] == 1): ?>
                                                        <input type="text" class="form-control form-control-sm rounded-0 text-center bg-light font-weight-bold" readonly value="1.0">
                                                    <?php else: ?>
                                                        <input type="number" step="any" min="0.0001" name="rate[<?php echo $curr['id']; ?>]" class="form-control form-control-sm rounded-0 text-center font-weight-bold border-secondary" value="<?php echo $curr['exchange_rate']; ?>">
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php echo ($curr['is_base'] == 1) ? '<span class="badge badge-success px-2 py-1 rounded-0">أساسية</span>' : '<span class="badge badge-secondary px-2 py-1 rounded-0">أجنبية</span>'; ?>
                                                </td>
                                                <td>
                                                    <?php if ($curr['is_base'] == 0): ?>
                                                        <a href="?del_curr=<?php echo $curr['id']; ?>" class="btn btn-outline-danger btn-sm rounded-0 py-0 px-2" onclick="return confirm('هل أنت متأكد من حذف هذه العملة؟')">حذف</a>
                                                    <?php else: ?>
                                                        -
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div class="text-left mt-3">
                                <button type="submit" name="btn_update_rates" class="btn-flat btn-flat-primary px-4">
                                    <?php echo get_icon('check', 'ml-1'); ?> تحديث أسعار الصرف
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- إضافة عملة جديدة -->
                    <div class="col-md-5 mb-4">
                        <div class="card bg-light border rounded-0">
                            <div class="card-header bg-secondary text-white rounded-0">
                                <h6 class="font-weight-bold mb-0">إضافة عملة صرافة جديدة</h6>
                            </div>
                            <div class="card-body">
                                <form method="POST">
                                    <div class="form-group mb-3">
                                        <label class="font-weight-bold mb-1 text-secondary">اسم العملة *</label>
                                        <input type="text" name="curr_name" class="form-control rounded-0" placeholder="مثال: دولار أمريكي" required>
                                    </div>
                                    <div class="row">
                                        <div class="col-md-6 form-group mb-3">
                                            <label class="font-weight-bold mb-1 text-secondary">رمز العملة *</label>
                                            <input type="text" name="curr_code" class="form-control rounded-0 text-center" placeholder="مثال: USD" required>
                                        </div>
                                        <div class="col-md-6 form-group mb-3">
                                            <label class="font-weight-bold mb-1 text-secondary">العلامة *</label>
                                            <input type="text" name="curr_symbol" class="form-control rounded-0 text-center" placeholder="مثال: $" required>
                                        </div>
                                    </div>
                                    <div class="form-group mb-3">
                                        <label class="font-weight-bold mb-1 text-secondary">سعر الصرف (قيمة الوحدة بـ ر.ي) *</label>
                                        <input type="number" step="any" name="curr_rate" class="form-control rounded-0 text-center font-weight-bold" placeholder="مثال: 530" required>
                                    </div>
                                    <button type="submit" name="btn_add_currency" class="btn-flat btn-flat-success btn-block mt-3">
                                        <?php echo get_icon('plus', 'ml-1'); ?> تسجيل العملة الجديدة
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 4. تبويب صلاحيات الموظفين -->
            <div class="tab-pane fade" id="permissions" role="tabpanel">
                <form method="POST">
                    <h5 class="text-secondary font-weight-bold border-bottom pb-2 mb-4">تخصيص صلاحيات الوصول والمجموعات</h5>
                    
                    <div class="row">
                        <!-- صلاحيات البائع / الكاشير -->
                        <div class="col-md-6 mb-4">
                            <div class="permission-checkbox-card border-top border-info" style="border-width: 3px !important;">
                                <h5 class="font-weight-bold text-info mb-3">صلاحيات دور الكاشير / البائع (Cashier)</h5>
                                <?php 
                                $cashier_active = array_map('trim', explode(',', $settings['cashier_permissions']));
                                foreach ($system_modules as $mod_key => $mod_name):
                                    $checked = in_array($mod_key, $cashier_active) ? 'checked' : '';
                                ?>
                                    <div class="custom-control custom-checkbox mb-2">
                                        <input type="checkbox" name="cashier_perms[]" value="<?php echo $mod_key; ?>" class="custom-control-input" id="cashier_<?php echo $mod_key; ?>" <?php echo $checked; ?>>
                                        <label class="custom-control-label font-weight-bold text-secondary" for="cashier_<?php echo $mod_key; ?>"><?php echo $mod_name; ?></label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- صلاحيات أمين المستودع -->
                        <div class="col-md-6 mb-4">
                            <div class="permission-checkbox-card border-top border-warning" style="border-width: 3px !important;">
                                <h5 class="font-weight-bold text-warning mb-3">صلاحيات دور أمين المستودع (Inventory)</h5>
                                <?php 
                                $inventory_active = array_map('trim', explode(',', $settings['inventory_permissions']));
                                foreach ($system_modules as $mod_key => $mod_name):
                                    $checked = in_array($mod_key, $inventory_active) ? 'checked' : '';
                                ?>
                                    <div class="custom-control custom-checkbox mb-2">
                                        <input type="checkbox" name="inventory_perms[]" value="<?php echo $mod_key; ?>" class="custom-control-input" id="inventory_<?php echo $mod_key; ?>" <?php echo $checked; ?>>
                                        <label class="custom-control-label font-weight-bold text-secondary" for="inventory_<?php echo $mod_key; ?>"><?php echo $mod_name; ?></label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <div class="text-left mt-3">
                        <button type="submit" name="btn_save_permissions" class="btn-flat btn-flat-primary px-4">
                            <?php echo get_icon('check', 'ml-1'); ?> تعميم وحفظ الصلاحيات لكل الأقسام
                        </button>
                    </div>
                </form>
            </div>

            <!-- 5. تبويب النسخ الاحتياطي -->
            <div class="tab-pane fade" id="backups" role="tabpanel">
                <div class="row">
                    <div class="col-md-4 mb-4">
                        <div class="card bg-light border rounded-0">
                            <div class="card-header bg-secondary text-white rounded-0">
                                <h6 class="font-weight-bold mb-0">إنشاء نسخة احتياطية جديدة</h6>
                            </div>
                            <div class="card-body text-right">
                                <p class="small text-muted">
                                    يقوم محرك النسخ الاحتياطي بتصدير الهيكل والبيانات الخاصة بقاعدة البيانات بالكامل، بالإضافة لنسخ جميع الملفات المرفوعة وحفظها في أرشيف مضغوط واحد.
                                </p>
                                <form method="POST">
                                    <button type="submit" name="btn_create_backup" class="btn-flat btn-flat-success btn-block mt-4">
                                        <i class="fa fa-download ml-1"></i> تشغيل النسخ الاحتياطي الآن
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-8 mb-4">
                        <h5 class="text-secondary font-weight-bold border-bottom pb-2 mb-4">أحدث 10 نسخ احتياطية مسجلة</h5>
                        <div class="table-responsive">
                            <table class="table-flat border text-right">
                                <thead>
                                    <tr>
                                        <th>اسم الملف</th>
                                        <th>الحجم</th>
                                        <th>النوع</th>
                                        <th>بواسطة</th>
                                        <th>تاريخ الإنشاء</th>
                                        <th>تحميل</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($backups_list)): ?>
                                        <tr>
                                            <td colspan="6" class="text-center text-muted py-3">لا توجد نسخ احتياطية مسجلة بعد.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($backups_list as $bk): ?>
                                            <tr>
                                                <td class="font-weight-bold small text-info"><?php echo htmlspecialchars($bk['backup_name']); ?>.zip</td>
                                                <td><?php echo round($bk['file_size'] / 1024 / 1024, 2); ?> MB</td>
                                                <td>
                                                    <span class="badge badge-info rounded-0"><?php echo htmlspecialchars($bk['backup_type']); ?></span>
                                                </td>
                                                <td><?php echo htmlspecialchars($bk['created_by']); ?></td>
                                                <td class="small"><?php echo htmlspecialchars($bk['created_at']); ?></td>
                                                <td>
                                                    <a href="<?php echo $prefix . htmlspecialchars($bk['file_path']); ?>" class="btn btn-outline-primary btn-sm rounded-0 py-0 px-2" download>
                                                        <i class="fa fa-download"></i>
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

            <!-- 6. تبويب الترخيص والتحديثات -->
            <div class="tab-pane fade" id="updates" role="tabpanel">
                <div class="row">
                    <!-- معلومات وتجديد الترخيص -->
                    <div class="col-md-6 mb-4">
                        <div class="card border rounded-0 text-right">
                            <div class="card-header bg-dark text-teal-400 rounded-0" style="color: #2dd4bf;">
                                <h6 class="font-weight-bold mb-0"><i class="fa fa-key ml-1"></i> معلومات الترخيص التجاري الحالي</h6>
                            </div>
                            <div class="card-body">
                                <?php if ($license_info): ?>
                                    <div class="row small mb-3">
                                        <div class="col-5 text-muted font-weight-bold">اسم المنشأة:</div>
                                        <div class="col-7 font-weight-bold"><?php echo htmlspecialchars($license_info['company_name']); ?></div>
                                    </div>
                                    <div class="row small mb-3">
                                        <div class="col-5 text-muted font-weight-bold">المالك:</div>
                                        <div class="col-7 font-weight-bold"><?php echo htmlspecialchars($license_info['owner_name']); ?></div>
                                    </div>
                                    <div class="row small mb-3">
                                        <div class="col-5 text-muted font-weight-bold">نوع الباقة:</div>
                                        <div class="col-7"><span class="badge badge-success"><?php echo strtoupper($license_info['license_type']); ?></span></div>
                                    </div>
                                    <div class="row small mb-3">
                                        <div class="col-5 text-muted font-weight-bold">تاريخ الانتهاء:</div>
                                        <div class="col-7 font-weight-bold text-danger"><?php echo htmlspecialchars($license_info['expiry_date']); ?></div>
                                    </div>
                                    <div class="row small mb-3">
                                        <div class="col-5 text-muted font-weight-bold">أقصى عدد فروع:</div>
                                        <div class="col-7"><?php echo htmlspecialchars($license_info['max_branches']); ?> فروع</div>
                                    </div>
                                    <div class="row small mb-3">
                                        <div class="col-5 text-muted font-weight-bold">أقصى عدد مستخدمين:</div>
                                        <div class="col-7"><?php echo htmlspecialchars($license_info['max_users']); ?> مستخدمين</div>
                                    </div>
                                    <div class="row small mb-3">
                                        <div class="col-5 text-muted font-weight-bold">الموديولات المفعلة:</div>
                                        <div class="col-7 small font-weight-bold text-info"><?php echo htmlspecialchars($license_info['modules_enabled']); ?></div>
                                    </div>
                                <?php else: ?>
                                    <div class="alert alert-warning text-center small rounded-0">
                                        لم يتم تسجيل بيانات الترخيص بقاعدة البيانات بشكل كامل بعد.
                                    </div>
                                <?php endif; ?>

                                <hr>
                                <div class="bg-light p-2 rounded mb-3" style="font-family:monospace; font-size:0.8rem; word-break:break-all; border:1px dashed #cbd5e1;">
                                    <strong>Machine ID:</strong> <?php echo $machineId; ?>
                                </div>

                                <div id="license-ajax-error" class="alert alert-danger rounded-0 mb-3 small" style="display:none;"></div>
                                <div id="license-ajax-success" class="alert alert-success rounded-0 mb-3 small" style="display:none;"></div>
                                <div id="license-ajax-loading" class="alert alert-info rounded-0 mb-3 small" style="display:none;">
                                    <i class="fa fa-refresh fa-spin ml-2"></i> جاري التحقق من كود التفعيل وتنشيط الترخيص... يرجى الانتظار
                                </div>
                                <form id="settings-license-form">
                                    <div class="form-group mb-2">
                                        <label class="small font-weight-bold text-secondary">تجديد أو ترقية الترخيص (إدخال كود التفعيل النصي)</label>
                                        <textarea id="new_activation_code" class="form-control rounded-0" rows="3" placeholder="قم بلصق كود التفعيل النصي الطويل المستلم من الشركة هنا..." style="font-family:monospace; font-size:0.8rem;"></textarea>
                                    </div>
                                    <div class="form-group mb-3">
                                        <label class="small font-weight-bold text-secondary">أو اختر ملف الترخيص (.AQNEX) مباشرة:</label>
                                        <div class="custom-file">
                                            <input type="file" class="custom-file-input" id="new_license_file" accept=".AQNEX">
                                            <label class="custom-file-label text-right" for="new_license_file" id="new_license_file_label" style="font-size:0.85rem;">اختر ملف الترخيص...</label>
                                        </div>
                                    </div>
                                    <button type="submit" class="btn-flat btn-flat-secondary btn-block btn-sm">
                                        <i class="fa fa-key"></i> تطبيق كود أو ملف الترخيص الجديد
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- التحديثات والترقيات -->
                    <div class="col-md-6 mb-4 text-right">
                        <div class="card border rounded-0">
                            <div class="card-header bg-dark text-teal-400 rounded-0" style="color: #2dd4bf;">
                                <h6 class="font-weight-bold mb-0"><i class="fa fa-refresh ml-1"></i> محرك ترقية وتحديث النظام</h6>
                            </div>
                            <div class="card-body">
                                <p class="small text-muted">
                                    يمكنك ترقية ميزات النظام وإصلاح الأخطاء عن طريق رفع حزمة تحديث مخصصة موقعة رقمياً من شركة AQNEX بامتداد <strong class="text-teal-300">update.AQNEX</strong>.
                                </p>
                                <form method="POST" enctype="multipart/form-data">
                                    <div class="form-group mb-3">
                                        <label class="small font-weight-bold">اختر ملف التحديث الجديد</label>
                                        <input type="file" name="update_file" class="form-control rounded-0" accept=".AQNEX" style="height:auto; padding:6px;" required>
                                    </div>
                                    <button type="submit" name="btn_apply_update" class="btn-flat btn-flat-primary btn-block btn-sm">
                                        <i class="fa fa-upload"></i> تثبيت الترقية وتطبيق SQL Migration
                                    </button>
                                </form>

                                <h6 class="font-weight-bold border-bottom pb-2 mt-4 mb-3">تاريخ آخر التحديثات المثبتة</h6>
                                <div class="table-responsive">
                                    <table class="table-flat border text-right small">
                                        <thead>
                                            <tr>
                                                <th>الإصدار</th>
                                                <th>الوصف</th>
                                                <th>التاريخ</th>
                                                <th>الحالة</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($updates_list)): ?>
                                                <tr>
                                                    <td colspan="4" class="text-center text-muted py-2">لا توجد تحديثات سابقة مسجلة.</td>
                                                </tr>
                                            <?php else: ?>
                                                <?php foreach ($updates_list as $up): ?>
                                                    <tr>
                                                        <td class="font-weight-bold"><?php echo htmlspecialchars($up['version']); ?></td>
                                                        <td><?php echo htmlspecialchars($up['description']); ?></td>
                                                        <td><?php echo htmlspecialchars($up['applied_at']); ?></td>
                                                        <td>
                                                            <?php if ($up['status'] === 'success'): ?>
                                                                <span class="badge badge-success rounded-0">ناجح</span>
                                                            <?php else: ?>
                                                                <span class="badge badge-danger rounded-0">فشل</span>
                                                            <?php endif; ?>
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
                </div>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // تحديث اسم الملف المختار في الواجهة
    $('#new_license_file').on('change', function() {
        var fileName = $(this).val().split('\\').pop();
        if (fileName) {
            $('#new_license_file_label').addClass("selected").html(fileName);
        } else {
            $('#new_license_file_label').removeClass("selected").html("اختر ملف الترخيص...");
        }
    });

    $('#settings-license-form').on('submit', function(e) {
        e.preventDefault();
        
        var codeVal = $('#new_activation_code').val().trim();
        var fileInput = document.getElementById('new_license_file');
        
        if (fileInput && fileInput.files.length > 0) {
            var file = fileInput.files[0];
            var reader = new FileReader();
            reader.onload = function(evt) {
                var text = evt.target.result.trim();
                var processedCode = '';
                
                try {
                    // التحقق إذا كان الملف JSON خام
                    var parsed = JSON.parse(text);
                    if (parsed.payload && parsed.signature) {
                        processedCode = btoa(unescape(encodeURIComponent(text)));
                    } else {
                        alert("ملف الترخيص المرفق غير صالح ولا يحتوي على بيانات التوقيع.");
                        return;
                    }
                } catch(e) {
                    // التحقق إذا كان الملف مشفر بيس64 بالفعل
                    try {
                        var decoded = atob(text);
                        var parsedDecoded = JSON.parse(decoded);
                        if (parsedDecoded.payload && parsedDecoded.signature) {
                            processedCode = text;
                        } else {
                            alert("محتوى ملف الترخيص المرفق غير صالح.");
                            return;
                        }
                    } catch(err) {
                        alert("ملف الترخيص المرفق غير صالح أو تالف.");
                        return;
                    }
                }
                
                sendUpdateLicenseAjax(processedCode);
            };
            reader.readAsText(file);
        } else {
            if (codeVal === "") {
                alert("يرجى إدخال كود التفعيل أو اختيار ملف الترخيص أولاً.");
                return;
            }
            sendUpdateLicenseAjax(codeVal);
        }
    });

    function sendUpdateLicenseAjax(codeVal) {
        $('#license-ajax-error').hide();
        $('#license-ajax-success').hide();
        $('#license-ajax-loading').show();
        
        $.ajax({
            url: 'index.php',
            type: 'POST',
            data: {
                ajax_update_license: 1,
                activation_code: codeVal
            },
            dataType: 'json',
            success: function(response) {
                $('#license-ajax-loading').hide();
                if (response.status === 'success') {
                    $('#license-ajax-success').text(response.message).show();
                    $('#new_activation_code').val('');
                    // تحديث الصفحة بعد ثانيتين لمشاهدة البيانات الجديدة للترخيص
                    setTimeout(function() {
                        window.location.reload();
                    }, 2000);
                } else {
                    $('#license-ajax-error').text(response.message).show();
                }
            },
            error: function(xhr, status, error) {
                $('#license-ajax-loading').hide();
                $('#license-ajax-error').text("حدث خطأ أثناء التفعيل والاتصال بالخادم: " + error).show();
            }
        });
    }
});
</script>
<?php
require_once($dir_prefix . 'includes/footer.php');
?>
