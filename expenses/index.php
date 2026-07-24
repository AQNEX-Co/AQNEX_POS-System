<?php
$dir_prefix = '../';
$module = 'expenses';
require_once($dir_prefix . 'includes/header.php');

check_permission(['admin', 'cashier']);

$search    = isset($_GET['search']) ? trim($_GET['search']) : '';
$from_date = isset($_GET['from_date']) ? trim($_GET['from_date']) : '';
$to_date   = isset($_GET['to_date']) ? trim($_GET['to_date']) : '';

$where = "WHERE te.s = '0'";
if (!empty($search)) {
    $where .= " AND (te.st LIKE '%" . $conn->real_escape_string($search) . "%' OR te.sremark LIKE '%" . $conn->real_escape_string($search) . "%' OR te.sid LIKE '%" . $conn->real_escape_string($search) . "%')";
}
if (!empty($from_date)) {
    $where .= " AND te.sdate >= '" . $conn->real_escape_string($from_date) . "'";
}
if (!empty($to_date)) {
    $where .= " AND te.sdate <= '" . $conn->real_escape_string($to_date) . "'";
}

$sql = "SELECT te.*, t.name AS box_name FROM treasury_expenses te LEFT JOIN treasury t ON te.box_id = t.box_id $where ORDER BY te.sid DESC";
$result = $conn->query($sql);

$stats = $conn->query("SELECT COALESCE(SUM(te.sprice),0) AS total_amount, COUNT(*) AS total_count FROM treasury_expenses te $where")->fetch_assoc();
$total_amount = (float)($stats['total_amount'] ?? 0);
$total_count  = (int)($stats['total_count'] ?? 0);
$currency = $global_settings['currency'] ?? 'ر.ي';
?>
<title>تقرير سجل سندات الصرف والمصروفات - <?php echo htmlspecialchars($global_settings['store_name'] ?? 'AQNEX'); ?></title>

<div class="card-flat">
    <div class="card-header no-print d-flex flex-wrap gap-2 align-items-center justify-content-between">
        <h5 class="mb-0"><i class="bi bi-file-earmark-text-fill text-danger ml-1"></i> تقرير سجل سندات الصرف والمصروفات</h5>
        <div>
            <a href="create.php" class="btn-flat btn-flat-primary btn-sm ml-2 text-decoration-none">
                <i class="bi bi-plus-circle ml-1"></i> سند صرف جديد
            </a>
            <button onclick="window.print()" class="btn-flat btn-flat-success btn-sm ml-2">
                <i class="bi bi-printer ml-1"></i> طباعة التقرير
            </button>
            <a href="../home.php" class="btn-flat btn-flat-secondary btn-sm text-decoration-none">
                <i class="bi bi-arrow-left ml-1"></i> عودة
            </a>
        </div>
    </div>
    <div class="card-body">
        <!-- ترويسة التقرير للطباعة -->
        <div class="d-none d-print-block text-center mb-4">
            <h2><?php echo !empty($global_settings['store_name']) ? htmlspecialchars($global_settings['store_name']) : 'تكنولوجيا فون'; ?></h2>
            <h4>تقرير سجل سندات الصرف والمصروفات النقدية</h4>
            <p>تاريخ التقرير: <?php echo date('Y/m/d'); ?> &bull; إجمالي المصروفات: <?php echo number_format($total_amount, 2) . ' ' . $currency; ?> &bull; عدد السندات: <?php echo $total_count; ?></p>
            <hr>
        </div>

        <!-- ملخص الإحصائيات -->
        <div class="row g-3 mb-4 no-print">
            <div class="col-md-6 col-6">
                <div style="background:linear-gradient(135deg,#ef4444,#b91c1c);border-radius:12px;padding:16px 20px;color:#fff;">
                    <div style="font-size:.85rem;opacity:.9;">إجمالي مبالغ المصروفات والسداد</div>
                    <div style="font-size:1.5rem;font-weight:700;"><?php echo number_format($total_amount, 2); ?> <small style="font-size:0.8rem;"><?php echo $currency; ?></small></div>
                </div>
            </div>
            <div class="col-md-6 col-6">
                <div style="background:linear-gradient(135deg,#f59e0b,#d97706);border-radius:12px;padding:16px 20px;color:#fff;">
                    <div style="font-size:.85rem;opacity:.9;">إجمالي عدد سندات الصرف</div>
                    <div style="font-size:1.5rem;font-weight:700;"><?php echo $total_count; ?> <small style="font-size:0.8rem;">سند</small></div>
                </div>
            </div>
        </div>

        <!-- حقل الفلترة والبحث -->
        <form method="GET" class="no-print mb-3">
            <div class="row g-2 align-items-end">
                <div class="col-md-4">
                    <label class="form-label small text-muted mb-1">بحث (بند الصرف / رقم السند / البيان)</label>
                    <input type="text" name="search" class="form-control form-control-sm" placeholder="ابحث هنا..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label small text-muted mb-1">من تاريخ</label>
                    <input type="date" name="from_date" class="form-control form-control-sm" value="<?php echo htmlspecialchars($from_date); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label small text-muted mb-1">إلى تاريخ</label>
                    <input type="date" name="to_date" class="form-control form-control-sm" value="<?php echo htmlspecialchars($to_date); ?>">
                </div>
                <div class="col-md-2 d-flex gap-2">
                    <button type="submit" class="btn-flat btn-flat-primary btn-sm flex-grow-1">
                        <i class="bi bi-search me-1"></i> بحث
                    </button>
                    <a href="index.php" class="btn-flat btn-flat-secondary btn-sm flex-grow-1 text-decoration-none text-center">
                        <i class="bi bi-x-circle me-1"></i> إلغاء
                    </a>
                </div>
            </div>
        </form>

        <div class="table-responsive">
            <table class="table-flat" id="expensesTable">
                <thead>
                    <tr>
                        <th># رقم السند</th>
                        <th>بند / حاجة الصرف</th>
                        <th>الصندوق المستهدف</th>
                        <th>المبلغ</th>
                        <th>البيان والملاحظات</th>
                        <th>تاريخ الصرف</th>
                        <th class="no-print">إجراء</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    if ($result && $result->num_rows > 0) {
                        while($row = $result->fetch_assoc()) {
                            ?>
                            <tr class="expense-row">
                                <td>#<?php echo $row['sid']; ?></td>
                                <td class="font-weight-bold text-right"><?php echo htmlspecialchars($row['st']); ?></td>
                                <td><span class="badge badge-light border text-dark py-1 px-2"><?php echo htmlspecialchars($row['box_name'] ?? 'الصندوق الرئيسي'); ?></span></td>
                                <td class="text-danger font-weight-bold"><?php echo number_format($row['sprice'], 2); ?></td>
                                <td class="text-right"><?php echo htmlspecialchars($row['sremark']); ?></td>
                                <td><?php echo htmlspecialchars($row['sdate']); ?></td>
                                <td class="no-print">
                                    <a href="create.php?id=<?php echo $row['sid']; ?>" class="btn-flat btn-flat-primary btn-sm py-1 px-2 text-decoration-none" title="تعديل / إنزال البيانات للإنشاء">
                                        <i class="bi bi-pencil-square ml-1"></i> تعديل
                                    </a>
                                    <?php if ($_SESSION['SESS_LAST_NAME'] === 'admin'): ?>
                                        <a href="delete.php?id=<?php echo $row['sid']; ?>" onclick="return confirm('هل أنت متأكد من حذف هذا المصروف؟')" class="btn-flat btn-flat-danger btn-sm py-1 px-2 ml-1 text-decoration-none">
                                            <i class="bi bi-trash"></i> حذف
                                        </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php
                        }
                    } else {
                        echo '<tr><td colspan="7" class="text-center py-4">لا توجد مصروفات مسجلة بالمواصفات المحددة</td></tr>';
                    }
                    ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
require_once($dir_prefix . 'includes/footer.php');
?>
