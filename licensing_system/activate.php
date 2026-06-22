<?php
/**
 * معالج التنشيط وتفعيل الأنظمة البرمجية - نسخة مستقلة
 */

// 1. استدعاء كلاس التراخيص (تأكد من تعديل المسار إذا قمت بنقله)
require_once(__DIR__ . '/Licensing.php');

$licensing = new \AQNEX\Licensing\Licensing();
$machineId = \AQNEX\Licensing\Licensing::generateMachineID();

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
        echo json_encode(['status' => 'error', 'message' => 'كود التفعيل المدخل غير صالح أو تالف. يرجى التأكد من نسخه بالكامل.']);
        exit();
    }
    
    // مسار حفظ الترخيص النهائي (يمكنك تعديل المجلد كما تشاء)
    $destPath = __DIR__ . '/license.AQNEX';
    
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
        
        // ====================================================================
        // [اختياري] TODO: قم بدمج قاعدة بيانات نظامك هنا لتسجيل التفعيل محلياً:
        // ====================================================================
        /*
        $conn = new mysqli("localhost", "root", "", "YOUR_DATABASE_NAME");
        if (!$conn->connect_error) {
            $conn->query("DELETE FROM your_licensing_table");
            $stmt = $conn->prepare("INSERT INTO your_licensing_table (machine_id, company_name, license_type, expiry_date, modules_enabled, activated_at) VALUES (?, ?, ?, ?, ?, NOW())");
            $stmt->bind_param("sssss", $payload['machine_id'], $payload['company_name'], $payload['license_type'], $payload['expiry_date'], $payload['modules_enabled']);
            $stmt->execute();
        }
        */
        
        echo json_encode(['status' => 'success', 'message' => '✓ تم تفعيل وتنشيط ترخيص النظام بنجاح! سيتم توجيهك الآن...']);
        exit();
    } else {
        // استعادة النسخة الاحتياطية في حال فشل التحقق
        if ($hasBackup) {
            file_put_contents($destPath, $backupContent);
        } else {
            @unlink($destPath);
        }
        echo json_encode(['status' => 'error', 'message' => 'كود التفعيل غير صالح لجهاز الكمبيوتر الحالي: ' . $verify['message']]);
        exit();
    }
}

// 2. معالجة طلب تحميل ملف تفعيل العميل (.AQNEX)
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
        
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="activation_request.AQNEX"');
        echo $payload;
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تفعيل النظام - بوابة التراخيص الرقمية</title>
    
    <!-- Bootstrap 4 & FontAwesome via CDNs for standalone use -->
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700&display=swap" rel="stylesheet">

    <style>
        :root {
            --primary-bg: #0f172a;
            --secondary-bg: #1e293b;
            --body-bg: #f8fafc;
            --text-color: #334155;
            --border-color: #cbd5e1;
            --accent-teal: #0f766e;
            --accent-teal-hover: #0d635c;
            --accent-info: #0369a1;
        }

        * {
            box-sizing: border-box;
            font-family: 'Tajawal', Tahoma, sans-serif;
            border-radius: 0 !important;
            box-shadow: none !important;
        }

        body {
            background-color: var(--body-bg) !important;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            color: var(--text-color);
            margin: 0;
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
            font-size: 1.15rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .header-logo i {
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
            max-width: 950px;
            padding: 30px;
        }

        .flat-title {
            color: var(--secondary-bg);
            font-weight: 700;
            border-bottom: 2px solid var(--border-color);
            padding-bottom: 12px;
            margin-bottom: 20px;
            font-size: 1.1rem;
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
            width: 100%;
            padding: 8px 12px;
            border: 1px solid var(--border-color);
        }

        .form-control {
            border: 1px solid var(--border-color);
        }

        .form-control:focus {
            border-color: var(--accent-info);
        }

        .btn-flat-primary {
            background-color: var(--accent-info);
            color: #fff;
            border: 1px solid transparent;
            font-weight: 500;
            padding: 8px 16px;
            cursor: pointer;
        }

        .btn-flat-primary:hover {
            background-color: #025a87;
        }

        .btn-flat-success {
            background-color: var(--accent-teal);
            color: #fff;
            border: 1px solid transparent;
            font-weight: 500;
            padding: 8px 16px;
            cursor: pointer;
        }

        .btn-flat-success:hover {
            background-color: var(--accent-teal-hover);
        }

        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(255, 255, 255, 0.95);
            z-index: 9999;
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

    <div class="header-brand-bar">
        <div class="header-logo">
            <i class="fa fa-key"></i>
            <span>بوابة تفعيل تراخيص الأنظمة البرمجية</span>
        </div>
        <div class="small text-muted font-weight-bold">Licensing System Manager</div>
    </div>

    <!-- شاشة الانتظار المنبثقة -->
    <div id="loading-overlay" class="loading-overlay">
        <i class="fa fa-refresh fa-spin loading-spinner"></i>
        <h5 id="loading-text" class="font-weight-bold" style="color: var(--primary-bg);">يرجى الانتظار... جاري التحقق من كود التفعيل وتنشيط النظام</h5>
        <p class="text-muted small">يرجى عدم إغلاق الصفحة أو تحديثها حتى تكتمل العملية.</p>
    </div>

    <div class="activation-main-container">
        <div class="flat-card">
            
            <div id="ajax-alert-error" class="alert alert-danger text-right mb-4" style="display: none;"></div>
            <div id="ajax-alert-success" class="alert alert-success text-right mb-4" style="display: none;"></div>

            <div class="row">
                
                <!-- القسم الأول: طلب التفعيل -->
                <div class="col-md-6 text-right">
                    <h4 class="flat-title"><span class="step-badge">1</span>إنشاء ملف طلب الترخيص</h4>
                    <p class="text-muted small mb-4">
                        يرجى إدخال معلومات منشأتك لتوليد ملف طلب الترخيص، ثم إرسال الملف المستخرج لخدمة العملاء لإصدار كود التفعيل الخاص بك.
                    </p>

                    <form method="POST">
                        <div class="form-group mb-3">
                            <label class="small font-weight-bold mb-1">اسم المنشأة / العميل *</label>
                            <input type="text" name="company_name" class="form-control" placeholder="مثال: مؤسسة النور البرمجية" required>
                        </div>

                        <div class="form-group mb-3">
                            <label class="small font-weight-bold mb-1">اسم مالك النظام الكامل *</label>
                            <input type="text" name="owner_name" class="form-control" placeholder="مثال: خالد محمد" required>
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
                                    <input type="text" name="city" class="form-control" placeholder="مثال: عدن">
                                </div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="small font-weight-bold mb-1 text-info">معرف الجهاز الفريد (Machine ID)</label>
                            <div class="machine-id-container">
                                <?php echo htmlspecialchars($machineId); ?>
                                <button type="button" onclick="copyMachineID()">نسخ</button>
                            </div>
                        </div>

                        <button type="submit" name="btn_request" class="btn-flat-primary w-100 py-2">
                            <i class="fa fa-download ml-2"></i> تحميل ملف طلب الترخيص (.AQNEX)
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
                        <div class="form-group mb-4">
                            <label class="small font-weight-bold mb-2">أدخل كود التفعيل المستلم *</label>
                            <textarea id="activation_code" class="activation-textarea" placeholder="قم بلصق كود التفعيل الطويل المنسوخ هنا..." required></textarea>
                        </div>

                        <button type="submit" class="btn-flat-success w-100 py-2" style="margin-top: 30px;">
                            <i class="fa fa-key ml-2"></i> تفعيل الترخيص والبدء فوراً
                        </button>
                    </form>

                    <div class="support-info-box">
                        <h6 class="font-weight-bold mb-2 text-secondary"><i class="fa fa-info-circle ml-1"></i> معلومات الدعم الفني:</h6>
                        <span class="text-muted d-block small">جوال: 777777777</span>
                        <span class="text-muted d-block small">البريد الإلكتروني: support@yourcompany.com</span>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <!-- تضمين مكتبة jQuery عبر CDN -->
    <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>

    <script>
        // نسخ معرف الجهاز
        function copyMachineID() {
            const text = "<?php echo $machineId; ?>";
            navigator.clipboard.writeText(text).then(function() {
                alert('✓ تم نسخ معرف الجهاز الفريد لحافظة النظام بنجاح.');
            }, function(err) {
                alert('فشل نسخ معرف الجهاز: ' + err);
            });
        }

        // معالجة إرسال الكود بالـ AJAX
        $(document).ready(function() {
            $('#activation-form').on('submit', function(e) {
                e.preventDefault();
                
                const codeVal = $('#activation_code').val().trim();
                if (codeVal === "") {
                    alert("يرجى إدخال كود التفعيل أولاً.");
                    return;
                }
                
                $('#ajax-alert-error').hide();
                $('#ajax-alert-success').hide();
                $('#loading-overlay').css('display', 'flex');
                
                $.ajax({
                    url: 'activate.php',
                    type: 'POST',
                    data: {
                        ajax_activate: 1,
                        activation_code: codeVal
                    },
                    dataType: 'json',
                    success: function(response) {
                        if (response.status === 'success') {
                            $('#loading-text').html('<span style="color:#0f766e;">' + response.message + '</span>');
                            setTimeout(function() {
                                // التوجيه للصفحة الرئيسية لنظامك بعد النجاح
                                window.location.href = '../index.php'; 
                            }, 2000);
                        } else {
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
            });
        });
    </script>
</body>
</html>
