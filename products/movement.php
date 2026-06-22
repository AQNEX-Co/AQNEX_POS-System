<?php
$dir_prefix = '../';
$module = 'products';
require_once($dir_prefix . 'includes/header.php');

check_permission(['admin', 'inventory']);
$param = isset($_GET['id']) ? $_GET['id'] : '';
$product_name = '';
$prod_id = 0;

if (is_numeric($param)) {
    $prod_id = intval($param);
    $sql_prod = "SELECT name FROM products WHERE id = $prod_id";
    $res_prod = $conn->query($sql_prod);
    if ($res_prod && $row_prod = $res_prod->fetch_assoc()) {
        $product_name = $row_prod['name'];
    }
} else {
    $product_name = $param;
    $sql_prod = "SELECT id FROM products WHERE name = '" . $conn->real_escape_string($product_name) . "'";
    $res_prod = $conn->query($sql_prod);
    if ($res_prod && $row_prod = $res_prod->fetch_assoc()) {
        $prod_id = intval($row_prod['id']);
    }
}

// جلب تفاصيل المبيعات للمنتج
$result_sales = false;
if ($prod_id > 0) {
    $sql_sales = "SELECT * FROM sales_items WHERE id = $prod_id ORDER BY p_id DESC";
    $result_sales = $conn->query($sql_sales);
}

// جلب تفاصيل المشتريات للمنتج بالاسم
$result_buys = false;
if (!empty($product_name)) {
    $sql_buys = "SELECT * FROM purchase_items WHERE name = '" . $conn->real_escape_string($product_name) . "' ORDER BY buyid DESC";
    $result_buys = $conn->query($sql_buys);
}
?>
<title>تقرير حركة المنتج: <?php echo htmlspecialchars($product_name); ?> - تكنولوجيا فون</title>



<div class="row no-print mb-4">
    <div class="col-md-6">
        <h3 class="text-secondary font-weight-bold">
            <i class="bi bi-arrow-left-right ml-2"></i>تقرير حركة المنتج: <?php echo htmlspecialchars($product_name ? $product_name : 'غير معروف'); ?>
        </h3>
    </div>
    <div class="col-md-6 text-left">
        <button onclick="window.print()" class="btn-flat btn-flat-success btn-sm ml-2">
            <i class="bi bi-printer ml-1"></i>طباعة كشف الحركة
        </button>
        <a href="index.php" class="btn-flat btn-flat-secondary btn-sm text-decoration-none">
            <i class="fa fa-arrow-left ml-1"></i>عودة للمنتجات
        </a>
    </div>
</div>

<div class="card-flat mb-4">
    <div class="card-body">
        <!-- جدول حركة مبيعات المنتج -->
        <div class="mb-5">
            <h5 class="font-weight-bold text-primary mb-3"><i class="fa fa-shopping-cart ml-1 text-primary"></i>حركة مبيعات المنتج</h5>
            <div class="table-responsive">
                <table class="table-flat">
                    <thead>
                        <tr>
                            <th>رقم الفاتورة</th>
                            <th>العميل</th>
                            <th>الكمية المباعة</th>
                            <th>سعر الوحدة</th>
                            <th>المدفوع</th>
                            <th>الخصم</th>
                            <th>المتبقي</th>
                            <th>الإجمالي الكلي</th>
                            <th>تاريخ البيع</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $total_sold_qty = 0;
                        $total_sold_val = 0;
                        if ($result_sales && $result_sales->num_rows > 0) {
                            while($row = $result_sales->fetch_assoc()) {
                                $total_sold_qty += $row['quantity'];
                                $total_sold_val += $row['all_tot'];
                                ?>
                                <tr>
                                    <td>#<?php echo $row['sales_id']; ?></td>
                                    <td class="font-weight-bold text-secondary"><?php echo htmlspecialchars($row['cust_name']); ?></td>
                                    <td><?php echo htmlspecialchars($row['quantity']); ?></td>
                                    <td><?php echo number_format($row['unit_price'], 2); ?></td>
                                    <td class="text-success"><?php echo number_format($row['bush'], 2); ?></td>
                                    <td class="text-warning"><?php echo number_format($row['d'], 2); ?></td>
                                    <td class="text-danger"><?php echo number_format($row['dis'], 2); ?></td>
                                    <td class="font-weight-bold text-dark"><?php echo number_format($row['all_tot'], 2); ?></td>
                                    <td><?php echo htmlspecialchars($row['build_date']); ?></td>
                                </tr>
                                <?php
                            }
                        } else {
                            echo '<tr><td colspan="9" class="text-center text-muted p-3">لا توجد عمليات بيع لهذا المنتج</td></tr>';
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- جدول حركة مشتريات المنتج -->
        <div class="mb-5">
            <h5 class="font-weight-bold text-danger mb-3">حركة مشتريات وتوريد المنتج</h5>
            <div class="table-responsive">
                <table class="table-flat">
                    <thead>
                        <tr>
                            <th>رقم معاملة الشراء</th>
                            <th>المورد</th>
                            <th>الكمية المشتراة</th>
                            <th>الإجمالي المدفوع</th>
                            <th>المتبقي</th>
                            <th>الإجمالي الكلي للشراء</th>
                            <th>تاريخ الشراء</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $total_bought_qty = 0;
                        $total_bought_val = 0;
                        if ($result_buys && $result_buys->num_rows > 0) {
                            while($row = $result_buys->fetch_assoc()) {
                                $total_bought_qty += $row['quantity'];
                                $total_bought_val += $row['buy_price']; // buy_price stores total line cost
                                ?>
                                <tr>
                                    <td>#<?php echo $row['buyid']; ?></td>
                                    <td class="font-weight-bold text-secondary"><?php echo htmlspecialchars($row['supp_name']); ?></td>
                                    <td><?php echo htmlspecialchars($row['quantity']); ?></td>
                                    <td class="text-success"><?php echo number_format($row['pushtosupp'], 2); ?></td>
                                    <td class="text-danger"><?php echo number_format($row['total_d'], 2); ?></td>
                                    <td class="font-weight-bold text-dark"><?php echo number_format($row['buy_price'], 2); ?></td>
                                    <td><?php echo htmlspecialchars($row['buys_date']); ?></td>
                                </tr>
                                <?php
                            }
                        } else {
                            echo '<tr><td colspan="7" class="text-center text-muted p-3">لا توجد عمليات شراء وتوريد لهذا المنتج</td></tr>';
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>

        <hr class="my-4">

        <!-- الخلاصة المالية لحركة المنتج -->
        <div class="row">
            <div class="col-md-6 mb-3">
                <div class="p-3 border-flat bg-light">
                    <h6 class="font-weight-bold text-primary mb-2">إجمالي ملخص المبيعات</h6>
                    <p class="m-0">الكمية الإجمالية المباعة: <strong><?php echo $total_sold_qty; ?> وحدة</strong></p>
                    <p class="m-0 mt-1">القيمة الإجمالية للمبيعات: <strong class="text-success"><?php echo number_format($total_sold_val, 2); ?> ر.ي</strong></p>
                </div>
            </div>
            <div class="col-md-6">
                <div class="p-3 border-flat bg-light">
                    <h6 class="font-weight-bold text-danger mb-2">إجمالي ملخص المشتريات</h6>
                    <p class="m-0">الكمية الإجمالية المشتراة: <strong><?php echo $total_bought_qty; ?> وحدة</strong></p>
                    <p class="m-0 mt-1">القيمة الإجمالية للمشتريات: <strong class="text-danger"><?php echo number_format($total_bought_val, 2); ?> ر.ي</strong></p>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
require_once($dir_prefix . 'includes/footer.php');
?>
