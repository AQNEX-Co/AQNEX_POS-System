<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$dir_prefix = '../';
require_once(__DIR__ . '/../includes/connect.php');
require_once(__DIR__ . '/../app/Services/AuthService.php');
require_once(__DIR__ . '/../app/Services/ConfigService.php');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 1. Ensure user is logged in
if (empty($_SESSION['SESS_MEMBER_ID'])) {
    echo json_encode(['status' => 'error', 'message' => 'غير مصرح بالوصول، يرجى تسجيل الدخول.']);
    exit();
}

// 2. Resolve database connection (PDO)
$pdo = \AQNEX\Config\Database::createPdo();
if (!$pdo) {
    echo json_encode(['status' => 'error', 'message' => 'فشل الاتصال بقاعدة البيانات.']);
    exit();
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// Double security checks: Support mode actions need full support auth
$supportActions = ['reset_system', 'save_support_config'];
$isAdmin = \AQNEX\Services\AuthService::isAdmin();
$isSupport = \AQNEX\Services\AuthService::currentUserRole() === 'Support';

// Check if Support Mode is unlocked in session
$isSupportUnlocked = !empty($_SESSION['support_mode_unlocked']) || $isSupport;

if (in_array($action, $supportActions, true) && !$isSupportUnlocked) {
    echo json_encode(['status' => 'error', 'message' => 'هذه العملية تتطلب تفعيل وضع الدعم الفني أولاً.']);
    exit();
}

// Standard validation
if (!$isAdmin && !$isSupport && !$isSupportUnlocked) {
    echo json_encode(['status' => 'error', 'message' => 'غير مصرح لك بإجراء تعديلات على الإعدادات.']);
    exit();
}

switch ($action) {
    case 'unlock_support':
        $password = $_POST['password'] ?? '';
        try {
            $stmt = $pdo->query("SELECT support_token FROM settings WHERE id = 1 LIMIT 1");
            $savedToken = $stmt->fetchColumn();
            if ($password !== '' && $password === $savedToken) {
                $_SESSION['support_mode_unlocked'] = true;
                echo json_encode(['status' => 'success', 'message' => 'تم تفعيل وضع الدعم الفني بنجاح.']);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'رمز الدعم الفني غير صحيح!']);
            }
        } catch (\Exception $e) {
            echo json_encode(['status' => 'error', 'message' => 'حدث خطأ أثناء التحقق: ' . $e->getMessage()]);
        }
        break;

    case 'save_enterprise':
        $data = [
            'store_name' => trim($_POST['store_name'] ?? ''),
            'store_name_en' => trim($_POST['store_name_en'] ?? ''),
            'phone' => trim($_POST['phone'] ?? ''),
            'phone_en' => trim($_POST['phone_en'] ?? ''),
            'address' => trim($_POST['address'] ?? ''),
            'address_en' => trim($_POST['address_en'] ?? ''),
            'tax_number' => trim($_POST['tax_number'] ?? ''),
            'commercial_register' => trim($_POST['commercial_register'] ?? ''),
            'timezone' => trim($_POST['timezone'] ?? 'Asia/Aden'),
            'date_format' => trim($_POST['date_format'] ?? 'Y-m-d'),
            'decimal_precision' => (int)($_POST['decimal_precision'] ?? 4),
            'thousand_separator' => $_POST['thousand_separator'] ?? ','
        ];

        if (empty($data['store_name'])) {
            echo json_encode(['status' => 'error', 'message' => 'اسم المتجر حقل إجباري.']);
            exit();
        }

        // Handle logo file upload
        if (isset($_FILES['logo_file']) && $_FILES['logo_file']['error'] === UPLOAD_ERR_OK) {
            $file_tmp = $_FILES['logo_file']['tmp_name'];
            $file_name = $_FILES['logo_file']['name'];
            $ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
            $allowed_exts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'ico'];
            
            if (in_array($ext, $allowed_exts, true)) {
                $upload_dir = $dir_prefix . 'uploads/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                $new_logo_name = 'logo_' . time() . '.' . $ext;
                $dest_path = $upload_dir . $new_logo_name;
                if (move_uploaded_file($file_tmp, $dest_path)) {
                    // Delete old logo
                    try {
                        $oldLogo = $pdo->query("SELECT logo FROM settings WHERE id = 1")->fetchColumn();
                        if ($oldLogo && file_exists($dir_prefix . $oldLogo)) {
                            @unlink($dir_prefix . $oldLogo);
                        }
                    } catch (\Exception $ex) {}
                    
                    $data['logo'] = 'uploads/' . $new_logo_name;
                }
            } else {
                echo json_encode(['status' => 'error', 'message' => 'امتداد شعار غير مسموح به.']);
                exit();
            }
        }

        $res = \AQNEX\Services\ConfigService::updateSettings($data);
        if ($res) {
            echo json_encode(['status' => 'success', 'message' => 'تم حفظ بيانات المؤسسة بنجاح.']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'فشل حفظ الإعدادات في قاعدة البيانات.']);
        }
        break;

    case 'list_branches':
        try {
            $branches = $pdo->query("SELECT * FROM branches ORDER BY id ASC")->fetchAll();
            echo json_encode(['status' => 'success', 'data' => $branches]);
        } catch (\Exception $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        break;

    case 'save_branch':
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $location = trim($_POST['location'] ?? '');
        $contacts = trim($_POST['contacts'] ?? '');

        if ($name === '') {
            echo json_encode(['status' => 'error', 'message' => 'اسم الفرع مطلوب.']);
            exit();
        }

        try {
            if ($id > 0) {
                $stmt = $pdo->prepare("UPDATE branches SET name = :name, location = :location, contacts = :contacts WHERE id = :id");
                $stmt->execute([':name' => $name, ':location' => $location, ':contacts' => $contacts, ':id' => $id]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO branches (name, location, contacts) VALUES (:name, :location, :contacts)");
                $stmt->execute([':name' => $name, ':location' => $location, ':contacts' => $contacts]);
            }
            echo json_encode(['status' => 'success', 'message' => 'تم حفظ بيانات الفرع بنجاح.']);
        } catch (\Exception $e) {
            echo json_encode(['status' => 'error', 'message' => 'خطأ أثناء الحفظ: ' . $e->getMessage()]);
        }
        break;

    case 'delete_branch':
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 1) {
            echo json_encode(['status' => 'error', 'message' => 'لا يمكن حذف الفرع الرئيسي الافتراضي.']);
            exit();
        }
        try {
            $stmt = $pdo->prepare("DELETE FROM branches WHERE id = :id");
            $stmt->execute([':id' => $id]);
            echo json_encode(['status' => 'success', 'message' => 'تم حذف الفرع بنجاح.']);
        } catch (\Exception $e) {
            echo json_encode(['status' => 'error', 'message' => 'فشل الحذف لوجود بيانات مرتبطة: ' . $e->getMessage()]);
        }
        break;

    case 'list_warehouses':
        $branchId = (int)($_GET['branch_id'] ?? $_POST['branch_id'] ?? 0);
        if ($branchId <= 0) {
            echo json_encode(['status' => 'success', 'data' => []]);
            exit();
        }
        try {
            $stmt = $pdo->prepare("SELECT * FROM warehouses WHERE branch_id = :branch_id ORDER BY id ASC");
            $stmt->execute([':branch_id' => $branchId]);
            $warehouses = $stmt->fetchAll();
            echo json_encode(['status' => 'success', 'data' => $warehouses]);
        } catch (\Exception $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        break;

    case 'save_warehouse':
        $id = (int)($_POST['id'] ?? 0);
        $branchId = (int)($_POST['branch_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $location = trim($_POST['location'] ?? '');

        if ($branchId <= 0 || $name === '') {
            echo json_encode(['status' => 'error', 'message' => 'اسم المستودع والفرع حقول إجبارية.']);
            exit();
        }

        try {
            if ($id > 0) {
                $stmt = $pdo->prepare("UPDATE warehouses SET name = :name, location = :location WHERE id = :id");
                $stmt->execute([':name' => $name, ':location' => $location, ':id' => $id]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO warehouses (branch_id, name, location) VALUES (:branch_id, :name, :location)");
                $stmt->execute([':branch_id' => $branchId, ':name' => $name, ':location' => $location]);
            }
            echo json_encode(['status' => 'success', 'message' => 'تم حفظ بيانات المستودع بنجاح.']);
        } catch (\Exception $e) {
            echo json_encode(['status' => 'error', 'message' => 'خطأ أثناء الحفظ: ' . $e->getMessage()]);
        }
        break;

    case 'delete_warehouse':
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 1) {
            echo json_encode(['status' => 'error', 'message' => 'لا يمكن حذف المستودع الرئيسي الافتراضي.']);
            exit();
        }
        try {
            $stmt = $pdo->prepare("DELETE FROM warehouses WHERE id = :id");
            $stmt->execute([':id' => $id]);
            echo json_encode(['status' => 'success', 'message' => 'تم حذف المستودع بنجاح.']);
        } catch (\Exception $e) {
            echo json_encode(['status' => 'error', 'message' => 'فشل الحذف لوجود بيانات مرتبطة: ' . $e->getMessage()]);
        }
        break;

    case 'list_currencies':
        try {
            $currencies = $pdo->query("SELECT * FROM currencies ORDER BY id ASC")->fetchAll();
            echo json_encode(['status' => 'success', 'data' => $currencies]);
        } catch (\Exception $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        break;

    case 'save_currency':
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $code = trim($_POST['code'] ?? '');
        $symbol = trim($_POST['symbol'] ?? '');
        $exchange_rate = (float)($_POST['exchange_rate'] ?? 1.0);
        $is_base = (int)($_POST['is_base'] ?? 0);

        if ($name === '' || $code === '' || $symbol === '') {
            echo json_encode(['status' => 'error', 'message' => 'جميع الحقول مطلوبة.']);
            exit();
        }

        try {
            $pdo->beginTransaction();
            if ($is_base === 1) {
                // reset previous base currency
                $pdo->exec("UPDATE currencies SET is_base = 0");
                $exchange_rate = 1.0;
            }

            if ($id > 0) {
                $stmt = $pdo->prepare("UPDATE currencies SET name = :name, code = :code, symbol = :symbol, exchange_rate = :rate, is_base = :is_base WHERE id = :id");
                $stmt->execute([':name' => $name, ':code' => $code, ':symbol' => $symbol, ':rate' => $exchange_rate, ':is_base' => $is_base, ':id' => $id]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO currencies (name, code, symbol, exchange_rate, is_base) VALUES (:name, :code, :symbol, :rate, :is_base)");
                $stmt->execute([':name' => $name, ':code' => $code, ':symbol' => $symbol, ':rate' => $exchange_rate, ':is_base' => $is_base]);
            }

            // Sync with settings table if setting currency (id = 1) is the base currency
            if ($is_base === 1) {
                $stmtSync = $pdo->prepare("UPDATE settings SET currency = :currency WHERE id = 1");
                $stmtSync->execute([':currency' => $name]);
            }

            $pdo->commit();
            echo json_encode(['status' => 'success', 'message' => 'تم حفظ العملة بنجاح.']);
        } catch (\Exception $e) {
            $pdo->rollBack();
            echo json_encode(['status' => 'error', 'message' => 'فشل الحفظ: ' . $e->getMessage()]);
        }
        break;

    case 'delete_currency':
        $id = (int)($_POST['id'] ?? 0);
        if ($id === 1) {
            echo json_encode(['status' => 'error', 'message' => 'لا يمكن حذف العملة الأساسية للنظام.']);
            exit();
        }
        try {
            // Ensure not base currency
            $stmtCheck = $pdo->prepare("SELECT is_base FROM currencies WHERE id = :id");
            $stmtCheck->execute([':id' => $id]);
            if ((int)$stmtCheck->fetchColumn() === 1) {
                echo json_encode(['status' => 'error', 'message' => 'لا يمكن حذف عملة معلمة كعملة مرجعية أساسية.']);
                exit();
            }

            $stmt = $pdo->prepare("DELETE FROM currencies WHERE id = :id");
            $stmt->execute([':id' => $id]);
            echo json_encode(['status' => 'success', 'message' => 'تم حذف العملة بنجاح.']);
        } catch (\Exception $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        break;

    case 'list_fiscal_years':
        try {
            $years = $pdo->query("SELECT * FROM fiscal_years ORDER BY id DESC")->fetchAll();
            echo json_encode(['status' => 'success', 'data' => $years]);
        } catch (\Exception $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        break;

    case 'save_fiscal_year':
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $start_date = trim($_POST['start_date'] ?? '');
        $end_date = trim($_POST['end_date'] ?? '');
        $is_closed = (int)($_POST['is_closed'] ?? 0);

        if ($name === '' || $start_date === '' || $end_date === '') {
            echo json_encode(['status' => 'error', 'message' => 'جميع الحقول مطلوبة.']);
            exit();
        }

        try {
            if ($id > 0) {
                $stmt = $pdo->prepare("UPDATE fiscal_years SET name = :name, start_date = :start_date, end_date = :end_date, is_closed = :is_closed WHERE id = :id");
                $stmt->execute([':name' => $name, ':start_date' => $start_date, ':end_date' => $end_date, ':is_closed' => $is_closed, ':id' => $id]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO fiscal_years (name, start_date, end_date, is_closed) VALUES (:name, :start_date, :end_date, :is_closed)");
                $stmt->execute([':name' => $name, ':start_date' => $start_date, ':end_date' => $end_date, ':is_closed' => $is_closed]);
            }
            echo json_encode(['status' => 'success', 'message' => 'تم حفظ الفترة المالية بنجاح.']);
        } catch (\Exception $e) {
            echo json_encode(['status' => 'error', 'message' => 'خطأ أثناء الحفظ: ' . $e->getMessage()]);
        }
        break;

    case 'delete_fiscal_year':
        $id = (int)($_POST['id'] ?? 0);
        try {
            $stmt = $pdo->prepare("DELETE FROM fiscal_years WHERE id = :id");
            $stmt->execute([':id' => $id]);
            echo json_encode(['status' => 'success', 'message' => 'تم حذف الفترة بنجاح.']);
        } catch (\Exception $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        break;

    case 'list_tax_groups':
        try {
            $tax_groups = $pdo->query("SELECT * FROM tax_groups ORDER BY id ASC")->fetchAll();
            echo json_encode(['status' => 'success', 'data' => $tax_groups]);
        } catch (\Exception $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        break;

    case 'save_tax_group':
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $rate = (float)($_POST['rate'] ?? 0.0);
        $is_active = (int)($_POST['is_active'] ?? 1);

        if ($name === '') {
            echo json_encode(['status' => 'error', 'message' => 'اسم المجموعة الضريبية مطلوب.']);
            exit();
        }

        try {
            if ($id > 0) {
                $stmt = $pdo->prepare("UPDATE tax_groups SET name = :name, rate = :rate, is_active = :is_active WHERE id = :id");
                $stmt->execute([':name' => $name, ':rate' => $rate, ':is_active' => $is_active, ':id' => $id]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO tax_groups (name, rate, is_active) VALUES (:name, :rate, :is_active)");
                $stmt->execute([':name' => $name, ':rate' => $rate, ':is_active' => $is_active]);
            }
            echo json_encode(['status' => 'success', 'message' => 'تم حفظ المجموعة الضريبية بنجاح.']);
        } catch (\Exception $e) {
            echo json_encode(['status' => 'error', 'message' => 'خطأ أثناء الحفظ: ' . $e->getMessage()]);
        }
        break;

    case 'delete_tax_group':
        $id = (int)($_POST['id'] ?? 0);
        try {
            $stmt = $pdo->prepare("DELETE FROM tax_groups WHERE id = :id");
            $stmt->execute([':id' => $id]);
            echo json_encode(['status' => 'success', 'message' => 'تم حذف المجموعة الضريبية بنجاح.']);
        } catch (\Exception $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        break;

    case 'save_business_rules':
        $data = [
            'allow_negative_stock' => (int)($_POST['allow_negative_stock'] ?? 0),
            'inventory_valuation_method' => ($_POST['inventory_valuation_method'] === 'FIFO') ? 'FIFO' : 'AVG',
            'max_discount_limit' => (float)($_POST['max_discount_limit'] ?? 100.0)
        ];

        $res = \AQNEX\Services\ConfigService::updateBusinessRules($data);
        if ($res) {
            echo json_encode(['status' => 'success', 'message' => 'تم حفظ سياسات وقواعد العمل بنجاح.']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'حدث خطأ أثناء حفظ القواعد.']);
        }
        break;

    case 'save_support_config':
        $industryType = trim($_POST['industry_type'] ?? '');
        $allowedTypes = ['Telecom', 'Grocery', 'Retail', 'General'];
        if (!in_array($industryType, $allowedTypes, true)) {
            echo json_encode(['status' => 'error', 'message' => 'نوع النشاط التجاري المحدد غير صالح.']);
            exit();
        }

        $res = \AQNEX\Services\ConfigService::updateSettings(['industry_type' => $industryType]);
        if ($res) {
            echo json_encode(['status' => 'success', 'message' => 'تم تغيير نوع النشاط التجاري للنظام بنجاح.']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'فشل التغيير.']);
        }
        break;

    case 'reset_system':
        $res = \AQNEX\Services\ConfigService::resetSystemData();
        if ($res) {
            echo json_encode(['status' => 'success', 'message' => '✓ تم تصفير بيانات العمليات والحسابات في النظام بالكامل وبنجاح!']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'حدث خطأ غير متوقع أثناء تصفير النظام!']);
        }
        break;

    default:
        echo json_encode(['status' => 'error', 'message' => 'الطلب غير معروف.']);
        break;
}
exit();
