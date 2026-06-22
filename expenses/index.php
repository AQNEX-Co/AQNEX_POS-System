<?php
$dir_prefix = '../';
$module = 'expenses';
require_once($dir_prefix . 'includes/header.php');

check_permission(['admin', 'cashier']);
$sql = "SELECT te.*, t.name AS box_name FROM treasury_expenses te LEFT JOIN treasury t ON te.box_id = t.box_id WHERE te.s = '0' ORDER BY te.sid DESC";
$result = $conn->query($sql);
?>
<title>إدارة المصروفات - تكنولوجيا فون</title>

<div class="card-flat">
    <div class="card-header no-print">
        <h5><i class="bi bi-minus-circle ml-1"></i> إدارة وجرد المصروفات</h5>
        <div>
            <a href="create.php" class="btn-flat btn-flat-primary btn-sm ml-2 text-decoration-none">
                <i class="bi bi-plus ml-1"></i> تسجيل مصروفات جديدة
            </a>
            <button onclick="window.print()" class="btn-flat btn-flat-success btn-sm ml-2">
                <i class="bi bi-printer ml-1"></i> طباعة القائمة
            </button>
            <a href="../home.php" class="btn-flat btn-flat-secondary btn-sm text-decoration-none">
                <i class="bi bi-arrow-left ml-1"></i> عودة
            </a>
        </div>
    </div>
    <div class="card-body">
        <div class="d-none d-print-block text-center mb-4">
            <img src="<?php echo $logo_url; ?>" style="max-height: 80px; width: auto;" class="mb-2">
            <h2><?php echo !empty($global_settings['store_name']) ? htmlspecialchars($global_settings['store_name']) : 'تكنولوجيا فون (TECHNOLOGY PHONE)'; ?></h2>
            <h4>تقرير جرد المصروفات اليومية</h4>
            <hr>
        </div>

        <!-- حقل البحث الفوري السريع -->
        <div class="mb-3 no-print row justify-content-end">
            <div class="col-md-4">
                <div class="input-group">
                    <div class="input-group-prepend">
                        <span class="input-group-text rounded-0"><i class="bi bi-search"></i></span>
                    </div>
                    <input type="text" id="searchInput" class="form-control" placeholder="ابحث حسب البند أو الملاحظات...">
                </div>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table-flat" id="expensesTable">
                <thead>
                    <tr>
                        <th>بند الصرف</th>
                        <th>الصندوق</th>
                        <th>المبلغ</th>
                        <th>البيان والملاحظات</th>
                        <th>تاريخ الصرف</th>
                        <th class="no-print">العمليات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    if ($result && $result->num_rows > 0) {
                        while($row = $result->fetch_assoc()) {
                            ?>
                            <tr class="expense-row">
                                <td class="font-weight-bold text-right"><?php echo htmlspecialchars($row['st']); ?></td>
                                <td><span class="badge badge-light border text-dark py-1 px-2"><?php echo htmlspecialchars($row['box_name'] ?? 'الصندوق الرئيسي'); ?></span></td>
                                <td class="text-danger font-weight-bold"><?php echo number_format($row['sprice'], 2); ?></td>
                                <td class="text-right"><?php echo htmlspecialchars($row['sremark']); ?></td>
                                <td><?php echo htmlspecialchars($row['sdate']); ?></td>
                                <td class="no-print">
                                    <?php if ($_SESSION['SESS_LAST_NAME'] === 'admin'): ?>
                                        <a href="edit.php?sid=<?php echo $row['sid']; ?>" class="btn-flat btn-flat-primary btn-sm py-1 px-2 text-decoration-none">
                                        <?php echo get_icon('edit', 'ml-1'); ?> تعديل
                                        </a>
                                        <a href="delete.php?id=<?php echo $row['sid']; ?>" onclick="return confirm('هل أنت متأكد من حذف هذا المصروف؟')" class="btn-flat btn-flat-danger btn-sm py-1 px-2 ml-1 text-decoration-none">
                                            <i class="bi bi-trash"></i> مسح
                                        </a>
                                    <?php else: ?>
                                        <span class="text-muted" style="font-size: 0.85rem;">لا توجد صلاحية</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php
                        }
                    } else {
                        echo '<tr><td colspan="6" class="text-center py-4">لا توجد مصروفات مسجلة</td></tr>';
                    }
                    ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script type="text/javascript">
document.addEventListener("DOMContentLoaded", function() {
    const searchInput = document.getElementById("searchInput");
    if (searchInput) {
        searchInput.addEventListener("keyup", function() {
            const filter = this.value.toUpperCase();
            document.querySelectorAll(".expense-row").forEach(function(row) {
                const text = row.innerText.toUpperCase();
                if (text.indexOf(filter) > -1) {
                    row.style.display = "";
                } else {
                    row.style.display = "none";
                }
            });
        });
    }
});
</script>

<?php
require_once($dir_prefix . 'includes/footer.php');
?>
