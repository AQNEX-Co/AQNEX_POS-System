<?php
$dir_prefix = '../';
$module = 'products';
require_once($dir_prefix . 'includes/header.php');

check_permission(['admin', 'inventory']);
$cat_id = intval($_GET['id']);

// جلب اسم التصنيف
$sql_cat = "SELECT name FROM categories WHERE catid = $cat_id";
$res_cat = $conn->query($sql_cat);
$cat_name = ($res_cat && $row = $res_cat->fetch_assoc()) ? $row['name'] : 'غير معروف';

// جلب المنتجات التابعة للتصنيف
$sql_products = "SELECT * FROM products WHERE catid = $cat_id AND delete_status = 0 ORDER BY id DESC";
$result = $conn->query($sql_products);
?>
<title>منتجات صنف: <?php echo htmlspecialchars($cat_name); ?> - تكنولوجيا فون</title>



<div class="row no-print mb-4">
    <div class="col-md-6">
        <h3 class="text-secondary font-weight-bold">
            <i class="fa fa-cubes ml-2"></i>منتجات صنف: <?php echo htmlspecialchars($cat_name); ?>
        </h3>
    </div>
    <div class="col-md-6 text-left">
        <a href="create.php" class="btn-flat btn-flat-primary btn-sm ml-2 text-decoration-none">
            <i class="fa fa-plus ml-1"></i>إضافة منتج جديد
        </a>
        <button onclick="window.print()" class="btn-flat btn-flat-success btn-sm ml-2">
            <i class="bi bi-printer ml-1"></i>طباعة القائمة
        </button>
        <a href="../categories/index.php" class="btn-flat btn-flat-secondary btn-sm text-decoration-none">
            <i class="fa fa-arrow-left ml-1"></i>عودة للأصناف
        </a>
    </div>
</div>

<div class="card-flat">
    <div class="card-header no-print">
        <h5>قائمة المنتجات التابعة لصنف: <?php echo htmlspecialchars($cat_name); ?></h5>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table-flat">
                <thead>
                    <tr>
                        <th style="width: 10%;">رقم المنتج</th>
                        <th>اسم المنتج</th>
                        <th style="width: 12%;">الكمية المتوفرة</th>
                        <th>سعر الشراء</th>
                        <th>سعر البيع</th>
                        <th>تاريخ الإضافة</th>
                        <th>القيمة الكلية للبضاعة</th>
                        <th class="no-print" style="width: 25%;">العمليات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    if ($result && $result->num_rows > 0) {
                        while($row = $result->fetch_assoc()) {
                            ?>
                            <tr>
                                <td>#<?php echo $row['id']; ?></td>
                                <td class="font-weight-bold text-secondary text-right pr-4"><?php echo htmlspecialchars($row['name']); ?></td>
                                <td class="font-weight-bold"><?php echo htmlspecialchars($row['quantity']); ?></td>
                                <td><?php echo number_format($row['buy_price'], 2); ?></td>
                                <td><?php echo number_format($row['sale_price'], 2); ?></td>
                                <td><?php echo htmlspecialchars($row['date']); ?></td>
                                <td class="font-weight-bold text-dark"><?php echo number_format($row['total'], 2); ?></td>
                                <td class="no-print">
                                    <a href="edit.php?id=<?php echo $row['id']; ?>" class="btn-flat btn-flat-primary btn-sm py-1 px-2 ml-1 text-decoration-none">
                                        <i class="bi bi-pencil-square"></i> تعديل
                                    </a>
                                    <a href="movement.php?id=<?php echo urlencode($row['name']); ?>" class="btn-flat btn-flat-success btn-sm py-1 px-2 ml-1 text-decoration-none">
                                        <i class="bi bi-arrow-left-right"></i> الحركة
                                    </a>
                                    <a href="delete.php?id=<?php echo $row['id']; ?>" onclick="return confirm('هل أنت متأكد من حذف هذا المنتج؟')" class="btn-flat btn-flat-danger btn-sm py-1 px-2 text-decoration-none">
                                        <i class="fa fa-trash"></i> حذف
                                    </a>
                                </td>
                            </tr>
                            <?php
                        }
                    } else {
                        echo '<tr><td colspan="8" class="text-center text-muted p-4">لا توجد منتجات مسجلة في هذا الصنف</td></tr>';
                    }
                    ?>
                </tbody>
            </table>
        </div>

        <hr class="my-4 no-print">

        <!-- إجمالي مبالغ البضائع في هذا الصنف -->
        <div class="row justify-content-end p-3">
            <div class="col-md-5">
                <table class="table-flat bg-light">
                    <tr>
                        <th class="text-right py-2 pr-3">إجمالي قيمة بضائع هذا الصنف:</th>
                        <?php
                        $sql_sum = "SELECT SUM(total) as cat_total FROM products WHERE catid = $cat_id AND delete_status = 0";
                        $res_sum = $conn->query($sql_sum);
                        $cat_total = ($res_sum && $row_sum = $res_sum->fetch_assoc()) ? $row_sum['cat_total'] : 0;
                        ?>
                        <td class="font-weight-bold text-primary pl-3" style="font-size: 1.1rem;">
                            <?php echo number_format($cat_total, 2); ?> ر.ي
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
