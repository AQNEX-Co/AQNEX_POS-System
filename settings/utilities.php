<?php
// settings/utilities.php
$dir_prefix = '../';
$module = 'settings';
require_once($dir_prefix . 'includes/header.php');
check_permission(['admin']);
?>

<div class="settings-shell">
<?php
$active_tab = 'utilities';
require_once 'setup_nav.php';
?>

<div class="row mb-4 no-print align-items-center">
    <div class="col-md-7 text-right">
        <span class="eyebrow" style="text-transform: uppercase; letter-spacing: .08em; font-size: 11px; font-weight: 700; color: var(--accent); margin-bottom: 4px; display: block;">لوحة التحكم — تهيئة النظام</span>
        <h3 class="mb-1" style="color: var(--ink-900); font-weight: 800; margin-bottom: 2px;">
            <span class="icon-chip" style="display: inline-flex; align-items: center; justify-content: center; width: 38px; height: 38px; border-radius: 8px; background: var(--accent-soft); color: var(--accent-dark); margin-left: 10px;"><i class="bi bi-wrench-adjustable"></i></span>
            الأدوات المساعدة والتقنية
        </h3>
        <p class="text-muted small mb-0" style="color: var(--ink-500);">مجموعة من الأدوات المساعدة لإدارة قواعد البيانات، إعدادات طابعات الفواتير، تفعيل الأقسام والموديولات، والمساعد الذكي.</p>
    </div>
    <div class="col-md-5 text-left">
        <a href="../home.php" class="btn-back-settings text-decoration-none" style="background: var(--surface-soft); border: 1px solid var(--line); color: var(--ink-700); padding: 8px 16px; border-radius: var(--radius); font-size: 13px; font-weight: bold; display: inline-flex; align-items: center; gap: 6px;">
            <?php echo get_icon('logout', 'ml-1'); ?> عودة للرئيسية
        </a>
    </div>
</div>

<div class="settings-head" style="border-bottom: 2px solid var(--ink-900); padding-bottom: 16px; margin-bottom: 24px;"></div>

<div class="row justify-content-center no-print">
    <div class="col-lg-11">
        <div class="row">
            <!-- 1. النسخ الاحتياطي والاستعادة -->
            <div class="col-md-6 col-lg-4 mb-4">
                <div class="card h-100 border-0 shadow-sm" style="background: var(--surface); border-radius: 8px; transition: transform 0.2s, box-shadow 0.2s; overflow: hidden; border: 1px solid var(--line) !important;">
                    <div class="card-body p-4 text-right d-flex flex-column justify-content-between">
                        <div>
                            <div class="d-inline-flex align-items-center justify-content-center mb-3" style="width: 48px; height: 48px; border-radius: 10px; background: var(--accent-soft); color: var(--accent);">
                                <i class="bi bi-database-fill-gear" style="font-size: 1.5rem;"></i>
                            </div>
                            <h5 class="card-title font-weight-bold mb-2" style="color: var(--ink-900); font-size: 1.1rem;">النسخ الاحتياطي والاستعادة</h5>
                            <p class="card-text text-muted small" style="line-height: 1.6; color: var(--ink-500);">
                                حماية بياناتك من خلال إنشاء نسخ احتياطية لقاعدة البيانات واستعادتها في أي وقت بسهولة لحفظ أمان النظام.
                            </p>
                        </div>
                        <a href="backup.php" class="btn btn-outline-primary btn-sm btn-block mt-4 font-weight-bold py-2 rounded-0">
                            فتح أداة النسخ الاحتياطي
                        </a>
                    </div>
                </div>
            </div>

            <!-- 2. إعدادات طابعات الفواتير -->
            <div class="col-md-6 col-lg-4 mb-4">
                <div class="card h-100 border-0 shadow-sm" style="background: var(--surface); border-radius: 8px; transition: transform 0.2s, box-shadow 0.2s; overflow: hidden; border: 1px solid var(--line) !important;">
                    <div class="card-body p-4 text-right d-flex flex-column justify-content-between">
                        <div>
                            <div class="d-inline-flex align-items-center justify-content-center mb-3" style="width: 48px; height: 48px; border-radius: 10px; background: var(--good-soft); color: var(--good);">
                                <i class="bi bi-printer-fill" style="font-size: 1.5rem;"></i>
                            </div>
                            <h5 class="card-title font-weight-bold mb-2" style="color: var(--ink-900); font-size: 1.1rem;">إعدادات طابعات الفواتير</h5>
                            <p class="card-text text-muted small" style="line-height: 1.6; color: var(--ink-500);">
                                ربط وتكوين طابعات الفواتير الحرارية (شبكية أو USB) وتعيين الطابعات الافتراضية لنقاط البيع المختلفة.
                            </p>
                        </div>
                        <a href="printers.php" class="btn btn-outline-success btn-sm btn-block mt-4 font-weight-bold py-2 rounded-0">
                            تهيئة وإعداد الطابعات
                        </a>
                    </div>
                </div>
            </div>

            <!-- 3. تفعيل الموديولات قطاعياً -->
            <div class="col-md-6 col-lg-4 mb-4">
                <div class="card h-100 border-0 shadow-sm" style="background: var(--surface); border-radius: 8px; transition: transform 0.2s, box-shadow 0.2s; overflow: hidden; border: 1px solid var(--line) !important;">
                    <div class="card-body p-4 text-right d-flex flex-column justify-content-between">
                        <div>
                            <div class="d-inline-flex align-items-center justify-content-center mb-3" style="width: 48px; height: 48px; border-radius: 10px; background: #fff8e6; color: #b9892f;">
                                <i class="bi bi-cpu-fill" style="font-size: 1.5rem;"></i>
                            </div>
                            <h5 class="card-title font-weight-bold mb-2" style="color: var(--ink-900); font-size: 1.1rem;">تفعيل وإدارة الموديولات</h5>
                            <p class="card-text text-muted small" style="line-height: 1.6; color: var(--ink-500);">
                                تفعيل أو تعطيل الأقسام الفرعية للنظام مثل (صيانة الأجهزة، جدولة الأقساط، طباعة الباركود) لتخصيص بيئة العمل.
                            </p>
                        </div>
                        <a href="modules.php" class="btn btn-outline-warning btn-sm btn-block mt-4 font-weight-bold py-2 rounded-0" style="color: #b9892f; border-color: #b9892f;">
                            إدارة موديولات النظام
                        </a>
                    </div>
                </div>
            </div>

            <!-- 4. المساعد الذكي والذكاء الاصطناعي -->
            <div class="col-md-6 col-lg-4 mb-4">
                <div class="card h-100 border-0 shadow-sm" style="background: var(--surface); border-radius: 8px; transition: transform 0.2s, box-shadow 0.2s; overflow: hidden; border: 1px solid var(--line) !important;">
                    <div class="card-body p-4 text-right d-flex flex-column justify-content-between">
                        <div>
                            <div class="d-inline-flex align-items-center justify-content-center mb-3" style="width: 48px; height: 48px; border-radius: 10px; background: var(--accent-soft); color: var(--accent);">
                                <i class="bi bi-robot" style="font-size: 1.5rem;"></i>
                            </div>
                            <h5 class="card-title font-weight-bold mb-2" style="color: var(--ink-900); font-size: 1.1rem;">إعداد المساعد الذكي AI</h5>
                            <p class="card-text text-muted small" style="line-height: 1.6; color: var(--ink-500);">
                                تهيئة الاتصال بمحرك الذكاء الاصطناعي Gemini وتحديث مفتاح الوصول (API Key) للمساعدة التحليلية المباشرة.
                            </p>
                        </div>
                        <a href="rules.php" class="btn btn-outline-primary btn-sm btn-block mt-4 font-weight-bold py-2 rounded-0">
                            تهيئة Gemini API
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

</div>

<link rel="stylesheet" href="<?php echo $prefix; ?>assets/css/settings.css">

<?php
require_once($dir_prefix . 'includes/footer.php');
?>
