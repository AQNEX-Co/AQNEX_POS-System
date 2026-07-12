<?php
namespace AQNEX\Services\Accounting;

/**
 * JournalService
 * Core double-entry engine. Every financial transaction MUST pass through this class.
 *
 * MANDATORY RULE: Sum(Debits) MUST equal Sum(Credits) or the transaction fails.
 * All operations are wrapped in MySQL Transactions for atomicity.
 * All amounts stored as DECIMAL(15,4).
 */
class JournalService
{
    /**
     * Post a complete double-entry journal entry.
     *
     * @param array $data {
     *   entry_date:   string (Y-m-d),
     *   reference_no: string,
     *   description:  string,
     *   source_type:  string ('manual'|'sale'|'purchase'|'voucher'|'expense'|'receipt'),
     *   source_id:    int|null,
     *   created_by:   string,
     *   items: [
     *     { account_id: int, debit: float, credit: float, currency_id: int|null, exchange_rate: float, memo: string },
     *     ...
     *   ]
     * }
     * @return array ['success'=>bool, 'entry_id'=>int|null, 'error'=>string]
     */
    public static function postEntry(\mysqli $conn, array $data): array
    {
        // ---- 1. Validate items ----
        $items = $data['items'] ?? [];
        if (count($items) < 2) {
            return ['success' => false, 'entry_id' => null, 'error' => 'يجب أن يحتوي القيد على سطرين على الأقل (مدين ودائن)'];
        }

        $totalDebit  = 0.0;
        $totalCredit = 0.0;
        foreach ($items as $item) {
            $totalDebit  += (float)($item['debit']  ?? 0);
            $totalCredit += (float)($item['credit'] ?? 0);
            if ((int)($item['account_id'] ?? 0) <= 0) {
                return ['success' => false, 'entry_id' => null, 'error' => 'رقم حساب غير صالح في أحد بنود القيد'];
            }
        }

        // ---- 2. STRICT: Debit must equal Credit ----
        if (round($totalDebit, 4) !== round($totalCredit, 4)) {
            return [
                'success'  => false,
                'entry_id' => null,
                'error'    => sprintf(
                    'خطأ في التوازن: إجمالي المدين (%.4f) لا يساوي إجمالي الدائن (%.4f)',
                    $totalDebit, $totalCredit
                ),
            ];
        }

        if ($totalDebit <= 0) {
            return ['success' => false, 'entry_id' => null, 'error' => 'إجمالي القيد يجب أن يكون أكبر من صفر'];
        }

        // ---- 3. Atomic transaction ----
        $conn->begin_transaction();
        try {
            // Generate reference number if empty
            $refNo = $data['reference_no'] ?? '';
            if (empty($refNo)) {
                $refNo = self::nextJournalNo($conn);
            }

            $entryDate   = $conn->real_escape_string($data['entry_date']  ?? date('Y-m-d'));
            $refNo_esc   = $conn->real_escape_string($refNo);
            $desc_esc    = $conn->real_escape_string($data['description'] ?? '');
            $srcType_esc = $conn->real_escape_string($data['source_type'] ?? 'manual');
            $createdBy   = $conn->real_escape_string($data['created_by']  ?? '');
            $srcId       = !empty($data['source_id']) ? intval($data['source_id']) : 'NULL';

            // Insert header
            $sql = "INSERT INTO accounting_journal_entries
                    (entry_date, reference_no, description, source_type, source_id, status, created_by)
                    VALUES
                    ('$entryDate', '$refNo_esc', '$desc_esc', '$srcType_esc', $srcId, 'posted', '$createdBy')";

            if (!$conn->query($sql)) {
                throw new \Exception("خطأ في حفظ رأس القيد: " . $conn->error);
            }
            $entryId = $conn->insert_id;

            // Insert line items
            foreach ($items as $item) {
                $accountId   = intval($item['account_id']);
                $debit       = round((float)($item['debit']  ?? 0), 4);
                $credit      = round((float)($item['credit'] ?? 0), 4);
                $exchRate    = round((float)($item['exchange_rate'] ?? 1.0), 4);
                $memo_esc    = $conn->real_escape_string($item['memo'] ?? '');
                $curId       = !empty($item['currency_id']) ? intval($item['currency_id']) : 'NULL';

                // Verify account exists and is a ledger (non-parent) account
                $chk = $conn->query("SELECT id, is_parent FROM accounting_accounts WHERE id=$accountId AND is_active=1 LIMIT 1");
                if (!$chk || $chk->num_rows === 0) {
                    throw new \Exception("الحساب رقم $accountId غير موجود أو غير نشط");
                }
                $chkRow = $chk->fetch_assoc();
                if ($chkRow['is_parent']) {
                    throw new \Exception("لا يمكن الترحيل على حساب أب (مجمّع). يجب اختيار حساب تفصيلي");
                }

                $itemSql = "INSERT INTO accounting_journal_items
                            (entry_id, account_id, debit, credit, currency_id, exchange_rate, memo)
                            VALUES
                            ($entryId, $accountId, $debit, $credit, $curId, $exchRate, '$memo_esc')";

                if (!$conn->query($itemSql)) {
                    throw new \Exception("خطأ في حفظ بند القيد: " . $conn->error);
                }
            }

            // ---- 4. Audit: log creation ----
            $conn->query("INSERT INTO accounting_journal_audit (entry_id, action, changed_by)
                          VALUES ($entryId, 'create', '$createdBy')");

            $conn->commit();
            return ['success' => true, 'entry_id' => $entryId, 'reference_no' => $refNo];

        } catch (\Exception $e) {
            $conn->rollback();
            return ['success' => false, 'entry_id' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * Get a single journal entry with its items.
     */
    public static function getEntry(\mysqli $conn, int $id): ?array
    {
        $stmt = $conn->prepare("SELECT * FROM accounting_journal_entries WHERE id = ? LIMIT 1");
        if (!$stmt) return null;
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $entry = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$entry) return null;

        $stmt2 = $conn->prepare("
            SELECT ji.*, a.name AS account_name, a.code AS account_code, a.account_type
            FROM accounting_journal_items ji
            JOIN accounting_accounts a ON a.id = ji.account_id
            WHERE ji.entry_id = ?
            ORDER BY ji.id ASC
        ");
        $stmt2->bind_param('i', $id);
        $stmt2->execute();
        $res = $stmt2->get_result();
        $entry['items'] = [];
        while ($row = $res->fetch_assoc()) {
            $entry['items'][] = $row;
        }
        $stmt2->close();
        return $entry;
    }

    /**
     * List entries with optional filters.
     * @param array $filters {from_date?, to_date?, source_type?, reference_no?, created_by?, status?}
     */
    public static function listEntries(\mysqli $conn, array $filters = [], int $limit = 100, int $offset = 0): array
    {
        $where = ['1=1'];
        if (!empty($filters['from_date']))   $where[] = "je.entry_date >= '" . $conn->real_escape_string($filters['from_date']) . "'";
        if (!empty($filters['to_date']))     $where[] = "je.entry_date <= '" . $conn->real_escape_string($filters['to_date']) . "'";
        if (!empty($filters['source_type'])) $where[] = "je.source_type = '" . $conn->real_escape_string($filters['source_type']) . "'";
        if (!empty($filters['reference_no']))$where[] = "je.reference_no LIKE '%" . $conn->real_escape_string($filters['reference_no']) . "%'";
        if (!empty($filters['status']))      $where[] = "je.status = '" . $conn->real_escape_string($filters['status']) . "'";

        $whereStr = implode(' AND ', $where);
        $sql = "
            SELECT je.*,
                   COALESCE(SUM(ji.debit), 0) AS total_amount
            FROM accounting_journal_entries je
            LEFT JOIN accounting_journal_items ji ON ji.entry_id = je.id
            WHERE $whereStr
            GROUP BY je.id
            ORDER BY je.entry_date DESC, je.id DESC
            LIMIT $limit OFFSET $offset
        ";
        $res = $conn->query($sql);
        $rows = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) $rows[] = $row;
        }
        return $rows;
    }

    /**
     * Void an existing journal entry (Support Mode correction with audit trail).
     */
    public static function voidEntry(\mysqli $conn, int $id, string $reason, string $by): array
    {
        $conn->begin_transaction();
        try {
            $entry = self::getEntry($conn, $id);
            if (!$entry) throw new \Exception("القيد غير موجود");
            if ($entry['status'] === 'voided') throw new \Exception("القيد ملغى مسبقاً");

            $conn->query("UPDATE accounting_journal_entries SET status='voided' WHERE id=$id");

            // Audit log with old data
            $oldDataJson = $conn->real_escape_string(json_encode($entry, JSON_UNESCAPED_UNICODE));
            $reason_esc  = $conn->real_escape_string($reason);
            $by_esc      = $conn->real_escape_string($by);

            $conn->query("INSERT INTO accounting_journal_audit (entry_id, action, changed_by, old_data, reason)
                          VALUES ($id, 'void', '$by_esc', '$oldDataJson', '$reason_esc')");

            $conn->commit();
            return ['success' => true];
        } catch (\Exception $e) {
            $conn->rollback();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    private static function nextJournalNo(\mysqli $conn): string
    {
        $conn->query("UPDATE accounting_sequences SET last_no = last_no + 1 WHERE seq_key = 'journal'");
        $res = $conn->query("SELECT last_no FROM accounting_sequences WHERE seq_key = 'journal' LIMIT 1");
        $no  = $res ? (int)$res->fetch_row()[0] : 1;
        return 'JE-' . date('Y') . '-' . str_pad($no, 6, '0', STR_PAD_LEFT);
    }
}
