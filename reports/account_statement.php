<?php
$dir_prefix = '../';
$module = 'reports';
require_once($dir_prefix . 'includes/header.php');

check_permission(['admin', 'cashier', 'inventory', 'reports']);

$account_type = isset($_GET['type']) ? trim($_GET['type']) : 'customer'; // customer, supplier
$account_id   = isset($_GET['account_id']) ? intval($_GET['account_id']) : 0;
$account_name = isset($_GET['account_name']) ? trim($_GET['account_name']) : '';
$from_date    = isset($_GET['from_date']) ? trim($_GET['from_date']) : '';
$to_date      = isset($_GET['to_date']) ? trim($_GET['to_date']) : '';

// 1. جلب قائمة العملاء من قاعدة البيانات
$customers_list = [];
$res_c = $conn->query("SELECT cust_id as id, cust_name as name, COALESCE(cust_madeen, 0) as madeen, COALESCE(cust_daain, 0) as daain, phone FROM customers WHERE d_s = 0 ORDER BY cust_name ASC");
if ($res_c) {
    while ($r = $res_c->fetch_assoc()) $customers_list[] = $r;
}

// 2. جلب قائمة الموردين من قاعدة البيانات
$suppliers_list = [];
$res_s = $conn->query("SELECT supp_id as id, supp_name as name, COALESCE(supp_daain, 0) as daain, COALESCE(supp_madeen, 0) as madeen, phone FROM suppliers WHERE d_s = 0 ORDER BY supp_name ASC");
if ($res_s) {
    while ($r = $res_s->fetch_assoc()) $suppliers_list[] = $r;
}

// تحديد الحساب المختار بالاسم أو بالـ ID
$selected_name = '';
$current_balance = 0.0;
$transactions = [];
$total_debit = 0.0;
$total_credit = 0.0;

if ($account_type === 'customer') {
    if (!empty($account_name)) {
        foreach ($customers_list as $c) {
            if ($c['name'] === $account_name || strpos($c['name'], $account_name) !== false || strpos($account_name, $c['name']) !== false) {
                $account_id = $c['id'];
                $selected_name = $c['name'];
                $current_balance = floatval($c['madeen']) - floatval($c['daain']);
                break;
            }
        }
        if (empty($selected_name)) {
            $selected_name = $account_name;
        }
    }
    if (empty($selected_name) && $account_id > 0) {
        $res_info = $conn->query("SELECT cust_name, cust_madeen, cust_daain FROM customers WHERE cust_id = $account_id LIMIT 1");
        if ($res_info && $row_i = $res_info->fetch_assoc()) {
            $selected_name = $row_i['cust_name'];
            $current_balance = floatval($row_i['cust_madeen']) - floatval($row_i['cust_daain']);
        }
    }
    if (empty($selected_name) && !empty($customers_list)) {
        $account_id = $customers_list[0]['id'];
        $selected_name = $customers_list[0]['name'];
        $current_balance = floatval($customers_list[0]['madeen']) - floatval($customers_list[0]['daain']);
    }
} else {
    if (!empty($account_name)) {
        foreach ($suppliers_list as $s) {
            if ($s['name'] === $account_name || strpos($s['name'], $account_name) !== false || strpos($account_name, $s['name']) !== false) {
                $account_id = $s['id'];
                $selected_name = $s['name'];
                $current_balance = floatval($s['daain']) - floatval($s['madeen']);
                break;
            }
        }
        if (empty($selected_name)) {
            $selected_name = $account_name;
        }
    }
    if (empty($selected_name) && $account_id > 0) {
        $res_info = $conn->query("SELECT supp_name, supp_daain, supp_madeen FROM suppliers WHERE supp_id = $account_id LIMIT 1");
        if ($res_info && $row_i = $res_info->fetch_assoc()) {
            $selected_name = $row_i['supp_name'];
            $current_balance = floatval($row_i['supp_daain']) - floatval($row_i['supp_madeen']);
        }
    }
    if (empty($selected_name) && !empty($suppliers_list)) {
        $account_id = $suppliers_list[0]['id'];
        $selected_name = $suppliers_list[0]['name'];
        $current_balance = floatval($suppliers_list[0]['daain']) - floatval($suppliers_list[0]['madeen']);
    }
}


// ==========================================
// جلب الحركات والتسجيلات المحاسبية الموحدة الشاملة
// ==========================================
if (!empty($selected_name) || $account_id > 0) {
    $name_esc = $conn->real_escape_string($selected_name);
    $seen_keys = []; // لمنع تكرار نفس الحركة بين الجداول المترابطة

    if ($account_type === 'customer') {
        // --- 1. فواتير المبيعات الحديثة (sales_invoices_mst) ---
        $chk_sim = $conn->query("SHOW TABLES LIKE 'sales_invoices_mst'");
        if ($chk_sim && $chk_sim->num_rows > 0) {
            $w = "(cust_id = $account_id OR cust_name = '$name_esc' OR cust_name LIKE '%$name_esc%') AND d_s = 0";
            if (!empty($from_date)) $w .= " AND invoice_date >= '" . $conn->real_escape_string($from_date) . "'";
            if (!empty($to_date))   $w .= " AND invoice_date <= '" . $conn->real_escape_string($to_date) . "'";

            $res = $conn->query("SELECT id, invoice_no, invoice_date, net_amount, paid_amount, remaining_amount, invoice_type, remark FROM sales_invoices_mst WHERE $w");
            if ($res) {
                while ($r = $res->fetch_assoc()) {
                    $key = 'sim_' . $r['id'];
                    if (isset($seen_keys[$key])) continue;
                    $seen_keys[$key] = true;

                    $tot = floatval($r['net_amount']);
                    $paid = floatval($r['paid_amount']);
                    $rem = floatval($r['remaining_amount']);
                    
                    // المسجل مدين هو إجمالي الفاتورة والمسدد دائن
                    $total_debit += $tot;
                    $total_credit += $paid;
                    
                    $inv_label = ($r['invoice_type'] === 'credit') ? 'آجل' : 'نقداً';
                    $transactions[] = [
                        'date' => $r['invoice_date'],
                        'doc_no' => '#' . ($r['invoice_no'] ?: $r['id']),
                        'doc_type' => 'فاتورة مبيعات (' . $inv_label . ')',
                        'debit' => $tot,
                        'credit' => $paid,
                        'notes' => $r['remark'] ?: 'مبيعات للعميل'
                    ];
                }
            }
        }

        // --- 2. فواتير المبيعات التقليدية (sales) ---
        $chk_s = $conn->query("SHOW TABLES LIKE 'sales'");
        if ($chk_s && $chk_s->num_rows > 0) {
            $w = "(cust_name = '$name_esc' OR cust_name LIKE '%$name_esc%') AND delete_status = 0";
            if (!empty($from_date)) $w .= " AND build_date >= '" . $conn->real_escape_string($from_date) . "'";
            if (!empty($to_date))   $w .= " AND build_date <= '" . $conn->real_escape_string($to_date) . "'";

            $res = $conn->query("SELECT id, build_date, total, remark FROM sales WHERE $w");
            if ($res) {
                while ($r = $res->fetch_assoc()) {
                    $key = 'sales_' . $r['id'];
                    if (isset($seen_keys[$key])) continue;
                    $seen_keys[$key] = true;

                    $deb = floatval($r['total']);
                    $total_debit += $deb;
                    $transactions[] = [
                        'date' => $r['build_date'],
                        'doc_no' => '#' . $r['id'],
                        'doc_type' => 'فاتورة مبيعات',
                        'debit' => $deb,
                        'credit' => 0.0,
                        'notes' => $r['remark'] ?: 'بيع بضاعة للعميل'
                    ];
                }
            }
        }

        // --- 3. سندات القبض الحديثة (receipt_vouchers_mst) ---
        $chk_rvm = $conn->query("SHOW TABLES LIKE 'receipt_vouchers_mst'");
        if ($chk_rvm && $chk_rvm->num_rows > 0) {
            $w = "(party_id = $account_id OR party_name = '$name_esc' OR party_name LIKE '%$name_esc%') AND d_s = 0";
            if (!empty($from_date)) $w .= " AND voucher_date >= '" . $conn->real_escape_string($from_date) . "'";
            if (!empty($to_date))   $w .= " AND voucher_date <= '" . $conn->real_escape_string($to_date) . "'";

            $res = $conn->query("SELECT id, voucher_no, voucher_date, total_amount, remark FROM receipt_vouchers_mst WHERE $w");
            if ($res) {
                while ($r = $res->fetch_assoc()) {
                    $key = 'rvm_' . $r['id'];
                    if (isset($seen_keys[$key])) continue;
                    $seen_keys[$key] = true;

                    $cred = floatval($r['total_amount']);
                    $total_credit += $cred;
                    $transactions[] = [
                        'date' => $r['voucher_date'],
                        'doc_no' => '#' . ($r['voucher_no'] ?: $r['id']),
                        'doc_type' => 'سند قبض مالي',
                        'debit' => 0.0,
                        'credit' => $cred,
                        'notes' => $r['remark'] ?: 'تحصيل دفعة مالية من العميل'
                    ];
                }
            }
        }

        // --- 4. سندات القبض التقليدية (receipts & mcust) ---
        $chk_rec = $conn->query("SHOW TABLES LIKE 'receipts'");
        if ($chk_rec && $chk_rec->num_rows > 0) {
            $w = "(cust_name = '$name_esc' OR cust_name LIKE '%$name_esc%') AND s = 0";
            if (!empty($from_date)) $w .= " AND q_date >= '" . $conn->real_escape_string($from_date) . "'";
            if (!empty($to_date))   $w .= " AND q_date <= '" . $conn->real_escape_string($to_date) . "'";

            $res = $conn->query("SELECT qid, q_date, q_price, remark FROM receipts WHERE $w");
            if ($res) {
                while ($r = $res->fetch_assoc()) {
                    $key = 'rec_' . $r['qid'];
                    if (isset($seen_keys[$key])) continue;
                    $seen_keys[$key] = true;

                    $cred = floatval($r['q_price']);
                    $total_credit += $cred;
                    $transactions[] = [
                        'date' => $r['q_date'],
                        'doc_no' => '#' . $r['qid'],
                        'doc_type' => 'سند قبض مالي',
                        'debit' => 0.0,
                        'credit' => $cred,
                        'notes' => $r['remark'] ?: 'قبض دفعة نقدية'
                    ];
                }
            }
        }

        $chk_mc = $conn->query("SHOW TABLES LIKE 'mcust'");
        if ($chk_mc && $chk_mc->num_rows > 0) {
            $w = "(sname = '$name_esc' OR sname LIKE '%$name_esc%')";
            if (!empty($from_date)) $w .= " AND m_date >= '" . $conn->real_escape_string($from_date) . "'";
            if (!empty($to_date))   $w .= " AND m_date <= '" . $conn->real_escape_string($to_date) . "'";

            $res = $conn->query("SELECT m_id, m_date, m_price, remark FROM mcust WHERE $w");
            if ($res) {
                while ($r = $res->fetch_assoc()) {
                    $key = 'mcust_' . $r['m_id'];
                    if (isset($seen_keys[$key])) continue;
                    $seen_keys[$key] = true;

                    $cred = floatval($r['m_price']);
                    $total_credit += $cred;
                    $transactions[] = [
                        'date' => $r['m_date'],
                        'doc_no' => '#' . $r['m_id'],
                        'doc_type' => 'سند قبض مالي',
                        'debit' => 0.0,
                        'credit' => $cred,
                        'notes' => $r['remark'] ?: 'تحصيل دفعة مالية'
                    ];
                }
            }
        }

    } else { // Supplier Statement
        // --- 1. فواتير المشتريات الحديثة (purchase_invoices_mst) ---
        $chk_pim = $conn->query("SHOW TABLES LIKE 'purchase_invoices_mst'");
        if ($chk_pim && $chk_pim->num_rows > 0) {
            $w = "(supp_id = $account_id OR supp_name = '$name_esc' OR supp_name LIKE '%$name_esc%') AND d_s = 0";
            if (!empty($from_date)) $w .= " AND invoice_date >= '" . $conn->real_escape_string($from_date) . "'";
            if (!empty($to_date))   $w .= " AND invoice_date <= '" . $conn->real_escape_string($to_date) . "'";

            $res = $conn->query("SELECT id, invoice_no, invoice_date, net_amount, paid_amount, remaining_amount, invoice_type, remark FROM purchase_invoices_mst WHERE $w");
            if ($res) {
                while ($r = $res->fetch_assoc()) {
                    $key = 'pim_' . $r['id'];
                    if (isset($seen_keys[$key])) continue;
                    $seen_keys[$key] = true;

                    $tot = floatval($r['net_amount']);
                    $paid = floatval($r['paid_amount']);
                    
                    $total_credit += $tot;
                    $total_debit += $paid;

                    $inv_label = ($r['invoice_type'] === 'credit') ? 'آجل' : 'نقداً';
                    $transactions[] = [
                        'date' => $r['invoice_date'],
                        'doc_no' => '#' . ($r['invoice_no'] ?: $r['id']),
                        'doc_type' => 'فاتورة مشتريات (' . $inv_label . ')',
                        'debit' => $paid,
                        'credit' => $tot,
                        'notes' => $r['remark'] ?: 'توريد مشتريات من المورد'
                    ];
                }
            }
        }

        // --- 2. فواتير المشتريات التقليدية (purchases / allbuys / buys) ---
        $chk_p = $conn->query("SHOW TABLES LIKE 'purchases'");
        if ($chk_p && $chk_p->num_rows > 0) {
            $w = "(supp_id = $account_id OR supp_name = '$name_esc' OR supp_name LIKE '%$name_esc%')";
            if (!empty($from_date)) $w .= " AND date >= '" . $conn->real_escape_string($from_date) . "'";
            if (!empty($to_date))   $w .= " AND date <= '" . $conn->real_escape_string($to_date) . "'";

            $res = $conn->query("SELECT id, date, total, remark FROM purchases WHERE $w");
            if ($res) {
                while ($r = $res->fetch_assoc()) {
                    $key = 'pur_' . $r['id'];
                    if (isset($seen_keys[$key])) continue;
                    $seen_keys[$key] = true;

                    $cred = floatval($r['total']);
                    $total_credit += $cred;
                    $transactions[] = [
                        'date' => $r['date'],
                        'doc_no' => '#' . $r['id'],
                        'doc_type' => 'فاتورة مشتريات',
                        'debit' => 0.0,
                        'credit' => $cred,
                        'notes' => $r['remark'] ?: 'توريد بضاعة'
                    ];
                }
            }
        }

        $chk_ab = $conn->query("SHOW TABLES LIKE 'allbuys'");
        if ($chk_ab && $chk_ab->num_rows > 0) {
            $w = "(supp_name = '$name_esc' OR supp_name LIKE '%$name_esc%')";
            if (!empty($from_date)) $w .= " AND date >= '" . $conn->real_escape_string($from_date) . "'";
            if (!empty($to_date))   $w .= " AND date <= '" . $conn->real_escape_string($to_date) . "'";

            $res = $conn->query("SELECT id, date, total, remark FROM allbuys WHERE $w");
            if ($res) {
                while ($r = $res->fetch_assoc()) {
                    $key = 'allbuys_' . $r['id'];
                    if (isset($seen_keys[$key])) continue;
                    $seen_keys[$key] = true;

                    $cred = floatval($r['total']);
                    $total_credit += $cred;
                    $transactions[] = [
                        'date' => $r['date'],
                        'doc_no' => '#' . $r['id'],
                        'doc_type' => 'فاتورة مشتريات',
                        'debit' => 0.0,
                        'credit' => $cred,
                        'notes' => $r['remark'] ?: 'إدخال فاتورة شراء'
                    ];
                }
            }
        }

        // --- 3. سندات الصرف الحديثة للمورد (payment_vouchers_mst) ---
        $chk_pvm = $conn->query("SHOW TABLES LIKE 'payment_vouchers_mst'");
        if ($chk_pvm && $chk_pvm->num_rows > 0) {
            $w = "(party_id = $account_id OR party_name = '$name_esc' OR party_name LIKE '%$name_esc%') AND d_s = 0";
            if (!empty($from_date)) $w .= " AND voucher_date >= '" . $conn->real_escape_string($from_date) . "'";
            if (!empty($to_date))   $w .= " AND voucher_date <= '" . $conn->real_escape_string($to_date) . "'";

            $res = $conn->query("SELECT id, voucher_no, voucher_date, total_amount, remark FROM payment_vouchers_mst WHERE $w");
            if ($res) {
                while ($r = $res->fetch_assoc()) {
                    $key = 'pvm_' . $r['id'];
                    if (isset($seen_keys[$key])) continue;
                    $seen_keys[$key] = true;

                    $deb = floatval($r['total_amount']);
                    $total_debit += $deb;
                    $transactions[] = [
                        'date' => $r['voucher_date'],
                        'doc_no' => '#' . ($r['voucher_no'] ?: $r['id']),
                        'doc_type' => 'سند صرف مالي',
                        'debit' => $deb,
                        'credit' => 0.0,
                        'notes' => $r['remark'] ?: 'سداد مستحقات المورد'
                    ];
                }
            }
        }

        // --- 4. سندات الصرف التقليدية (treasury_expenses / bush / ms) ---
        $chk_te = $conn->query("SHOW TABLES LIKE 'treasury_expenses'");
        if ($chk_te && $chk_te->num_rows > 0) {
            $w = "(st = '$name_esc' OR sname = '$name_esc' OR st LIKE '%$name_esc%') AND s = 0";
            if (!empty($from_date)) $w .= " AND sdate >= '" . $conn->real_escape_string($from_date) . "'";
            if (!empty($to_date))   $w .= " AND sdate <= '" . $conn->real_escape_string($to_date) . "'";

            $res = $conn->query("SELECT sid, sdate, sprice, sremark FROM treasury_expenses WHERE $w");
            if ($res) {
                while ($r = $res->fetch_assoc()) {
                    $key = 'te_' . $r['sid'];
                    if (isset($seen_keys[$key])) continue;
                    $seen_keys[$key] = true;

                    $deb = floatval($r['sprice']);
                    $total_debit += $deb;
                    $transactions[] = [
                        'date' => $r['sdate'],
                        'doc_no' => '#' . $r['sid'],
                        'doc_type' => 'سند صرف مالي',
                        'debit' => $deb,
                        'credit' => 0.0,
                        'notes' => $r['sremark'] ?: 'صرف مبالغ للمورد'
                    ];
                }
            }
        }

        $chk_bush = $conn->query("SHOW TABLES LIKE 'bush'");
        if ($chk_bush && $chk_bush->num_rows > 0) {
            $w = "(supp_name = '$name_esc' OR supp_name LIKE '%$name_esc%')";
            if (!empty($from_date)) $w .= " AND bush_date >= '" . $conn->real_escape_string($from_date) . "'";
            if (!empty($to_date))   $w .= " AND bush_date <= '" . $conn->real_escape_string($to_date) . "'";

            $res = $conn->query("SELECT bush_id, bush_date, bush_price, remark FROM bush WHERE $w");
            if ($res) {
                while ($r = $res->fetch_assoc()) {
                    $key = 'bush_' . $r['bush_id'];
                    if (isset($seen_keys[$key])) continue;
                    $seen_keys[$key] = true;

                    $deb = floatval($r['bush_price']);
                    $total_debit += $deb;
                    $transactions[] = [
                        'date' => $r['bush_date'],
                        'doc_no' => '#' . $r['bush_id'],
                        'doc_type' => 'سند صرف مالي',
                        'debit' => $deb,
                        'credit' => 0.0,
                        'notes' => $r['remark'] ?: 'دفعة سداد للمورد'
                    ];
                }
            }
        }
    }

    // ترتيب الحركات تسلسلياً حسب التاريخ
    usort($transactions, function($a, $b) {
        return strtotime($a['date']) - strtotime($b['date']);
    });
}
?>
<title>كشف حساب تفصيلي الموحد — AQNEX POS</title>

<style>
@media print {
    #sidebar, .navbar-top, .no-print, .filter-panel, .ptb-actions { display: none !important; }
    #content { margin: 0 !important; padding: 0 !important; }
    body { background: #fff !important; font-size: 11pt; }
    .print-header { display: block !important; margin-bottom: 20px; border-bottom: 2px solid #000; padding-bottom: 10px; }
    .report-table th, .report-table td { border: 1px solid #000 !important; color: #000 !important; }
}
.print-header { display: none; }

/* ===== Page Title Bar ===== */
.page-title-bar {
    display: flex; align-items: center; justify-content: space-between;
    padding: 10px 0 14px; border-bottom: 2px solid #e2e8f0;
    margin-bottom: 18px; flex-wrap: wrap; gap: 10px;
}
.page-title-bar .ptb-left { display: flex; align-items: center; gap: 10px; }
.page-title-bar .icon-wrap {
    width: 36px; height: 36px;
    background: linear-gradient(135deg, #1e3a8a 0%, #1d4ed8 100%);
    display: flex; align-items: center; justify-content: center;
    color: #fff; font-size: 1rem;
}
.page-title-bar h4 { margin: 0; font-size: 0.95rem; font-weight: 700; color: var(--text-color); }
.page-title-bar small { font-size: 0.72rem; color: #64748b; display: block; }
.page-title-bar .ptb-actions { display: flex; gap: 8px; flex-wrap: wrap; }

/* ===== Filter Panel ===== */
.filter-panel {
    background: #f8fafc; border: 1px solid #e2e8f0; padding: 14px 16px; margin-bottom: 18px;
}
.filter-panel .form-label { font-size: 0.72rem; font-weight: 700; color: #475569; margin-bottom: 4px; }

/* ===== KPI Cards ===== */
.kpi-row {
    display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-bottom: 18px;
}
@media (max-width: 768px) { .kpi-row { grid-template-columns: repeat(1, 1fr); } }

.kpi-card {
    background: #fff; border: 1px solid #e2e8f0; padding: 14px 16px; position: relative; overflow: hidden;
}
.kpi-card::before { content:''; position: absolute; top:0; right:0; width:4px; height:100%; }
.kpi-red::before   { background: linear-gradient(180deg, #ef4444, #b91c1c); }
.kpi-green::before { background: linear-gradient(180deg, #10b981, #059669); }
.kpi-blue::before  { background: linear-gradient(180deg, #3b82f6, #1d4ed8); }

.kpi-card .lbl { font-size: 0.7rem; font-weight: 700; color: #64748b; margin-bottom: 4px; }
.kpi-card .val { font-size: 1.25rem; font-weight: 800; color: #0f172a; line-height: 1; }

/* ===== Report Tables ===== */
.report-table {
    width: 100%; border-collapse: collapse; background: #fff;
}
.report-table thead th {
    background: #f1f5f9; color: #334155; font-size: 0.76rem;
    font-weight: 700; padding: 10px 8px; border: 1px solid #cbd5e1; text-align: center;
}
.report-table tbody td {
    padding: 8px 10px; border: 1px solid #e2e8f0; font-size: 0.78rem; vertical-align: middle;
}
.report-table tbody tr:hover { background: #f8fafc; }
</style>

<div class="page-inner">

<!-- ترويسة الطباعة الرسمية -->
<div class="print-header text-center">
    <h3 class="font-weight-bold mb-1">كشف حساب تفصيلي — <?php echo htmlspecialchars($selected_name ?: 'حساب عام'); ?></h3>
    <p class="text-muted mb-0">الفترة من: <?php echo $from_date ?: 'البداية'; ?> إلى: <?php echo $to_date ?: 'اليوم'; ?> | طُبع بواسطة: <?php echo htmlspecialchars($_SESSION['SESS_FIRST_NAME'] ?? 'المدير'); ?></p>
</div>

<!-- رأس الصفحة -->
<div class="page-title-bar no-print">
    <div class="ptb-left">
        <div class="icon-wrap"><i class="bi bi-file-earmark-spreadsheet-fill"></i></div>
        <div>
            <h4>كشف حساب تفصيلي الموحد</h4>
            <small>عرض سجل الحركات المالية، المدين والدائن والرصيد التراكمي للحسابات</small>
        </div>
    </div>
    <div class="ptb-actions">
        <button onclick="window.print()" class="btn btn-sm btn-light" style="font-size:0.8rem; border:1px solid #cbd5e1;" title="طباعة كشف الحساب الحالي">
            <i class="bi bi-printer ml-1"></i> طباعة كشف الحساب
        </button>
        <a href="../home.php" class="btn btn-sm btn-light text-decoration-none" style="font-size:0.8rem; border:1px solid #cbd5e1;" title="العودة للرئيسية">
            <i class="bi bi-arrow-left ml-1"></i> عودة
        </a>
    </div>
</div>

<!-- فلتر البحث مع دعم الإكمال التلقائي Autocomplete -->
<div class="filter-panel no-print">
    <form method="GET" action="account_statement.php" id="statementForm">
        <div class="row align-items-end">
            <div class="col-md-3 mb-2 mb-md-0">
                <label class="form-label">نوع كشف الحساب</label>
                <select name="type" id="statementTypeSelect" class="form-control form-control-sm font-weight-bold" onchange="toggleAccountType(this.value)">
                    <option value="customer" <?php echo ($account_type === 'customer') ? 'selected' : ''; ?>>كشف حساب عميل (ذمم مدينة)</option>
                    <option value="supplier" <?php echo ($account_type === 'supplier') ? 'selected' : ''; ?>>كشف حساب مورد (ذمم دائنة)</option>
                </select>
            </div>

            <div class="col-md-4 mb-2 mb-md-0">
                <label class="form-label">بحث واختيار الحساب (Autocomplete)</label>
                <div class="position-relative">
                    <input type="text" name="account_name" id="accountSearchInput" list="accountsDatalist" class="form-control form-control-sm font-weight-bold" placeholder="اكتب للبحث عن اسم الحساب..." autocomplete="off" value="<?php echo htmlspecialchars($selected_name); ?>">
                    <datalist id="accountsDatalist">
                        <?php if ($account_type === 'customer'): ?>
                            <?php foreach ($customers_list as $c): ?>
                                <option value="<?php echo htmlspecialchars($c['name']); ?>">
                            <?php endforeach; ?>
                        <?php else: ?>
                            <?php foreach ($suppliers_list as $s): ?>
                                <option value="<?php echo htmlspecialchars($s['name']); ?>">
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </datalist>
                    <input type="hidden" name="account_id" id="accountIdHidden" value="<?php echo $account_id; ?>">
                </div>
            </div>

            <div class="col-md-2 mb-2 mb-md-0">
                <label class="form-label">من تاريخ</label>
                <input type="date" name="from_date" class="form-control form-control-sm" value="<?php echo htmlspecialchars($from_date); ?>">
            </div>

            <div class="col-md-2 mb-2 mb-md-0">
                <label class="form-label">إلى تاريخ</label>
                <input type="date" name="to_date" class="form-control form-control-sm" value="<?php echo htmlspecialchars($to_date); ?>">
            </div>

            <div class="col-md-1">
                <button type="submit" class="btn btn-primary btn-sm btn-block" style="font-weight:700;">
                    <i class="bi bi-search ml-1"></i> عرض
                </button>
            </div>
        </div>
    </form>
</div>

<!-- KPI Summary Cards -->
<div class="kpi-row no-print">
    <div class="kpi-card kpi-red">
        <div class="lbl">إجمالي مدين الفحوصات (Debit)</div>
        <div class="val text-danger"><?php echo number_format($total_debit, 2); ?> <small style="font-size:0.6rem;">ر.ي</small></div>
    </div>
    <div class="kpi-card kpi-green">
        <div class="lbl">إجمالي دائن السدادات (Credit)</div>
        <div class="val text-success"><?php echo number_format($total_credit, 2); ?> <small style="font-size:0.6rem;">ر.ي</small></div>
    </div>
    <div class="kpi-card kpi-blue">
        <div class="lbl">رصيد الحساب الدفتري الحالي</div>
        <div class="val text-primary"><?php echo number_format($current_balance, 2); ?> <small style="font-size:0.6rem;">ر.ي</small></div>
    </div>
</div>

<!-- جدول الحركات التفصيلي -->
<div class="card-flat">
    <div class="card-header d-flex align-items-center justify-content-between">
        <h5><i class="bi bi-list-stars ml-2 text-primary"></i>كشف حركات الحساب التفصيلي: <strong><?php echo htmlspecialchars($selected_name ?: 'حساب عام'); ?></strong></h5>
        <span class="badge badge-light border px-2 py-1">عدد الحركات: <?php echo count($transactions); ?></span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="report-table">
                <thead>
                    <tr>
                        <th style="width:12%;">التاريخ</th>
                        <th style="width:12%;">رقم المستند</th>
                        <th style="width:24%;">نوع الحركة / المستند</th>
                        <th style="width:13%;">مدين (Debit)</th>
                        <th style="width:13%;">دائن (Credit)</th>
                        <th style="width:14%;">الرصيد التراكمي</th>
                        <th style="width:12%;">ملاحظات / البيان</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($transactions)): 
                        $running = 0.0;
                        foreach ($transactions as $t):
                            $running += ($t['debit'] - $t['credit']);
                    ?>
                    <tr>
                        <td class="text-center font-monospace"><?php echo htmlspecialchars($t['date']); ?></td>
                        <td class="text-center font-weight-bold text-muted"><?php echo htmlspecialchars($t['doc_no']); ?></td>
                        <td class="font-weight-bold text-dark"><?php echo htmlspecialchars($t['doc_type']); ?></td>
                        <td class="text-center font-weight-bold text-danger"><?php echo $t['debit'] > 0 ? number_format($t['debit'], 2) : '—'; ?></td>
                        <td class="text-center font-weight-bold text-success"><?php echo $t['credit'] > 0 ? number_format($t['credit'], 2) : '—'; ?></td>
                        <td class="text-center font-weight-bold text-primary" style="background:#f8fafc;"><?php echo number_format($running, 2); ?> ر.ي</td>
                        <td class="text-muted small"><?php echo htmlspecialchars($t['notes']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php else: ?>
                    <tr>
                        <td colspan="7" class="text-center py-4 text-muted">لا توجد حركات مسجلة لهذا الحساب خلال الفترة المحددة</td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

</div><!-- end .page-inner -->

<script>
const customersList = <?php echo json_encode($customers_list); ?>;
const suppliersList = <?php echo json_encode($suppliers_list); ?>;

function toggleAccountType(type) {
    const datalist = document.getElementById('accountsDatalist');
    const input = document.getElementById('accountSearchInput');
    input.value = '';
    datalist.innerHTML = '';

    const list = (type === 'customer') ? customersList : suppliersList;
    list.forEach(item => {
        const opt = document.createElement('option');
        opt.value = item.name;
        datalist.appendChild(opt);
    });
}
</script>

<?php require_once($dir_prefix . 'includes/footer.php'); ?>
