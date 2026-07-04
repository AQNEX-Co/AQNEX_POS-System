<?php
$dir_prefix = './';
$module = 'dashboard';
require_once($dir_prefix . 'includes/header.php');
@include_once($dir_prefix . 'includes/modules.php');

$today      = date("Y-m-d");
$yesterday  = date('Y-m-d', strtotime('-1 day'));
$month_start= date('Y-m-01');
$month_end  = date('Y-m-t');

// دالة مساعدة آمنة
function db_val($conn, $sql, $field, $default = 0) {
    $res = $conn->query($sql);
    if (!$res || is_bool($res)) return $default;
    $row = $res->fetch_assoc();
    return $row[$field] ?? $default;
}

// إحصائيات اليوم
$box_mony       = (float) db_val($conn, "SELECT COALESCE(SUM(mony),0) as v FROM treasury", 'v');
$today_sales    = (float) db_val($conn, "SELECT COALESCE(SUM(total),0) as v FROM sales WHERE build_date='$today' AND delete_status=0", 'v');
$today_sales    = $today_sales - (float) db_val($conn, "SELECT COALESCE(SUM(sr.refund_amount),0) as v FROM sales_returns sr JOIN sales s ON sr.sales_id=s.id WHERE sr.return_date='$today' AND sr.status='active' AND s.delete_status=0", 'v');
$today_profit   = (float) db_val($conn, "SELECT COALESCE(SUM(prifet),0) as v FROM sales WHERE build_date='$today' AND delete_status=0", 'v');
// طرح تأثير أرباح مرتجعات اليوم
$today_ret_profit = (float) db_val($conn, "SELECT COALESCE(SUM(profit_impact),0) as v FROM sales_returns WHERE return_date='$today' AND status='active'", 'v');
$today_profit   = $today_profit + $today_ret_profit; // profit_impact سالب، فالجمع يعني الطرح

$invoices_count = (int)   db_val($conn, "SELECT COUNT(*) as v FROM sales WHERE build_date='$today' AND delete_status=0", 'v');
$today_expenses = (float) db_val($conn, "SELECT COALESCE(SUM(sprice),0) as v FROM treasury_expenses WHERE sdate='$today'", 'v');
$today_receipts = (float) db_val($conn, "SELECT COALESCE(SUM(q_price),0) as v FROM receipts WHERE q_date='$today'", 'v');
$today_purchases= (float) db_val($conn, "SELECT COALESCE(SUM(total),0) as v FROM purchases WHERE date='$today'", 'v');
$today_purchases = $today_purchases - (float) db_val($conn, "SELECT COALESCE(SUM(refund_amount),0) as v FROM purchase_returns WHERE return_date='$today' AND status='active'", 'v');

$yesterday_sales = (float) db_val($conn, "SELECT COALESCE(SUM(total),0) as v FROM sales WHERE build_date='$yesterday' AND delete_status=0", 'v');
$yesterday_sales = $yesterday_sales - (float) db_val($conn, "SELECT COALESCE(SUM(sr.refund_amount),0) as v FROM sales_returns sr JOIN sales s ON sr.sales_id=s.id WHERE sr.return_date='$yesterday' AND sr.status='active' AND s.delete_status=0", 'v');
$sales_change    = ($yesterday_sales > 0) ? (($today_sales - $yesterday_sales) / $yesterday_sales * 100) : 0;

// إحصائيات الشهر
$month_sales  = (float) db_val($conn, "SELECT COALESCE(SUM(total),0) as v FROM sales WHERE build_date BETWEEN '$month_start' AND '$month_end' AND delete_status=0", 'v');
$month_sales  = $month_sales - (float) db_val($conn, "SELECT COALESCE(SUM(sr.refund_amount),0) as v FROM sales_returns sr JOIN sales s ON sr.sales_id=s.id WHERE sr.return_date BETWEEN '$month_start' AND '$month_end' AND sr.status='active' AND s.delete_status=0", 'v');
$month_profit = (float) db_val($conn, "SELECT COALESCE(SUM(prifet),0) as v FROM sales WHERE build_date BETWEEN '$month_start' AND '$month_end' AND delete_status=0", 'v');
// طرح تأثير أرباح مرتجعات الشهر
$month_ret_profit = (float) db_val($conn, "SELECT COALESCE(SUM(profit_impact),0) as v FROM sales_returns WHERE return_date BETWEEN '$month_start' AND '$month_end' AND status='active'", 'v');
$month_profit   = $month_profit + $month_ret_profit; // profit_impact سالب، فالجمع يعني الطرح

// ذمم
$res_debt = $conn->query("SELECT COALESCE(SUM(cust_madeen),0) as v FROM customers WHERE d_s=0");
$debtors_total = ($res_debt && !is_bool($res_debt)) ? (float)($res_debt->fetch_assoc()['v'] ?? 0) : 0.0;
$res_sdebt = $conn->query("SELECT COALESCE(SUM(supp_daain),0) as v FROM suppliers WHERE d_s=0");
$suppliers_debt = ($res_sdebt && !is_bool($res_sdebt)) ? (float)($res_sdebt->fetch_assoc()['v'] ?? 0) : 0.0;

// إحصائيات المتجر
$total_products  = (int) db_val($conn, "SELECT COUNT(*) as v FROM products WHERE delete_status=0", 'v');
$res_cust = $conn->query("SELECT COUNT(*) as v FROM customers WHERE d_s=0"); $total_customers = ($res_cust && !is_bool($res_cust)) ? (int)($res_cust->fetch_assoc()['v'] ?? 0) : 0;
$res_supp = $conn->query("SELECT COUNT(*) as v FROM suppliers WHERE d_s=0"); $total_suppliers = ($res_supp && !is_bool($res_supp)) ? (int)($res_supp->fetch_assoc()['v'] ?? 0) : 0;

// تنبيهات المخزون
$low_stock_threshold = intval($global_settings['low_stock_threshold'] ?? 5);
$res_low = $conn->query("SELECT name, quantity, COALESCE(NULLIF(min_stock_alert, 0), $low_stock_threshold) AS min_quantity FROM products WHERE delete_status=0 AND quantity <= COALESCE(NULLIF(min_stock_alert, 0), $low_stock_threshold) ORDER BY quantity ASC LIMIT 8");
$low_stock_items = ($res_low && !is_bool($res_low)) ? $res_low->fetch_all(MYSQLI_ASSOC) : [];

// آخر المبيعات والمشتريات
$res_recent = $conn->query("SELECT id, cust_name, total, prifet, build_date FROM sales WHERE delete_status=0 ORDER BY id DESC LIMIT 8");
$recent_sales = ($res_recent && !is_bool($res_recent)) ? $res_recent->fetch_all(MYSQLI_ASSOC) : [];

$res_recent_pur = $conn->query("SELECT id, supp_name, total, date FROM purchases ORDER BY id DESC LIMIT 5");
$recent_purchases = ($res_recent_pur && !is_bool($res_recent_pur)) ? $res_recent_pur->fetch_all(MYSQLI_ASSOC) : [];

// الأقساط
$due_installments = 0;
$res_inst = $conn->query("SELECT COUNT(*) as v FROM installment_schedule WHERE due_date <= '$today' AND status='pending'");
if ($res_inst && !is_bool($res_inst)) $due_installments = (int)($res_inst->fetch_assoc()['v'] ?? 0);

// بيانات الرسم البياني
$weekly_data = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $weekly_data[$d] = ['label' => date('d/m', strtotime($d)), 'sales' => 0, 'expenses' => 0];
}
$res_ws = $conn->query("SELECT build_date, SUM(total) as s FROM sales WHERE build_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) AND delete_status=0 GROUP BY build_date");
if ($res_ws && !is_bool($res_ws)) while ($r = $res_ws->fetch_assoc()) { if (isset($weekly_data[$r['build_date']])) $weekly_data[$r['build_date']]['sales'] = $r['s']; }
$res_we = $conn->query("SELECT sdate, SUM(sprice) as e FROM treasury_expenses WHERE sdate >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) GROUP BY sdate");
if ($res_we && !is_bool($res_we)) while ($r = $res_we->fetch_assoc()) { if (isset($weekly_data[$r['sdate']])) $weekly_data[$r['sdate']]['expenses'] = $r['e']; }
$chart_labels = []; $chart_sales = []; $chart_expenses = [];
foreach ($weekly_data as $d => $v) { $chart_labels[] = $v['label']; $chart_sales[] = $v['sales']; $chart_expenses[] = $v['expenses']; }

$today_cash_returns = (float) db_val($conn, "SELECT COALESCE(SUM(refund_amount),0) as v FROM sales_returns WHERE return_date='$today' AND refund_method='cash' AND refund_source='box' AND status='active'", 'v');
$net = $today_sales + $today_receipts - $today_expenses - $today_purchases - $today_cash_returns;
$cur = $global_settings['currency'] ?? 'ر.ي';

$main_box_id = (int) db_val($conn, "SELECT box_id as v FROM treasury WHERE name = 'الصندوق الرئيسي' LIMIT 1", 'v');
if ($main_box_id <= 0) {
    $main_box_id = (int) db_val($conn, "SELECT box_id as v FROM treasury WHERE is_active = 1 ORDER BY box_id ASC LIMIT 1", 'v');
}

$res_main_box = false;
if ($main_box_id > 0) {
    $res_main_box = $conn->query("SELECT t.box_id, t.name AS box_name, t.mony AS box_balance,
           u.full_name AS cashier,
           COALESCE((SELECT SUM(s.total) FROM sales s WHERE s.box_id = t.box_id AND s.build_date='$today' AND s.delete_status=0 AND (s.pay_type='cash' OR s.pay_type IS NULL OR s.pay_type='')),0) AS today_cash_sales,
           COALESCE((SELECT SUM(r.q_price) FROM receipts r WHERE r.box_id = t.box_id AND r.q_date='$today'),0) AS today_receipts,
           COALESCE((SELECT SUM(e.sprice) FROM treasury_expenses e WHERE e.box_id = t.box_id AND e.sdate='$today'),0) AS today_expenses,
           COALESCE((SELECT SUM(p.total) FROM purchases p WHERE p.box_id = t.box_id AND p.date='$today'),0) AS today_purchases
    FROM treasury t
    LEFT JOIN users u ON t.user_id = u.userid
    WHERE t.box_id = $main_box_id
    LIMIT 1");
}
// capture SQL error for debugging when query fails
$main_box_error = '';
if ($res_main_box === false) {
    $main_box_error = $conn->error;
}
$main_box = null;
if ($res_main_box && !is_bool($res_main_box)) {
    $mb = $res_main_box->fetch_assoc();
    if ($mb) {
        $mb['live_balance'] = $mb['box_balance'] + $mb['today_cash_sales'] + $mb['today_receipts'] - $mb['today_expenses'] - $mb['today_purchases'];
        $main_box = $mb;
    }
}
$box_live_balance = $main_box ? $main_box['live_balance'] : 0.0;

// ذمم العملاء (لبطاقة KPI)
$res_debt = $conn->query("SELECT COALESCE(SUM(cust_madeen),0) as v FROM customers WHERE d_s=0");
$debtors_total = ($res_debt && !is_bool($res_debt)) ? (float)($res_debt->fetch_assoc()['v'] ?? 0) : 0.0;


?>
<title>لوحة التحكم</title>
<style>
/* dashboard overrides — لا border-radius لأن custom.css يُلغيها */
.kpi-card { background:#fff; border:1px solid #dde3ec; padding:16px 18px; height:100%; display:flex; align-items:center; gap:14px; position:relative; overflow:hidden; }
.kpi-icon { width:44px; height:44px; display:flex; align-items:center; justify-content:center; font-size:1.3rem; flex-shrink:0; border:1px solid #dde3ec; }
.kpi-icon.c1 { background:#eef2ff; color:#3730a3; border-color:#c7d2fe; }
.kpi-icon.c2 { background:#f0fdf4; color:#166534; border-color:#bbf7d0; }
.kpi-icon.c3 { background:#ecfdf5; color:#065f46; border-color:#6ee7b7; }
.kpi-icon.c4 { background:#fef2f2; color:#991b1b; border-color:#fecaca; }
.kpi-label { font-size:.75rem; color:#64748b; margin-bottom:3px; }
.kpi-value { font-size:1.4rem; font-weight:700; color:#0f172a; line-height:1.1; }
.kpi-sub   { font-size:.7rem; color:#94a3b8; margin-top:3px; }
.kpi-sub.up { color:#15803d; } .kpi-sub.dn { color:#dc2626; }
/* زر إدارة فوق كل بطاقة */
.kpi-mgmt-btn { position:absolute; top:7px; left:9px; font-size:.6rem; color:#b0bec5; text-decoration:none; border:1px solid #e8ecf0; padding:1px 6px; border-radius:2px; background:#f8fafc; transition:color .15s, border-color .15s; line-height:1.7; white-space:nowrap; }
.kpi-mgmt-btn:hover { color:#1d4ed8 !important; border-color:#1d4ed8 !important; background:#eff6ff; }


.month-strip { background:#f8fafc; border:1px solid #dde3ec; padding:12px 16px; height:100%; display:flex; align-items:center; gap:10px; }
.month-label { font-size:.72rem; color:#64748b; }
.month-value { font-size:1.1rem; font-weight:700; color:#1e293b; }

.db-panel { background:#fff; border:1px solid #dde3ec; height:100%; }
.db-panel-hdr { padding:10px 14px; border-bottom:1px solid #edf2f7; background:#f8fafc; display:flex; justify-content:space-between; align-items:center; }
.db-panel-hdr span { font-size:.82rem; font-weight:700; color:#1e293b; }
.db-panel-hdr i { color:#64748b; margin-left:6px; }
.db-panel-body { padding:14px; }
.db-panel-body.p0 { padding:0; }

.sumrow { display:flex; justify-content:space-between; align-items:center; padding:8px 14px; border-bottom:1px solid #f1f5f9; font-size:.82rem; }
.sumrow:last-child { border-bottom:none; }
.sumrow.total { background:#f8fafc; border-top:2px solid #e2e8f0; font-weight:700; }
.sumrow .lbl { color:#475569; }
.sumrow .val { font-weight:600; color:#0f172a; }

.qgrid { display:grid; grid-template-columns:1fr 1fr; gap:6px; }
.qbtn { display:flex; flex-direction:column; align-items:center; justify-content:center; gap:4px; padding:10px 6px; text-decoration:none; border:1px solid #dde3ec; background:#fff; min-height:68px; transition:background .15s; }
.qbtn:hover { background:#f1f5f9; }
.qbtn i { font-size:1.2rem; color:#475569; }
.qbtn span { font-size:.72rem; color:#334155; font-weight:600; }

.stock-item { display:flex; justify-content:space-between; align-items:center; padding:7px 14px; border-bottom:1px solid #f1f5f9; font-size:.8rem; }
.stock-item:last-child { border-bottom:none; }
.stock-badge { background:#fee2e2; color:#991b1b; font-size:.68rem; padding:2px 6px; font-weight:700; }

.db-tbl { width:100%; border-collapse:collapse; font-size:.8rem; }
.db-tbl th { background:#f8fafc; color:#475569; font-weight:600; padding:8px 12px; border-bottom:2px solid #e2e8f0; text-align:right; font-size:.74rem; white-space:nowrap; }
.db-tbl td { padding:8px 12px; border-bottom:1px solid #f1f5f9; color:#334155; vertical-align:middle; }
.db-tbl tbody tr:hover { background:#fafafa; }
.idbadge { background:#eff6ff; color:#1e40af; padding:1px 6px; font-weight:700; font-size:.76rem; display:inline-block; }

.db-link-sm { font-size:.72rem; padding:2px 8px; border:1px solid #dde3ec; background:#fff; color:#475569; text-decoration:none; display:inline-flex; align-items:center; gap:3px; }
.db-link-sm:hover { background:#f1f5f9; color:#1e293b; }

.alert-inst { background:#fffbeb; border:1px solid #fde68a; border-right:4px solid #d97706; padding:9px 14px; font-size:.82rem; color:#78350f; display:flex; align-items:center; gap:10px; margin-bottom:14px; }

.mb14 { margin-bottom:14px; }
</style>

<!-- تنبيه الأقساط -->
<?php if ($due_installments > 0): ?>
<div class="alert-inst no-print">
    <i class="bi bi-bell-fill"></i>
    <span>يوجد <strong><?php echo $due_installments; ?></strong> قسط مستحق أو متأخر.</span>
    <a href="installments/plans.php" class="db-link-sm ms-auto" style="border-color:#d97706;color:#92400e;">عرض الأقساط</a>
</div>
<?php endif; ?>

<!-- ════ بطاقات KPI ════ -->
<div class="row g-2 mb14">

    <!-- 1. الصندوق الرئيسي الحي -->
    <div class="col-xl-3 col-md-6">
        <div class="kpi-card">
            <a href="box/index.php" class="kpi-mgmt-btn"><i class="bi bi-gear-fill"></i> إدارة</a>
            <div class="kpi-icon c1"><i class="bi bi-safe2"></i></div>
            <div style="flex:1;min-width:0;">
                <div class="kpi-label">الصندوق الرئيسي</div>
                <?php $display_box_balance = $box_mony; ?>
                <div class="kpi-value" style="<?php echo $display_box_balance >= 0 ? 'color:#15803d;' : 'color:#dc2626;'; ?>"><?php echo number_format($display_box_balance, 2); ?></div>
                <div class="kpi-sub">
                    <?php if ($main_box): ?>
                        <?php echo htmlspecialchars($main_box['box_name']); ?> — <?php echo $cur; ?>
                    <?php else: ?>
                        لا يوجد صندوق نشط
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
                

    <!-- 2. مبيعات اليوم -->
    <div class="col-xl-3 col-md-6">
        <div class="kpi-card">
            <a href="sales/index.php" class="kpi-mgmt-btn"><i class="bi bi-gear-fill"></i> إدارة</a>
            <div class="kpi-icon c2"><i class="bi bi-cart-check"></i></div>
            <div>
                <div class="kpi-label">مبيعات اليوم</div>
                <div class="kpi-value"><?php echo number_format($today_sales, 0); ?></div>
                <div class="kpi-sub <?php echo $sales_change >= 0 ? 'up' : 'dn'; ?>">
                    <i class="bi bi-arrow-<?php echo $sales_change >= 0 ? 'up' : 'down'; ?>"></i>
                    <?php echo number_format(abs($sales_change), 1); ?>% عن أمس &nbsp;|&nbsp; <?php echo $invoices_count; ?> فاتورة
                </div>
            </div>
        </div>
    </div>

    <!-- 3. ذمم العملاء -->
    <div class="col-xl-3 col-md-6">
        <div class="kpi-card">
            <a href="customers/index.php" class="kpi-mgmt-btn"><i class="bi bi-gear-fill"></i> إدارة</a>
            <div class="kpi-icon c3"><i class="bi bi-person-lines-fill"></i></div>
            <div>
                <div class="kpi-label">ذمم العملاء</div>
                <div class="kpi-value" style="color:<?php echo $debtors_total > 0 ? '#b45309' : '#15803d'; ?>"><?php echo number_format($debtors_total, 0); ?></div>
                <div class="kpi-sub"><?php echo $cur; ?> — المستحق لك</div>
            </div>
        </div>
    </div>

    <!-- 4. مصروفات اليوم -->
    <div class="col-xl-3 col-md-6">
        <div class="kpi-card">
            <a href="expenses/index.php" class="kpi-mgmt-btn"><i class="bi bi-gear-fill"></i> إدارة</a>
            <div class="kpi-icon c4"><i class="bi bi-cash-coin"></i></div>
            <div>
                <div class="kpi-label">مصروفات اليوم</div>
                <div class="kpi-value"><?php echo number_format($today_expenses, 0); ?></div>
                <div class="kpi-sub">مشتريات: <?php echo number_format($today_purchases, 0); ?> <?php echo $cur; ?></div>
            </div>
        </div>
    </div>
</div>


<!-- ════ إحصائيات الشهر (مدير فقط) ════ -->
<?php if ($is_admin): ?>
<div class="row g-2 mb14">
    <div class="col-lg-3 col-6">
        <div class="month-strip">
            <i class="bi bi-calendar-check" style="font-size:1.1rem;color:#475569;flex-shrink:0;"></i>
            <div><div class="month-label">مبيعات <?php echo date('M Y'); ?></div><div class="month-value"><?php echo number_format($month_sales, 0); ?> <small style="font-weight:400;font-size:.7rem;color:#94a3b8;"><?php echo $cur; ?></small></div></div>
        </div>
    </div>
    <div class="col-lg-3 col-6">
        <div class="month-strip">
            <i class="bi bi-bar-chart" style="font-size:1.1rem;color:#475569;flex-shrink:0;"></i>
            <div><div class="month-label">أرباح <?php echo date('M Y'); ?></div><div class="month-value"><?php echo number_format($month_profit, 0); ?> <small style="font-weight:400;font-size:.7rem;color:#94a3b8;"><?php echo $cur; ?></small></div></div>
        </div>
    </div>
    <div class="col-lg-3 col-6">
        <div class="month-strip">
            <i class="bi bi-person-lines-fill" style="font-size:1.1rem;color:#475569;flex-shrink:0;"></i>
            <div><div class="month-label">ذمم العملاء</div><div class="month-value"><?php echo number_format($debtors_total, 0); ?> <small style="font-weight:400;font-size:.7rem;color:#94a3b8;"><?php echo $cur; ?></small></div></div>
        </div>
    </div>
    <div class="col-lg-3 col-6">
        <div class="month-strip">
            <i class="bi bi-truck" style="font-size:1.1rem;color:#475569;flex-shrink:0;"></i>
            <div><div class="month-label">ذمم الموردين</div><div class="month-value"><?php echo number_format($suppliers_debt, 0); ?> <small style="font-weight:400;font-size:.7rem;color:#94a3b8;"><?php echo $cur; ?></small></div></div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ════ الوسط: وصول سريع + ملخص اليوم + المخزون ════ -->
<div class="row g-2 mb14">

    <!-- وصول سريع -->
    <div class="col-lg-3 col-md-6">
        <div class="db-panel h-100">
            <div class="db-panel-hdr"><span><i class="bi bi-grid-fill"></i> وصول سريع</span></div>
            <div class="db-panel-body">
                <div class="qgrid">
                    <a href="sales/create.php"      class="qbtn"><i class="bi bi-cart4"></i><span>فاتورة مبيعات</span></a>
                    <a href="purchases/create.php"  class="qbtn"><i class="bi bi-cart-plus"></i><span>فاتورة مشتريات</span></a>
                    <a href="sales/returns.php"     class="qbtn"><i class="bi bi-arrow-return-left"></i><span>مردود مبيعات</span></a>
                    <a href="purchases/returns.php" class="qbtn"><i class="bi bi-arrow-counterclockwise"></i><span>مردود مشتريات</span></a>
                    <a href="products/index.php"    class="qbtn"><i class="bi bi-tools"></i><span>قسم الصيانة</span></a>
                    <a href="products/index.php"    class="qbtn"><i class="bi bi-boxes"></i><span>المخزون</span></a>
                    <a href="customers/index.php"   class="qbtn"><i class="bi bi-person"></i><span>العملاء</span></a>
                    <a href="products/index.php"    class="qbtn"><i class="bi bi-person-plus"></i><span>الموردين</span></a>
                    <a href="receipts/index.php"   class="qbtn"><i class="bi bi-arrow-up-left"></i><span>سندات القبض</span></a>
                    <a href="expenses/index.php"   class="qbtn"><i class="bi bi-arrow-up-right"></i><span>سندات الصرف</span></a>
                </div>
            </div>
        </div>
    </div>

    <!-- ملخص اليوم + إحصائيات المتجر -->
    <div class="col-lg-5 col-md-6">
        <div class="db-panel mb14">
            <div class="db-panel-hdr"><span><i class="bi bi-clipboard-data"></i> ملخص اليوم المالي</span></div>
            <div class="db-panel-body p0">
                <div class="sumrow"><span class="lbl"><i class="bi bi-cart-check me-1 text-muted"></i>المبيعات</span><span class="val"><?php echo number_format($today_sales, 0); ?></span></div>
                <div class="sumrow"><span class="lbl"><i class="bi bi-cash me-1 text-muted"></i>المقبوضات</span><span class="val"><?php echo number_format($today_receipts, 0); ?></span></div>
                <div class="sumrow"><span class="lbl"><i class="bi bi-receipt me-1 text-muted"></i>المصروفات</span><span class="val"><?php echo number_format($today_expenses, 0); ?></span></div>
                <div class="sumrow"><span class="lbl"><i class="bi bi-box-arrow-in-down me-1 text-muted"></i>المشتريات</span><span class="val"><?php echo number_format($today_purchases, 0); ?></span></div>
                <div class="sumrow total">
                    <span class="lbl">صافي التدفق النقدي</span>
                    <span class="val" style="color:<?php echo $net >= 0 ? '#15803d' : '#dc2626'; ?>"><?php echo ($net >= 0 ? '+' : '') . number_format($net, 0); ?></span>
                </div>
            </div>
        </div>
        <!-- <div class="db-panel">
            <div class="db-panel-hdr"><span><i class="bi bi-info-circle"></i> إحصائيات المتجر</span></div>
            <div class="db-panel-body p0">
                <div class="sumrow"><span class="lbl"><i class="bi bi-box-seam me-1 text-muted"></i>إجمالي المنتجات</span><span class="val"><?php echo number_format($total_products); ?></span></div>
                <div class="sumrow"><span class="lbl"><i class="bi bi-people me-1 text-muted"></i>إجمالي العملاء</span><span class="val"><?php echo number_format($total_customers); ?></span></div>
                <div class="sumrow"><span class="lbl"><i class="bi bi-truck me-1 text-muted"></i>إجمالي الموردين</span><span class="val"><?php echo number_format($total_suppliers); ?></span></div>
            </div>
        </div> -->
    </div>

    <!-- تنبيهات المخزون -->
    <div class="col-lg-4 col-md-12">
        <div class="db-panel h-100">
            <div class="db-panel-hdr">
                <span><i class="bi bi-exclamation-triangle"></i> تنبيهات المخزون</span>
                <?php if (!empty($low_stock_items)): ?>
                <span style="background:#fee2e2;color:#991b1b;font-size:.68rem;padding:1px 6px;font-weight:700;"><?php echo count($low_stock_items); ?></span>
                <?php endif; ?>
            </div>
            <div class="db-panel-body p0">
                <?php if (empty($low_stock_items)): ?>
                <div style="text-align:center;padding:30px 0;color:#94a3b8;">
                    <i class="bi bi-check-circle" style="font-size:1.8rem;display:block;margin-bottom:6px;color:#86efac;"></i>
                    <small>جميع المنتجات بمستويات آمنة</small>
                </div>
                <?php else: ?>
                <?php foreach ($low_stock_items as $item): ?>
                <div class="stock-item">
                    <div>
                        <div style="font-weight:600;color:#1e293b;font-size:.8rem;"><?php echo htmlspecialchars($item['name']); ?></div>
                        <div style="font-size:.7rem;color:#94a3b8;">متبقي: <strong style="color:#dc2626;"><?php echo $item['quantity']; ?></strong> | الحد: <?php echo $item['min_quantity']; ?></div>
                    </div>
                    <span class="stock-badge">منخفض</span>
                </div>
                <?php endforeach; ?>
                <div style="padding:7px 14px;text-align:center;">
                    <a href="products/inventory.php" class="db-link-sm"><i class="bi bi-arrow-left"></i> عرض التقرير</a>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php if ($is_admin): 
    $res_boxes_dashboard = $conn->query("SELECT t.box_id, t.name as box_name, t.mony as box_balance, u.username as cashier_username, u.full_name as cashier_fullname, (SELECT COALESCE(SUM(s.total), 0) FROM sales s WHERE s.box_id = t.box_id AND s.is_transferred_to_box = 0 AND s.delete_status = 0) as pending_sales FROM treasury t LEFT JOIN users u ON t.user_id = u.userid WHERE t.is_active = 1 ORDER BY t.box_id ASC");
    $boxes_dashboard = [];
    if ($res_boxes_dashboard) {
        while($b_row = $res_boxes_dashboard->fetch_assoc()) {
            $boxes_dashboard[] = $b_row;
        }
    }
?>
<div class="row g-2 mb14 no-print">
    <div class="col-lg-12">
        <div class="db-panel">
            <div class="db-panel-hdr">
                <span><i class="bi bi-wallet2"></i> أرصدة الصناديق والمبيعات المعلقة للموظفين (الدرج المالي)</span>
                <a href="box/index.php" class="db-link-sm"><i class="bi bi-arrow-left-right"></i> إدارة الصناديق والتحويلات</a>
            </div>
            <div class="db-panel-body p0">
                <div class="table-responsive">
                    <table class="db-tbl">
                        <thead>
                            <tr>
                                <th>اسم الصندوق</th>
                                <th>الموظف المسؤول</th>
                                <th>الرصيد الدفتري الحالي</th>
                                <th>مبيعات غير مرحلة (الدرج)</th>
                                <th>إجمالي عهدة الموظف</th>
                                <th class="no-print" style="width: 15%">الإجراء</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($boxes_dashboard as $box): 
                                $total_hold = $box['box_balance'] + $box['pending_sales'];
                            ?>
                            <tr>
                                <td><span class="font-weight-bold text-dark"><?php echo htmlspecialchars($box['box_name']); ?></span></td>
                                <td><?php echo htmlspecialchars($box['cashier_fullname'] ?? $box['cashier_username'] ?? 'المدير العام'); ?></td>
                                <td style="font-weight: 600; color: #1e293b;"><?php echo number_format($box['box_balance'], 2); ?> ر.ي</td>
                                <td style="font-weight: 600; color: #e11d48;"><?php echo number_format($box['pending_sales'], 2); ?> ر.ي</td>
                                <td style="font-weight: 600; color: #0284c7;"><?php echo number_format($total_hold, 2); ?> ر.ي</td>
                                <td class="no-print">
                                    <a href="box/index.php" class="db-link-sm" style="background-color: rgba(2, 132, 199, 0.08); padding: 4px 8px; border-radius: 4px; display: inline-block; text-decoration: none;"><i class="bi bi-arrow-left-right"></i> تحويل مالي</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ════ الرسم البياني ════ -->
<div class="row g-2 mb14">
    <div class="col-lg-12">
        <div class="db-panel">
            <div class="db-panel-hdr"><span><i class="bi bi-graph-up"></i> أداء المتجر — آخر 7 أيام</span></div>
            <div class="db-panel-body">
                <div style="position:relative;height:220px;width:100%;"><canvas id="weeklyChart"></canvas></div>
            </div>
        </div>
    </div>
</div>

<!-- ════ آخر المبيعات والمشتريات ════ -->
<div class="row g-2">
    <div class="col-lg-7">
        <div class="db-panel">
            <div class="db-panel-hdr">
                <span><i class="bi bi-receipt-cutoff"></i> أحدث فواتير المبيعات</span>
                <a href="sales/index.php" class="db-link-sm"><i class="bi bi-list-ul"></i> الكل</a>
            </div>
            <div class="db-panel-body p0">
                <div class="table-responsive">
                <table class="db-tbl">
                    <thead><tr>
                        <th>#</th><th>العميل</th><th>التاريخ</th><th>الإجمالي</th>
                        <?php if ($is_admin): ?><th>الربح</th><?php endif; ?>
                        <th class="no-print"></th>
                    </tr></thead>
                    <tbody>
                    <?php if (empty($recent_sales)): ?>
                    <tr><td colspan="<?php echo $is_admin ? 6 : 5; ?>" style="text-align:center;padding:24px;color:#94a3b8;">لا توجد فواتير مبيعات</td></tr>
                    <?php else: foreach ($recent_sales as $row): ?>
                    <tr>
                        <td><span class="idbadge">#<?php echo $row['id']; ?></span></td>
                        <td><?php echo $row['cust_name'] ? htmlspecialchars($row['cust_name']) : '<span style="color:#94a3b8;">نقدي</span>'; ?></td>
                        <td style="color:#64748b;font-size:.75rem;"><?php echo $row['build_date']; ?></td>
                        <td style="font-weight:600;"><?php echo number_format($row['total'], 0); ?></td>
                        <?php if ($is_admin): ?><td style="color:#15803d;font-weight:600;"><?php echo number_format($row['prifet'], 0); ?></td><?php endif; ?>
                        <td class="no-print"><a href="sales/view.php?id=<?php echo $row['id']; ?>" class="db-link-sm"><i class="bi bi-eye"></i> عرض</a></td>
                    </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="db-panel">
            <div class="db-panel-hdr">
                <span><i class="bi bi-box-arrow-in-down"></i> أحدث المشتريات</span>
                <a href="purchases/index.php" class="db-link-sm"><i class="bi bi-list-ul"></i> الكل</a>
            </div>
            <div class="db-panel-body p0">
                <div class="table-responsive">
                <table class="db-tbl">
                    <thead><tr><th>#</th><th>المورد</th><th>التاريخ</th><th>الإجمالي</th></tr></thead>
                    <tbody>
                    <?php if (empty($recent_purchases)): ?>
                    <tr><td colspan="4" style="text-align:center;padding:24px;color:#94a3b8;">لا توجد مشتريات</td></tr>
                    <?php else: foreach ($recent_purchases as $row): ?>
                    <tr>
                        <td><span class="idbadge">#<?php echo $row['id']; ?></span></td>
                        <td style="max-width:130px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo htmlspecialchars($row['supp_name'] ?: 'مورد عام'); ?></td>
                        <td style="color:#64748b;font-size:.75rem;"><?php echo $row['date']; ?></td>
                        <td style="font-weight:600;"><?php echo number_format($row['total'], 0); ?></td>
                    </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="<?php echo $dir_prefix; ?>files/chart.js"></script>
<script>
document.addEventListener("DOMContentLoaded", function () {
    new Chart(document.getElementById('weeklyChart').getContext('2d'), {
        type: 'line',
        data: {
            labels: <?php echo json_encode($chart_labels); ?>,
            datasets: [
                { label:'المبيعات',   data:<?php echo json_encode($chart_sales); ?>,    backgroundColor:'rgba(51,65,85,0.08)', borderColor:'rgba(51,65,85,0.95)', pointBackgroundColor:'#1e293b', pointBorderColor:'#fff', pointHoverRadius:6, tension:0.35, fill:true, borderWidth:2 },
                { label:'المصروفات', data:<?php echo json_encode($chart_expenses); ?>,  backgroundColor:'rgba(148,163,184,0.12)', borderColor:'rgba(148,163,184,0.95)', pointBackgroundColor:'#475569', pointBorderColor:'#fff', pointHoverRadius:6, tension:0.35, fill:true, borderWidth:2 }
            ]
        },
        options: {
            responsive:true, maintainAspectRatio:false,
            plugins:{
                legend:{ position:'top', rtl:true, labels:{boxWidth:10, padding:14, font:{size:11}, color:'#475569'} },
                tooltip:{ mode:'index', intersect:false, rtl:true, backgroundColor:'rgba(15,23,42,.95)', padding:10, bodyFont:{size:12}, titleFont:{weight:'700'} }
            },
            interaction:{ mode:'nearest', axis:'x', intersect:false },
            scales:{
                y:{ beginAtZero:true, position:'right', grid:{color:'rgba(0,0,0,.06)'}, ticks:{font:{size:11},color:'#64748b'} },
                x:{ grid:{display:false}, ticks:{font:{size:11},color:'#64748b'} }
            }
        }
    });
});
</script>
<?php require_once($dir_prefix . 'includes/footer.php'); ?>
