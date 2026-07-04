<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// تحميل ملفات النواة الحساسة
require_once(__DIR__ . '/Licensing.php');
require_once(__DIR__ . '/AntiTamper.php');

// تحديد المسار الأساسي للمشروع ديناميكياً لتجنب مشاكل المجلدات الفرعية
$projectRoot = str_replace('\\', '/', realpath(__DIR__ . '/../'));
$docRoot = str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']);
$baseUrl = '';
if (strpos($projectRoot, $docRoot) === 0) {
    $baseUrl = substr($projectRoot, strlen($docRoot));
}
$baseUrl = '/' . ltrim(str_replace('\\', '/', $baseUrl), '/');
$baseUrl = rtrim($baseUrl, '/');

// المسار الحالي للصفحة المطلوبة
$currentUri = str_replace('\\', '/', $_SERVER['SCRIPT_NAME']);

// استثناء ملفات الأصول (Assets) والـ AJAX لتجنب مقاطعة الاتصالات الخلفية
$isAsset = preg_match('/\.(css|js|png|jpg|jpeg|gif|ico|svg|woff|woff2|ttf|eot)$/i', $currentUri);
$isAjax = (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') 
          || (strpos($currentUri, '/ajax/') !== false);

if ($isAsset || $isAjax) {
    return; // عدم إجراء التحقق على الأصول أو طلبات AJAX الخلفية
}

// الصفحات الحساسة الخاصة بمعالجات النظام
$activateUrl = $baseUrl . '/auth/activate.php';
$tamperingUrl = $baseUrl . '/auth/tampering.php';
$setupUrl = $baseUrl . '/auth/setup_wizard.php';
$loginUrl = $baseUrl . '/auth/login.php';

// تهيئة الكلاسات الأساسية للتحقق
$licensing = new \AQNEX\Core\Licensing();
$antiTamper = new \AQNEX\Core\AntiTamper($conn);

// 1. التحقق من ملف الترخيص (تجاوز الفحص في وضع CLI لمنع توقف سكربتات التهيئة والترحيل)
$isCli = (php_sapi_name() === 'cli');
if ($isCli) {
    $verify = ['status' => true];
    $isTimeValid = true;
    $isLocked = false;
} else {
    $verify = $licensing->verifyLicense();
    $isTimeValid = true;
    $isLocked = false;
}

if (!$verify['status']) {
    // الترخيص غير صالح أو غير موجود
    if ($currentUri !== $activateUrl) {
        header("Location: " . $activateUrl);
        exit();
    }
} else {
    // الترخيص صالح. فحص كشف التلاعب بالوقت
    if (!$isCli) {
        $isTimeValid = $antiTamper->checkSystemTime();
        $isLocked = $antiTamper->isLocked();
    }
    
    if (!$isTimeValid || $isLocked) {
        if ($currentUri !== $tamperingUrl) {
            header("Location: " . $tamperingUrl);
            exit();
        }
    } else {
        // التأكد من وجود جدول الإعدادات وإنشائه إن كان مفقوداً
        $checkTable = $conn->query("SHOW TABLES LIKE 'settings'");
        if ($checkTable && $checkTable->num_rows == 0) {
            $conn->query("CREATE TABLE `settings` (
              `id` int(11) NOT NULL PRIMARY KEY,
              `store_name` varchar(100) NOT NULL,
              `phone` varchar(50) DEFAULT NULL,
              `address` text DEFAULT NULL,
              `commercial_register` varchar(100) DEFAULT NULL,
              `tax_number` varchar(100) DEFAULT NULL,
              `currency` varchar(20) DEFAULT 'ريال يمني',
              `barcode_scanner` tinyint(1) DEFAULT 1,
              `printer_type` varchar(50) DEFAULT 'receipt_80mm',
              `tax_percent` double DEFAULT 0,
              `low_stock_threshold` int(11) DEFAULT 5,
              `receipt_footer` text DEFAULT NULL,
              `logo` varchar(255) DEFAULT NULL,
              `is_configured` tinyint(1) NOT NULL DEFAULT 0,
              `support_token` varchar(255) DEFAULT 'ReplaceWithStrongSupportToken123!'
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            
            $conn->query("INSERT INTO `settings` (`id`, `store_name`, `phone`, `address`, `currency`, `barcode_scanner`, `printer_type`, `tax_percent`, `low_stock_threshold`, `receipt_footer`, `is_configured`, `support_token`) 
                VALUES (1, 'تكنولوجيا فون', '777777777', 'اليمن - عدن', 'ريال يمني', 1, 'receipt_80mm', 0, 5, 'شكرًا لزيارتكم!', 0, 'ReplaceWithStrongSupportToken123!')
                ON DUPLICATE KEY UPDATE id=id");
        } else {
            // التأكد من وجود الأعمدة الجديدة في جدول الإعدادات لتفادي مشاكل الترقية أو التهيئة الأولى
            $checkCol = $conn->query("SHOW COLUMNS FROM `settings` LIKE 'is_configured'");
            if ($checkCol && $checkCol->num_rows == 0) {
                $conn->query("ALTER TABLE `settings` ADD COLUMN `commercial_register` varchar(100) DEFAULT NULL AFTER `address`");
                $conn->query("ALTER TABLE `settings` ADD COLUMN `tax_number` varchar(100) DEFAULT NULL AFTER `commercial_register`");
                $conn->query("ALTER TABLE `settings` ADD COLUMN `logo` varchar(255) DEFAULT NULL AFTER `receipt_footer`");
                $conn->query("ALTER TABLE `settings` ADD COLUMN `is_configured` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'تحديد إذا ما تم تشغيل معالج الإعداد الأول'");
            }

            // التأكد من وجود أعمدة إعدادات الواتساب وتكامل الذكاء الاصطناعي في جدول الإعدادات
            $checkWA = $conn->query("SHOW COLUMNS FROM `settings` LIKE 'whatsapp_token'");
            if ($checkWA && $checkWA->num_rows == 0) {
                $conn->query("ALTER TABLE `settings` 
                    ADD COLUMN `whatsapp_token` varchar(255) DEFAULT NULL,
                    ADD COLUMN `whatsapp_instance` varchar(100) DEFAULT NULL,
                    ADD COLUMN `whatsapp_enabled` tinyint(1) NOT NULL DEFAULT 0,
                    ADD COLUMN `gemini_api_key` varchar(255) DEFAULT NULL");
            }

            // التأكد من وجود عمود رمز الدعم الفني
            $checkSupport = $conn->query("SHOW COLUMNS FROM `settings` LIKE 'support_token'");
            if ($checkSupport && $checkSupport->num_rows == 0) {
                $conn->query("ALTER TABLE `settings` ADD COLUMN `support_token` varchar(255) DEFAULT 'ReplaceWithStrongSupportToken123!'");
            }
        }

        // التأكد من وجود عمود sector_id في جدول القيود المحاسبية
        $checkSectorCol = $conn->query("SHOW COLUMNS FROM `accounting_journal` LIKE 'sector_id'");
        if ($checkSectorCol && $checkSectorCol->num_rows == 0) {
            $conn->query("ALTER TABLE `accounting_journal` ADD COLUMN `sector_id` int(11) DEFAULT NULL AFTER `box_id`");
        }

        // ==========================================
        // تهيئة جداول وتعديلات ميزات المرحلة 1 و 2
        // ==========================================

        // التأكد من وجود جدول الموديولات وتدشينها
        $checkModules = $conn->query("SHOW TABLES LIKE 'system_modules'");
        if ($checkModules && $checkModules->num_rows == 0) {
            $conn->query("CREATE TABLE `system_modules` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `module_key` VARCHAR(50) NOT NULL UNIQUE,
                `module_name` VARCHAR(100) NOT NULL DEFAULT '',
                `is_enabled` TINYINT(1) NOT NULL DEFAULT 0,
                `config_json` TEXT DEFAULT NULL,
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            
            $conn->query("INSERT INTO `system_modules` (`module_key`, `module_name`, `is_enabled`) VALUES
                ('barcode_units', 'وحدات متعددة وباركودات متعددة', 1),
                ('expiry_tracking', 'تتبع تواريخ الصلاحية', 0),
                ('serial_imei_tracking', 'تتبع الأرقام التسلسلية / IMEI', 0),
                ('repair_service', 'وحدة الصيانة', 0),
                ('installments', 'البيع بالتقسيط', 0),
                ('thermal_printing', 'الطباعة الحرارية', 1),
                ('label_printing', 'طباعة ملصقات الباركود', 1)");
        }

        // التأكد من جدول الباركودات المتعددة
        $checkBC = $conn->query("SHOW TABLES LIKE 'product_barcodes'");
        if ($checkBC && $checkBC->num_rows == 0) {
            $conn->query("CREATE TABLE `product_barcodes` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `product_id` INT NOT NULL,
                `barcode` VARCHAR(100) NOT NULL DEFAULT '',
                `unit_id` INT DEFAULT NULL,
                `is_primary` TINYINT(1) NOT NULL DEFAULT 0,
                `d_s` CHAR(1) NOT NULL DEFAULT '0',
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY `uniq_barcode` (`barcode`),
                INDEX `idx_product` (`product_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }

        // التأكد من جدول الوحدات المتعددة
        $checkUnits = $conn->query("SHOW TABLES LIKE 'product_units'");
        if ($checkUnits && $checkUnits->num_rows == 0) {
            $conn->query("CREATE TABLE `product_units` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `product_id` INT NOT NULL,
                `unit_name` VARCHAR(50) NOT NULL DEFAULT '',
                `conversion_factor` DECIMAL(12,4) NOT NULL DEFAULT 1.0000,
                `sale_price` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                `purchase_price` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                `is_base_unit` TINYINT(1) NOT NULL DEFAULT 0,
                `d_s` CHAR(1) NOT NULL DEFAULT '0',
                INDEX `idx_product` (`product_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }

        // التأكد من جدول تشغيلات وتواريخ الصلاحية
        $checkBatches = $conn->query("SHOW TABLES LIKE 'product_batches'");
        if ($checkBatches && $checkBatches->num_rows == 0) {
            $conn->query("CREATE TABLE `product_batches` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `product_id` INT NOT NULL,
                `purchase_item_id` INT DEFAULT NULL,
                `batch_number` VARCHAR(50) NOT NULL DEFAULT '',
                `quantity` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                `expiry_date` DATE DEFAULT NULL,
                `d_s` CHAR(1) NOT NULL DEFAULT '0',
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX `idx_product_expiry` (`product_id`, `expiry_date`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }

        // التأكد من جدول الأرقام التسلسلية / IMEI
        $checkSerials = $conn->query("SHOW TABLES LIKE 'product_serials'");
        if ($checkSerials && $checkSerials->num_rows == 0) {
            $conn->query("CREATE TABLE `product_serials` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `product_id` INT NOT NULL,
                `serial_number` VARCHAR(100) NOT NULL DEFAULT '',
                `imei_1` VARCHAR(20) NOT NULL DEFAULT '',
                `imei_2` VARCHAR(20) NOT NULL DEFAULT '',
                `status` ENUM('in_stock','sold','returned','defective') NOT NULL DEFAULT 'in_stock',
                `purchase_item_id` INT DEFAULT NULL,
                `sale_item_id` INT DEFAULT NULL,
                `warranty_start` DATE DEFAULT NULL,
                `warranty_end` DATE DEFAULT NULL,
                `d_s` CHAR(1) NOT NULL DEFAULT '0',
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY `uniq_imei1` (`imei_1`),
                INDEX `idx_product_status` (`product_id`, `status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }

        // التأكد من جدول تذاكر الصيانة
        $checkRepairs = $conn->query("SHOW TABLES LIKE 'repair_tickets'");
        if ($checkRepairs && $checkRepairs->num_rows == 0) {
            $conn->query("CREATE TABLE `repair_tickets` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `ticket_number` VARCHAR(30) NOT NULL DEFAULT '',
                `customer_id` INT DEFAULT NULL,
                `device_name` VARCHAR(200) NOT NULL DEFAULT '',
                `device_type` VARCHAR(100) NOT NULL DEFAULT '',
                `device_brand` VARCHAR(100) NOT NULL DEFAULT '',
                `imei` VARCHAR(20) NOT NULL DEFAULT '',
                `issue_type` VARCHAR(150) NOT NULL DEFAULT '',
                `expected_delivery_date` DATE DEFAULT NULL,
                `problem_description` TEXT,
                `diagnosis` TEXT,
                `status` ENUM('received','in_progress','waiting_parts','completed','delivered','cancelled') NOT NULL DEFAULT 'received',
                `estimated_cost` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                `final_cost` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                `technician_id` INT DEFAULT NULL,
                `received_date` DATETIME DEFAULT CURRENT_TIMESTAMP,
                `delivered_date` DATETIME DEFAULT NULL,
                `d_s` CHAR(1) NOT NULL DEFAULT '0',
                INDEX `idx_status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } else {
            $checkDeviceNameCol = $conn->query("SHOW COLUMNS FROM `repair_tickets` LIKE 'device_name'");
            if ($checkDeviceNameCol && $checkDeviceNameCol->num_rows == 0) {
                $conn->query("ALTER TABLE `repair_tickets` ADD COLUMN `device_name` VARCHAR(200) NOT NULL DEFAULT '' AFTER `customer_id`");
            }
            $checkIssueTypeCol = $conn->query("SHOW COLUMNS FROM `repair_tickets` LIKE 'issue_type'");
            if ($checkIssueTypeCol && $checkIssueTypeCol->num_rows == 0) {
                $conn->query("ALTER TABLE `repair_tickets` ADD COLUMN `issue_type` VARCHAR(150) NOT NULL DEFAULT '' AFTER `imei`");
            }
            $checkExpectedDeliveryCol = $conn->query("SHOW COLUMNS FROM `repair_tickets` LIKE 'expected_delivery_date'");
            if ($checkExpectedDeliveryCol && $checkExpectedDeliveryCol->num_rows == 0) {
                $conn->query("ALTER TABLE `repair_tickets` ADD COLUMN `expected_delivery_date` DATE DEFAULT NULL AFTER `issue_type`");
            }
        }

        // التأكد من جدول أنواع أعطال الصيانة
        $checkIssueTypes = $conn->query("SHOW TABLES LIKE 'repair_issue_types'");
        if ($checkIssueTypes && $checkIssueTypes->num_rows == 0) {
            $conn->query("CREATE TABLE `repair_issue_types` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `type_name` VARCHAR(150) NOT NULL DEFAULT '',
                `d_s` CHAR(1) NOT NULL DEFAULT '0'
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $conn->query("INSERT INTO repair_issue_types (type_name, d_s) VALUES
                ('الشاشة مكسورة', '0'),
                ('لا يعمل الجهاز', '0'),
                ('لا يشحن', '0'),
                ('البطارية سريعة النفاد', '0'),
                ('الكاميرا لا تعمل', '0'),
                ('يعيد التشغيل تلقائياً', '0')");
        }

        // التأكد من جدول قطع الصيانة المستخدمة
        $checkRepairParts = $conn->query("SHOW TABLES LIKE 'repair_parts_used'");
        if ($checkRepairParts && $checkRepairParts->num_rows == 0) {
            $conn->query("CREATE TABLE `repair_parts_used` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `repair_ticket_id` INT NOT NULL,
                `product_id` INT DEFAULT NULL,
                `part_name` VARCHAR(150) NOT NULL DEFAULT '',
                `quantity` DECIMAL(10,2) NOT NULL DEFAULT 1.00,
                `cost` DECIMAL(14,2) NOT NULL DEFAULT 0.00
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }

        // التأكد من جدول خطط التقسيط
        $checkInstPlans = $conn->query("SHOW TABLES LIKE 'installment_plans'");
        if ($checkInstPlans && $checkInstPlans->num_rows == 0) {
            $conn->query("CREATE TABLE `installment_plans` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `sale_id` INT NOT NULL,
                `customer_id` INT NOT NULL,
                `total_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                `down_payment` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                `remaining_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                `installments_count` INT NOT NULL DEFAULT 1,
                `status` ENUM('active','completed','defaulted') NOT NULL DEFAULT 'active',
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }

        // التأكد من جدول جدولة الأقساط
        $checkInstSched = $conn->query("SHOW TABLES LIKE 'installment_schedule'");
        if ($checkInstSched && $checkInstSched->num_rows == 0) {
            $conn->query("CREATE TABLE `installment_schedule` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `plan_id` INT NOT NULL,
                `installment_number` INT NOT NULL DEFAULT 1,
                `due_date` DATE NOT NULL,
                `amount_due` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                `amount_paid` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                `status` ENUM('pending','paid','overdue') NOT NULL DEFAULT 'pending',
                `paid_at` DATETIME DEFAULT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }

        // التأكد من جدول الإشعارات
        $checkNotifs = $conn->query("SHOW TABLES LIKE 'notifications'");
        if ($checkNotifs && $checkNotifs->num_rows == 0) {
            $conn->query("CREATE TABLE `notifications` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `type` VARCHAR(50) NOT NULL DEFAULT '',
                `title` VARCHAR(200) NOT NULL DEFAULT '',
                `message` TEXT,
                `related_id` INT DEFAULT NULL,
                `is_read` TINYINT(1) NOT NULL DEFAULT 0,
                `target_role` VARCHAR(20) NOT NULL DEFAULT 'admin',
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }

        // التأكد من جدول إعدادات الطابعات
        $checkPrinters = $conn->query("SHOW TABLES LIKE 'printer_settings'");
        if ($checkPrinters && $checkPrinters->num_rows == 0) {
            $conn->query("CREATE TABLE `printer_settings` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `printer_name` VARCHAR(100) NOT NULL DEFAULT '',
                `printer_type` ENUM('thermal_80','thermal_58','a4','label_zpl') NOT NULL DEFAULT 'thermal_80',
                `connection_type` ENUM('usb','network','bluetooth') NOT NULL DEFAULT 'usb',
                `ip_address` VARCHAR(50) NOT NULL DEFAULT '',
                `port` INT NOT NULL DEFAULT 9100,
                `usb_vendor_id` VARCHAR(20) NOT NULL DEFAULT '',
                `usb_product_id` VARCHAR(20) NOT NULL DEFAULT '',
                `is_default` TINYINT(1) NOT NULL DEFAULT 0,
                `template_json` TEXT,
                `d_s` CHAR(1) NOT NULL DEFAULT '0',
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }

        // إضافة عمود purchase_id لجدول purchase_items لربط البنود بالفاتورة الأصل
        $checkColPI = $conn->query("SHOW COLUMNS FROM `purchase_items` LIKE 'purchase_id'");
        if ($checkColPI && $checkColPI->num_rows == 0) {
            $conn->query("ALTER TABLE `purchase_items` ADD COLUMN `purchase_id` INT DEFAULT NULL AFTER `buyid`, ADD INDEX `idx_purchase_id` (`purchase_id`)");
        }

        // إضافة عمود remaining_total لجدول purchases إذا لم يكن موجوداً
        $checkColPurRem = $conn->query("SHOW COLUMNS FROM `purchases` LIKE 'remaining_total'");
        if ($checkColPurRem && $checkColPurRem->num_rows == 0) {
            $conn->query("ALTER TABLE `purchases` ADD COLUMN `remaining_total` DECIMAL(15,2) NOT NULL DEFAULT 0.00");
        }

        // إضافة عمود is_transferred_to_box لجدول sales لتأخير ترحيل السيولة للصندوق
        $checkColSalesTransfer = $conn->query("SHOW COLUMNS FROM `sales` LIKE 'is_transferred_to_box'");
        if ($checkColSalesTransfer && $checkColSalesTransfer->num_rows == 0) {
            $conn->query("ALTER TABLE `sales` ADD COLUMN `is_transferred_to_box` TINYINT(1) NOT NULL DEFAULT 0 AFTER `box_id`, ADD INDEX `idx_sales_transfer` (`is_transferred_to_box`)");
        }

        // إضافة عمود remark لجدول purchases إذا لم يكن موجوداً
        $checkColPurRem2 = $conn->query("SHOW COLUMNS FROM `purchases` LIKE 'remark'");
        if ($checkColPurRem2 && $checkColPurRem2->num_rows == 0) {
            $conn->query("ALTER TABLE `purchases` ADD COLUMN `remark` TEXT DEFAULT NULL");
        }

        // تعديلات الجداول الحالية بأمان
        $checkColCat = $conn->query("SHOW COLUMNS FROM `categories` LIKE 'requires_serial'");
        if ($checkColCat && $checkColCat->num_rows == 0) {
            $conn->query("ALTER TABLE `categories` ADD COLUMN `requires_serial` TINYINT(1) NOT NULL DEFAULT 0");
        }

        $checkColProd1 = $conn->query("SHOW COLUMNS FROM `products` LIKE 'min_stock_alert'");
        if ($checkColProd1 && $checkColProd1->num_rows == 0) {
            $conn->query("ALTER TABLE `products` ADD COLUMN `min_stock_alert` DECIMAL(10,2) NOT NULL DEFAULT 0.00");
        }

        $checkColProd2 = $conn->query("SHOW COLUMNS FROM `products` LIKE 'has_multiple_units'");
        if ($checkColProd2 && $checkColProd2->num_rows == 0) {
            $conn->query("ALTER TABLE `products` ADD COLUMN `has_multiple_units` TINYINT(1) NOT NULL DEFAULT 0");
        }

        $checkColProd3 = $conn->query("SHOW COLUMNS FROM `products` LIKE 'track_expiry'");
        if ($checkColProd3 && $checkColProd3->num_rows == 0) {
            $conn->query("ALTER TABLE `products` ADD COLUMN `track_expiry` TINYINT(1) NOT NULL DEFAULT 0");
        }

        // إضافة أعمدة التقارير الرسمية وإضافات الموظفين والعملاء والموردين
        $checkSettingsReport = $conn->query("SHOW COLUMNS FROM `settings` LIKE 'report_header_subtitle'");
        if ($checkSettingsReport && $checkSettingsReport->num_rows == 0) {
            $conn->query("ALTER TABLE `settings` 
                ADD COLUMN `report_header_subtitle` VARCHAR(255) DEFAULT '',
                ADD COLUMN `report_header_notes` TEXT DEFAULT NULL,
                ADD COLUMN `report_show_logo` TINYINT(1) NOT NULL DEFAULT 1,
                ADD COLUMN `report_show_cr` TINYINT(1) NOT NULL DEFAULT 1,
                ADD COLUMN `report_show_tax` TINYINT(1) NOT NULL DEFAULT 1");
        }

        $checkColCustEmail = $conn->query("SHOW COLUMNS FROM `customers` LIKE 'email'");
        if ($checkColCustEmail && $checkColCustEmail->num_rows == 0) {
            $conn->query("ALTER TABLE `customers` 
                ADD COLUMN `email` VARCHAR(100) DEFAULT '',
                ADD COLUMN `address` VARCHAR(255) DEFAULT '',
                ADD COLUMN `credit_limit` DECIMAL(25,2) NOT NULL DEFAULT 0.00,
                ADD COLUMN `notes` TEXT DEFAULT NULL");
        }

        $checkColSuppEmail = $conn->query("SHOW COLUMNS FROM `suppliers` LIKE 'email'");
        if ($checkColSuppEmail && $checkColSuppEmail->num_rows == 0) {
            $conn->query("ALTER TABLE `suppliers` 
                ADD COLUMN `email` VARCHAR(100) DEFAULT '',
                ADD COLUMN `address` VARCHAR(255) DEFAULT '',
                ADD COLUMN `company_name` VARCHAR(150) DEFAULT '',
                ADD COLUMN `notes` TEXT DEFAULT NULL");
        }

        // التأكد من وجود جدول دليل الحسابات وتأسيسه تلقائياً
        $checkAccounts = $conn->query("SHOW TABLES LIKE 'accounting_accounts'");
        if ($checkAccounts && $checkAccounts->num_rows == 0) {
            $conn->query("CREATE TABLE `accounting_accounts` (
              `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
              `code` varchar(50) NOT NULL UNIQUE,
              `name` varchar(150) NOT NULL,
              `parent_id` int(11) DEFAULT NULL,
              `account_type` enum('asset','liability','equity','revenue','expense') NOT NULL,
              `is_parent` tinyint(1) NOT NULL DEFAULT 0,
              `level` int(11) NOT NULL DEFAULT 1,
              `notes` text DEFAULT NULL,
              `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $conn->query("INSERT INTO `accounting_accounts` (id, code, name, parent_id, account_type, is_parent, level, notes) VALUES
                (1, '1', 'الأصول', NULL, 'asset', 1, 1, 'الحساب الرئيسي للأصول'),
                (2, '2', 'الخصوم والالتزامات', NULL, 'liability', 1, 1, 'الحساب الرئيسي للخصوم والالتزامات'),
                (3, '3', 'حقوق الملكية', NULL, 'equity', 1, 1, 'الحساب الرئيسي لحقوق الملكية'),
                (4, '4', 'الإيرادات', NULL, 'revenue', 1, 1, 'الحساب الرئيسي للإيرادات'),
                (5, '5', 'المصروفات', NULL, 'expense', 1, 1, 'الحساب الرئيسي للمصروفات')");
                
            $conn->query("INSERT INTO `accounting_accounts` (id, code, name, parent_id, account_type, is_parent, level, notes) VALUES
                (6, '11', 'الأصول المتداولة', 1, 'asset', 1, 2, 'الأصول المتداولة والسيولة'),
                (7, '12', 'الأصول الثابتة', 1, 'asset', 1, 2, 'الأصول الثابتة للمنشأة'),
                (8, '21', 'الالتزامات المتداولة', 2, 'liability', 1, 2, 'الالتزامات المتداولة والديون قصيرة الأجل'),
                (9, '31', 'رأس المال والاحتياطيات', 3, 'equity', 1, 2, 'رأس المال والاحتياطيات وحقوق الشركاء')");
                
            $conn->query("INSERT INTO `accounting_accounts` (id, code, name, parent_id, account_type, is_parent, level, notes) VALUES
                (10, '1101', 'الصناديق والسيولة', 6, 'asset', 1, 3, 'حساب الصناديق النقدية والسيولة'),
                (11, '1102', 'الذمم المدينة', 6, 'asset', 1, 3, 'حساب مديونيات العملاء الآجلة'),
                (12, '1103', 'المخزون / البضاعة', 6, 'asset', 0, 3, 'حساب تقييم بضاعة المخازن'),
                (13, '1104', 'نقدية مبيعات معلقة', 6, 'asset', 1, 3, 'حساب مبيعات اليوم النقدية قبل الترحيل للصندوق'),
                (14, '2101', 'الذمم الدائنة', 8, 'liability', 1, 3, 'حساب مستحقات الموردين الآجلة'),
                (15, '3101', 'رأس المال المفتوح', 9, 'equity', 0, 3, 'رأس مال المشروع التأسيسي'),
                (16, '3102', 'رأس المال / رصيد افتتاحي', 9, 'equity', 0, 3, 'رأس المال الافتتاحي للصناديق'),
                (17, '3103', 'رأس المال / دفع خارجي', 9, 'equity', 0, 3, 'المسحوبات والمشاركات الخارجية'),
                (18, '4101', 'المبيعات', 4, 'revenue', 0, 2, 'حساب إيرادات المبيعات العامة'),
                (19, '4102', 'مردودات المبيعات', 4, 'revenue', 0, 2, 'حساب مرتجعات المبيعات'),
                (20, '4103', 'إيرادات الصيانة والخدمات', 4, 'revenue', 0, 2, 'إيرادات صيانة الأجهزة والخدمات الفنية'),
                (21, '4104', 'زيادات وفروقات الصناديق (إيراد)', 4, 'revenue', 0, 2, 'إيرادات فروقات وإقفال الصناديق'),
                (22, '5101', 'تكلفة البضاعة المباعة', 5, 'expense', 0, 2, 'حساب تكلفة البضاعة المباعة للعملاء'),
                (23, '5102', 'المصروفات العامة والتشغيلية', 5, 'expense', 1, 2, 'حساب المصروفات والتشغيل العام'),
                (24, '5103', 'الخصم المسموح به (مصروف)', 5, 'expense', 0, 2, 'الخصومات الممنوحة للعملاء'),
                (25, '5104', 'عجز وفروقات الصناديق (مصروف)', 5, 'expense', 0, 2, 'مصروفات عجز وإقفال الصناديق')");
        }

        // التأكد من إدخال العملات الثلاث في جدول العملات
        $checkCurrCount = $conn->query("SELECT COUNT(*) as cnt FROM currencies");
        if ($checkCurrCount && $checkCurrCount->fetch_assoc()['cnt'] == 0) {
            $conn->query("INSERT INTO `currencies` (`id`, `name`, `code`, `symbol`, `exchange_rate`, `is_base`) VALUES
                (1, 'ريال يمني', 'YER', 'ر.ي', 1.0, 1),
                (2, 'دولار أمريكي', 'USD', '$', 530.0, 0),
                (3, 'ريال سعودي', 'SAR', 'ر.س', 140.0, 0)");
        }

        // التأكد من جدول القطاعات (sectors)
        $checkSectors = $conn->query("SHOW TABLES LIKE 'sectors'");
        if ($checkSectors && $checkSectors->num_rows == 0) {
            $conn->query("CREATE TABLE `sectors` (
              `sector_id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
              `name` varchar(100) NOT NULL,
              `is_active` tinyint(1) NOT NULL DEFAULT 1
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $conn->query("INSERT INTO `sectors` (`sector_id`, `name`, `is_active`) VALUES (1, 'القطاع الرئيسي', 1)");
        }

        // الترخيص والوقت سليمان. فحص معالج الإعداد الأول
        $settingsRes = $conn->query("SELECT is_configured FROM settings WHERE id = 1");
        $settings = $settingsRes ? $settingsRes->fetch_assoc() : null;
        $isConfigured = $settings ? intval($settings['is_configured']) : 0;
        
        if (!$isCli && $isConfigured === 0) {
            if ($currentUri !== $setupUrl && $currentUri !== $activateUrl && strpos($currentUri, '/support_tools/') === false) {
                header("Location: " . $setupUrl);
                exit();
            }
        } elseif (!$isCli) {
            // النظام مفعل ومعد بالكامل. منع الدخول لصفحات التهيئة والتلاعب وإعادتهم للرئيسية
            if ($currentUri === $setupUrl || $currentUri === $tamperingUrl) {
                header("Location: " . $baseUrl . "/home.php");
                exit();
            }
        }
}}
?>