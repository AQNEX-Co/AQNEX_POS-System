<?php
// ======================================================
// دالة الحصول على معرف صندوق المستخدم الحالي
// ======================================================
if (!function_exists('get_user_box_id')) {
    function get_user_box_id($conn, $user_id) {
        $user_id = intval($user_id);
        $res = $conn->query("SELECT box_id FROM treasury WHERE user_id = $user_id AND is_active = 1 LIMIT 1");
        if ($res && $res->num_rows > 0) {
            return intval($res->fetch_assoc()['box_id']);
        }
        return 1; // الافتراضي هو الصندوق الرئيسي
    }
}

// ======================================================
// دالة الحصول على اسم الصندوق المالي
// ======================================================
if (!function_exists('get_box_name')) {
    function get_box_name($conn, $box_id) {
        $box_id = intval($box_id);
        $res = $conn->query("SELECT name FROM treasury WHERE box_id = $box_id LIMIT 1");
        if ($res && $res->num_rows > 0) {
            return $res->fetch_assoc()['name'];
        }
        return 'الصندوق الرئيسي';
    }
}

// ======================================================
// دالة قراءة رصيد الصندوق الحالي
// ======================================================
if (!function_exists('get_box_balance')) {
    function get_box_balance($conn, $box_id) {
        $box_id = intval($box_id);
        $res = $conn->query("SELECT mony FROM treasury WHERE box_id = $box_id LIMIT 1");
        if ($res && $res->num_rows > 0) {
            return doubleval($res->fetch_assoc()['mony']);
        }
        return 0.0;
    }
}

// ======================================================
// دالة تحديث رصيد الصندوق وتسجيل المعاملة التاريخية
// ======================================================
if (!function_exists('update_box_balance')) {
    function update_box_balance($conn, $box_id, $amount, $type, $remark, $date) {
        $box_id = intval($box_id);
        $amount = doubleval($amount);
        $type = trim($type); // 'addition' (إيداع/مقبوضات) أو 'discount' (سحب/مصاريف)
        $remark = $conn->real_escape_string($remark);
        $date = $conn->real_escape_string($date);

        if ($amount <= 0) return true;

        if ($type !== 'addition') {
            $current_balance = get_box_balance($conn, $box_id);
            if ($current_balance < $amount) {
                return false;
            }
        }

        // تحديث الرصيد في جدول الصناديق
        if ($type === 'addition') {
            $sql_update = "UPDATE treasury SET mony = mony + $amount WHERE box_id = $box_id";
        } else {
            $sql_update = "UPDATE treasury SET mony = mony - $amount WHERE box_id = $box_id";
        }
        if (!$conn->query($sql_update)) {
            return false;
        }

        // تسجيل المعاملة في جدول حركات الصناديق
        $statue = ($type === 'addition') ? 'addition' : 'discount';
        $sql_log = "INSERT INTO treasury_transactions (mony, statue, remark, datte, box_id) 
                    VALUES ($amount, '$statue', '$remark', '$date', $box_id)";
        return $conn->query($sql_log);
    }
}

// ======================================================
// دالة تسجيل قيد محاسبي مزدوج (Double-entry Journal Line)
// ======================================================
if (!function_exists('post_journal_entry')) {
    function post_journal_entry($conn, $ref_type, $ref_id, $debit_acc, $credit_acc, $amount, $desc, $user, $box_id = null, $curr = 'YER', $rate = 1.0, $sector_id = null) {
        return \AQNEX\Services\AccountingService::post(
            $conn, 
            $ref_type, 
            $ref_id, 
            $debit_acc, 
            $credit_acc, 
            $amount, 
            $desc, 
            $user, 
            $box_id, 
            'general', 
            null, 
            'general', 
            null, 
            $curr, 
            $rate, 
            $sector_id
        );
    }
}

// ======================================================
// دالة ترحيل المبيعات النقدية المعلقة إلى الصندوق المالي
// ======================================================
if (!function_exists('transfer_sales_to_box')) {
    function transfer_sales_to_box($conn, $box_id, $user_name) {
        return 0.0;
    }
}

// ======================================================
// إنشاء جداول المحاسبة تلقائياً عند أول تشغيل
// Auto-migration: runs safely with IF NOT EXISTS
// ======================================================
if (isset($conn) && !isset($ACCOUNTING_TABLES_CHECKED)) {
    $ACCOUNTING_TABLES_CHECKED = true;
    // Check if accounting_sequences exists (simplest indicator)
    $chk = $conn->query("SELECT 1 FROM accounting_sequences LIMIT 1");
    if (!$chk) {
        // Tables are missing — create them
        $conn->query("CREATE TABLE IF NOT EXISTS `accounting_journal_entries` (
          `id` INT NOT NULL AUTO_INCREMENT, `entry_date` DATE NOT NULL,
          `reference_no` VARCHAR(50) NOT NULL DEFAULT '', `description` VARCHAR(500) NOT NULL DEFAULT '',
          `source_type` VARCHAR(50) NOT NULL DEFAULT 'manual', `source_id` INT NULL DEFAULT NULL,
          `status` ENUM('draft','posted','voided') NOT NULL DEFAULT 'posted',
          `created_by` VARCHAR(100) NOT NULL DEFAULT '', `approved_by` VARCHAR(100) NULL DEFAULT NULL,
          `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`), KEY `idx_date` (`entry_date`), KEY `idx_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        $conn->query("CREATE TABLE IF NOT EXISTS `accounting_journal_items` (
          `id` INT NOT NULL AUTO_INCREMENT, `entry_id` INT NOT NULL, `account_id` INT NOT NULL,
          `debit` DECIMAL(15,4) NOT NULL DEFAULT 0.0000, `credit` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
          `currency_id` INT NULL DEFAULT NULL, `exchange_rate` DECIMAL(15,4) NOT NULL DEFAULT 1.0000,
          `memo` VARCHAR(255) NOT NULL DEFAULT '', `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`), KEY `idx_entry_id` (`entry_id`), KEY `idx_account_id` (`account_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        $conn->query("CREATE TABLE IF NOT EXISTS `accounting_vouchers` (
          `id` INT NOT NULL AUTO_INCREMENT, `voucher_type` ENUM('receipt','payment') NOT NULL,
          `voucher_no` VARCHAR(50) NOT NULL DEFAULT '', `voucher_date` DATE NOT NULL,
          `party_type` ENUM('customer','supplier','other') NOT NULL DEFAULT 'other',
          `party_id` INT NULL DEFAULT NULL, `party_name` VARCHAR(150) NOT NULL DEFAULT '',
          `cash_account_id` INT NOT NULL, `contra_account_id` INT NOT NULL,
          `amount` DECIMAL(15,4) NOT NULL DEFAULT 0.0000, `currency_id` INT NULL DEFAULT NULL,
          `exchange_rate` DECIMAL(15,4) NOT NULL DEFAULT 1.0000, `description` VARCHAR(500) NOT NULL DEFAULT '',
          `status` ENUM('draft','posted','voided') NOT NULL DEFAULT 'posted',
          `journal_entry_id` INT NULL DEFAULT NULL, `created_by` VARCHAR(100) NOT NULL DEFAULT '',
          `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`), KEY `idx_voucher_date` (`voucher_date`), KEY `idx_voucher_type` (`voucher_type`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        $conn->query("CREATE TABLE IF NOT EXISTS `accounting_journal_audit` (
          `id` INT NOT NULL AUTO_INCREMENT, `entry_id` INT NOT NULL,
          `action` ENUM('create','edit','void','approve') NOT NULL,
          `changed_by` VARCHAR(100) NOT NULL DEFAULT '', `changed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          `old_data` TEXT NULL, `new_data` TEXT NULL, `reason` VARCHAR(500) NOT NULL DEFAULT '',
          PRIMARY KEY (`id`), KEY `idx_audit_entry` (`entry_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        $conn->query("CREATE TABLE IF NOT EXISTS `accounting_sequences` (
          `seq_key` VARCHAR(50) NOT NULL, `last_no` INT NOT NULL DEFAULT 0,
          PRIMARY KEY (`seq_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
        $conn->query("INSERT IGNORE INTO `accounting_sequences` (`seq_key`,`last_no`) VALUES ('receipt',0),('payment',0),('journal',0)");

        $conn->query("CREATE TABLE IF NOT EXISTS `treasury_closings` (
          `id` INT NOT NULL AUTO_INCREMENT, `box_id` INT NOT NULL, `close_date` DATE NOT NULL,
          `expected_balance` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
          `actual_balance` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
          `difference` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
          `transferred_amount` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
          `user` VARCHAR(100) NOT NULL DEFAULT '', `notes` TEXT NULL,
          `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`), KEY `idx_box_date` (`box_id`, `close_date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        // Add missing columns if needed
        $conn->query("ALTER TABLE `accounting_accounts` ADD COLUMN IF NOT EXISTS `is_reconcilable` TINYINT(1) NOT NULL DEFAULT 1");
        $conn->query("ALTER TABLE `accounting_accounts` ADD COLUMN IF NOT EXISTS `is_active` TINYINT(1) NOT NULL DEFAULT 1");
        $conn->query("UPDATE `accounting_accounts` SET `is_active`=1 WHERE `is_active` IS NULL OR `is_active`=0");
    }
}
?>
