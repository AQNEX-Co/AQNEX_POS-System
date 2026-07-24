<?php
header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['SESS_MEMBER_ID'])) {
    echo json_encode(['success' => false, 'error' => 'غير مصرح']);
    exit;
}

require_once(__DIR__ . '/../includes/connect.php');

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$user_name = $_SESSION['SESS_FIRST_NAME'] ?? 'System';

// ==========================================
// 0. جلب رصيد المورد
// ==========================================
if ($action === 'get_supplier_balance' && isset($_GET['supplier_id'])) {
    $supp_id = intval($_GET['supplier_id']);
    $res = $conn->query("SELECT supp_name, supp_daain, supp_madeen FROM suppliers WHERE supp_id = $supp_id AND d_s = 0 LIMIT 1");
    if ($row = $res->fetch_assoc()) {
        echo json_encode([
            'success' => true,
            'name' => $row['supp_name'],
            'daain' => floatval($row['supp_daain']),
            'madeen' => floatval($row['supp_madeen'])
        ]);
    } else {
        echo json_encode(['success' => false, 'error' => 'المورد غير موجود']);
    }
    exit;
}

// ==========================================
// 1. إنشاء سند صرف (Payment Voucher)
// ==========================================
if ($action === 'create_payment') {
    $voucher_date = $conn->real_escape_string($_POST['voucher_date']);
    $party_type   = $conn->real_escape_string($_POST['party_type']);
    $party_id     = intval($_POST['party_id'] ?? 0);
    $party_name   = $conn->real_escape_string($_POST['party_name']);
    $cash_acc_id  = intval($_POST['cash_account_id']);
    $box_id       = intval($_POST['box_id'] ?? 0); // تم الاستلام
    $description  = $conn->real_escape_string($_POST['description']);
    $total_amount = floatval($_POST['amount']);
    $items        = json_decode($_POST['items'], true);

    if (empty($items) || $total_amount <= 0 || !$cash_acc_id) {
        echo json_encode(['success' => false, 'error' => 'بيانات السند غير مكتملة']);
        exit;
    }

    $conn->begin_transaction();
    try {
        // أ. التحقق الخلفي من رصيد الصندوق
        if ($box_id > 0) {
            $box_res = $conn->query("SELECT mony, name FROM treasury WHERE box_id = $box_id LIMIT 1");
            if ($box_res && $box_row = $box_res->fetch_assoc()) {
                if (floatval($box_row['mony']) <= 0) {
                    throw new Exception('لا يمكن الصرف من صندوق "' . $box_row['name'] . '" لأن رصيده صفر.');
                }
                if (floatval($box_row['mony']) < $total_amount) {
                    throw new Exception('رصيد الصندوق "' . $box_row['name'] . '" غير كافٍ. المتاح: ' . number_format(floatval($box_row['mony']), 2) . '، المطلوب: ' . number_format($total_amount, 2));
                }
            } else {
                throw new Exception('الصندوق المحدد غير موجود في النظام.');
            }
        }

        // ب. التحقق الخلفي من مديونية المورد
        if ($party_type === 'supplier' && $party_id > 0) {
            $supp_res = $conn->query("SELECT supp_name, supp_daain FROM suppliers WHERE supp_id = $party_id AND d_s = 0 LIMIT 1");
            if ($supp_res && $supp_row = $supp_res->fetch_assoc()) {
                if (floatval($supp_row['supp_daain']) <= 0) {
                    throw new Exception('لا يمكن إنشاء سند صرف للمورد "' . $supp_row['supp_name'] . '" لأنه لا يوجد عليه مديونية مستحقة (الرصيد صفر).');
                }
            }
        }

        // ج. توليد رقم السند
        $conn->query("LOCK TABLES accounting_sequences WRITE");
        $seq_res = $conn->query("SELECT last_no FROM accounting_sequences WHERE seq_key = 'payment'");
        $next_no = ($seq_res && $row = $seq_res->fetch_assoc()) ? intval($row['last_no']) + 1 : 1;
        $voucher_no = 'PV-' . date('Y') . '-' . str_pad($next_no, 5, '0', STR_PAD_LEFT);
        $conn->query("INSERT INTO accounting_sequences (seq_key, last_no) VALUES ('payment', $next_no) ON DUPLICATE KEY UPDATE last_no = $next_no");
        $conn->query("UNLOCK TABLES");

        // د. إدراج رأس السند (تمت إضافة box_id هنا)
        $conn->query("INSERT INTO accounting_vouchers (voucher_type, voucher_no, voucher_date, party_type, party_id, party_name, cash_account_id, box_id, contra_account_id, amount, description, status, created_by) 
                      VALUES ('payment', '$voucher_no', '$voucher_date', '$party_type', $party_id, '$party_name', $cash_acc_id, $box_id, 0, $total_amount, '$description', 'posted', '$user_name')");
        $voucher_id = $conn->insert_id;

        // هـ. تحديث أرصدة الأطراف بدقة
        if ($party_type === 'supplier' && $party_id > 0) {
            $conn->query("UPDATE suppliers SET supp_daain = GREATEST(0, supp_daain - $total_amount) WHERE supp_id = $party_id");
        } elseif ($party_type === 'customer' && $party_id > 0) {
            $conn->query("UPDATE customers SET cust_madeen = GREATEST(0, cust_madeen - $total_amount) WHERE cust_id = $party_id");
        }

        // 🔥 و. خصم المبلغ من رصيد الصندوق (هذا هو السطر المفقود)
        if ($box_id > 0) {
            $conn->query("UPDATE treasury SET mony = mony - $total_amount WHERE box_id = $box_id");
        }

        // ز. معالجة البنود وإنشاء القيود المحاسبية
        $cash_acc_res = $conn->query("SELECT name FROM accounting_accounts WHERE id = $cash_acc_id LIMIT 1");
        $cash_acc_name = $cash_acc_res ? $conn->real_escape_string($cash_acc_res->fetch_assoc()['name']) : 'الصندوق';

        foreach ($items as $item) {
            $acc_id = intval($item['account_id']);
            $amt    = floatval($item['amount']);
            $memo   = $conn->real_escape_string($item['memo'] ?? '');

            $acc_res = $conn->query("SELECT name FROM accounting_accounts WHERE id = $acc_id LIMIT 1");
            $acc_name = $acc_res ? $conn->real_escape_string($acc_res->fetch_assoc()['name']) : 'حساب مقابل';

            $conn->query("INSERT INTO accounting_voucher_items (voucher_id, account_id, amount, memo) VALUES ($voucher_id, $acc_id, $amt, '$memo')");

            $desc_line = $memo ?: $description;
            $conn->query("INSERT INTO accounting_journal (ref_type, ref_id, account_debit, account_credit, amount, description, currency_code, exchange_rate, user, box_id) 
                          VALUES ('payment', $voucher_id, '$acc_name', '$cash_acc_name', $amt, '$desc_line', 'YER', 1.0, '$user_name', $box_id)");
            
            $conn->query("INSERT INTO journal_entries (ref_type, ref_id, account_debit, account_credit, amount, description, currency_code, exchange_rate, user, box_id) 
                          VALUES ('voucher', $voucher_id, '$acc_name', '$cash_acc_name', $amt, '$desc_line', 'YER', 1.0, '$user_name', $box_id)");
        }

        $conn->commit();
        echo json_encode(['success' => true, 'voucher_no' => $voucher_no, 'voucher_id' => $voucher_id]);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ==========================================
// 2. إنشاء سند قبض (Receipt Voucher)
// ==========================================
if ($action === 'create_receipt') {
    $voucher_date = $conn->real_escape_string($_POST['voucher_date']);
    $party_type   = $conn->real_escape_string($_POST['party_type']);
    $party_id     = intval($_POST['party_id'] ?? 0);
    $party_name   = $conn->real_escape_string($_POST['party_name']);
    $cash_acc_id  = intval($_POST['cash_account_id']);
    $box_id       = intval($_POST['box_id'] ?? 0); // استقبال box_id أيضاً لسند القبض
    $description  = $conn->real_escape_string($_POST['description']);
    $total_amount = floatval($_POST['amount']);
    $items        = json_decode($_POST['items'], true);

    if (empty($items) || $total_amount <= 0 || !$cash_acc_id) {
        echo json_encode(['success' => false, 'error' => 'بيانات السند غير مكتملة']);
        exit;
    }

    $conn->begin_transaction();
    try {
        $conn->query("LOCK TABLES accounting_sequences WRITE");
        $seq_res = $conn->query("SELECT last_no FROM accounting_sequences WHERE seq_key = 'receipt'");
        $next_no = ($seq_res && $row = $seq_res->fetch_assoc()) ? intval($row['last_no']) + 1 : 1;
        $voucher_no = 'RV-' . date('Y') . '-' . str_pad($next_no, 5, '0', STR_PAD_LEFT);
        $conn->query("INSERT INTO accounting_sequences (seq_key, last_no) VALUES ('receipt', $next_no) ON DUPLICATE KEY UPDATE last_no = $next_no");
        $conn->query("UNLOCK TABLES");

        $conn->query("INSERT INTO accounting_vouchers (voucher_type, voucher_no, voucher_date, party_type, party_id, party_name, cash_account_id, box_id, contra_account_id, amount, description, status, created_by) 
                      VALUES ('receipt', '$voucher_no', '$voucher_date', '$party_type', $party_id, '$party_name', $cash_acc_id, $box_id, 0, $total_amount, '$description', 'posted', '$user_name')");
        $voucher_id = $conn->insert_id;

        if ($party_type === 'customer' && $party_id > 0) {
            $conn->query("UPDATE customers SET cust_madeen = GREATEST(0, cust_madeen - $total_amount) WHERE cust_id = $party_id");
        } elseif ($party_type === 'supplier' && $party_id > 0) {
            $conn->query("UPDATE suppliers SET supp_madeen = GREATEST(0, supp_madeen - $total_amount) WHERE supp_id = $party_id");
        }

        // 🔥 إضافة المبلغ لرصيد الصندوق في سند القبض
        if ($box_id > 0) {
            $conn->query("UPDATE treasury SET mony = mony + $total_amount WHERE box_id = $box_id");
        }

        $cash_acc_res = $conn->query("SELECT name FROM accounting_accounts WHERE id = $cash_acc_id LIMIT 1");
        $cash_acc_name = $cash_acc_res ? $conn->real_escape_string($cash_acc_res->fetch_assoc()['name']) : 'الصندوق';

        foreach ($items as $item) {
            $acc_id = intval($item['account_id']);
            $amt    = floatval($item['amount']);
            $memo   = $conn->real_escape_string($item['memo'] ?? '');

            $acc_res = $conn->query("SELECT name FROM accounting_accounts WHERE id = $acc_id LIMIT 1");
            $acc_name = $acc_res ? $conn->real_escape_string($acc_res->fetch_assoc()['name']) : 'حساب مقابل';

            $conn->query("INSERT INTO accounting_voucher_items (voucher_id, account_id, amount, memo) VALUES ($voucher_id, $acc_id, $amt, '$memo')");

            $desc_line = $memo ?: $description;
            $conn->query("INSERT INTO accounting_journal (ref_type, ref_id, account_debit, account_credit, amount, description, currency_code, exchange_rate, user, box_id) 
                          VALUES ('receipt', $voucher_id, '$cash_acc_name', '$acc_name', $amt, '$desc_line', 'YER', 1.0, '$user_name', $box_id)");
            
            $conn->query("INSERT INTO journal_entries (ref_type, ref_id, account_debit, account_credit, amount, description, currency_code, exchange_rate, user, box_id) 
                          VALUES ('voucher', $voucher_id, '$cash_acc_name', '$acc_name', $amt, '$desc_line', 'YER', 1.0, '$user_name', $box_id)");
        }

        $conn->commit();
        echo json_encode(['success' => true, 'voucher_no' => $voucher_no, 'voucher_id' => $voucher_id]);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'error' => 'فشل حفظ السند: ' . $e->getMessage()]);
    }
    exit;
}

// ==========================================
// 3. جلب بيانات السند (للعرض أو الطباعة)
// ==========================================
if ($action === 'get' && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    $res = $conn->query("SELECT v.*, ca.name AS cash_account_name, ca.code AS cash_account_code 
                         FROM accounting_vouchers v
                         LEFT JOIN accounting_accounts ca ON v.cash_account_id = ca.id
                         WHERE v.id = $id LIMIT 1");
    
    if (!$res || $res->num_rows === 0) {
        echo json_encode(['success' => false, 'error' => 'السند غير موجود']);
        exit;
    }
    
    $voucher = $res->fetch_assoc();
    $items_res = $conn->query("SELECT vi.*, a.name AS account_name, a.code AS account_code 
                               FROM accounting_voucher_items vi
                               LEFT JOIN accounting_accounts a ON vi.account_id = a.id
                               WHERE vi.voucher_id = $id");
    $items = [];
    while ($row = $items_res->fetch_assoc()) {
        $items[] = $row;
    }
    $voucher['items'] = $items;
    echo json_encode(['success' => true, 'voucher' => $voucher]);
    exit;
}

// ==========================================
// 4. إلغاء السند (Void)
// ==========================================
if ($action === 'void' && isset($_POST['id'])) {
    $id = intval($_POST['id']);
    $reason = $conn->real_escape_string($_POST['reason'] ?? 'تم الإلغاء يدوياً');
    
    $conn->begin_transaction();
    try {
        // تم إضافة box_id في الاستعلام لاسترجاعه
        $res = $conn->query("SELECT * FROM accounting_vouchers WHERE id = $id AND status = 'posted' LIMIT 1");
        if (!$res || $res->num_rows === 0) {
            throw new Exception('السند غير موجود أو تم إلغاؤه مسبقاً');
        }
        $v = $res->fetch_assoc();
        $amount = floatval($v['amount']);
        $party_type = $v['party_type'];
        $party_id = intval($v['party_id']);
        $box_id = intval($v['box_id'] ?? 0);

        if ($v['voucher_type'] === 'payment') {
            if ($party_type === 'supplier' && $party_id > 0) {
                $conn->query("UPDATE suppliers SET supp_daain = supp_daain + $amount WHERE supp_id = $party_id");
            } elseif ($party_type === 'customer' && $party_id > 0) {
                $conn->query("UPDATE customers SET cust_madeen = cust_madeen + $amount WHERE cust_id = $party_id");
            }
            // 🔥 إرجاع المبلغ للصندوق عند إلغاء سند الصرف
            if ($box_id > 0) {
                $conn->query("UPDATE treasury SET mony = mony + $amount WHERE box_id = $box_id");
            }
        } else {
            if ($party_type === 'customer' && $party_id > 0) {
                $conn->query("UPDATE customers SET cust_madeen = cust_madeen + $amount WHERE cust_id = $party_id");
            } elseif ($party_type === 'supplier' && $party_id > 0) {
                $conn->query("UPDATE suppliers SET supp_madeen = supp_madeen + $amount WHERE supp_id = $party_id");
            }
            // 🔥 خصم المبلغ من الصندوق عند إلغاء سند القبض
            if ($box_id > 0) {
                $conn->query("UPDATE treasury SET mony = mony - $amount WHERE box_id = $box_id");
            }
        }

        $conn->query("UPDATE accounting_vouchers SET status = 'voided' WHERE id = $id");
        $old_data = json_encode($v);
        $conn->query("INSERT INTO accounting_journal_audit (entry_id, action, changed_by, reason, old_data) 
                      VALUES ($id, 'void', '$user_name', '$reason', '$old_data')");

        $conn->commit();
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ==========================================
// 5. حذف السند نهائياً (Delete)
// ==========================================
if ($action === 'delete' && isset($_POST['id'])) {
    $id = intval($_POST['id']);
    $conn->begin_transaction();
    try {
        // تم إضافة box_id في الاستعلام لاسترجاعه
        $res = $conn->query("SELECT * FROM accounting_vouchers WHERE id = $id LIMIT 1");
        if (!$res || $res->num_rows === 0) {
            throw new Exception('السند غير موجود');
        }
        $v = $res->fetch_assoc();
        $amount = floatval($v['amount']);
        $party_type = $v['party_type'];
        $party_id = intval($v['party_id']);
        $box_id = intval($v['box_id'] ?? 0);

        if ($v['voucher_type'] === 'payment') {
            if ($party_type === 'supplier' && $party_id > 0) {
                $conn->query("UPDATE suppliers SET supp_daain = supp_daain + $amount WHERE supp_id = $party_id");
            } elseif ($party_type === 'customer' && $party_id > 0) {
                $conn->query("UPDATE customers SET cust_madeen = cust_madeen + $amount WHERE cust_id = $party_id");
            }
            // 🔥 إرجاع المبلغ للصندوق عند حذف سند الصرف
            if ($box_id > 0) {
                $conn->query("UPDATE treasury SET mony = mony + $amount WHERE box_id = $box_id");
            }
        } else {
            if ($party_type === 'customer' && $party_id > 0) {
                $conn->query("UPDATE customers SET cust_madeen = cust_madeen + $amount WHERE cust_id = $party_id");
            } elseif ($party_type === 'supplier' && $party_id > 0) {
                $conn->query("UPDATE suppliers SET supp_madeen = supp_madeen + $amount WHERE supp_id = $party_id");
            }
            // 🔥 خصم المبلغ من الصندوق عند حذف سند القبض
            if ($box_id > 0) {
                $conn->query("UPDATE treasury SET mony = mony - $amount WHERE box_id = $box_id");
            }
        }

        $conn->query("DELETE FROM accounting_voucher_items WHERE voucher_id = $id");
        $conn->query("DELETE FROM accounting_journal WHERE ref_type IN ('payment', 'receipt') AND ref_id = $id");
        $conn->query("DELETE FROM journal_entries WHERE ref_type = 'voucher' AND ref_id = $id");
        $conn->query("DELETE FROM accounting_vouchers WHERE id = $id");

        $conn->commit();
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

echo json_encode(['success' => false, 'error' => 'إجراء غير معروف']);
?>