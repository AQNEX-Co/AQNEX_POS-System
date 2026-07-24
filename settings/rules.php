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

$pdo = \AQNEX\Config\Database::createPdo();
if (!$pdo) {
    die('فشل الاتصال بقاعدة البيانات.');
}

$rules_error = '';
$rules_success = '';

// Handle Gemini API save
if (isset($_POST['btn_save_gemini'])) {
    $gemini_key = trim($_POST['gemini_api_key'] ?? '');
    try {
        $stmt = $pdo->prepare("UPDATE settings SET gemini_api_key = :key WHERE id = 1");
        $stmt->execute([':key' => $gemini_key]);
        $rules_success = 'تم حفظ مفتاح Gemini API بنجاح!';
    } catch (\Exception $e) {
        $rules_error = 'فشل حفظ مفتاح API: ' . $e->getMessage();
    }
}

// Get Settings (ID = 1)
$settingsStmt = $pdo->query("SELECT * FROM settings WHERE id = 1 LIMIT 1");
$settings = $settingsStmt->fetch();

// Get Business Rules (ID = 1)
$rulesStmt = $pdo->query("SELECT * FROM business_rules WHERE id = 1 LIMIT 1");
$businessRules = $rulesStmt->fetch();
?>

<title>قواعد وسياسات العمل - AQNEX POS</title>

<link rel="stylesheet" href="<?php echo $prefix; ?>assets/css/settings.css">

<div class="settings-shell">
    <div class="row mb-3 no-print align-items-center">
        <div class="col-md-7">
            <span class="eyebrow">إعدادات النظام وإدارة الموارد</span>
            <h3 class="mb-1">
                <span class="icon-chip"><i class="bi bi-shield-check"></i></span>
                سياسات وقواعد العمل
            </h3>
            <p class="text-muted small mb-0">ضبط القيود التشغيلية وصلاحيات المبيعات بالسالب، وتحديد طرق تقييم التكلفة المحاسبية للمخازن.</p>
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

            <!-- Page level errors -->
            <?php if (!empty($rules_error)): ?>
                <div class="alert-formal is-error mb-4"><?php echo $rules_error; ?></div>
            <?php endif; ?>
            <?php if (!empty($rules_success)): ?>
                <div class="alert-formal is-success mb-4"><?php echo $rules_success; ?></div>
            <?php endif; ?>

            <!-- Shared Sub-Navigation Menu -->
            <?php 
            $active_tab = 'rules'; 
            require_once 'settings_nav.php'; 
            ?>

            <div class="tab-content tab-content-custom mb-5">
                <div class="tab-pane-inner">
                    <form id="form-business-rules" method="POST">
                        <input type="hidden" name="action" value="save_business_rules">
                        <h5 class="section-heading">قواعد القيود والسياسات المحاسبية والمخزنية</h5>
                        
                        <div class="row">
                            <div class="col-md-6 mb-4">
                                <label class="field-label">السماح بالبيع بالسالب (الكميات السالبة)</label>
                                <select name="allow_negative_stock" class="form-control rounded-0">
                                    <option value="0" <?php echo ($businessRules['allow_negative_stock'] == 0) ? 'selected' : ''; ?>>معطل (يمنع بيع كمية غير متوفرة)</option>
                                    <option value="1" <?php echo ($businessRules['allow_negative_stock'] == 1) ? 'selected' : ''; ?>>مفعل (يسمح بإجراء مبيعات سلبية للمخزن)</option>
                                </select>
                                <span class="field-hint">إذا تم التعطيل، يمنع النظام الكاشير من إتمام أي فاتورة مبيعات تحتوي على كمية أكبر من الرصيد المتوفر في مستودعات المنشأة.</span>
                            </div>
                            
                            <div class="col-md-6 mb-4">
                                <label class="field-label">طريقة تقييم المخزون المحاسبية</label>
                                <select name="inventory_valuation_method" class="form-control rounded-0">
                                    <option value="AVG" <?php echo ($businessRules['inventory_valuation_method'] === 'AVG') ? 'selected' : ''; ?>>متوسط التكلفة المرجح (W.A.C.)</option>
                                    <option value="FIFO" <?php echo ($businessRules['inventory_valuation_method'] === 'FIFO') ? 'selected' : ''; ?>>الوارد أولاً صادر أولاً (FIFO)</option>
                                </select>
                                <span class="field-hint">الطريقة المستخدمة لحساب تكلفة البضاعة وقيمة الأصول في التقارير المحاسبية والميزانيات العمومية.</span>
                            </div>
                            
                            <div class="col-md-6 mb-4">
                                <label class="field-label">الحد الأقصى للخصم المسموح به للبائع (%)</label>
                                <input type="number" step="any" min="0" max="100" name="max_discount_limit" class="form-control rounded-0 text-center font-weight-bold" value="<?php echo htmlspecialchars((string)($businessRules['max_discount_limit'] ?? 100.0000)); ?>">
                                <span class="field-hint">الحد الأقصى للخصم الذي يستطيع الكاشير تطبيقه يدوياً على الفواتير المباشرة.</span>
                            </div>
                        </div>

                        <div class="text-left mt-3">
                            <button type="submit" class="btn-formal-primary">
                                <i class="bi bi-save ml-1"></i> حفظ وتطبيق سياسات العمل
                            </button>
                        </div>
                    </form>

                    <hr class="my-5">

                    <!-- Gemini AI Assistant settings card -->
                    <div class="row">
                        <div class="col-md-12">
                            <div class="formal-card">
                                <div class="formal-card-head is-accent">
                                    <i class="bi bi-robot ml-1"></i> إعداد الاتصال بـ Gemini API للساعد الذكي
                                </div>
                                <div class="formal-card-body">
                                    <form method="POST" action="rules.php">
                                        <div class="form-group mb-3 text-right">
                                            <label class="field-label">مفتاح Gemini API (API Key) *</label>
                                            <input type="text" name="gemini_api_key" class="form-control rounded-0 font-monospace" value="<?php echo htmlspecialchars($settings['gemini_api_key'] ?? ''); ?>" placeholder="AIzaSy..." required>
                                            <span class="field-hint">يمكنك الحصول على مفتاح API مجاني أو مدفوع من منصة <a href="https://aistudio.google.com/" target="_blank">Google AI Studio</a>.</span>
                                        </div>
                                        <div class="text-left">
                                            <button type="submit" name="btn_save_gemini" class="btn-formal-primary">
                                                <i class="bi bi-check2-circle ml-1"></i> حفظ مفتاح API
                                            </button>
                                        </div>
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
function showSettingsAlert(status, message) {
    var box = document.getElementById('settings-alert-box');
    if (!box) return;
    box.className = 'alert-formal ' + (status === 'success' ? 'is-success' : 'is-error');
    box.textContent = message;
    box.style.display = 'block';
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

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
    var formRules = document.getElementById('form-business-rules');
    if (formRules) {
        formRules.addEventListener('submit', function(e) {
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
});
</script>

<?php
require_once($dir_prefix . 'includes/footer.php');
?>
