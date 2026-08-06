<?php
$dir_prefix = '../';
$module = 'journal';
$report_title = 'دفتر القيود اليومية (القيد المزدوج)';
require_once($dir_prefix . 'includes/header.php');

check_permission(['admin']);

// فلاتر البحث
$search = isset($_GET['search']) ? $conn->real_escape_string($_GET['search']) : '';
$box_id = isset($_GET['box_id']) ? intval($_GET['box_id']) : 0;
$from_date = isset($_GET['from_date']) ? $conn->real_escape_string($_GET['from_date']) : '';
$to_date = isset($_GET['to_date']) ? $conn->real_escape_string($_GET['to_date']) : '';
$ref_type = isset($_GET['ref_type']) ? $conn->real_escape_string($_GET['ref_type']) : '';

// بناء جملة الاستعلام الشرطية
$where_clauses = ["1=1"];

if (!empty($search)) {
    $where_clauses[] = "(je.reference_no LIKE '%$search%' OR je.description LIKE '%$search%' OR a.name LIKE '%$search%' OR a.code LIKE '%$search%' OR ji.memo LIKE '%$search%' OR je.created_by LIKE '%$search%')";
}

if ($box_id > 0) {
    // جلب أرقام القيود المرتبطة بالصندوق من جدول القيود المساعد
    $res_box_entries = $conn->query("SELECT DISTINCT ref_type, ref_id FROM accounting_journal WHERE box_id = $box_id");
    $box_conds = ["1=0"];
    if ($res_box_entries && $res_box_entries->num_rows > 0) {
        while ($re = $res_box_entries->fetch_assoc()) {
            $t = $conn->real_escape_string($re['ref_type']);
            $rid = intval($re['ref_id']);
            $box_conds[] = "(je.source_type = '$t' AND je.source_id = $rid)";
        }
    }
    $where_clauses[] = "(" . implode(" OR ", $box_conds) . ")";
}

if (!empty($from_date)) {
    $where_clauses[] = "je.entry_date >= '$from_date'";
}

if (!empty($to_date)) {
    $where_clauses[] = "je.entry_date <= '$to_date'";
}

if (!empty($ref_type)) {
    $where_clauses[] = "je.source_type = '$ref_type'";
}

$where_sql = implode(" AND ", $where_clauses);

// استعلام جلب قيود اليومية المزدوجة المفصلة
$sql = "SELECT 
            je.id AS entry_id,
            je.entry_date,
            je.reference_no,
            je.description AS entry_desc,
            je.source_type,
            je.source_id,
            je.created_by,
            ji.id AS item_id,
            ji.debit,
            ji.credit,
            ji.memo,
            a.code AS account_code,
            a.name AS account_name
        FROM accounting_journal_entries je
        JOIN accounting_journal_items ji ON je.id = ji.entry_id
        JOIN accounting_accounts a ON ji.account_id = a.id
        WHERE $where_sql
        ORDER BY je.id DESC, ji.debit DESC";
$result = $conn->query($sql);

// إجماليات مفصلة: المدين، الدائن، الأرصدة
$sql_totals = "SELECT 
    SUM(ji.debit) AS total_debit,
    SUM(ji.credit) AS total_credit,
    COUNT(DISTINCT je.id) AS total_entries
    FROM accounting_journal_entries je
    JOIN accounting_journal_items ji ON je.id = ji.entry_id
    JOIN accounting_accounts a ON ji.account_id = a.id
    WHERE $where_sql";
$res_totals = $conn->query($sql_totals);
$totals = ($res_totals && $row_t = $res_totals->fetch_assoc()) ? $row_t : ['total_debit' => 0, 'total_credit' => 0, 'total_entries' => 0];
$total_debit = floatval($totals['total_debit']);
$total_credit = floatval($totals['total_credit']);
$net_balance = $total_debit - $total_credit;
$total_entries = intval($totals['total_entries']);

// ترجمة نوع المرجع للعربية
function translate_ref_type($type) {
    switch ($type) {
        case 'sale': return 'مبيعات';
        case 'return': return 'مرتجع مبيعات';
        case 'sales_return': return 'مرتجع مبيعات';
        case 'purchase_return': return 'مرتجع مشتريات';
        case 'purchase': return 'مشتريات';
        case 'expense': return 'مصروفات';
        case 'receipt': return 'سند قبض';
        case 'payment': return 'سند صرف';
        case 'manual': return 'قيد يدوي';
        case 'adjustment': return 'تسوية';
        default: return $type;
    }
}

// ألوان شارات مرجع القيد
function get_ref_badge_class($type) {
    switch ($type) {
        case 'sale': return 'badge-success';
        case 'return': return 'badge-warning';
        case 'purchase': return 'badge-info';
        case 'expense': return 'badge-danger';
        case 'receipt': return 'badge-primary';
        case 'payment': return 'badge-dark';
        case 'manual': return 'badge-secondary';
        case 'adjustment': return 'badge-light';
        default: return 'badge-light';
    }
}
?>

<title>دفتر القيود اليومية (القيد المزدوج) - AQNEX POS</title>

<style>
@media print {
    #sidebar, .navbar-top, .no-print, .filter-panel, .ptb-actions { display: none !important; }
    #content { margin: 0 !important; padding: 0 !important; }
    body { background: #fff !important; }
}

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

/* ===== KPI Cards ===== */
.kpi-row {
    display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 18px;
}
@media (max-width: 768px) { .kpi-row { grid-template-columns: repeat(2, 1fr); } }
.kpi-card-sm {
    background: #fff; border: 1px solid #e2e8f0; padding: 12px 14px;
    position: relative; overflow: hidden; text-align: center;
}
.kpi-card-sm::before {
    content:''; position: absolute; top:0; right:0; width: 4px; height: 100%;
}
.kpi-card-sm.kpi-red::before    { background: linear-gradient(180deg, #ef4444, #b91c1c); }
.kpi-card-sm.kpi-green::before  { background: linear-gradient(180deg, #10b981, #059669); }
.kpi-card-sm.kpi-yellow::before { background: linear-gradient(180deg, #f59e0b, #d97706); }
.kpi-card-sm.kpi-blue::before   { background: linear-gradient(180deg, #3b82f6, #1d4ed8); }
.kpi-card-sm .kpi-lbl { font-size: 0.68rem; color: #64748b; font-weight: 700; margin-bottom: 5px; }
.kpi-card-sm .kpi-val { font-size: 1.05rem; font-weight: 800; color: #0f172a; line-height: 1; }

/* ===== Filter Panel ===== */
.filter-panel {
    background: #f8fafc; border: 1px solid #e2e8f0; padding: 14px 16px; margin-bottom: 16px;
}
.filter-panel .filter-form { display: flex; flex-wrap: wrap; gap: 10px; align-items: flex-end; }
.filter-panel .filter-group { display: flex; flex-direction: column; }
.filter-panel .filter-group label { font-size: 0.72rem; font-weight: 700; color: #475569; margin-bottom: 4px; }
.filter-panel .filter-group input,
.filter-panel .filter-group select { font-size: 0.8rem; min-width: 140px; }
.filter-btn { font-size: 0.8rem; font-weight: 700; padding: 5px 14px; }

/* ===== Journal Table ===== */
.journal-table-professional {
    width: 100%; border-collapse: collapse; background: #fff;
}
.journal-table-professional thead th {
    background: linear-gradient(135deg, #1e3a8a 0%, #0f172a 100%);
    color: #fff; font-weight: 700; padding: 9px 8px;
    font-size: 0.76rem; border: 1px solid #1e3a8a; text-align: center;
}
.journal-table-professional tbody td {
    padding: 6px 5px; border: 1px solid #e5e7eb;
    vertical-align: middle; font-size: 0.75rem; line-height: 1.3;
}
.journal-table-professional tbody tr:hover { background-color: #f0f4f8; }
.col-id { text-align: center; font-weight: 700; color: #6b7280; font-family: monospace; font-size: 0.72rem; }
.col-date { text-align: center; font-size: 0.7rem; color: #4b5563; white-space: nowrap; font-family: monospace; }
.col-type { text-align: center; }
.col-account { text-align: right; font-weight: 600; font-size: 0.73rem; padding-right: 8px; }
.col-amount { text-align: center; font-weight: 700; font-family: monospace; font-size: 0.75rem; white-space: nowrap; }
.col-desc { text-align: right; font-size: 0.7rem; color: #4b5563; max-width: 200px; word-wrap: break-word; }
.col-user { text-align: center; font-size: 0.7rem; color: #6b7280; }

.amount-debit  { color: #b91c1c; background-color: #fef2f2; }
.amount-credit { color: #047857; background-color: #ecfdf5; }
.account-debit  { color: #991b1b; border-right: 3px solid #dc2626 !important; }
.account-credit { color: #065f46; border-right: 3px solid #10b981 !important; }

/* ===== Summary Footer ===== */
.journal-summary-footer {
    background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
    color: #fff; padding: 14px; margin-top: 14px;
}
.summary-grid {
    display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
    gap: 12px; text-align: center;
}
.summary-item { border-left: 1px dashed rgba(255,255,255,0.15); padding: 5px; }
.summary-item:last-child { border-left: none; }
.summary-item .label { font-size: 0.68rem; color: #94a3b8; margin-bottom: 4px; }
.summary-item .value { font-size: 1.1rem; font-weight: 800; font-family: monospace; }
.summary-item.total-debit .value  { color: #fca5a5; }
.summary-item.total-credit .value { color: #86efac; }
.summary-item.net-balance .value  { color: #fcd34d; }
.summary-item.count .value        { color: #e2e8f0; }

.ref-badge {
    display: inline-block; padding: 2px 6px;
    font-size: 0.63rem; font-weight: 700;
}
.badge-success { background:#dcfce7; color:#15803d; }
.badge-warning { background:#fef3c7; color:#b45309; }
.badge-info    { background:#dbeafe; color:#1d4ed8; }
.badge-danger  { background:#fee2e2; color:#b91c1c; }
.badge-primary { background:#eff6ff; color:#1d4ed8; }
.badge-dark    { background:#1e293b; color:#fff; }
.badge-secondary { background:#f1f5f9; color:#475569; }
.badge-light   { background:#f8fafc; color:#64748b; border:1px solid #e2e8f0; }
</style>

<div class="page-inner">

<?php
$journal_msg = isset($_GET['msg']) ? $_GET['msg'] : '';
if ($journal_msg === 'deleted'): ?>
<div class="alert alert-success rounded-0 mb-3 no-print" style="font-size:0.82rem; border-right:4px solid #15803d;">
    <i class="bi bi-check-circle-fill ml-2"></i> تم حذف القيد اليومي وتحديث الدفاتر بنجاح.
</div>
<?php elseif ($journal_msg === 'error'): ?>
<div class="alert alert-danger rounded-0 mb-3 no-print" style="font-size:0.82rem; border-right:4px solid #b91c1c;">
    <strong>خطأ:</strong> حدث خطأ أثناء العملية. حاول مرة أخرى.
</div>
<?php endif; ?>

<!-- ===== رأس الصفحة ===== -->
<div class="page-title-bar no-print">
    <div class="ptb-left">
        <div class="icon-wrap"><i class="bi bi-book"></i></div>
        <div>
            <h4>دفتر القيود اليومية — القيد المزدوج</h4>
            <small>عرض وتصفية القيود اليومية المحاسبية المسجلة في النظام</small>
        </div>
    </div>
    <div class="ptb-actions">
        <a href="journal_entry.php" class="btn btn-sm btn-success text-white text-decoration-none" style="font-size:0.8rem;" title="إضافة قيد يومية جديد">
            <i class="bi bi-plus-lg ml-1"></i> إضافة قيد
        </a>
        <button onclick="window.print()" class="btn btn-sm btn-light" style="font-size:0.8rem; border:1px solid #cbd5e1;" title="طباعة دفتر القيود">
            <i class="bi bi-printer ml-1"></i> طباعة
        </button>
        <a href="../home.php" class="btn btn-sm btn-light text-decoration-none" style="font-size:0.8rem; border:1px solid #cbd5e1;" title="العودة للرئيسية">
            <i class="bi bi-arrow-left ml-1"></i> عودة
        </a>
    </div>
</div>

<!-- ===== KPI Cards ===== -->
<div class="kpi-row no-print">
    <div class="kpi-card-sm kpi-red">
        <div class="kpi-lbl">إجمالي المدين</div>
        <div class="kpi-val"><?php echo number_format($total_debit, 2); ?> <small style="font-size:0.6rem; font-weight:400;">ر.ي</small></div>
    </div>
    <div class="kpi-card-sm kpi-green">
        <div class="kpi-lbl">إجمالي الدائن</div>
        <div class="kpi-val"><?php echo number_format($total_credit, 2); ?> <small style="font-size:0.6rem; font-weight:400;">ر.ي</small></div>
    </div>
    <div class="kpi-card-sm kpi-yellow">
        <div class="kpi-lbl">الفرق (ميزان)</div>
        <div class="kpi-val <?php echo abs($net_balance) < 0.01 ? 'text-success' : 'text-danger'; ?>">
            <?php echo ($net_balance >= 0 ? '+' : '') . number_format($net_balance, 2); ?>
        </div>
    </div>
    <div class="kpi-card-sm kpi-blue">
        <div class="kpi-lbl">عدد القيود</div>
        <div class="kpi-val"><?php echo number_format($total_entries); ?> <small style="font-size:0.6rem; font-weight:400;">قيد</small></div>
    </div>
</div>

<div class="card-flat">
    <div class="card-body p-3">

    <!-- ===== فلتر البحث ===== -->
    <div class="filter-panel no-print">
        <form method="GET" class="filter-form">
            <div class="filter-group" style="flex:2; min-width:180px;">
                <label>البحث في الحسابات والبيانات</label>
                <input type="text" name="search" class="form-control" placeholder="اسم الحساب، البيان، الموظف..." value="<?php echo htmlspecialchars($search); ?>">
            </div>
            <div class="filter-group">
                <label>نوع العملية</label>
                <select name="ref_type" class="form-control">
                    <option value="">-- الكل --</option>
                    <option value="sale" <?php echo ($ref_type == 'sale') ? 'selected' : ''; ?>>مبيعات</option>
                    <option value="return" <?php echo ($ref_type == 'return') ? 'selected' : ''; ?>>مرتجع مبيعات</option>
                    <option value="purchase" <?php echo ($ref_type == 'purchase') ? 'selected' : ''; ?>>مشتريات</option>
                    <option value="expense" <?php echo ($ref_type == 'expense') ? 'selected' : ''; ?>>مصروفات</option>
                    <option value="receipt" <?php echo ($ref_type == 'receipt') ? 'selected' : ''; ?>>إيرادات</option>
                    <option value="manual" <?php echo ($ref_type == 'manual') ? 'selected' : ''; ?>>قيود يدوي</option>
                    <option value="adjustment" <?php echo ($ref_type == 'adjustment') ? 'selected' : ''; ?>>تسويات</option>
                </select>
            </div>
            <div class="filter-group">
                <label>الصندوق المرتبط</label>
                <select name="box_id" class="form-control">
                    <option value="0">-- جميع الصناديق --</option>
                    <?php
                    $res_b = $conn->query("SELECT box_id, name FROM treasury ORDER BY box_id ASC");
                    if ($res_b) {
                        while($b = $res_b->fetch_assoc()) {
                            $sel = ($b['box_id'] == $box_id) ? 'selected' : '';
                            echo "<option value='{$b['box_id']}' $sel>" . htmlspecialchars($b['name']) . "</option>";
                        }
                    }
                    ?>
                </select>
            </div>
            <div class="filter-group">
                <label>من تاريخ</label>
                <input type="date" name="from_date" class="form-control" value="<?php echo $from_date; ?>">
            </div>
            <div class="filter-group">
                <label>إلى تاريخ</label>
                <input type="date" name="to_date" class="form-control" value="<?php echo $to_date; ?>">
            </div>
            <div class="filter-group" style="flex-direction:row; gap:6px; align-items:flex-end;">
                <button type="submit" class="btn btn-primary filter-btn">
                    <i class="bi bi-search ml-1"></i> تطبيق
                </button>
                <a href="journal.php" class="btn btn-light filter-btn text-decoration-none" style="border:1px solid #cbd5e1;">
                    <i class="bi bi-x-lg"></i>
                </a>
            </div>
        </form>
    </div>

    <!-- ===== جدول القيود اليومية ===== -->
    <div class="table-responsive" style="border:1px solid #e2e8f0; overflow:hidden;">
        <table class="journal-table-professional" id="journalTable">
            <thead>
                <tr>
                    <th style="width:5%;">رقم القيد</th>
                    <th style="width:9%;">التاريخ</th>
                    <th style="width:11%;">النوع</th>
                    <th style="width:26%;">الحساب المحاسبي</th>
                    <th style="width:10%;">مدين</th>
                    <th style="width:10%;">دائن</th>
                    <th style="width:20%;">البيان / المرجع</th>
                    <th style="width:9%;">المسؤول</th>
                    <?php if ($is_admin): ?>
                    <th class="no-print" style="width:5%;">حذف</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php
                if ($result && $result->num_rows > 0) {
                    $prev_entry_id = null;
                    while ($row = $result->fetch_assoc()) {
                        $is_debit = floatval($row['debit']) > 0;
                        $amount = $is_debit ? floatval($row['debit']) : floatval($row['credit']);
                        $row_border = ($prev_entry_id !== null && $prev_entry_id !== $row['entry_id']) ? 'style="border-top: 2px solid #c7d2fe;"' : '';
                        ?>
                        <tr <?php echo $row_border; ?>>
                            <td class="col-id"><?php echo $row['entry_id']; ?></td>
                            <td class="col-date"><?php echo date('y/m/d', strtotime($row['entry_date'])); ?></td>
                            <td class="col-type">
                                <span class="ref-badge <?php echo get_ref_badge_class($row['source_type']); ?>">
                                    <?php echo translate_ref_type($row['source_type']); ?>
                                </span>
                                <?php if (!empty($row['reference_no'])): ?>
                                <br><small class="text-muted" style="font-size:0.62rem;"><?php echo htmlspecialchars($row['reference_no']); ?></small>
                                <?php endif; ?>
                            </td>
                            <td class="col-account <?php echo $is_debit ? 'account-debit' : 'account-credit'; ?>">
                                [<?php echo htmlspecialchars($row['account_code']); ?>] <?php echo htmlspecialchars($row['account_name']); ?>
                            </td>
                            <td class="col-amount <?php echo $is_debit ? 'amount-debit' : ''; ?>">
                                <?php echo $is_debit ? number_format($amount, 2) : '—'; ?>
                            </td>
                            <td class="col-amount <?php echo !$is_debit ? 'amount-credit' : ''; ?>">
                                <?php echo !$is_debit ? number_format($amount, 2) : '—'; ?>
                            </td>
                            <td class="col-desc"><?php echo htmlspecialchars($row['memo'] ?: $row['entry_desc']); ?></td>
                            <td class="col-user"><?php echo htmlspecialchars($row['created_by']); ?></td>
                            <?php if ($is_admin): ?>
                            <td class="no-print text-center">
                                <?php if ($prev_entry_id !== $row['entry_id']): ?>
                                <a href="delete_journal.php?id=<?php echo $row['entry_id']; ?>"
                                   class="btn btn-danger btn-sm py-0 px-1 text-decoration-none"
                                   style="font-size:0.68rem; width:26px; height:24px; display:inline-flex; align-items:center; justify-content:center;"
                                   onclick="return confirm('تأكيد حذف القيد المزدوج #<?php echo $row['entry_id']; ?> بالكامل؟\nهذا الإجراء لا يمكن التراجع عنه!')">
                                    <i class="bi bi-trash"></i>
                                </a>
                                <?php endif; ?>
                            </td>
                            <?php endif; ?>
                        </tr>
                        <?php
                        $prev_entry_id = $row['entry_id'];
                    }
                } else {
                    $colspan = $is_admin ? 9 : 8;
                    echo '<tr><td colspan="' . $colspan . '" class="text-center py-5 text-muted" style="font-size:0.85rem;"><i class="bi bi-inbox ml-2" style="font-size:1.5rem; display:block; margin-bottom:8px; color:#cbd5e1;"></i>لا توجد قيود يومية مطابقة لخيارات البحث</td></tr>';
                }
                ?>
            </tbody>
        </table>
    </div>

    <!-- ===== تذييل الإجماليات ===== -->
    <div class="journal-summary-footer">
        <div class="summary-grid">
            <div class="summary-item total-debit">
                <div class="label">إجمالي المدين (Debits)</div>
                <div class="value"><?php echo number_format($total_debit, 2); ?></div>
                <small style="color:#cbd5e1; font-size:0.65rem;">ر.ي</small>
            </div>
            <div class="summary-item total-credit">
                <div class="label">إجمالي الدائن (Credits)</div>
                <div class="value"><?php echo number_format($total_credit, 2); ?></div>
                <small style="color:#cbd5e1; font-size:0.65rem;">ر.ي</small>
            </div>
            <div class="summary-item net-balance">
                <div class="label">الفرق (مدين - دائن)</div>
                <div class="value">
                    <?php
                    $sign = $net_balance >= 0 ? '+' : '';
                    echo $sign . number_format($net_balance, 2);
                    ?>
                </div>
                <small style="color:#cbd5e1; font-size:0.65rem;">ر.ي</small>
            </div>
            <div class="summary-item count">
                <div class="label">عدد القيود المحاسبية</div>
                <div class="value"><?php echo number_format($total_entries); ?></div>
                <small style="color:#cbd5e1; font-size:0.65rem;">قيد يومي</small>
            </div>
        </div>
    </div>

    </div>
</div>

</div> <!-- End .page-inner -->

<?php require_once($dir_prefix . 'includes/footer.php'); ?>
