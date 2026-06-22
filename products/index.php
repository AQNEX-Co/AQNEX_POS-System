<?php
$dir_prefix = '../';
$module = 'products';
require_once($dir_prefix . 'includes/header.php');

check_permission(['admin', 'inventory']);
$sql = "SELECT * FROM products WHERE delete_status = '0' ORDER BY id DESC";
$result = $conn->query($sql);
?>
<title>إدارة وجرد منتجات المستودع</title>



<div class="row no-print mb-4">
    <div class="col-md-6">
        <h3 class="text-secondary font-weight-bold">
            <i class="fa fa-cubes ml-2"></i>إدارة وجرد منتجات المستودع
        </h3>
    </div>
    <div class="col-md-6 text-left">
        <a href="create.php" class="btn-flat btn-flat-primary btn-sm ml-2 text-decoration-none">
            <i class="fa fa-plus ml-1"></i>إضافة منتج جديد
        </a>
        <a href="import.php" class="btn-flat btn-flat-info btn-sm ml-2 text-decoration-none" style="background-color: var(--accent-info); color: #fff;">
            <i class="bi bi-file-earmark-arrow-up ml-1"></i>استيراد من إكسل
        </a>
        <a href="../includes/export.php?type=products" class="btn-flat btn-flat-success btn-sm ml-2 text-decoration-none" style="background-color: var(--accent-success); color: #fff;">
            <i class="bi bi-file-earmark-excel ml-1"></i>تصدير إكسل
        </a>
        <button onclick="window.print()" class="btn-flat btn-flat-success btn-sm ml-2">
            <i class="bi bi-printer ml-1"></i>طباعة كشف الجرد
        </button>
        <a href="../home.php" class="btn-flat btn-flat-secondary btn-sm text-decoration-none">
            <i class="fa fa-arrow-left ml-1"></i>عودة للرئيسية
        </a>
    </div>
</div>

<div class="card-flat">
    <div class="card-header no-print">
        <h5>منتجات المستودع والكميات</h5>
        <div class="d-flex align-items-center">
            <span class="ml-2 font-weight-bold">البحث:</span>
            <input type="text" id="searchInput" class="form-control form-control-sm" style="width: 250px;" placeholder="ابحث باسم المنتج...">
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table-flat" id="productsTable">
                <thead>
                    <tr>
                        <th style="width: 10%;">رقم المنتج</th>
                        <th>اسم المنتج</th>
                        <th style="width: 12%;">المتوفر بالمخزن</th>
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
                            <tr class="product-row">
                                <td>#<?php echo $row['id']; ?></td>
                                <td class="font-weight-bold text-secondary text-right pr-4"><?php echo htmlspecialchars($row['name']); ?></td>
                                <td class="font-weight-bold"><?php echo htmlspecialchars($row['quantity']); ?></td>
                                <td><?php echo number_format($row['buy_price'], 2); ?></td>
                                <td><?php echo number_format($row['sale_price'], 2); ?></td>
                                <td><?php echo htmlspecialchars($row['date']); ?></td>
                                <td class="font-weight-bold text-dark"><?php echo number_format($row['total'], 2); ?></td>
                                <td class="no-print">
                                    <a href="edit.php?id=<?php echo $row['id']; ?>" class="btn-flat btn-flat-primary btn-sm py-1 px-2 ml-1 text-decoration-none">
                                        <?php echo get_icon('edit', 'ml-1'); ?> تعديل
                                    </a>
                                    <a href="movement.php?id=<?php echo urlencode($row['name']); ?>" class="btn-flat btn-flat-success btn-sm py-1 px-2 ml-1 text-decoration-none">
                                        <i class="bi bi-arrow-left-right"></i> حركة المنتج
                                    </a>
                                    <a href="delete.php?id=<?php echo $row['id']; ?>" onclick="return confirm('هل أنت متأكد من حذف هذا المنتج؟')" class="btn-flat btn-flat-danger btn-sm py-1 px-2 text-decoration-none">
                                        <i class="fa fa-trash"></i> حذف
                                    </a>
                                </td>
                            </tr>
                            <?php
                        }
                    } else {
                        echo '<tr><td colspan="8" class="text-center text-muted p-4">لا توجد منتجات مسجلة</td></tr>';
                    }
                    ?>
                </tbody>
            </table>
        </div>

        <hr class="my-4 no-print">

        <!-- إجمالي قيمة بضاعة المستودع الكلية -->
        <div class="row justify-content-end p-3">
            <div class="col-md-5">
                <table class="table-flat bg-light">
                    <tr>
                        <th class="text-right py-2 pr-3">إجمالي قيمة البضائع المتوفرة بالمستودع</th>
                        <?php
                        $sql3 = "SELECT SUM(total) as total_val FROM products WHERE delete_status = 0";
                        $res3 = $conn->query($sql3);
                        $total_val = ($res3 && $row3 = $res3->fetch_assoc()) ? $row3['total_val'] : 0;
                        ?>
                        <td class="font-weight-bold text-primary pl-3" style="font-size: 1.25rem;">
                            <?php echo number_format($total_val, 2); ?> ر.ي
                        </td>
                    </tr>
                </table>
            </div>
        </div>
    </div>
</div>

<script type="text/javascript">
document.addEventListener("DOMContentLoaded", function() {
    const searchInput = document.getElementById("searchInput");
    if (searchInput) {
        searchInput.addEventListener("keyup", function() {
            const filter = this.value.toUpperCase();
            document.querySelectorAll(".product-row").forEach(function(row) {
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
