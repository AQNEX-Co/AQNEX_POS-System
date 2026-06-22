<?php
$dir_prefix = './';
$module = 'dashboard';
require_once($dir_prefix . 'includes/header.php');

$today = date("Y-m-d");
$yesterday = date('Y-m-d', strtotime('-1 day'));

// 1. جلب رصيد الصندوق
$sql_box = "SELECT mony FROM treasury WHERE box_id = '1'";
$res_box = $conn->query($sql_box);
$box_mony = ($res_box && $row = $res_box->fetch_assoc()) ? $row['mony'] : 0;

// 2. جلب مبيعات اليوم
$sql_sales = "SELECT SUM(total) as total_sales FROM sales WHERE build_date = '$today' AND delete_status = 0";
$res_sales = $conn->query($sql_sales);
$today_sales = ($res_sales && $row = $res_sales->fetch_assoc()) ? $row['total_sales'] : 0;
$today_sales = $today_sales ? $today_sales : 0;

// 3. جلب أرباح اليوم
$sql_profit = "SELECT SUM(prifet) as total_profit FROM sales WHERE build_date = '$today' AND delete_status = 0";
$res_profit = $conn->query($sql_profit);
$today_profit = ($res_profit && $row = $res_profit->fetch_assoc()) ? $row['total_profit'] : 0;
$today_profit = $today_profit ? $today_profit : 0;

// 4. جلب مصروفات اليوم
$sql_exp = "SELECT SUM(sprice) as total_expenses FROM treasury_expenses WHERE sdate = '$today'";
$res_exp = $conn->query($sql_exp);
$today_expenses = ($res_exp && $row = $res_exp->fetch_assoc()) ? $row['total_expenses'] : 0;
$today_expenses = $today_expenses ? $today_expenses : 0;

// 5. جلب مقبوضات اليوم
$sql_rec = "SELECT SUM(q_price) as total_receipts FROM receipts WHERE q_date = '$today'";
$res_rec = $conn->query($sql_rec);
$today_receipts = ($res_rec && $row = $res_rec->fetch_assoc()) ? $row['total_receipts'] : 0;
$today_receipts = $today_receipts ? $today_receipts : 0;

// 6. جلب مبيعات أمس للمقارنة
$sql_yesterday_sales = "SELECT SUM(total) as total_sales FROM sales WHERE build_date = '$yesterday' AND delete_status = 0";
$res_yesterday_sales = $conn->query($sql_yesterday_sales);
$yesterday_sales = ($res_yesterday_sales && $row = $res_yesterday_sales->fetch_assoc()) ? $row['total_sales'] : 0;
$yesterday_sales = $yesterday_sales ? $yesterday_sales : 0;

// حساب نسبة التغيير
$sales_change = 0;
if ($yesterday_sales > 0) {
    $sales_change = (($today_sales - $yesterday_sales) / $yesterday_sales) * 100;
}
?>
<style>
    
</style>
<!-- المحتوى الرئيسي للوحة التحكم -->
<!-- القسم الأول: الإحصائيات الرئيسية -->
<div class="row">
    <!-- بطاقة رصيد الصندوق -->
    <div class="col-md-3 col-sm-6">
        <div class="stat-card">
            <div class="stat-info">
                <h6>رصيد الصندوق الحالي</h6>
                <h3><?php echo number_format($box_mony, 2); ?> ر.ي</h3>
            </div>
            <div class="stat-icon">
                <?php echo get_icon('bank'); ?>
            </div>
        </div>
    </div>
    
    <!-- بطاقة مبيعات اليوم -->
    <div class="col-md-3 col-sm-6">
        <div class="stat-card success">
            <div class="stat-info">
                <h6>مبيعات اليوم</h6>
                <h3><?php echo number_format($today_sales, 2); ?> ر.ي</h3>
                <?php if ($sales_change != 0): ?>
                <small class="<?php echo $sales_change > 0 ? 'text-success' : 'text-danger'; ?>">
                    <?php echo $sales_change > 0 ? '▲' : '▼'; ?> <?php echo number_format(abs($sales_change), 1); ?>% عن أمس
                </small>
                <?php endif; ?>
            </div>
            <div class="stat-icon text-success">
                <?php echo get_icon('sales'); ?>
            </div>
        </div>
    </div>
    
    <!-- بطاقة أرباح اليوم - تظهر للمدير فقط -->
    <?php if ($is_admin): ?>
    <div class="col-md-3 col-sm-6">
        <div class="stat-card success">
            <div class="stat-info">
                <h6>أرباح اليوم</h6>
                <h3><?php echo number_format($today_profit, 2); ?> ر.ي</h3>
                <?php if ($today_sales > 0): ?>
                <small class="text-info">نسبة الربح: <?php echo number_format(($today_profit / $today_sales) * 100, 1); ?>%</small>
                <?php endif; ?>
            </div>
            <div class="stat-icon text-info">
                <?php echo get_icon('reports'); ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- بطاقة مصروفات اليوم -->
    <div class="col-md-3 col-sm-6">
        <div class="stat-card danger">
            <div class="stat-info">
                <h6>مصروفات اليوم</h6>
                <h3><?php echo number_format($today_expenses, 2); ?> ر.ي</h3>
                <small class="text-muted">المصاريف اليومية</small>
            </div>
            <div class="stat-icon text-danger">
                <?php echo get_icon('expenses'); ?>
            </div>
        </div>
    </div>
</div>

<!-- القسم الثاني: الروابط السريعة وحالة المتجر -->
<div class="row">
<!-- لوحة الروابط السريعة -->
<div class="col-lg-4">
    <div class="card-flat mb-4">
        <div class="card-header">
            <h5><?php echo get_icon('bolt', 'ml-1 text-primary'); ?> روابط سريعة</h5>
        </div>

        <div class="card-body">
            <div class="row g-2 quick-grid">

                <div class="col-6 ">
                    <a href="sales/create.php" class="quick-tile border border-1 border-primary" data-bs-toggle="tooltip" title="إنشاء فاتورة بيع">
<i class="bi bi-cart4 text-primary"></i>
                    <span>فاتورة مبيعات</span>
                    </a>
                </div>

                <div class="col-6">
                    <a href="sales/returns.php" class="quick-tile border border-1 border-primary" data-bs-toggle="tooltip" title="مردودات المبيعات">
<i class="fa-solid fa-receipt text-primary"></i>                      
 <span>مردود مبيعات</span>
                    </a>
                </div>

                <div class="col-6">
                    <a href="purchases/create.php" class="quick-tile border border-1 border-primary" data-bs-toggle="tooltip" title="إنشاء فاتورة شراء">
<i class="bi bi-cart-plus text-primary"></i>
 <span>فاتورة مشتريات</span>
                    </a>
                </div>

                <div class="col-6">
                    <a href="purchases/returns.php" class="quick-tile border border-1 border-primary" data-bs-toggle="tooltip" title="مردودات المشتريات">
<i class="bi bi-file-earmark-diff text-primary" ></i>
 <span>مردود مشتريات</span>
                    </a>
                </div>

                <div class="col-6">
                    <a href="items/index.php" class="quick-tile border border-1 border-primary" data-bs-toggle="tooltip" title="إدارة الأصناف">
                        <i class="bi bi-boxes text-primary"></i>
                        <span>ادارة الاصناف</span>
                    </a>
                </div>

                <div class="col-6">
                    <a href="inventory/index.php" class="quick-tile border border-1 border-primary" data-bs-toggle="tooltip" title="المخزون">
<i class="bi bi-box-seam text-primary"></i>
                        <span>المخزون</span>
                    </a>
                </div>

                <div class="col-6">
                    <a href="customers/index.php" class="quick-tile border border-1 border-primary" data-bs-toggle="tooltip" title="العملاء">
                        <i class="bi bi-people-fill text-primary"></i>
                        <span>ادارة العملاء</span>
                    </a>
                </div>

                <div class="col-6">
                    <a href="suppliers/index.php" class="quick-tile border border-1 border-primary" data-bs-toggle="tooltip" title="الموردين">
                        <i class="bi bi-truck text-primary"></i>
                        <span>ادارة الموردين</span>
                    </a>
                </div>

            </div>
        </div>
    </div>

        
        <div class="card-flat mb-4">
            <div class="card-header">
                <h5><?php echo get_icon('info-circle', 'ml-1 text-info'); ?> حالة المتجر اليوم</h5>
            </div>
            <div class="card-body p-0">
                <table class="table-flat m-0">
                    <tbody>
                        <tr>
                            <td class="pr-3 font-weight-bold">إجمالي القبض اليوم</td>
                            <td class="text-success font-weight-bold pl-3"><?php echo number_format($today_receipts, 2); ?> ر.ي</td>
                        </tr>
                        <tr>
                            <td class="pr-3 font-weight-bold">إجمالي المصاريف اليوم</td>
                            <td class="text-danger font-weight-bold pl-3"><?php echo number_format($today_expenses, 2); ?> ر.ي</td>
                        </tr>
                        <tr>
                            <td class="pr-3 font-weight-bold">صافي التدفق النقدي</td>
                            <td class="font-weight-bold pl-3 <?php echo ($today_sales + $today_receipts - $today_expenses) >= 0 ? 'text-success' : 'text-danger'; ?>">
                                <?php echo number_format($today_sales + $today_receipts - $today_expenses, 2); ?> ر.ي
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- مخطط الأداء اليومي -->
    <div class="col-lg-8">
        <div class="card-flat mb-4">
            <div class="card-header bg-light">
                <h6 class="font-weight-bold text-secondary mb-0">
                    <?php echo get_icon('line-chart', 'ml-2 text-primary'); ?> أداء المتجر خلال الأسبوع الماضي
                </h6>
            </div>
            <div class="card-body">
                <div style="position: relative; height: 320px; width: 100%;">
                    <canvas id="weeklyPerformanceChart"></canvas>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- القسم الثالث: المخططات التفصيلية -->
<?php
// جلب مبيعات وأرباح ومصروفات آخر 7 أيام (شاملاً اليوم)
$weekly_data = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $weekly_data[$date] = [
        'label' => date('d/m', strtotime($date)),
        'sales' => 0,
        'expenses' => 0,
        'profit' => 0
    ];
}

// استعلام المبيعات والأرباح خلال الـ 7 أيام الماضية
$sql_w_sales = "SELECT build_date, SUM(total) as daily_sales, SUM(prifet) as daily_profit FROM sales 
                WHERE build_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) AND delete_status = 0 
                GROUP BY build_date";
$res_w_sales = $conn->query($sql_w_sales);
if ($res_w_sales) {
    while ($r = $res_w_sales->fetch_assoc()) {
        $d = $r['build_date'];
        if (isset($weekly_data[$d])) {
            $weekly_data[$d]['sales'] = doubleval($r['daily_sales']);
            $weekly_data[$d]['profit'] = doubleval($r['daily_profit']);
        }
    }
}

// استعلام المصروفات خلال الـ 7 أيام الماضية
$sql_w_exp = "SELECT sdate, SUM(sprice) as daily_expenses FROM treasury_expenses 
              WHERE sdate >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) 
              GROUP BY sdate";
$res_w_exp = $conn->query($sql_w_exp);
if ($res_w_exp) {
    while ($r = $res_w_exp->fetch_assoc()) {
        $d = $r['sdate'];
        if (isset($weekly_data[$d])) {
            $weekly_data[$d]['expenses'] = doubleval($r['daily_expenses']);
        }
    }
}

$chart_labels = [];
$chart_sales = [];
$chart_expenses = [];
$chart_profit = [];
foreach ($weekly_data as $date => $data) {
    $chart_labels[] = $data['label'];
    $chart_sales[] = $data['sales'];
    $chart_expenses[] = $data['expenses'];
    $chart_profit[] = $data['profit'];
}

// جلب توزيع الأصناف
$cat_labels = [];
$cat_counts = [];
$sql_cat_dist = "SELECT c.name as cat_name, COUNT(p.id) as prod_count 
                 FROM products p 
                 JOIN categories c ON p.catid = c.catid 
                 WHERE p.delete_status = 0 
                 GROUP BY p.catid";
$res_cat_dist = $conn->query($sql_cat_dist);
if ($res_cat_dist) {
    while ($r = $res_cat_dist->fetch_assoc()) {
        $cat_labels[] = $r['cat_name'];
        $cat_counts[] = intval($r['prod_count']);
    }
}

// جلب أفضل 5 منتجات مبيعاً
$top_products_labels = [];
$top_products_sales = [];
$sql_top_products = "SELECT p.name, SUM(si.qty) as total_qty 
                     FROM sales_items si 
                     JOIN products p ON si.product_id = p.id 
                     JOIN sales s ON si.sale_id = s.id 
                     WHERE s.build_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) AND s.delete_status = 0 
                     GROUP BY si.product_id 
                     ORDER BY total_qty DESC 
                     LIMIT 5";
$res_top_products = $conn->query($sql_top_products);
if ($res_top_products) {
    while ($r = $res_top_products->fetch_assoc()) {
        $top_products_labels[] = $r['name'];
        $top_products_sales[] = intval($r['total_qty']);
    }
}
?>

<div class="row">
    <!-- مخطط توزيع المخزون على الأصناف -->
    <div class="col-lg-4 mb-4">
        <div class="card-flat">
            <div class="card-header bg-light">
                <h6 class="font-weight-bold text-secondary mb-0">
                    <?php echo get_icon('cubes', 'ml-2 text-success'); ?> توزيع المنتجات حسب التصنيفات
                </h6>
            </div>
            <div class="card-body d-flex align-items-center justify-content-center" style="min-height: 300px;">
                <div style="position: relative; height: 240px; width: 100%; max-width: 260px;">
                    <canvas id="categoryDistributionChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- مخطط أفضل المنتجات مبيعاً -->
    <div class="col-lg-8 mb-4">
        <div class="card-flat">
            <div class="card-header bg-light">
                <h6 class="font-weight-bold text-secondary mb-0">
                    <?php echo get_icon('star', 'ml-2 text-warning'); ?> أفضل 5 منتجات مبيعاً خلال الأسبوع
                </h6>
            </div>
            <div class="card-body">
                <div style="position: relative; height: 300px; width: 100%;">
                    <canvas id="topProductsChart"></canvas>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- القسم الرابع: جدول أحدث المبيعات -->
<div class="row">
    <div class="col-12">
        <div class="card-flat mb-4">
            <div class="card-header">
                <h5><?php echo get_icon('movement', 'ml-1 text-secondary'); ?> أحدث عمليات البيع اليوم</h5>
                <a href="sales/index.php" class="btn-flat btn-flat-secondary btn-sm text-decoration-none">عرض الكل</a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table-flat m-0">
                        <thead>
                            <tr>
                                <th>رقم الفاتورة</th>
                                <th>اسم العميل</th>
                                <th>الإجمالي</th>
                                <?php if ($is_admin): ?>
                                <th>الربح</th>
                                <?php endif; ?>
                                <th>ملاحظات</th>
                                <th>العمليات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $sql_recent = "SELECT * FROM sales WHERE build_date = '$today' AND delete_status = 0 ORDER BY id DESC LIMIT 5";
                            $res_recent = $conn->query($sql_recent);
                            if ($res_recent && $res_recent->num_rows > 0) {
                                while($row = $res_recent->fetch_assoc()) {
                                    ?>
                                    <tr>
                                        <td>#<?php echo $row['id']; ?></td>
                                        <td class="font-weight-bold text-secondary"><?php echo $row['cust_name'] ? htmlspecialchars($row['cust_name']) : 'عميل نقدي'; ?></td>
                                        <td class="font-weight-bold"><?php echo number_format($row['total'], 2); ?></td>
                                        <?php if ($is_admin): ?>
                                        <td class="text-success font-weight-bold"><?php echo number_format($row['prifet'], 2); ?></td>
                                        <?php endif; ?>
                                        <td><?php echo htmlspecialchars($row['remark']); ?></td>
                                        <td>
                                            <a href="sales/view.php?id=<?php echo $row['id']; ?>" class="btn-flat btn-flat-primary btn-sm py-1 px-2 text-decoration-none">
                                                <?php echo get_icon('eye', 'ml-1'); ?>عرض
                                            </a>
                                        </td>
                                    </tr>
                                    <?php
                                }
                            } else {
                                $cols = $is_admin ? 6 : 5;
                                echo '<tr><td colspan="' . $cols . '" class="text-center text-muted p-4">لا توجد عمليات بيع مسجلة اليوم حتى الآن</td></tr>';
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="<?php echo $dir_prefix; ?>files/chart.js"></script>
<script>
document.addEventListener("DOMContentLoaded", function() {
    // 1. مخطط الأداء الأسبوعي (المبيعات، الأرباح، المصروفات)
    const ctxPerformance = document.getElementById('weeklyPerformanceChart').getContext('2d');
    new Chart(ctxPerformance, {
        type: 'line',
        data: {
            labels: <?php echo json_encode($chart_labels); ?>,
            datasets: [
                {
                    label: 'المبيعات',
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
                    label: 'الأرباح',
                    data: <?php echo json_encode($chart_profit); ?>,
                    borderColor: '#3498db',
                    backgroundColor: 'rgba(52, 152, 219, 0.08)',
                    borderWidth: 2,
                    pointBackgroundColor: '#3498db',
                    pointBorderColor: '#fff',
                    pointRadius: 4,
                    tension: 0.3,
                    fill: true
                },
                {
                    label: 'المصروفات',
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

    // 2. مخطط الأصناف الدائري
    const ctxPie = document.getElementById('categoryDistributionChart').getContext('2d');
    new Chart(ctxPie, {
        type: 'doughnut',
        data: {
            labels: <?php echo json_encode($cat_labels); ?>,
            datasets: [{
                data: <?php echo json_encode($cat_counts); ?>,
                backgroundColor: [
                    '#3498db', '#9b59b6', '#1abc9c', '#f1c40f', '#e67e22', '#e74c3c', '#34495e', '#2ecc71'
                ],
                borderWidth: 1,
                borderColor: '#fff'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom',
                    rtl: true,
                    labels: {
                        boxWidth: 10,
                        padding: 10,
                        font: { family: 'Arial', size: 11 }
                    }
                }
            },
            cutout: '65%'
        }
    });

    // 3. مخطط أفضل المنتجات مبيعاً
    const ctxTopProducts = document.getElementById('topProductsChart').getContext('2d');
    new Chart(ctxTopProducts, {
        type: 'bar',
        data: {
            labels: <?php echo json_encode($top_products_labels); ?>,
            datasets: [{
                label: 'الكمية المباعة',
                data: <?php echo json_encode($top_products_sales); ?>,
                backgroundColor: 'rgba(52, 152, 219, 0.8)',
                borderColor: '#3498db',
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: false
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

<?php
require_once($dir_prefix . 'includes/footer.php'); ?>