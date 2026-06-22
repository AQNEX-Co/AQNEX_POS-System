<?php
$dir_prefix = '../';
$module = 'receipts';
require_once($dir_prefix . 'includes/header.php');

check_permission(['admin', 'cashier']);
$sql = "SELECT r.*, t.name AS box_name FROM receipts r LEFT JOIN treasury t ON r.box_id = t.box_id WHERE r.s = '0' ORDER BY r.qid DESC";
$result = $conn->query($sql);
?>
<title>إدارة المقبوضات - تكنولوجيا فون</title>

<div class="card-flat">
    <div class="card-header no-print">
        <h5><i class="bi bi-plus-circle ml-1"></i> إدارة وجرد المقبوضات (سندات القبض للعملاء)</h5>
        <div>
            <a href="create.php" class="btn-flat btn-flat-primary btn-sm ml-2 text-decoration-none">
                <i class="bi bi-plus ml-1"></i> تسجيل مقبوضات جديدة
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


        <!-- حقل البحث السريع -->
        <div class="mb-3 no-print row justify-content-end">
            <div class="col-md-4">
                <div class="input-group">
                    <div class="input-group-prepend">
                        <span class="input-group-text rounded-0"><i class="bi bi-search"></i></span>
                    </div>
                    <input type="text" id="searchInput" class="form-control" placeholder="ابحث باسم العميل أو الملاحظات...">
                </div>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table-flat" id="receiptsTable">
                <thead>
                    <tr>
                        <th>رقم السند</th>
                        <th>اسم العميل</th>
                        <th>الصندوق</th>
                        <th>المبلغ المقبوض</th>
                        <th>البيان والملاحظات</th>
                        <th>تاريخ القبض</th>
                        <th class="no-print">العمليات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    if ($result && $result->num_rows > 0) {
                        while($row = $result->fetch_assoc()) {
                            ?>
                            <tr class="receipt-row">
                                <td>#<?php echo $row['qid']; ?></td>
                                <td class="font-weight-bold text-right"><?php echo htmlspecialchars($row['cust_name']); ?></td>
                                <td><span class="badge badge-light border text-dark py-1 px-2"><?php echo htmlspecialchars($row['box_name'] ?? 'الصندوق الرئيسي'); ?></span></td>
                                <td class="text-success font-weight-bold"><?php echo number_format($row['q_price'], 2); ?></td>
                                <td class="text-right"><?php echo htmlspecialchars($row['remark']); ?></td>
                                <td><?php echo htmlspecialchars($row['q_date']); ?></td>
                                <td class="no-print">
                                    <?php if ($_SESSION['SESS_LAST_NAME'] === 'admin'): ?>
                                        <a href="edit.php?id=<?php echo $row['qid']; ?>" class="btn-flat btn-flat-primary btn-sm py-1 px-2 text-decoration-none">
                                        <?php echo get_icon('edit', 'ml-1'); ?> تعديل
                                        </a>
                                        <a href="delete.php?id=<?php echo $row['qid']; ?>" onclick="return confirm('هل أنت متأكد من حذف هذا السند؟')" class="btn-flat btn-flat-danger btn-sm py-1 px-2 ml-1 text-decoration-none">
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
                        echo '<tr><td colspan="7" class="text-center py-4">لا توجد سندات قبض مسجلة</td></tr>';
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
            document.querySelectorAll(".receipt-row").forEach(function(row) {
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
