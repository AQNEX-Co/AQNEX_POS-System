<?php
// بدء الجلسة إذا لم تكن قد بدأت بالفعل
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$prefix = isset($dir_prefix) ? $dir_prefix : '';

// حماية الصفحات باستثناء صفحة تسجيل الدخول ونسيان كلمة المرور
require_once(__DIR__ . '/connect.php');
require_once(__DIR__ . '/icons.php');
require_once(__DIR__ . '/accounting_helper.php');

// حماية الصفحات باستثناء صفحة تسجيل الدخول ونسيان كلمة المرور
$current_page = basename($_SERVER['PHP_SELF']);
if ($current_page !== 'login.php' && $current_page !== 'forget.php') {
    require_once(__DIR__ . '/auth.php');
}

// ======= جلب الإعدادات العامة مرة واحدة لكل صفحات النظام =======
if (!isset($global_settings)) {
    $gs_res = $conn->query("SELECT * FROM settings WHERE id = 1");
    $global_settings = ($gs_res) ? $gs_res->fetch_assoc() : [];
    // إذا لم تكن موجودة، أنشئها
    if (empty($global_settings)) {
        $conn->query("INSERT IGNORE INTO settings (id, store_name, phone, address, currency, barcode_scanner, printer_type, tax_percent, low_stock_threshold, receipt_footer, cashier_permissions, inventory_permissions) VALUES (1, 'AQNEX POS', '777777777', 'اليمن - عدن', 'ريال يمني', 1, 'receipt_80mm', 0, 5, 'شكراً لزيارتكم!', 'sales,customers,receipts', 'products,categories,purchases,suppliers')");
        $gs_res = $conn->query("SELECT * FROM settings WHERE id = 1");
        $global_settings = ($gs_res) ? $gs_res->fetch_assoc() : [];
    }
}
$logo_url = !empty($global_settings['logo']) ? $prefix . htmlspecialchars($global_settings['logo']) : $prefix . 'icon/tec.jpg';

// متغير مساعد يُحدد إذا كان المستخدم مدير
$is_admin = (isset($_SESSION['SESS_LAST_NAME']) && trim($_SESSION['SESS_LAST_NAME']) === 'admin');

// ترقية تلقائي: إضافة عمود full_name إذا لم يكن موجوداً
try {
    $chk = $conn->query("SELECT full_name FROM users LIMIT 1");
    if (!$chk) {
        // العمود غير موجود - أضفه
        $conn->query("ALTER TABLE users ADD COLUMN full_name varchar(150) DEFAULT NULL AFTER username");
        $conn->query("UPDATE users SET full_name = username WHERE full_name IS NULL OR full_name = ''");
    }
} catch (Exception $e) { /* تجاهل الأخطاء */ }

?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script>
    if (window.trustedTypes && window.trustedTypes.createPolicy) {
        if (!window.trustedTypes.defaultPolicy) {
            try {
                window.trustedTypes.createPolicy('default', {
                    createHTML: (string) => string,
                    createScript: (string) => string,
                    createScriptURL: (string) => string
                });
            } catch (e) {
                console.error("TrustedTypes default policy setup failed:", e);
            }
        }
    }
    </script>
    <title><?php echo !empty($global_settings['store_name']) ? htmlspecialchars($global_settings['store_name']) : 'AQNEX POS'; ?></title>
    
    <!-- أيقونة الموقع -->
    <link rel="shortcut icon" href="<?php echo $logo_url; ?>">
    
    <!-- Bootstrap 4 محلي -->
    <link rel="stylesheet" type="text/css" href="<?php echo $prefix; ?>files/bower_components/bootstrap/css/bootstrap.min.css">
    
    <!-- Font Awesome محلي (للتوافق) -->
    <link rel="stylesheet" type="text/css" href="<?php echo $prefix; ?>files/bower_components/font-awesome/css/font-awesome.min.css">
    
    <!-- Bootstrap Icons محلي -->
    <link rel="stylesheet" type="text/css" href="<?php echo $prefix; ?>files/bootstrap-icons/bootstrap-icons.min.css">
    
    <!-- التنسيق المخصص -->
    <link rel="stylesheet" type="text/css" href="<?php echo $prefix; ?>css/custom.css">
</head>
<body>
    <div class="wrapper">
        <!-- Print header (visible only when printing) -->
        <?php if (!isset($no_print_header) || !$no_print_header): ?>
        <div class="print-header">
            <div class="print-header-top">
                <div class="print-header-right">
                    <?php if (!empty($logo_url)): ?>
                    <img src="<?php echo $logo_url; ?>" class="print-logo" alt="logo">
                    <?php endif; ?>
                    <div class="print-store-info">
                        <h3 class="print-store-name"><?php echo htmlspecialchars($global_settings['store_name'] ?? 'تكنولوجيا فون'); ?></h3>
                        <span class="print-store-details"><?php echo htmlspecialchars($global_settings['address'] ?? ''); ?></span>
                        <span class="print-store-details"><?php echo htmlspecialchars($global_settings['phone'] ?? ''); ?></span>
                    </div>
                </div>
                <div class="print-header-center">
                    <h2 class="print-document-title" id="print-doc-title"><?php echo isset($report_title) ? htmlspecialchars($report_title) : ''; ?></h2>
                </div>
                <div class="print-header-left">
                    <div><strong>تاريخ الطباعة:</strong> <?php echo date('Y-m-d H:i'); ?></div>
                    <div><strong>المستخدم:</strong> <?php echo isset($_SESSION['SESS_FIRST_NAME']) ? htmlspecialchars($_SESSION['SESS_FIRST_NAME']) : 'مدير النظام'; ?></div>
                </div>
            </div>
            <div class="print-header-line"></div>
        </div>
        <?php endif; ?>
        <?php 
        // تضمين القائمة الجانبية إذا لم نكن في صفحة الدخول
        if ($current_page !== 'login.php' && $current_page !== 'forget.php') {
            require_once(__DIR__ . '/sidebar.php');
        }
        ?>
        <!-- بدء محتوى الصفحة -->
        <div id="content">
            <?php if ($current_page !== 'login.php' && $current_page !== 'forget.php'): ?>
            <!-- شريط علوي موحد -->
            <div class="navbar-top no-print">
                <!-- يسار: اسم النظام -->
                <div class="navbar-top-brand">
                    <span class="navbar-brand-logo">
                        <img src="<?php echo $logo_url; ?>" alt="logo" style="height:28px;width:28px;object-fit:cover;border-radius:50%;border:2px solid #e2e8f0;">
                    </span>
                    <div class="navbar-brand-text">
                        <span class="navbar-brand-name">AQNEX POS</span>
                        <span class="navbar-brand-sub"><?php echo !empty($global_settings['store_name']) ? htmlspecialchars($global_settings['store_name']) : 'نظام إدارة المبيعات'; ?></span>
                    </div>
                </div>

                <!-- وسط: معلومات المستخدم -->
                <div class="navbar-top-user">
                    <?php
                    $current_role = isset($_SESSION['SESS_LAST_NAME']) ? trim($_SESSION['SESS_LAST_NAME']) : '';
                    $role_map = ['admin' => ['label'=>'مدير النظام','color'=>'#be123c'], 'cashier' => ['label'=>'كاشير','color'=>'#0369a1'], 'inventory' => ['label'=>'أمين مستودع','color'=>'#0f766e']];
                    $role_info = $role_map[$current_role] ?? ['label'=>'مستخدم','color'=>'#64748b'];
                    ?>
                    <div class="navbar-user-avatar">
                        <i class="fa fa-user-circle"></i>
                    </div>
                    <div class="navbar-user-info">
                        <span class="navbar-user-name"><?php
                            // عرض الاسم الكامل إن وُجد، وإلا اسم المستخدم
                            $display_name = '';
                            if (!empty($_SESSION['SESS_FULL_NAME'])) {
                                $display_name = htmlspecialchars($_SESSION['SESS_FULL_NAME']);
                            } elseif (!empty($_SESSION['SESS_FIRST_NAME'])) {
                                $display_name = htmlspecialchars($_SESSION['SESS_FIRST_NAME']);
                            } else {
                                $display_name = 'مستخدم';
                            }
                            echo $display_name;
                        ?></span>
                        <span class="navbar-user-role" style="color:<?php echo $role_info['color']; ?>;"><?php echo $role_info['label']; ?></span>
                    </div>
                </div>

                <!-- يمين: الوقت والتاريخ -->
                <div class="navbar-top-datetime">
                    <?php date_default_timezone_set("Asia/Aden"); ?>
                    <div class="navbar-datetime-time" id="live-time"><?php echo date("h:i:s A"); ?></div>
                    <div class="navbar-datetime-date">
                        <i class="bi bi-calendar ml-1"></i><?php echo date("Y/m/d"); ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php
            // تنبيه بقرب انتهاء الترخيص قبل 7 أيام أو أقل
            if ($current_page !== 'login.php' && $current_page !== 'forget.php' && $current_page !== 'activate.php') {
                require_once(__DIR__ . '/../core/Licensing.php');
                $h_lic = new \AQNEX\Core\Licensing();
                $h_verify = $h_lic->verifyLicense();
                if ($h_verify['status']) {
                    $h_payload = $h_verify['data'];
                    if (isset($h_payload['license_type']) && $h_payload['license_type'] !== 'lifetime') {
                        $h_expiry_date = $h_payload['expiry_date'];
                        $h_expiry_time = strtotime($h_expiry_date . ' 23:59:59');
                        $h_now = time();
                        $h_diff = $h_expiry_time - $h_now;
                        if ($h_diff > 0 && $h_diff <= 86400 * 7) { // 7 أيام أو أقل
                            $days_left = ceil($h_diff / 86400);
                            if ($days_left <= 1) {
                                // تنبيه حرج جداً (أقل من 24 ساعة) - باللون الأحمر
                                ?>
                                <div class="alert alert-danger rounded-0 mb-4 no-print text-right" style="border: 1px solid #f87171; border-right: 4px solid #dc2626 !important; background-color: #fef2f2; color: #991b1b; padding: 12px 15px; font-size: 0.85rem;">
                                    <i class="fa fa-exclamation-triangle ml-2"></i>
                                    <strong>تنبيه حرج - ينتهي الترخيص اليوم:</strong> ينتهي ترخيص النظام الممنوح لكم خلال أقل من 24 ساعة (تاريخ الانتهاء: <?php echo htmlspecialchars($h_expiry_date); ?>). يرجى الانتقال فوراً إلى 
                                    <a href="<?php echo $prefix; ?>auth/activate.php" style="font-weight: 700; color: #dc2626; text-decoration: underline !important;">صفحة تفعيل الترخيص</a> 
                                    وتطبيق كود التفعيل لتجنب توقف النظام عن العمل.
                                </div>
                                <?php
                            } else {
                                // تنبيه بقرب الانتهاء (2 إلى 7 أيام) - باللون الأصفر
                                ?>
                                <div class="alert alert-warning rounded-0 mb-4 no-print text-right" style="border: 1px solid #fbbf24; border-right: 4px solid #d97706 !important; background-color: #fffbeb; color: #92400e; padding: 12px 15px; font-size: 0.85rem;">
                                    <i class="fa fa-exclamation-circle ml-2"></i>
                                    <strong>تنبيه بقرب انتهاء الترخيص:</strong> يتبقى على انتهاء ترخيص النظام الممنوح لكم <?php echo $days_left; ?> أيام (تاريخ الانتهاء: <?php echo htmlspecialchars($h_expiry_date); ?>). يرجى الانتقال إلى 
                                    <a href="<?php echo $prefix; ?>auth/activate.php" style="font-weight: 700; color: #d97706; text-decoration: underline !important;">إعادة تفعيل الترخيص</a> 
                                    لتحديث وتمديد الترخيص.
                                </div>
                                <?php
                            }
                        }
                    }
                }
            }
            ?>
