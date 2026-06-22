<?php
/**
 * لوحة إدارة وإصدار التراخيص الرقمية للشركة - نسخة مستقلة
 */

$privateKeyPath = __DIR__ . '/private.key';

// 1. توليد زوج المفاتيح في حال عدم وجود المفتاح الخاص
$regenerationMessage = '';
if (isset($_POST['btn_generate_keys']) || !file_exists($privateKeyPath) || @filesize($privateKeyPath) < 10) {
    $config = array(
        "digest_alg" => "sha256",
        "private_key_bits" => 2048,
        "private_key_type" => OPENSSL_KEYTYPE_RSA,
    );
    // حل مشكلة OpenSSL cnf في بيئة ويندوز
    $cnfPaths = [
        'C:/xampp/php/extras/ssl/openssl.cnf',
        'C:/xampp/apache/conf/openssl.cnf',
        'C:/xampp/php/extras/openssl/openssl.cnf',
        'C:/xampp/php/windowsXamppPhp/extras/ssl/openssl.cnf'
    ];
    foreach ($cnfPaths as $path) {
        if (file_exists($path)) {
            $config['config'] = $path;
            break;
        }
    }

    $res = openssl_pkey_new($config);
    if ($res !== false) {
        openssl_pkey_export($res, $privKey, null, $config);
        file_put_contents($privateKeyPath, $privKey);

        $pubKeyDetails = openssl_pkey_get_details($res);
        $pubKey = $pubKeyDetails["key"];
        
        // حفظ المفتاح العام مؤقتاً أيضاً لمن يريد نسخه
        file_put_contents(__DIR__ . '/public.key', $pubKey);
        
        $regenerationMessage = "✓ تم توليد زوج مفاتيح جديد متطابق بنجاح وحفظ المفتاح الخاص محلياً.";
    }
}

// 2. معالجة إصدار الترخيص وتوليد كود التفعيل
$generatedCode = '';
if (isset($_POST['btn_issue'])) {
    $machine_id = trim($_POST['machine_id']);
    $company = trim($_POST['company']);
    $owner = trim($_POST['owner']);
    $phone = trim($_POST['phone']);
    $type = trim($_POST['type']);
    $expiry = trim($_POST['expiry']);
    $modules = implode(',', $_POST['modules'] ?? []);

    $payload = [
        'machine_id' => $machine_id,
        'company_name' => $company,
        'owner_name' => $owner,
        'phone' => $phone,
        'license_type' => $type,
        'start_date' => date('Y-m-d'),
        'expiry_date' => $expiry,
        'modules_enabled' => $modules,
        'max_users' => 10,
        'max_branches' => 3,
        'issued_at' => date('Y-m-d H:i:s')
    ];

    $payloadBase64 = base64_encode(json_encode($payload));
    
    // تحميل المفتاح الخاص للتوقيع
    $privKey = file_get_contents($privateKeyPath);
    $resKey = openssl_get_privatekey($privKey);
    
    // التوقيع الرقمي للمحتوى
    $signature = '';
    openssl_sign($payloadBase64, $signature, $resKey, OPENSSL_ALGO_SHA256);
    $signatureBase64 = base64_encode($signature);

    // تجهيز الهيكل النهائي للترخيص
    $licenseData = [
        'payload' => $payloadBase64,
        'signature' => $signatureBase64
    ];

    // تشفير ملف الترخيص بالكامل ليعطي كود تفعيل نصي
    $generatedCode = base64_encode(json_encode($licenseData));
}

// قراءة المفتاح العام الحالي لعرضه لنسخه ولصقه في كود Licensing.php
$currentPublicKey = '';
if (file_exists(__DIR__ . '/public.key')) {
    $currentPublicKey = file_get_contents(__DIR__ . '/public.key');
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>لوحة إصدار التراخيص الرقمية - AQNEX Standalone Generator</title>
    
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
            --accent-light: #f1f5f9;
        }
        
        * {
            box-sizing: border-box;
            font-family: 'Tajawal', Tahoma, sans-serif;
            border-radius: 0 !important;
            box-shadow: none !important;
        }

        body {
            background-color: var(--body-bg);
            color: var(--text-color);
            margin: 0;
            padding: 40px 20px;
            font-size: 13px;
            direction: rtl;
            text-align: right;
        }

        .container {
            max-width: 800px;
            background: #fff;
            padding: 30px;
            margin: 0 auto;
            border: 1px solid var(--border-color);
        }

        h2 {
            color: var(--primary-bg);
            border-bottom: 2px solid var(--primary-bg);
            padding-bottom: 15px;
            margin-top: 0;
            margin-bottom: 25px;
            font-weight: 700;
            font-size: 1.4rem;
        }

        h5 {
            font-size: 1.05rem;
            font-weight: 700;
            margin: 0 0 10px 0;
        }

        .form-group {
            margin-bottom: 18px;
        }

        label {
            display: block;
            font-weight: 700;
            margin-bottom: 6px;
            color: var(--secondary-bg);
        }

        input[type="text"], select, input[type="date"], textarea {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid var(--border-color);
            background-color: #fff;
            color: var(--text-color);
            outline: none;
            font-size: 0.85rem;
        }

        input[type="text"]:focus, select:focus, input[type="date"]:focus, textarea:focus {
            border-color: var(--accent-teal);
        }

        .btn-teal {
            background: var(--accent-teal);
            color: #fff;
            border: 1px solid transparent;
            padding: 10px 20px;
            cursor: pointer;
            font-weight: 700;
            font-size: 0.85rem;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-teal:hover {
            background: var(--accent-teal-hover);
        }

        .btn-copy {
            background: var(--secondary-bg);
            color: #fff;
            border: 1px solid transparent;
            padding: 6px 14px;
            cursor: pointer;
            font-weight: 700;
            font-size: 0.8rem;
            margin-top: 10px;
        }

        .btn-copy:hover {
            background: var(--primary-bg);
        }

        .alert-success {
            padding: 15px;
            background: #d1fae5;
            border: 1px solid #34d399;
            color: #065f46;
            margin-bottom: 25px;
        }

        .code-box {
            background: var(--accent-light);
            border: 1px solid var(--border-color);
            padding: 15px;
            margin-bottom: 25px;
        }

        .code-textarea {
            height: 120px;
            font-family: monospace !important;
            font-size: 0.8rem !important;
            background: #f8fafc;
            resize: none;
        }

        .grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }

        .modules-container {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 8px;
            background: var(--accent-light);
            padding: 15px;
            border: 1px solid var(--border-color);
        }

        .modules-container label {
            font-weight: normal;
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            margin-bottom: 0;
        }

        .upload-box {
            border: 2px dashed var(--accent-teal);
            padding: 18px;
            background: rgba(15, 118, 110, 0.03);
            margin-bottom: 25px;
            text-align: center;
        }

        .upload-box input[type="file"] {
            display: none;
        }

        .upload-label {
            background: var(--accent-teal);
            color: white;
            padding: 6px 12px;
            cursor: pointer;
            display: inline-block;
            font-weight: 700;
            margin-bottom: 8px;
        }

        .upload-label:hover {
            background: var(--accent-teal-hover);
        }
    </style>
</head>
<body>
<div class="container">
    <h2>لوحة التحكم لإصدار وتوليد التراخيص البرمجية</h2>

    <?php if (!empty($regenerationMessage)): ?>
        <div class="alert alert-info py-2 rounded-0 small text-right mb-4">
            <i class="fa fa-info-circle ml-2"></i> <?php echo htmlspecialchars($regenerationMessage); ?>
        </div>
    <?php endif; ?>

    <!-- صندوق رفع ملف العميل -->
    <div class="upload-box">
        <h5 style="color: var(--accent-teal); margin-bottom: 5px;">استيراد ملف طلب العميل تلقائياً</h5>
        <p class="small text-muted" style="margin-top: 0; margin-bottom: 12px;">اختر ملف طلب الترخيص المستلم من العميل ليتم تعبئة الحقول تلقائياً</p>
        <label for="request_file" class="upload-label">اختر ملف الطلب</label>
        <input type="file" id="request_file" accept=".AQNEX" onchange="loadRequestFile(this)">
        <div id="upload_status" class="small font-weight-bold" style="color: var(--accent-teal); display:none;"></div>
    </div>

    <?php if (!empty($generatedCode)): ?>
        <div class="alert-success">
            <h5>✓ تم توليد الترخيص وكود التفعيل بنجاح!</h5>
            <p style="margin: 5px 0 10px 0; font-size: 0.8rem;">قم بنسخ كود التفعيل أدناه وإرساله للعميل لتنشيط نظامه فوراً:</p>
            <div class="code-box">
                <textarea class="code-textarea" id="activation_code_val" readonly onclick="this.select()"><?php echo htmlspecialchars($generatedCode); ?></textarea>
                <button type="button" class="btn-copy" onclick="copyActivationCode()">نسخ كود التفعيل للحافظة</button>
            </div>
        </div>
    <?php endif; ?>

    <form method="POST">
        <div class="form-group">
            <label>معرف الجهاز الفريد للعميل (Machine ID) *</label>
            <input type="text" name="machine_id" id="machine_id" placeholder="الصق معرف الجهاز المستخرج من العميل" required>
        </div>
        
        <div class="grid-2">
            <div class="form-group">
                <label>اسم المنشأة / الشركة *</label>
                <input type="text" name="company" id="company" placeholder="تكنولوجيا فون" required>
            </div>
            <div class="form-group">
                <label>اسم المالك الكامل *</label>
                <input type="text" name="owner" id="owner" placeholder="أمين قحطان" required>
            </div>
        </div>

        <div class="grid-2">
            <div class="form-group">
                <label>رقم الجوال *</label>
                <input type="text" name="phone" id="phone" placeholder="777777777" required>
            </div>
            <div class="form-group">
                <label>المدينة</label>
                <input type="text" name="city" id="city" placeholder="عدن">
            </div>
        </div>

        <div class="grid-2">
            <div class="form-group">
                <label>نوع باقة الترخيص *</label>
                <select name="type" id="license_type" onchange="adjustExpiry(this.value)">
                    <option value="trial">تجريبي (Trial)</option>
                    <option value="monthly">شهري (Monthly)</option>
                    <option value="yearly">سنوي (Yearly)</option>
                    <option value="lifetime" selected>دائم (Lifetime)</option>
                </select>
            </div>
            <div class="form-group">
                <label>تاريخ انتهاء الترخيص *</label>
                <input type="date" name="expiry" id="expiry_date" value="2035-12-31">
            </div>
        </div>

        <div class="form-group">
            <label>الوحدات والوحدات المشمولة بالتفعيل</label>
            <div class="modules-container">
                <label><input type="checkbox" name="modules[]" value="sales" checked> المبيعات</label>
                <label><input type="checkbox" name="modules[]" value="inventory" checked> المخازن</label>
                <label><input type="checkbox" name="modules[]" value="purchases" checked> المشتريات</label>
                <label><input type="checkbox" name="modules[]" value="accounting" checked> الحسابات</label>
                <label><input type="checkbox" name="modules[]" value="crm" checked> العملاء</label>
                <label><input type="checkbox" name="modules[]" value="reports" checked> التقارير</label>
            </div>
        </div>

        <button type="submit" name="btn_issue" class="btn-teal" style="width:100%; justify-content:center; padding:12px; margin-bottom: 25px;">
            <i class="fa fa-cogs"></i> إصدار وتوليد كود التفعيل
        </button>
    </form>

    <!-- تبويب المفاتيح العامة والخاصة لإعادة التهيئة -->
    <hr>
    <div class="card p-3 rounded-0 bg-light">
        <h5 class="text-secondary font-weight-bold mb-2">إعداد مفاتيح RSA وتشفير الأنظمة الأخرى</h5>
        <p class="text-muted small">
            إذا كنت تريد استخدام هذه الحزمة في نظام آخر مستقل، يجب عليك توليد زوج مفاتيح جديد، ولصق **المفتاح العام** أدناه داخل ملف `Licensing.php` الخاص بالنظام الجديد لضمان التحقق السليم.
        </p>
        
        <?php if (!empty($currentPublicKey)): ?>
            <div class="form-group mt-3">
                <label class="small font-weight-bold text-teal-700">المفتاح العام (المطلوب لصقه في Licensing.php للمشروع الجديد)</label>
                <textarea class="form-control" style="font-family: monospace; font-size: 0.75rem; height: 110px; background: #fff;" readonly onclick="this.select()"><?php echo htmlspecialchars($currentPublicKey); ?></textarea>
            </div>
        <?php endif; ?>

        <form method="POST">
            <button type="submit" name="btn_generate_keys" class="btn btn-outline-danger btn-sm rounded-0 font-weight-bold" onclick="return confirm('تنبيه: توليد مفاتيح جديدة سيعطل صلاحية جميع الأكواد التي أصدرتها سابقاً بهذا المفتاح. هل أنت متأكد؟')">
                <i class="fa fa-refresh"></i> توليد زوج مفاتيح RSA جديد كلياً
            </button>
        </form>
    </div>
</div>

<!-- jQuery via CDN -->
<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>

<script>
    // ضبط التواريخ تلقائياً
    function adjustExpiry(type) {
        const expiryInput = document.getElementById('expiry_date');
        const today = new Date();
        if (type === 'trial') {
            today.setDate(today.getDate() + 15);
        } else if (type === 'monthly') {
            today.setMonth(today.getMonth() + 1);
        } else if (type === 'yearly') {
            today.setFullYear(today.getFullYear() + 1);
        } else if (type === 'lifetime') {
            expiryInput.value = '2035-12-31';
            return;
        }
        const yyyy = today.getFullYear();
        let mm = today.getMonth() + 1;
        let dd = today.getDate();
        if (mm < 10) mm = '0' + mm;
        if (dd < 10) dd = '0' + dd;
        expiryInput.value = yyyy + '-' + mm + '-' + dd;
    }

    // قراءة ملف طلب العميل
    function loadRequestFile(fileInput) {
        const file = fileInput.files[0];
        const statusDiv = document.getElementById('upload_status');
        if (!file) return;

        statusDiv.style.display = 'block';
        statusDiv.style.color = '#0f766e';
        statusDiv.innerText = 'جاري قراءة ملف الطلب...';

        const reader = new FileReader();
        reader.onload = function(e) {
            try {
                const rawContent = e.target.result.trim();
                const decodedStr = decodeURIComponent(escape(atob(rawContent)));
                const data = JSON.parse(decodedStr);
                
                if (data.machine_id) {
                    document.getElementById('machine_id').value = data.machine_id || '';
                    document.getElementById('company').value = data.company_name || '';
                    document.getElementById('owner').value = data.owner_name || '';
                    document.getElementById('phone').value = data.phone || '';
                    if (data.city) {
                        document.getElementById('city').value = data.city || '';
                    }
                    
                    statusDiv.style.color = '#065f46';
                    statusDiv.innerText = '✓ تم تحميل بيانات طلب العميل بنجاح!';
                } else {
                    statusDiv.style.color = '#be123c';
                    statusDiv.innerText = 'خطأ: ملف طلب غير صالح.';
                }
            } catch (err) {
                statusDiv.style.color = '#be123c';
                statusDiv.innerText = 'خطأ في معالجة الملف: ' + err.message;
            }
        };
        reader.readAsText(file);
    }

    // نسخ الكود المولد
    function copyActivationCode() {
        const codeArea = document.getElementById('activation_code_val');
        codeArea.select();
        navigator.clipboard.writeText(codeArea.value).then(function() {
            alert('✓ تم نسخ كود التفعيل لحافظة الجهاز بنجاح.');
        }, function(err) {
            alert('فشل نسخ كود التفعيل: ' + err);
        });
    }
</script>
</body>
</html>
