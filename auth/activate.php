<?php
/**
 * معالج التفعيل AQNEX Activation Wizard
 */
$dir_prefix = '../';
require_once(__DIR__ . '/../includes/connect.php');

// التأكد من استدعاء كلاس التراخيص
require_once(__DIR__ . '/../core/Licensing.php');
$licensing = new \AQNEX\Core\Licensing();
$machineId = \AQNEX\Core\Licensing::generateMachineID();

$error_message = '';
$success_message = '';

// معالجة طلب التفعيل عبر AJAX
if (isset($_POST['ajax_activate'])) {
    header('Content-Type: application/json; charset=utf-8');
    
    $activationCode = trim($_POST['activation_code'] ?? '');
    if (empty($activationCode)) {
        echo json_encode(['status' => 'error', 'message' => 'يرجى إدخال كود التفعيل أولاً.']);
        exit();
    }
    
    $fileContent = @base64_decode($activationCode);
    $licenseData = @json_decode($fileContent, true);
    
    if (!$licenseData || !isset($licenseData['payload']) || !isset($licenseData['signature'])) {
        echo json_encode(['status' => 'error', 'message' => 'كود التفعيل المرفق غير صالح أو تالف. يرجى نسخ الكود بالكامل من الشركة والمحاولة مرة أخرى.']);
        exit();
    }
    
    // مسار حفظ الترخيص النهائي في المجلد المخصص للرخصة
    $destDir = __DIR__ . '/../license';
    if (!is_dir($destDir)) {
        @mkdir($destDir, 0777, true);
    }
    $destPath = $destDir . '/license.AQNEX';
    
    // الاحتفاظ بالنسخة القديمة للاستعادة في حال الفشل
    $backupContent = '';
    $hasBackup = file_exists($destPath);
    if ($hasBackup) {
        $backupContent = file_get_contents($destPath);
    }
    
    // حفظ ملف الترخيص الجديد مؤقتاً للتحقق منه
    file_put_contents($destPath, $fileContent);
    
    // التحقق من صلاحية الملف الجديد
    $verify = $licensing->verifyLicense();
    if ($verify['status']) {
        $payload = $verify['data'];
        
        // حفظ بيانات التفعيل بقاعدة البيانات
        $conn->query("DELETE FROM system_licensing");
        
        $stmt = $conn->prepare("INSERT INTO system_licensing 
            (machine_id, company_name, owner_name, phone, city, license_type, start_date, expiry_date, modules_enabled, max_users, max_branches, license_key, activation_status, activated_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())");
        
        $city_val = trim($_POST['city'] ?? '');
        if (empty($city_val)) {
            $city_val = 'غير محدد';
        }
        
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
            // تصفير قفل التلاعب بالوقت
            $conn->query("UPDATE system_licensing SET tampering_lock = 0 WHERE id = 1");
            echo json_encode(['status' => 'success', 'message' => '✓ تم تفعيل وتنشيط النظام بنجاح! سيتم توجيهك الآن إلى معالج الإعداد...']);
            exit();
        } else {
            // استعادة النسخة الاحتياطية
            if ($hasBackup) {
                file_put_contents($destPath, $backupContent);
            } else {
                @unlink($destPath);
            }
            echo json_encode(['status' => 'error', 'message' => 'فشل تسجيل بيانات الترخيص في قاعدة البيانات: ' . $conn->error]);
            exit();
        }
    } else {
        // استعادة النسخة الاحتياطية
        if ($hasBackup) {
            file_put_contents($destPath, $backupContent);
        } else {
            @unlink($destPath);
        }
        echo json_encode(['status' => 'error', 'message' => 'كود التفعيل غير مطابق لجهازك الحالي أو منتهي الصلاحية: ' . $verify['message']]);
        exit();
    }
}

// 1. معالجة طلب تحميل ملف تفعيل العميل (.AQNEX)
if (isset($_POST['btn_request'])) {
    $company_name = trim($_POST['company_name']);
    $owner_name   = trim($_POST['owner_name']);
    $phone        = trim($_POST['phone']);
    $city         = trim($_POST['city']);

    if (!empty($company_name) && !empty($owner_name) && !empty($phone)) {
        $requestData = [
            'machine_id'   => $machineId,
            'company_name' => $company_name,
            'owner_name'   => $owner_name,
            'phone'        => $phone,
            'city'         => $city,
            'requested_at' => date('Y-m-d H:i:s')
        ];

        $payload = base64_encode(json_encode($requestData));
        
        // إجبار المتصفح على تحميل الملف المولد
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="activation_request.AQNEX"');
        echo $payload;
        exit();
    } else {
        $error_message = 'يرجى تعبئة جميع الحقول المطلوبة لإنشاء طلب التفعيل.';
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تفعيل النظام - AQNEX Business Solutions</title>
    
    <!-- Bootstrap 4 محلي -->
    <link rel="stylesheet" type="text/css" href="../files/bower_components/bootstrap/css/bootstrap.min.css">
    <!-- Font Awesome محلي -->
    <link rel="stylesheet" type="text/css" href="../files/bower_components/font-awesome/css/font-awesome.min.css">
    <!-- Bootstrap Icons محلي -->
    <link rel="stylesheet" type="text/css" href="../files/bootstrap-icons/bootstrap-icons.min.css">
    <!-- التنسيق المخصص للنظام للحصول على المظهر الموحد والمسطح -->
    <link rel="stylesheet" type="text/css" href="../css/custom.css">

    <style>
        body {
            background-color: var(--body-bg) !important;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .header-brand-bar {
            background-color: var(--primary-bg);
            color: #fff;
            padding: 15px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 2px solid var(--border-color);
        }

        .header-logo {
            font-size: 1.1rem;
            font-weight: 800;
            letter-spacing: 0.5px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .header-logo-text-teal {
            color: #14b8a6;
        }

        .activation-main-container {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px 20px;
        }

        .flat-card {
            background: #fff;
            border: 1px solid var(--border-color);
            width: 100%;
            max-width: 980px;
            padding: 30px;
        }

        .flat-title {
            color: var(--secondary-bg);
            font-weight: 700;
            border-bottom: 2px solid var(--border-color);
            padding-bottom: 12px;
            margin-bottom: 20px;
            font-size: 1.2rem;
        }

        .machine-id-container {
            background: var(--body-bg);
            border: 1px dashed var(--border-color);
            padding: 10px 12px;
            font-family: monospace;
            font-size: 0.85rem;
            color: var(--accent-info);
            word-break: break-all;
            position: relative;
            margin-bottom: 15px;
        }

        .machine-id-container button {
            position: absolute;
            left: 8px;
            top: 50%;
            transform: translateY(-50%);
            background: #e2e8f0;
            border: 1px solid var(--border-color);
            color: var(--text-color);
            padding: 2px 8px;
            cursor: pointer;
            font-size: 0.75rem;
        }

        .machine-id-container button:hover {
            background: var(--border-color);
        }

        .form-section-divider {
            border-left: 1px solid var(--border-color);
        }

        @media (max-width: 768px) {
            .form-section-divider {
                border-left: none;
                border-top: 1px solid var(--border-color);
                margin-top: 25px;
                padding-top: 25px;
            }
        }

        .activation-textarea {
            height: 120px;
            font-family: monospace !important;
            font-size: 0.8rem;
            resize: none;
        }

        /* شاشة الانتظار overlay */
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(255, 255, 255, 0.95);
            z-index: 99999;
            display: none;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
        }

        .loading-spinner {
            font-size: 3rem;
            color: var(--accent-info);
            margin-bottom: 20px;
        }

        .support-info-box {
            background: var(--body-bg);
            border: 1px solid var(--border-color);
            padding: 15px;
            margin-top: 20px;
        }

        .step-badge {
            background-color: var(--accent-info);
            color: white;
            width: 24px;
            height: 24px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 0.8rem;
            margin-left: 8px;
            vertical-align: middle;
        }
    </style>
</head>
<body>

    <!-- شريط الرأس الموحد -->
    <div class="header-brand-bar">
        <div class="header-logo">
            <i class="bi bi-key-fill header-logo-text-teal"></i>
            <span>AQNEX <span class="header-logo-text-teal">POS</span></span>
        </div>
        <div class="small text-muted">نظام ادارة المتاجر</div>
    </div>

    <!-- شاشة الانتظار المنبثقة -->
    <div id="loading-overlay" class="loading-overlay">
        <i class="bi bi-arrow-repeat loading-spinner"></i>
        <h5 id="loading-text" class="font-weight-bold" style="color: var(--primary-bg);">يرجى الانتظار... جاري التحقق من كود التفعيل وتنشيط النظام</h5>
        <p class="text-muted small">يرجى عدم إغلاق الصفحة أو تحديثها حتى تكتمل العملية.</p>
    </div>

    <div class="activation-main-container">
        <div class="flat-card">
            
            <!-- تنبيهات الأخطاء (AJAX) -->
            <div id="ajax-alert-error" class="alert alert-danger rounded-0 text-right mb-4" style="display: none;"></div>
            
            <?php if (!empty($error_message)): ?>
                <div class="alert alert-danger rounded-0 text-right mb-4">
                    <i class="bi bi-exclamation-triangle-fill ml-2"></i> <?php echo $error_message; ?>
                </div>
            <?php endif; ?>

            <div class="row">
                
                <!-- القسم الأول: طلب التفعيل -->
                <div class="col-md-6 text-right">
                    <h4 class="flat-title"><span class="step-badge">1</span>إنشاء طلب تفعيل رقمي</h4>
                    <p class="text-muted small mb-4">
                        يرجى إدخال معلومات منشأتك لتوليد ملف طلب الترخيص، ثم إرسال الملف المستخرج لشركة AQNEX لإصدار كود التفعيل.
                    </p>

                    <form method="POST">
                        <div class="form-group mb-3">
                            <label class="small font-weight-bold mb-1">اسم المنشأة / الشركة *</label>
                            <input type="text" name="company_name" id="company_name" class="form-control" placeholder="مثال: شركة النجم للتجارة" required>
                        </div>

                        <div class="form-group mb-3">
                            <label class="small font-weight-bold mb-1">اسم مالك النظام الكامل *</label>
                            <input type="text" name="owner_name" class="form-control" placeholder="مثال: أمين قحطان" required>
                        </div>

                        <div class="row">
                            <div class="col-6">
                                <div class="form-group mb-3">
                                    <label class="small font-weight-bold mb-1">رقم الجوال *</label>
                                    <input type="text" name="phone" class="form-control" placeholder="777777777" required>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="form-group mb-3">
                                    <label class="small font-weight-bold mb-1">المدينة</label>
                                    <input type="text" name="city" id="city_input" class="form-control" placeholder="مثال: عدن">
                                </div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="small font-weight-bold mb-1 text-info">معرف الجهاز الفريد (Machine ID)</label>
                            <div class="machine-id-container" id="machine_id_val">
                                <?php echo htmlspecialchars($machineId); ?>
                                <button type="button" onclick="copyMachineID()">نسخ</button>
                            </div>
                        </div>

                        <button type="submit" name="btn_request" class="btn-flat btn-flat-primary w-100 py-2">
                            <i class="bi bi-download ml-2"></i> تحميل ملف الطلب (.AQNEX)
                        </button>
                    </form>
                </div>

                <!-- القسم الثاني: تفعيل النظام بكود التفعيل -->
                <div class="col-md-6 text-right form-section-divider">
                    <h4 class="flat-title"><span class="step-badge">2</span>تنشيط النظام بكود التفعيل</h4>
                    <p class="text-muted small mb-4">
                        بعد إرسال ملف طلب التفعيل، ستقوم خدمة العملاء بتزويدك بـ **كود تفعيل نصي**. الصق الكود في الحقل أدناه لتنشيط النظام.
                    </p>

                    <form id="activation-form">
                        <div class="form-group mb-3">
                            <label class="small font-weight-bold mb-2">أدخل كود التفعيل المستلم من الشركة:</label>
                            <textarea id="activation_code" class="form-control activation-textarea" placeholder="قم بلصق كود التفعيل النصي الطويل المنسوخ هنا..."></textarea>
                        </div>

                        <div class="form-group mb-4">
                            <label class="small font-weight-bold mb-2">أو اختر ملف الترخيص (.AQNEX) مباشرة:</label>
                            <div class="custom-file">
                                <input type="file" class="custom-file-input" id="license_file" accept=".AQNEX">
                                <label class="custom-file-label text-right" for="license_file" id="license_file_label">اختر ملف الترخيص...</label>
                            </div>
                        </div>

                        <button type="submit" class="btn-flat btn-flat-success w-100 py-2" style="margin-top: 15px;">
                            <i class="bi bi-key-fill ml-2"></i> تفعيل الترخيص والبدء فوراً
                        </button>
                    </form>

                    <div class="support-info-box">
                        <h6 class="font-weight-bold mb-2 text-secondary"><i class="bi bi-info-circle-fill ml-1"></i> الدعم الفني والمبيعات:</h6>
                        <span class="text-muted d-block small">هاتف / واتساب: 777777777</span>
                        <span class="text-muted d-block small">البريد الإلكتروني: support@aqnex.com</span>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <!-- تضمين مكتبة jQuery المحلية المتوفرة بالنظام -->
    <script type="text/javascript" src="../files/bower_components/jquery/js/jquery.min.js"></script>

    <script>
        // نسخ معرف الجهاز لحافظة النظام
        function copyMachineID() {
            const text = "<?php echo $machineId; ?>";
            navigator.clipboard.writeText(text).then(function() {
                alert('✓ تم نسخ معرف الجهاز الفريد لحافظة النظام بنجاح.');
            }, function(err) {
                alert('فشل نسخ معرف الجهاز: ' + err);
            });
        }

        // معالجة إرسال كود التفعيل بالـ AJAX مع رسائل الانتظار
        $(document).ready(function() {
            // تحديث اسم الملف المختار في الواجهة
            $('#license_file').on('change', function() {
                var fileName = $(this).val().split('\\').pop();
                if (fileName) {
                    $('#license_file_label').addClass("selected").html(fileName);
                } else {
                    $('#license_file_label').removeClass("selected").html("اختر ملف الترخيص...");
                }
            });

            $('#activation-form').on('submit', function(e) {
                e.preventDefault();
                
                var codeVal = $('#activation_code').val().trim();
                var cityVal = $('#city_input').val().trim();
                var fileInput = document.getElementById('license_file');
                
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
                        
                        sendActivationAjax(processedCode, cityVal);
                    };
                    reader.readAsText(file);
                } else {
                    if (codeVal === "") {
                        alert("يرجى إدخال كود التفعيل أو اختيار ملف الترخيص أولاً.");
                        return;
                    }
                    sendActivationAjax(codeVal, cityVal);
                }
            });

            function sendActivationAjax(codeVal, cityVal) {
                // إظهار شاشة الانتظار
                $('#ajax-alert-error').hide();
                $('#loading-overlay').css('display', 'flex');
                
                $.ajax({
                    url: 'activate.php',
                    type: 'POST',
                    data: {
                        ajax_activate: 1,
                        activation_code: codeVal,
                        city: cityVal
                    },
                    dataType: 'json',
                    success: function(response) {
                        if (response.status === 'success') {
                            // تغيير رسالة الانتظار لتؤكد النجاح
                            $('#loading-text').html('<span style="color:#0f766e;">' + response.message + '</span>');
                            setTimeout(function() {
                                window.location.href = 'setup_wizard.php';
                            }, 2000);
                        } else {
                            // إخفاء شاشة الانتظار وإظهار الخطأ
                            $('#loading-overlay').hide();
                            $('#ajax-alert-error').html('<i class="fa fa-exclamation-triangle ml-2"></i> ' + response.message).show();
                            $('html, body').animate({ scrollTop: 0 }, 'slow');
                        }
                    },
                    error: function(xhr, status, error) {
                        $('#loading-overlay').hide();
                        $('#ajax-alert-error').html('<i class="fa fa-exclamation-triangle ml-2"></i> فشل الاتصال بالخادم والتحقق: ' + error).show();
                        $('html, body').animate({ scrollTop: 0 }, 'slow');
                    }
                });
            }
        });
    </script>
</body>
</html>
