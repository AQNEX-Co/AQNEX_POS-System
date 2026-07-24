<?php
$dir_prefix = '../';
$module = 'reports';
require_once($dir_prefix . 'includes/header.php');

check_permission(['admin', 'reports', 'suppliers']);

// ==========================================
// الفلاتر واستعلامات البيانات
// ==========================================
$search = isset($_GET['search']) ? $conn->real_escape_string(trim($_GET['search'])) : '';
$balance_status = isset($_GET['balance_status']) ? $_GET['balance_status'] : 'all';

$where_clauses = ["s.d_s = 0"];
if (!empty($search)) {
    $where_clauses[] = "(s.supp_name LIKE '%$search%' OR s.phone LIKE '%$search%' OR s.company_name LIKE '%$search%')";
}

if ($balance_status === 'daain') {
    $where_clauses[] = "(s.supp_daain - s.supp_madeen) > 0"; // له علينا مبالغ
} elseif ($balance_status === 'madeen') {
    $where_clauses[] = "(s.supp_madeen - s.supp_daain) > 0"; // عليه لنا مبالغ
} elseif ($balance_status === 'zero') {
    $where_clauses[] = "(s.supp_daain - s.supp_madeen) = 0";
}

$where_sql = implode(' AND ', $where_clauses);

// 1. إحصائيات الموردين العامة
$stats_sql = "SELECT 
    COUNT(s.supp_id) as total_suppliers,
    COALESCE(SUM(s.supp_daain), 0) as total_daain,
    COALESCE(SUM(s.supp_madeen), 0) as total_madeen
FROM suppliers s
WHERE s.d_s = 0";
$res_stats = $conn->query($stats_sql);
$stats = $res_stats ? $res_stats->fetch_assoc() : ['total_suppliers' => 0, 'total_daain' => 0, 'total_madeen' => 0];

$net_supplier_balance = $stats['total_daain'] - $stats['total_madeen'];

// 2. قائمة الموردين المفصلة مع إجمالي فواتير الشراء الشاملة
$chk_pur_tbl = $conn->query("SHOW TABLES LIKE 'purchases'");
$has_pur_tbl = ($chk_pur_tbl && $chk_pur_tbl->num_rows > 0);
$chk_pim_tbl = $conn->query("SHOW TABLES LIKE 'purchase_invoices_mst'");
$has_pim_tbl = ($chk_pim_tbl && $chk_pim_tbl->num_rows > 0);

$suppliers_sql = "SELECT 
    s.*,
    (" . ($has_pim_tbl ? "(SELECT COUNT(*) FROM purchase_invoices_mst p WHERE (p.supp_id = s.supp_id OR p.supp_name = s.supp_name) AND p.d_s = 0)" : "0") . 
    ($has_pur_tbl ? " + (SELECT COUNT(*) FROM purchases pur WHERE (pur.supp_id = s.supp_id OR pur.supp_name = s.supp_name))" : "") . ") as invoice_count,
    (" . ($has_pim_tbl ? "(SELECT COALESCE(SUM(p.net_amount), 0) FROM purchase_invoices_mst p WHERE (p.supp_id = s.supp_id OR p.supp_name = s.supp_name) AND p.d_s = 0)" : "0") . 
    ($has_pur_tbl ? " + (SELECT COALESCE(SUM(pur.total), 0) FROM purchases pur WHERE (pur.supp_id = s.supp_id OR pur.supp_name = s.supp_name))" : "") . ") as total_purchases
FROM suppliers s
WHERE $where_sql
ORDER BY (s.supp_daain - s.supp_madeen) DESC";

$res_suppliers = $conn->query($suppliers_sql);
$suppliers_list = [];
if ($res_suppliers) {
    while ($row = $res_suppliers->fetch_assoc()) $suppliers_list[] = $row;
}

?>
<title>تقرير الموردين وحسابات الإجماليات الشامل — AQNEX POS</title>

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

/* ===== KPI Grid ===== */
.kpi-row {
    display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 18px;
}
@media (max-width: 768px) { .kpi-row { grid-template-columns: repeat(2, 1fr); } }

.kpi-card {
    background: #fff; border: 1px solid #e2e8f0; padding: 12px 14px;
    position: relative; overflow: hidden;
}
.kpi-card::before { content:''; position: absolute; top:0; right:0; width:4px; height:100%; }
.kpi-blue::before  { background: linear-gradient(180deg, #3b82f6, #1d4ed8); }
.kpi-red::before   { background: linear-gradient(180deg, #ef4444, #b91c1c); }
.kpi-green::before { background: linear-gradient(180deg, #10b981, #059669); }
.kpi-amber::before { background: linear-gradient(180deg, #f59e0b, #d97706); }

.kpi-card .lbl { font-size: 0.68rem; font-weight: 700; color: #64748b; margin-bottom: 4px; }
.kpi-card .val { font-size: 1.1rem; font-weight: 800; color: #0f172a; line-height: 1; }
.kpi-card .sub { font-size: 0.62rem; color: #94a3b8; margin-top: 4px; }

/* ===== Filter Panel ===== */
.filter-panel {
    background: #f8fafc; border: 1px solid #e2e8f0; padding: 12px 16px; margin-bottom: 18px;
}
.filter-panel .form-label { font-size: 0.72rem; font-weight: 700; color: #475569; margin-bottom: 4px; }

/* ===== Report Tables ===== */
.report-table {
    width: 100%; border-collapse: collapse; background: #fff;
}
.report-table thead th {
    background: #f1f5f9; color: #334155; font-size: 0.76rem;
    font-weight: 700; padding: 9px 8px; border: 1px solid #cbd5e1; text-align: center;
}
.report-table tbody td {
    padding: 7px 8px; border: 1px solid #e2e8f0; font-size: 0.78rem; vertical-align: middle;
}
.report-table tbody tr:hover { background: #f8fafc; }

.balance-daain  { color: #b91c1c; font-weight: 800; } /* له مبالغ علينا (مطلوبين له) */
.balance-madeen { color: #15803d; font-weight: 800; } /* مدين لنا بمبالغ */
.balance-zero   { color: #64748b; font-weight: 600; }
</style>

<div class="page-inner">

<!-- ترويسة الطباعة الرسمية -->
<div class="print-header text-center">
    <h3 class="font-weight-bold mb-1">التقرير السنوي / كشف الحسابات الإجمالي للموردين</h3>
    <p class="text-muted mb-0">تاريخ التقرير: <?php echo date("Y-m-d H:i"); ?> | طُبع بواسطة: <?php echo htmlspecialchars($_SESSION['SESS_FIRST_NAME'] ?? 'المدير'); ?></p>
</div>

<!-- رأس الصفحة -->
<div class="page-title-bar no-print">
    <div class="ptb-left">
        <div class="icon-wrap"><i class="bi bi-person-lines-fill"></i></div>
        <div>
            <h4>تقرير الموردين وحسابات الإجماليات</h4>
            <small>تقرير مالي شامل يوضح الأرصدة، الالتزامات المستحقة للموردين، وإجمالي فواتير الشراء</small>
        </div>
    </div>
    <div class="ptb-actions">
        <button onclick="window.print()" class="btn btn-sm btn-light" style="font-size:0.8rem; border:1px solid #cbd5e1;" title="طباعة كشف الموردين">
            <i class="bi bi-printer ml-1"></i> طباعة التقرير
        </button>
        <a href="../home.php" class="btn btn-sm btn-light text-decoration-none" style="font-size:0.8rem; border:1px solid #cbd5e1;" title="العودة للرئيسية">
            <i class="bi bi-arrow-left ml-1"></i> عودة
        </a>
    </div>
</div>

<!-- KPI Cards -->
<div class="kpi-row no-print">
    <div class="kpi-card kpi-blue">
        <div class="lbl">إجمالي الموردين</div>
        <div class="val"><?php echo number_format($stats['total_suppliers']); ?> <small style="font-size:0.6rem;">مورد</small></div>
        <div class="sub">عدد الموردين المسجلين</div>
    </div>
    <div class="kpi-card kpi-red">
        <div class="lbl">إجمالي المستحق للموردين (دائن)</div>
        <div class="val text-danger"><?php echo number_format($stats['total_daain'], 2); ?> <small style="font-size:0.6rem;">ر.ي</small></div>
        <div class="sub">ديون مستحقة للموردين</div>
    </div>
    <div class="kpi-card kpi-green">
        <div class="lbl">إجمالي المدفوع المسبق (مدين)</div>
        <div class="val text-success"><?php echo number_format($stats['total_madeen'], 2); ?> <small style="font-size:0.6rem;">ر.ي</small></div>
        <div class="sub">دفعة مقدمة / متبقي لنا</div>
    </div>
    <div class="kpi-card kpi-amber">
        <div class="lbl">صافي أرصدة الموردين المستحقة</div>
        <div class="val <?php echo $net_supplier_balance > 0 ? 'text-danger' : 'text-success'; ?>">
            <?php echo number_format($net_supplier_balance, 2); ?> <small style="font-size:0.6rem;">ر.ي</small>
        </div>
        <div class="sub">الصافي الواجب سداده للموردين</div>
    </div>
</div>

<!-- فلتر البحث -->
<div class="filter-panel no-print">
    <form method="GET" class="row align-items-end">
        <div class="col-md-5 mb-2 mb-md-0">
            <label class="form-label">البحث باسم المورد / الشركة / رقم الجوال (Autocomplete)</label>
            <input type="text" name="search" list="suppliersListDatalist" class="form-control form-control-sm font-weight-bold" placeholder="ابحث عن مورد..." autocomplete="off" value="<?php echo htmlspecialchars($search); ?>">
            <datalist id="suppliersListDatalist">
                <?php foreach ($suppliers_list as $s_item): ?>
                    <option value="<?php echo htmlspecialchars($s_item['supp_name']); ?>">
                <?php endforeach; ?>
            </datalist>
        </div>

        <div class="col-md-4 mb-2 mb-md-0">
            <label class="form-label">حالة الرصيد</label>
            <select name="balance_status" class="form-control form-control-sm">
                <option value="all" <?php echo $balance_status === 'all' ? 'selected' : ''; ?>>-- جميع الحالات --</option>
                <option value="daain" <?php echo $balance_status === 'daain' ? 'selected' : ''; ?>>دائن (له علينا مبالغ مستحقة)</option>
                <option value="madeen" <?php echo $balance_status === 'madeen' ? 'selected' : ''; ?>>مدين (عليه مبالغ/دفعة مسبقة)</option>
                <option value="zero" <?php echo $balance_status === 'zero' ? 'selected' : ''; ?>>خالي الرصيد (0.00)</option>
            </select>
        </div>
        <div class="col-md-3">
            <button type="submit" class="btn btn-primary btn-sm btn-block" style="font-weight:700;">
                <i class="bi bi-search ml-1"></i> تطبيق التصفية
            </button>
        </div>
    </form>
</div>

<!-- جدول الموردين الشامل -->
<div class="card-flat">
    <div class="card-header">
        <h5><i class="bi bi-person-badge ml-2 text-primary"></i>سجل أرصدة الموردين الإجمالي</h5>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="report-table">
                <thead>
                    <tr>
                        <th style="width:6%;">رقم المورد</th>
                        <th style="width:22%;">اسم المورد</th>
                        <th style="width:14%;">الشركة / المؤسسة</th>
                        <th style="width:12%;">رقم الجوال</th>
                        <th style="width:10%;">عدد الفواتير</th>
                        <th style="width:12%;">إجمالي المشتريات</th>
                        <th style="width:12%;">له علينا (دائن)</th>
                        <th style="width:12%;">عليه لنا (مدين)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($suppliers_list)): ?>
                        <?php foreach ($suppliers_list as $s): 
                            $daain = floatval($s['supp_daain']);
                            $madeen = floatval($s['supp_madeen']);
                        ?>
                        <tr>
                            <td class="text-center font-weight-bold text-muted">#<?php echo $s['supp_id']; ?></td>
                            <td class="font-weight-bold text-dark"><?php echo htmlspecialchars($s['supp_name']); ?></td>
                            <td><?php echo htmlspecialchars($s['company_name'] ?: '—'); ?></td>
                            <td class="text-center font-monospace"><?php echo htmlspecialchars($s['phone'] ?: '—'); ?></td>
                            <td class="text-center font-weight-bold"><?php echo number_format($s['invoice_count']); ?></td>
                            <td class="text-center font-weight-bold"><?php echo number_format($s['total_purchases'], 2); ?></td>
                            <td class="text-center <?php echo $daain > 0 ? 'balance-daain' : ''; ?>"><?php echo number_format($daain, 2); ?></td>
                            <td class="text-center <?php echo $madeen > 0 ? 'balance-madeen' : ''; ?>"><?php echo number_format($madeen, 2); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="8" class="text-center py-4 text-muted">لا توجد نتائج مطابقة لخيارات البحث</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

</div><!-- end .page-inner -->
<?php require_once($dir_prefix . 'includes/footer.php'); ?>
