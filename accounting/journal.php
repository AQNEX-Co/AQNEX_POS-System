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

<title>دفتر القيود اليومية (القيد المزدوج) - تكنولوجيا فون</title>

<style>
@media print {
    #sidebar, .navbar-top, .no-print, .btn-flat, hr, .card-header { display: none !important; }
    #content { margin: 0 !important; padding: 0 !important; }
    body { background: #fff !important; }
}

.journal-table-professional {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 20px;
    background-color: #fff;
}

.journal-table-professional thead th {
    background: linear-gradient(135deg, #1e3a8a 0%, #0f172a 100%);
    color: #fff;
    font-weight: 700;
    padding: 10px 8px;
    font-size: 0.78rem;
    border: 1px solid #1e3a8a;
    text-align: center;
}

.journal-table-professional tbody td {
    padding: 6px 5px;
    border: 1px solid #e5e7eb;
    vertical-align: middle;
    font-size: 0.75rem;
    line-height: 1.3;
}

.journal-table-professional tbody tr:hover {
    background-color: #f0f4f8;
}

.journal-table-professional .col-id {
    text-align: center;
    font-weight: 700;
    color: #6b7280;
    font-family: 'Courier New', monospace;
    font-size: 0.72rem;
}

.journal-table-professional .col-date {
    text-align: center;
    font-size: 0.7rem;
    color: #4b5563;
    white-space: nowrap;
    font-family: 'Courier New', monospace;
}

.journal-table-professional .col-type {
    text-align: center;
}

.journal-table-professional .col-account {
    text-align: right;
    font-weight: 600;
    font-size: 0.73rem;
    padding-right: 8px;
}

.journal-table-professional .col-amount {
    text-align: center;
    font-weight: 700;
    font-family: 'Courier New', monospace;
    font-size: 0.75rem;
    white-space: nowrap;
}

.journal-table-professional .col-desc {
    text-align: right;
    font-size: 0.7rem;
    color: #4b5563;
    max-width: 200px;
    word-wrap: break-word;
    line-height: 1.35;
}

.journal-table-professional .col-user {
    text-align: center;
    font-size: 0.7rem;
    color: #6b7280;
}

.amount-debit {
    color: #b91c1c;
    background-color: #fef2f2;
}

.amount-credit {
    color: #047857;
    background-color: #ecfdf5;
}

.account-debit {
    color: #991b1b;
    border-right: 3px solid #dc2626 !important;
}

.account-credit {
    color: #065f46;
    border-right: 3px solid #10b981 !important;
}

/* تذييل الإجماليات */
.journal-summary-footer {
    background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
    color: #fff;
    border-radius: 6px;
    padding: 15px;
    margin-top: 15px;
}

.summary-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 15px;
    text-align: center;
}

.summary-item {
    border-left: 1px dashed rgba(255, 255, 255, 0.15);
    padding: 5px;
}

.summary-item:last-child {
    border-left: none;
}

.summary-item .label {
    font-size: 0.7rem;
    color: #94a3b8;
    margin-bottom: 5px;
}

.summary-item .value {
    font-size: 1.15rem;
    font-weight: 800;
    font-family: 'Courier New', monospace;
}

.summary-item.total-debit .value { color: #fca5a5; }
.summary-item.total-credit .value { color: #86efac; }
.summary-item.net-balance .value { color: #fcd34d; }
.summary-item.count .value { color: #e2e8f0; }

.ref-badge {
    display: inline-block;
    padding: 2px 6px;
    border-radius: 3px;
    font-size: 0.65rem;
    font-weight: 700;
}
</style>

<div class="page-inner">

<?php
$journal_msg = isset($_GET['msg']) ? $_GET['msg'] : '';
if ($journal_msg === 'deleted'): ?>
<div class="alert alert-professional alert-success rounded-0 mb-3 no-print">
    <i class="bi bi-check-circle-fill ml-2"></i> تم حذف القيد اليومي وتحديث الدفاتر بنجاح.
</div>
<?php elseif ($journal_msg === 'error'): ?>
<div class="alert alert-professional alert-danger rounded-0 mb-3 no-print">
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
        <a href="journal_entry.php" class="btn btn-sm btn-primary text-white text-decoration-none ml-2" style="font-size: 0.8rem; background: linear-gradient(135deg,#10b981 0%,#059669 100%); border: none;">
            <i class="bi bi-plus-lg ml-1"></i> إضافة قيد يومية
        </a>
        <button onclick="window.print()" class="btn btn-sm btn-light" style="font-size: 0.8rem; border: 1px solid #cbd5e1;">
            <i class="bi bi-printer ml-1"></i> طباعة
        </button>
        <a href="../home.php" class="btn btn-sm btn-light text-decoration-none" style="font-size: 0.8rem; border: 1px solid #cbd5e1;">
            <i class="bi bi-arrow-left ml-1"></i> عودة
        </a>
    </div>
</div>

<div class="card-flat">
    <div class="card-body p-3">
        
        <!-- ===== فلتر البحث المتقدم ===== -->
        <form method="GET" class="filter-section no-print">
            <div class="row">
                <div class="col-md-3 col-sm-6 mb-2">
                    <label class="form-label">البحث في الحسابات والبيانات</label>
                    <input type="text" name="search" class="form-control" placeholder="اسم الحساب، البيان، الموظف..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="col-md-2 col-sm-6 mb-2">
                    <label class="form-label">نوع العملية</label>
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
                <div class="col-md-3 col-sm-6 mb-2">
                    <label class="form-label">الصندوق المرتبط</label>
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
                <div class="col-md-2 col-sm-6 mb-2">
                    <label class="form-label">من تاريخ</label>
                    <input type="date" name="from_date" class="form-control" value="<?php echo $from_date; ?>">
                </div>
                <div class="col-md-2 col-sm-6 mb-2">
                    <label class="form-label">إلى تاريخ</label>
                    <input type="date" name="to_date" class="form-control" value="<?php echo $to_date; ?>">
                </div>
            </div>
            <div class="text-left mt-2">
                <button type="submit" class="btn btn-sm btn-primary px-4" style="font-size: 0.8rem;">
                    <i class="fa fa-filter ml-1"></i> تطبيق الفلتر
                </button>
                <a href="journal.php" class="btn btn-sm btn-outline-secondary px-3 text-decoration-none ml-2" style="font-size: 0.8rem;">
                    <i class="bi bi-arrow-counterclockwise ml-1"></i> إعادة تعيين
                </a>
            </div>
        </form>

        <!-- ===== جدول القيود اليومية ===== -->
        <div class="table-responsive" style="border: 1px solid #d1d5db; border-radius: 6px; overflow: hidden;">
            <table class="journal-table-professional" id="journalTable">
                <thead>
                    <tr>
                        <th style="width: 5%;">رقم القيد</th>
                        <th style="width: 10%;">التاريخ</th>
                        <th style="width: 12%;">النوع / المرجع</th>
                        <th style="width: 25%;">الحساب المحاسبي</th>
                        <th style="width: 10%;">مدين</th>
                        <th style="width: 10%;">دائن</th>
                        <th style="width: 20%;">البيان / المرجع</th>
                        <th style="width: 8%;">المسؤول</th>
                        <?php if ($is_admin): ?>
                        <th class="no-print" style="width: 5%;">حذف</th>
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
                            
                            $row_border = ($prev_entry_id !== null && $prev_entry_id !== $row['entry_id']) ? 'style="border-top: 2px solid #cbd5e1;"' : '';
                            ?>
                            <tr <?php echo $row_border; ?>>
                                <td class="col-id"><?php echo $row['entry_id']; ?></td>
                                <td class="col-date">
                                    <?php echo date('y/m/d', strtotime($row['entry_date'])); ?>
                                </td>
                                <td class="col-type">
                                    <span class="ref-badge <?php echo get_ref_badge_class($row['source_type']); ?>">
                                        <?php echo translate_ref_type($row['source_type']); ?>
                                    </span>
                                    <?php if (!empty($row['reference_no'])): ?>
                                        <br><small class="text-muted"><?php echo htmlspecialchars($row['reference_no']); ?></small>
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
                                <td class="col-desc">
                                    <?php echo htmlspecialchars($row['memo'] ?: $row['entry_desc']); ?>
                                </td>
                                <td class="col-user"><?php echo htmlspecialchars($row['created_by']); ?></td>
                                <?php if ($is_admin): ?>
                                <td class="no-print text-center">
                                    <?php if ($prev_entry_id !== $row['entry_id']): ?>
                                    <a href="delete_journal.php?id=<?php echo $row['entry_id']; ?>" 
                                       class="btn btn-sm btn-outline-danger py-0 px-1 text-decoration-none"
                                       style="font-size: 0.7rem;"
                                       onclick="return confirm('تأكيد حذف القيد المزدوج #<?php echo $row['entry_id']; ?> بالكامل؟\nهذا الإجراء سيقوم بحذف كافة السطور والبنود التابعة له!')">
                                        <i class="fa fa-trash"></i>
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
                        echo '<tr><td colspan="' . $colspan . '" class="text-center py-4 text-muted" style="font-size: 0.85rem;">لا توجد قيود يومية مطابقة لخيارات البحث</td></tr>';
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
                    <small style="color: #cbd5e1; font-size: 0.7rem;">ر.ي</small>
                </div>
                <div class="summary-item total-credit">
                    <div class="label">إجمالي الدائن (Credits)</div>
                    <div class="value"><?php echo number_format($total_credit, 2); ?></div>
                    <small style="color: #cbd5e1; font-size: 0.7rem;">ر.ي</small>
                </div>
                <div class="summary-item net-balance">
                    <div class="label">الفرق (مدين - دائن)</div>
                    <div class="value">
                        <?php 
                        $sign = $net_balance >= 0 ? '+' : '';
                        echo $sign . number_format($net_balance, 2); 
                        ?>
                    </div>
                    <small style="color: #cbd5e1; font-size: 0.7rem;">ر.ي</small>
                </div>
                <div class="summary-item count">
                    <div class="label">عدد القيود</div>
                    <div class="value"><?php echo number_format($total_entries); ?></div>
                    <small style="color: #cbd5e1; font-size: 0.7rem;">قيد محاسبي</small>
                </div>
            </div>
        </div>

    </div>
</div>

</div> <!-- End .page-inner -->

<?php require_once($dir_prefix . 'includes/footer.php'); ?>