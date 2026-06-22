<?php
$dir_prefix = '../';
$module = 'customers';
require_once($dir_prefix . 'includes/header.php');

check_permission(['admin', 'cashier']);
if (!isset($_GET['id']) || empty($_GET['id'])) {
    echo "<div class='alert alert-danger rounded-0'>خطأ: لم يتم تحديد العميل.</div>";
    require_once($dir_prefix . 'includes/footer.php');
    exit;
}

$id = $conn->real_escape_string($_GET['id']);
$sql = "SELECT * FROM customers WHERE cust_id='$id'";
$result = $conn->query($sql);

if (!$result || $result->num_rows == 0) {
    echo "<div class='alert alert-danger rounded-0'>خطأ: العميل غير موجود.</div>";
    require_once($dir_prefix . 'includes/footer.php');
    exit;
}

$customer = $result->fetch_assoc();
$cust_name = $customer['cust_name'];

// حساب إجمالي المبيعات الآجلة (المتبقية dis)
$sql_sales_sum = "SELECT SUM(dis) as total_debt FROM sales_items WHERE cust_name='$cust_name'";
$res_sales_sum = $conn->query($sql_sales_sum);
$row_sales_sum = $res_sales_sum->fetch_assoc();
$total_debt = isset($row_sales_sum['total_debt']) ? floatval($row_sales_sum['total_debt']) : 0.0;

// حساب إجمالي المدفوعات (total في جدول receipts)
$sql_mq_sum = "SELECT SUM(total) as total_paid FROM receipts WHERE cust_name='$cust_name'";
$res_mq_sum = $conn->query($sql_mq_sum);
$row_mq_sum = $res_mq_sum->fetch_assoc();
$total_paid = isset($row_mq_sum['total_paid']) ? floatval($row_mq_sum['total_paid']) : 0.0;

// المتبقي النهائي
$remaining_balance = $total_debt - $total_paid;

// جلب تفاصيل حركات المبيعات
$sql_sales = "SELECT * FROM sales_items WHERE cust_name='$cust_name' ORDER BY sales_id DESC";
$sales_result = $conn->query($sql_sales);

// جلب تفاصيل حركات المقبوضات (سندات القبض)
$sql_payments = "SELECT * FROM receipts WHERE cust_name='$cust_name' ORDER BY qid DESC";
$payments_result = $conn->query($sql_payments);
?>
<title>كشف حساب العميل: <?php echo htmlspecialchars($cust_name); ?> - تكنولوجيا فون</title>




<div class="row no-print mb-4">
    <div class="col-md-6">
        <h3 class="text-secondary font-weight-bold">
            <i class="fa fa-file-text-o ml-2"></i>كشف حساب العميل
        </h3>
        <p class="text-muted small mb-0">كشف حركات المبيعات وسندات القبض للعميل: <strong><?php echo htmlspecialchars($cust_name); ?></strong></p>
    </div>
    <div class="col-md-6 text-left">
        <a href="../sales/create.php" class="btn-flat btn-flat-primary btn-sm ml-2 text-decoration-none">
            <i class="fa fa-shopping-cart ml-1"></i>إضافة مبيعات
        </a>
        <a href="../receipts/create.php" class="btn-flat btn-flat-success btn-sm ml-2 text-decoration-none">
            <i class="fa fa-plus-circle ml-1"></i>إضافة مقبوضات
        </a>
        <button onclick="window.print()" class="btn-flat btn-flat-info btn-sm ml-2" style="background-color: var(--accent-info); color:#fff;">
            <i class="fa fa-print ml-1"></i>طباعة كشف الحساب
        </button>
        <a href="index.php" class="btn-flat btn-flat-secondary btn-sm text-decoration-none">
            <i class="fa fa-arrow-left ml-1"></i>عودة لقائمة العملاء
        </a>
    </div>
</div>

<!-- كشف الحساب التفصيلي -->
<div class="card-flat mb-4">
    <div class="card-header bg-light">
        <h5 class="font-weight-bold mb-0">
            <i class="fa fa-users ml-2 text-primary"></i>تفاصيل العميل: <?php echo htmlspecialchars($cust_name); ?>
            <?php if (!empty($customer['phone'])): ?>
                <span class="text-muted small ml-3">(جوال: <?php echo htmlspecialchars($customer['phone']); ?>)</span>
            <?php endif; ?>
        </h5>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-4">
                <div class="stat-card danger mb-3">
                    <div class="stat-info">
                        <h6>إجمالي مبيعات الأجل (المدين)</h6>
                        <h3><?php echo number_format($total_debt, 2); ?> ر.ي</h3>
                    </div>
                    <div class="stat-icon">
                        <i class="fa fa-shopping-bag text-danger"></i>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card success mb-3">
                    <div class="stat-info">
                        <h6>إجمالي المقبوضات (المسدد/الدائن)</h6>
                        <h3><?php echo number_format($total_paid, 2); ?> ر.ي</h3>
                    </div>
                    <div class="stat-icon">
                        <i class="fa fa-money text-success"></i>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <?php
                $balance_class = $remaining_balance > 0 ? 'danger' : ($remaining_balance < 0 ? 'success' : 'secondary');
                $balance_title = $remaining_balance > 0 ? 'المتبقي (عليه)' : ($remaining_balance < 0 ? 'المتبقي (له)' : 'المتبقي (متزن)');
                ?>
                <div class="stat-card <?php echo $balance_class; ?> mb-3">
                    <div class="stat-info">
                        <h6><?php echo $balance_title; ?></h6>
                        <h3><?php echo number_format(abs($remaining_balance), 2); ?> ر.ي</h3>
                    </div>
                    <div class="stat-icon">
                        <i class="fa fa-balance-scale"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- جدول الفواتير / المبيعات الآجلة -->
<div class="card-flat mb-4">
    <div class="card-header">
        <h5><i class="fa fa-shopping-cart ml-2"></i>سجل المبيعات والفواتير</h5>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table-flat">
                <thead>
                    <tr>
                        <th>رقم الفاتورة</th>
                        <th>المنتج</th>
                        <th>الكمية</th>
                        <th>سعر الوحدة</th>
                        <th>المجموع الكلي</th>
                        <th>المدفوع فوراً</th>
                        <th>الخصم</th>
                        <th>المتبقي (آجل)</th>
                        <th>التاريخ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($sales_result && $sales_result->num_rows > 0): ?>
                        <?php while ($s_row = $sales_result->fetch_assoc()): ?>
                            <tr>
                                <td class="font-weight-bold text-secondary"><?php echo htmlspecialchars($s_row['sales_id']); ?></td>
                                <td><?php echo htmlspecialchars($s_row['name']); ?></td>
                                <td><?php echo htmlspecialchars($s_row['quantity']); ?></td>
                                <td><?php echo number_format($s_row['unit_price'], 2); ?></td>
                                <td><?php echo number_format($s_row['all_tot'], 2); ?></td>
                                <td class="text-success"><?php echo number_format($s_row['bush'], 2); ?></td>
                                <td class="text-warning"><?php echo number_format($s_row['d'], 2); ?></td>
                                <td class="text-danger font-weight-bold"><?php echo number_format($s_row['dis'], 2); ?></td>
                                <td><?php echo htmlspecialchars($s_row['build_date']); ?></td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="9" class="text-center text-muted p-3">لا يوجد سجل مبيعات لهذا العميل.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- جدول سندات القبض والتسديدات -->
<div class="card-flat">
    <div class="card-header">
        <h5><i class="fa fa-money ml-2"></i>سجل سندات القبض والتسديدات</h5>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table-flat">
                <thead>
                    <tr>
                        <th>رقم السند</th>
                        <th>المبلغ المقبوض</th>
                        <th>البيان / الملاحظة</th>
                        <th>تاريخ السند</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($payments_result && $payments_result->num_rows > 0): ?>
                        <?php while ($p_row = $payments_result->fetch_assoc()): ?>
                            <tr>
                                <td class="font-weight-bold text-secondary"><?php echo htmlspecialchars($p_row['qid']); ?></td>
                                <td class="text-success font-weight-bold"><?php echo number_format($p_row['q_price'], 2); ?></td>
                                <td class="text-right pr-4"><?php echo htmlspecialchars($p_row['remark']); ?></td>
                                <td><?php echo htmlspecialchars($p_row['q_date']); ?></td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="4" class="text-center text-muted p-3">لا يوجد سجل سندات قبض لهذا العميل.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
require_once($dir_prefix . 'includes/footer.php');
?>
