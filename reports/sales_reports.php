<?php
$dir_prefix = '../';
$module = 'reports';
require_once($dir_prefix . 'includes/header.php');

// التأكد من صلاحيات المسؤول/المدير
check_permission(['admin']);

// جلب الفلاتر المدخلة أو تعيين القيم الافتراضية
$start_date = isset($_GET['start_date']) ? $conn->real_escape_string($_GET['start_date']) : date('Y-m-d', strtotime('-30 days'));
$end_date   = isset($_GET['end_date']) ? $conn->real_escape_string($_GET['end_date']) : date('Y-m-d');

$branch_id      = isset($_GET['branch_id']) && $_GET['branch_id'] !== '' ? intval($_GET['branch_id']) : '';
$user_id        = isset($_GET['user_id']) && $_GET['user_id'] !== '' ? intval($_GET['user_id']) : '';
$payment_method = isset($_GET['payment_method']) && $_GET['payment_method'] !== '' ? $conn->real_escape_string($_GET['payment_method']) : '';

// بناء جملة الـ WHERE
$where_clauses = ["s.delete_status = 0"];
$where_clauses[] = "s.build_date BETWEEN '$start_date' AND '$end_date'";

if ($branch_id !== '') {
    $where_clauses[] = "u.branch_id = $branch_id";
}
if ($user_id !== '') {
    $where_clauses[] = "t.user_id = $user_id";
}
if ($payment_method !== '') {
    if ($payment_method === 'cash') {
        $where_clauses[] = "s.invoice_type = 'cash'";
    } elseif ($payment_method === 'credit') {
        $where_clauses[] = "s.invoice_type = 'credit'";
    }
}

$where_sql = implode(' AND ', $where_clauses);

// 1. استعلام كروت الأداء المالي (KPIs)
$sql_kpi = "
    SELECT 
        COALESCE(SUM(s.total), 0) as total_sales,
        COALESCE(SUM(s.prifet), 0) as total_profit,
        COUNT(s.id) as total_invoices,
        COALESCE(AVG(s.total), 0) as avg_invoice
    FROM sales s
    JOIN treasury t ON s.box_id = t.box_id
    LEFT JOIN users u ON t.user_id = u.userid
    WHERE $where_sql
";
$res_kpi = $conn->query($sql_kpi);
$kpis = $res_kpi ? $res_kpi->fetch_assoc() : ['total_sales' => 0, 'total_profit' => 0, 'total_invoices' => 0, 'avg_invoice' => 0];

// 2. استعلام منحنى المبيعات اليومي (Daily Trend Chart)
$sql_trend = "
    SELECT s.build_date, SUM(s.total) as daily_total, SUM(s.prifet) as daily_profit
    FROM sales s
    JOIN treasury t ON s.box_id = t.box_id
    LEFT JOIN users u ON t.user_id = u.userid
    WHERE $where_sql
    GROUP BY s.build_date
    ORDER BY s.build_date ASC
";
$res_trend = $conn->query($sql_trend);
$trend_labels = [];
$trend_sales = [];
$trend_profit = [];
if ($res_trend) {
    while ($row = $res_trend->fetch_assoc()) {
        $trend_labels[] = $row['build_date'];
        $trend_sales[]  = doubleval($row['daily_total']);
        $trend_profit[] = doubleval($row['daily_profit']);
    }
}

// 3. استعلام المنتجات الأكثر مبيعاً (Top Selling Products)
$sql_products = "
    SELECT si.name, SUM(si.quantity) as total_qty, SUM(si.total) as total_revenue
    FROM sales_items si
    JOIN sales s ON si.sales_id = s.id
    JOIN treasury t ON s.box_id = t.box_id
    LEFT JOIN users u ON t.user_id = u.userid
    WHERE $where_sql
    GROUP BY si.id, si.name
    ORDER BY total_qty DESC
    LIMIT 8
";
$res_products = $conn->query($sql_products);
$prod_labels = [];
$prod_qtys = [];
$prod_revenues = [];
if ($res_products) {
    while ($row = $res_products->fetch_assoc()) {
        $prod_labels[]   = $row['name'];
        $prod_qtys[]     = intval($row['total_qty']);
        $prod_revenues[] = doubleval($row['total_revenue']);
    }
}

// 4. استعلام الفواتير المفصلة والربح لكل فاتورة
$sql_invoices = "
    SELECT s.id, s.cust_name, s.build_date, s.total, s.prifet, s.invoice_type, u.username, b.name as branch_name
    FROM sales s
    JOIN treasury t ON s.box_id = t.box_id
    LEFT JOIN users u ON t.user_id = u.userid
    LEFT JOIN branches b ON u.branch_id = b.id
    WHERE $where_sql
    ORDER BY s.id DESC
";
$res_invoices = $conn->query($sql_invoices);

// جلب الفروع للفلاتر
$branches_list = [];
$res_branches = $conn->query("SELECT * FROM branches ORDER BY id ASC");
if ($res_branches) {
    while ($row = $res_branches->fetch_assoc()) {
        $branches_list[] = $row;
    }
}

// جلب المستخدمين للفلاتر
$users_list = [];
$res_users = $conn->query("SELECT userid, username, full_name FROM users ORDER BY userid ASC");
if ($res_users) {
    while ($row = $res_users->fetch_assoc()) {
        $users_list[] = $row;
    }
}
?>

<title>تقارير وتحليلات المبيعات الذكية - AQNEX</title>

<!-- لوحة التحليلات والمبيعات -->
<div class="page-inner">
    <div class="page-title-bar mb-4 no-print">
        <div>
            <h5 class="page-title font-weight-bold mb-0">
                <i class="bi bi-graph-up-arrow ml-2 text-primary"></i> تقارير وتحليلات المبيعات الذكية
            </h5>
            <p class="text-muted small mb-0 mt-1">تتبع مؤشرات الأداء المالي لمبيعات وأرباح الفروع والكاشيرات بمخططات تفاعلية.</p>
        </div>
        <div>
            <button onclick="window.print()" class="btn-flat btn-flat-success btn-sm ml-2">
                <i class="bi bi-printer ml-1"></i> طباعة التقرير
            </button>
        </div>
    </div>

    <!-- فلاتر البحث والفرز المتقدمة -->
    <div class="card-flat p-4 mb-4 no-print text-right" dir="rtl">
        <form method="GET" action="sales_reports.php">
            <div class="row">
                <!-- تاريخ البدء -->
                <div class="col-md-2 mb-3">
                    <label class="form-label font-weight-bold text-secondary">من تاريخ</label>
                    <input type="date" name="start_date" class="form-control rounded-0" value="<?php echo $start_date; ?>">
                </div>

                <!-- تاريخ الانتهاء -->
                <div class="col-md-2 mb-3">
                    <label class="form-label font-weight-bold text-secondary">إلى تاريخ</label>
                    <input type="date" name="end_date" class="form-control rounded-0" value="<?php echo $end_date; ?>">
                </div>

                <!-- الفرع -->
                <div class="col-md-3 mb-3">
                    <label class="form-label font-weight-bold text-secondary">الفرع</label>
                    <select name="branch_id" class="form-control rounded-0">
                        <option value="">-- جميع الفروع --</option>
                        <?php foreach ($branches_list as $b): ?>
                            <option value="<?php echo $b['id']; ?>" <?php echo $branch_id == $b['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($b['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- الكاشير / المستخدم -->
                <div class="col-md-3 mb-3">
                    <label class="form-label font-weight-bold text-secondary">الكاشير (المستخدم)</label>
                    <select name="user_id" class="form-control rounded-0">
                        <option value="">-- جميع الكاشيرات --</option>
                        <?php foreach ($users_list as $u): ?>
                            <option value="<?php echo $u['userid']; ?>" <?php echo $user_id == $u['userid'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($u['full_name'] ?: $u['username']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- طريقة الدفع -->
                <div class="col-md-2 mb-3">
                    <label class="form-label font-weight-bold text-secondary">طريقة الدفع</label>
                    <select name="payment_method" class="form-control rounded-0">
                        <option value="">-- الجميع --</option>
                        <option value="cash" <?php echo $payment_method === 'cash' ? 'selected' : ''; ?>>نقدي</option>
                        <option value="credit" <?php echo $payment_method === 'credit' ? 'selected' : ''; ?>>آجل</option>
                    </select>
                </div>
            </div>
            
            <div class="text-left mt-2">
                <a href="sales_reports.php" class="btn btn-sm btn-light rounded-0 font-weight-bold ml-2">إعادة تعيين</a>
                <button type="submit" class="btn btn-sm btn-primary rounded-0 px-4 font-weight-bold">
                    <i class="fa fa-filter ml-1"></i> تطبيق التصفية
                </button>
            </div>
        </form>
    </div>

    <!-- كروت الأداء المالي (KPI Summary) -->
    <div class="row text-right mb-4" dir="rtl">
        <!-- إجمالي المبيعات -->
        <div class="col-md-3 mb-3">
            <div class="card-flat p-3 d-flex align-items-center justify-content-between" style="border-right: 4px solid #1e3a8a;">
                <div>
                    <small class="text-secondary d-block font-weight-bold mb-1">إجمالي المبيعات</small>
                    <h4 class="font-weight-bold mb-0 text-primary"><?php echo number_format($kpis['total_sales'], 2); ?> <span style="font-size:0.75rem;">ر.ي</span></h4>
                </div>
                <div class="bg-light p-2 rounded">
                    <i class="bi bi-cash-stack text-primary" style="font-size: 1.5rem;"></i>
                </div>
            </div>
        </div>

        <!-- إجمالي الأرباح -->
        <div class="col-md-3 mb-3">
            <div class="card-flat p-3 d-flex align-items-center justify-content-between" style="border-right: 4px solid #10b981;">
                <div>
                    <small class="text-secondary d-block font-weight-bold mb-1">صافي الأرباح المتوقعة</small>
                    <h4 class="font-weight-bold mb-0 text-success"><?php echo number_format($kpis['total_profit'], 2); ?> <span style="font-size:0.75rem;">ر.ي</span></h4>
                </div>
                <div class="bg-light p-2 rounded">
                    <i class="bi bi-piggy-bank text-success" style="font-size: 1.5rem;"></i>
                </div>
            </div>
        </div>

        <!-- عدد الفواتير -->
        <div class="col-md-3 mb-3">
            <div class="card-flat p-3 d-flex align-items-center justify-content-between" style="border-right: 4px solid #f59e0b;">
                <div>
                    <small class="text-secondary d-block font-weight-bold mb-1">عدد فواتير المبيعات</small>
                    <h4 class="font-weight-bold mb-0 text-warning"><?php echo number_format($kpis['total_invoices']); ?> <span style="font-size:0.75rem;">فاتورة</span></h4>
                </div>
                <div class="bg-light p-2 rounded">
                    <i class="bi bi-file-earmark-text text-warning" style="font-size: 1.5rem;"></i>
                </div>
            </div>
        </div>

        <!-- متوسط الفاتورة -->
        <div class="col-md-3 mb-3">
            <div class="card-flat p-3 d-flex align-items-center justify-content-between" style="border-right: 4px solid #6366f1;">
                <div>
                    <small class="text-secondary d-block font-weight-bold mb-1">متوسط قيمة الفاتورة</small>
                    <h4 class="font-weight-bold mb-0 text-indigo" style="color: #6366f1;"><?php echo number_format($kpis['avg_invoice'], 2); ?> <span style="font-size:0.75rem;">ر.ي</span></h4>
                </div>
                <div class="bg-light p-2 rounded">
                    <i class="bi bi-calculator text-indigo" style="color: #6366f1; font-size: 1.5rem;"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- الرسوم والمخططات البيانية -->
    <div class="row mb-4">
        <!-- منحنى المبيعات اليومي والربح -->
        <div class="col-lg-8 mb-4">
            <div class="card-flat p-3 text-right">
                <h6 class="font-weight-bold mb-3"><i class="bi bi-activity ml-1 text-primary"></i> منحنى حركة المبيعات والأرباح اليومية</h6>
                <div style="height: 320px; position: relative;">
                    <canvas id="trendChart"></canvas>
                </div>
            </div>
        </div>

        <!-- المنتجات الأكثر مبيعاً بالكمية -->
        <div class="col-lg-4 mb-4">
            <div class="card-flat p-3 text-right">
                <h6 class="font-weight-bold mb-3"><i class="bi bi-box-seam ml-1 text-success"></i> الأصناف الأكثر طلباً بالكمية</h6>
                <div style="height: 320px; position: relative;">
                    <canvas id="productsChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- جدول تفصيلي بفواتير المبيعات المدرجة -->
    <div class="card-flat ret-card text-right mb-4">
        <div class="card-header bg-light">
            <h6 class="mb-0 text-dark font-weight-bold"><i class="fa fa-list ml-2 text-primary"></i> فواتير المبيعات المشمولة في التقرير</h6>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table-flat mb-0">
                    <thead>
                        <tr>
                            <th style="width: 8%;">رقم الفاتورة</th>
                            <th style="width: 15%;">تاريخ التحرير</th>
                            <th>اسم العميل</th>
                            <th style="width: 12%;">نوع الفاتورة</th>
                            <th style="width: 12%;">الكاشير</th>
                            <th style="width: 15%;">الفرع</th>
                            <th style="width: 15%;">إجمالي الفاتورة (ر.ي)</th>
                            <th style="width: 12%;">الربح المحقق (ر.ي)</th>
                            <th style="width: 8%;" class="no-print text-center">عرض</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$res_invoices || $res_invoices->num_rows == 0): ?>
                            <tr><td colspan="9" class="text-center text-muted p-4">لا توجد فواتير مبيعات مطابقة للفلاتر والتواريخ المحددة.</td></tr>
                        <?php else: ?>
                            <?php while ($row = $res_invoices->fetch_assoc()): ?>
                            <tr>
                                <td class="font-weight-bold text-secondary">#<?php echo $row['id']; ?></td>
                                <td class="small"><?php echo htmlspecialchars($row['build_date']); ?></td>
                                <td class="font-weight-bold"><?php echo htmlspecialchars($row['cust_name'] ?: 'عميل نقدي'); ?></td>
                                <td>
                                    <?php if ($row['invoice_type'] === 'cash'): ?>
                                        <span class="badge badge-success py-1 px-2 rounded-0">نقدي</span>
                                    <?php else: ?>
                                        <span class="badge badge-warning py-1 px-2 rounded-0">آجل</span>
                                    <?php endif; ?>
                                </td>
                                <td class="small text-secondary font-weight-bold"><?php echo htmlspecialchars($row['username']); ?></td>
                                <td class="small"><?php echo htmlspecialchars($row['branch_name'] ?: 'الفرع الرئيسي'); ?></td>
                                <td class="font-weight-bold"><?php echo number_format($row['total'], 2); ?></td>
                                <td class="font-weight-bold text-success"><?php echo number_format($row['prifet'], 2); ?></td>
                                <td class="no-print text-center">
                                    <a href="../sales/view.php?id=<?php echo $row['id']; ?>" target="_blank" class="btn btn-sm btn-flat btn-flat-primary py-1 px-2 text-decoration-none">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- تحميل مكتبة Chart.js المحلية -->
<script src="<?php echo $dir_prefix; ?>files/chart.js"></script>

<script>
document.addEventListener("DOMContentLoaded", function() {
    // 1. إعداد مخطط منحنى حركة المبيعات والأرباح
    const trendCtx = document.getElementById('trendChart').getContext('2d');
    const trendLabels = <?php echo json_encode($trend_labels); ?>;
    const trendSalesData = <?php echo json_encode($trend_sales); ?>;
    const trendProfitData = <?php echo json_encode($trend_profit); ?>;

    new Chart(trendCtx, {
        type: 'line',
        data: {
            labels: trendLabels,
            datasets: [
                {
                    label: 'إجمالي المبيعات (ر.ي)',
                    data: trendSalesData,
                    borderColor: '#1e3a8a',
                    backgroundColor: 'rgba(30, 58, 138, 0.05)',
                    borderWidth: 2.5,
                    fill: true,
                    tension: 0.25
                },
                {
                    label: 'صافي الأرباح (ر.ي)',
                    data: trendProfitData,
                    borderColor: '#10b981',
                    backgroundColor: 'rgba(16, 185, 129, 0.05)',
                    borderWidth: 2.5,
                    fill: true,
                    tension: 0.25
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'top',
                    labels: {
                        font: { family: 'Tajawal', size: 12 }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        font: { family: 'Tajawal' }
                    }
                },
                x: {
                    ticks: {
                        font: { family: 'Tajawal' }
                    }
                }
            }
        }
    });

    // 2. إعداد مخطط الأصناف الأكثر طلباً بالكمية
    const prodCtx = document.getElementById('productsChart').getContext('2d');
    const prodLabels = <?php echo json_encode($prod_labels); ?>;
    const prodQtys = <?php echo json_encode($prod_qtys); ?>;

    new Chart(prodCtx, {
        type: 'bar',
        data: {
            labels: prodLabels.map(l => l.length > 18 ? l.substring(0, 15) + '...' : l),
            datasets: [
                {
                    label: 'الكمية المباعة',
                    data: prodQtys,
                    backgroundColor: '#10b981',
                    borderWidth: 0,
                    barThickness: 15
                }
            ]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: false
                }
            },
            scales: {
                x: {
                    beginAtZero: true,
                    ticks: {
                        font: { family: 'Tajawal' }
                    }
                },
                y: {
                    ticks: {
                        font: { family: 'Tajawal', size: 10 }
                    }
                }
            }
        }
    });
});
</script>

<?php require_once($dir_prefix . 'includes/footer.php'); ?>
