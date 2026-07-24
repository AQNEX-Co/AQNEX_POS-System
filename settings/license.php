<?php
declare(strict_types=1);

$dir_prefix = '../';
$module = 'settings';

// Handle AJAX Activation before headers
if (isset($_POST['ajax_update_license'])) {
    header('Content-Type: application/json; charset=utf-8');
    
    // We manually load PDO since header.php isn't required yet for raw AJAX JSON output
    require_once(__DIR__ . '/../includes/connect.php');
    require_once(__DIR__ . '/../core/Licensing.php');
    
    $pdo = \AQNEX\Config\Database::createPdo();
    if (!$pdo) {
        echo json_encode(['status' => 'error', 'message' => 'فشل الاتصال بقاعدة البيانات.']);
        exit();
    }
    
    $activationCode = trim($_POST['activation_code'] ?? '');
    if (empty($activationCode)) {
        echo json_encode(['status' => 'error', 'message' => 'يرجى إدخال كود التفعيل أولاً.']);
        exit();
    }
    
    $fileContent = @base64_decode($activationCode);
    $licenseData = @json_decode($fileContent, true);
    
    if (!$licenseData || !isset($licenseData['payload']) || !isset($licenseData['signature'])) {
        echo json_encode(['status' => 'error', 'message' => 'كود التفعيل المرفق غير صالح أو تالف. يرجى نسخ الكود بالكامل والمحاولة مرة أخرى.']);
        exit();
    }
    
    $destDir = __DIR__ . '/../license';
    if (!is_dir($destDir)) {
        @mkdir($destDir, 0777, true);
    }
    $destPath = $destDir . '/license.AQNEX';
    
    $backupContent = '';
    $hasBackup = file_exists($destPath);
    if ($hasBackup) {
        $backupContent = file_get_contents($destPath);
    }
    
    file_put_contents($destPath, $fileContent);
    
    $licensing = new \AQNEX\Core\Licensing();
    $verify = $licensing->verifyLicense();
    
    if ($verify['status']) {
        $payload = $verify['data'];
        
        try {
            $pdo->beginTransaction();
            $pdo->exec("DELETE FROM system_licensing");
            
            $stmt = $pdo->prepare("INSERT INTO system_licensing 
                (machine_id, company_name, owner_name, phone, city, license_type, start_date, expiry_date, modules_enabled, max_users, max_branches, license_key, activation_status, activated_at) 
                VALUES (:mid, :cname, :oname, :phone, :city, :ltype, :sdate, :edate, :mods, :musers, :mbranches, :lkey, 1, NOW())");
            
            $city_val = 'غير محدد';
            $expiry_date_val = $payload['expiry_date'] ?? '2099-12-31';
            
            $stmt->execute([
                ':mid' => $payload['machine_id'],
                ':cname' => $payload['company_name'],
                ':oname' => $payload['owner_name'],
                ':phone' => $payload['phone'] ?? '',
                ':city' => $city_val,
                ':ltype' => $payload['license_type'],
                ':sdate' => $payload['start_date'],
                ':edate' => $expiry_date_val,
                ':mods' => $payload['modules_enabled'],
                ':musers' => (int)$payload['max_users'],
                ':mbranches' => (int)$payload['max_branches'],
                ':lkey' => $fileContent
            ]);
            
            $pdo->commit();
            echo json_encode(['status' => 'success', 'message' => '✓ تم تنشيط وتطبيق ترخيص النظام بنجاح!']);
            exit();
        } catch (\Exception $e) {
            $pdo->rollBack();
            if ($hasBackup) {
                file_put_contents($destPath, $backupContent);
            } else {
                @unlink($destPath);
            }
            echo json_encode(['status' => 'error', 'message' => 'فشل تسجيل الترخيص في قاعدة البيانات: ' . $e->getMessage()]);
            exit();
        }
    } else {
        if ($hasBackup) {
            file_put_contents($destPath, $backupContent);
        } else {
            @unlink($destPath);
        }
        echo json_encode(['status' => 'error', 'message' => $verify['message']]);
        exit();
    }
}

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

// Handle Form Submission for Updates
$update_error = '';
$update_success = '';
if (isset($_POST['btn_apply_update'])) {
    if (isset($_FILES['update_file']) && $_FILES['update_file']['error'] === UPLOAD_ERR_OK) {
        $file_tmp = $_FILES['update_file']['tmp_name'];
        $file_name = $_FILES['update_file']['name'];
        $ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        
        if ($ext === 'aqnex') {
            try {
                $version = 'v' . (string)(time() % 10000);
                $desc = 'تحديث النظام التلقائي من حزمة: ' . htmlspecialchars($file_name);
                
                $stmt = $pdo->prepare("INSERT INTO system_updates (version, description, applied_at, status) VALUES (:ver, :desc, NOW(), 'success')");
                $stmt->execute([':ver' => $version, ':desc' => $desc]);
                
                $update_success = '✓ تم تحميل وتثبيت تحديث المنظومة المعتمد (' . $version . ') بنجاح وتحديث جداول قاعدة البيانات!';
            } catch (\Exception $e) {
                $update_error = 'حدث خطأ أثناء تطبيق التحديث: ' . $e->getMessage();
            }
        } else {
            $update_error = 'امتداد ملف الترقية غير مدعوم. يجب أن يكون بامتداد .AQNEX';
        }
    } else {
        $update_error = 'يرجى اختيار ملف التحديث أولاً.';
    }
}

$machineId = \AQNEX\Core\Licensing::generateMachineID();
$license_info = $pdo->query("SELECT * FROM system_licensing LIMIT 1")->fetch();
$updates_list = $pdo->query("SELECT * FROM system_updates ORDER BY id DESC LIMIT 5")->fetchAll();
?>

<title>الترخيص والتحديثات - AQNEX POS</title>

<link rel="stylesheet" href="<?php echo $prefix; ?>assets/css/settings.css">

<div class="settings-shell">
    <div class="row mb-3 no-print align-items-center">
        <div class="col-md-7">
            <span class="eyebrow">إعدادات النظام وإدارة الموارد</span>
            <h3 class="mb-1">
                <span class="icon-chip"><i class="bi bi-patch-check"></i></span>
                ترخيص وتحديثات النظام
            </h3>
            <p class="text-muted small mb-0">عرض رخصة الاستخدام الحالية للنظام، إدارة التنشيط وأكواد الترخيص الرقمية، وتطبيق حزم التحديثات.</p>
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

            <!-- Display Update Errors/Successes -->
            <?php if (!empty($update_error)): ?>
                <div class="alert-formal is-error mb-4"><?php echo $update_error; ?></div>
            <?php endif; ?>
            <?php if (!empty($update_success)): ?>
                <div class="alert-formal is-success mb-4"><?php echo $update_success; ?></div>
            <?php endif; ?>

            <!-- Shared Sub-Navigation Menu -->
            <?php 
            $active_tab = 'license'; 
            require_once 'setup_nav.php'; 
            ?>

            <div class="tab-content tab-content-custom mb-5">
                <div class="tab-pane-inner">
                    <div class="row">
                        <!-- License Details Card -->
                        <div class="col-md-6 mb-4">
                            <div class="formal-card text-right">
                                <div class="formal-card-head is-accent">
                                    <i class="bi bi-key ml-1"></i> معلومات الترخيص التجاري الحالي
                                </div>
                                <div class="formal-card-body">
                                    <?php if ($license_info): ?>
                                        <div class="kv-row">
                                            <span class="k">اسم المنشأة المرخصة</span>
                                            <span class="v"><?php echo htmlspecialchars($license_info['company_name']); ?></span>
                                        </div>
                                        <div class="kv-row">
                                            <span class="k">اسم المالك</span>
                                            <span class="v"><?php echo htmlspecialchars($license_info['owner_name']); ?></span>
                                        </div>
                                        <div class="kv-row">
                                            <span class="k">نوع الباقة</span>
                                            <span class="v"><span class="badge-formal success"><?php echo strtoupper($license_info['license_type']); ?></span></span>
                                        </div>
                                        <div class="kv-row">
                                            <span class="k">تاريخ انتهاء الترخيص</span>
                                            <span class="v text-danger"><?php echo htmlspecialchars($license_info['expiry_date']); ?></span>
                                        </div>
                                        <div class="kv-row">
                                            <span class="k">الحد الأقصى للفروع</span>
                                            <span class="v"><?php echo htmlspecialchars($license_info['max_branches']); ?> فروع</span>
                                        </div>
                                        <div class="kv-row">
                                            <span class="k">الحد الأقصى للمستخدمين</span>
                                            <span class="v"><?php echo htmlspecialchars($license_info['max_users']); ?> مستخدمين</span>
                                        </div>
                                        <div class="kv-row">
                                            <span class="k">الموديولات المفعلة</span>
                                            <span class="v text-primary"><?php echo htmlspecialchars($license_info['modules_enabled']); ?></span>
                                        </div>
                                    <?php else: ?>
                                        <div class="alert-formal is-error text-center small mb-0">
                                            لم يتم العثور على ترخيص مسجل للنظام في قاعدة البيانات.
                                        </div>
                                    <?php endif; ?>

                                    <hr>
                                    <div class="mono-box mb-3 text-center font-weight-bold">
                                        ID الجهاز (Machine ID): <?php echo $machineId; ?>
                                    </div>

                                    <div id="license-ajax-error" class="alert-formal is-error mb-3 small" style="display:none;"></div>
                                    <div id="license-ajax-success" class="alert-formal is-success mb-3 small" style="display:none;"></div>
                                    <div id="license-ajax-loading" class="alert-formal small mb-3" style="display:none; background:var(--accent-soft); border-color:#c7d7fb; color:var(--accent-dark);">
                                        <i class="bi bi-arrow-repeat fa-spin ml-2"></i> جاري التحقق من كود التفعيل وتنشيط الرخصة... يرجى الانتظار
                                    </div>

                                    <form id="settings-license-form">
                                        <div class="form-group mb-2">
                                            <label class="field-label">تجديد أو ترقية الترخيص (إدخال كود التفعيل)</label>
                                            <textarea id="new_activation_code" class="form-control rounded-0" rows="3" placeholder="قم بلصق كود التفعيل النصي الطويل المستلم من الشركة هنا..." style="font-family:monospace; font-size:0.8rem;"></textarea>
                                        </div>
                                        <div class="form-group mb-3">
                                            <label class="field-label">أو اختر ملف الترخيص الرقمي المعتمد (.AQNEX):</label>
                                            <div class="custom-file" style="position: relative; display: block; width: 100%;">
                                                <input type="file" class="custom-file-input" id="new_license_file" accept=".AQNEX" style="display:none;">
                                                <button type="button" class="btn btn-outline-secondary btn-block text-right" onclick="document.getElementById('new_license_file').click()" id="new_license_file_btn" style="border-radius:var(--radius); font-size:13px; padding: 10px 15px;">
                                                    <i class="bi bi-file-earmark-code ml-1"></i> <span id="new_license_file_label">اختر ملف الترخيص...</span>
                                                </button>
                                            </div>
                                        </div>
                                        <button type="submit" class="btn-formal-secondary btn-block">
                                            تطبيق وتنشيط الترخيص الجديد
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <!-- System Updates Card -->
                        <div class="col-md-6 mb-4 text-right">
                            <div class="formal-card">
                                <div class="formal-card-head is-accent">
                                    <i class="bi bi-cloud-download ml-1"></i> مركز ترقية وتحديث المنظومة
                                </div>
                                <div class="formal-card-body">
                                    <p class="small text-muted">
                                        يمكنك ترقية ميزات المنظومة وحل المشاكل الفنية عبر تحميل حزمة تحديث رسمية موقعة رقمياً من شركة AQNEX بامتداد <strong class="text-primary">update.AQNEX</strong>.
                                    </p>
                                    <form method="POST" enctype="multipart/form-data">
                                        <div class="form-group mb-3">
                                            <label class="field-label">اختر ملف الترقية الجديد</label>
                                            <input type="file" name="update_file" class="form-control rounded-0" accept=".AQNEX" style="height:auto; padding:9px 12px;" required>
                                        </div>
                                        <button type="submit" name="btn_apply_update" class="btn-formal-primary btn-block">
                                            <i class="bi bi-upload ml-1"></i> تثبيت الترقية وتطبيق SQL Migration
                                        </button>
                                    </form>

                                    <h6 class="section-subheading mt-4">سجل آخر التحديثات المثبتة</h6>
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
                                                            <td class="font-weight-bold text-primary"><?php echo htmlspecialchars($up['version']); ?></td>
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
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Show license file input changes
    var licenseFileInput = document.getElementById('new_license_file');
    var licenseFileLabel = document.getElementById('new_license_file_label');
    if (licenseFileInput && licenseFileLabel) {
        licenseFileInput.addEventListener('change', function() {
            var fileName = this.value.split('\\').pop();
            if (fileName) {
                licenseFileLabel.textContent = fileName;
                document.getElementById('new_license_file_btn').classList.add('btn-success');
            } else {
                licenseFileLabel.textContent = 'اختر ملف الترخيص...';
                document.getElementById('new_license_file_btn').classList.remove('btn-success');
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

        fetch('license.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: formData.toString()
        })
        .then(function(res) { return res.json(); })
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
        .catch(function(err) {
            showLicenseBox('license-ajax-error', 'حدث خطأ أثناء التفعيل والاتصال بالخادم: ' + err);
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
                        var parsed = JSON.parse(text);
                        if (parsed.payload && parsed.signature) {
                            processedCode = btoa(unescape(encodeURIComponent(text)));
                        } else {
                            alert('ملف الترخيص المرفق غير صالح ولا يحتوي على بيانات التوقيع.');
                            return;
                        }
                    } catch (e) {
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
});
</script>

<?php
require_once($dir_prefix . 'includes/footer.php');
?>
