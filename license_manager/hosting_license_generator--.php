<?php
/**
 * AQNEX License & Deployment Center - License Manager & Activation Request Center
 * هذا الملف مخصص للاستضافة على سيرفر الشركة لإدارة وإصدار تراخيص العملاء.
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
session_start();

// التحقق من صلاحيات المسؤول على الاستضافة
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login");
    exit();
}

require_once '../config/db.php';
require_once 'license_crypto_helper.php'; // تضمين الكلاس المساعد المحدث ديناميكياً
require_once '../includes/audit_helper.php';

// Generate CSRF token if not set
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Dynamic License File Download Handler
if (isset($_GET['download_license_id'])) {
    $license_id = intval($_GET['download_license_id']);
    // Fetch license details
    $stmt = $pdo->prepare("
        SELECT l.*, c.customer_name, c.owner_name, c.mobile, p.code as product_code, lt.name as type_name
        FROM licenses l
        JOIN customers c ON l.customer_id = c.id
        JOIN products p ON l.product_id = p.id
        JOIN license_types lt ON l.license_type_id = lt.id
        WHERE l.id = ?
    ");
    $stmt->execute([$license_id]);
    $l_row = $stmt->fetch();

    if ($l_row) {
        $mod_stmt = $pdo->prepare("
            SELECT m.module_code 
            FROM license_modules lm 
            JOIN modules m ON lm.module_id = m.id 
            WHERE lm.license_id = ?
        ");
        $mod_stmt->execute([$license_id]);
        $modules = $mod_stmt->fetchAll(PDO::FETCH_COLUMN);

        $license_payload = [
            'machine_id' => $l_row['machine_id'],
            'company_name' => $l_row['customer_name'] ?? '',
            'owner_name' => $l_row['owner_name'] ?? '',
            'phone' => $l_row['mobile'] ?? '',
            'license_type' => strtolower($l_row['type_name']),
            'start_date' => $l_row['start_date'],
            'expiry_date' => $l_row['expire_date'] ?: '',
            'modules_enabled' => implode(',', $modules),
            'max_users' => intval($l_row['max_users']),
            'max_branches' => intval($l_row['max_branches']),
            'issued_at' => date('Y-m-d H:i:s', strtotime($l_row['created_at']))
        ];

        try {
            $signed_file_content = AQNEXLicenseCrypto::generateLicenseFile($license_payload);
            $filename = "license_" . strtolower($l_row['product_code']) . "_" . $l_row['customer_id'] . ".AQNEX";

            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            echo $signed_file_content;
            exit();
        } catch (Exception $e) {
            $error = 'فشل تحميل ملف الرخصة: ' . $e->getMessage();
        }
    }
}

$error = '';
$success = '';
$parsed_request = null;
$generated_activation_code = '';
$generated_license_id = 0;

// Handle Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF Check
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = 'فشل التحقق من رمز الحماية (CSRF).';
    } else {
        
        // --- UPLOAD & PARSE ACTIVATION REQUEST ---
        if (isset($_POST['upload_request'])) {
            if (isset($_FILES['activation_file']) && $_FILES['activation_file']['error'] === UPLOAD_ERR_OK) {
                $file_tmp = $_FILES['activation_file']['tmp_name'];
                $content = file_get_contents($file_tmp);
                
                $data = AQNEXLicenseCrypto::parseActivationRequest($content);
                if ($data && isset($data['machine_id'])) {
                    // Extract fields with fallbacks
                    $machine_id = $data['machine_id'];
                    $customer_name = $data['company_name'] ?? ($data['customer_name'] ?? 'عميل غير محدد');
                    $owner_name = $data['owner_name'] ?? null;
                    $mobile = $data['phone'] ?? ($data['mobile'] ?? null);
                    $email = $data['email'] ?? null;
                    $city = $data['city'] ?? null;
                    $country = $data['country'] ?? 'Yemen';
                    $product_code = $data['product_code'] ?? ($data['product'] ?? '');

                    // Find product
                    $product = null;
                    if (!empty($product_code)) {
                        $prod_stmt = $pdo->prepare("SELECT id, name FROM products WHERE code = ? AND status = 'active'");
                        $prod_stmt->execute([$product_code]);
                        $product = $prod_stmt->fetch();
                    }
                    
                    // Fallback to first active product if not found
                    if (!$product) {
                        $product = $pdo->query("SELECT id, name FROM products WHERE status = 'active' ORDER BY id ASC LIMIT 1")->fetch();
                    }
                    
                    if (!$product) {
                        $error = 'الرجاء إضافة منتج نشط واحد على الأقل في قاعدة البيانات أولاً قبل تفعيل الأجهزة.';
                    } else {
                        // Find or Create Customer
                        $cust_id = null;
                        if (!empty($email)) {
                            $cust_stmt = $pdo->prepare("SELECT id FROM customers WHERE email = ?");
                            $cust_stmt->execute([$email]);
                            $cust_id = $cust_stmt->fetchColumn();
                        }
                        
                        if (!$cust_id && !empty($mobile)) {
                            $cust_stmt = $pdo->prepare("SELECT id FROM customers WHERE mobile = ?");
                            $cust_stmt->execute([$mobile]);
                            $cust_id = $cust_stmt->fetchColumn();
                        }

                        if (!$cust_id) {
                            $cust_stmt = $pdo->prepare("SELECT id FROM customers WHERE customer_name = ?");
                            $cust_stmt->execute([$customer_name]);
                            $cust_id = $cust_stmt->fetchColumn();
                        }
                        
                        if (!$cust_id) {
                            // Create customer
                            $ins_stmt = $pdo->prepare("INSERT INTO customers (customer_name, owner_name, mobile, email, city, country, notes) VALUES (?, ?, ?, ?, ?, ?, ?)");
                            $ins_stmt->execute([
                                $customer_name,
                                $owner_name,
                                $mobile,
                                $email ?: null,
                                $city,
                                $country,
                                'تم إنشاؤه تلقائياً عبر طلب تفعيل.'
                            ]);
                            $cust_id = $pdo->lastInsertId();
                            logLicenseAction($pdo, 'Auto Create Customer', "Customer: $customer_name (ID: $cust_id)");
                        }
                        
                        // Find or Create Installation
                        $machine_name = $data['machine_name'] ?? 'جهاز العميل';
                        
                        $inst_stmt = $pdo->prepare("SELECT id FROM customer_installations WHERE customer_id = ? AND product_id = ? AND machine_id = ?");
                        $inst_stmt->execute([$cust_id, $product['id'], $machine_id]);
                        $inst_id = $inst_stmt->fetchColumn();
                        
                        if (!$inst_id) {
                            $ins_stmt = $pdo->prepare("INSERT INTO customer_installations (customer_id, product_id, machine_id, machine_name, status) VALUES (?, ?, ?, ?, 'Active')");
                            $ins_stmt->execute([$cust_id, $product['id'], $machine_id, $machine_name]);
                            $inst_id = $pdo->lastInsertId();
                            logLicenseAction($pdo, 'Auto Create Installation', "Installation: $machine_name for Customer ID: $cust_id");
                        }
                        
                        // Set parsed request for view
                        $parsed_request = [
                            'customer_id' => $cust_id,
                            'customer_name' => $customer_name,
                            'product_id' => $product['id'],
                            'product_name' => $product['name'],
                            'product_code' => $product_code ?: 'NONE',
                            'machine_id' => $machine_id,
                            'machine_name' => $machine_name
                        ];
                        
                        $success = 'تم قراءة وتأكيد ملف طلب التفعيل بنجاح! يرجى مراجعة وتحديد إعدادات التفعيل أدناه للتوليد.';
                    }
                } else {
                    $error = 'فشل قراءة الملف أو تنسيق ملف التفعيل غير صالح. تأكد من صحة الملف.';
                }
            } else {
                $error = 'خطأ أثناء رفع ملف طلب التفعيل.';
            }
        }
        
        // --- GENERATE LICENSE ---
        elseif (isset($_POST['generate_license'])) {
            $customer_id = intval($_POST['customer_id']);
            $product_id = intval($_POST['product_id']);
            $machine_id = trim($_POST['machine_id']);
            $license_type_id = intval($_POST['license_type_id']);
            $max_users = intval($_POST['max_users']);
            $max_branches = intval($_POST['max_branches']);
            $selected_modules = $_POST['modules'] ?? []; // Array of module IDs
            
            // Get customer details & product code
            $cust_stmt = $pdo->prepare("SELECT customer_name, owner_name, mobile FROM customers WHERE id = ?");
            $cust_stmt->execute([$customer_id]);
            $cust_row = $cust_stmt->fetch();
            $cust_name = $cust_row['customer_name'] ?? 'عميل غير محدد';
            
            $prod_row = $pdo->query("SELECT code, name FROM products WHERE id = $product_id")->fetch();
            $product_code = $prod_row['code'];
            $product_name = $prod_row['name'];
            
            // Get Duration of license
            $type_row = $pdo->query("SELECT name, duration_days FROM license_types WHERE id = $license_type_id")->fetch();
            $start_date = date('Y-m-d');
            $expire_date = null;
            
            if ($type_row['duration_days'] !== null) {
                $expire_date = date('Y-m-d', strtotime("+" . $type_row['duration_days'] . " days"));
            }
            
            try {
                $pdo->beginTransaction();
                
                // Add License to Database
                $stmt = $pdo->prepare("INSERT INTO licenses (customer_id, product_id, machine_id, license_type_id, start_date, expire_date, max_users, max_branches, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Active')");
                $stmt->execute([$customer_id, $product_id, $machine_id, $license_type_id, $start_date, $expire_date, $max_users, $max_branches]);
                $license_id = $pdo->lastInsertId();
                
                // Link Modules
                $module_codes = [];
                if (!empty($selected_modules)) {
                    $ins_mod = $pdo->prepare("INSERT INTO license_modules (license_id, module_id) VALUES (?, ?)");
                    foreach ($selected_modules as $mod_id) {
                        $ins_mod->execute([$license_id, $mod_id]);
                        
                        // Get module code
                        $m_code = $pdo->query("SELECT module_code FROM modules WHERE id = $mod_id")->fetchColumn();
                        $module_codes[] = $m_code;
                    }
                }
                
                // Prepare cryptographic payload matching offline verification requirements
                $license_payload = [
                    'machine_id' => $machine_id,
                    'company_name' => $cust_row['customer_name'] ?? '',
                    'owner_name' => $cust_row['owner_name'] ?? '',
                    'phone' => $cust_row['mobile'] ?? '',
                    'license_type' => strtolower($type_row['name']),
                    'start_date' => $start_date,
                    'expiry_date' => $expire_date ?: '',
                    'modules_enabled' => implode(',', $module_codes),
                    'max_users' => $max_users,
                    'max_branches' => $max_branches,
                    'issued_at' => date('Y-m-d H:i:s')
                ];
                
                // Generate and sign file content
                $signed_file_content = AQNEXLicenseCrypto::generateLicenseFile($license_payload);
                
                $pdo->commit();
                
                logLicenseAction($pdo, 'Generate License', "License ID: $license_id for Customer: $cust_name");
                
                // Storing variables to display in the success panel (instead of forcing immediate exit/download)
                $generated_activation_code = base64_encode($signed_file_content);
                $generated_license_id = $license_id;
                
                $success = 'تم توليد وتوقيع الترخيص الرقمي للعميل بنجاح! يمكنك نسخ الكود أو تحميل الملف لإرساله.';
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = 'خطأ أثناء إصدار الترخيص: ' . $e->getMessage();
            }
        }
        
        // --- RENEW LICENSE ---
        elseif (isset($_POST['renew_license'])) {
            $license_id = intval($_POST['license_id']);
            $license_type_id = intval($_POST['license_type_id']);
            $max_users = intval($_POST['max_users']);
            $max_branches = intval($_POST['max_branches']);
            $selected_modules = $_POST['modules'] ?? [];
            
            // Get original details with customer owner details
            $lic_row = $pdo->query("SELECT l.*, c.customer_name, c.owner_name, c.mobile, p.code as product_code FROM licenses l JOIN customers c ON l.customer_id = c.id JOIN products p ON l.product_id = p.id WHERE l.id = $license_id")->fetch();
            if ($lic_row) {
                $type_row = $pdo->query("SELECT name, duration_days FROM license_types WHERE id = $license_type_id")->fetch();
                $start_date = date('Y-m-d');
                $expire_date = null;
                if ($type_row['duration_days'] !== null) {
                    $expire_date = date('Y-m-d', strtotime("+" . $type_row['duration_days'] . " days"));
                }
                
                try {
                    $pdo->beginTransaction();
                    
                    // Update Database
                    $upd = $pdo->prepare("UPDATE licenses SET license_type_id = ?, start_date = ?, expire_date = ?, max_users = ?, max_branches = ?, status = 'Active' WHERE id = ?");
                    $upd->execute([$license_type_id, $start_date, $expire_date, $max_users, $max_branches, $license_id]);
                    
                    // Delete old modules and insert new ones
                    $pdo->exec("DELETE FROM license_modules WHERE license_id = $license_id");
                    
                    $module_codes = [];
                    if (!empty($selected_modules)) {
                        $ins_mod = $pdo->prepare("INSERT INTO license_modules (license_id, module_id) VALUES (?, ?)");
                        foreach ($selected_modules as $mod_id) {
                            $ins_mod->execute([$license_id, $mod_id]);
                            $m_code = $pdo->query("SELECT module_code FROM modules WHERE id = $mod_id")->fetchColumn();
                            $module_codes[] = $m_code;
                        }
                    }
                    
                    // Prepare cryptographic payload matching offline verification requirements
                    $license_payload = [
                        'machine_id' => $lic_row['machine_id'],
                        'company_name' => $lic_row['customer_name'] ?? '',
                        'owner_name' => $lic_row['owner_name'] ?? '',
                        'phone' => $lic_row['mobile'] ?? '',
                        'license_type' => strtolower($type_row['name']),
                        'start_date' => $start_date,
                        'expiry_date' => $expire_date ?: '',
                        'modules_enabled' => implode(',', $module_codes),
                        'max_users' => $max_users,
                        'max_branches' => $max_branches,
                        'issued_at' => date('Y-m-d H:i:s')
                    ];
                    
                    // Generate and sign file content
                    $signed_file_content = AQNEXLicenseCrypto::generateLicenseFile($license_payload);
                    
                    $pdo->commit();
                    logLicenseAction($pdo, 'Renew License', "License ID: $license_id renewed");
                    
                    // Store values to render the success banner and copy block
                    $generated_activation_code = base64_encode($signed_file_content);
                    $generated_license_id = $license_id;
                    
                    $success = 'تم تجديد الترخيص وتوليد كود التفعيل الرقمي الجديد بنجاح!';
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $error = 'خطأ أثناء التجديد: ' . $e->getMessage();
                }
            } else {
                $error = 'الترخيص المطلوب غير موجود.';
            }
        }
        
        // --- REVOKE / SUSPEND LICENSE ---
        elseif (isset($_POST['change_status'])) {
            $license_id = intval($_POST['license_id']);
            $status = $_POST['status'];
            
            try {
                $stmt = $pdo->prepare("UPDATE licenses SET status = ? WHERE id = ?");
                $stmt->execute([$status, $license_id]);
                logLicenseAction($pdo, 'Change License Status', "License ID: $license_id changed status to $status");
                $success = "تم تغيير حالة الترخيص بنجاح إلى $status.";
            } catch (PDOException $e) {
                $error = 'خطأ أثناء تغيير الحالة: ' . $e->getMessage();
            }
        }

        // --- DELETE LICENSE ---
        elseif (isset($_POST['delete_license'])) {
            $license_id = intval($_POST['license_id']);
            try {
                $stmt = $pdo->prepare("DELETE FROM licenses WHERE id = ?");
                $stmt->execute([$license_id]);
                logLicenseAction($pdo, 'Delete License', "License ID: $license_id deleted");
                $success = "تم حذف الترخيص نهائياً بنجاح.";
            } catch (PDOException $e) {
                $error = 'خطأ أثناء حذف الترخيص: ' . $e->getMessage();
            }
        }
    }
}

// Fetch license types
$license_types = $pdo->query("SELECT * FROM license_types ORDER BY id ASC")->fetchAll();

// Fetch issued licenses
$licenses = $pdo->query("
    SELECT l.*, c.customer_name, p.name as product_name, p.code as product_code, lt.name as type_name
    FROM licenses l
    JOIN customers c ON l.customer_id = c.id
    JOIN products p ON l.product_id = p.id
    JOIN license_types lt ON l.license_type_id = lt.id
    ORDER BY l.created_at DESC
")->fetchAll();

include '../includes/header.php';
?>

<div class="page-title-bar d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
    <div>
        <h1><i class="fas fa-key me-2" style="color:var(--gold);"></i>طلبات التفعيل وإصدار التراخيص</h1>
        <span class="text-muted small">رفع ملفات تفعيل الأجهزة وإصدار/تجديد وتوقيع ملفات تراخيص الأنظمة للعملاء</span>
    </div>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger border-0 shadow-sm py-3 mb-4 d-flex align-items-center gap-2">
        <i class="fas fa-exclamation-triangle"></i>
        <div><?php echo htmlspecialchars($error); ?></div>
    </div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert alert-success border-0 shadow-sm py-3 mb-4 d-flex align-items-center gap-2">
        <i class="fas fa-check-circle"></i>
        <div><?php echo htmlspecialchars($success); ?></div>
    </div>
<?php endif; ?>

<?php if (!empty($generated_activation_code)): ?>
    <div class="card border-0 shadow-sm mb-4" style="border-right: 5px solid #198754 !important;">
        <div class="card-header py-3" style="border-bottom: 2px solid #198754 !important;">
            <h5 class="mb-0 fw-bold text-navy"><i class="fas fa-check-circle me-2 text-success"></i>تم توليد مفتاح تفعيل النظام وتوقيعه رقمياً بنجاح!</h5>
        </div>
        <div class="card-body p-4">
            <p class="text-muted small mb-3">انسخ كود التفعيل الرقمي (Activation Code) الموضح أدناه بالكامل وارسله للعميل لتنشيط نظامه، أو قم بتحميل ملف الترخيص الرقمي.</p>
            
            <div class="mb-3">
                <label class="form-label fw-bold text-navy mb-1">كود التفعيل الرقمي (Activation Code):</label>
                <textarea class="form-control bg-light font-monospace text-center fw-bold text-navy" id="activationCodeBox" readonly rows="5" style="font-size: 0.8rem; letter-spacing: 0.5px; word-break: break-all;"><?php echo htmlspecialchars($generated_activation_code); ?></textarea>
            </div>
            
            <div class="d-flex gap-2 flex-wrap">
                <button type="button" class="btn btn-primary text-white fw-bold px-4" onclick="copyGeneratedActivationCode()">
                    <i class="fas fa-copy me-2"></i>نسخ كود التفعيل لحافظة الجهاز
                </button>
                <a href="?download_license_id=<?php echo $generated_license_id; ?>" class="btn btn-gold text-navy fw-bold px-4">
                    <i class="fas fa-download me-2"></i>تحميل ملف الترخيص (.AQNEX)
                </a>
            </div>
        </div>
    </div>
    
    <script>
    function copyGeneratedActivationCode() {
        var copyText = document.getElementById("activationCodeBox");
        copyText.select();
        copyText.setSelectionRange(0, 99999);
        navigator.clipboard.writeText(copyText.value).then(function() {
            Swal.fire({
                icon: 'success',
                title: 'تم النسخ!',
                text: 'تم نسخ كود التفعيل إلى الحافظة بنجاح.',
                timer: 2000,
                showConfirmButton: false
            });
        }, function(err) {
            alert('فشل في نسخ الكود: ' + err);
        });
    }
    </script>
<?php endif; ?>

<!-- Tabs -->
<ul class="nav nav-tabs mb-4" id="licenseTabs" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="nav-link active fw-bold text-navy" id="upload-tab" data-bs-toggle="tab" data-bs-target="#upload" type="button" role="tab" aria-controls="upload" aria-selected="true">
            <i class="fas fa-file-upload me-1 text-primary"></i>مركز رفع ملفات التفعيل (.AQNEX)
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link fw-bold text-navy" id="list-tab" data-bs-toggle="tab" data-bs-target="#list" type="button" role="tab" aria-controls="list" aria-selected="false">
            <i class="fas fa-list me-1 text-success"></i>سجل التراخيص الصادرة
        </button>
    </li>
</ul>

<div class="tab-content" id="licenseTabsContent">
    <!-- TAB 1: UPLOAD AND PARSE -->
    <div class="tab-pane fade show active" id="upload" role="tabpanel" aria-labelledby="upload-tab">
        <div class="row">
            <div class="col-lg-5">
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header">
                        <h5 class="mb-0 text-navy fw-bold"><i class="fas fa-file-import me-2" style="color: var(--gold);"></i>الخطوة 1: رفع ملف طلب التفعيل</h5>
                    </div>
                    <div class="card-body p-4">
                        <form method="POST" action="" enctype="multipart/form-data">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            <input type="hidden" name="upload_request" value="1">
                            <div class="mb-3">
                                <label class="form-label fw-bold">ملف طلب التفعيل (activation_request.AQNEX)</label>
                                <input type="file" name="activation_file" class="form-control text-navy" accept=".AQNEX" required>
                                <small class="text-muted d-block mt-2" style="font-size:0.75rem;">يتم استخراج هذا الملف من واجهة تفعيل العميل بعد تنصيب النظام.</small>
                            </div>
                            <button type="submit" class="btn btn-primary fw-bold text-white w-100 py-2">
                                <i class="fas fa-magic me-1"></i>قراءة وتأكيد ملف الطلب
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-lg-7">
                <?php if ($parsed_request): ?>
                    <div class="card border-0 shadow-sm mb-4">
                        <div class="card-header">
                            <h5 class="mb-0 text-navy fw-bold"><i class="fas fa-check-double me-2 text-success"></i>الخطوة 2: تحديد الإعدادات وإنشاء الترخيص</h5>
                        </div>
                        <div class="card-body p-4">
                            <div class="p-3 mb-4" style="background-color: var(--bg-page); border: 1px solid var(--border); border-radius: 3px;">
                                <h6 class="fw-bold text-navy mb-3"><i class="fas fa-info-circle me-1" style="color: var(--gold);"></i>بيانات الجهاز والعميل المستخرجة:</h6>
                                <table class="table table-sm table-borderless mb-0 fs-7">
                                    <tr>
                                        <td class="fw-bold text-navy p-1" style="width: 120px;">اسم المنشأة:</td>
                                        <td class="p-1"><?php echo htmlspecialchars($parsed_request['customer_name']); ?></td>
                                    </tr>
                                    <tr>
                                        <td class="fw-bold text-navy p-1">البرنامج المقترح:</td>
                                        <td class="p-1"><?php echo htmlspecialchars($parsed_request['product_name']); ?> (<?php echo htmlspecialchars($parsed_request['product_code']); ?>)</td>
                                    </tr>
                                    <tr>
                                        <td class="fw-bold text-navy p-1">اسم جهاز العميل:</td>
                                        <td class="p-1"><?php echo htmlspecialchars($parsed_request['machine_name']); ?></td>
                                    </tr>
                                    <tr>
                                        <td class="fw-bold text-navy p-1">كود الجهاز:</td>
                                        <td class="p-1 text-danger font-monospace"><?php echo htmlspecialchars($parsed_request['machine_id']); ?></td>
                                    </tr>
                                </table>
                            </div>

                            <form method="POST" action="">
                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                <input type="hidden" name="generate_license" value="1">
                                <input type="hidden" name="customer_id" value="<?php echo $parsed_request['customer_id']; ?>">
                                <input type="hidden" name="machine_id" value="<?php echo htmlspecialchars($parsed_request['machine_id']); ?>">

                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold">المنتج (النظام)</label>
                                        <select name="product_id" id="generate_product_id" class="form-select text-navy" required>
                                            <?php
                                            $products = $pdo->query("SELECT id, name, code FROM products WHERE status = 'active' ORDER BY name ASC")->fetchAll();
                                            foreach ($products as $p) {
                                                $selected = ($p['id'] == $parsed_request['product_id']) ? 'selected' : '';
                                                echo '<option value="' . $p['id'] . '" ' . $selected . '>' . htmlspecialchars($p['name']) . ' (' . htmlspecialchars($p['code']) . ')</option>';
                                            }
                                            ?>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold">باقة الترخيص</label>
                                        <select name="license_type_id" class="form-select text-navy" required>
                                            <?php foreach ($license_types as $lt): ?>
                                                <option value="<?php echo $lt['id']; ?>"><?php echo htmlspecialchars($lt['name']); ?> (<?php echo $lt['duration_days'] ? $lt['duration_days'] . ' يوم' : 'مدى الحياة'; ?>)</option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-6 col-md-3">
                                        <label class="form-label fw-bold">الحد الأقصى للمستخدمين</label>
                                        <input type="number" name="max_users" class="form-control text-navy" value="5" min="1" required>
                                    </div>
                                    <div class="col-6 col-md-3">
                                        <label class="form-label fw-bold">الحد الأقصى للفروع</label>
                                        <input type="number" name="max_branches" class="form-control text-navy" value="1" min="1" required>
                                    </div>

                                    <div class="col-12">
                                        <label class="form-label fw-bold d-block">الموديلات والوحدات المفعّلة</label>
                                        <div class="border rounded p-3 bg-white" id="generate_modules_container" style="max-height: 180px; overflow-y: auto;">
                                            <?php
                                            $p_id = $parsed_request['product_id'];
                                            $mods = $pdo->query("SELECT * FROM modules WHERE product_id = $p_id ORDER BY module_name ASC")->fetchAll();
                                            if (count($mods) > 0) {
                                                foreach ($mods as $m) {
                                                    echo '<div class="form-check form-check-inline me-3 mb-2">
                                                            <input class="form-check-input" type="checkbox" name="modules[]" value="'.$m['id'].'" id="mod_'.$m['id'].'" checked>
                                                            <label class="form-check-label fw-bold small text-navy" for="mod_'.$m['id'].'">'.$m['module_name'].' ('.$m['module_code'].')</label>
                                                          </div>';
                                                }
                                            } else {
                                                echo '<span class="text-muted small">لا توجد موديلات معرفة لهذا المنتج. قم بتعريفها في صفحة المنتجات.</span>';
                                            }
                                            ?>
                                        </div>
                                    </div>
                                </div>

                                <button type="submit" class="btn btn-gold fw-bold text-navy w-100 py-2 mt-4">
                                    <i class="fas fa-key me-1"></i>توليد مفتاح تفعيل النظام وتوقيعه رقمياً
                                </button>
                            </form>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="card border-0 shadow-sm mb-4 h-100">
                        <div class="card-body p-5 text-center text-muted d-flex flex-column align-items-center justify-content-center">
                            <i class="fas fa-file-medical fa-4x mb-3" style="opacity:0.25; color: var(--gold);"></i>
                            <h5 class="fw-bold text-navy">بانتظار ملف طلب التفعيل</h5>
                            <p class="small text-muted mb-0">قم برفع ملف <code>.AQNEX</code> المستخرج من جهاز العميل لقراءته والبدء بإصدار التفعيل فوراً.</p>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- TAB 2: ISSUED LICENSES -->
    <div class="tab-pane fade" id="list" role="tabpanel" aria-labelledby="list-tab">
        <div class="card border-0 shadow-sm">
            <div class="card-body p-0">
                <?php if (count($licenses) > 0): ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>العميل</th>
                                    <th>المنتج</th>
                                    <th>نوع الترخيص</th>
                                    <th>تاريخ البدء</th>
                                    <th>تاريخ الانتهاء</th>
                                    <th class="text-center">المستخدمين / الفروع</th>
                                    <th>الحالة</th>
                                    <th class="text-center" style="min-width: 220px;">إجراءات</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($licenses as $l): ?>
                                    <tr>
                                        <td class="align-middle fw-bold text-navy"><?php echo htmlspecialchars($l['customer_name']); ?></td>
                                        <td class="align-middle text-navy">
                                            <span class="fw-bold"><?php echo htmlspecialchars($l['product_name']); ?></span><br>
                                            <small class="text-muted font-monospace"><?php echo htmlspecialchars($l['machine_id']); ?></small>
                                        </td>
                                        <td class="align-middle"><span class="badge bg-secondary"><?php echo htmlspecialchars($l['type_name']); ?></span></td>
                                        <td class="align-middle small"><?php echo date('Y-m-d', strtotime($l['start_date'])); ?></td>
                                        <td class="align-middle small"><?php echo $l['expire_date'] ? date('Y-m-d', strtotime($l['expire_date'])) : 'مدى الحياة'; ?></td>
                                        <td class="align-middle text-center small"><?php echo $l['max_users'] . ' مستخدمين / ' . $l['max_branches'] . ' فروع'; ?></td>
                                        <td class="align-middle">
                                            <form method="POST" action="" class="mb-0">
                                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                                <input type="hidden" name="license_id" value="<?php echo $l['id']; ?>">
                                                <select name="status" class="form-select form-select-sm p-1 fs-7 border-0 <?php echo $l['status'] === 'Active' ? 'text-success bg-light' : ($l['status'] === 'Suspended' ? 'text-warning bg-light' : 'text-danger bg-light'); ?>" onchange="this.form.submit()" style="font-weight:bold;">
                                                    <option value="Active" <?php echo $l['status'] === 'Active' ? 'selected' : ''; ?>>Active (نشط)</option>
                                                    <option value="Suspended" <?php echo $l['status'] === 'Suspended' ? 'selected' : ''; ?>>Suspended (معلق)</option>
                                                    <option value="Expired" <?php echo $l['status'] === 'Expired' ? 'selected' : ''; ?>>Expired (منتهي)</option>
                                                    <option value="Revoked" <?php echo $l['status'] === 'Revoked' ? 'selected' : ''; ?>>Revoked (ملغى)</option>
                                                </select>
                                                <input type="hidden" name="change_status" value="1">
                                            </form>
                                        </td>
                                        <td class="align-middle text-center">
                                            <div class="btn-group btn-group-sm">
                                                <button class="btn btn-outline-primary" 
                                                        data-bs-toggle="modal" 
                                                        data-bs-target="#renewModal"
                                                        data-id="<?php echo $l['id']; ?>"
                                                        data-customer="<?php echo htmlspecialchars($l['customer_name']); ?>"
                                                        data-product="<?php echo htmlspecialchars($l['product_name']); ?>"
                                                        data-prodid="<?php echo $l['product_id']; ?>"
                                                        data-machine="<?php echo htmlspecialchars($l['machine_id']); ?>"
                                                        data-users="<?php echo $l['max_users']; ?>"
                                                        data-branches="<?php echo $l['max_branches']; ?>"
                                                        data-type="<?php echo $l['license_type_id']; ?>"
                                                        title="تجديد الترخيص">
                                                    <i class="fas fa-sync-alt"></i>
                                                </button>
                                                <button class="btn btn-outline-success" 
                                                        type="button"
                                                        onclick="ajaxCopyActivationCode(<?php echo $l['id']; ?>, this)"
                                                        title="نسخ كود التفعيل">
                                                    <i class="fas fa-copy"></i>
                                                </button>
                                                <a href="?download_license_id=<?php echo $l['id']; ?>" 
                                                   class="btn btn-outline-navy" 
                                                   title="تحميل ملف الترخيص (.AQNEX)">
                                                    <i class="fas fa-download"></i>
                                                </a>
                                                <form method="POST" action="" class="d-inline mb-0" onsubmit="return confirm('هل أنت متأكد من حذف هذا الترخيص نهائياً من النظام؟');">
                                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                                    <input type="hidden" name="license_id" value="<?php echo $l['id']; ?>">
                                                    <input type="hidden" name="delete_license" value="1">
                                                    <button type="submit" class="btn btn-outline-danger" title="حذف نهائياً">
                                                        <i class="fas fa-trash-alt"></i>
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="p-5 text-center text-muted">
                        <i class="fas fa-key fa-4x mb-3" style="opacity:0.2; color: var(--gold);"></i>
                        <h5 class="fw-bold text-navy">لا توجد تراخيص مصدرة حالياً</h5>
                        <p class="small mb-0">قم برفع طلب تفعيل من التبويب الماوري لإصدار تفعيل فوري.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ==========================================
     RENEW MODAL
     ========================================== -->
<div class="modal fade text-dark" id="renewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-history me-1" style="color: var(--gold);"></i>تجديد وإعادة إصدار ترخيص</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="renew_license" value="1">
                <input type="hidden" name="license_id" id="renew_license_id">
                
                <div class="modal-body p-4">
                    <div class="p-3 mb-3 small" style="background-color: var(--bg-page); border: 1px solid var(--border); border-radius: 3px;">
                        <div class="row">
                            <div class="col-sm-6 text-navy"><strong>العميل:</strong> <span id="renew_customer_name"></span></div>
                            <div class="col-sm-6 text-navy"><strong>المنتج:</strong> <span id="renew_product_name"></span></div>
                            <div class="col-12 mt-1 text-navy"><strong>معرّف الجهاز:</strong> <code id="renew_machine_id" class="text-danger font-monospace"></code></div>
                        </div>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold">الباقة الجديدة</label>
                            <select name="license_type_id" id="renew_type_id" class="form-select text-navy" required>
                                <?php foreach ($license_types as $lt): ?>
                                    <option value="<?php echo $lt['id']; ?>"><?php echo htmlspecialchars($lt['name']); ?> (<?php echo $lt['duration_days'] ? $lt['duration_days'] . ' يوم' : 'مدى الحياة'; ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label fw-bold">الحد الأقصى للمستخدمين</label>
                            <input type="number" name="max_users" id="renew_max_users" class="form-control text-navy" min="1" required>
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label fw-bold">الحد الأقصى للفروع</label>
                            <input type="number" name="max_branches" id="renew_max_branches" class="form-control text-navy" min="1" required>
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-bold">تحديد الموديلات والوحدات المفعّلة</label>
                            <div class="border rounded p-3 bg-white" id="renew_modules_container" style="max-height: 180px; overflow-y: auto;">
                                <!-- Loaded dynamically via JS -->
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-gold text-navy fw-bold">تجديد وإصدار الترخيص</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Load modules on product change for generation
    $('#generate_product_id').on('change', function() {
        var prod_id = $(this).val();
        loadGenerateModules(prod_id);
    });

    function loadGenerateModules(prod_id) {
        var $container = $('#generate_modules_container');
        $container.html('<span class="text-muted small">جاري تحميل الموديلات...</span>');
        $.getJSON('../api/get_product_modules.php', { product_id: prod_id }, function(data) {
            var html = '';
            if (data && data.length > 0) {
                data.forEach(function(m) {
                    html += '<div class="form-check form-check-inline me-3 mb-2">' +
                            '<input class="form-check-input" type="checkbox" name="modules[]" value="' + m.id + '" id="mod_' + m.id + '" checked>' +
                            '<label class="form-check-label fw-bold small text-navy" for="mod_' + m.id + '">' + m.module_name + ' (' + m.module_code + ')</label>' +
                            '</div>';
                });
            } else {
                html = '<span class="text-muted small">لا توجد موديلات لهذا المنتج.</span>';
            }
            $container.html(html);
        }).fail(function() {
            $container.html('<span class="text-danger small">خطأ أثناء تحميل الوحدات.</span>');
        });
    }

    // Modal show event
    $('#renewModal').on('show.bs.modal', function(event) {
        var button = $(event.relatedTarget);
        var license_id = button.data('id');
        var customer = button.data('customer');
        var product = button.data('product');
        var prod_id = button.data('prodid');
        var machine = button.data('machine');
        var users = button.data('users');
        var branches = button.data('branches');
        var type = button.data('type');

        var modal = $(this);
        modal.find('#renew_license_id').val(license_id);
        modal.find('#renew_customer_name').text(customer);
        modal.find('#renew_product_name').text(product);
        modal.find('#renew_machine_id').text(machine);
        modal.find('#renew_max_users').val(users);
        modal.find('#renew_max_branches').val(branches);
        modal.find('#renew_type_id').val(type);

        // Load modules for renewal
        loadRenewModules(prod_id, license_id);
    });

    function loadRenewModules(prod_id, license_id) {
        var $container = $('#renew_modules_container');
        $container.html('<span class="text-muted small">جاري تحميل الموديلات...</span>');
        $.getJSON('../api/get_product_modules.php', { product_id: prod_id, license_id: license_id }, function(data) {
            var html = '';
            if (data && data.length > 0) {
                data.forEach(function(m) {
                    var checked = m.enabled ? 'checked' : '';
                    html += '<div class="form-check form-check-inline me-3 mb-2">' +
                            '<input class="form-check-input" type="checkbox" name="modules[]" value="' + m.id + '" id="ren_mod_' + m.id + '" ' + checked + '>' +
                            '<label class="form-check-label fw-bold small text-navy" for="ren_mod_' + m.id + '">' + m.module_name + ' (' + m.module_code + ')</label>' +
                            '</div>';
                });
            } else {
                html = '<span class="text-muted small">لا توجد موديلات لهذا المنتج.</span>';
            }
            $container.html(html);
        }).fail(function() {
            $container.html('<span class="text-danger small">خطأ أثناء تحميل الوحدات.</span>');
        });
    }
});

function ajaxCopyActivationCode(license_id, btn) {
    $(btn).html('<i class="fas fa-spinner fa-spin"></i>');
    $.ajax({
        url: '../api/get_activation_code.php',
        type: 'GET',
        data: { license_id: license_id },
        dataType: 'json',
        success: function(response) {
            $(btn).html('<i class="fas fa-copy"></i>');
            if (response.status === 'success') {
                navigator.clipboard.writeText(response.code).then(function() {
                    Swal.fire({
                        icon: 'success',
                        title: 'تم النسخ!',
                        text: 'تم نسخ كود التفعيل للحافظة بنجاح.',
                        timer: 1500,
                        showConfirmButton: false
                    });
                });
            } else {
                alert('خطأ: ' + response.message);
            }
        },
        error: function() {
            $(btn).html('<i class="fas fa-copy"></i>');
            alert('فشل الاتصال بالخادم لجلب كود التفعيل.');
        }
    });
}
</script>

<?php include '../includes/footer.php'; ?>
