<?php
$dir_prefix = '../';
$module = 'reports';
require_once($dir_prefix . 'includes/header.php');

check_permission(['admin']);
date_default_timezone_set("Asia/Aden");
$today = date("Y-m-d");

$selected_box_id = isset($_GET['box_id']) ? intval($_GET['box_id']) : 1;

// جلب تفاصيل حركات المبيعات اليوم
$sql = "SELECT si.* FROM sales_items si JOIN sales s ON si.sales_id = s.id WHERE s.build_date='$today' AND s.box_id = $selected_box_id ORDER BY si.id DESC";
$result = $conn->query($sql);

// جلب تفاصيل الأرباح اليوم
$sql9 = "SELECT * FROM sales WHERE build_date='$today' AND box_id = $selected_box_id ORDER BY id DESC";
$result9 = $conn->query($sql9);

// جلب تفاصيل المشتريات اليوم
$sql1 = "SELECT * FROM purchases WHERE date='$today' AND box_id = $selected_box_id ORDER BY id DESC";
$result1 = $conn->query($sql1);

// جلب تفاصيل المصروفات اليوم
$sql2 = "SELECT * FROM treasury_expenses WHERE s='0' AND sdate='$today' AND box_id = $selected_box_id ORDER BY sid DESC";
$result2 = $conn->query($sql2);

// جلب تفاصيل المقبوضات اليوم
$sql3 = "SELECT * FROM receipts WHERE s='0' AND q_date='$today' AND box_id = $selected_box_id ORDER BY qid DESC";
$result3 = $conn->query($sql3);

// حساب المجاميع
// 1. إجمالي المقبوضات اليوم
$sql4 = "SELECT SUM(q_price) as total_receipts FROM receipts WHERE q_date='$today' AND box_id = $selected_box_id";
$row4 = $conn->query($sql4)->fetch_assoc();
$total_receipts = isset($row4['total_receipts']) ? floatval($row4['total_receipts']) : 0.0;

// 2. إجمالي المقبوض فوراً من المبيعات اليوم
$sql5 = "SELECT SUM(si.bush) as total_sales_cash FROM sales_items si JOIN sales s ON si.sales_id = s.id WHERE s.build_date='$today' AND s.box_id = $selected_box_id";
$row5 = $conn->query($sql5)->fetch_assoc();
$total_sales_cash = isset($row5['total_sales_cash']) ? floatval($row5['total_sales_cash']) : 0.0;

// 3. إجمالي المشتريات اليوم
$sql6 = "SELECT SUM(total) as total_purchases FROM purchases WHERE date='$today' AND box_id = $selected_box_id";
$row6 = $conn->query($sql6)->fetch_assoc();
$total_purchases = isset($row6['total_purchases']) ? floatval($row6['total_purchases']) : 0.0;

// 4. إجمالي المصروفات اليوم
$sql7 = "SELECT SUM(sprice) as total_expenses FROM treasury_expenses WHERE sdate='$today' AND box_id = $selected_box_id";
$row7 = $conn->query($sql7)->fetch_assoc();
$total_expenses = isset($row7['total_expenses']) ? floatval($row7['total_expenses']) : 0.0;

// 5. رصيد الصندوق الحالي
$sql8 = "SELECT mony as current_box_balance FROM treasury WHERE box_id = $selected_box_id";
$row8 = $conn->query($sql8)->fetch_assoc();
$current_box_balance = isset($row8['current_box_balance']) ? floatval($row8['current_box_balance']) : 0.0;

// 6. الأرباح قبل الخصم اليوم
$s = "SELECT SUM(prifet) as profit_before_discount FROM sales WHERE build_date='$today' AND box_id = $selected_box_id";
$r = $conn->query($s)->fetch_assoc();
$profit_before_discount = isset($r['profit_before_discount']) ? floatval($r['profit_before_discount']) : 0.0;

// 7. إجمالي الخصومات اليوم
$ss = "SELECT SUM(si.d) as total_discounts FROM sales_items si JOIN sales s ON si.sales_id = s.id WHERE s.build_date='$today' AND s.box_id = $selected_box_id";
$rr = $conn->query($ss)->fetch_assoc();
$total_discounts = isset($rr['total_discounts']) ? floatval($rr['total_discounts']) : 0.0;

// الحسابات النهائية
$net_profit = $profit_before_discount - $total_discounts;

// حساب الرصيد الافتتاحي المقدر
$total_today_additions = $total_sales_cash + $total_receipts;
$total_today_subtractions = $total_expenses + $total_purchases;
$calculated_opening = $current_box_balance - $total_today_additions + $total_today_subtractions;

$net_cash_balance = $current_box_balance;
?>
<title>ملخص الحركة اليومية - تكنولوجيا فون</title>



<div class="row no-print mb-4">
    <div class="col-md-6">
        <h3 class="text-secondary font-weight-bold">
            <i class="fa fa-line-chart ml-2"></i>الحركة المالية اليومية
        </h3>
        <p class="text-muted small mb-0">مراقبة المبيعات والمشتريات والأرباح والمصاريف اليومية بشكل متكامل.</p>
    </div>
    <div class="col-md-6 text-left">
        <button onclick="window.print()" class="btn-flat btn-flat-info btn-sm ml-2" style="background-color: var(--accent-info); color:#fff;">
            <i class="bi bi-printer ml-1"></i>طباعة التقرير اليومي
        </button>
        <a href="history.php" class="btn-flat btn-flat-primary btn-sm ml-2 text-decoration-none">
            <i class="bi bi-calendar-check ml-1"></i>تقرير حسب التاريخ
        </a>
        <a href="../home.php" class="btn-flat btn-flat-secondary btn-sm text-decoration-none">
            <i class="bi bi-arrow-left ml-1"></i>العودة للرئيسية
        </a>
    </div>
</div>

<!-- فلتر الصناديق لتصفية الحركة الماليّة -->
<form method="GET" class="no-print mb-4 bg-light p-3 border">
    <div class="row align-items-center">
        <div class="col-md-4">
            <label class="form-label font-weight-bold text-secondary">عرض تقرير الصندوق المالي:</label>
            <select name="box_id" class="form-control rounded-0" onchange="this.form.submit()">
                <?php
                $res_b = $conn->query("SELECT box_id, name, mony FROM treasury ORDER BY box_id ASC");
                if ($res_b) {
                    while($b = $res_b->fetch_assoc()) {
                        $sel = ($b['box_id'] == $selected_box_id) ? 'selected' : '';
                        echo "<option value='{$b['box_id']}' $sel>" . htmlspecialchars($b['name']) . " (" . number_format($b['mony'], 2) . " ر.ي)</option>";
                    }
                }
                ?>
            </select>
        </div>
        <div class="col-md-8 mt-4">
            <span class="text-muted small">حدد الصندوق لتصفية كافة تفاصيل المبيعات والمشتريات والمصاريف والمقبوضات اليومية الخاصة به.</span>
        </div>
    </div>
</form>

<!-- ملخص الصندوق والربح الحاليين -->
<div class="row">
    <div class="col-md-4">
        <div class="stat-card mb-3">
            <div class="stat-info">
                <h6>رصيد الصندوق الدفتري الحالي المتوقع</h6>
                <h3><?php echo number_format($net_cash_balance, 2); ?> ر.ي</h3>
            </div>
            <div class="stat-icon">
                <i class="fa fa-archive text-info"></i>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="stat-card success mb-3">
            <div class="stat-info">
                <h6>صافي الأرباح اليومية اليوم</h6>
                <h3><?php echo number_format($net_profit, 2); ?> ر.ي</h3>
            </div>
            <div class="stat-icon">
                <i class="fa fa-line-chart text-success"></i>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="stat-card warning mb-3">
            <div class="stat-info">
                <h6>الرصيد الافتتاحي التقديري للصندوق</h6>
                <h3><?php echo number_format($calculated_opening, 2); ?> ر.ي</h3>
            </div>
            <div class="stat-icon">
                <i class="fa fa-bank text-warning"></i>
            </div>
        </div>
    </div>
</div>

<!-- جدول الحركة الإجمالية الملخصة بشكل محاسبي (مدين / دائن) -->
<div class="card-flat mb-4">
    <div class="card-header bg-light">
        <h5 class="font-weight-bold text-dark mb-0"><i class="fa fa-calculator ml-2"></i>خلاصة الحسابات - تقرير محاسبي</h5>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table report-table mb-0">
                <thead>
                    <tr>
                        <th>الحساب / البيان</th>
                        <th class="text-right">مدين (Dr)</th>
                        <th class="text-right">دائن (Cr)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $dr_opening = max(0, $calculated_opening);
                    $cr_opening = max(0, -$calculated_opening);
                    $dr_sales_cash = $total_sales_cash;
                    $dr_receipts = $total_receipts;
                    $cr_purchases = $total_purchases;
                    $cr_expenses = $total_expenses;
                    $cr_discounts = $total_discounts;

                    $total_dr = $dr_opening + $dr_sales_cash + $dr_receipts;
                    $total_cr = $cr_purchases + $cr_expenses + $cr_discounts + $cr_opening;

                    ?>
                    <tr>
                        <td>الرصيد الافتتاحي التقديري</td>
                        <td class="text-right"><?php echo number_format($dr_opening,2); ?></td>
                        <td class="text-right"><?php echo number_format($cr_opening,2); ?></td>
                    </tr>
                    <tr>
                        <td>المقبوض فوراً (مبيعات نقدية)</td>
                        <td class="text-right"><?php echo number_format($dr_sales_cash,2); ?></td>
                        <td class="text-right">0.00</td>
                    </tr>
                    <tr>
                        <td>سندات القبض (مقبوضات)</td>
                        <td class="text-right"><?php echo number_format($dr_receipts,2); ?></td>
                        <td class="text-right">0.00</td>
                    </tr>
                    <tr>
                        <td>المشتريات / المشتريات النقدية</td>
                        <td class="text-right">0.00</td>
                        <td class="text-right"><?php echo number_format($cr_purchases,2); ?></td>
                    </tr>
                    <tr>
                        <td>المصروفات</td>
                        <td class="text-right">0.00</td>
                        <td class="text-right"><?php echo number_format($cr_expenses,2); ?></td>
                    </tr>
                    <tr>
                        <td>الخصومات للعملاء</td>
                        <td class="text-right">0.00</td>
                        <td class="text-right"><?php echo number_format($cr_discounts,2); ?></td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr>
                        <th class="text-right">الإجمالي</th>
                        <th class="text-right"><?php echo number_format($total_dr,2); ?></th>
                        <th class="text-right"><?php echo number_format($total_cr,2); ?></th>
                    </tr>
                    <tr>
                        <th class="text-right">الرصيد الصافي</th>
                        <?php $net = $total_dr - $total_cr; ?>
                        <th class="text-right" colspan="2"><?php echo number_format(abs($net),2); ?> ر.ي &nbsp; <?php echo ($net>=0) ? 'لكم' : 'عليكم'; ?></th>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
    <div class="card-footer no-print text-left bg-white">
        <a href="../box/close.php?box_id=<?php echo $selected_box_id; ?>" class="btn-flat btn-flat-success text-decoration-none font-weight-bold" title="إقفال الوردية ومطابقة النقدية للصندوق">
            <?php echo get_icon('bank', 'ml-1'); ?> إقفال الوردية ومطابقة النقدية للصندوق
        </a>
    </div>
</div>

<!-- تفاصيل الحركات والعمليات -->
<div class="row">
    <!-- المبيعات اليوم -->
    <div class="col-lg-6">
        <div class="card-flat mb-4">
            <div class="card-header bg-light">
                <h5 class="text-secondary"><i class="fa fa-shopping-cart ml-2"></i>تفاصيل المبيعات اليوم</h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="report-table mb-0">
                        <thead>
                            <tr>
                                <th>العميل</th>
                                <th>المنتج</th>
                                <th>الكمية</th>
                                <th>المجموع الكلي</th>
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
                                    <td colspan="6" class="text-center text-muted py-3">لا توجد عمليات مبيعات اليوم.</td>
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
                <h5 class="text-secondary"><i class="fa fa-line-chart ml-2"></i>سجل أرباح الفواتير اليوم</h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="report-table mb-0">
                        <thead>
                            <tr>
                                <th>رقم الفاتورة</th>
                                <th>اسم العميل</th>
                                <th>ربح الفاتورة</th>
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
                                    <td colspan="4" class="text-center text-muted py-3">لا توجد أرباح فواتير مسجلة اليوم.</td>
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
                <h5 class="text-secondary"><i class="fa fa-truck ml-2"></i>فواتير المشتريات اليوم</h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="report-table mb-0">
                        <thead>
                            <tr>
                                <th>المورد</th>
                                <th>القيمة الإجمالية</th>
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
                                    <td colspan="3" class="text-center text-muted py-3">لا توجد فواتير مشتريات اليوم.</td>
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
                <h5 class="text-secondary"><i class="fa fa-minus-circle ml-2"></i>مصروفات وسندات الصرف اليوم</h5>
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
                                    <td colspan="4" class="text-center text-muted py-3">لا توجد مصروفات مسجلة اليوم.</td>
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
                <h5 class="text-secondary"><i class="fa fa-plus-circle ml-2"></i>مقبوضات وسندات القبض اليوم</h5>
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
                                    <td colspan="3" class="text-center text-muted py-3">لا توجد سندات قبض مسجلة اليوم.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
require_once($dir_prefix . 'includes/footer.php');
?>

