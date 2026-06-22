<?php
/**
 * أداة AQNEX License Manager - خاص بالشركة لإصدار التراخيص وتوليد المفاتيح
 */

$keysDir = __DIR__ . '/../core/keys';
$privateKeyPath = __DIR__ . '/private.key';

$possiblePubKeys = [
    $keysDir . '/public_key.pem',
    $keysDir . '/public.pem',
    $keysDir . '/public.key'
];

$publicKeyPath = $keysDir . '/public_key.pem'; // Default write path
foreach ($possiblePubKeys as $path) {
    if (file_exists($path) && filesize($path) > 10) {
        $publicKeyPath = $path;
        break;
    }
}

// 1. التأكد من وجود المجلدات
if (!is_dir($keysDir)) {
    mkdir($keysDir, 0777, true);
}

// 2. توليد زوج المفاتيح في حال عدم وجود المفتاح الخاص أو كونه فارغاً
if (!file_exists($privateKeyPath) || @filesize($privateKeyPath) < 10) {
    echo "إعداد النظام: يتم الآن توليد زوج مفاتيح التشفير RSA 2048-bit...\n";
    $config = array(
        "digest_alg" => "sha256",
        "private_key_bits" => 2048,
        "private_key_type" => OPENSSL_KEYTYPE_RSA,
    );
    // On Windows, if PHP can't find openssl.cnf, openssl_pkey_new fails.
    // We search and inject a known cnf path if it exists.
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
    if ($res === false) {
        $errs = '';
        while ($msg = openssl_error_string()) {
            $errs .= $msg . " | ";
        }
        die("خطأ: فشل توليد مفاتيح التشفير. تفاصيل الخطأ: " . $errs . "\n");
    }

    // تصدير المفتاح الخاص
    if (!openssl_pkey_export($res, $privKey, null, $config)) {
        $errs = '';
        while ($msg = openssl_error_string()) {
            $errs .= $msg . " | ";
        }
        die("خطأ: فشل تصدير المفتاح الخاص. تفاصيل الخطأ: " . $errs . "\n");
    }
    file_put_contents($privateKeyPath, $privKey);

    // تصدير المفتاح العام
    $pubKeyDetails = openssl_pkey_get_details($res);
    $pubKey = $pubKeyDetails["key"];
    file_put_contents($publicKeyPath, $pubKey);
    echo "✓ تم توليد المفاتيح بنجاح وحفظها:\n - المفتاح الخاص: $privateKeyPath\n - المفتاح العام: $publicKeyPath\n\n";
}

if (php_sapi_name() === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    if ($argc < 2) {
        showUsage();
        exit(1);
    }

    $action = $argv[1];
    if ($action === 'generate') {
        if ($argc < 8) {
            echo "خطأ: المعاملات غير كافية للتوليد.\n";
            showUsage();
            exit(1);
        }
        $machine_id = $argv[2];
        $company    = $argv[3];
        $owner      = $argv[4];
        $phone      = $argv[5];
        $type       = $argv[6]; // trial, lifetime, yearly...
        $expiry     = $argv[7]; // YYYY-MM-DD
        $modules    = isset($argv[8]) ? $argv[8] : 'sales,inventory,purchases,accounting,crm,hr,reports'; // موديولات مفعلة
        
        issueLicense($machine_id, $company, $owner, $phone, $type, $expiry, $modules, $privateKeyPath);
    } elseif ($action === 'read') {
        if ($argc < 3) {
            die("خطأ: يرجى تحديد مسار ملف طلب التفعيل activation_request.AQNEX\n");
        }
        readRequest($argv[2]);
    } else {
        showUsage();
    }
} else {
    // تشغيل عبر المتصفح كواجهة بسيطة للتجريب السريع
    showWebInterface($privateKeyPath, $publicKeyPath);
}

function showUsage() {
    echo "=== AQNEX License Generator CLI ===\n";
    echo "الاستخدام لقراءة ملف طلب العميل:\n";
    echo "  php license_generator.php read <path_to_request.AQNEX>\n\n";
    echo "الاستخدام لإصدار رخصة جديدة:\n";
    echo "  php license_generator.php generate <machine_id> <company_name> <owner_name> <phone> <license_type> <expiry_date> [modules]\n";
    echo "مثال:\n";
    echo "  php license_generator.php generate \"sha256_hash_here\" \"تكنولوجيا فون\" \"أمين قحطان\" \"777777777\" \"lifetime\" \"2030-12-31\"\n";
}

function readRequest($reqPath) {
    if (!file_exists($reqPath)) {
        die("خطأ: الملف غير موجود على المسار المحدد: $reqPath\n");
    }
    $content = file_get_contents($reqPath);
    $data = json_decode(base64_decode($content), true);
    if (!$data) {
        die("خطأ: ملف الطلب تالف أو غير صالح.\n");
    }
    echo "=== بيانات طلب التفعيل المستلمة ===\n";
    echo "معرف الجهاز (Machine ID): " . $data['machine_id'] . "\n";
    echo "المنشأة: " . $data['company_name'] . "\n";
    echo "المالك: " . $data['owner_name'] . "\n";
    echo "الهاتف: " . $data['phone'] . "\n";
    echo "المدينة: " . $data['city'] . "\n";
    echo "==================================\n";
}

function issueLicense($machine_id, $company, $owner, $phone, $type, $expiry, $modules, $privateKeyPath) {
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

    // استخراج المفتاح العام المقابل للمفتاح الخاص ديناميكياً لتضمينه في الكود
    $pubKeyDetails = openssl_pkey_get_details($resKey);
    $publicKeyPEM = isset($pubKeyDetails["key"]) ? $pubKeyDetails["key"] : '';

    // تجهيز الهيكل النهائي للملف مضافاً إليه المفتاح العام الديناميكي
    $licenseData = [
        'payload' => $payloadBase64,
        'signature' => $signatureBase64,
        'public_key' => $publicKeyPEM
    ];

    $jsonStr = json_encode($licenseData, JSON_PRETTY_PRINT);
    $activationCode = base64_encode($jsonStr);

    // حفظ الملف في مجلد الترخيص الرئيسي للتطبيق (للتجريب المحلي والسريع)
    $dest = __DIR__ . '/../license/license.AQNEX';
    if (!is_dir(dirname($dest))) {
        @mkdir(dirname($dest), 0777, true);
    }
    @file_put_contents($dest, $jsonStr);
    
    if (php_sapi_name() === 'cli') {
        echo "✓ تم إصدار ملف الترخيص بنجاح وحفظه في:\n $dest\n\n";
        echo "كود التفعيل النصي الخاص بالعميل (Activation Code):\n";
        echo $activationCode . "\n";
    }
    
    return $activationCode;
}

function showWebInterface($privateKeyPath, $publicKeyPath) {
    header('Content-Type: text/html; charset=utf-8');
    
    $generatedCode = '';
    if (isset($_POST['btn_issue'])) {
        $machine_id = trim($_POST['machine_id']);
        $company = trim($_POST['company']);
        $owner = trim($_POST['owner']);
        $phone = trim($_POST['phone']);
        $type = trim($_POST['type']);
        $expiry = trim($_POST['expiry']);
        $modules = implode(',', $_POST['modules'] ?? []);

        $generatedCode = issueLicense($machine_id, $company, $owner, $phone, $type, $expiry, $modules, $privateKeyPath);
    }
    ?>
    <!DOCTYPE html>
    <html lang="ar" dir="rtl">
    <head>
        <meta charset="UTF-8">
        <title>AQNEX License Manager - لوحة توليد التراخيص</title>
        <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@300;400;500;700&display=swap" rel="stylesheet">
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
                font-family: 'Tajawal', Tahoma, Geneva, Verdana, sans-serif;
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
                max-width: 750px;
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
                font-size: 1.5rem;
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
                grid-template-columns: 1fr 1fr;
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
        <h2>لوحة إدارة التراخيص AQNEX Business Solutions</h2>

        <!-- صندوق رفع ملف العميل -->
        <div class="upload-box">
            <h5 style="color: var(--accent-teal); margin-bottom: 5px;">استيراد بيانات العميل تلقائياً</h5>
            <p class="small text-muted" style="margin-top: 0; margin-bottom: 12px;">اختر ملف طلب التفعيل المستلم من العميل (`.AQNEX`) لتعبئة الحقول بالأسفل تلقائياً</p>
            <label for="request_file" class="upload-label">اختر ملف الطلب</label>
            <input type="file" id="request_file" accept=".AQNEX" onchange="loadRequestFile(this)">
            <div id="upload_status" class="small font-weight-bold" style="color: var(--accent-teal); display:none;"></div>
        </div>

        <?php if (!empty($generatedCode)): ?>
            <div class="alert-success">
                <h5>✓ تم توليد الترخيص بنجاح!</h5>
                <p style="margin: 5px 0 10px 0;">تم حفظ الترخيص محلياً في مجلد الترخيص للبرنامج. انسخ كود التفعيل النصي أدناه وأرسله للعميل للتفعيل الفوري.</p>
                <div class="code-box">
                    <label>كود التفعيل الخاص بالعميل (Activation Code)</label>
                    <textarea class="code-textarea" id="activation_code_val" readonly onclick="this.select()"><?php echo htmlspecialchars($generatedCode); ?></textarea>
                    <button type="button" class="btn-copy" onclick="copyActivationCode()">نسخ كود التفعيل لحافظة الجهاز</button>
                </div>
            </div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-group">
                <label>معرف الجهاز الفريد للعميل (Machine ID) *</label>
                <input type="text" name="machine_id" id="machine_id" placeholder="الصق معرف الجهاز المستخرج من جهاز العميل" required>
            </div>
            
            <div class="grid-2">
                <div class="form-group">
                    <label>اسم المنشأة / الشركة *</label>
                    <input type="text" name="company" id="company" placeholder="مثال: شركة النجم للتجارة" required>
                </div>
                <div class="form-group">
                    <label>اسم المالك *</label>
                    <input type="text" name="owner" id="owner" placeholder="مثال: أمين قحطان" required>
                </div>
            </div>

            <div class="grid-2">
                <div class="form-group">
                    <label>رقم الجوال *</label>
                    <input type="text" name="phone" id="phone" placeholder="777777777" required>
                </div>
                <div class="form-group">
                    <label>المدينة</label>
                    <input type="text" name="city" id="city" placeholder="مثال: عدن">
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
                    <input type="date" name="expiry" id="expiry_date" value="2030-12-31">
                </div>
            </div>

            <div class="form-group">
                <label>الوحدات (Modules) المشمولة بالتفعيل</label>
                <div class="modules-container">
                    <label><input type="checkbox" name="modules[]" value="sales" checked> المبيعات ونقاط البيع</label>
                    <label><input type="checkbox" name="modules[]" value="inventory" checked> المخازن والجرد</label>
                    <label><input type="checkbox" name="modules[]" value="purchases" checked> المشتريات والتوريد</label>
                    <label><input type="checkbox" name="modules[]" value="accounting" checked> القيود والحسابات اليومية</label>
                    <label><input type="checkbox" name="modules[]" value="crm" checked> إدارة العملاء والموردين</label>
                    <label><input type="checkbox" name="modules[]" value="hr" checked> الموظفين والصلاحيات</label>
                    <label><input type="checkbox" name="modules[]" value="reports" checked> التقارير المالية والأرباح</label>
                </div>
            </div>

            <button type="submit" name="btn_issue" class="btn-teal" style="width:100%; justify-content:center; padding:12px;">
                إصدار وتوليد كود التفعيل
            </button>
        </form>
    </div>

    <script>
        // ضبط تاريخ الانتهاء تلقائياً عند تغيير نوع الباقة
        function adjustExpiry(type) {
            const expiryInput = document.getElementById('expiry_date');
            const today = new Date();
            if (type === 'trial') {
                today.setDate(today.getDate() + 15); // 15 يوم تجريبي
            } else if (type === 'monthly') {
                today.setMonth(today.getMonth() + 1); // شهر واحد
            } else if (type === 'yearly') {
                today.setFullYear(today.getFullYear() + 1); // سنة واحدة
            } else if (type === 'lifetime') {
                expiryInput.value = '2035-12-31'; // قيمة طويلة الأجل
                return;
            }
            const yyyy = today.getFullYear();
            let mm = today.getMonth() + 1;
            let dd = today.getDate();
            if (mm < 10) mm = '0' + mm;
            if (dd < 10) dd = '0' + dd;
            expiryInput.value = yyyy + '-' + mm + '-' + dd;
        }

        // قراءة وفك تشفير ملف طلب العميل
        function loadRequestFile(fileInput) {
            const file = fileInput.files[0];
            const statusDiv = document.getElementById('upload_status');
            if (!file) return;

            statusDiv.style.display = 'block';
            statusDiv.style.color = '#0f766e';
            statusDiv.innerText = 'جاري استيراد قراءة الملف...';

            const reader = new FileReader();
            reader.onload = function(e) {
                try {
                    const rawContent = e.target.result.trim();
                    // فك تشفير Base64 وحماية النصوص العربية
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
                        statusDiv.innerText = '✓ تم استيراد وتعبئة بيانات العميل بنجاح!';
                    } else {
                        statusDiv.style.color = '#be123c';
                        statusDiv.innerText = 'خطأ: ملف الطلب تالف أو غير صالح.';
                    }
                } catch (err) {
                    statusDiv.style.color = '#be123c';
                    statusDiv.innerText = 'خطأ أثناء فك تشفير الملف: ' + err.message;
                }
            };
            reader.onerror = function() {
                statusDiv.style.color = '#be123c';
                statusDiv.innerText = 'خطأ في قراءة ملف طلب العميل.';
            };
            reader.readAsText(file);
        }

        // نسخ كود التفعيل لحافظة النظام
        function copyActivationCode() {
            const codeArea = document.getElementById('activation_code_val');
            codeArea.select();
            navigator.clipboard.writeText(codeArea.value).then(function() {
                alert('✓ تم نسخ كود التفعيل لحافظة الجهاز بنجاح. أرسله الآن للعميل لتنشيط النظام.');
            }, function(err) {
                alert('فشل نسخ كود التفعيل: ' + err);
            });
        }
    </script>
    </body>
    </html>
    <?php
}
?>
