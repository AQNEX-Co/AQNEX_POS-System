<?php
declare(strict_types=1);

$dir_prefix = '../';
$module = 'settings';

require_once($dir_prefix . 'includes/header.php');
\AQNEX\Services\AuthService::ensureLogin();

// Check basic permissions
if (!\AQNEX\Services\AuthService::isAdmin() && \AQNEX\Services\AuthService::currentUserRole() !== 'Support') {
    \AQNEX\Services\AuthService::denyAccess();
}

// Fetch current configurations
$pdo = \AQNEX\Config\Database::createPdo();
if (!$pdo) {
    die('فشل الاتصال بقاعدة البيانات.');
}

// Get Settings (ID = 1)
$settingsStmt = $pdo->query("SELECT * FROM settings WHERE id = 1 LIMIT 1");
$settings = $settingsStmt->fetch();

$machineId = \AQNEX\Core\Licensing::generateMachineID();

// Get license info
$license_info = $pdo->query("SELECT * FROM system_licensing LIMIT 1")->fetch();

// Check if Support Mode is unlocked in session
$isSupportUnlocked = !empty($_SESSION['support_mode_unlocked']) || (\AQNEX\Services\AuthService::currentUserRole() === 'Support');
?>

<title>إعدادات النظام - AQNEX POS</title>

<link rel="stylesheet" href="<?php echo $prefix; ?>assets/css/settings.css">

<div class="settings-shell">
    <div class="row mb-3 no-print align-items-center">
        <div class="col-md-7">
            <span class="eyebrow">إعدادات النظام وإدارة الموارد</span>
            <h3 class="mb-1">
                <span class="icon-chip"><i class="bi bi-sliders"></i></span>
                بيانات المنشأة والتوطين
            </h3>
            <p class="text-muted small mb-0">تأسيس الهوية التجارية للمؤسسة، تحديد الشعار، والترميز المحلي لعرض العمليات وتنسيق التاريخ والوقت.</p>
        </div>
        <div class="col-md-5 text-left">
            <a href="../home.php" class="btn btn-outline-secondary font-weight-bold" style="padding: 8px 16px; border-radius: var(--radius); font-size: 13px;">
                <i class="bi bi-arrow-right-short ml-1"></i> العودة للرئيسية
            </a>
        </div>
    </div>

    <div class="row justify-content-center no-print">
        <div class="col-lg-12">
            
            <!-- Dynamic Notifications Box -->
            <div id="settings-alert-box" class="alert-formal mb-4" style="display:none;"></div>

            <!-- Shared Sub-Navigation Menu -->
            <?php 
            $active_tab = 'enterprise'; 
            require_once 'settings_nav.php'; 
            ?>

            <div class="tab-content tab-content-custom mb-5">
                <div class="tab-pane-inner">
                    <form id="form-enterprise" method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="save_enterprise">
                        <h5 class="section-heading">بيانات المؤسسة والترميز المحلي</h5>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="field-label">اسم المنشأة / المتجر (بالعربية) *</label>
                                <input type="text" name="store_name" class="form-control rounded-0" value="<?php echo htmlspecialchars($settings['store_name'] ?? ''); ?>" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="field-label">اسم المنشأة بالإنجليزية</label>
                                <input type="text" name="store_name_en" class="form-control rounded-0" value="<?php echo htmlspecialchars($settings['store_name_en'] ?? ''); ?>">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="field-label">رقم الهاتف الافتراضي</label>
                                <input type="text" name="phone" class="form-control rounded-0" value="<?php echo htmlspecialchars($settings['phone'] ?? ''); ?>">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="field-label">رقم الهاتف بالإنجليزية</label>
                                <input type="text" name="phone_en" class="form-control rounded-0" value="<?php echo htmlspecialchars($settings['phone_en'] ?? ''); ?>">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="field-label">رقم السجل التجاري (C.R.)</label>
                                <input type="text" name="commercial_register" class="form-control rounded-0" value="<?php echo htmlspecialchars($settings['commercial_register'] ?? ''); ?>">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="field-label">الرقم الضريبي للمؤسسة (Tax ID)</label>
                                <input type="text" name="tax_number" class="form-control rounded-0" value="<?php echo htmlspecialchars($settings['tax_number'] ?? ''); ?>">
                            </div>
                            <div class="col-md-12 mb-3">
                                <label class="field-label">العنوان الجغرافي للمؤسسة</label>
                                <input type="text" name="address" class="form-control rounded-0" value="<?php echo htmlspecialchars($settings['address'] ?? ''); ?>">
                            </div>
                            <div class="col-md-12 mb-3">
                                <label class="field-label">العنوان الجغرافي بالإنجليزية</label>
                                <input type="text" name="address_en" class="form-control rounded-0" value="<?php echo htmlspecialchars($settings['address_en'] ?? ''); ?>">
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="field-label">شعار المؤسسة المعتمد (Logo)</label>
                                <input type="file" name="logo_file" class="form-control rounded-0">
                                <?php if (!empty($settings['logo'])): ?>
                                    <div class="mt-2">
                                        <img src="<?php echo $dir_prefix . htmlspecialchars($settings['logo']); ?>" style="max-height: 55px; border: 1px solid var(--line); padding: 4px; background:#fff;">
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="field-label">التوقيت المحلي للنظام *</label>
                                <select name="timezone" class="form-control rounded-0">
                                    <option value="Asia/Aden" <?php echo (($settings['timezone'] ?? 'Asia/Aden') === 'Asia/Aden') ? 'selected' : ''; ?>>توقيت اليمن (Asia/Aden)</option>
                                    <option value="Asia/Riyadh" <?php echo (($settings['timezone'] ?? '') === 'Asia/Riyadh') ? 'selected' : ''; ?>>توقيت السعودية (Asia/Riyadh)</option>
                                    <option value="Asia/Dubai" <?php echo (($settings['timezone'] ?? '') === 'Asia/Dubai') ? 'selected' : ''; ?>>توقيت الإمارات (Asia/Dubai)</option>
                                    <option value="Asia/Cairo" <?php echo (($settings['timezone'] ?? '') === 'Asia/Cairo') ? 'selected' : ''; ?>>توقيت مصر (Asia/Cairo)</option>
                                    <option value="UTC" <?php echo (($settings['timezone'] ?? '') === 'UTC') ? 'selected' : ''; ?>>توقيت غرينتش الموحد (UTC)</option>
                                </select>
                            </div>

                            <div class="col-md-4 mb-3">
                                <label class="field-label">تنسيق عرض التاريخ</label>
                                <select name="date_format" class="form-control rounded-0">
                                    <option value="Y-m-d" <?php echo (($settings['date_format'] ?? 'Y-m-d') === 'Y-m-d') ? 'selected' : ''; ?>>السنة-الشهر-اليوم (2026-07-10)</option>
                                    <option value="d/m/Y" <?php echo (($settings['date_format'] ?? '') === 'd/m/Y') ? 'selected' : ''; ?>>اليوم/الشهر/السنة (10/07/2026)</option>
                                    <option value="m-d-Y" <?php echo (($settings['date_format'] ?? '') === 'm-d-Y') ? 'selected' : ''; ?>>الشهر-اليوم-السنة (07-10-2026)</option>
                                </select>
                            </div>

                            <div class="col-md-4 mb-3">
                                <label class="field-label">عدد الخانات العشرية (التقريب)</label>
                                <input type="number" min="0" max="4" name="decimal_precision" class="form-control rounded-0 text-center" value="<?php echo htmlspecialchars((string)($settings['decimal_precision'] ?? 4)); ?>">
                                <span class="field-hint">مستوى دقة التقريب المحاسبي للعمليات والضرائب (1-4).</span>
                            </div>

                            <div class="col-md-4 mb-3">
                                <label class="field-label">فاصل الآلاف</label>
                                <select name="thousand_separator" class="form-control rounded-0">
                                    <option value="," <?php echo (($settings['thousand_separator'] ?? ',') === ',') ? 'selected' : ''; ?>>فاصلة (1,000.00)</option>
                                    <option value="." <?php echo (($settings['thousand_separator'] ?? '') === '.') ? 'selected' : ''; ?>>نقطة (1.000,00)</option>
                                    <option value=" " <?php echo (($settings['thousand_separator'] ?? '') === ' ') ? 'selected' : ''; ?>>مسافة فارغة (1 000.00)</option>
                                </select>
                            </div>
                        </div>

                        <div class="text-left mt-3">
                            <button type="submit" class="btn-formal-primary">
                                <i class="bi bi-check2-circle ml-1"></i> حفظ بيانات المؤسسة والتوطين
                            </button>
                        </div>
                    </form>

                    <hr class="my-5">

                    <!-- System Backup & Licensing -->
                    <div class="row">
                        <div class="col-md-6">
                            <div class="formal-card">
                                <div class="formal-card-head is-accent">
                                    <i class="bi bi-cloud-arrow-up ml-1"></i> إدارة النسخ الاحتياطي للنظام
                                </div>
                                <div class="formal-card-body">
                                    <p class="text-muted small">يمكنك إنشاء نسخة احتياطية كاملة لقاعدة البيانات وحزم الأصول والمستندات بضغطة زر واحدة لحفظ البيانات في خادمك المحلي.</p>
                                    <form method="GET" action="backup.php">
                                        <input type="hidden" name="export" value="1">
                                        <button type="submit" class="btn btn-block btn-formal-success">
                                            <i class="bi bi-download ml-1"></i> تشغيل النسخ الاحتياطي الآن
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="formal-card">
                                <div class="formal-card-head is-accent">
                                    <i class="bi bi-patch-check ml-1"></i> ترخيص وتحديث النظام
                                </div>
                                <div class="formal-card-body">
                                    <?php if ($license_info): ?>
                                        <div class="small mb-2 d-flex justify-content-between">
                                            <span class="text-muted">المنشأة المرخصة:</span>
                                            <strong class="text-dark"><?php echo htmlspecialchars($license_info['company_name']); ?></strong>
                                        </div>
                                        <div class="small mb-2 d-flex justify-content-between">
                                            <span class="text-muted">تاريخ انتهاء الباقة:</span>
                                            <strong class="text-danger"><?php echo htmlspecialchars($license_info['expiry_date']); ?></strong>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <div class="mono-box mb-2 p-1 text-center font-weight-bold" style="font-size:0.75rem; background: var(--surface-soft); border: 1px dashed var(--line);">
                                        ID: <?php echo $machineId; ?>
                                    </div>

                                    <form id="settings-license-form">
                                        <textarea id="new_activation_code" class="form-control rounded-0 mb-2 font-monospace" rows="2" placeholder="أدخل كود الترخيص الرقمي المستلم هنا..." style="font-size:0.75rem;"></textarea>
                                        <button type="submit" class="btn btn-block btn-formal-secondary py-1">
                                            تطبيق ترخيص جديد
                                        </button>
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

<script>
// Dynamic Alert helper
function showSettingsAlert(status, message) {
    var box = document.getElementById('settings-alert-box');
    if (!box) return;
    box.className = 'alert-formal ' + (status === 'success' ? 'is-success' : 'is-error');
    box.textContent = message;
    box.style.display = 'block';
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

// Fetch helper with form data
function makeAjaxCall(formData, callback) {
    fetch('ajax_handler.php', {
        method: 'POST',
        body: formData
    })
    .then(function(res) { return res.json(); })
    .then(function(data) {
        if (data.status === 'success') {
            callback(null, data);
        } else {
            callback(data.message || 'حدث خطأ غير معروف');
        }
    })
    .catch(function(err) {
        callback('فشل الاتصال بالخادم: ' + err);
    });
}

document.addEventListener('DOMContentLoaded', function() {
    var formEnterprise = document.getElementById('form-enterprise');
    if (formEnterprise) {
        formEnterprise.addEventListener('submit', function(e) {
            e.preventDefault();
            var formData = new FormData(this);
            makeAjaxCall(formData, function(err, response) {
                if (err) {
                    showSettingsAlert('error', err);
                } else {
                    showSettingsAlert('success', response.message);
                    setTimeout(function() { window.location.reload(); }, 1500);
                }
            });
        });
    }

    var licenseForm = document.getElementById('settings-license-form');
    if (licenseForm) {
        licenseForm.addEventListener('submit', function(e) {
            e.preventDefault();

            var codeField = document.getElementById('new_activation_code');
            var codeVal = codeField ? codeField.value.trim() : '';

            if (codeVal === '') {
                alert('يرجى إدخال كود التفعيل.');
                return;
            }

            var formData = new URLSearchParams();
            formData.append('ajax_update_license', '1');
            formData.append('activation_code', codeVal);

            fetch('index.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: formData.toString()
            })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (data.status === 'success') {
                    alert(data.message);
                    window.location.reload();
                } else {
                    alert(data.message);
                }
            })
            .catch(function(err) {
                alert('حدث خطأ أثناء الاتصال بالخادم: ' + err);
            });
        });
    }
});
</script>

<?php
require_once($dir_prefix . 'includes/footer.php');
?>