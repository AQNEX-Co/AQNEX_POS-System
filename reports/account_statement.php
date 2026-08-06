<?php
$dir_prefix = '../';
$module = 'reports';
require_once($dir_prefix . 'includes/header.php');

check_permission(['admin', 'cashier', 'inventory', 'reports']);

// جلب إعدادات المنشأة والشعار
$settings = \AQNEX\Services\SettingsService::loadSettings($conn);
$store_name_ar = !empty($settings['store_name']) ? $settings['store_name'] : 'شركة أقنكس للأنظمة البرمجية المحدودة';
$store_name_en = !empty($settings['store_name_en']) ? $settings['store_name_en'] : 'AQNEX POS & ERP Systems Co.';
$phone         = !empty($settings['phone']) ? $settings['phone'] : '777777777';
$address_ar    = !empty($settings['address']) ? $settings['address'] : 'اليمن - عدن - الشارع الرئيسي';
$address_en    = !empty($settings['address_en']) ? $settings['address_en'] : 'Main St, Aden, Yemen';
$cr_number     = !empty($settings['commercial_register']) ? $settings['commercial_register'] : 'CR-104928';
$tax_number    = !empty($settings['tax_number']) ? $settings['tax_number'] : 'TAX-300192847';
$logo_src      = !empty($settings['logo']) ? $dir_prefix . ltrim($settings['logo'], '/') : $dir_prefix . 'assets/icon/tec.jpg';

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
            if ($c['name'] === $account_name || strpos($c['name'], $account_name) !== false) {
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
            if ($s['name'] === $account_name || strpos($s['name'], $account_name) !== false) {
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
    $seen_keys = [];

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
                    $key = 'sale_' . $r['id'];
                    if (isset($seen_keys[$key])) continue;
                    $seen_keys[$key] = true;

                    $tot = floatval($r['net_amount']);
                    $paid = floatval($r['paid_amount']);
                    $total_debit += $tot;
                    $total_credit += $paid;
                    
                    $inv_label = ($r['invoice_type'] === 'credit') ? 'آجل' : 'نقداً';
                    $transactions[] = [
                        'date' => $r['invoice_date'],
                        'doc_no' => '#' . ($r['invoice_no'] ?: $r['id']),
                        'doc_type' => 'فاتورة مبيعات (' . $inv_label . ')',
                        'debit' => $tot,
                        'credit' => $paid,
                        'notes' => $r['remark'] ?: 'فاتورة مبيعات للعميل'
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
                    $key = 'sale_' . $r['id'];
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

        // --- 2b. مردودات المبيعات (sales_returns_mst) ---
        $chk_srm = $conn->query("SHOW TABLES LIKE 'sales_returns_mst'");
        if ($chk_srm && $chk_srm->num_rows > 0) {
            $w = "(cust_id = $account_id OR cust_name = '$name_esc' OR cust_name LIKE '%$name_esc%') AND d_s = 0";
            if (!empty($from_date)) $w .= " AND return_date >= '" . $conn->real_escape_string($from_date) . "'";
            if (!empty($to_date))   $w .= " AND return_date <= '" . $conn->real_escape_string($to_date) . "'";

            $res = $conn->query("SELECT id, return_no, return_date, total_amount, reason FROM sales_returns_mst WHERE $w");
            if ($res) {
                while ($r = $res->fetch_assoc()) {
                    $key = 'sale_return_' . $r['id'];
                    if (isset($seen_keys[$key])) continue;
                    $seen_keys[$key] = true;

                    $amt = floatval($r['total_amount']);
                    $total_credit += $amt; // مردود مبيعات = دائن (خصم من مديونية العميل)
                    $transactions[] = [
                        'date'     => $r['return_date'],
                        'doc_no'   => '#' . ($r['return_no'] ?: $r['id']),
                        'doc_type' => 'مردود مبيعات',
                        'debit'    => 0.0,
                        'credit'   => $amt,
                        'notes'    => $r['reason'] ?: 'مردود بضاعة من العميل'
                    ];
                }
            }
        }

        // --- 3. سندات القبض (receipt_vouchers_mst & receipts & mcust) ---
        $chk_rvm = $conn->query("SHOW TABLES LIKE 'receipt_vouchers_mst'");
        if ($chk_rvm && $chk_rvm->num_rows > 0) {
            $w = "(party_id = $account_id OR party_name = '$name_esc' OR party_name LIKE '%$name_esc%') AND d_s = 0";
            if (!empty($from_date)) $w .= " AND voucher_date >= '" . $conn->real_escape_string($from_date) . "'";
            if (!empty($to_date))   $w .= " AND voucher_date <= '" . $conn->real_escape_string($to_date) . "'";

            $res = $conn->query("SELECT id, voucher_no, voucher_date, total_amount, remark FROM receipt_vouchers_mst WHERE $w");
            if ($res) {
                while ($r = $res->fetch_assoc()) {
                    $key = 'receipt_' . $r['id'];
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

        $chk_rec = $conn->query("SHOW TABLES LIKE 'receipts'");
        if ($chk_rec && $chk_rec->num_rows > 0) {
            $w = "(cust_name = '$name_esc' OR cust_name LIKE '%$name_esc%') AND s = 0";
            if (!empty($from_date)) $w .= " AND q_date >= '" . $conn->real_escape_string($from_date) . "'";
            if (!empty($to_date))   $w .= " AND q_date <= '" . $conn->real_escape_string($to_date) . "'";

            $res = $conn->query("SELECT qid, q_date, q_price, remark FROM receipts WHERE $w");
            if ($res) {
                while ($r = $res->fetch_assoc()) {
                    $key = 'receipt_' . $r['qid'];
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
                    $key = 'purchase_' . $r['id'];
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

        // --- 2. فواتير المشتريات التقليدية (purchases) ---
        $chk_p = $conn->query("SHOW TABLES LIKE 'purchases'");
        if ($chk_p && $chk_p->num_rows > 0) {
            $w = "(supp_id = $account_id OR supp_name = '$name_esc' OR supp_name LIKE '%$name_esc%')";
            if (!empty($from_date)) $w .= " AND date >= '" . $conn->real_escape_string($from_date) . "'";
            if (!empty($to_date))   $w .= " AND date <= '" . $conn->real_escape_string($to_date) . "'";

            $res = $conn->query("SELECT id, date, total, remark FROM purchases WHERE $w");
            if ($res) {
                while ($r = $res->fetch_assoc()) {
                    $key = 'purchase_' . $r['id'];
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

        // --- 2b. مردودات المشتريات (purchase_returns_mst) ---
        $chk_prm = $conn->query("SHOW TABLES LIKE 'purchase_returns_mst'");
        if ($chk_prm && $chk_prm->num_rows > 0) {
            $w = "(supp_id = $account_id OR supp_name = '$name_esc' OR supp_name LIKE '%$name_esc%') AND d_s = 0";
            if (!empty($from_date)) $w .= " AND return_date >= '" . $conn->real_escape_string($from_date) . "'";
            if (!empty($to_date))   $w .= " AND return_date <= '" . $conn->real_escape_string($to_date) . "'";

            $res = $conn->query("SELECT id, return_no, return_date, total_amount, refund_method, reason FROM purchase_returns_mst WHERE $w");
            if ($res) {
                while ($r = $res->fetch_assoc()) {
                    $key = 'pur_return_' . $r['id'];
                    if (isset($seen_keys[$key])) continue;
                    $seen_keys[$key] = true;

                    $amt = floatval($r['total_amount']);
                    $method_label = ($r['refund_method'] === 'cash') ? 'نقداً' : 'آجل (خصم من الذمة)';
                    // مردود مشتريات = مدين (يُقلّل من ما نستحقه على المورد)
                    $total_debit += $amt;
                    $transactions[] = [
                        'date'     => $r['return_date'],
                        'doc_no'   => '#' . ($r['return_no'] ?: $r['id']),
                        'doc_type' => 'مردود مشتريات (' . $method_label . ')',
                        'debit'    => $amt,
                        'credit'   => 0.0,
                        'notes'    => $r['reason'] ?: 'مردود بضاعة للمورد'
                    ];
                }
            }
        }

        // --- 3. سندات الصرف للمورد (payment_vouchers_mst & treasury_expenses & bush) ---
        $chk_pvm = $conn->query("SHOW TABLES LIKE 'payment_vouchers_mst'");
        if ($chk_pvm && $chk_pvm->num_rows > 0) {
            $w = "(party_id = $account_id OR party_name = '$name_esc' OR party_name LIKE '%$name_esc%') AND d_s = 0";
            if (!empty($from_date)) $w .= " AND voucher_date >= '" . $conn->real_escape_string($from_date) . "'";
            if (!empty($to_date))   $w .= " AND voucher_date <= '" . $conn->real_escape_string($to_date) . "'";

            $res = $conn->query("SELECT id, voucher_no, voucher_date, total_amount, remark FROM payment_vouchers_mst WHERE $w");
            if ($res) {
                while ($r = $res->fetch_assoc()) {
                    $key = 'payment_' . $r['id'];
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

        $chk_bush = $conn->query("SHOW TABLES LIKE 'bush'");
        if ($chk_bush && $chk_bush->num_rows > 0) {
            $w = "(supp_name = '$name_esc' OR supp_name LIKE '%$name_esc%')";
            if (!empty($from_date)) $w .= " AND bush_date >= '" . $conn->real_escape_string($from_date) . "'";
            if (!empty($to_date))   $w .= " AND bush_date <= '" . $conn->real_escape_string($to_date) . "'";

            $res = $conn->query("SELECT bush_id, bush_date, bush_price, remark FROM bush WHERE $w");
            if ($res) {
                while ($r = $res->fetch_assoc()) {
                    $key = 'payment_' . $r['bush_id'];
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

// حساب صافي الرصيد النهائي الموحد على مستوى النظام بالكامل (تطابق 100% مع شاشة إدارة العملاء والموردين)
if ($account_type === 'customer') {
    $net_balance_diff = $current_balance;
    $balance_status = ($net_balance_diff >= 0) ? 'عليكم ' : 'لكم';
} else {
    $net_balance_diff = $current_balance;
    $balance_status = ($net_balance_diff >= 0) ? 'لكم ' : 'عليكم ';
}
?>
<title>كشف حساب تفصيلي — AQNEX ERP</title>

<style>
@media print {
    #sidebar, .navbar-top, .no-print, .filter-panel, .ptb-actions { display: none !important; }
    #content { margin: 0 !important; padding: 0 !important; }
    body { background: #fff !important; font-size: 10pt; }
    .sap-grid-table th, .sap-grid-table td { border: 1px solid #000 !important; color: #000 !important; }
}

.page-title-bar {
    display: flex; align-items: center; justify-content: space-between;
    padding: 10px 0 14px; border-bottom: 2px solid #e2e8f0;
    margin-bottom: 18px; flex-wrap: wrap; gap: 10px;
}
.page-title-bar .ptb-left { display: flex; align-items: center; gap: 10px; }
.page-title-bar .icon-wrap {
    width: 40px; height: 40px;
    background: linear-gradient(180deg, #1e293b 0%, #0f172a 100%);
    display: flex; align-items: center; justify-content: center;
    color: #fff; font-size: 1.1rem; border-radius: 6px;
}
.page-title-bar h4 { margin: 0; font-size: 1rem; font-weight: 800; color: #0f172a; }
.page-title-bar small { font-size: 0.75rem; color: #64748b; display: block; }
.page-title-bar .ptb-actions { display: flex; gap: 8px; flex-wrap: wrap; }

.filter-panel {
    background: #f8fafc; border: 1px solid #e2e8f0; padding: 16px; margin-bottom: 18px; border-radius: 8px;
}
.filter-panel .form-label { font-size: 0.75rem; font-weight: 700; color: #334155; margin-bottom: 6px; }

.kpi-row {
    display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; margin-bottom: 18px;
}
@media (max-width: 768px) { .kpi-row { grid-template-columns: repeat(1, 1fr); } }

.kpi-card {
    background: #fff; border: 1px solid #e2e8f0; padding: 14px 18px; position: relative; overflow: hidden; border-radius: 8px; box-shadow: 0 2px 6px rgba(0,0,0,0.03);
}
.kpi-card::before { content:''; position: absolute; top:0; right:0; width:5px; height:100%; }
.kpi-red::before   { background: linear-gradient(180deg, #ef4444, #b91c1c); }
.kpi-green::before { background: linear-gradient(180deg, #10b981, #059669); }
.kpi-blue::before  { background: linear-gradient(180deg, #3b82f6, #1d4ed8); }

.kpi-card .lbl { font-size: 0.75rem; font-weight: 700; color: #64748b; margin-bottom: 6px; }
.kpi-card .val { font-size: 1.3rem; font-weight: 800; color: #0f172a; line-height: 1.1; }
.kpi-card .sub-tag { font-size: 0.72rem; font-weight: 700; display: inline-block; margin-top: 4px; padding: 2px 8px; border-radius: 4px; }

/* زر / رابط عرض الوثيقة */
.doc-view-link {
    display: inline-flex; align-items: center; gap: 4px;
    color: #1e40af; font-weight: 700; text-decoration: none;
    cursor: pointer; border: none; background: none; padding: 0;
    font-size: inherit; transition: color .15s;
}
.doc-view-link:hover { color: #1d4ed8; text-decoration: underline; }
.doc-view-link svg { width: 13px; height: 13px; flex-shrink: 0; }
.doc-type-link {
    display: inline-flex; align-items: center; gap: 5px;
    color: #0f172a; font-weight: 600; text-decoration: none;
    cursor: pointer; border: none; background: none; padding: 0;
    font-size: inherit;
}
.doc-type-link:hover { color: #1d4ed8; text-decoration: underline; }
</style>

<div class="page-inner">

<!-- ترويسة المتجر الرسمية (شعار بالمنتصف + عربي يمين + إنجليزي يسار) -->
<div class="official-enterprise-header">
    <div class="header-right">
        <div class="company-title-ar"><?php echo htmlspecialchars($store_name_ar); ?></div>
        <div class="company-info-item">السجل التجاري: <strong><?php echo htmlspecialchars($cr_number); ?></strong></div>
        <div class="company-info-item">الرقم الضريبي: <strong><?php echo htmlspecialchars($tax_number); ?></strong></div>
        <div class="company-info-item">الهاتف: <strong><?php echo htmlspecialchars($phone); ?></strong></div>
        <div class="company-info-item">العنوان: <?php echo htmlspecialchars($address_ar); ?></div>
    </div>
    
    <div class="header-center">
        <img src="<?php echo htmlspecialchars($logo_src); ?>" class="company-logo-img" alt="Logo" onerror="this.src='<?php echo $dir_prefix; ?>assets/icon/tec.jpg'">
    </div>
    
    <div class="header-left">
        <div class="company-title-en"><?php echo htmlspecialchars($store_name_en); ?></div>
        <div class="company-info-item">C.R: <strong><?php echo htmlspecialchars($cr_number); ?></strong></div>
        <div class="company-info-item">VAT No: <strong><?php echo htmlspecialchars($tax_number); ?></strong></div>
        <div class="company-info-item">Tel: <strong><?php echo htmlspecialchars($phone); ?></strong></div>
        <div class="company-info-item">Addr: <?php echo htmlspecialchars($address_en); ?></div>
    </div>
</div>

<div class="official-report-banner">
    <h4>تقرير كشف حساب :<?php echo htmlspecialchars($selected_name ?: 'حساب عام'); ?></h4>
    <div class="banner-sub">
        نوع الحساب: <strong><?php echo ($account_type === 'customer') ? 'عميل (ذمم مدينة)' : 'مورد (ذمم دائنة)'; ?></strong> | 
        الفترة من: <strong><?php echo $from_date ?: 'البداية'; ?></strong> إلى: <strong><?php echo $to_date ?: date('Y-m-d'); ?></strong> | 
        تاريخ الطباعة: <strong><?php echo date('Y/m/d H:i'); ?></strong>
    </div>
</div>

<!-- رأس الصفحة -->
<div class="page-title-bar no-print">
    <div class="ptb-left">
        <div class="icon-wrap"><i class="bi bi-file-earmark-spreadsheet-fill"></i></div>
        <div>
            <h4>كشف حساب </h4>
            <small>استعراض تفصيلي للحركات المحاسبية المدينة والدائنة والرصيد التراكمي للحسابات</small>
        </div>
    </div>
    <div class="ptb-actions">
        <button onclick="window.print()" class="btn btn-sm btn-primary font-weight-bold" style="font-size:0.85rem;" title="طباعة كشف الحساب الحالي">
            <i class="bi bi-printer ml-1"></i> طباعة التقرير الكامل (F9)
        </button>
        <a href="../home.php" class="btn btn-sm btn-outline-secondary font-weight-bold" style="font-size:0.85rem;" title="العودة للرئيسية">
            <i class="bi bi-arrow-left ml-1"></i> عودة
        </a>
    </div>
</div>

<!-- فلتر الاختيار الديناميكي (عميل / مورد) مع Autocomplete -->
<div class="filter-panel no-print">
    <form method="GET" action="account_statement.php" id="statementForm">
        <div class="row align-items-end">
            <div class="col-md-3 mb-2 mb-md-0">
                <label class="form-label">نوع كشف الحساب</label>
                <select name="type" id="statementTypeSelect" class="form-control form-control-sm font-weight-bold" onchange="switchAccountType(this.value)">
                    <option value="customer" <?php echo ($account_type === 'customer') ? 'selected' : ''; ?>>عميل (ذمم مدينة)</option>
                    <option value="supplier" <?php echo ($account_type === 'supplier') ? 'selected' : ''; ?>>مورد (ذمم دائنة)</option>
                </select>
            </div>

            <div class="col-md-4 mb-2 mb-md-0">
                <label class="form-label" id="accountSearchLabel">اختيار العميل / المورد</label>
                <div class="position-relative">
                    <input type="text" name="account_name" id="accountSearchInput" list="accountsDatalist" class="form-control form-control-sm font-weight-bold" placeholder="اكتب للبحث واختيار الحساب..." autocomplete="off" value="<?php echo htmlspecialchars($selected_name); ?>" required>
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
                <button type="submit" class="btn btn-dark btn-sm btn-block font-weight-bold">
                    <i class="bi bi-search ml-1"></i> عرض
                </button>
            </div>
        </div>
    </form>
</div>

<!-- KPI Summary Cards -->
<div class="kpi-row no-print">
    <div class="kpi-card kpi-red">
        <div class="lbl">إجمالي الحركة المدينة (Debit)</div>
        <div class="val text-danger"><?php echo number_format($total_debit, 2); ?> <small style="font-size:0.65rem;">ر.ي</small></div>
        <div class="sub-tag bg-light text-danger mt-2">إجمالي المسحوبات / المشتريات</div>
    </div>
    <div class="kpi-card kpi-green">
        <div class="lbl">إجمالي الحركة الدائنة (Credit)</div>
        <div class="val text-success"><?php echo number_format($total_credit, 2); ?> <small style="font-size:0.65rem;">ر.ي</small></div>
        <div class="sub-tag bg-light text-success mt-2">إجمالي المقبوضات / التسديدات</div>
    </div>
    <div class="kpi-card kpi-blue">
        <div class="lbl">صافي الرصيد المستحق (Net Balance)</div>
        <div class="val text-primary"><?php echo number_format(abs($net_balance_diff), 2); ?> <small style="font-size:0.65rem;">ر.ي</small></div>
        <div class="sub-tag bg-primary text-white mt-2"><?php echo $balance_status; ?></div>
    </div>
</div>

<!-- جدول كشف الحساب التفصيلي الموحد (SAP Grid Table) -->
<div class="card-flat">
    <div class="card-header d-flex align-items-center justify-content-between">
        <h5 class="m-0 font-weight-bold text-dark"><i class="bi bi-journal-text ml-2 text-primary"></i>كشف حركات الحساب التفصيلي: <strong class="text-primary"><?php echo htmlspecialchars($selected_name ?: 'حساب عام'); ?></strong></h5>
        <span class="badge badge-secondary px-3 py-2 font-weight-bold">عدد الحركات: <?php echo count($transactions); ?></span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="report-table sap-grid-table">
                <thead>
                    <tr>
                        <th style="width:10%;">التاريخ</th>
                        <th style="width:15%;">رقم المستند</th>
                        <th style="width:20%;">نوع الحركة / المستند</th>
                        <th style="width:13%;">مدين (Debit)</th>
                        <th style="width:13%;">دائن (Credit)</th>
                        <th style="width:16%;">الرصيد التراكمي</th>
                        <th style="width:13%;">البيان / ملاحظات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($transactions)): 
                        $running = 0.0;
                        foreach ($transactions as $t):
                            if ($account_type === 'customer') {
                                $running += ($t['debit'] - $t['credit']);
                                $r_status = ($running >= 0) ? 'لكم' : 'عليهم';
                            } else {
                                $running += ($t['credit'] - $t['debit']);
                                $r_status = ($running >= 0) ? 'عليكم' : 'لكم';
                            }
                            $raw_doc_no = trim(str_replace('#', '', $t['doc_no']));
                    ?>
                    <tr>
                        <td class="text-center font-monospace font-weight-bold"><?php echo htmlspecialchars($t['date']); ?></td>
                        <td class="text-center">
                            <button type="button" class="doc-view-link " title="عرض الوثيقة" onclick="openDocumentViewerModal('<?php echo htmlspecialchars($t['doc_type']); ?>', '<?php echo htmlspecialchars($raw_doc_no); ?>', '<?php echo htmlspecialchars($t['doc_type']); ?>');">
                                <svg viewBox="0 0 24 24" fill="none" class="no-print" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                <?php echo htmlspecialchars($t['doc_no']); ?>
                            </button>
                        </td>
                        <td>
                            <button type="button" class="doc-type-link" title="عرض الوثيقة" onclick="openDocumentViewerModal('<?php echo htmlspecialchars($t['doc_type']); ?>', '<?php echo htmlspecialchars($raw_doc_no); ?>', '<?php echo htmlspecialchars($t['doc_type']); ?>');">
                                <?php echo htmlspecialchars($t['doc_type']); ?>
                                <svg viewBox="0 0 24 24" fill="none" class="no-print" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:12px;height:12px;opacity:.5;"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                            </button>
                        </td>
                        <td class="text-center font-weight-bold text-danger"><?php echo $t['debit'] > 0 ? number_format($t['debit'], 2) : '—'; ?></td>
                        <td class="text-center font-weight-bold text-success"><?php echo $t['credit'] > 0 ? number_format($t['credit'], 2) : '—'; ?></td>
                        <td class="text-center font-weight-bold text-primary" style="background:#f8fafc;">
                            <?php echo number_format(abs($running), 2); ?> ر.ي <small class="text-muted">(<?php echo $r_status; ?>)</small>
                        </td>
                        <td class="text-muted small"><?php echo htmlspecialchars($t['notes']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php else: ?>
                    <tr>
                        <td colspan="8" class="text-center py-4 text-muted font-weight-bold">لا توجد حركات مسجلة لهذا الحساب خلال الفترة المحددة</td>
                    </tr>
                    <?php endif; ?>
                </tbody>
                <tfoot style="background: #f1f5f9; font-weight: bold;">
                    <tr>
                        <td colspan="3" class="text-right font-weight-bold">الإجمالي الكلي للحركات المحاسبية:</td>
                        <td class="text-center text-danger font-weight-bold" style="font-size:1rem;"><?php echo number_format($total_debit, 2); ?> ر.ي</td>
                        <td class="text-center text-success font-weight-bold" style="font-size:1rem;"><?php echo number_format($total_credit, 2); ?> ر.ي</td>
                        <td class="text-center text-primary font-weight-bold" style="font-size:1rem;"><?php echo number_format(abs($net_balance_diff), 2); ?> ر.ي (<?php echo $balance_status; ?>)</td>
                        <td colspan="1">-</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

<!-- تذييل الاعتماد والتوقيع المحاسبي الرسمي للطباعة -->
<div class="sap-signature-block">
    <div>
        <div>إعداد المحاسب المختص</div>
        <div style="margin-top: 30px;">التوقيع: ..................</div>
    </div>
    <div>
        <div>مراجعة وتدقيق الحسابات</div>
        <div style="margin-top: 30px;">التوقيع: ..................</div>
    </div>
    <div>
        <div>اعتماد المدير المالي / العام</div>
        <div style="margin-top: 30px;">التوقيع: ..................</div>
    </div>
</div>

</div><!-- end .page-inner -->

<script>
const customersList = <?php echo json_encode($customers_list); ?>;
const suppliersList = <?php echo json_encode($suppliers_list); ?>;

function switchAccountType(type) {
    const datalist = document.getElementById('accountsDatalist');
    const input = document.getElementById('accountSearchInput');
    const label = document.getElementById('accountSearchLabel');
    input.value = '';
    datalist.innerHTML = '';

    if (label) {
        label.textContent = (type === 'customer') ? 'اختيار العميل' : 'اختيار المورد';
    }

    const list = (type === 'customer') ? customersList : suppliersList;
    list.forEach(item => {
        const opt = document.createElement('option');
        opt.value = item.name;
        datalist.appendChild(opt);
    });
}
</script>

<?php require_once($dir_prefix . 'includes/footer.php'); ?>
