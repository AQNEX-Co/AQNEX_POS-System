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
    $store_name = isset($_POST['store_name']) ? $conn->real_escape_string(trim($_POST['store_name'])) : '';
    $store_name_en = isset($_POST['store_name_en']) ? $conn->real_escape_string(trim($_POST['store_name_en'])) : '';
    $phone = isset($_POST['phone']) ? $conn->real_escape_string(trim($_POST['phone'])) : '';
    $phone_en = isset($_POST['phone_en']) ? $conn->real_escape_string(trim($_POST['phone_en'])) : '';
    $address = isset($_POST['address']) ? $conn->real_escape_string(trim($_POST['address'])) : '';
    $address_en = isset($_POST['address_en']) ? $conn->real_escape_string(trim($_POST['address_en'])) : '';
    $currency = isset($_POST['currency']) ? $conn->real_escape_string(trim($_POST['currency'])) : '';
    $printer_type = isset($_POST['printer_type']) ? $conn->real_escape_string(trim($_POST['printer_type'])) : '';
    $receipt_footer = isset($_POST['receipt_footer']) ? $conn->real_escape_string(trim($_POST['receipt_footer'])) : '';
    
    $commercial_register = isset($_POST['commercial_register']) ? $conn->real_escape_string(trim($_POST['commercial_register'])) : '';
    $tax_number = isset($_POST['tax_number']) ? $conn->real_escape_string(trim($_POST['tax_number'])) : '';
    $support_token_post = isset($_POST['support_token']) ? $conn->real_escape_string(trim($_POST['support_token'])) : '';
    
    $report_header_subtitle = isset($_POST['report_header_subtitle']) ? $conn->real_escape_string(trim($_POST['report_header_subtitle'])) : '';
    $report_header_notes = isset($_POST['report_header_notes']) ? $conn->real_escape_string(trim($_POST['report_header_notes'])) : '';
    $report_show_logo = isset($_POST['report_show_logo']) ? 1 : 0;
    $report_show_cr = isset($_POST['report_show_cr']) ? 1 : 0;
    $report_show_tax = isset($_POST['report_show_tax']) ? 1 : 0;

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
                       store_name_en = '$store_name_en', 
                       phone_en = '$phone_en', 
                       address_en = '$address_en', 
                       currency = '$currency', 
                       printer_type = '$printer_type', 
                       receipt_footer = '$receipt_footer',
                       logo = '$logo_path',
                       commercial_register = '$commercial_register',
                       tax_number = '$tax_number',
                       support_token = '$support_token_post',
                       report_header_subtitle = '$report_header_subtitle',
                       report_header_notes = '$report_header_notes',
                       report_show_logo = $report_show_logo,
                       report_show_cr = $report_show_cr,
                       report_show_tax = $report_show_tax
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
                $logo_url = !empty($global_settings['logo']) ? $prefix . htmlspecialchars($global_settings['logo']) : $prefix . 'assets/icon/tec.jpg';
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

// معالجة حفظ مفتاح Gemini API
if (isset($_POST['btn_save_gemini'])) {
    $gemini_key = $conn->real_escape_string(trim($_POST['gemini_api_key']));
    $sql_up = "UPDATE settings SET gemini_api_key = '$gemini_key' WHERE id = 1";
    if ($conn->query($sql_up)) {
        $success = 'تم حفظ مفتاح Gemini API بنجاح!';
        $res = $conn->query($sql);
        $settings = $res->fetch_assoc();
        $global_settings = $settings;
    } else {
        $error = 'حدث خطأ أثناء حفظ المفتاح: ' . $conn->error;
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
    'repair' => 'قسم الصيانة',
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

$standalone = isset($standalone) ? $standalone : false;
$standalone_title = isset($standalone_title) ? $standalone_title : 'إدارة النظام وتفضيلات المتجر';
$standalone_eyebrow = isset($standalone_eyebrow) ? $standalone_eyebrow : 'لوحة التحكم — إعدادات النظام';
$standalone_desc = isset($standalone_desc) ? $standalone_desc : 'تهيئة إعدادات المتجر العامة، الضرائب، الباركود، أسعار الصرف، وصلاحيات المجموعات الوظيفية.';
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'store';
?>
<title><?php echo htmlspecialchars($standalone_title); ?> - <?php echo $company_info['comp_name'] ?? ''; ?></title>

<style>
:root {
    --ink-900: #0f1b2d;
    --ink-700: #1e3148;
    --ink-500: #46607e;
    --ink-300: #8fa3ba;
    --line: #e1e7ee;
    --surface: #ffffff;
    --surface-soft: #f6f8fb;
    --accent: #1d4ed8;
    --accent-dark: #1e3a8a;
    --accent-soft: #eaf0ff;
    --gold: #b9892f;
    --good: #15803d;
    --good-soft: #ecfdf3;
    --bad: #b91c1c;
    --bad-soft: #fef2f2;
    --radius: 4px;
}

.settings-shell { font-family: inherit; }

.settings-head {
    border-bottom: 2px solid var(--ink-900);
    padding-bottom: 16px;
    margin-bottom: 24px;
}
.settings-head .eyebrow {
    text-transform: uppercase;
    letter-spacing: .08em;
    font-size: 11px;
    font-weight: 700;
    color: var(--accent);
    margin-bottom: 4px;
    display: block;
}
.settings-head h3 {
    color: var(--ink-900);
    font-weight: 800;
    margin-bottom: 2px;
}
.settings-head .icon-chip {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 38px;
    height: 38px;
    border-radius: 8px;
    background: var(--accent-soft);
    color: var(--accent-dark);
    margin-left: 10px;
}
.settings-head p { color: var(--ink-500); }

.btn-back-settings {
    border: 1px solid var(--line);
    color: var(--ink-700);
    background: var(--surface);
    padding: 8px 16px;
    border-radius: var(--radius);
    font-weight: 700;
    font-size: 13px;
    transition: all .15s ease;
}
.btn-back-settings:hover { background: var(--surface-soft); color: var(--ink-900); text-decoration: none; }

.alert-formal {
    border-radius: var(--radius);
    border: 1px solid;
    padding: 13px 18px;
    font-weight: 600;
    font-size: 14px;
}
.alert-formal.is-error { background: var(--bad-soft); border-color: #fecaca; color: var(--bad); }
.alert-formal.is-success { background: var(--good-soft); border-color: #bbf7d0; color: var(--good); }

.nav-tabs-custom {
    margin-bottom: 0;
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
    border: none;
    padding-left: 0;
    list-style: none;
    background: var(--ink-900);
    border-radius: 8px 8px 0 0;
    padding: 6px;
}
.nav-tabs-custom .nav-item { margin-bottom: 0; }
.nav-tabs-custom .nav-link {
    border-radius: 5px !important;
    border: none !important;
    font-weight: 700;
    font-size: 13.5px;
    color: #c3cedb !important;
    padding: 11px 18px;
    background: transparent;
    transition: all 0.15s ease-in-out;
    display: inline-flex;
    align-items: center;
}
.nav-tabs-custom .nav-link i,
.nav-tabs-custom .nav-link svg { opacity: .85; }
.nav-tabs-custom .nav-link:hover {
    color: #fff !important;
    background-color: rgba(255,255,255,0.08);
}
.nav-tabs-custom .nav-link.active {
    background-color: var(--accent) !important;
    color: #ffffff !important;
}

.tab-content-custom {
    background: var(--surface);
    padding: 0;
    border: 1px solid var(--line);
    border-top: none;
    border-radius: 0 0 8px 8px;
    box-shadow: 0 1px 2px rgba(15,27,45,0.04);
}
.tab-pane-inner { padding: 32px; }

.section-heading {
    color: var(--ink-900);
    font-weight: 800;
    font-size: 15px;
    text-transform: uppercase;
    letter-spacing: .03em;
    border-bottom: 1px solid var(--line);
    padding-bottom: 12px;
    margin-bottom: 24px;
    display: flex;
    align-items: center;
    gap: 8px;
}
.section-heading::before {
    content: "";
    width: 4px;
    height: 16px;
    background: var(--accent);
    display: inline-block;
    border-radius: 2px;
}
.section-subheading {
    color: var(--ink-700);
    font-weight: 700;
    font-size: 13.5px;
    border-bottom: 1px solid var(--line);
    padding-bottom: 10px;
    margin-bottom: 18px;
}

.field-label {
    font-weight: 700;
    font-size: 12.5px;
    color: var(--ink-700);
    margin-bottom: 7px;
    display: block;
    letter-spacing: .01em;
}
.field-hint {
    color: var(--ink-300);
    font-size: 11.5px;
    margin-top: 5px;
    display: block;
}
.form-control.rounded-0 {
    border: 1px solid var(--line);
    border-radius: var(--radius);
    font-size: 13.5px;
    color: var(--ink-900);
    padding: 9px 12px;
    height: auto;
    transition: border-color .15s ease, box-shadow .15s ease;
}
.form-control.rounded-0:focus {
    border-color: var(--accent);
    box-shadow: 0 0 0 3px var(--accent-soft);
}

.formal-card {
    border: 1px solid var(--line);
    border-radius: 6px;
    background: var(--surface);
    overflow: hidden;
}
.formal-card-head {
    background: var(--ink-900);
    color: #fff;
    padding: 13px 18px;
    font-weight: 700;
    font-size: 13.5px;
    display: flex;
    align-items: center;
    gap: 8px;
    letter-spacing: .01em;
}
.formal-card-head.is-accent { background: var(--ink-700); color: #e8f0fb; }
.formal-card-body { padding: 20px; }

.kv-row {
    display: flex;
    justify-content: space-between;
    padding: 9px 0;
    border-bottom: 1px dashed var(--line);
    font-size: 13px;
}
.kv-row:last-child { border-bottom: none; }
.kv-row .k { color: var(--ink-500); font-weight: 700; }
.kv-row .v { color: var(--ink-900); font-weight: 700; }

.badge-formal {
    display: inline-block;
    padding: 3px 11px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: .02em;
}
.badge-formal.success { background: var(--good-soft); color: var(--good); }
.badge-formal.info { background: var(--accent-soft); color: var(--accent-dark); }
.badge-formal.secondary { background: var(--surface-soft); color: var(--ink-500); border: 1px solid var(--line); }
.badge-formal.danger { background: var(--bad-soft); color: var(--bad); }

.table-formal {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}
.table-formal thead th {
    background: var(--surface-soft);
    color: var(--ink-700);
    font-weight: 700;
    font-size: 11.5px;
    text-transform: uppercase;
    letter-spacing: .03em;
    padding: 11px 14px;
    border-bottom: 2px solid var(--line);
    text-align: right;
}
.table-formal tbody td {
    padding: 11px 14px;
    border-bottom: 1px solid var(--line);
    color: var(--ink-900);
    vertical-align: middle;
}
.table-formal tbody tr:hover { background: var(--surface-soft); }

.permission-card {
    border: 1px solid var(--line);
    border-radius: 6px;
    padding: 20px;
    background: var(--surface);
    height: 100%;
}
.permission-card-head {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 18px;
    padding-bottom: 14px;
    border-bottom: 1px solid var(--line);
}
.permission-card-head .dot {
    width: 10px; height: 10px; border-radius: 50%;
}
.permission-card-head.role-cashier .dot { background: var(--accent); }
.permission-card-head.role-inventory .dot { background: var(--gold); }
.permission-card-head h5 { margin: 0; font-weight: 800; font-size: 14.5px; color: var(--ink-900); }
.permission-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 9px 4px;
    border-bottom: 1px solid #f1f4f8;
    font-size: 13px;
    font-weight: 600;
    color: var(--ink-700);
}
.permission-item:last-child { border-bottom: none; }

.btn-formal-primary {
    background: var(--accent);
    color: #fff;
    border: 1px solid var(--accent);
    border-radius: var(--radius);
    font-weight: 700;
    font-size: 13px;
    padding: 10px 22px;
    transition: background .15s ease;
}
.btn-formal-primary:hover { background: var(--accent-dark); color: #fff; }
.btn-formal-secondary {
    background: var(--surface);
    color: var(--ink-700);
    border: 1px solid var(--line);
    border-radius: var(--radius);
    font-weight: 700;
    font-size: 13px;
    padding: 10px 22px;
}
.btn-formal-secondary:hover { background: var(--surface-soft); color: var(--ink-900); }
.btn-formal-success {
    background: var(--good);
    color: #fff;
    border: 1px solid var(--good);
    border-radius: var(--radius);
    font-weight: 700;
    font-size: 13px;
    padding: 10px 22px;
}
.btn-formal-success:hover { background: #126b34; color: #fff; }

.mono-box {
    font-family: 'Courier New', monospace;
    font-size: 12px;
    background: var(--surface-soft);
    border: 1px dashed var(--line);
    border-radius: var(--radius);
    padding: 10px 14px;
    color: var(--ink-700);
    word-break: break-all;
}

.report-toggle-group { display: flex; flex-wrap: wrap; gap: 18px; }
.report-toggle-group .form-check { display: flex; align-items: center; gap: 6px; margin: 0; }

@media (max-width: 768px) {
    .tab-pane-inner { padding: 20px; }
    .nav-tabs-custom { overflow-x: auto; flex-wrap: nowrap; }
}
</style>

<div class="settings-shell">

<div class="row mb-3 no-print align-items-center">
    <div class="col-md-7">
        <span class="eyebrow"><?php echo htmlspecialchars($standalone_eyebrow); ?></span>
        <h3 class="mb-1">
            <span class="icon-chip"><?php echo get_icon('cog'); ?></span>
            <?php echo htmlspecialchars($standalone_title); ?>
        </h3>
        <p class="text-muted small mb-0"><?php echo htmlspecialchars($standalone_desc); ?></p>
    </div>
    <div class="col-md-5 text-left">
        <button type="button" id="support-tools-button-top" class="btn btn-warning btn-sm ml-2 font-weight-bold support-tools-trigger" style="background-color: var(--gold); border-color: var(--gold); color: #fff; padding: 8px 16.5px; border-radius: var(--radius); font-size: 13px; outline: none; border: 1px solid var(--gold);">
            <i class="fa fa-wrench ml-1"></i> للدعم الفني
        </button>
        <a href="../home.php" class="btn-back-settings text-decoration-none">
            <?php echo get_icon('logout', 'ml-1'); ?> عودة للرئيسية
        </a>
    </div>
</div>

<div class="settings-head"></div>

<div class="row justify-content-center no-print">
    <div class="col-lg-11">
        <?php if (!empty($error)): ?>
            <div class="alert-formal is-error mb-4"><?php echo $error; ?></div>
        <?php endif; ?>

        <?php if (!empty($success)): ?>
            <div class="alert-formal is-success mb-4"><?php echo $success; ?></div>
        <?php endif; ?>

        <?php if (!$standalone): ?>
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
            <li class="nav-item">
                <a class="nav-link" id="assistant-tab" data-toggle="tab" href="#assistant" role="tab" aria-selected="false">
                    <i class="bi bi-robot ml-1"></i> الذكاء الاصطناعي والمساعد
                </a>
            </li>
        </ul>
        <?php endif; ?>

        <div class="tab-content tab-content-custom mb-5">
            <!-- 1. تبويب بيانات المتجر والطباعة -->
            <div class="tab-pane fade <?php echo ($active_tab == 'store') ? 'show active' : ''; ?>" id="store" role="tabpanel">
                <div class="tab-pane-inner">
                <form method="POST" enctype="multipart/form-data">
                    <h5 class="section-heading">بيانات الفواتير والطباعة</h5>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="field-label">اسم المتجر / الشركة *</label>
                            <input type="text" name="store_name" class="form-control rounded-0" value="<?php echo htmlspecialchars($settings['store_name']); ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="field-label">اسم المتجر بالإنجليزية </label>
                            <input type="text" name="store_name_en" class="form-control rounded-0" value="<?php echo htmlspecialchars($settings['store_name_en'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="field-label">رقم هاتف الفاتورة</label>
                            <input type="text" name="phone" class="form-control rounded-0" value="<?php echo htmlspecialchars($settings['phone']); ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="field-label">رقم الهاتف بالإنجليزية</label>
                            <input type="text" name="phone_en" class="form-control rounded-0" value="<?php echo htmlspecialchars($settings['phone_en'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="field-label">شعار المتجر / الشركة (Logo)</label>
                            <input type="file" name="logo_file" class="form-control rounded-0">
                            <?php if (!empty($settings['logo'])): ?>
                                <div class="mt-2">
                                    <img src="<?php echo $prefix . htmlspecialchars($settings['logo']); ?>" style="max-height: 70px; border: 1px solid var(--line); padding: 4px; background: #fff; border-radius: 4px;">
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="field-label">العملة الافتراضية للنظام (الأساسية)</label>
                            <input type="text" name="currency" class="form-control rounded-0 bg-light" value="<?php echo htmlspecialchars($settings['currency']); ?>" readonly>
                            <span class="field-hint">العملة المرجعية الأساسية لكل حسابات الخزينة وتقارير الأرباح.</span>
                        </div>
                        <div class="col-md-12 mb-3">
                            <label class="field-label">تنسيق فواتير المبيعات</label>
                            <select name="printer_type" class="form-control rounded-0">
                                <option value="receipt_80mm" <?php echo ($settings['printer_type'] === 'receipt_80mm') ? 'selected' : ''; ?>>فاتورة حرارية POS قياس 80 مم (عرض متناسق وضيق)</option>
                                <option value="receipt_58mm" <?php echo ($settings['printer_type'] === 'receipt_58mm') ? 'selected' : ''; ?>>فاتورة حرارية POS قياس 58 مم (عرض ضيق جداً)</option>
                                <option value="standard_a4" <?php echo ($settings['printer_type'] === 'standard_a4') ? 'selected' : ''; ?>>ورق قياسي A4 (كامل عرض الصفحة للتقارير)</option>
                            </select>
                        </div>
                        <div class="col-md-12 mb-3">
                            <label class="field-label">عنوان المتجر (يظهر في ترويسة الفاتورة)</label>
                            <input type="text" name="address" class="form-control rounded-0" value="<?php echo htmlspecialchars($settings['address']); ?>">
                        </div>
                        <div class="col-md-12 mb-3">
                            <label class="field-label">عنوان المتجر بالإنجليزية</label>
                            <input type="text" name="address_en" class="form-control rounded-0" value="<?php echo htmlspecialchars($settings['address_en'] ?? ''); ?>">
                        </div>
                        <div class="col-md-12 mb-3">
                            <label class="field-label">رسالة تذييل الفاتورة (تظهر أسفل فاتورة العميل)</label>
                            <textarea name="receipt_footer" class="form-control rounded-0" rows="3"><?php echo htmlspecialchars($settings['receipt_footer']); ?></textarea>
                        </div>
                        
                        <!-- إعدادات ترويسة التقارير الرسمية -->
                        <div class="col-md-12 my-3">
                            <h6 class="section-subheading">تخصيص ترويسة التقارير الرسمية</h6>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="field-label">العنوان الفرعي للتقارير</label>
                            <input type="text" name="report_header_subtitle" class="form-control rounded-0" value="<?php echo htmlspecialchars($settings['report_header_subtitle'] ?? ''); ?>" placeholder="مثال: قسم المبيعات والحسابات العامة">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="field-label">رقم السجل التجاري (C.R.)</label>
                            <input type="text" name="commercial_register" class="form-control rounded-0" value="<?php echo htmlspecialchars($settings['commercial_register'] ?? ''); ?>" placeholder="أدخل رقم السجل التجاري">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="field-label">الرقم الضريبي (Tax ID)</label>
                            <input type="text" name="tax_number" class="form-control rounded-0" value="<?php echo htmlspecialchars($settings['tax_number'] ?? ''); ?>" placeholder="أدخل الرقم الضريبي">
                        </div>
                        <!-- <div class="col-md-6 mb-3">
                            <label class="field-label">رمز الوصول للدعم الفني (Access Key) *</label>
                            <input type="text" name="support_token" class="form-control rounded-0" value="<?php echo htmlspecialchars($settings['support_token'] ?? 'ReplaceWithStrongSupportToken123!'); ?>" placeholder="رمز الدعم الفني الخاص بك" required>
                            <span class="field-hint">الرمز المطلوب للدخول إلى أدوات الدعم الفني المحلي (الافتراضي: ReplaceWithStrongSupportToken123!).</span>
                        </div> -->
                        <div class="col-md-6 mb-3">
                            <label class="field-label d-block">خيارات العرض في الترويسة</label>
                            <div class="report-toggle-group mt-2">
                                <div class="form-check">
                                    <input type="checkbox" name="report_show_logo" id="report_show_logo" class="form-check-input" value="1" <?php echo (!isset($settings['report_show_logo']) || $settings['report_show_logo'] == 1) ? 'checked' : ''; ?>>
                                    <label class="form-check-label font-weight-bold text-secondary mr-1" for="report_show_logo">شعار المتجر</label>
                                </div>
                                <div class="form-check">
                                    <input type="checkbox" name="report_show_cr" id="report_show_cr" class="form-check-input" value="1" <?php echo (!isset($settings['report_show_cr']) || $settings['report_show_cr'] == 1) ? 'checked' : ''; ?>>
                                    <label class="form-check-label font-weight-bold text-secondary mr-1" for="report_show_cr">السجل التجاري</label>
                                </div>
                                <div class="form-check">
                                    <input type="checkbox" name="report_show_tax" id="report_show_tax" class="form-check-input" value="1" <?php echo (!isset($settings['report_show_tax']) || $settings['report_show_tax'] == 1) ? 'checked' : ''; ?>>
                                    <label class="form-check-label font-weight-bold text-secondary mr-1" for="report_show_tax">الرقم الضريبي</label>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-12 mb-3">
                            <label class="field-label">ملاحظات/شروط ترويسة التقارير الرسمية</label>
                            <textarea name="report_header_notes" class="form-control rounded-0" rows="3" placeholder="تكتب هنا الملاحظات أو البنود المرجعية التي تظهر في ترويسة التقارير..."><?php echo htmlspecialchars($settings['report_header_notes'] ?? ''); ?></textarea>
                        </div>
                    </div>
                    <div class="text-left mt-3 pt-3" style="border-top:1px solid var(--line);">
                        <button type="submit" name="btn_save_store" class="btn-formal-primary">
                            <?php echo get_icon('check', 'ml-1'); ?> حفظ تفضيلات المتجر
                        </button>
                    </div>
                </form>
                </div>
            </div>

            <!-- 2. تبويب السياسات والباركود -->
            <div class="tab-pane fade <?php echo ($active_tab == 'policies') ? 'show active' : ''; ?>" id="policies" role="tabpanel">
                <div class="tab-pane-inner">
                <form method="POST">
                    <h5 class="section-heading">السياسات المالية والباركود</h5>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="field-label">نسبة الضريبة المضافة والـ VAT (%)</label>
                            <input type="number" step="any" min="0" name="tax_percent" class="form-control rounded-0" value="<?php echo htmlspecialchars($settings['tax_percent']); ?>">
                            <span class="field-hint">تضاف تلقائياً لحساب مبيعات التجزئة عند تسجيل فواتير بيع جديدة.</span>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="field-label">حد تنبيه نقص الكميات بالمخزن</label>
                            <input type="number" min="1" name="low_stock_threshold" class="form-control rounded-0" value="<?php echo htmlspecialchars($settings['low_stock_threshold']); ?>">
                            <span class="field-hint">يقوم النظام بتنبيه أمين المستودع باللون الأحمر إذا نقصت كمية المنتج عن هذا الرقم.</span>
                        </div>
                        <div class="col-md-12 mb-3">
                            <label class="field-label">قارئ الباركود الإلكتروني في المبيعات</label>
                            <select name="barcode_scanner" class="form-control rounded-0">
                                <option value="1" <?php echo ($settings['barcode_scanner'] == 1) ? 'selected' : ''; ?>>مفعل (فتح حقل المسح التلقائي والفوري للمنتج في الفاتورة)</option>
                                <option value="0" <?php echo ($settings['barcode_scanner'] == 0) ? 'selected' : ''; ?>>معطل</option>
                            </select>
                        </div>
                    </div>
                    <div class="text-left mt-3 pt-3" style="border-top:1px solid var(--line);">
                        <button type="submit" name="btn_save_policies" class="btn-formal-primary">
                            <?php echo get_icon('check', 'ml-1'); ?> حفظ سياسات النظام
                        </button>
                    </div>
                </form>
                </div>
            </div>

            <!-- 3. تبويب العملات والصرف -->
            <div class="tab-pane fade <?php echo ($active_tab == 'currencies') ? 'show active' : ''; ?>" id="currencies" role="tabpanel">
                <div class="tab-pane-inner">
                <div class="row">
                    <!-- تعديل أسعار الصرف الحالية -->
                    <div class="col-md-7 mb-4">
                        <form method="POST">
                            <h5 class="section-heading">قائمة العملات وأسعار الصرف الحالية</h5>
                            <div class="table-responsive">
                                <table class="table-formal">
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
                                                        <input type="number" step="any" min="0.0001" name="rate[<?php echo $curr['id']; ?>]" class="form-control form-control-sm rounded-0 text-center font-weight-bold" value="<?php echo $curr['exchange_rate']; ?>">
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php echo ($curr['is_base'] == 1) ? '<span class="badge-formal success">أساسية</span>' : '<span class="badge-formal secondary">أجنبية</span>'; ?>
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
                                <button type="submit" name="btn_update_rates" class="btn-formal-primary">
                                    <?php echo get_icon('check', 'ml-1'); ?> تحديث أسعار الصرف
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- إضافة عملة جديدة -->
                    <div class="col-md-5 mb-4">
                        <div class="formal-card">
                            <div class="formal-card-head">
                                <?php echo get_icon('plus'); ?> إضافة عملة صرافة جديدة
                            </div>
                            <div class="formal-card-body">
                                <form method="POST">
                                    <div class="form-group mb-3">
                                        <label class="field-label">اسم العملة *</label>
                                        <input type="text" name="curr_name" class="form-control rounded-0" placeholder="مثال: دولار أمريكي" required>
                                    </div>
                                    <div class="row">
                                        <div class="col-md-6 form-group mb-3">
                                            <label class="field-label">رمز العملة *</label>
                                            <input type="text" name="curr_code" class="form-control rounded-0 text-center" placeholder="مثال: USD" required>
                                        </div>
                                        <div class="col-md-6 form-group mb-3">
                                            <label class="field-label">العلامة *</label>
                                            <input type="text" name="curr_symbol" class="form-control rounded-0 text-center" placeholder="مثال: $" required>
                                        </div>
                                    </div>
                                    <div class="form-group mb-3">
                                        <label class="field-label">سعر الصرف (قيمة الوحدة بـ ر.ي) *</label>
                                        <input type="number" step="any" name="curr_rate" class="form-control rounded-0 text-center font-weight-bold" placeholder="مثال: 530" required>
                                    </div>
                                    <button type="submit" name="btn_add_currency" class="btn-formal-success btn-block mt-3">
                                        <?php echo get_icon('plus', 'ml-1'); ?> تسجيل العملة الجديدة
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
                </div>
            </div>

            <!-- 4. تبويب صلاحيات الموظفين -->
            <div class="tab-pane fade <?php echo ($active_tab == 'permissions') ? 'show active' : ''; ?>" id="permissions" role="tabpanel">
                <div class="tab-pane-inner">
                <form method="POST">
                    <h5 class="section-heading">تخصيص صلاحيات الوصول والمجموعات</h5>
                    
                    <div class="row">
                        <!-- صلاحيات البائع / الكاشير -->
                        <div class="col-md-6 mb-4">
                            <div class="permission-card">
                                <div class="permission-card-head role-cashier">
                                    <span class="dot"></span>
                                    <h5>صلاحيات دور الكاشير / البائع (Cashier)</h5>
                                </div>
                                <?php 
                                $cashier_active = array_map('trim', explode(',', $settings['cashier_permissions']));
                                foreach ($system_modules as $mod_key => $mod_name):
                                    $checked = in_array($mod_key, $cashier_active) ? 'checked' : '';
                                ?>
                                    <div class="permission-item">
                                        <input type="checkbox" name="cashier_perms[]" value="<?php echo $mod_key; ?>" class="m-0" id="cashier_<?php echo $mod_key; ?>" <?php echo $checked; ?>>
                                        <label class="m-0 font-weight-bold text-secondary" for="cashier_<?php echo $mod_key; ?>" style="cursor:pointer;"><?php echo $mod_name; ?></label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- صلاحيات أمين المستودع -->
                        <div class="col-md-6 mb-4">
                            <div class="permission-card">
                                <div class="permission-card-head role-inventory">
                                    <span class="dot"></span>
                                    <h5>صلاحيات دور أمين المستودع (Inventory)</h5>
                                </div>
                                <?php 
                                $inventory_active = array_map('trim', explode(',', $settings['inventory_permissions']));
                                foreach ($system_modules as $mod_key => $mod_name):
                                    $checked = in_array($mod_key, $inventory_active) ? 'checked' : '';
                                ?>
                                    <div class="permission-item">
                                        <input type="checkbox" name="inventory_perms[]" value="<?php echo $mod_key; ?>" class="m-0" id="inventory_<?php echo $mod_key; ?>" <?php echo $checked; ?>>
                                        <label class="m-0 font-weight-bold text-secondary" for="inventory_<?php echo $mod_key; ?>" style="cursor:pointer;"><?php echo $mod_name; ?></label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <div class="text-left mt-3 pt-3" style="border-top:1px solid var(--line);">
                        <button type="submit" name="btn_save_permissions" class="btn-formal-primary">
                            <?php echo get_icon('check', 'ml-1'); ?> تعميم وحفظ الصلاحيات لكل الأقسام
                        </button>
                    </div>
                </form>
                </div>
            </div>

            <!-- 5. تبويب النسخ الاحتياطي -->
            <div class="tab-pane fade <?php echo ($active_tab == 'backups') ? 'show active' : ''; ?>" id="backups" role="tabpanel">
                <div class="tab-pane-inner">
                <div class="row">
                    <div class="col-md-4 mb-4">
                        <div class="formal-card">
                            <div class="formal-card-head">
                                <i class="fa fa-download"></i> إنشاء نسخة احتياطية جديدة
                            </div>
                            <div class="formal-card-body text-right">
                                <p class="small text-muted">
                                    يقوم محرك النسخ الاحتياطي بتصدير الهيكل والبيانات الخاصة بقاعدة البيانات بالكامل، بالإضافة لنسخ جميع الملفات المرفوعة وحفظها في أرشيف مضغوط واحد.
                                </p>
                                <form method="POST">
                                    <button type="submit" name="btn_create_backup" class="btn-formal-success btn-block mt-4">
                                        <i class="fa fa-download ml-1"></i> تشغيل النسخ الاحتياطي الآن
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-8 mb-4">
                        <h5 class="section-heading">أحدث 10 نسخ احتياطية مسجلة</h5>
                        <div class="table-responsive">
                            <table class="table-formal text-right">
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
                                                    <span class="badge-formal info"><?php echo htmlspecialchars($bk['backup_type']); ?></span>
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
            </div>

            <!-- 6. تبويب الترخيص والتحديثات -->
            <div class="tab-pane fade <?php echo ($active_tab == 'updates') ? 'show active' : ''; ?>" id="updates" role="tabpanel">
                <div class="tab-pane-inner">
                <div class="row">
                    <!-- معلومات وتجديد الترخيص -->
                    <div class="col-md-6 mb-4">
                        <div class="formal-card text-right">
                            <div class="formal-card-head is-accent">
                                <i class="fa fa-key"></i> معلومات الترخيص التجاري الحالي
                            </div>
                            <div class="formal-card-body">
                                <?php if ($license_info): ?>
                                    <div class="kv-row">
                                        <span class="k">اسم المنشأة</span>
                                        <span class="v"><?php echo htmlspecialchars($license_info['company_name']); ?></span>
                                    </div>
                                    <div class="kv-row">
                                        <span class="k">المالك</span>
                                        <span class="v"><?php echo htmlspecialchars($license_info['owner_name']); ?></span>
                                    </div>
                                    <div class="kv-row">
                                        <span class="k">نوع الباقة</span>
                                        <span class="v"><span class="badge-formal success"><?php echo strtoupper($license_info['license_type']); ?></span></span>
                                    </div>
                                    <div class="kv-row">
                                        <span class="k">تاريخ الانتهاء</span>
                                        <span class="v" style="color:var(--bad);"><?php echo htmlspecialchars($license_info['expiry_date']); ?></span>
                                    </div>
                                    <div class="kv-row">
                                        <span class="k">أقصى عدد فروع</span>
                                        <span class="v"><?php echo htmlspecialchars($license_info['max_branches']); ?> فروع</span>
                                    </div>
                                    <div class="kv-row">
                                        <span class="k">أقصى عدد مستخدمين</span>
                                        <span class="v"><?php echo htmlspecialchars($license_info['max_users']); ?> مستخدمين</span>
                                    </div>
                                    <div class="kv-row">
                                        <span class="k">الموديولات المفعلة</span>
                                        <span class="v" style="color:var(--accent-dark);"><?php echo htmlspecialchars($license_info['modules_enabled']); ?></span>
                                    </div>
                                <?php else: ?>
                                    <div class="alert-formal is-error text-center small mb-0">
                                        لم يتم تسجيل بيانات الترخيص بقاعدة البيانات بشكل كامل بعد.
                                    </div>
                                <?php endif; ?>

                                <hr>
                                <div class="mono-box mb-3">
                                    <strong>Machine ID:</strong> <?php echo $machineId; ?>
                                </div>

                                <div id="license-ajax-error" class="alert-formal is-error mb-3 small" style="display:none;"></div>
                                <div id="license-ajax-success" class="alert-formal is-success mb-3 small" style="display:none;"></div>
                                <div id="license-ajax-loading" class="alert-formal small mb-3" style="display:none; background:var(--accent-soft); border-color:#c7d7fb; color:var(--accent-dark);">
                                    <i class="fa fa-refresh fa-spin ml-2"></i> جاري التحقق من كود التفعيل وتنشيط الترخيص... يرجى الانتظار
                                </div>
                                <form id="settings-license-form">
                                    <div class="form-group mb-2">
                                        <label class="field-label">تجديد أو ترقية الترخيص (إدخال كود التفعيل النصي)</label>
                                        <textarea id="new_activation_code" class="form-control rounded-0" rows="3" placeholder="قم بلصق كود التفعيل النصي الطويل المستلم من الشركة هنا..." style="font-family:monospace; font-size:0.8rem;"></textarea>
                                    </div>
                                    <div class="form-group mb-3">
                                        <label class="field-label">أو اختر ملف الترخيص (.AQNEX) مباشرة:</label>
                                        <div class="custom-file">
                                            <input type="file" class="custom-file-input" id="new_license_file" accept=".AQNEX">
                                            <label class="custom-file-label text-right" for="new_license_file" id="new_license_file_label" style="font-size:0.85rem;">اختر ملف الترخيص...</label>
                                        </div>
                                    </div>
                                    <button type="submit" class="btn-formal-secondary btn-block">
                                        <i class="fa fa-key"></i> تطبيق كود أو ملف الترخيص الجديد
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- التحديثات والترقيات -->
                    <div class="col-md-6 mb-4 text-right">
                        <div class="formal-card">
                            <div class="formal-card-head is-accent">
                                <i class="fa fa-refresh"></i> محرك ترقية وتحديث النظام
                            </div>
                            <div class="formal-card-body">
                                <p class="small text-muted">
                                    يمكنك ترقية ميزات النظام وإصلاح الأخطاء عن طريق رفع حزمة تحديث مخصصة موقعة رقمياً من شركة AQNEX بامتداد <strong style="color:var(--accent-dark);">update.AQNEX</strong>.
                                </p>
                                <form method="POST" enctype="multipart/form-data">
                                    <div class="form-group mb-3">
                                        <label class="field-label">اختر ملف التحديث الجديد</label>
                                        <input type="file" name="update_file" class="form-control rounded-0" accept=".AQNEX" style="height:auto; padding:9px 12px;" required>
                                    </div>
                                    <button type="submit" name="btn_apply_update" class="btn-formal-primary btn-block">
                                        <i class="fa fa-upload"></i> تثبيت الترقية وتطبيق SQL Migration
                                    </button>
                                </form>

                                <h6 class="section-subheading mt-4">تاريخ آخر التحديثات المثبتة</h6>
                                <div class="table-responsive">
                                    <table class="table-formal text-right small">
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
                                                                <span class="badge-formal success">ناجح</span>
                                                            <?php else: ?>
                                                                <span class="badge-formal danger">فشل</span>
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
            
            <!-- 7. تبويب المساعد الذكي والذكاء الاصطناعي -->
            <div class="tab-pane fade <?php echo ($active_tab == 'assistant') ? 'show active' : ''; ?>" id="assistant" role="tabpanel">
                <div class="tab-pane-inner">
                <form method="POST">
                    <h5 class="section-heading"><i class="bi bi-robot"></i> إعداد الاتصال بـ Gemini API للساعد الذكي</h5>
                    <div class="row">
                        <div class="col-md-12 mb-3">
                            <label class="field-label">مفتاح Gemini API (API Key) *</label>
                            <input type="text" name="gemini_api_key" class="form-control rounded-0" value="<?php echo htmlspecialchars($settings['gemini_api_key'] ?? ''); ?>" placeholder="AIzaSy..." required>
                            <small class="text-muted">
                                يمكنك الحصول على مفتاح API مجاني أو مدفوع من منصة <a href="https://aistudio.google.com/" target="_blank">Google AI Studio</a>.
                            </small>
                        </div>
                    </div>
                    <hr class="my-4">
                    <div class="text-right">
                        <button type="submit" name="btn_save_gemini" class="btn-formal-primary">
                            <?php echo get_icon('save', 'ml-1'); ?> حفظ مفتاح API
                        </button>
                    </div>
                </form>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="text-left mt-3">
    <button id="support-tools-button-bottom" type="button" class="btn btn-outline-primary btn-sm rounded-0 support-tools-trigger">
        <i class="bi bi-tools ml-1"></i> أدوات الدعم (F2)
    </button>
</div>

</div>

<script>
// =======================================================================
// ملاحظة مهمة: هذا الكود لا يعتمد على jQuery إطلاقاً (Vanilla JS بالكامل)
// حتى يعمل بشكل مستقل عن ترتيب/توقيت تحميل مكتبة jQuery في الصفحة.
// =======================================================================

// -------------------------------------------------------------------
// 1. أدوات الدعم الفني (F2 / Ctrl+Shift+S / أزرار الدعم الفني)
//    هذا الجزء مسجّل فوراً، خارج أي انتظار لتحميل عناصر أو مكتبات أخرى
// -------------------------------------------------------------------
function openSupportTools() {
    var supportKey = window.prompt('Enter the local support access key:');

    // المستخدم ضغط Cancel أو ترك الحقل فارغاً
    if (supportKey === null || supportKey.trim() === '') {
        return;
    }

    var supportUrl = '<?php echo htmlspecialchars($dir_prefix, ENT_QUOTES); ?>support_tools/index.php?auth=' + encodeURIComponent(supportKey.trim());
    window.location.href = supportUrl;
}

// معالج لوحة المفاتيح: F2 أو Ctrl+Shift+S
document.addEventListener('keydown', function(e) {
    var isF2 = (e.key === 'F2' || e.keyCode === 113);
    var isCtrlShiftS = e.ctrlKey && e.shiftKey && (e.key === 'S' || e.key === 's');

    if (isF2 || isCtrlShiftS) {
        e.preventDefault();
        openSupportTools();
    }
});

// معالج النقر على أزرار الدعم الفني (عبر تفويض الحدث على document، يعمل فوراً
// بدون انتظار DOMContentLoaded ويغطي أي زر بنفس الكلاس حتى لو أضيف لاحقاً)
document.addEventListener('click', function(e) {
    var btn = e.target.closest('.support-tools-trigger');
    if (btn) {
        e.preventDefault();
        openSupportTools();
    }
});

// -------------------------------------------------------------------
// 2. باقي وظائف الصفحة (تبويبات، ترخيص...) — تعمل بعد جاهزية الـ DOM
// -------------------------------------------------------------------
document.addEventListener('DOMContentLoaded', function() {

    // تفعيل التبويب المحدد من الرابط إن وجد (مثل tab=currencies)
    var urlParams = new URLSearchParams(window.location.search);
    var tabParam = urlParams.get('tab');
    if (tabParam) {
        var targetTabLink = document.getElementById(tabParam + '-tab');
        if (targetTabLink) {
            targetTabLink.click();
        }
    }

    // تحديث اسم الملف المختار في الواجهة
    var licenseFileInput = document.getElementById('new_license_file');
    var licenseFileLabel = document.getElementById('new_license_file_label');
    if (licenseFileInput && licenseFileLabel) {
        licenseFileInput.addEventListener('change', function() {
            var fileName = this.value.split('\\').pop();
            if (fileName) {
                licenseFileLabel.classList.add('selected');
                licenseFileLabel.textContent = fileName;
            } else {
                licenseFileLabel.classList.remove('selected');
                licenseFileLabel.textContent = 'اختر ملف الترخيص...';
            }
        });
    }

    function showLicenseBox(id, message) {
        ['license-ajax-error', 'license-ajax-success', 'license-ajax-loading'].forEach(function(boxId) {
            var box = document.getElementById(boxId);
            if (box) box.style.display = 'none';
        });
        var target = document.getElementById(id);
        if (target) {
            if (message !== undefined) target.textContent = message;
            target.style.display = 'block';
        }
    }

    function sendUpdateLicenseAjax(codeVal) {
        showLicenseBox('license-ajax-loading');

        var formData = new URLSearchParams();
        formData.append('ajax_update_license', '1');
        formData.append('activation_code', codeVal);

        fetch('index.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: formData.toString()
        })
        .then(function(response) { return response.json(); })
        .then(function(data) {
            if (data.status === 'success') {
                showLicenseBox('license-ajax-success', data.message);
                var codeField = document.getElementById('new_activation_code');
                if (codeField) codeField.value = '';
                setTimeout(function() {
                    window.location.reload();
                }, 2000);
            } else {
                showLicenseBox('license-ajax-error', data.message);
            }
        })
        .catch(function(error) {
            showLicenseBox('license-ajax-error', 'حدث خطأ أثناء التفعيل والاتصال بالخادم: ' + error);
        });
    }

    var licenseForm = document.getElementById('settings-license-form');
    if (licenseForm) {
        licenseForm.addEventListener('submit', function(e) {
            e.preventDefault();

            var codeField = document.getElementById('new_activation_code');
            var codeVal = codeField ? codeField.value.trim() : '';
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
                            alert('ملف الترخيص المرفق غير صالح ولا يحتوي على بيانات التوقيع.');
                            return;
                        }
                    } catch (e) {
                        // التحقق إذا كان الملف مشفر بيس64 بالفعل
                        try {
                            var decoded = atob(text);
                            var parsedDecoded = JSON.parse(decoded);
                            if (parsedDecoded.payload && parsedDecoded.signature) {
                                processedCode = text;
                            } else {
                                alert('محتوى ملف الترخيص المرفق غير صالح.');
                                return;
                            }
                        } catch (err) {
                            alert('ملف الترخيص المرفق غير صالح أو تالف.');
                            return;
                        }
                    }

                    sendUpdateLicenseAjax(processedCode);
                };
                reader.readAsText(file);
            } else {
                if (codeVal === '') {
                    alert('يرجى إدخال كود التفعيل أو اختيار ملف الترخيص أولاً.');
                    return;
                }
                sendUpdateLicenseAjax(codeVal);
            }
        });
    }

    // تبديل التبويبات (Vanilla JS بالكامل)
    var tabLinks = document.querySelectorAll("#settingsTabs a[data-toggle='tab']");
    tabLinks.forEach(function(link) {
        link.addEventListener('click', function(e) {
            e.preventDefault();

            tabLinks.forEach(function(l) {
                l.classList.remove('active');
                l.setAttribute('aria-selected', 'false');
            });

            this.classList.add('active');
            this.setAttribute('aria-selected', 'true');

            var targetId = this.getAttribute('href');
            var tabPanes = document.querySelectorAll('.tab-content-custom .tab-pane');
            tabPanes.forEach(function(pane) {
                pane.classList.remove('show', 'active');
            });

            var targetPane = document.querySelector(targetId);
            if (targetPane) {
                targetPane.classList.add('show', 'active');
            }
        });
    });
});
</script>
<?php
require_once($dir_prefix . 'includes/footer.php');
?>