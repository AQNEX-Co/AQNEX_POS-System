<?php
// بدء الجلسة إذا لم تكن قد بدأت بالفعل
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$prefix = isset($dir_prefix) ? $dir_prefix : '';

// حماية الصفحات واستدعاء بنية التطبيق
require_once(__DIR__ . '/connect.php');
require_once(__DIR__ . '/../app/Services/AuthService.php');
require_once(__DIR__ . '/../app/Services/SettingsService.php');
require_once(__DIR__ . '/icons.php');
require_once(__DIR__ . '/accounting_helper.php');

// حماية الصفحات باستثناء صفحة تسجيل الدخول ونسيان كلمة المرور
$current_page = basename($_SERVER['PHP_SELF']);
if ($current_page !== 'login.php' && $current_page !== 'forget.php') {
    require_once(__DIR__ . '/auth.php');
}

// ======= جلب الإعدادات العامة مرة واحدة لكل صفحات النظام =======
if (!isset($global_settings)) {
    $global_settings = \AQNEX\Services\SettingsService::loadSettings($conn);
}
$logo_url = !empty($global_settings['logo']) ? $prefix . htmlspecialchars($global_settings['logo']) : $prefix . 'assets/icon/tec.jpg';

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
    <link rel="stylesheet" type="text/css" href="<?php echo $prefix; ?>assets/css/custom.css">

    <!-- شاشة التحميل الرسمية — تعريف موحد -->
    <style>
/* ═══════════════════════════════════════════
   Page Loader — تعريف موحد ورسمي للدوّار
   ═══════════════════════════════════════════ */

/* غلاف شاشة التحميل */
#page-loader {
    position: fixed;
    inset: 0;
    z-index: 99999;
    background: rgba(248, 250, 252, 0.97);
    display: flex;
    align-items: center;
    justify-content: center;
    opacity: 0;
    visibility: hidden;
    transition: opacity 0.25s ease, visibility 0.25s ease;
    pointer-events: none;
}
#page-loader.active {
    opacity: 1;
    visibility: visible;
    pointer-events: all;
}
@media print { #page-loader { display: none !important; } }

/* صندوق المحتوى الداخلي */
#page-loader .pl-box {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 16px;
}

/* الدوّار الدائري الرسمي */
#page-loader .pl-ring {
    width: 48px;
    height: 48px;
    border-radius: 50% !important;
    border: 3.5px solid #dde6f0;
    border-top-color: #1d4ed8;
    border-right-color: #3b82f6;
    animation: pl-spin 0.8s cubic-bezier(0.4,0,0.2,1) infinite;
    flex-shrink: 0;
    display: block;
    box-sizing: border-box;
}

/* نص التحميل */
#page-loader .pl-text {
    font-family: 'Tajawal', sans-serif;
    font-size: 13px;
    font-weight: 700;
    letter-spacing: 0.03em;
    color: #1e3148;
    opacity: 0.85;
}

@keyframes pl-spin {
    from { transform: rotate(0deg); }
    to   { transform: rotate(360deg); }
}

/* ═══ فرض الاستدارة على جميع مؤشرات التحميل ═══ */
.official-spinner, .loading-spinner, .circular-loader, .loader-progress {
    border-radius: 50% !important;
    display: block !important;
    box-sizing: border-box !important;
}

/* تحسين شكل الشريط الجانبي */
#sidebar .sidebar-header {
    padding: 20px;
    background: #1a2226;
    border-bottom: 1px solid #2c3b41;
}

    /* ═══════════════════════════════════════════════════════
       تنسيق المودالات الرسمي الموحّد — هوية النظام
       ═══════════════════════════════════════════════════════ */
    .modal-content {
        border-radius: 2px !important;
        border: 1px solid #1e293b !important;
        background-color: #ffffff !important;
        box-shadow: 0 16px 48px rgba(0,0,0,0.28), 0 4px 16px rgba(0,0,0,0.15) !important;
        overflow: hidden;
    }
    .modal-header {
        background: linear-gradient(135deg, #0c1629 0%, #1a2e4a 55%, #112240 100%) !important;
        color: #ffffff !important;
        border-bottom: 2px solid #1e6b5e !important;
        padding: 14px 20px !important;
        border-radius: 0 !important;
        margin-top: 0 !important;
    }
    .modal-title {
        font-family: 'Tajawal', sans-serif !important;
        font-weight: 700 !important;
        font-size: 0.97rem !important;
        color: #ffffff !important;
        letter-spacing: 0.03em;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .modal-header .close,
    .modal-header [data-dismiss="modal"],
    .modal-header [data-bs-dismiss="modal"] {
        color: rgba(255,255,255,0.75) !important;
        text-shadow: none !important;
        opacity: 1 !important;
        transition: color 0.18s;
        background: none !important;
        border: none !important;
    }
    .modal-header .close:hover,
    .modal-header [data-dismiss="modal"]:hover,
    .modal-header [data-bs-dismiss="modal"]:hover {
        color: #ffffff !important;
        opacity: 1 !important;
    }
    .modal-body {
        background-color: #f8fafc !important;
        padding: 22px !important;
    }
    .modal-footer {
        border-top: 1px solid #e2e8f0 !important;
        background-color: #f1f5f9 !important;
        padding: 12px 18px !important;
        border-radius: 0 !important;
    }
    .modal-helper {
        font-size: 0.80rem;
        color: #475569;
        border-right: 3px solid #1e6b5e;
        padding: 6px 10px;
        background: #f0fdf8;
        border-radius: 2px;
        margin-top: 8px;
    }

    /* ═══ حقل البحث السريع عن المنتج ═══ */
    #quickProductSearchInput {
        border: 2px solid #cbd5e1;
        border-radius: 2px;
        padding: 10px 14px;
        font-size: 0.97rem;
        transition: border-color 0.2s, box-shadow 0.2s;
        background: #fff;
        font-family: 'Tajawal', sans-serif;
    }
    #quickProductSearchInput:focus {
        border-color: #1d4ed8;
        box-shadow: 0 0 0 3px rgba(29,78,216,0.12);
        outline: none;
    }

    /* ═══ نتائج البحث عن منتج ═══ */
    .search-result-item {
        background-color: #ffffff !important;
        border: 1px solid #e2e8f0 !important;
        border-radius: 2px !important;
        padding: 11px 16px !important;
        margin-bottom: 8px !important;
        transition: border-color 0.15s, box-shadow 0.15s, background-color 0.15s !important;
        cursor: pointer; width: 100%; text-align: right; display: block;
    }
    .search-result-item:hover, .search-result-item:focus {
        border-color: #1d4ed8 !important;
        background-color: #eff6ff !important;
        box-shadow: 0 2px 8px rgba(29,78,216,0.10) !important;
        outline: none;
    }
    .search-result-item .item-title { font-size:0.93rem; font-weight:700; color:#1e293b; margin-bottom:4px; }
    .search-result-item .item-meta  { font-size:0.78rem; color:#64748b; display:flex; flex-wrap:wrap; gap:8px; }
    .search-result-item .item-meta span { background:#f1f5f9; padding:2px 7px; border-radius:2px; font-weight:500; }
    .search-result-item:hover .item-meta span { background:#dbeafe; color:#1d4ed8; }

    /* ═══ فرض الدائرية على جميع مؤشرات التحميل الفرعية ═══ */
    .circular-loader, .loading-spinner, .official-spinner, .loader-progress {
        border-radius: 50% !important;
        display: block !important;
        box-sizing: border-box !important;
    }
    .circular-loader {
        border: 3px solid #e2e8f0;
        border-top-color: #1d4ed8;
        width: 32px; height: 32px;
        animation: pl-spin 0.8s linear infinite;
        margin: 20px auto;
    }
</style>

    <?php
    // التحقق إذا كنا في صفحة تقرير رسمي لإخفاء الأيقونات لجعلها رسمية
    $script_name = $_SERVER['SCRIPT_NAME'] ?? '';
    $is_report_page = (strpos($script_name, '/reports/') !== false || strpos($script_name, 'ledger.php') !== false || (isset($module) && $module === 'reports'));
    if ($is_report_page):
    ?>
        <style>
            /* إخفاء كافة الأيقونات في التقارير الرسمية بناءً على رغبة المستخدم */
            i, .bi, .fa, [class^="bi-"], [class^="fa-"] {
                display: none !important;
            }
        </style>
    <?php endif; ?>
</head>
<body>

<!-- شاشة التحميل الرسمية -->
<div id="page-loader">
    <div class="pl-box">
        <div class="pl-ring"></div>
        <span class="pl-text">جاري التحميل...</span>
    </div>
</div>

    <div class="wrapper">
        <!-- Print header (visible only when printing) -->
        <?php if (!isset($no_print_header) || !$no_print_header): ?>
        <div class="print-header" style="direction: rtl; width: 100%; border-bottom: 2px solid #000; padding-bottom: 12px; margin-bottom: 20px;">
            <table style="width: 100%; border-collapse: collapse; border: none; margin: 0; padding: 0;">
                <tr style="border: none;">
                    <!-- اليمين: معلومات المتجر باللغة العربية -->
                    <td style="width: 35%; text-align: right; vertical-align: middle; font-family: 'Tajawal', sans-serif; font-size: 11px; line-height: 1.5; border: none; padding: 0;">
                        <h3 style="margin: 0 0 4px 0; font-size: 15px; font-weight: 800; color: #000;"><?php echo htmlspecialchars($global_settings['store_name'] ?? ''); ?></h3>
                        <div style="font-weight: 600; color: #334155;"><?php echo htmlspecialchars($global_settings['address'] ?? ''); ?></div>
                        <div style="font-weight: 600; color: #334155;">هاتف: <?php echo htmlspecialchars($global_settings['phone'] ?? ''); ?></div>
                        <?php if (!empty($global_settings['commercial_register']) && (!isset($global_settings['report_show_cr']) || $global_settings['report_show_cr'] == 1)): ?>
                            <div style="font-size: 10px; color: #475569;">سجل تجاري: <?php echo htmlspecialchars($global_settings['commercial_register']); ?></div>
                        <?php endif; ?>
                        <?php if (!empty($global_settings['tax_number']) && (!isset($global_settings['report_show_tax']) || $global_settings['report_show_tax'] == 1)): ?>
                            <div style="font-size: 10px; color: #475569;">الرقم الضريبي: <?php echo htmlspecialchars($global_settings['tax_number']); ?></div>
                        <?php endif; ?>
                    </td>
                    
                    <!-- الوسط: شعار الشركة وعنوان التقرير -->
                    <td style="width: 30%; text-align: center; vertical-align: middle; border: none; padding: 0;">
                        <?php 
                        $show_logo = (!isset($global_settings['report_show_logo']) || $global_settings['report_show_logo'] == 1) && !empty($logo_url);
                        if ($show_logo): 
                        ?>
                            <img src="<?php echo $logo_url; ?>" style="max-height: 70px; object-fit: contain; margin-bottom: 6px; border: 1px solid #cbd5e1; padding: 3px; border-radius: 4px;">
                        <?php endif; ?>
                        <div style="font-size: 15px; font-weight: 800; text-decoration: underline; color: #000; margin-top: 4px; letter-spacing: 0.5px;">
                            <?php echo isset($report_title) ? htmlspecialchars($report_title) : 'تقرير رسمي'; ?>
                        </div>
                        <?php if (!empty($global_settings['report_header_subtitle'])): ?>
                            <div style="font-size: 10px; margin-top: 3px; color: #475569; font-weight: 700;"><?php echo htmlspecialchars($global_settings['report_header_subtitle']); ?></div>
                        <?php endif; ?>
                    </td>
                    
                    <!-- اليسار: معلومات المتجر باللغة الإنجليزية -->
                    <td style="width: 35%; text-align: left; vertical-align: middle; font-family: 'Tajawal', sans-serif; font-size: 11px; line-height: 1.5; direction: ltr; border: none; padding: 0;">
                        <h3 style="margin: 0 0 4px 0; font-size: 15px; font-weight: 800; color: #000;"><?php echo htmlspecialchars($global_settings['store_name_en'] ?? 'AQNEX POS System'); ?></h3>
                        <div style="font-weight: 600; color: #334155;"><?php echo htmlspecialchars($global_settings['address_en'] ?? 'Aden - Yemen'); ?></div>
                        <div style="font-weight: 600; color: #334155;">Tel: <?php echo htmlspecialchars($global_settings['phone_en'] ?? '+967 777777777'); ?></div>
                        <?php if (!empty($global_settings['commercial_register']) && (!isset($global_settings['report_show_cr']) || $global_settings['report_show_cr'] == 1)): ?>
                            <div style="font-size: 10px; color: #475569;">C.R. No: <?php echo htmlspecialchars($global_settings['commercial_register']); ?></div>
                        <?php endif; ?>
                        <?php if (!empty($global_settings['tax_number']) && (!isset($global_settings['report_show_tax']) || $global_settings['report_show_tax'] == 1)): ?>
                            <div style="font-size: 10px; color: #475569;">Tax ID: <?php echo htmlspecialchars($global_settings['tax_number']); ?></div>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>
            <?php if (!empty($global_settings['report_header_notes'])): ?>
                <div class="print-header-notes" style="font-size: 9px; text-align: right; color: #475569; border: 1px dashed #cbd5e1; padding: 5px; margin-top: 6px; margin-bottom: 2px; background: #fafafa; border-radius: 4px;">
                    <strong>ملاحظات التقرير:</strong> <?php echo nl2br(htmlspecialchars($global_settings['report_header_notes'])); ?>
                </div>
            <?php endif; ?>
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
        <!-- ===== الشريط العلوي الرئيسي (امتداد كامل فوق الصفحة) ===== -->
        <header class="main-topbar no-print">

            <!-- الجانب الأيمن: شعار المنشأة + اسمها -->
            <div class="topbar-brand">
                <img src="<?php echo $logo_url; ?>" alt="logo" class="topbar-logo">
                <div class="topbar-brand-text">
                    <span class="topbar-brand-name"><?php echo !empty($global_settings['store_name']) ? htmlspecialchars($global_settings['store_name']) : 'AQNEX POS'; ?></span>
                    <!-- <span class="topbar-brand-sub">نظام إدارة المبيعات والمحاسبة</span> -->
                </div>
            </div>

            <!-- الجانب الأوسط: التاريخ والوقت المباشر -->
            <div class="topbar-datetime">

                <div class="topbar-date">
                    <div class="topbar-time" id="live-clock">
    <?php date_default_timezone_set("Asia/Aden"); echo date("h:i:s A"); ?>
</div>
<?php
                    $days_ar = ['الأحد','الاثنين','الثلاثاء','الأربعاء','الخميس','الجمعة','السبت'];
                    echo $days_ar[date('w')] . ' &nbsp;|&nbsp; ' . date('d / m / Y');
                    ?>
                    <i class="bi bi-calendar3"></i>
                </div>
            </div>

            <!-- الجانب الأيسر: بيانات المستخدم + تسجيل خروج -->
            <div class="topbar-user-area">
                <?php
                $current_role = isset($_SESSION['SESS_LAST_NAME']) ? trim($_SESSION['SESS_LAST_NAME']) : '';
                $role_map = [
                    'admin'     => ['label' => 'مدير النظام', 'color' => '#be123c', 'icon' => 'bi-shield-fill-check'],
                    'cashier'   => ['label' => 'أمين صندوق',  'color' => '#0369a1', 'icon' => 'bi-cash-coin'],
                    'inventory' => ['label' => 'أمين مستودع', 'color' => '#0f766e', 'icon' => 'bi-box-seam-fill'],
                ];
                $role_info = $role_map[$current_role] ?? ['label' => 'مستخدم', 'color' => '#64748b', 'icon' => 'bi-person-fill'];
                $display_name = !empty($_SESSION['SESS_FULL_NAME'])
                    ? htmlspecialchars($_SESSION['SESS_FULL_NAME'])
                    : (htmlspecialchars($_SESSION['SESS_FIRST_NAME'] ?? 'مستخدم'));
                ?>

                <!-- بطاقة المستخدم -->
                <div class="topbar-user-card">
                    <div class="topbar-user-avatar">
                        <i class="bi <?php echo $role_info['icon']; ?>"></i>
                    </div>
                    <div class="topbar-user-info">
                        <span class="topbar-user-name"><?php echo $display_name; ?></span>
                        <span class="topbar-user-role" style="color:<?php echo $role_info['color']; ?>">
                            <?php echo $role_info['label']; ?>
                        </span>
                    </div>
                </div>

                <!-- فاصل -->
                <div class="topbar-divider"></div>

                <!-- زر تسجيل الخروج -->
                <a href="<?php echo $prefix; ?>auth/logout.php" class="topbar-logout-btn" title="تسجيل الخروج">
                    <i class="bi bi-box-arrow-left"></i>
                    <span>خروج</span>
                </a>
            </div>
        </header>
        <!-- ===== نهاية الشريط العلوي ===== -->
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