<?php
$dir_prefix = '../';
$module = 'reports';
require_once($dir_prefix . 'includes/header.php');

check_permission(['admin']);
$start_date = '';
$end_date = '';
$has_search = false;

$where_sales = '';
$where_expenses = '';

if (isset($_GET['start_date']) && isset($_GET['end_date']) && !empty($_GET['start_date']) && !empty($_GET['end_date'])) {
    $start_date = $conn->real_escape_string($_GET['start_date']);
    $end_date = $conn->real_escape_string($_GET['end_date']);
    $has_search = true;
    
    $where_sales = " AND (DATE(build_date) BETWEEN '$start_date' AND '$end_date') ";
    $where_expenses = " AND (DATE(m_date) BETWEEN '$start_date' AND '$end_date') ";
}

// جلب حركات المبيعات خلال الفترة
$sql_sales = "SELECT * FROM sales WHERE delete_status='0' $where_sales ORDER BY id DESC";
$result_sales = $conn->query($sql_sales);

// جلب المصروفات العامة خلال الفترة (باستثناء تسديدات الموردين في treasury_expenses)
$sql_expenses = "SELECT * FROM expenses WHERE s='0' $where_expenses ORDER BY m_id DESC";
$result_expenses = $conn->query($sql_expenses);

// حساب المجاميع خلال الفترة
$total_profit = 0.0;
$total_spent = 0.0;

if ($has_search) {
    $sql_profit_sum = "SELECT SUM(prifet) as sum_profit FROM sales WHERE build_date BETWEEN '$start_date' AND '$end_date'";
    $row_p = $conn->query($sql_profit_sum)->fetch_assoc();
    $total_profit = isset($row_p['sum_profit']) ? floatval($row_p['sum_profit']) : 0.0;

    $sql_spent_sum = "SELECT SUM(m_price) as sum_spent FROM expenses WHERE m_date BETWEEN '$start_date' AND '$end_date'";
    $row_s = $conn->query($sql_spent_sum)->fetch_assoc();
    $total_spent = isset($row_s['sum_spent']) ? floatval($row_s['sum_spent']) : 0.0;
}

$net_profit = $total_profit - $total_spent;
?>
<title>تقرير المبيعات والأرباح - تكنولوجيا فون</title>




<div class="row no-print mb-4">
    <div class="col-md-6">
        <h3 class="text-secondary font-weight-bold">
            <i class="fa fa-line-chart ml-2"></i>الأرباح والمبيعات الدورية
        </h3>
        <p class="text-muted small mb-0">تحليل الأرباح وصافي الدخل من المبيعات والمصروفات خلال فترة زمنية معينة.</p>
    </div>
    <div class="col-md-6 text-left">
        <?php if ($has_search): ?>
            <button onclick="window.print()" class="btn-flat btn-flat-info btn-sm ml-2" style="background-color: var(--accent-info); color:#fff;">
                <i class="fa fa-print ml-1"></i>طباعة التقرير الدوري
            </button>
        <?php endif; ?>
        <a href="../home.php" class="btn-flat btn-flat-secondary btn-sm text-decoration-none">
            <i class="fa fa-arrow-left ml-1"></i>العودة للرئيسية
        </a>
    </div>
</div>

<!-- نموذج الفرز بالفترة الزمنية -->
<div class="card-flat no-print mb-4">
    <div class="card-header">
        <h5><i class="fa fa-search ml-2 text-primary"></i>تحديد الفترة الزمنية للتقرير</h5>
    </div>
    <div class="card-body">
        <form method="GET">
            <div class="row align-items-end justify-content-center">
                <div class="col-md-4">
                    <div class="form-group mb-0">
                        <label class="font-weight-bold text-secondary mb-2">من تاريخ</label>
                        <input type="date" name="start_date" class="form-control rounded-0" value="<?php echo $start_date ? $start_date : date('Y-m-d', strtotime('-1 month')); ?>" required>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="form-group mb-0">
                        <label class="font-weight-bold text-secondary mb-2">إلى تاريخ</label>
                        <input type="date" name="end_date" class="form-control rounded-0" value="<?php echo $end_date ? $end_date : date('Y-m-d'); ?>" required>
                    </div>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn-flat btn-flat-primary btn-block py-2">
                        <i class="fa fa-filter ml-1"></i>تطبيق الفلتر
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<?php if ($has_search): ?>
    <!-- البطاقات الإحصائية للمجاميع -->
    <div class="row mb-4">
        <div class="col-md-4">
            <div class="stat-card success mb-3">
                <div class="stat-info">
                    <h6>إجمالي أرباح المبيعات</h6>
                    <h3><?php echo number_format($total_profit, 2); ?> ر.ي</h3>
                </div>
                <div class="stat-icon">
                    <i class="fa fa-line-chart text-success"></i>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stat-card danger mb-3">
                <div class="stat-info">
                    <h6>إجمالي المصروفات العامة</h6>
                    <h3><?php echo number_format($total_spent, 2); ?> ر.ي</h3>
                </div>
                <div class="stat-icon">
                    <i class="fa fa-minus-circle text-danger"></i>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <?php
            $net_class = $net_profit >= 0 ? 'success' : 'danger';
            ?>
            <div class="stat-card <?php echo $net_class; ?> mb-3">
                <div class="stat-info">
                    <h6>صافي الأرباح الدورية (الخلاصة)</h6>
                    <h3><?php echo number_format($net_profit, 2); ?> ر.ي</h3>
                </div>
                <div class="stat-icon">
                    <i class="fa fa-balance-scale"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- كود المخطط البياني الدوري -->
    <?php
    $daily_sales = [];
    $daily_expenses = [];
    $dates_range = [];

    // توليد نطاق التواريخ بين البداية والنهاية
    $start = new DateTime($start_date);
    $end = new DateTime($end_date);
    $interval = new DateInterval('P1D');
    $date_period = new DatePeriod($start, $interval, $end->modify('+1 day'));

    foreach ($date_period as $dt) {
        $d_str = $dt->format("Y-m-d");
        $dates_range[] = $d_str;
        $daily_sales[$d_str] = 0;
        $daily_expenses[$d_str] = 0;
    }

    // استعلام الأرباح اليومية
    $sql_daily_sales = "SELECT build_date, SUM(prifet) as daily_profit FROM sales 
                        WHERE delete_status = 0 AND (build_date BETWEEN '$start_date' AND '$end_date') 
                        GROUP BY build_date";
    $res_ds = $conn->query($sql_daily_sales);
    if ($res_ds) {
        while ($r = $res_ds->fetch_assoc()) {
            $d = $r['build_date'];
            if (isset($daily_sales[$d])) {
                $daily_sales[$d] = doubleval($r['daily_profit']);
            }
        }
    }

    // استعلام المصروفات اليومية
    $sql_daily_exp = "SELECT m_date, SUM(m_price) as daily_spent FROM expenses 
                      WHERE (m_date BETWEEN '$start_date' AND '$end_date') 
                      GROUP BY m_date";
    $res_de = $conn->query($sql_daily_exp);
    if ($res_de) {
        while ($r = $res_de->fetch_assoc()) {
            $d = $r['m_date'];
            if (isset($daily_expenses[$d])) {
                $daily_expenses[$d] = doubleval($r['daily_spent']);
            }
        }
    }

    $formatted_dates = [];
    foreach ($dates_range as $d) {
        $formatted_dates[] = date('d/m', strtotime($d));
    }

    $dates_count = count($dates_range);
    if ($dates_count > 31) {
        // تجميع شهري للمدد الطويلة
        $monthly_sales = [];
        $monthly_expenses = [];
        
        $sql_m_sales = "SELECT DATE_FORMAT(build_date, '%Y-%m') as ym, SUM(prifet) as profit FROM sales 
                        WHERE delete_status = 0 AND (build_date BETWEEN '$start_date' AND '$end_date') 
                        GROUP BY ym ORDER BY ym";
        $res_ms = $conn->query($sql_m_sales);
        if ($res_ms) {
            while ($r = $res_ms->fetch_assoc()) {
                $monthly_sales[$r['ym']] = doubleval($r['profit']);
            }
        }
        
        $sql_m_exp = "SELECT DATE_FORMAT(m_date, '%Y-%m') as ym, SUM(m_price) as spent FROM expenses 
                      WHERE (m_date BETWEEN '$start_date' AND '$end_date') 
                      GROUP BY ym ORDER BY ym";
        $res_me = $conn->query($sql_m_exp);
        if ($res_me) {
            while ($r = $res_me->fetch_assoc()) {
                $monthly_expenses[$r['ym']] = doubleval($r['spent']);
            }
        }
        
        $all_yms = array_unique(array_merge(array_keys($monthly_sales), array_keys($monthly_expenses)));
        sort($all_yms);
        
        $chart_labels = [];
        $chart_sales = [];
        $chart_expenses = [];
        foreach ($all_yms as $ym) {
            $chart_labels[] = date('m / Y', strtotime($ym . '-01'));
            $chart_sales[] = isset($monthly_sales[$ym]) ? $monthly_sales[$ym] : 0;
            $chart_expenses[] = isset($monthly_expenses[$ym]) ? $monthly_expenses[$ym] : 0;
        }
    } else {
        // تجميع يومي عادي
        $chart_labels = $formatted_dates;
        $chart_sales = array_values($daily_sales);
        $chart_expenses = array_values($daily_expenses);
    }
    ?>
    
    <div class="row mb-4">
        <div class="col-12">
            <div class="card-flat">
                <div class="card-header bg-light">
                    <h6 class="font-weight-bold text-secondary mb-0">
                        <?php echo get_icon('line-chart', 'ml-2 text-primary'); ?> المنحنى المالي للأرباح والمصروفات خلال الفترة المحددة
                    </h6>
                </div>
                <div class="card-body">
                    <div style="position: relative; height: 260px; width: 100%;">
                        <canvas id="periodicFinancialChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="<?php echo $dir_prefix; ?>files/chart.js"></script>
    <script>
    document.addEventListener("DOMContentLoaded", function() {
        const ctxPeriodic = document.getElementById('periodicFinancialChart').getContext('2d');
        new Chart(ctxPeriodic, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($chart_labels); ?>,
                datasets: [
                    {
                        label: 'أرباح المبيعات',
                        data: <?php echo json_encode($chart_sales); ?>,
                        borderColor: '#2ecc71',
                        backgroundColor: 'rgba(46, 204, 113, 0.08)',
                        borderWidth: 2,
                        pointBackgroundColor: '#2ecc71',
                        pointBorderColor: '#fff',
                        pointRadius: 4,
                        tension: 0.3,
                        fill: true
                    },
                    {
                        label: 'المصروفات العامة',
                        data: <?php echo json_encode($chart_expenses); ?>,
                        borderColor: '#e74c3c',
                        backgroundColor: 'rgba(231, 76, 60, 0.08)',
                        borderWidth: 2,
                        pointBackgroundColor: '#e74c3c',
                        pointBorderColor: '#fff',
                        pointRadius: 4,
                        tension: 0.3,
                        fill: true
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top',
                        rtl: true,
                        labels: {
                            boxWidth: 15,
                            font: { family: 'Arial', size: 12 }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        position: 'right',
                        grid: {
                            color: 'rgba(0, 0, 0, 0.05)'
                        },
                        ticks: {
                            font: { family: 'Arial', size: 11 }
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        },
                        ticks: {
                            font: { family: 'Arial', size: 11 }
                        }
                    }
                }
            }
        });
    });
    </script>

    <div class="alert alert-info rounded-0 no-print mb-4">
        <strong>* ملاحظة محاسبية:</strong> يتم احتساب صافي الأرباح على أساس خصم إجمالي المصروفات العامة (عدا تسديدات حسابات الموردين في المخزن) من إجمالي أرباح المبيعات المحققة خلال هذه الفترة المحددة.
    </div>

    <!-- جداول تفاصيل الفترة -->
    <div class="row">
        <!-- سجل المبيعات خلال الفترة -->
        <div class="col-lg-7">
            <div class="card-flat mb-4">
                <div class="card-header bg-light">
                    <h5><i class="fa fa-shopping-cart ml-2"></i>تفاصيل فواتير المبيعات والأرباح المحققة</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="report-table mb-0" style="font-size: 0.9rem;">
                            <thead>
                                <tr>
                                    <th>رقم الفاتورة</th>
                                    <th>العميل</th>
                                    <th>المجموع</th>
                                    <th>الربح</th>
                                    <th>التاريخ</th>
                                    <th class="no-print">الإجراءات</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($result_sales && $result_sales->num_rows > 0): ?>
                                    <?php while ($rows = $result_sales->fetch_assoc()): ?>
                                        <tr>
                                            <td class="font-weight-bold text-secondary">#<?php echo htmlspecialchars($rows['id']); ?></td>
                                            <td class="font-weight-bold"><?php echo htmlspecialchars($rows['cust_name']); ?></td>
                                            <td><?php echo number_format($rows['total'], 2); ?></td>
                                            <td class="text-success font-weight-bold"><?php echo number_format($rows['prifet'], 2); ?></td>
                                            <td><?php echo htmlspecialchars($rows['build_date']); ?></td>
                                            <td class="no-print">
                                                <a href="../sales/view.php?id=<?php echo $rows['id']; ?>" class="btn-flat btn-flat-primary btn-sm py-1 px-2 text-decoration-none">
                                                    <i class="fa fa-eye ml-1"></i>التفاصيل
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="6" class="text-center text-muted p-3">لا توجد حركات مبيعات في هذه الفترة المحددة.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- المصروفات خلال الفترة -->
        <div class="col-lg-5">
            <div class="card-flat mb-4">
                <div class="card-header bg-light">
                    <h5><i class="fa fa-minus-circle ml-2"></i>المصروفات العامة المسجلة</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="report-table mb-0" style="font-size: 0.9rem;">
                            <thead>
                                <tr>
                                    <th>الرقم</th>
                                    <th>البند</th>
                                    <th>التاريخ</th>
                                    <th>المبلغ</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($result_expenses && $result_expenses->num_rows > 0): ?>
                                    <?php while ($rowsa = $result_expenses->fetch_assoc()): ?>
                                        <tr>
                                            <td>#<?php echo htmlspecialchars($rowsa['m_id']); ?></td>
                                            <td class="font-weight-bold text-secondary"><?php echo htmlspecialchars($rowsa['sname']); ?></td>
                                            <td><?php echo htmlspecialchars($rowsa['m_date']); ?></td>
                                            <td class="text-danger font-weight-bold"><?php echo number_format($rowsa['m_price'], 2); ?></td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="4" class="text-center text-muted p-3">لا توجد مصروفات مسجلة في هذه الفترة المحددة.</td>
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
    <!-- رسالة ترحيبية وتوجيه -->
    <div class="card-flat p-5 text-center text-muted">
        <i class="fa fa-bar-chart mb-3 text-secondary" style="font-size: 3.5rem;"></i>
        <h4>الرجاء اختيار نطاق الفترة الزمنية (من تاريخ / إلى تاريخ) من النموذج أعلاه للبدء بالتحليل المالي وتتبع الأرباح.</h4>
    </div>
<?php endif; ?>

<?php
require_once($dir_prefix . 'includes/footer.php');
?>

