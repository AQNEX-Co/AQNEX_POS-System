<?php
$dir_prefix = '../';
$module = 'sales';
require_once($dir_prefix . 'includes/header.php');

check_permission(['admin', 'cashier']);
$sql = "SELECT * FROM sales WHERE delete_status = '0' ORDER BY id DESC";
$result = $conn->query($sql);
?>
<title>إدارة المبيعات - تكنولوجيا فون</title>

<div class="card-flat">
    <div class="card-header no-print">
        <h5><?php echo get_icon('sales', 'ml-2 text-primary'); ?> إدارة فواتير المبيعات</h5>
        <div>
            <a href="create.php" class="btn-flat btn-flat-primary btn-sm ml-2 text-decoration-none">
                <?php echo get_icon('plus', 'ml-1'); ?> إضافة فاتورة جديدة
            </a>
            <a href="returns.php" class="btn-flat btn-flat-secondary btn-sm ml-2 text-decoration-none" style="background-color: #8e44ad; color: #fff;">
                <i class="fa-solid fa-receipt ml-1"></i> مردودات المبيعات
            </a>
            <a href="../includes/export.php?type=sales" class="btn-flat btn-flat-success btn-sm ml-2 text-decoration-none" style="background-color: var(--accent-success); color: #fff;">
                <i class="fa-solid fa-file-excel ml-1"></i>تصدير إكسل
            </a>
            <button onclick="window.print()" class="btn-flat btn-flat-success btn-sm ml-2">
                <?php echo get_icon('print', 'ml-1'); ?> طباعة القائمة
            </button>
            <a href="../home.php" class="btn-flat btn-flat-secondary btn-sm text-decoration-none">
                <?php echo get_icon('logout', 'ml-1'); ?> عودة
            </a>
        </div>
    </div>
    <div class="card-body">


        <div class="table-responsive">
            <table class="table-flat">
                <thead>
                    <tr>
                        <th>رقم الفاتورة</th>
                        <th>اسم العميل</th>
                        <th>تاريخ البيع</th>
                        <th>إجمالي الفاتورة</th>
                        <th>الربح</th>
                        <th>ملاحظات</th>
                        <th class="no-print">العمليات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    if ($result && $result->num_rows > 0) {
                        while($row = $result->fetch_assoc()) {
                            ?>
                            <tr>
                                <td>#<?php echo $row['id']; ?></td>
                                <td class="font-weight-bold text-secondary"><?php echo $row['cust_name'] ? htmlspecialchars($row['cust_name']) : 'عميل نقدي'; ?></td>
                                <td><?php echo htmlspecialchars($row['build_date']); ?></td>
                                <td class="font-weight-bold"><?php echo number_format($row['total'], 2); ?></td>
                                <td class="text-success font-weight-bold"><?php echo number_format($row['prifet'], 2); ?></td>
                                <td><?php echo htmlspecialchars($row['remark']); ?></td>
                                <td class="no-print">
                                    <a href="view.php?id=<?php echo $row['id']; ?>" class="btn-flat btn-flat-primary btn-sm py-1 px-2 text-decoration-none">
                                        <?php echo get_icon('eye', 'ml-1'); ?> تفاصيل
                                    </a>
                                    <a href="edit.php?id=<?php echo $row['id']; ?>" class="btn-flat btn-flat-warning btn-sm py-1 px-2 ml-1 text-decoration-none" style="background-color: var(--accent-warning); color: #fff;">
                                        <?php echo get_icon('edit', 'ml-1'); ?> تعديل
                                    </a>
                                    <a href="delete.php?id=<?php echo $row['id']; ?>" onclick="return confirm('هل أنت متأكد من حذف هذه الفاتورة؟')" class="btn-flat btn-flat-danger btn-sm py-1 px-2 ml-1 text-decoration-none">
                                        <?php echo get_icon('trash', 'ml-1'); ?> حذف
                                    </a>
                                </td>
                            </tr>
                            <?php
                        }
                    } else {
                        echo '<tr><td colspan="7" class="text-center text-muted p-4">لا توجد فواتير مبيعات مسجلة</td></tr>';
                    }
                    ?>
                </tbody>
            </table>
        </div>

        <hr class="my-4">

        <!-- جدول الأرباح الإجمالية -->
        <div class="row justify-content-end">
            <div class="col-md-4">
                <table class="table-flat">
                    <tbody>
                        <tr>
                            <th class="bg-light text-right pr-3">إجمالي الأرباح الكلية للمبيعات</th>
                            <?php
                            $sql3 = "SELECT SUM(prifet) as total_profit FROM sales WHERE delete_status = 0";
                            $res3 = $conn->query($sql3);
                            $total_profit = ($res3 && $row3 = $res3->fetch_assoc()) ? floatval($row3['total_profit']) : 0.0;
                            ?>
                            <td class="font-weight-bold text-success pl-3" style="font-size: 1.2rem;">
                                <?php echo number_format($total_profit, 2); ?> ر.ي
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php
require_once($dir_prefix . 'includes/footer.php');
?>
