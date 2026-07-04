<?php
$dir_prefix = '../';
$module = 'reports';
require_once($dir_prefix . 'includes/header.php');

check_permission(['admin']);
date_default_timezone_set("Asia/Aden");
$today = date("Y-m-d");

// جلب معرف الصندوق الرئيسي ديناميكياً لتجنب تعارض الـ ID
$res_main = $conn->query("SELECT box_id FROM treasury WHERE name = 'الصندوق الرئيسي' LIMIT 1");
$main_box_id = ($res_main && $res_main->num_rows > 0) ? intval($res_main->fetch_assoc()['box_id']) : 2;

$selected_box_id = isset($_GET['box_id']) ? intval($_GET['box_id']) : $main_box_id;

// جلب تفاصيل حركات المبيعات اليوم
$sql = "SELECT si.* FROM sales_items si JOIN sales s ON si.sales_id = s.id WHERE s.build_date='$today' AND s.box_id = $selected_box_id ORDER BY si.id DESC";
$result = $conn->query($sql);

// جلب تفاصيل الأرباح اليوم
$sql9 = "SELECT * FROM sales WHERE build_date='$today' AND box_id = $selected_box_id ORDER BY id DESC";
$result9 = $conn->query($sql9);

// جلب تفاصيل المشتريات اليوم
$sql1 = "SELECT * FROM purchases WHERE date='$today' AND box_id = $selected_box_id ORDER BY id DESC";
$result1 = $conn->query($sql1);

// جلب تفاصيل المصروفات اليوم
$sql2 = "SELECT * FROM treasury_expenses WHERE s='0' AND sdate='$today' AND box_id = $selected_box_id ORDER BY sid DESC";
$result2 = $conn->query($sql2);

// جلب تفاصيل المقبوضات اليوم
$sql3 = "SELECT * FROM receipts WHERE s='0' AND q_date='$today' AND box_id = $selected_box_id ORDER BY qid DESC";
$result3 = $conn->query($sql3);

// حساب المجاميع
// 1. إجمالي المقبوضات اليوم
$sql4 = "SELECT SUM(q_price) as total_receipts FROM receipts WHERE q_date='$today' AND box_id = $selected_box_id";
$row4 = $conn->query($sql4)->fetch_assoc();
$total_receipts = isset($row4['total_receipts']) ? floatval($row4['total_receipts']) : 0.0;

// 2. إجمالي المقبوض فوراً من المبيعات اليوم
$sql5 = "SELECT SUM(si.bush) as total_sales_cash FROM sales_items si JOIN sales s ON si.sales_id = s.id WHERE s.build_date='$today' AND s.box_id = $selected_box_id";
$row5 = $conn->query($sql5)->fetch_assoc();
$total_sales_cash = isset($row5['total_sales_cash']) ? floatval($row5['total_sales_cash']) : 0.0;

// 3. إجمالي المشتريات اليوم (بعد خصم مردودات المشتريات)
$sql6 = "SELECT COALESCE(SUM(p.total),0) - COALESCE(SUM(pr.refund_amount),0) as total_purchases FROM purchases p LEFT JOIN (SELECT purchase_id, SUM(refund_amount) as refund_amount FROM purchase_returns WHERE status='active' AND return_date='$today' GROUP BY purchase_id) pr ON pr.purchase_id = p.id WHERE p.date='$today' AND p.box_id = $selected_box_id";
$row6 = $conn->query($sql6)->fetch_assoc();
$total_purchases = isset($row6['total_purchases']) ? floatval($row6['total_purchases']) : 0.0;

// 4. إجمالي المصروفات اليوم
$sql7 = "SELECT SUM(sprice) as total_expenses FROM treasury_expenses WHERE sdate='$today' AND box_id = $selected_box_id";
$row7 = $conn->query($sql7)->fetch_assoc();
$total_expenses = isset($row7['total_expenses']) ? floatval($row7['total_expenses']) : 0.0;

// 5. رصيد الصندوق الحالي
$sql8 = "SELECT mony as current_box_balance FROM treasury WHERE box_id = $selected_box_id";
$row8 = $conn->query($sql8)->fetch_assoc();
$current_box_balance = isset($row8['current_box_balance']) ? floatval($row8['current_box_balance']) : 0.0;

// 6. الأرباح قبل الخصم اليوم
$s = "SELECT SUM(prifet) as profit_before_discount FROM sales WHERE build_date='$today' AND box_id = $selected_box_id";
$r = $conn->query($s)->fetch_assoc();
$profit_before_discount = isset($r['profit_before_discount']) ? floatval($r['profit_before_discount']) : 0.0;

// 7. إجمالي الخصومات اليوم
$ss = "SELECT SUM(si.d) as total_discounts FROM sales_items si JOIN sales s ON si.sales_id = s.id WHERE s.build_date='$today' AND s.box_id = $selected_box_id";
$rr = $conn->query($ss)->fetch_assoc();
$total_discounts = isset($rr['total_discounts']) ? floatval($rr['total_discounts']) : 0.0;

// 8. تفاصيل المرتجعات اليوم
$sql_returns_today = "SELECT * FROM sales_returns WHERE return_date = '$today' AND box_id = $selected_box_id AND status = 'active' ORDER BY id DESC";
$result_returns_today = $conn->query($sql_returns_today);

$sql_returns_today_sum = "SELECT SUM(refund_amount) as total_returns, SUM(profit_impact) as total_returns_profit_impact FROM sales_returns WHERE return_date = '$today' AND box_id = $selected_box_id AND status = 'active'";
$row_returns_today_sum = $conn->query($sql_returns_today_sum)->fetch_assoc();
$total_returns_today = isset($row_returns_today_sum['total_returns']) ? floatval($row_returns_today_sum['total_returns']) : 0.0;
$total_returns_profit_impact = isset($row_returns_today_sum['total_returns_profit_impact']) ? abs(floatval($row_returns_today_sum['total_returns_profit_impact'])) : 0.0;

// حساب المرتجعات النقدية لتسوية الرصيد الافتتاحي (كل المرتجعات النقدية سواء من الصندوق أو المبيعات لأن كلاهما خروج نقدية)
$sql_cash_returns_today = "SELECT SUM(refund_amount) as total_cash_returns FROM sales_returns WHERE return_date = '$today' AND box_id = $selected_box_id AND refund_method = 'cash' AND status = 'active'";
$row_cash_returns = $conn->query($sql_cash_returns_today)->fetch_assoc();
$total_cash_returns_today = isset($row_cash_returns['total_cash_returns']) ? floatval($row_cash_returns['total_cash_returns']) : 0.0;

// الحسابات النهائية
$net_profit = $profit_before_discount - $total_discounts - $total_returns_profit_impact;

// حساب الرصيد الافتتاحي المقدر (بإضافة المرتجعات النقدية المصروفة من الصندوق لتعديل التسوية)
$total_today_additions = $total_sales_cash + $total_receipts;
$total_today_subtractions = $total_expenses + $total_purchases + $total_cash_returns_today;
$calculated_opening = $current_box_balance - $total_today_additions + $total_today_subtractions;

$net_cash_balance = $current_box_balance;
?>
<?php
$settingsRes = $conn->query("SELECT * FROM settings WHERE id = 1 LIMIT 1");
$settings = $settingsRes ? $settingsRes->fetch_assoc() : [];
$shop_name = $settings['store_name'] ?? 'تكنولوجيا فون';

$box_name_res = $conn->query("SELECT name FROM treasury WHERE box_id = $selected_box_id LIMIT 1");
$box_name = ($box_name_res && $box_name_res->num_rows > 0) ? $box_name_res->fetch_assoc()['name'] : 'الصندوق الرئيسي';
$printed_by = $_SESSION['SESS_FIRST_NAME'] ?? 'مسؤول النظام';
?>
<title>تقرير الحركة المالية اليومية الرسمي - <?php echo htmlspecialchars($shop_name); ?></title>

<style>
/* CSS مخصص لواجهة التقرير المالي الرسمي */
.official-report-header {
    background: linear-gradient(135deg, #1e293b, #0f172a);
    color: #fff;
    padding: 30px;
    border-radius: 8px;
    margin-bottom: 25px;
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.official-report-title h2 {
    font-size: 1.6rem;
    margin: 0 0 5px 0;
    font-weight: 700;
    letter-spacing: -0.025em;
    color: #f8fafc;
}
.official-report-title p {
    margin: 0;
    font-size: 0.9rem;
    color: #94a3b8;
}
.official-report-logo {
    text-align: left;
}
.official-report-logo h3 {
    margin: 0;
    font-size: 1.4rem;
    font-weight: 800;
    color: #38bdf8;
}
.official-report-logo span {
    font-size: 0.8rem;
    color: #94a3b8;
}
.report-meta-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 15px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    padding: 15px 20px;
    border-radius: 6px;
    margin-bottom: 25px;
}
.meta-item {
    font-size: 0.85rem;
    color: #475569;
}
.meta-item strong {
    color: #0f172a;
}
.stat-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 20px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05);
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.stat-card.success {
    border-left: 4px solid #10b981;
}
.stat-card.info {
    border-left: 4px solid #0ea5e9;
}
.stat-card.warning {
    border-left: 4px solid #f59e0b;
}
.stat-card h3 {
    margin: 8px 0 0 0;
    font-size: 1.6rem;
    font-weight: 700;
    color: #0f172a;
}
.stat-card h6 {
    margin: 0;
    font-size: 0.85rem;
    color: #64748b;
    font-weight: 600;
}
.stat-icon {
    font-size: 2rem;
    opacity: 0.2;
}
.signature-section {
    margin-top: 40px;
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 25px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05);
}
.signature-title {
    font-size: 1rem;
    font-weight: bold;
    color: #0f172a;
    border-bottom: 1px solid #e2e8f0;
    padding-bottom: 10px;
    margin-bottom: 25px;
}
.signature-row {
    display: flex;
    justify-content: space-between;
    gap: 20px;
}
.signature-box {
    width: 30%;
    text-align: center;
    border-top: 1px dashed #cbd5e1;
    padding-top: 12px;
    font-size: 0.88rem;
    font-weight: 600;
    color: #475569;
}

/* تنسيقات الطباعة لتقرير A4 رسمي */
@media print {
    body {
        background-color: #fff !important;
        color: #000 !important;
        font-family: Arial, sans-serif !important;
    }
    .no-print, form.no-print, .btn-flat, .card-footer, #addItemBtn, .remove-item-btn {
        display: none !important;
    }
    .official-report-header {
        background: transparent !important;
        color: #000 !important;
        padding: 0 0 15px 0 !important;
        border-bottom: 3px double #000 !important;
        border-radius: 0 !important;
        box-shadow: none !important;
        margin-bottom: 20px !important;
        display: flex !important;
        justify-content: space-between !important;
        align-items: flex-end !important;
    }
    .official-report-title h2 {
        color: #000 !important;
        font-size: 1.8rem !important;
        font-weight: bold !important;
    }
    .official-report-title p {
        color: #444 !important;
    }
    .official-report-logo h3 {
        color: #000 !important;
    }
    .official-report-logo span {
        color: #444 !important;
    }
    .report-meta-grid {
        background: transparent !important;
        border: 1px solid #000 !important;
        grid-template-columns: repeat(4, 1fr) !important;
        padding: 10px !important;
        margin-bottom: 20px !important;
    }
    .meta-item {
        font-size: 9pt !important;
        color: #000 !important;
    }
    .stat-card {
        border: 1px solid #000 !important;
        border-radius: 0 !important;
        box-shadow: none !important;
        background: transparent !important;
        color: #000 !important;
        padding: 10px !important;
        margin-bottom: 15px !important;
    }
    .stat-card.success, .stat-card.info, .stat-card.warning {
        border-left: 1px solid #000 !important;
    }
    .stat-card h3 {
        font-size: 14pt !important;
        color: #000 !important;
    }
    .stat-card h6 {
        font-size: 9pt !important;
        color: #000 !important;
    }
    .stat-icon {
        display: none !important;
    }
    table.report-table, table.table {
        width: 100% !important;
        border-collapse: collapse !important;
    }
    table.report-table th, table.report-table td, table.table th, table.table td {
        border: 1px solid #000 !important;
        padding: 6px 8px !important;
        font-size: 9.5pt !important;
        color: #000 !important;
    }
    table.report-table th, table.table th {
        background: #f1f5f9 !important;
        color: #000 !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    .signature-section {
        margin-top: 40px !important;
        border: none !important;
        box-shadow: none !important;
        padding: 0 !important;
        page-break-inside: avoid !important;
    }
    .signature-title {
        border-bottom: 1px solid #000 !important;
        color: #000 !important;
    }
    .signature-box {
        border-top: 1px dashed #000 !important;
        color: #000 !important;
    }
}
</style>

<!-- الهيدر الرسمي للتقرير المالي -->
<div class="official-report-header">
    <div class="official-report-title">
        <h2>تقرير الحركة المالية اليومية الرسمي</h2>
        <p>مسودة تسوية العمليات النقدية والآجلة ليوم واحد</p>
    </div>
    <div class="official-report-logo">
        <h3><?php echo htmlspecialchars($shop_name); ?></h3>
        <span>نظام إدارة المبيعات والمخازن المتكامل POS</span>
    </div>
</div>

<!-- شبكة تفاصيل التقرير والجهات -->
<div class="report-meta-grid">
    <div class="meta-item">تاريخ التقرير: <strong><?php echo $today; ?></strong></div>
    <div class="meta-item">صندوق التقرير: <strong><?php echo htmlspecialchars($box_name); ?></strong></div>
    <div class="meta-item">أعد بواسطة: <strong><?php echo htmlspecialchars($printed_by); ?></strong></div>
    <div class="meta-item">تاريخ الطباعة: <strong><?php echo date("Y-m-d H:i:s"); ?></strong></div>
</div>

<!-- أدوات التحكم والفلترة -->
<div class="row no-print mb-4 align-items-center">
    <div class="col-md-7">
        <form method="GET" class="d-flex align-items-center bg-light p-2 border rounded-0" style="gap: 12px;">
            <label class="form-label font-weight-bold text-secondary mb-0" style="white-space: nowrap;">عرض صندوق مالي آخر:</label>
            <select name="box_id" class="form-control rounded-0 form-control-sm" style="max-width: 250px;" onchange="this.form.submit()">
                <?php
                $res_b = $conn->query("SELECT box_id, name, mony FROM treasury ORDER BY box_id ASC");
                if ($res_b) {
                    while($b = $res_b->fetch_assoc()) {
                        $sel = ($b['box_id'] == $selected_box_id) ? 'selected' : '';
                        echo "<option value='{$b['box_id']}' $sel>" . htmlspecialchars($b['name']) . " (" . number_format($b['mony'], 2) . " ر.ي)</option>";
                    }
                }
                ?>
            </select>
            <span class="text-muted small">اختر لتصفية كافة تفاصيل المقبوضات والمدفوعات.</span>
        </form>
    </div>
    <div class="col-md-5 text-left">
        <button onclick="window.print()" class="btn btn-primary rounded-0 btn-sm ml-2">
            <i class="bi bi-printer ml-1"></i> طباعة التقرير (A4)
        </button>
        <a href="history.php" class="btn btn-outline-primary rounded-0 btn-sm ml-2 text-decoration-none">
            <i class="bi bi-calendar-check ml-1"></i> تقرير بالتاريخ
        </a>
        <a href="../home.php" class="btn btn-outline-secondary rounded-0 btn-sm text-decoration-none">
            <i class="bi bi-arrow-left ml-1"></i> العودة
        </a>
    </div>
</div>

<!-- تفاصيل الحركات والعمليات في جدول موحد -->
<?php
$unified_ledger = [];

// 1. المبيعات
$res_s = $conn->query("SELECT s.* FROM sales s WHERE s.build_date = '$today' AND s.box_id = $selected_box_id AND s.delete_status = 0 ORDER BY s.id ASC");
if ($res_s) {
    while($row = $res_s->fetch_assoc()) {
        $unified_ledger[] = [
            'time' => date('H:i A', strtotime($row['created_at'] ?? $today)),
            'ref' => 'INV-' . $row['id'],
            'type' => 'مبيعات',
            'party' => $row['cust_name'] ?: 'عميل نقدي',
            'desc' => $row['remark'] ?: 'فاتورة مبيعات نقدية وآجلة',
            'in' => floatval($row['total']),
            'out' => 0.0,
            'color' => 'success'
        ];
    }
}

// 2. المشتريات
$res_p = $conn->query("SELECT p.* FROM purchases p WHERE p.date = '$today' AND p.box_id = $selected_box_id ORDER BY p.id ASC");
if ($res_p) {
    while($row = $res_p->fetch_assoc()) {
        $unified_ledger[] = [
            'time' => date('H:i A', strtotime($row['created_at'] ?? $today)),
            'ref' => 'PUR-' . $row['id'],
            'type' => 'مشتريات',
            'party' => $row['supp_name'] ?: 'مورد عام',
            'desc' => $row['remark'] ?: 'فاتورة مشتريات وتوريد بضائع',
            'in' => 0.0,
            'out' => floatval($row['total']),
            'color' => 'info'
        ];
    }
}

// 3. المصروفات
$res_e = $conn->query("SELECT e.* FROM treasury_expenses e WHERE e.s = '0' AND e.sdate = '$today' AND e.box_id = $selected_box_id ORDER BY e.sid ASC");
if ($res_e) {
    while($row = $res_e->fetch_assoc()) {
        $unified_ledger[] = [
            'time' => date('H:i A', strtotime($row['created_at'] ?? $today)),
            'ref' => 'EXP-' . $row['sid'],
            'type' => 'مصروفات (' . $row['st'] . ')',
            'party' => $row['sname'] ?: 'مصروف عام',
            'desc' => $row['sremark'] ?: 'سند صرف مصروفات',
            'in' => 0.0,
            'out' => floatval($row['sprice']),
            'color' => 'danger'
        ];
    }
}

// 4. المقبوضات
$res_r = $conn->query("SELECT r.* FROM receipts r WHERE r.s = '0' AND r.q_date = '$today' AND r.box_id = $selected_box_id ORDER BY r.qid ASC");
if ($res_r) {
    while($row = $res_r->fetch_assoc()) {
        $unified_ledger[] = [
            'time' => date('H:i A', strtotime($row['created_at'] ?? $today)),
            'ref' => 'RCT-' . $row['qid'],
            'type' => 'مقبوضات',
            'party' => $row['cust_name'] ?: 'عميل',
            'desc' => $row['remark'] ?: 'سند قبض نقدية',
            'in' => floatval($row['q_price']),
            'out' => 0.0,
            'color' => 'primary'
        ];
    }
}

// 5. مرتجعات المبيعات
$res_ret = $conn->query("SELECT sr.* FROM sales_returns sr WHERE sr.return_date = '$today' AND sr.box_id = $selected_box_id AND sr.status = 'active' ORDER BY sr.id ASC");
if ($res_ret) {
    while($row = $res_ret->fetch_assoc()) {
        $unified_ledger[] = [
            'time' => date('H:i A', strtotime($row['created_at'] ?? $today)),
            'ref' => 'RET-' . $row['id'],
            'type' => 'مرتجع مبيعات',
            'party' => 'فاتورة مبيعات #' . $row['sales_id'],
            'desc' => ($row['reason'] ?: 'مرتجع سلع مباعة') . ' (' . ($row['refund_method'] === 'cash' ? 'نقدي' : 'آجل') . ')',
            'in' => 0.0,
            'out' => floatval($row['refund_amount']),
            'color' => 'warning'
        ];
    }
}

// ترتيب الحركات زمنياً
usort($unified_ledger, function($a, $b) {
    return strcmp($a['time'], $b['time']);
});
?>

<div class="card-flat mb-4">
    <div class="card-header bg-light d-flex justify-content-between align-items-center">
        <h5 class="mb-0 text-secondary font-weight-bold">
            <i class="fa fa-list-alt ml-2"></i> دفتر الحركات المالية اليومي الموحد (القيود الزمنية)
        </h5>
        <span class="badge badge-secondary no-print">إجمالي الحركات: <?php echo count($unified_ledger); ?></span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="report-table mb-0">
                <thead>
                    <tr>
                        <th style="width: 10%;">الوقت</th>
                        <th style="width: 12%;">رقم المرجع</th>
                        <th style="width: 15%;">نوع العملية</th>
                        <th style="width: 20%;">الحساب / الجهة المستلمة</th>
                        <th>البيان والتفاصيل</th>
                        <th style="width: 15%;" class="text-right">وارد / مدين (In)</th>
                        <th style="width: 15%;" class="text-right">صادر / دائن (Out)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($unified_ledger)): ?>
                        <tr>
                            <td colspan="7" class="text-center text-muted py-4">لا توجد أي حركات مالية مسجلة اليوم لهذا الصندوق.</td>
                        </tr>
                    <?php else: ?>
                        <?php 
                        $sum_in = 0;
                        $sum_out = 0;
                        foreach ($unified_ledger as $tx): 
                            $sum_in += $tx['in'];
                            $sum_out += $tx['out'];
                        ?>
                            <tr>
                                <td class="text-muted small"><?php echo htmlspecialchars($tx['time']); ?></td>
                                <td class="font-weight-bold">#<?php echo htmlspecialchars($tx['ref']); ?></td>
                                <td>
                                    <span class="badge badge-<?php echo $tx['color']; ?> p-1 font-weight-normal" style="font-size: 11px;">
                                        <?php echo htmlspecialchars($tx['type']); ?>
                                    </span>
                                </td>
                                <td class="font-weight-bold text-secondary text-right pr-3"><?php echo htmlspecialchars($tx['party']); ?></td>
                                <td class="text-right pr-3 text-muted"><?php echo htmlspecialchars($tx['desc']); ?></td>
                                <td class="text-success font-weight-bold text-right pl-3">
                                    <?php echo $tx['in'] > 0 ? number_format($tx['in'], 2) : '-'; ?>
                                </td>
                                <td class="text-danger font-weight-bold text-right pl-3">
                                    <?php echo $tx['out'] > 0 ? number_format($tx['out'], 2) : '-'; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                <?php if (!empty($unified_ledger)): ?>
                <tfoot>
                    <tr style="background: #f8fafc; font-weight: bold;">
                        <td colspan="5" class="text-left font-weight-bold">مجموع حركات اليوم:</td>
                        <td class="text-success text-right pl-3" style="font-size: 1.05rem;"><?php echo number_format($sum_in, 2); ?> ر.ي</td>
                        <td class="text-danger text-right pl-3" style="font-size: 1.05rem;"><?php echo number_format($sum_out, 2); ?> ر.ي</td>
                    </tr>
                    <tr style="background: #f1f5f9; font-weight: bold;">
                        <td colspan="5" class="text-left font-weight-bold">صافي التدفق النقدي للحركات:</td>
                        <td colspan="2" class="text-center font-weight-bold" style="font-size: 1.1rem; color: #1e293b;">
                            <?php 
                            $net_flow = $sum_in - $sum_out;
                            echo number_format(abs($net_flow), 2) . ' ر.ي ' . ($net_flow >= 0 ? '(فائض وارد)' : '(عجز صادر)');
                            ?>
                        </td>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>
</div>

<!-- بطاقات الموازين المالية -->
<div class="row mb-4">
    <div class="col-md-4">
        <div class="stat-card info">
            <div class="stat-info">
                <h6>رصيد الصندوق الدفتري المتوقع</h6>
                <h3><?php echo number_format($net_cash_balance, 2); ?> ر.ي</h3>
            </div>
            <div class="stat-icon">
                <i class="fa fa-archive"></i>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="stat-card success">
            <div class="stat-info">
                <h6>صافي الأرباح اليومية</h6>
                <h3><?php echo number_format($net_profit, 2); ?> ر.ي</h3>
            </div>
            <div class="stat-icon">
                <i class="fa fa-line-chart"></i>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="stat-card warning">
            <div class="stat-info">
                <h6>الرصيد الافتتاحي التقديري</h6>
                <h3><?php echo number_format($calculated_opening, 2); ?> ر.ي</h3>
            </div>
            <div class="stat-icon">
                <i class="fa fa-bank"></i>
            </div>
        </div>
    </div>
</div>

<!-- الميزان والحسابات المزدوجة -->
<div class="card-flat mb-4">
    <div class="card-header bg-light">
        <h5 class="font-weight-bold text-dark mb-0"><i class="fa fa-calculator ml-2"></i> ميزان المراجعة اليومي التقديري (مدين / دائن)</h5>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table report-table mb-0">
                <thead>
                    <tr>
                        <th>الحساب / البيان المالي للعملية</th>
                        <th class="text-right">مدين (Dr)</th>
                        <th class="text-right">دائن (Cr)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $dr_opening = max(0, $calculated_opening);
                    $cr_opening = max(0, -$calculated_opening);
                    $dr_sales_cash = $total_sales_cash;
                    $dr_receipts = $total_receipts;
                    $cr_purchases = $total_purchases;
                    $cr_expenses = $total_expenses;
                    $cr_discounts = $total_discounts;
                    $cr_returns = $total_cash_returns_today;

                    $total_dr = $dr_opening + $dr_sales_cash + $dr_receipts;
                    $total_cr = $cr_purchases + $cr_expenses + $cr_discounts + $cr_returns + $cr_opening;
                    ?>
                    <tr>
                        <td>الرصيد الافتتاحي التقديري للصندوق المالي</td>
                        <td class="text-right"><?php echo number_format($dr_opening, 2); ?></td>
                        <td class="text-right"><?php echo number_format($cr_opening, 2); ?></td>
                    </tr>
                    <tr>
                        <td>متحصلات المبيعات النقدية الفورية</td>
                        <td class="text-right"><?php echo number_format($dr_sales_cash, 2); ?></td>
                        <td class="text-right">0.00</td>
                    </tr>
                    <tr>
                        <td>مقبوضات سندات القبض (مستحقات العملاء)</td>
                        <td class="text-right"><?php echo number_format($dr_receipts, 2); ?></td>
                        <td class="text-right">0.00</td>
                    </tr>
                    <tr>
                        <td>المدفوع للمشتريات / بضاعة وتوريدات</td>
                        <td class="text-right">0.00</td>
                        <td class="text-right"><?php echo number_format($cr_purchases, 2); ?></td>
                    </tr>
                    <tr>
                        <td>المدفوعات والمصروفات العامة والتشغيلية</td>
                        <td class="text-right">0.00</td>
                        <td class="text-right"><?php echo number_format($cr_expenses, 2); ?></td>
                    </tr>
                    <tr>
                        <td>إجمالي الخصومات الممنوحة للعملاء في الفواتير</td>
                        <td class="text-right">0.00</td>
                        <td class="text-right"><?php echo number_format($cr_discounts, 2); ?></td>
                    </tr>
                    <tr>
                        <td>مرتجع المبيعات النقدي المصروف للعملاء</td>
                        <td class="text-right">0.00</td>
                        <td class="text-right"><?php echo number_format($cr_returns, 2); ?></td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr style="font-weight: bold; background-color: #f8fafc;">
                        <th class="text-right">الإجمالي الكلي لليوم:</th>
                        <th class="text-right text-success" style="font-size: 1.05rem;"><?php echo number_format($total_dr, 2); ?> ر.ي</th>
                        <th class="text-right text-danger" style="font-size: 1.05rem;"><?php echo number_format($total_cr, 2); ?> ر.ي</th>
                    </tr>
                    <tr style="font-weight: bold; background-color: #f1f5f9;">
                        <th class="text-right">صافي رصيد التسوية النهائي:</th>
                        <?php $net = $total_dr - $total_cr; ?>
                        <th class="text-center" colspan="2" style="font-size: 1.1rem; color: #0f172a;">
                            <?php echo number_format(abs($net), 2); ?> ر.ي &nbsp; <?php echo ($net >= 0) ? 'رصيد دائن فائض (لكم)' : 'رصيد مدين عجز (عليكم)'; ?>
                        </th>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
    <div class="card-footer no-print text-left bg-white border-top-0 pt-3">
        <a href="../box/close.php?box_id=<?php echo $selected_box_id; ?>" class="btn btn-success rounded-0 font-weight-bold" title="إقفال الوردية ومطابقة النقدية للصندوق">
            <i class="fa fa-bank ml-1"></i> إقفال الوردية ومطابقة النقدية الفورية للصندوق
        </a>
    </div>
</div>

<!-- قسم الاعتمادات والتوقيعات الرسمية للتقرير المالي -->
<div class="signature-section">
    <div class="signature-title">الاعتمادات والمطابقة الرسمية للحركة المالية:</div>
    <div class="signature-row">
        <div class="signature-box">توقيع أمين الصندوق / الكاشير</div>
        <div class="signature-box">المحاسب المالي المسؤول</div>
        <div class="signature-box">اعتماد وإقرار المدير العام</div>
    </div>
</div>

<?php
require_once($dir_prefix . 'includes/footer.php');
?>


