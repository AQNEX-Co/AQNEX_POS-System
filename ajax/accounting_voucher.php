<?php
/**
 * AJAX Handler: Vouchers (Receipt & Payment)
 * POST /ajax/accounting_voucher.php
 */
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');

require_once(__DIR__ . '/../includes/connect.php');
require_once(__DIR__ . '/../app/Services/Accounting/JournalService.php');
require_once(__DIR__ . '/../app/Services/Accounting/VoucherService.php');
require_once(__DIR__ . '/../app/Services/Accounting/AccountTreeService.php');

use AQNEX\Services\Accounting\VoucherService;

// ─── إنشاء الجداول المحاسبية تلقائياً إذا لم تكن موجودة ───
$conn->query("CREATE TABLE IF NOT EXISTS `accounting_journal_entries` (
  `id` INT NOT NULL AUTO_INCREMENT, `entry_date` DATE NOT NULL,
  `reference_no` VARCHAR(50) NOT NULL DEFAULT '', `description` VARCHAR(500) NOT NULL DEFAULT '',
  `source_type` VARCHAR(50) NOT NULL DEFAULT 'manual', `source_id` INT NULL DEFAULT NULL,
  `status` ENUM('draft','posted','voided') NOT NULL DEFAULT 'posted',
  `created_by` VARCHAR(100) NOT NULL DEFAULT '', `approved_by` VARCHAR(100) NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), KEY `idx_entry_date` (`entry_date`)
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

$conn->query("CREATE TABLE IF NOT EXISTS `accounting_voucher_items` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `voucher_id` INT NOT NULL,
  `account_id` INT NOT NULL,
  `amount` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  `memo` VARCHAR(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `idx_voucher_id` (`voucher_id`),
  CONSTRAINT `fk_vi_voucher` FOREIGN KEY (`voucher_id`) REFERENCES `accounting_vouchers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

$conn->query("ALTER TABLE `treasury_expenses` ADD COLUMN IF NOT EXISTS `voucher_id` INT NULL DEFAULT NULL");
$conn->query("ALTER TABLE `receipts` ADD COLUMN IF NOT EXISTS `voucher_id` INT NULL DEFAULT NULL");

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
$conn->query("INSERT IGNORE INTO `accounting_sequences` (`seq_key`, `last_no`) VALUES ('receipt',0),('payment',0),('journal',0)");

if (empty($_SESSION['SESS_MEMBER_ID'])) {
    echo json_encode(['success' => false, 'error' => 'غير مصرح — يرجى تسجيل الدخول']);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {
    switch ($action) {

        // ----- Create Receipt Voucher -----
        case 'create_receipt':
        case 'create_payment':
            $type   = ($action === 'create_receipt') ? 'receipt' : 'payment';
            $items_raw = $_POST['items'] ?? '[]';
            $items = json_decode($items_raw, true);
            if (!is_array($items)) {
                $items = [];
            }
            $amount = (float)str_replace(',', '', $_POST['amount'] ?? 0);

            $result = VoucherService::{'create' . ucfirst($type) . 'Voucher'}($conn, [
                'voucher_date'      => $_POST['voucher_date']      ?? date('Y-m-d'),
                'party_type'        => $_POST['party_type']        ?? 'other',
                'party_id'          => !empty($_POST['party_id'])  ? intval($_POST['party_id']) : null,
                'party_name'        => trim($_POST['party_name']   ?? ''),
                'cash_account_id'   => intval($_POST['cash_account_id']   ?? 0),
                'contra_account_id' => intval($_POST['contra_account_id'] ?? 0),
                'amount'            => $amount,
                'items'             => $items,
                'currency_id'       => !empty($_POST['currency_id']) ? intval($_POST['currency_id']) : null,
                'exchange_rate'     => (float)($_POST['exchange_rate'] ?? 1.0),
                'description'       => trim($_POST['description'] ?? ''),
                'created_by'        => $_SESSION['SESS_FIRST_NAME'] ?? $_SESSION['username'] ?? 'system',
            ]);
            echo json_encode($result);
            break;

        // ----- List Vouchers -----
        case 'list':
            $vouchers = VoucherService::listVouchers($conn, [
                'type'       => $_GET['type']       ?? null,
                'from_date'  => $_GET['from_date']  ?? null,
                'to_date'    => $_GET['to_date']    ?? null,
                'party_type' => $_GET['party_type'] ?? null,
                'status'     => $_GET['status']     ?? null,
            ], 200);
            echo json_encode(['success' => true, 'vouchers' => $vouchers]);
            break;

        // ----- Get Single Voucher -----
        case 'get':
            $id = intval($_GET['id'] ?? 0);
            if ($id <= 0) throw new \Exception('معرّف غير صالح');
            $voucher = VoucherService::getVoucher($conn, $id);
            if (!$voucher) throw new \Exception('السند غير موجود');
            echo json_encode(['success' => true, 'voucher' => $voucher]);
            break;

        // ----- Void Voucher -----
        case 'void':
            $id     = intval($_POST['id'] ?? 0);
            $reason = trim($_POST['reason'] ?? '');
            if ($id <= 0) throw new \Exception('معرّف غير صالح');
            $result = VoucherService::voidVoucher($conn, $id, $reason, $_SESSION['SESS_FIRST_NAME'] ?? 'system');
            echo json_encode($result);
            break;

        // ----- Delete Voucher -----
        case 'delete':
            $id = intval($_POST['id'] ?? $_GET['id'] ?? 0);
            if ($id <= 0) throw new \Exception('معرّف غير صالح');
            $result = VoucherService::deleteVoucher($conn, $id);
            echo json_encode($result);
            break;

        default:
            throw new \Exception("إجراء غير معروف: $action");
    }
} catch (\Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
