<?php
/**
 * AJAX Handler: Double-Entry Journal Entry
 * POST /ajax/accounting_journal_entry.php
 */
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');

require_once(__DIR__ . '/../includes/connect.php');
require_once(__DIR__ . '/../app/Services/Accounting/JournalService.php');
require_once(__DIR__ . '/../app/Services/Accounting/AccountTreeService.php');

use AQNEX\Services\Accounting\JournalService;

if (empty($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'غير مصرح — يرجى تسجيل الدخول']);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {
    switch ($action) {

        // ----- Save new journal entry -----
        case 'save':
            $items_raw = $_POST['items'] ?? [];
            if (!is_array($items_raw) || count($items_raw) < 2) {
                throw new \Exception('يجب إدخال سطرين على الأقل في القيد');
            }

            $items = [];
            foreach ($items_raw as $row) {
                $accountId = intval($row['account_id'] ?? 0);
                $debit     = (float)str_replace(',', '', $row['debit']  ?? 0);
                $credit    = (float)str_replace(',', '', $row['credit'] ?? 0);
                if ($accountId <= 0) continue;
                if ($debit == 0 && $credit == 0) continue;

                $items[] = [
                    'account_id'    => $accountId,
                    'debit'         => $debit,
                    'credit'        => $credit,
                    'currency_id'   => !empty($row['currency_id']) ? intval($row['currency_id']) : null,
                    'exchange_rate' => (float)($row['exchange_rate'] ?? 1.0),
                    'memo'          => trim($row['memo'] ?? ''),
                ];
            }

            $result = JournalService::postEntry($conn, [
                'entry_date'   => $_POST['entry_date']   ?? date('Y-m-d'),
                'reference_no' => trim($_POST['reference_no'] ?? ''),
                'description'  => trim($_POST['description'] ?? ''),
                'source_type'  => 'manual',
                'source_id'    => null,
                'created_by'   => $_SESSION['username'] ?? 'system',
                'items'        => $items,
            ]);

            echo json_encode($result);
            break;

        // ----- Get single entry details -----
        case 'get':
            $id = intval($_GET['id'] ?? 0);
            if ($id <= 0) throw new \Exception('معرّف غير صالح');
            $entry = JournalService::getEntry($conn, $id);
            if (!$entry) throw new \Exception('القيد غير موجود');
            echo json_encode(['success' => true, 'entry' => $entry]);
            break;

        // ----- List entries -----
        case 'list':
            $entries = JournalService::listEntries($conn, [
                'from_date'   => $_GET['from_date']   ?? null,
                'to_date'     => $_GET['to_date']     ?? null,
                'source_type' => $_GET['source_type'] ?? null,
                'status'      => $_GET['status']      ?? null,
            ], 200);
            echo json_encode(['success' => true, 'entries' => $entries]);
            break;

        // ----- Void (Support Mode) -----
        case 'void':
            $id     = intval($_POST['id'] ?? 0);
            $reason = trim($_POST['reason'] ?? '');
            if ($id <= 0)       throw new \Exception('معرّف غير صالح');
            if (empty($reason)) throw new \Exception('يجب إدخال سبب الإلغاء');

            $result = JournalService::voidEntry($conn, $id, $reason, $_SESSION['username'] ?? 'system');
            echo json_encode($result);
            break;

        default:
            throw new \Exception("إجراء غير معروف: $action");
    }
} catch (\Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
