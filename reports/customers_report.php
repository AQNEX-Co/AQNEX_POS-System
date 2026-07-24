<?php
$dir_prefix = '../';
$module = 'reports';
require_once($dir_prefix . 'includes/header.php');

check_permission(['admin', 'reports', 'customers']);

// ==========================================
// الفلاتر واستعلامات البيانات
// ==========================================
$search = isset($_GET['search']) ? $conn->real_escape_string(trim($_GET['search'])) : '';
$debt_status = isset($_GET['debt_status']) ? $_GET['debt_status'] : 'all';

$where_clauses = ["c.d_s = 0"];
if (!empty($search)) {
    $where_clauses[] = "(c.cust_name LIKE '%$search%' OR c.phone LIKE '%$search%' OR c.address LIKE '%$search%')";
}

if ($debt_status === 'debtor') {
    $where_clauses[] = "(c.cust_madeen - c.cust_daain) > 0"; // عليه مبالغ ديون لنا
} elseif ($debt_status === 'creditor') {
    $where_clauses[] = "(c.cust_daain - c.cust_madeen) > 0"; // له مبالغ علينا (دائن)
} elseif ($debt_status === 'zero') {
    $where_clauses[] = "(c.cust_madeen - c.cust_daain) = 0";
}

$where_sql = implode(' AND ', $where_clauses);

// 1. إحصائيات العملاء العامة
$stats_sql = "SELECT 
    COUNT(c.cust_id) as total_customers,
    COALESCE(SUM(c.cust_madeen), 0) as total_madeen,
    COALESCE(SUM(c.cust_daain), 0) as total_daain
FROM customers c
WHERE c.d_s = 0";
$res_stats = $conn->query($stats_sql);
$stats = $res_stats ? $res_stats->fetch_assoc() : ['total_customers' => 0, 'total_madeen' => 0, 'total_daain' => 0];

$net_receivables = $stats['total_madeen'] - $stats['total_daain'];

// 2. قائمة العملاء المفصلة مع إجمالي فواتير المبيعات الشاملة
$chk_sales_mst = $conn->query("SHOW TABLES LIKE 'sales_invoices_mst'");
$has_sales_mst = ($chk_sales_mst && $chk_sales_mst->num_rows > 0);

$customers_sql = "SELECT 
    c.*,
    ((SELECT COUNT(*) FROM sales s WHERE (s.cust_name = c.cust_name) AND s.delete_status = 0) " . 
    ($has_sales_mst ? "+ (SELECT COUNT(*) FROM sales_invoices_mst sim WHERE (sim.cust_id = c.cust_id OR sim.cust_name = c.cust_name) AND sim.d_s = 0)" : "") . ") as sales_count,
    ((SELECT COALESCE(SUM(s.total), 0) FROM sales s WHERE (s.cust_name = c.cust_name) AND s.delete_status = 0) " . 
    ($has_sales_mst ? "+ (SELECT COALESCE(SUM(sim.net_amount), 0) FROM sales_invoices_mst sim WHERE (sim.cust_id = c.cust_id OR sim.cust_name = c.cust_name) AND sim.d_s = 0)" : "") . ") as total_sales_val
FROM customers c
WHERE $where_sql
ORDER BY (c.cust_madeen - c.cust_daain) DESC";

$res_customers = $conn->query($customers_sql);
$customers_list = [];
if ($res_customers) {
    while ($row = $res_customers->fetch_assoc()) $customers_list[] = $row;
}

?>
<title>تقرير العملاء وحسابات الإجماليات الشامل — AQNEX POS</title>

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

.balance-madeen { color: #b91c1c; font-weight: 800; } /* عليه مبالغ لنا (ديون) */
.balance-daain  { color: #15803d; font-weight: 800; } /* له مبالغ لدينا */
.balance-zero   { color: #64748b; font-weight: 600; }
</style>

<div class="page-inner">

<!-- ترويسة الطباعة الرسمية -->
<div class="print-header text-center">
    <h3 class="font-weight-bold mb-1">التقرير السنوي / كشف الحسابات الإجمالي للعملاء</h3>
    <p class="text-muted mb-0">تاريخ التقرير: <?php echo date("Y-m-d H:i"); ?> | طُبع بواسطة: <?php echo htmlspecialchars($_SESSION['SESS_FIRST_NAME'] ?? 'المدير'); ?></p>
</div>

<!-- رأس الصفحة -->
<div class="page-title-bar no-print">
    <div class="ptb-left">
        <div class="icon-wrap"><i class="bi bi-people-fill"></i></div>
        <div>
            <h4>تقرير العملاء وحسابات الإجماليات</h4>
            <small>تقرير مالي شامل يوضح ديون العملاء، إجمالي التحصيلات، وإجمالي حركة المبيعات</small>
        </div>
    </div>
    <div class="ptb-actions">
        <button onclick="window.print()" class="btn btn-sm btn-light" style="font-size:0.8rem; border:1px solid #cbd5e1;" title="طباعة كشف العملاء">
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
        <div class="lbl">إجمالي العملاء</div>
        <div class="val"><?php echo number_format($stats['total_customers']); ?> <small style="font-size:0.6rem;">عميل</small></div>
        <div class="sub">عدد العملاء المسجلين في النظام</div>
    </div>
    <div class="kpi-card kpi-red">
        <div class="lbl">إجمالي ديون العملاء (مدين)</div>
        <div class="val text-danger"><?php echo number_format($stats['total_madeen'], 2); ?> <small style="font-size:0.6rem;">ر.ي</small></div>
        <div class="sub">مبالغ مستحقة لنا لدى العملاء</div>
    </div>
    <div class="kpi-card kpi-green">
        <div class="lbl">إجمالي المبالغ الدائنة للعملاء</div>
        <div class="val text-success"><?php echo number_format($stats['total_daain'], 2); ?> <small style="font-size:0.6rem;">ر.ي</small></div>
        <div class="sub">دفعة مقدمة / رصيد دائن</div>
    </div>
    <div class="kpi-card kpi-amber">
        <div class="lbl">صافي المستحق للشركة على العملاء</div>
        <div class="val <?php echo $net_receivables > 0 ? 'text-danger' : 'text-success'; ?>">
            <?php echo number_format($net_receivables, 2); ?> <small style="font-size:0.6rem;">ر.ي</small>
        </div>
        <div class="sub">صافي المستحقات القابلة للتحصيل</div>
    </div>
</div>

<!-- فلتر البحث -->
<div class="filter-panel no-print">
    <form method="GET" class="row align-items-end">
        <div class="col-md-5 mb-2 mb-md-0">
            <label class="form-label">البحث باسم العميل / رقم الجوال / العنوان (Autocomplete)</label>
            <input type="text" name="search" list="customersListDatalist" class="form-control form-control-sm font-weight-bold" placeholder="ابحث عن عميل..." autocomplete="off" value="<?php echo htmlspecialchars($search); ?>">
            <datalist id="customersListDatalist">
                <?php foreach ($customers_list as $c_item): ?>
                    <option value="<?php echo htmlspecialchars($c_item['cust_name']); ?>">
                <?php endforeach; ?>
            </datalist>
        </div>

        <div class="col-md-4 mb-2 mb-md-0">
            <label class="form-label">حالة رصيد الدين</label>
            <select name="debt_status" class="form-control form-control-sm">
                <option value="all" <?php echo $debt_status === 'all' ? 'selected' : ''; ?>>-- جميع الحالات --</option>
                <option value="debtor" <?php echo $debt_status === 'debtor' ? 'selected' : ''; ?>>مدين (عليه ديون ومبالغ لنا)</option>
                <option value="creditor" <?php echo $debt_status === 'creditor' ? 'selected' : ''; ?>>دائن (له رصيد مدفوع مقدماً)</option>
                <option value="zero" <?php echo $debt_status === 'zero' ? 'selected' : ''; ?>>مُتزن / خالي الرصيد (0.00)</option>
            </select>
        </div>
        <div class="col-md-3">
            <button type="submit" class="btn btn-primary btn-sm btn-block" style="font-weight:700;">
                <i class="bi bi-search ml-1"></i> تطبيق التصفية
            </button>
        </div>
    </form>
</div>

<!-- جدول العملاء الشامل -->
<div class="card-flat">
    <div class="card-header">
        <h5><i class="bi bi-person-badge ml-2 text-primary"></i>سجل أرصدة العملاء الإجمالي</h5>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="report-table">
                <thead>
                    <tr>
                        <th style="width:6%;">رقم العميل</th>
                        <th style="width:24%;">اسم العميل</th>
                        <th style="width:14%;">رقم الجوال</th>
                        <th style="width:16%;">العنوان / المنطقة</th>
                        <th style="width:10%;">عدد الفواتير</th>
                        <th style="width:14%;">إجمالي المبيعات</th>
                        <th style="width:16%;">رصيد الدين (عليه / له)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($customers_list)): ?>
                        <?php foreach ($customers_list as $c): 
                            $madeen = floatval($c['cust_madeen']);
                            $daain = floatval($c['cust_daain']);
                            $net_bal = $madeen - $daain;
                        ?>
                        <tr>
                            <td class="text-center font-weight-bold text-muted">#<?php echo $c['cust_id']; ?></td>
                            <td class="font-weight-bold text-dark"><?php echo htmlspecialchars($c['cust_name']); ?></td>
                            <td class="text-center font-monospace"><?php echo htmlspecialchars($c['phone'] ?: '—'); ?></td>
                            <td><?php echo htmlspecialchars($c['address'] ?: '—'); ?></td>
                            <td class="text-center font-weight-bold"><?php echo number_format($c['sales_count']); ?></td>
                            <td class="text-center font-weight-bold"><?php echo number_format($c['total_sales_val'], 2); ?></td>
                            <td class="text-center <?php echo $net_bal > 0 ? 'balance-madeen' : ($net_bal < 0 ? 'balance-daain' : 'balance-zero'); ?>">
                                <?php 
                                if ($net_bal > 0) {
                                    echo "عليه " . number_format($net_bal, 2) . " ر.ي";
                                } elseif ($net_bal < 0) {
                                    echo "له " . number_format(abs($net_bal), 2) . " ر.ي";
                                } else {
                                    echo "0.00";
                                }
                                ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="7" class="text-center py-4 text-muted">لا توجد نتائج مطابقة لخيارات البحث</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

</div><!-- end .page-inner -->
<?php require_once($dir_prefix . 'includes/footer.php'); ?>
