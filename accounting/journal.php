<?php
$dir_prefix = '../';
$module = 'journal';
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

// إجمالي المبالغ للقيود المفلترة
$sql_total = "SELECT SUM(aj.amount) AS total_amount FROM accounting_journal aj WHERE $where_sql";
$res_total = $conn->query($sql_total);
$total_amount = ($res_total && $row_t = $res_total->fetch_assoc()) ? floatval($row_t['total_amount']) : 0.0;

// ترجمة نوع المرجع للعربية
function translate_ref_type($type) {
    switch ($type) {
        case 'sale': return 'فاتورة مبيعات';
        case 'return': return 'مرتجع مبيعات';
        case 'purchase': return 'فاتورة مشتريات';
        case 'expense': return 'سند صرف';
        case 'receipt': return 'سند قبض';
        case 'adjustment': return 'تسوية صناديق';
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
?>
<title>دفتر القيود اليومية - تكنولوجيا فون</title>

<?php
// رسائل التغذية الراجعة
$journal_msg = isset($_GET['msg']) ? $_GET['msg'] : '';
?>
<?php if ($journal_msg === 'deleted'): ?>
<div class="alert alert-success rounded-0 mb-3 no-print"><?php echo get_icon('check','ml-1'); ?> تم حذف القيد المحاسبي بنجاح.</div>
<?php elseif ($journal_msg === 'error'): ?>
<div class="alert alert-danger rounded-0 mb-3 no-print"><?php echo get_icon('info-circle','ml-1'); ?> حدث خطأ. حاول مرة أخرى.</div>
<?php endif; ?>
<div class="card-flat">
    <div class="card-header no-print">
        <h5><i class="fa fa-book ml-1"></i> دفتر القيود اليومية (القيد المزدوج)</h5>
        <div>
            <button onclick="window.print()" class="btn-flat btn-flat-success btn-sm ml-2">
                <i class="fa fa-print ml-1"></i> طباعة القيود
            </button>
            <a href="../home.php" class="btn-flat btn-flat-secondary btn-sm text-decoration-none">
                <i class="fa fa-arrow-left ml-1"></i> عودة
            </a>
        </div>
    </div>
    
    <div class="card-body">
        <!-- ترويسة للطباعة فقط -->
        <div class="d-none d-print-block text-center mb-4">
            <img src="<?php echo $logo_url; ?>" style="max-height: 80px; width: auto;" class="mb-2">
            <h2><?php echo !empty($global_settings['store_name']) ? htmlspecialchars($global_settings['store_name']) : 'تكنولوجيا فون (TECHNOLOGY PHONE)'; ?></h2>
            <h4>دفتر القيود اليومية المحاسبي</h4>
            <?php if (!empty($from_date) || !empty($to_date)): ?>
                <p class="text-muted">للفترة من: <?php echo $from_date ?: 'البداية'; ?> إلى: <?php echo $to_date ?: 'اليوم'; ?></p>
            <?php endif; ?>
            <hr>
        </div>

        <!-- فلتر البحث المتقدم -->
        <form method="GET" class="no-print mb-4 bg-light p-3 border">
            <div class="row">
                <div class="col-md-3 col-sm-6 mb-3">
                    <label class="form-label font-weight-bold text-secondary">البحث عن حساب أو بيان</label>
                    <input type="text" name="search" class="form-control rounded-0" placeholder="الحساب، البيان، الموظف..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="col-md-2 col-sm-6 mb-3">
                    <label class="form-label font-weight-bold text-secondary">نوع العملية</label>
                    <select name="ref_type" class="form-control rounded-0">
                        <option value="">-- الكل --</option>
                        <option value="sale" <?php echo ($ref_type == 'sale') ? 'selected' : ''; ?>>فاتورة مبيعات</option>
                        <option value="return" <?php echo ($ref_type == 'return') ? 'selected' : ''; ?>>مرتجع مبيعات</option>
                        <option value="purchase" <?php echo ($ref_type == 'purchase') ? 'selected' : ''; ?>>فاتورة مشتريات</option>
                        <option value="expense" <?php echo ($ref_type == 'expense') ? 'selected' : ''; ?>>سند صرف</option>
                        <option value="receipt" <?php echo ($ref_type == 'receipt') ? 'selected' : ''; ?>>سند قبض</option>
                        <option value="adjustment" <?php echo ($ref_type == 'adjustment') ? 'selected' : ''; ?>>تسوية صناديق</option>
                    </select>
                </div>
                <div class="col-md-3 col-sm-6 mb-3">
                    <label class="form-label font-weight-bold text-secondary">الصندوق المرتبط</label>
                    <select name="box_id" class="form-control rounded-0">
                        <option value="0">-- الكل --</option>
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
                <div class="col-md-2 col-sm-6 mb-3">
                    <label class="form-label font-weight-bold text-secondary">من تاريخ</label>
                    <input type="date" name="from_date" class="form-control rounded-0" value="<?php echo $from_date; ?>">
                </div>
                <div class="col-md-2 col-sm-6 mb-3">
                    <label class="form-label font-weight-bold text-secondary">إلى تاريخ</label>
                    <input type="date" name="to_date" class="form-control rounded-0" value="<?php echo $to_date; ?>">
                </div>
            </div>
            <div class="text-left mt-2">
                <button type="submit" class="btn-flat btn-flat-primary btn-sm px-4">
                    <i class="fa fa-filter ml-1"></i> تصفية
                </button>
                <a href="journal.php" class="btn-flat btn-flat-secondary btn-sm px-3 text-decoration-none">
                    إعادة تعيين
                </a>
            </div>
        </form>

        <!-- جدول القيود -->
        <div class="table-responsive">
            <table class="table-flat" id="journalTable">
                <thead>
                    <tr>
                        <th style="width: 7%;">رقم القيد</th>
                        <th style="width: 13%;">التاريخ والوقت</th>
                        <th style="width: 11%;">نوع القيد</th>
                        <th style="width: 15%;">الحساب المدين (من حـ/)</th>
                        <th style="width: 15%;">الحساب الدائن (إلى حـ/)</th>
                        <th style="width: 11%;">المبلغ (ر.ي)</th>
                        <th style="width: 15%;">الوصف والبيان</th>
                        <th style="width: 7%;">المسؤول</th>
                        <?php if ($is_admin): ?>
                        <th class="no-print" style="width: 6%;">حذف</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    if ($result && $result->num_rows > 0) {
                        while ($row = $result->fetch_assoc()) {
                            ?>
                            <tr class="journal-row">
                                <td class="font-weight-bold text-muted">#<?php echo $row['id']; ?></td>
                                <td style="font-size: 0.85rem;"><?php echo date('Y-m-d H:i', strtotime($row['created_at'])); ?></td>
                                <td>
                                    <span class="badge <?php echo get_ref_badge_class($row['ref_type']); ?> font-weight-normal py-1 px-2">
                                        <?php echo translate_ref_type($row['ref_type']); ?>
                                        <?php if ($row['ref_id'] > 0): ?>
                                            #<?php echo $row['ref_id']; ?>
                                        <?php endif; ?>
                                    </span>
                                </td>
                                <td class="text-right text-primary font-weight-bold" style="font-size: 0.9rem;">
                                    <?php echo htmlspecialchars($row['account_debit']); ?>
                                </td>
                                <td class="text-right text-success font-weight-bold" style="font-size: 0.9rem;">
                                    <?php echo htmlspecialchars($row['account_credit']); ?>
                                </td>
                                <td class="font-weight-bold text-center" style="font-size: 1rem; color: #1e293b;">
                                    <?php echo number_format($row['amount'], 2); ?>
                                </td>
                                <td class="text-right small text-muted" style="max-width: 200px; word-wrap: break-word;">
                                    <?php echo htmlspecialchars($row['description']); ?>
                                    <?php if (!empty($row['box_name'])): ?>
                                        <br><span class="badge badge-light border text-dark font-weight-normal mt-1"><?php echo htmlspecialchars($row['box_name']); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="small"><?php echo htmlspecialchars($row['user']); ?></td>
                                <?php if ($is_admin): ?>
                                <td class="no-print text-center">
                                    <a href="delete_journal.php?id=<?php echo $row['id']; ?>" 
                                       class="btn-flat btn-flat-danger btn-sm py-1 px-2 text-decoration-none"
                                       onclick="return confirm('تأكيد حذف القيد #<?php echo $row['id']; ?>\nهذا الإجراء لا يمكن التراجع عنه!')">
                                        <?php echo get_icon('trash'); ?>
                                    </a>
                                </td>
                                <?php endif; ?>
                            </tr>
                            <?php
                        }
                    } else {
                        echo '<tr><td colspan="8" class="text-center py-4">لا توجد قيود يومية مطابقة لخيارات البحث</td></tr>';
                    }
                    ?>
                </tbody>
            </table>
        </div>

        <!-- ملخص المبالغ المفلترة -->
        <div class="row mt-4 justify-content-end">
            <div class="col-md-4">
                <table class="table-flat bg-light border">
                    <tr>
                        <th class="py-2 text-right">إجمالي مبالغ القيود المفلترة:</th>
                        <td class="text-left font-weight-bold text-dark" style="font-size: 1.15rem;">
                            <?php echo number_format($total_amount, 2); ?> <span style="font-size: 0.85rem;">ر.ي</span>
                        </td>
                    </tr>
                </table>
            </div>
        </div>
    </div>
</div>

<?php
require_once($dir_prefix . 'includes/footer.php');
?>
