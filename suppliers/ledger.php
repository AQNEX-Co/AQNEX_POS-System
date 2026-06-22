<?php
$dir_prefix = '../';
$module = 'suppliers';
require_once($dir_prefix . 'includes/header.php');

check_permission(['admin', 'inventory']);
// التأكد من تمرير رقم تعريف المورد
if (!isset($_GET['id']) || empty($_GET['id'])) {
    echo "<div class='alert alert-danger rounded-0'>خطأ: لم يتم تحديد المورد.</div>";
    require_once($dir_prefix . 'includes/footer.php');
    exit;
}

$id = $conn->real_escape_string($_GET['id']);
$sql = "SELECT * FROM Suppliers WHERE supp_id='$id'";
$result = $conn->query($sql);

if (!$result || $result->num_rows == 0) {
    echo "<div class='alert alert-danger rounded-0'>خطأ: المورد غير موجود.</div>";
    require_once($dir_prefix . 'includes/footer.php');
    exit;
}

$supplier = $result->fetch_assoc();
$supp_name = $supplier['supp_name'];

// حساب إجمالي المشتريات (buy_price في جدول purchase_items)
$sql_buys_sum = "SELECT SUM(buy_price) as total_bought FROM purchase_items WHERE supp_name='$supp_name'";
$res_buys_sum = $conn->query($sql_buys_sum);
$row_buys_sum = $res_buys_sum->fetch_assoc();
$total_bought = isset($row_buys_sum['total_bought']) ? floatval($row_buys_sum['total_bought']) : 0.0;

// حساب إجمالي المبالغ المسددة للمورد (bush_price في جدول bush)
$sql_bush_sum = "SELECT SUM(bush_price) as total_paid FROM supplier_payments WHERE supp_name='$supp_name'";
$res_bush_sum = $conn->query($sql_bush_sum);
$row_bush_sum = $res_bush_sum->fetch_assoc();
$total_paid = isset($row_bush_sum['total_paid']) ? floatval($row_bush_sum['total_paid']) : 0.0;

// المتبقي النهائي للمورد
$remaining_balance = $total_bought - $total_paid;

// جلب تفاصيل حركات الشراء
$sql_buys = "SELECT * FROM purchase_items WHERE supp_name='$supp_name' ORDER BY buyid DESC";
$buys_result = $conn->query($sql_buys);

// جلب تفاصيل سندات التسديد للمورد
$sql_bush = "SELECT * FROM supplier_payments WHERE supp_name='$supp_name' ORDER BY bush_id DESC";
$bush_result = $conn->query($sql_bush);
?>
<title>كشف حساب المورد: <?php echo htmlspecialchars($supp_name); ?> - تكنولوجيا فون</title>



<div class="row no-print mb-4">
    <div class="col-md-6">
        <h3 class="text-secondary font-weight-bold">
            <i class="fa fa-file-text-o ml-2"></i>كشف حساب المورد
        </h3>
        <p class="text-muted small mb-0">كشف حركات المشتريات وسندات الصرف والصرف للمورد: <strong><?php echo htmlspecialchars($supp_name); ?></strong></p>
    </div>
    <div class="col-md-6 text-left">
        <a href="../purchases/create.php" class="btn-flat btn-flat-primary btn-sm ml-2 text-decoration-none">
            <i class="fa fa-truck ml-1"></i>إضافة مشتريات
        </a>
        <a href="pay.php" class="btn-flat btn-flat-success btn-sm ml-2 text-decoration-none">
            <i class="fa fa-money ml-1"></i>تسديد مورد
        </a>
        <button onclick="window.print()" class="btn-flat btn-flat-info btn-sm ml-2" style="background-color: var(--accent-info); color:#fff;">
            <i class="fa fa-print ml-1"></i>طباعة كشف الحساب
        </button>
        <a href="index.php" class="btn-flat btn-flat-secondary btn-sm text-decoration-none">
            <i class="fa fa-arrow-left ml-1"></i>عودة لقائمة الموردين
        </a>
    </div>
</div>

<!-- كشف الحساب التفصيلي -->
<div class="card-flat mb-4">
    <div class="card-header bg-light">
        <h5 class="font-weight-bold mb-0">
            <i class="fa fa-briefcase ml-2 text-primary"></i>تفاصيل المورد: <?php echo htmlspecialchars($supp_name); ?>
            <?php if (!empty($supplier['phone'])): ?>
                <span class="text-muted small ml-3">(جوال: <?php echo htmlspecialchars($supplier['phone']); ?>)</span>
            <?php endif; ?>
        </h5>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-4">
                <div class="stat-card danger mb-3">
                    <div class="stat-info">
                        <h6>إجمالي المشتريات (المدين)</h6>
                        <h3><?php echo number_format($total_bought, 2); ?> ر.ي</h3>
                    </div>
                    <div class="stat-icon">
                        <i class="fa fa-truck text-danger"></i>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card success mb-3">
                    <div class="stat-info">
                        <h6>إجمالي المسدد (الدائن)</h6>
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
                $balance_title = $remaining_balance > 0 ? 'المتبقي له (دائن)' : ($remaining_balance < 0 ? 'المتبقي عليه (مدين)' : 'المتبقي (متزن)');
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

<!-- جدول حركات الشراء -->
<div class="card-flat mb-4">
    <div class="card-header">
        <h5><i class="fa fa-truck ml-2"></i>سجل حركات الشراء والتوريد</h5>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="report-table">
                <thead>
                    <tr>
                        <th>رقم الحركة</th>
                        <th>اسم المنتج</th>
                        <th>الكمية الموردة</th>
                        <th>قيمة الحركة الكلية</th>
                        <th>المدفوع فوراً</th>
                        <th>المتبقي ذمة</th>
                        <th>تاريخ الحركة</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($buys_result && $buys_result->num_rows > 0): ?>
                        <?php while ($b_row = $buys_result->fetch_assoc()): ?>
                            <tr>
                                <td class="font-weight-bold text-secondary"><?php echo htmlspecialchars($b_row['buyid']); ?></td>
                                <td class="font-weight-bold"><?php echo htmlspecialchars($b_row['name']); ?></td>
                                <td><?php echo htmlspecialchars($b_row['quantity']); ?></td>
                                <td><?php echo number_format($b_row['buy_price'], 2); ?></td>
                                <td class="text-success"><?php echo number_format($b_row['pushtosupp'], 2); ?></td>
                                <td class="text-danger font-weight-bold"><?php echo number_format($b_row['total_d'], 2); ?></td>
                                <td><?php echo htmlspecialchars($b_row['buys_date']); ?></td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" class="text-center text-muted p-3">لا يوجد سجل مشتريات من هذا المورد.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- جدول سندات الصرف / التسديدات للمورد -->
<div class="card-flat">
    <div class="card-header">
        <h5><i class="fa fa-money ml-2"></i>سجل مبالغ التسديدات (سندات الصرف)</h5>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="report-table">
                <thead>
                    <tr>
                        <th>رقم السند</th>
                        <th>المبلغ المسدد</th>
                        <th>البيان / الملاحظات</th>
                        <th>تاريخ السند</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($bush_result && $bush_result->num_rows > 0): ?>
                        <?php while ($bu_row = $bush_result->fetch_assoc()): ?>
                            <tr>
                                <td class="font-weight-bold text-secondary"><?php echo htmlspecialchars($bu_row['bush_id']); ?></td>
                                <td class="text-success font-weight-bold"><?php echo number_format($bu_row['bush_price'], 2); ?></td>
                                <td class="text-right pr-4"><?php echo htmlspecialchars($bu_row['remark']); ?></td>
                                <td><?php echo htmlspecialchars($bu_row['bush_date']); ?></td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="4" class="text-center text-muted p-3">لا يوجد سجل تسديدات مسجل لهذا المورد.</td>
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
