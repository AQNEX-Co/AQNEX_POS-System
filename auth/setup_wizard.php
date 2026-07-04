<?php

/**
 * معالج الإعداد الأول AQNEX Setup Wizard
 */
$dir_prefix = '../';
require_once(__DIR__ . '/../includes/connect.php');

// التحقق من تفعيل الترخيص أولاً. إذا لم يكن مفعلاً، سيقوم bootstrap بالتوجيه لـ activate.php تلقائياً.
// هنا نقوم بجلب إعدادات المنشأة الحالية للتأكد من حالة التهيئة
$settingsRes = $conn->query("SELECT is_configured FROM settings WHERE id = 1");
$settings = $settingsRes ? $settingsRes->fetch_assoc() : null;
$isConfigured = $settings ? intval($settings['is_configured']) : 0;

if ($isConfigured === 1) {
    // تم التهيئة مسبقاً، لا داعي للبقاء هنا
    header("Location: ../home.php");
    exit();
}

$error_message = '';
$success_message = '';

if (isset($_POST['btn_setup'])) {
    $store_name = trim($_POST['store_name']);
    $phone = trim($_POST['phone']);
    $address = trim($_POST['address']);
    $commercial_register = trim($_POST['commercial_register']);
    $tax_number = trim($_POST['tax_number']);
    $currency = trim($_POST['currency']);
    $printer_type = trim($_POST['printer_type']);
    
    // بيانات الحساب الإداري
    $admin_username = trim($_POST['admin_username']);
    $admin_fullname = trim($_POST['admin_fullname']);
    $admin_password = $_POST['admin_password'];
    $admin_phone = trim($_POST['admin_phone']);

    if (!empty($store_name) && !empty($admin_username) && !empty($admin_fullname) && !empty($admin_password)) {
        
        // معالجة رفع الشعار (Logo)
        $logo_path = 'assets/icon/tec.jpg'; // الشعار الافتراضي
        if (isset($_FILES['store_logo']) && $_FILES['store_logo']['error'] === UPLOAD_ERR_OK) {
            $uploadsDir = __DIR__ . '/../uploads';
            if (!is_dir($uploadsDir)) {
                mkdir($uploadsDir, 0777, true);
            }
            $fileExtension = pathinfo($_FILES['store_logo']['name'], PATHINFO_EXTENSION);
            $fileName = 'logo_' . time() . '.' . $fileExtension;
            $destPath = $uploadsDir . '/' . $fileName;
            
            if (move_uploaded_file($_FILES['store_logo']['tmp_name'], $destPath)) {
                $logo_path = 'uploads/' . $fileName;
            }
        }

        // 1. ضمان وجود صف الإعدادات الافتراضي (INSERT إذا كان الجدول فارغاً)
        $conn->query("INSERT IGNORE INTO `settings` 
            (`id`, `store_name`, `phone`, `address`, `currency`, `barcode_scanner`, 
             `printer_type`, `tax_percent`, `low_stock_threshold`, `receipt_footer`, `is_configured`) 
            VALUES (1, '', '', '', 'ريال يمني', 1, 'receipt_80mm', 0, 5, 'شكرًا لزيارتكم!', 0)");

        // 2. حفظ إعدادات المنشأة باستخدام INSERT...ON DUPLICATE KEY UPDATE
        //    يعمل سواء كان الصف موجوداً مسبقاً أم لا (إصلاح مشكلة التثبيت الأول)
        $stmt_settings = $conn->prepare("INSERT INTO `settings` 
            (`id`, `store_name`, `phone`, `address`, `commercial_register`, `tax_number`, 
             `currency`, `printer_type`, `logo`, `is_configured`,
             `barcode_scanner`, `tax_percent`, `low_stock_threshold`, `receipt_footer`)
            VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, 1, 1, 0, 5, 'شكرًا لزيارتكم!')
            ON DUPLICATE KEY UPDATE
                store_name = VALUES(store_name),
                phone = VALUES(phone),
                address = VALUES(address),
                commercial_register = VALUES(commercial_register),
                tax_number = VALUES(tax_number),
                currency = VALUES(currency),
                printer_type = VALUES(printer_type),
                logo = VALUES(logo),
                is_configured = 1");

        if (!$stmt_settings) {
            $error_message = 'فشل تهيئة استعلام حفظ الإعدادات: ' . $conn->error;
        } else {
            $stmt_settings->bind_param(
                "ssssssss",
                $store_name,
                $phone,
                $address,
                $commercial_register,
                $tax_number,
                $currency,
                $printer_type,
                $logo_path
            );

            if ($stmt_settings->execute()) {
                // 3. إنشاء أو تحديث حساب مدير النظام
                $chk_admin = $conn->query("SELECT userid FROM users WHERE position = 'admin' LIMIT 1");

                if ($chk_admin && $chk_admin->num_rows > 0) {
                    // تحديث المدير الحالي
                    $admin_id = $chk_admin->fetch_assoc()['userid'];
                    $stmt_user = $conn->prepare("UPDATE users SET 
                        username = ?, 
                        full_name = ?, 
                        password = ?, 
                        phone = ? 
                        WHERE userid = ?");
                    if ($stmt_user) {
                        $stmt_user->bind_param("ssssi", $admin_username, $admin_fullname, $admin_password, $admin_phone, $admin_id);
                        $stmt_user->execute();
                    }
                } else {
                    // إضافة مدير جديد
                    $stmt_user = $conn->prepare("INSERT INTO users (username, full_name, password, phone, position) VALUES (?, ?, ?, ?, 'admin')");
                    if ($stmt_user) {
                        $stmt_user->bind_param("ssss", $admin_username, $admin_fullname, $admin_password, $admin_phone);
                        $stmt_user->execute();
                        $admin_id = $conn->insert_id;
                    }
                }

                // 4. توجيه المدير لصفحة تسجيل الدخول مع تعبئة اسم المستخدم مسبقاً
                //    ملاحظة: لا يتم فتح جلسة هنا — يجب على المدير تسجيل الدخول يدوياً بكلمة المرور
                header("Location: login.php?u=" . urlencode($admin_username));
                exit();
            } else {
                $error_message = 'حدث خطأ أثناء حفظ الإعدادات: ' . $stmt_settings->error;
            }
        }
    } else {
        $error_message = 'يرجى ملء جميع الحقول المطلوبة باللون الأحمر.';
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>معالج الإعداد الأول - AQNEX POS</title>
    <link rel="stylesheet" type="text/css" href="../files/bower_components/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" type="text/css" href="../files/bower_components/font-awesome/css/font-awesome.min.css">
    <link rel="stylesheet" type="text/css" href="../files/bootstrap-icons/bootstrap-icons.min.css">
    <link rel="stylesheet" type="text/css" href="../assets/css/custom.css">
    <style>
        body {
            background-color: var(--body-bg) !important;
            min-height: 100vh;
            color: var(--text-color) !important;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .setup-container {
            max-width: 900px;
            width: 100%;
        }
        .brand-header {
            text-align: center;
            margin-bottom: 25px;
        }
        .brand-title {
            font-size: 2rem;
            font-weight: 800;
            color: var(--secondary-bg) !important;
        }
        .setup-card {
            background: #fff !important;
            border: 1px solid var(--border-color) !important;
            padding: 35px;
        }
        .section-title {
            color: var(--accent-info) !important;
            font-weight: 700;
            border-bottom: 2px solid var(--border-color) !important;
            padding-bottom: 8px;
            margin-bottom: 20px;
            font-size: 1.15rem;
        }
        .btn-setup {
            background-color: var(--accent-success) !important;
            color: #fff !important;
            font-weight: 700;
            border: none !important;
            padding: 12px 30px !important;
            cursor: pointer;
            transition: background-color 0.2s ease;
        }
        .btn-setup:hover {
            background-color: #0d635c !important;
        }
        label {
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--text-color) !important;
            margin-bottom: 5px;
        }
        .required-star {
            color: var(--accent-danger) !important;
            margin-right: 2px;
        }
    </style>
</head>
<body>

<div class="setup-container">
    <div class="brand-header">
        <h1 class="brand-title">تهيئة النظام - AQNEX POS</h1>
        <p class="text-muted small">يرجى استكمال البيانات التالية لإعداد منشأتك وإنشاء حساب الإدارة الرئيسي</p>
    </div>

    <div class="setup-card">
        <?php if (!empty($error_message)): ?>
            <div class="alert alert-danger text-right mb-4" style="border-radius: 6px;">
                <i class="fa fa-exclamation-triangle ml-2"></i> <?php echo $error_message; ?>
            </div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data">
            <div class="row">
                <!-- العمود الأيمن: بيانات المنشأة -->
                <div class="col-md-6 text-right">
                    <div class="section-title">
                        <i class="fa fa-building ml-1"></i> معلومات المنشأة / المحل
                    </div>

                    <div class="form-group">
                        <label>اسم المنشأة التجاري <span class="required-star">*</span></label>
                        <input type="text" name="store_name" class="form-control" placeholder="مثال: مؤسسة التقنية للتجارة" required>
                    </div>

                    <div class="row">
                        <div class="col-6">
                            <div class="form-group">
                                <label>رقم الجوال / الهاتف</label>
                                <input type="text" name="phone" class="form-control" placeholder="777777777">
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="form-group">
                                <label>العملة الافتراضية</label>
                                <select name="currency" class="form-control">
                                    <option value="ريال يمني" selected>ريال يمني (YER)</option>
                                    <option value="ريال سعودي">ريال سعودي (SAR)</option>
                                    <option value="دولار أمريكي">دولار أمريكي (USD)</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>العنوان الجغرافي</label>
                        <input type="text" name="address" class="form-control" placeholder="مثال: اليمن - عدن">
                    </div>

                    <div class="row">
                        <div class="col-6">
                            <div class="form-group">
                                <label>رقم السجل التجاري</label>
                                <input type="text" name="commercial_register" class="form-control" placeholder="اختياري">
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="form-group">
                                <label>الرقم الضريبي (إن وجد)</label>
                                <input type="text" name="tax_number" class="form-control" placeholder="اختياري">
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-6">
                            <div class="form-group">
                                <label>شعار المنشأة (Logo)</label>
                                <input type="file" name="store_logo" class="form-control-file" accept="image/*" style="color: var(--text-color); font-size: 0.8rem;">
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="form-group">
                                <label>نوع طباعة الفواتير</label>
                                <select name="printer_type" class="form-control">
                                    <option value="receipt_80mm" selected>حراري حر 80mm</option>
                                    <option value="receipt_58mm">حراري حر 58mm</option>
                                    <option value="a4">صفحة كاملة A4</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- العمود الأيسر: بيانات حساب المسؤول -->
                <div class="col-md-6 text-right" style="border-right: 1px solid var(--border-color);">
                    <div class="section-title">
                        <i class="fa fa-user-shield ml-1"></i> حساب مدير النظام الرئيسي
                    </div>

                    <div class="form-group">
                        <label>اسم المستخدم للمدير <span class="required-star">*</span></label>
                        <input type="text" name="admin_username" class="form-control" placeholder="أدخل اسم مستخدم (مثل admin)" required>
                    </div>

                    <div class="form-group">
                        <label>الاسم الكامل للموظف المسؤول <span class="required-star">*</span></label>
                        <input type="text" name="admin_fullname" class="form-control" placeholder="مثال: أمين قحطان" required>
                    </div>

                    <div class="form-group">
                        <label>كلمة المرور <span class="required-star">*</span></label>
                        <input type="password" name="admin_password" class="form-control" placeholder="أدخل كلمة مرور قوية" required>
                    </div>

                    <div class="form-group">
                        <label>رقم جوال المدير</label>
                        <input type="text" name="admin_phone" class="form-control" placeholder="مثال: 777777777">
                    </div>

                    <div class="mt-4 p-3 text-justify" style="background: #f0fdf4; border: 1px solid #bbf7d0;">
                        <small style="color: #15803d; line-height: 1.5; display: block;">
                            <i class="fa fa-info-circle ml-1"></i>
                            <strong>ملاحظة أمنية:</strong> يرجى حفظ بيانات الحساب الإداري بدقة، حيث ستكون هذه البيانات هي المفتاح الحصري لتهيئة بقية صلاحيات الموظفين وإجراء العمليات والتقارير المالية بالنظام.
                        </small>
                    </div>
                </div>
            </div>

            <div class="text-center mt-5">
                <button type="submit" name="btn_setup" class="btn btn-setup w-50">
                    <i class="fa fa-rocket ml-2"></i> إنهاء التهيئة وتشغيل التطبيق
                </button>
            </div>
        </form>
    </div>
</div>

</body>
</html>
