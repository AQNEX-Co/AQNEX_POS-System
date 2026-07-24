<?php
namespace AQNEX\Services\Accounting;

use AQNEX\Services\Accounting\JournalService;

/**
 * VoucherService
 * Creates Receipt (سند قبض) and Payment (سند صرف) vouchers.
 * Each voucher automatically posts a balanced journal entry.
 */
class VoucherService
{
    /**
     * Create a Receipt Voucher (سند قبض).
     * Dr. Cash/Bank Account  ←  Cr. Contra Account
     *
     * @param array $data {
     *   voucher_date, party_type, party_id, party_name,
     *   cash_account_id, contra_account_id, amount,
     *   currency_id, exchange_rate, description, created_by
     * }
     * @return array ['success'=>bool, 'voucher_id'=>int|null, 'entry_id'=>int|null, 'error'=>string]
     */
    public static function createReceiptVoucher(\mysqli $conn, array $data): array
    {
        return self::createVoucher($conn, 'receipt', $data);
    }

    /**
     * Create a Payment Voucher (سند صرف).
     * Dr. Expense/Contra Account  ←  Cr. Cash/Bank Account
     *
     * @param array $data Same structure as createReceiptVoucher
     * @return array ['success'=>bool, 'voucher_id'=>int|null, 'entry_id'=>int|null, 'error'=>string]
     */
    public static function createPaymentVoucher(\mysqli $conn, array $data): array
    {
        return self::createVoucher($conn, 'payment', $data);
    }

    /**
     * Get a single voucher with its journal entry info.
     */
    public static function getVoucher(\mysqli $conn, int $id): ?array
    {
        $stmt = $conn->prepare("
            SELECT v.*,
                   ca.name AS cash_account_name, ca.code AS cash_account_code,
                   COALESCE(xa.name, '') AS contra_account_name, COALESCE(xa.code, '') AS contra_account_code
            FROM accounting_vouchers v
            JOIN accounting_accounts ca ON ca.id = v.cash_account_id
            LEFT JOIN accounting_accounts xa ON xa.id = v.contra_account_id
            WHERE v.id = ?
            LIMIT 1
        ");
        if (!$stmt) return null;
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res->fetch_assoc();
        $stmt->close();
        if (!$row) return null;

        // Fetch child items
        $row['items'] = [];
        $stmt2 = $conn->prepare("
            SELECT vi.*, a.name AS account_name, a.code AS account_code
            FROM accounting_voucher_items vi
            JOIN accounting_accounts a ON a.id = vi.account_id
            WHERE vi.voucher_id = ?
        ");
        if ($stmt2) {
            $stmt2->bind_param('i', $id);
            $stmt2->execute();
            $res2 = $stmt2->get_result();
            while ($item = $res2->fetch_assoc()) {
                $row['items'][] = $item;
            }
            $stmt2->close();
        }

        return $row;
    }

    /**
     * List vouchers with optional filters.
     * @param array $filters {type?, from_date?, to_date?, party_type?, party_id?, status?}
     */
    public static function listVouchers(\mysqli $conn, array $filters = [], int $limit = 100, int $offset = 0): array
    {
        $where = ['1=1'];
        if (!empty($filters['type']))       $where[] = "v.voucher_type = '" . $conn->real_escape_string($filters['type']) . "'";
        if (!empty($filters['from_date']))  $where[] = "v.voucher_date >= '" . $conn->real_escape_string($filters['from_date']) . "'";
        if (!empty($filters['to_date']))    $where[] = "v.voucher_date <= '" . $conn->real_escape_string($filters['to_date']) . "'";
        if (!empty($filters['party_type'])) $where[] = "v.party_type = '" . $conn->real_escape_string($filters['party_type']) . "'";
        if (!empty($filters['party_id']))   $where[] = "v.party_id = " . intval($filters['party_id']);
        if (!empty($filters['status']))     $where[] = "v.status = '" . $conn->real_escape_string($filters['status']) . "'";

        $whereStr = implode(' AND ', $where);
        $sql = "
            SELECT v.*,
                   ca.name AS cash_account_name,
                   xa.name AS contra_account_name
            FROM accounting_vouchers v
            JOIN accounting_accounts ca ON ca.id = v.cash_account_id
            JOIN accounting_accounts xa ON xa.id = v.contra_account_id
            WHERE $whereStr
            ORDER BY v.voucher_date DESC, v.id DESC
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
     * Void a voucher (and its linked journal entry).
     */
    public static function voidVoucher(\mysqli $conn, int $id, string $reason, string $by): array
    {
        $conn->begin_transaction();
        try {
            $vou = self::getVoucher($conn, $id);
            if (!$vou) throw new \Exception("السند غير موجود");
            if ($vou['status'] === 'voided') throw new \Exception("السند ملغى مسبقاً");

            $conn->query("UPDATE accounting_vouchers SET status='voided' WHERE id=$id");

            if ($vou['journal_entry_id']) {
                $entryId = (int)$vou['journal_entry_id'];
                $conn->query("UPDATE accounting_journal_entries SET status='voided' WHERE id=$entryId");

                // Audit log
                $reason_esc = $conn->real_escape_string($reason);
                $by_esc     = $conn->real_escape_string($by);
                $conn->query("INSERT INTO accounting_journal_audit (entry_id, action, changed_by, reason)
                              VALUES ($entryId, 'void', '$by_esc', '$reason_esc')");
            }

            // Resolve treasury box ID
            $box_id = 0;
            $cashAccId = intval($vou['cash_account_id']);
            $acc_res = $conn->query("SELECT name FROM accounting_accounts WHERE id = $cashAccId LIMIT 1");
            if ($acc_res && $acc_row = $acc_res->fetch_assoc()) {
                $acc_name = $acc_row['name'];
                if (strpos($acc_name, 'الصندوق - ') === 0) {
                    $box_name = str_replace('الصندوق - ', '', $acc_name);
                    $box_res = $conn->query("SELECT box_id FROM treasury WHERE name = '" . $conn->real_escape_string($box_name) . "' LIMIT 1");
                    if ($box_res && $box_row = $box_res->fetch_assoc()) {
                        $box_id = intval($box_row['box_id']);
                    }
                }
            }

            // Reverse treasury box balance and mark legacy transaction deleted
            if ($box_id > 0) {
                $total_amount = (float)$vou['amount'];
                $voucherNo = $vou['voucher_no'];
                $rev_type = ($vou['voucher_type'] === 'receipt') ? 'discount' : 'addition';
                $rev_remark = "إلغاء سند " . ($vou['voucher_type'] === 'receipt' ? 'قبض' : 'صرف') . " رقم $voucherNo";
                
                // We use helper function if exists, or update directly
                if (function_exists('update_box_balance')) {
                    update_box_balance($conn, $box_id, $total_amount, $rev_type, $rev_remark, date('Y-m-d'));
                } else {
                    // Fallback
                    if ($rev_type === 'addition') {
                        $conn->query("UPDATE treasury SET mony = mony + $total_amount WHERE box_id = $box_id");
                    } else {
                        $conn->query("UPDATE treasury SET mony = mony - $total_amount WHERE box_id = $box_id");
                    }
                    $rev_remark_esc = $conn->real_escape_string($rev_remark);
                    $conn->query("INSERT INTO treasury_transactions (mony, statue, remark, datte, box_id) 
                                  VALUES ($total_amount, '$rev_type', '$rev_remark_esc', '" . date('Y-m-d') . "', $box_id)");
                }

                // Update legacy transaction status to s = 1 (deleted)
                if ($vou['voucher_type'] === 'receipt') {
                    $conn->query("UPDATE receipts SET s = 1 WHERE voucher_id = $id");
                } else {
                    $conn->query("UPDATE treasury_expenses SET s = 1 WHERE voucher_id = $id");
                }
            }

            // Delete legacy journal entries
            $conn->query("DELETE FROM journal_entries WHERE ref_type = 'voucher' AND ref_id = $id");
            $conn->query("DELETE FROM accounting_journal WHERE ref_type = 'voucher' AND ref_id = $id");

            $conn->commit();
            return ['success' => true];
        } catch (\Exception $e) {
            $conn->rollback();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Delete a voucher completely (and its linked journal entry, items, and reverse box balance).
     */
    public static function deleteVoucher(\mysqli $conn, int $id): array
    {
        $conn->begin_transaction();
        try {
            $vou = self::getVoucher($conn, $id);
            if (!$vou) throw new \Exception("السند غير موجود");

            // Reverse treasury box balance if the voucher was posted
            if ($vou['status'] === 'posted') {
                $box_id = 0;
                $cashAccId = intval($vou['cash_account_id']);
                $acc_res = $conn->query("SELECT name FROM accounting_accounts WHERE id = $cashAccId LIMIT 1");
                if ($acc_res && $acc_row = $acc_res->fetch_assoc()) {
                    $acc_name = $acc_row['name'];
                    if (strpos($acc_name, 'الصندوق - ') === 0) {
                        $box_name = str_replace('الصندوق - ', '', $acc_name);
                        $box_res = $conn->query("SELECT box_id FROM treasury WHERE name = '" . $conn->real_escape_string($box_name) . "' LIMIT 1");
                        if ($box_res && $box_row = $box_res->fetch_assoc()) {
                            $box_id = intval($box_row['box_id']);
                        }
                    }
                }

                if ($box_id > 0) {
                    $total_amount = (float)$vou['amount'];
                    $voucherNo = $vou['voucher_no'];
                    $rev_type = ($vou['voucher_type'] === 'receipt') ? 'discount' : 'addition';
                    $rev_remark = "حذف سند " . ($vou['voucher_type'] === 'receipt' ? 'قبض' : 'صرف') . " رقم $voucherNo";
                    
                    if (function_exists('update_box_balance')) {
                        update_box_balance($conn, $box_id, $total_amount, $rev_type, $rev_remark, date('Y-m-d'));
                    } else {
                        if ($rev_type === 'addition') {
                            $conn->query("UPDATE treasury SET mony = mony + $total_amount WHERE box_id = $box_id");
                        } else {
                            $conn->query("UPDATE treasury SET mony = mony - $total_amount WHERE box_id = $box_id");
                        }
                        $rev_remark_esc = $conn->real_escape_string($rev_remark);
                        $conn->query("INSERT INTO treasury_transactions (mony, statue, remark, datte, box_id) 
                                      VALUES ($total_amount, '$rev_type', '$rev_remark_esc', '" . date('Y-m-d') . "', $box_id)");
                    }
                }
            }

            // Delete legacy receipts/expenses completely
            if ($vou['voucher_type'] === 'receipt') {
                $conn->query("DELETE FROM receipts WHERE voucher_id = $id");
            } else {
                $conn->query("DELETE FROM treasury_expenses WHERE voucher_id = $id");
            }

            // Delete legacy journal entries
            $conn->query("DELETE FROM journal_entries WHERE ref_type = 'voucher' AND ref_id = $id");
            $conn->query("DELETE FROM accounting_journal WHERE ref_type = 'voucher' AND ref_id = $id");

            // Delete general ledger journal entry and items
            if ($vou['journal_entry_id']) {
                $entryId = (int)$vou['journal_entry_id'];
                $conn->query("DELETE FROM accounting_journal_items WHERE entry_id = $entryId");
                $conn->query("DELETE FROM accounting_journal_entries WHERE id = $entryId");
            }

            // Delete voucher record (will cascade delete items)
            $conn->query("DELETE FROM accounting_vouchers WHERE id = $id");

            $conn->commit();
            return ['success' => true];
        } catch (\Exception $e) {
            $conn->rollback();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // -----------------------------------------------------------------------
    // Private
    // -----------------------------------------------------------------------

    private static function createVoucher(\mysqli $conn, string $type, array $data): array
    {
        $conn->begin_transaction();
        try {
            $items = $data['items'] ?? [];
            if (empty($items)) {
                // If single item structure is passed (for backward compatibility), convert to items array
                $contraAccId = (int)($data['contra_account_id'] ?? 0);
                $amount = (float)($data['amount'] ?? 0);
                if ($contraAccId > 0 && $amount > 0) {
                    $items = [
                        [
                            'account_id' => $contraAccId,
                            'amount' => $amount,
                            'memo' => $data['description'] ?? '',
                        ]
                    ];
                }
            }

            if (empty($items)) throw new \Exception("يجب إضافة بند واحد على الأقل في السند");

            // Calculate total amount
            $total_amount = 0.0;
            foreach ($items as $item) {
                $total_amount += (float)($item['amount'] ?? 0);
                if (intval($item['account_id'] ?? 0) <= 0) {
                    throw new \Exception("يجب اختيار حساب صحيح في كل بند");
                }
                if (floatval($item['amount'] ?? 0) <= 0) {
                    throw new \Exception("المبلغ في كل بند يجب أن يكون أكبر من صفر");
                }
            }

            if ($total_amount <= 0) throw new \Exception("المبلغ الإجمالي يجب أن يكون أكبر من صفر");

            $cashAccId = (int)($data['cash_account_id'] ?? 0);
            if (!$cashAccId) throw new \Exception("يجب تحديد حساب الصندوق أو البنك");

            // Generate voucher number
            $voucherNo = self::nextVoucherNo($conn, $type);
            $voucherDate = $data['voucher_date'] ?? date('Y-m-d');
            $description = trim($data['description'] ?? '');

            // First item's account ID as contra_account_id for schema constraints
            $firstContraAccId = intval($items[0]['account_id']);

            // Insert voucher record
            $type_esc      = $conn->real_escape_string($type);
            $no_esc        = $conn->real_escape_string($voucherNo);
            $date_esc      = $conn->real_escape_string($voucherDate);
            $ptype_esc     = $conn->real_escape_string($data['party_type'] ?? 'other');
            $pname_esc     = $conn->real_escape_string($data['party_name'] ?? '');
            $desc_esc      = $conn->real_escape_string($description);
            $by_esc        = $conn->real_escape_string($data['created_by'] ?? '');
            $party_id_val  = !empty($data['party_id']) ? intval($data['party_id']) : 'NULL';
            $cur_val       = !empty($data['currency_id']) ? intval($data['currency_id']) : 'NULL';
            $exch          = (float)($data['exchange_rate'] ?? 1.0);

            $sql = "INSERT INTO accounting_vouchers
                    (voucher_type, voucher_no, voucher_date, party_type, party_id, party_name,
                     cash_account_id, contra_account_id, amount, currency_id, exchange_rate,
                     description, status, journal_entry_id, created_by)
                    VALUES
                    ('$type_esc', '$no_esc', '$date_esc', '$ptype_esc', $party_id_val, '$pname_esc',
                     $cashAccId, $firstContraAccId, $total_amount, $cur_val, $exch,
                     '$desc_esc', 'posted', NULL, '$by_esc')";

            if (!$conn->query($sql)) throw new \Exception("خطأ في حفظ السند: " . $conn->error);
            $voucherId = $conn->insert_id;

            // Insert voucher items
            foreach ($items as $item) {
                $item_acc_id = intval($item['account_id']);
                $item_amt = (float)$item['amount'];
                $item_memo = $conn->real_escape_string($item['memo'] ?? '');
                
                $sql_item = "INSERT INTO accounting_voucher_items (voucher_id, account_id, amount, memo)
                             VALUES ($voucherId, $item_acc_id, $item_amt, '$item_memo')";
                if (!$conn->query($sql_item)) throw new \Exception("خطأ في حفظ بند السند: " . $conn->error);
            }

            // Gather items for journal posting
            $journalItems = [];

            // Cash Account line item
            $journalItems[] = [
                'account_id'    => $cashAccId,
                'debit'         => ($type === 'receipt') ? $total_amount : 0.0,
                'credit'        => ($type === 'payment') ? $total_amount : 0.0,
                'currency_id'   => $data['currency_id'] ?? null,
                'exchange_rate' => $data['exchange_rate'] ?? 1.0,
                'memo'          => $description,
            ];

            // Detailed split items
            foreach ($items as $item) {
                $journalItems[] = [
                    'account_id'    => intval($item['account_id']),
                    'debit'         => ($type === 'payment') ? (float)$item['amount'] : 0.0,
                    'credit'        => ($type === 'receipt') ? (float)$item['amount'] : 0.0,
                    'currency_id'   => $data['currency_id'] ?? null,
                    'exchange_rate' => $data['exchange_rate'] ?? 1.0,
                    'memo'          => $item['memo'] ?? $description,
                ];
            }

            // Post double-entry journal (New general ledger)
            $entryDesc = ($type === 'receipt' ? "سند قبض" : "سند صرف") . " رقم $voucherNo - " . $description;
            $entryResult = JournalService::postEntry($conn, [
                'entry_date'   => $voucherDate,
                'reference_no' => $voucherNo,
                'description'  => $entryDesc,
                'source_type'  => 'voucher',
                'source_id'    => $voucherId,
                'created_by'   => $data['created_by'] ?? '',
                'items'        => $journalItems,
            ]);

            if (!$entryResult['success']) throw new \Exception($entryResult['error']);
            $entryId = $entryResult['entry_id'];

            // Link journal entry back to voucher
            $conn->query("UPDATE accounting_vouchers SET journal_entry_id=$entryId WHERE id=$voucherId");

            // Keep supplier debt untouched for voucher-based payments/receipts.
            // Debt movement is handled by purchase and settlement flows elsewhere.

            // Look up cash account name
            $cash_acc_name = '';
            $acc_res = $conn->query("SELECT name FROM accounting_accounts WHERE id = $cashAccId LIMIT 1");
            if ($acc_res && $acc_row = $acc_res->fetch_assoc()) {
                $cash_acc_name = $acc_row['name'];
            }

            // Post legacy journal entries (for balances compatibility)
            foreach ($items as $item) {
                $item_acc_id = intval($item['account_id']);
                $item_amt = (float)$item['amount'];
                $item_memo = $item['memo'] ?? $description;

                // Look up contra account name
                $contra_acc_name = '';
                $c_res = $conn->query("SELECT name FROM accounting_accounts WHERE id = $item_acc_id LIMIT 1");
                if ($c_res && $c_row = $c_res->fetch_assoc()) {
                    $contra_acc_name = $c_row['name'];
                }

                // Resolve entities (customer, supplier, fund)
                $debit_acc = ($type === 'receipt') ? $cash_acc_name : $contra_acc_name;
                $credit_acc = ($type === 'receipt') ? $contra_acc_name : $cash_acc_name;

                $debit_entity_type = 'general';
                $debit_entity_id = null;
                $credit_entity_type = 'general';
                $credit_entity_id = null;

                // Replicate entity resolution logic
                // Debit side
                if (strpos($debit_acc, 'الذمم المدينة - ') === 0) {
                    $cname = str_replace('الذمم المدينة - ', '', $debit_acc);
                    $cid = self::lookupCustomerId($conn, $cname);
                    if ($cid > 0) { $debit_entity_type = 'customer'; $debit_entity_id = $cid; }
                } elseif (strpos($debit_acc, 'الذمم الدائنة - ') === 0) {
                    $sname = str_replace('الذمم الدائنة - ', '', $debit_acc);
                    $sid = self::lookupSupplierId($conn, $sname);
                    if ($sid > 0) { $debit_entity_type = 'supplier'; $debit_entity_id = $sid; }
                } elseif (strpos($debit_acc, 'الصندوق - ') === 0) {
                    $bname = str_replace('الصندوق - ', '', $debit_acc);
                    $bid = self::lookupBoxId($conn, $bname);
                    if ($bid > 0) { $debit_entity_type = 'fund'; $debit_entity_id = $bid; }
                }

                // Credit side
                if (strpos($credit_acc, 'الذمم المدينة - ') === 0) {
                    $cname = str_replace('الذمم المدينة - ', '', $credit_acc);
                    $cid = self::lookupCustomerId($conn, $cname);
                    if ($cid > 0) { $credit_entity_type = 'customer'; $credit_entity_id = $cid; }
                } elseif (strpos($credit_acc, 'الذمم الدائنة - ') === 0) {
                    $sname = str_replace('الذمم الدائنة - ', '', $credit_acc);
                    $sid = self::lookupSupplierId($conn, $sname);
                    if ($sid > 0) { $credit_entity_type = 'supplier'; $credit_entity_id = $sid; }
                } elseif (strpos($credit_acc, 'الصندوق - ') === 0) {
                    $bname = str_replace('الصندوق - ', '', $credit_acc);
                    $bid = self::lookupBoxId($conn, $bname);
                    if ($bid > 0) { $credit_entity_type = 'fund'; $credit_entity_id = $bid; }
                }

                $box_val = 'NULL';
                if ($debit_entity_type === 'fund') $box_val = intval($debit_entity_id);
                elseif ($credit_entity_type === 'fund') $box_val = intval($credit_entity_id);

                $debit_acc_esc = $conn->real_escape_string($debit_acc);
                $credit_acc_esc = $conn->real_escape_string($credit_acc);
                $item_memo_esc = $conn->real_escape_string($item_memo);
                $currency_esc = !empty($data['currency_id']) ? 'USD' : 'YER';
                $exchange_rate = (float)($data['exchange_rate'] ?? 1.0);
                $amount_foreign = $item_amt / ($exchange_rate > 0 ? $exchange_rate : 1.0);
                
                $debit_id_sql = ($debit_entity_id === null) ? 'NULL' : intval($debit_entity_id);
                $credit_id_sql = ($credit_entity_id === null) ? 'NULL' : intval($credit_entity_id);

                // Insert into journal_entries
                $conn->query("INSERT INTO journal_entries 
                        (ref_type, ref_id, account_debit, account_credit, debit_entity_type, debit_entity_id, credit_entity_type, credit_entity_id, amount, description, currency_code, exchange_rate, amount_foreign, user, box_id) 
                        VALUES 
                        ('voucher', $voucherId, '$debit_acc_esc', '$credit_acc_esc', '$debit_entity_type', $debit_id_sql, '$credit_entity_type', $credit_id_sql, $item_amt, '$item_memo_esc', '$currency_esc', $exchange_rate, $amount_foreign, '$by_esc', $box_val)");

                // Insert into accounting_journal
                $conn->query("INSERT INTO accounting_journal 
                        (ref_type, ref_id, account_debit, account_credit, amount, description, currency_code, exchange_rate, amount_foreign, user, box_id) 
                        VALUES 
                        ('voucher', $voucherId, '$debit_acc_esc', '$credit_acc_esc', $item_amt, '$item_memo_esc', '$currency_esc', $exchange_rate, $amount_foreign, '$by_esc', $box_val)");
            }

            // Treasury Box balance update and legacy receipts/expenses insert
            $box_id = 0;
            if (strpos($cash_acc_name, 'الصندوق - ') === 0) {
                $box_name = str_replace('الصندوق - ', '', $cash_acc_name);
                $box_res = $conn->query("SELECT box_id FROM treasury WHERE name = '" . $conn->real_escape_string($box_name) . "' LIMIT 1");
                if ($box_res && $box_row = $box_res->fetch_assoc()) {
                    $box_id = intval($box_row['box_id']);
                }
            }

            if ($box_id > 0) {
                $txt_type = ($type === 'receipt') ? 'addition' : 'discount';
                $tx_remark = ($type === 'receipt' ? "سند قبض رقم " : "سند صرف رقم ") . "$voucherNo - $description";

                // Update box balance
                if (function_exists('update_box_balance')) {
                    update_box_balance($conn, $box_id, $total_amount, $txt_type, $tx_remark, $voucherDate);
                } else {
                    if ($txt_type === 'addition') {
                        $conn->query("UPDATE treasury SET mony = mony + $total_amount WHERE box_id = $box_id");
                    } else {
                        $conn->query("UPDATE treasury SET mony = mony - $total_amount WHERE box_id = $box_id");
                    }
                    $tx_remark_esc = $conn->real_escape_string($tx_remark);
                    $conn->query("INSERT INTO treasury_transactions (mony, statue, remark, datte, box_id) 
                                  VALUES ($total_amount, '$txt_type', '$tx_remark_esc', '$date_esc', $box_id)");
                }

                // Insert legacy summary record
                if ($type === 'receipt') {
                    $conn->query("INSERT INTO receipts (cust_name, q_price, remark, q_date, total, s, box_id, voucher_id)
                                  VALUES ('$pname_esc', $total_amount, '$desc_esc', '$date_esc', $total_amount, 0, $box_id, $voucherId)");
                } else {
                    $first_exp_type = 'حسابات - صرف';
                    if (strpos($contra_acc_name, 'مصروفات - ') === 0) {
                        $first_exp_type = str_replace('مصروفات - ', '', $contra_acc_name);
                    }
                    $first_exp_type_esc = $conn->real_escape_string($first_exp_type);
                    $conn->query("INSERT INTO treasury_expenses (st, sname, sdate, sprice, sremark, tot, s, box_id, voucher_id)
                                  VALUES ('$first_exp_type_esc', '$pname_esc', '$date_esc', $total_amount, '$desc_esc', $total_amount, 0, $box_id, $voucherId)");
                }
            }

            $conn->commit();
            return ['success' => true, 'voucher_id' => $voucherId, 'entry_id' => $entryId, 'voucher_no' => $voucherNo];

        } catch (\Exception $e) {
            $conn->rollback();
            return ['success' => false, 'voucher_id' => null, 'entry_id' => null, 'error' => $e->getMessage()];
        }
    }

    private static function nextVoucherNo(\mysqli $conn, string $type): string
    {
        $conn->query("UPDATE accounting_sequences SET last_no = last_no + 1 WHERE seq_key = '$type'");
        $res = $conn->query("SELECT last_no FROM accounting_sequences WHERE seq_key = '$type' LIMIT 1");
        $no  = $res ? (int)$res->fetch_row()[0] : 1;
        $prefix = $type === 'receipt' ? 'RV' : 'PV';
        return $prefix . '-' . date('Y') . '-' . str_pad($no, 5, '0', STR_PAD_LEFT);
    }

    private static function lookupCustomerId(\mysqli $conn, string $name): int
    {
        $name_esc = $conn->real_escape_string($name);
        $res = $conn->query("SELECT cust_id FROM customers WHERE cust_name = '$name_esc' LIMIT 1");
        return ($res && $row = $res->fetch_assoc()) ? intval($row['cust_id']) : 0;
    }

    private static function lookupSupplierId(\mysqli $conn, string $name): int
    {
        $name_esc = $conn->real_escape_string($name);
        $res = $conn->query("SELECT supp_id FROM suppliers WHERE supp_name = '$name_esc' LIMIT 1");
        return ($res && $row = $res->fetch_assoc()) ? intval($row['supp_id']) : 0;
    }

    private static function lookupBoxId(\mysqli $conn, string $name): int
    {
        $name_esc = $conn->real_escape_string($name);
        $res = $conn->query("SELECT box_id FROM treasury WHERE name = '$name_esc' LIMIT 1");
        return ($res && $row = $res->fetch_assoc()) ? intval($row['box_id']) : 0;
    }
}
