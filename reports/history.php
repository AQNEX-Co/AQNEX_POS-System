<?php
$dir_prefix = '../';
$module = 'reports';
require_once($dir_prefix . 'includes/header.php');

check_permission(['admin']);
$selected_date = '';
$has_results = false;

if (isset($_POST['btn']) && !empty($_POST['start_date'])) {
    $selected_date = $conn->real_escape_string($_POST['start_date']);
    $has_results = true;

    // جلب حركات المبيعات
    $sql = "SELECT * FROM sales_items WHERE build_date='$selected_date' ORDER BY id DESC";
    $result = $conn->query($sql);

    // جلب الأرباح
    $sql9 = "SELECT * FROM sales WHERE build_date='$selected_date' ORDER BY id DESC";
    $result9 = $conn->query($sql9);

    // جلب المشتريات
    $sql1 = "SELECT * FROM purchases WHERE date='$selected_date' ORDER BY id DESC";
    $result1 = $conn->query($sql1);

    // جلب المصروفات
    $sql2 = "SELECT * FROM treasury_expenses WHERE s='0' AND sdate='$selected_date' ORDER BY id DESC";
    $result2 = $conn->query($sql2);

    // جلب المقبوضات
    $sql3 = "SELECT * FROM receipts WHERE s='0' AND q_date='$selected_date' ORDER BY qid DESC";
    $result3 = $conn->query($sql3);

    // حساب المجاميع
    $sql4 = "SELECT SUM(q_price) as total_receipts FROM receipts WHERE q_date='$selected_date'";
    $row4 = $conn->query($sql4)->fetch_assoc();
    $total_receipts = isset($row4['total_receipts']) ? floatval($row4['total_receipts']) : 0.0;

    $sql5 = "SELECT SUM(bush) as total_sales_cash FROM sales_items WHERE build_date='$selected_date'";
    $row5 = $conn->query($sql5)->fetch_assoc();
    $total_sales_cash = isset($row5['total_sales_cash']) ? floatval($row5['total_sales_cash']) : 0.0;

    $sql6 = "SELECT SUM(buy_price) as total_purchases FROM purchase_items WHERE buys_date='$selected_date'";
    $row6 = $conn->query($sql6)->fetch_assoc();
    $total_purchases = isset($row6['total_purchases']) ? floatval($row6['total_purchases']) : 0.0;

    $sql7 = "SELECT SUM(sprice) as total_expenses FROM treasury_expenses WHERE sdate='$selected_date'";
    $row7 = $conn->query($sql7)->fetch_assoc();
    $total_expenses = isset($row7['total_expenses']) ? floatval($row7['total_expenses']) : 0.0;

    $s = "SELECT SUM(prifet) as profit_before_discount FROM sales WHERE build_date='$selected_date'";
    $r = $conn->query($s)->fetch_assoc();
    $profit_before_discount = isset($r['profit_before_discount']) ? floatval($r['profit_before_discount']) : 0.0;

    $ss = "SELECT SUM(d) as total_discounts FROM sales_items WHERE build_date='$selected_date'";
    $rr = $conn->query($ss)->fetch_assoc();
    $total_discounts = isset($rr['total_discounts']) ? floatval($rr['total_discounts']) : 0.0;

    $net_profit = $profit_before_discount - $total_discounts;
    $net_cash_balance = ($total_receipts + $total_sales_cash) - $total_expenses;
}
?>
<title>الحركة اليومية حسب التاريخ - تكنولوجيا فون</title>



<div class="row no-print mb-4">
    <div class="col-md-6">
        <h3 class="text-secondary font-weight-bold">
            <i class="fa fa-calendar ml-2"></i>الحركة حسب التاريخ
        </h3>
        <p class="text-muted small mb-0">البحث واستعراض التقارير اليومية التفصيلية لأي تاريخ سابق.</p>
    </div>
    <div class="col-md-6 text-left">
        <?php if ($has_results): ?>
            <button onclick="window.print()" class="btn-flat btn-flat-info btn-sm ml-2" style="background-color: var(--accent-info); color:#fff;">
                <i class="fa fa-print ml-1"></i>طباعة الحركة اليومية
            </button>
        <?php endif; ?>
        <a href="daily.php" class="btn-flat btn-flat-success btn-sm ml-2 text-decoration-none">
            <i class="fa fa-line-chart ml-1"></i>حركة اليوم الحالية
        </a>
        <a href="../home.php" class="btn-flat btn-flat-secondary btn-sm text-decoration-none">
            <i class="fa fa-arrow-left ml-1"></i>العودة للرئيسية
        </a>
    </div>
</div>

<!-- نموذج البحث عن التاريخ -->
<div class="card-flat no-print mb-4">
    <div class="card-header">
        <h5><i class="fa fa-search ml-2 text-primary"></i>تصفية التقرير بالتاريخ</h5>
    </div>
    <div class="card-body">
        <form method="POST">
            <div class="row align-items-end justify-content-center">
                <div class="col-md-4">
                    <div class="form-group mb-0">
                        <label class="font-weight-bold text-secondary mb-2">اختر التاريخ المطلوب</label>
                        <input class="form-control rounded-0" name="start_date" type="date" value="<?php echo $selected_date ? $selected_date : date('Y-m-d'); ?>" required>
                    </div>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn-flat btn-flat-primary btn-block py-2" name="btn">
                        <i class="fa fa-search ml-1"></i>عرض التقرير
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<?php if ($has_results): ?>
    <!-- ملخص الصندوق والربح للحركة المبحوثة -->
    <div class="row mb-4">
        <div class="col-md-6">
            <div class="stat-card mb-3">
                <div class="stat-info">
                    <h6>التدفق النقدي الصافي لهذا اليوم</h6>
                    <h3><?php echo number_format($net_cash_balance, 2); ?> ر.ي</h3>
                </div>
                <div class="stat-icon">
                    <i class="fa fa-archive text-info"></i>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="stat-card success mb-3">
                <div class="stat-info">
                    <h6>صافي أرباح العمليات لهذا اليوم</h6>
                    <h3><?php echo number_format($net_profit, 2); ?> ر.ي</h3>
                </div>
                <div class="stat-icon">
                    <i class="fa fa-line-chart text-success"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- جدول حركة الحسابات الإجمالية -->
    <div class="card-flat mb-4">
        <div class="card-header bg-light">
            <h5 class="font-weight-bold text-dark mb-0"><i class="fa fa-calculator ml-2"></i>خلاصة التدفق النقدي بتاريخ: <?php echo htmlspecialchars($selected_date); ?></h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="report-table mb-0">
                    <thead>
                        <tr>
                            <th>المقبوض نقداً (من المبيعات)</th>
                            <th>سندات القبض المستلمة</th>
                            <th>قيمة المشتريات الإجمالية</th>
                            <th>المصروفات وسندات الصرف</th>
                            <th>الخصومات الممنوحة</th>
                            <th>صافي التغير المالي لليوم</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td class="text-success font-weight-bold">+<?php echo number_format($total_sales_cash, 2); ?></td>
                            <td class="text-success font-weight-bold">+<?php echo number_format($total_receipts, 2); ?></td>
                            <td class="text-danger font-weight-bold"><?php echo number_format($total_purchases, 2); ?></td>
                            <td class="text-danger font-weight-bold">-<?php echo number_format($total_expenses, 2); ?></td>
                            <td class="text-warning"><?php echo number_format($total_discounts, 2); ?></td>
                            <td class="font-weight-bold text-primary" style="font-size: 1.15rem;"><?php echo number_format($net_cash_balance, 2); ?> ر.ي</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- تفاصيل الحركات -->
    <div class="row">
        <!-- المبيعات اليوم -->
        <div class="col-lg-6">
            <div class="card-flat mb-4">
                <div class="card-header bg-light">
                    <h5 class="text-secondary"><i class="fa fa-shopping-cart ml-2"></i>تفاصيل المبيعات</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="report-table mb-0">
                            <thead>
                                <tr>
                                    <th>العميل</th>
                                    <th>المنتج</th>
                                    <th>الكمية</th>
                                    <th>المجموع</th>
                                    <th>المدفوع</th>
                                    <th>المتبقي</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($result && $result->num_rows > 0): ?>
                                    <?php while ($rows = $result->fetch_assoc()): ?>
                                        <tr>
                                            <td class="font-weight-bold text-secondary"><?php echo htmlspecialchars($rows['cust_name']); ?></td>
                                            <td class="font-weight-bold"><?php echo htmlspecialchars($rows['name']); ?></td>
                                            <td><?php echo htmlspecialchars($rows['quantity']); ?></td>
                                            <td><?php echo number_format($rows['all_tot'], 2); ?></td>
                                            <td class="text-success"><?php echo number_format($rows['bush'], 2); ?></td>
                                            <td class="text-danger"><?php echo number_format($rows['dis'], 2); ?></td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="6" class="text-center text-muted py-3">لا توجد عمليات مبيعات مسجلة في هذا اليوم.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- أرباح فواتير المبيعات -->
        <div class="col-lg-6">
            <div class="card-flat mb-4">
                <div class="card-header bg-light">
                    <h5 class="text-secondary"><i class="fa fa-line-chart ml-2"></i>سجل أرباح العمليات</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="report-table mb-0">
                            <thead>
                                <tr>
                                    <th>رقم الفاتورة</th>
                                    <th>اسم العميل</th>
                                    <th>الربح</th>
                                    <th>البيان</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($result9 && $result9->num_rows > 0): ?>
                                    <?php while ($rows9 = $result9->fetch_assoc()): ?>
                                        <tr>
                                            <td>#<?php echo htmlspecialchars($rows9['id']); ?></td>
                                            <td class="font-weight-bold text-secondary"><?php echo htmlspecialchars($rows9['cust_name']); ?></td>
                                            <td class="text-success font-weight-bold"><?php echo number_format($rows9['prifet'], 2); ?></td>
                                            <td><?php echo htmlspecialchars($rows9['remark']); ?></td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="4" class="text-center text-muted py-3">لا توجد أرباح فواتير مسجلة في هذا اليوم.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- مشتريات اليوم -->
        <div class="col-lg-4">
            <div class="card-flat mb-4">
                <div class="card-header bg-light">
                    <h5 class="text-secondary"><i class="fa fa-truck ml-2"></i>المشتريات والتوريدات</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="report-table mb-0">
                            <thead>
                                <tr>
                                    <th>المورد</th>
                                    <th>القيمة إجمالاً</th>
                                    <th>ملاحظات</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($result1 && $result1->num_rows > 0): ?>
                                    <?php while ($rows1 = $result1->fetch_assoc()): ?>
                                        <tr>
                                            <td class="font-weight-bold text-secondary"><?php echo htmlspecialchars($rows1['supp_name']); ?></td>
                                            <td><?php echo number_format($rows1['total'], 2); ?></td>
                                            <td><?php echo htmlspecialchars($rows1['remark']); ?></td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="3" class="text-center text-muted py-3">لا توجد فواتير مشتريات في هذا اليوم.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- مصروفات اليوم -->
        <div class="col-lg-4">
            <div class="card-flat mb-4">
                <div class="card-header bg-light">
                    <h5 class="text-secondary"><i class="fa fa-minus-circle ml-2"></i>المصروفات وسندات الصرف</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="report-table mb-0">
                            <thead>
                                <tr>
                                    <th>النوع</th>
                                    <th>الجهة المستلمة</th>
                                    <th>المبلغ</th>
                                    <th>البيان</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($result2 && $result2->num_rows > 0): ?>
                                    <?php while ($rows2 = $result2->fetch_assoc()): ?>
                                        <tr>
                                            <td><span class="badge badge-danger p-1 font-weight-normal"><?php echo htmlspecialchars($rows2['st']); ?></span></td>
                                            <td class="font-weight-bold text-secondary"><?php echo htmlspecialchars($rows2['sname']); ?></td>
                                            <td class="text-danger font-weight-bold"><?php echo number_format($rows2['sprice'], 2); ?></td>
                                            <td><?php echo htmlspecialchars($rows2['sremark']); ?></td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="4" class="text-center text-muted py-3">لا توجد مصروفات مسجلة في هذا اليوم.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- مقبوضات اليوم -->
        <div class="col-lg-4">
            <div class="card-flat mb-4">
                <div class="card-header bg-light">
                    <h5 class="text-secondary"><i class="fa fa-plus-circle ml-2"></i>سندات القبض والمقبوضات</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="report-table mb-0">
                            <thead>
                                <tr>
                                    <th>اسم العميل</th>
                                    <th>المبلغ المقبوض</th>
                                    <th>البيان</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($result3 && $result3->num_rows > 0): ?>
                                    <?php while ($rows3 = $result3->fetch_assoc()): ?>
                                        <tr>
                                            <td class="font-weight-bold text-secondary"><?php echo htmlspecialchars($rows3['cust_name']); ?></td>
                                            <td class="text-success font-weight-bold"><?php echo number_format($rows3['q_price'], 2); ?></td>
                                            <td><?php echo htmlspecialchars($rows3['remark']); ?></td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="3" class="text-center text-muted py-3">لا توجد سندات قبض مسجلة في هذا اليوم.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php else: ?>
    <!-- رسالة تنبيه للمستخدم لاختيار تاريخ -->
    <div class="card-flat p-5 text-center text-muted no-print">
        <i class="fa fa-calendar-o mb-3 text-secondary" style="font-size: 3.5rem;"></i>
        <h4>الرجاء اختيار تاريخ من النموذج أعلاه واستعراض تفاصيل حركات العمليات والتدفق المالي.</h4>
    </div>
<?php endif; ?>

<?php
require_once($dir_prefix . 'includes/footer.php');
?>

