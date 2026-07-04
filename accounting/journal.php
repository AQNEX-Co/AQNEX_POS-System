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
    $where_clauses[] = "(aj.account_debit LIKE '%$search%' OR aj.account_credit LIKE '%$search%' OR aj.description LIKE '%$search%' OR aj.user LIKE '%$search%')";
}

if ($box_id > 0) {
    $where_clauses[] = "aj.box_id = $box_id";
}

if (!empty($from_date)) {
    $where_clauses[] = "DATE(aj.created_at) >= '$from_date'";
}

if (!empty($to_date)) {
    $where_clauses[] = "DATE(aj.created_at) <= '$to_date'";
}

if (!empty($ref_type)) {
    $where_clauses[] = "aj.ref_type = '$ref_type'";
}

$where_sql = implode(" AND ", $where_clauses);

// الاستعلام لجلب القيود المحاسبية مع اسم الصندوق
$sql = "SELECT aj.*, t.name AS box_name 
        FROM accounting_journal aj 
        LEFT JOIN treasury t ON aj.box_id = t.box_id 
        WHERE $where_sql 
        ORDER BY aj.id DESC";
$result = $conn->query($sql);

// إجماليات مفصلة: المدين، الدائن، الصافي
$sql_totals = "SELECT 
    SUM(CASE WHEN aj.account_debit IS NOT NULL AND aj.account_debit != '' THEN aj.amount ELSE 0 END) AS total_debit,
    SUM(CASE WHEN aj.account_credit IS NOT NULL AND aj.account_credit != '' THEN aj.amount ELSE 0 END) AS total_credit,
    COUNT(*) AS total_entries
    FROM accounting_journal aj WHERE $where_sql";
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
        case 'return': return 'مرتجع';
        case 'purchase': return 'مشتريات';
        case 'expense': return 'مصروف';
        case 'receipt': return 'إيراد';
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
        case 'adjustment': return 'badge-secondary';
        default: return 'badge-light';
    }
}

// تحديد اتجاه الحركة المالية (زيادة / نقص)
function get_movement_direction($debit_acc, $credit_acc) {
    $debit_acc = strtolower($debit_acc);
    $credit_acc = strtolower($credit_acc);
    
    // حسابات الصندوق والبنوك
    if (strpos($debit_acc, 'صندوق') !== false || strpos($debit_acc, 'بنك') !== false || strpos($debit_acc, 'نقدية') !== false) {
        return 'increase';
    }
    if (strpos($credit_acc, 'صندوق') !== false || strpos($credit_acc, 'بنك') !== false || strpos($credit_acc, 'نقدية') !== false) {
        return 'decrease';
    }
    return 'neutral';
}
?>
<title>دفتر القيود اليومية</title>

<style>
/* ===== تصميم احترافي للجدول ===== */
.journal-table-professional {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.75rem;
    background: #fff;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
}

.journal-table-professional thead th {
    background: linear-gradient(180deg, #2c3e50 0%, #34495e 100%);
    color: #fff;
    font-weight: 600;
    font-size: 0.72rem;
    padding: 8px 6px;
    text-align: center;
    border: 1px solid #1a252f;
    letter-spacing: 0.3px;
    position: sticky;
    top: 0;
    z-index: 10;
}

.journal-table-professional tbody td {
    padding: 6px 5px;
    border: 1px solid #e5e7eb;
    vertical-align: middle;
    font-size: 0.75rem;
    line-height: 1.3;
}

.journal-table-professional tbody tr:nth-child(even) {
    background-color: #fafbfc;
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

.movement-badge {
    display: inline-block;
    padding: 1px 6px;
    border-radius: 3px;
    font-size: 0.65rem;
    font-weight: 700;
    margin-right: 4px;
}

.movement-increase {
    background: #d1fae5;
    color: #065f46;
    border: 1px solid #10b981;
}

.movement-decrease {
    background: #fee2e2;
    color: #991b1b;
    border: 1px solid #ef4444;
}

.movement-neutral {
    background: #e5e7eb;
    color: #374151;
    border: 1px solid #9ca3af;
}

/* ===== تذييل الإجماليات ===== */
.journal-summary-footer {
    background: linear-gradient(180deg, #1e293b 0%, #0f172a 100%);
    color: #fff;
    border-radius: 0 0 8px 8px;
    padding: 15px 20px;
    margin-top: -1px;
}

.summary-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 15px;
}

.summary-item {
    background: rgba(255,255,255,0.08);
    border: 1px solid rgba(255,255,255,0.15);
    border-radius: 6px;
    padding: 12px;
    text-align: center;
}

.summary-item .label {
    font-size: 0.75rem;
    color: #cbd5e1;
    margin-bottom: 6px;
    font-weight: 500;
}

.summary-item .value {
    font-size: 1.1rem;
    font-weight: 700;
    font-family: 'Courier New', monospace;
    letter-spacing: 0.5px;
}

.summary-item.total-debit .value {
    color: #fca5a5;
}

.summary-item.total-credit .value {
    color: #86efac;
}

.summary-item.net-balance .value {
    color: #fcd34d;
    font-size: 1.2rem;
}

.summary-item.count .value {
    color: #93c5fd;
}

/* ===== شارات نوع القيد ===== */
.ref-badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 3px;
    font-size: 0.68rem;
    font-weight: 600;
    letter-spacing: 0.3px;
}

/* ===== تصميم الفلاتر ===== */
.filter-section {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    padding: 15px;
    margin-bottom: 20px;
}

.filter-section .form-label {
    font-size: 0.75rem;
    font-weight: 600;
    color: #475569;
    margin-bottom: 4px;
}

.filter-section .form-control {
    font-size: 0.8rem;
    padding: 6px 10px;
    border-radius: 4px;
    border: 1px solid #cbd5e1;
}

/* ===== الطباعة ===== */
@media print {
    .no-print, .filter-section, .journal-summary-footer { display: none !important; }
    .journal-table-professional { font-size: 0.7rem; }
    .journal-table-professional thead th {
        background: #2c3e50 !important;
        color: #fff !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    .amount-debit, .amount-credit {
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
}

/* ===== رسائل التنبيه ===== */
.alert-professional {
    border-radius: 4px;
    font-size: 0.85rem;
    padding: 10px 15px;
    border: none;
    border-right: 4px solid;
}

/* ===== عنوان الصفحة ===== */
.page-header-professional {
    background: linear-gradient(135deg, #1e3a8a 0%, #0f172a 100%);
    color: #fff;
    padding: 15px 20px;
    border-radius: 6px 6px 0 0;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.page-header-professional h5 {
    margin: 0;
    font-size: 1rem;
    font-weight: 700;
    letter-spacing: 0.5px;
}
</style>

<?php
$journal_msg = isset($_GET['msg']) ? $_GET['msg'] : '';
?>

<?php if ($journal_msg === 'deleted'): ?>
<div class="alert alert-professional alert-success rounded-0 mb-3 no-print">
    <strong>تم الحفظ:</strong> تم حذف القيد المحاسبي بنجاح.
</div>
<?php elseif ($journal_msg === 'error'): ?>
<div class="alert alert-professional alert-danger rounded-0 mb-3 no-print">
    <strong>خطأ:</strong> حدث خطأ أثناء العملية. حاول مرة أخرى.
</div>
<?php endif; ?>

<!-- ===== رأس الصفحة ===== -->
<div class="page-header-professional no-print">
    <h5>
        <i class="fa fa-book ml-2"></i>
        دفتر القيود اليومية — القيد المزدوج
    </h5>
    <div>
        <button onclick="window.print()" class="btn btn-sm btn-light mr-2" style="font-size: 0.8rem;">
            <i class="fa fa-printer ml-1"></i> طباعة
        </button>
        <a href="../home.php" class="btn btn-sm btn-outline-light text-decoration-none" style="font-size: 0.8rem;">
            <i class="fa fa-arrow-left ml-1"></i> عودة
        </a>
    </div>
</div>

<div class="card-flat" style="border-radius: 0 0 8px 8px; border-top: none;">
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
                        <th style="width: 5%;">#</th>
                        <th style="width: 9%;">التاريخ</th>
                        <th style="width: 7%;">النوع</th>
                        <th style="width: 17%;">الحساب المدين</th>
                        <th style="width: 9%;">مدين</th>
                        <th style="width: 17%;">الحساب الدائن</th>
                        <th style="width: 9%;">دائن</th>
                        <th style="width: 18%;">البيان / المرجع</th>
                        <th style="width: 5%;">المسؤول</th>
                        <?php if ($is_admin): ?>
                        <th class="no-print" style="width: 4%;">حذف</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    if ($result && $result->num_rows > 0) {
                        $row_num = 1;
                        while ($row = $result->fetch_assoc()) {
                            $movement = get_movement_direction($row['account_debit'], $row['account_credit']);
                            $movement_label = '';
                            $movement_class = '';
                            
                            if ($movement === 'increase') {
                                $movement_label = 'زيادة';
                                $movement_class = 'movement-increase';
                            } elseif ($movement === 'decrease') {
                                $movement_label = 'نقص';
                                $movement_class = 'movement-decrease';
                            } else {
                                $movement_label = 'قيد';
                                $movement_class = 'movement-neutral';
                            }
                            ?>
                            <tr>
                                <td class="col-id"><?php echo $row_num++; ?></td>
                                <td class="col-date">
                                    <?php echo date('y/m/d', strtotime($row['created_at'])); ?>
                                    <br>
                                    <small style="color: #9ca3af;"><?php echo date('H:i', strtotime($row['created_at'])); ?></small>
                                </td>
                                <td class="col-type">
                                    <span class="ref-badge <?php echo get_ref_badge_class($row['ref_type']); ?>">
                                        <?php echo translate_ref_type($row['ref_type']); ?>
                                    </span>
                                    <?php if ($row['ref_id'] > 0): ?>
                                        <br><small class="text-muted">#<?php echo $row['ref_id']; ?></small>
                                    <?php endif; ?>
                                </td>
                                <td class="col-account account-debit">
                                    <?php echo htmlspecialchars(!empty($row['account_debit']) ? $row['account_debit'] : '—'); ?>
                                </td>
                                <td class="col-amount amount-debit">
                                    <?php echo number_format($row['amount'], 2); ?>
                                </td>
                                <td class="col-account account-credit">
                                    <?php echo htmlspecialchars(!empty($row['account_credit']) ? $row['account_credit'] : '—'); ?>
                                </td>
                                <td class="col-amount amount-credit">
                                    <?php echo number_format($row['amount'], 2); ?>
                                </td>
                                <td class="col-desc">
                                    <span class="movement-badge <?php echo $movement_class; ?>"><?php echo $movement_label; ?></span>
                                    <?php echo htmlspecialchars($row['description']); ?>
                                    <?php if (!empty($row['box_name'])): ?>
                                        <br><small class="text-muted"><i class="fa fa-cash-register ml-1"></i><?php echo htmlspecialchars($row['box_name']); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td class="col-user"><?php echo htmlspecialchars($row['user']); ?></td>
                                <?php if ($is_admin): ?>
                                <td class="no-print text-center">
                                    <a href="delete_journal.php?id=<?php echo $row['id']; ?>" 
                                       class="btn btn-sm btn-outline-danger py-0 px-1 text-decoration-none"
                                       style="font-size: 0.7rem;"
                                       onclick="return confirm('تأكيد حذف القيد #<?php echo $row['id']; ?> ؟\nهذا الإجراء لا يمكن التراجع عنه!')">
                                        <i class="fa fa-trash"></i>
                                    </a>
                                </td>
                                <?php endif; ?>
                            </tr>
                            <?php
                        }
                    } else {
                        $colspan = $is_admin ? 10 : 9;
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
                    <div class="label">إجمالي المدين (الزيادات)</div>
                    <div class="value"><?php echo number_format($total_debit, 2); ?></div>
                    <small style="color: #cbd5e1; font-size: 0.7rem;">ر.ي</small>
                </div>
                <div class="summary-item total-credit">
                    <div class="label">إجمالي الدائن (النقص)</div>
                    <div class="value"><?php echo number_format($total_credit, 2); ?></div>
                    <small style="color: #cbd5e1; font-size: 0.7rem;">ر.ي</small>
                </div>
                <div class="summary-item net-balance">
                    <div class="label">الصافي (الزيادة - النقص)</div>
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
            
            <?php if (!empty($search) || $box_id > 0 || !empty($from_date) || !empty($to_date) || !empty($ref_type)): ?>
            <div class="text-center mt-3 pt-3" style="border-top: 1px solid rgba(255,255,255,0.15);">
                <small style="color: #94a3b8; font-size: 0.75rem;">
                    <i class="fa fa-info-circle ml-1"></i>
                    الإحصائيات أعلاه خاصة بالقيود المفلترة فقط
                </small>
            </div>
            <?php endif; ?>
        </div>

    </div>
</div>

<?php
require_once($dir_prefix . 'includes/footer.php');
?>