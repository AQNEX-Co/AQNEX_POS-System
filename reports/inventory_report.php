<?php
$dir_prefix = '../';
$module = 'reports';
require_once($dir_prefix . 'includes/header.php');

check_permission(['admin', 'inventory', 'reports']);

// ==========================================
// الفلاتر واستعلامات البيانات
// ==========================================
$search = isset($_GET['search']) ? $conn->real_escape_string(trim($_GET['search'])) : '';
$cat_filter = isset($_GET['cat_id']) ? intval($_GET['cat_id']) : 0;
$stock_status = isset($_GET['stock_status']) ? $_GET['stock_status'] : 'all';

// جلب التصنيفات للفلترة
$categories = [];
$res_cat = $conn->query("SELECT catid, name FROM categories WHERE d_s = 0 ORDER BY name ASC");
if ($res_cat) {
    while ($c = $res_cat->fetch_assoc()) $categories[] = $c;
}

// بناء استعلام الشروط
$where_clauses = ["p.delete_status = 0"];
if (!empty($search)) {
    $where_clauses[] = "(p.name LIKE '%$search%' OR p.barcode LIKE '%$search%' OR p.id = '$search')";
}
if ($cat_filter > 0) {
    $where_clauses[] = "p.catid = $cat_filter";
}
if ($stock_status === 'good') {
    $where_clauses[] = "p.quantity > COALESCE(p.min_stock_alert, 5)";
} elseif ($stock_status === 'low') {
    $where_clauses[] = "p.quantity > 0 AND p.quantity <= COALESCE(p.min_stock_alert, 5)";
} elseif ($stock_status === 'zero') {
    $where_clauses[] = "p.quantity <= 0";
}

$where_sql = implode(' AND ', $where_clauses);

// 1. الإحصائيات الشاملة للمخزون
$stats_sql = "SELECT 
    COUNT(p.id) as total_items,
    COALESCE(SUM(p.quantity), 0) as total_qty,
    COALESCE(SUM(p.quantity * p.buy_price), 0) as total_cost_val,
    COALESCE(SUM(p.quantity * p.sale_price), 0) as total_sale_val,
    COALESCE(SUM(CASE WHEN p.quantity <= 0 THEN 1 ELSE 0 END), 0) as zero_stock_cnt,
    COALESCE(SUM(CASE WHEN p.quantity > 0 AND p.quantity <= COALESCE(p.min_stock_alert, 5) THEN 1 ELSE 0 END), 0) as low_stock_cnt
FROM products p 
WHERE p.delete_status = 0";
$res_stats = $conn->query($stats_sql);
$stats = $res_stats ? $res_stats->fetch_assoc() : [
    'total_items' => 0, 'total_qty' => 0, 'total_cost_val' => 0,
    'total_sale_val' => 0, 'zero_stock_cnt' => 0, 'low_stock_cnt' => 0
];

$total_profit_val = $stats['total_sale_val'] - $stats['total_cost_val'];
$profit_margin_pct = $stats['total_cost_val'] > 0 ? ($total_profit_val / $stats['total_cost_val']) * 100 : 0;

// 2. توزيع المخزون حسب التصنيف
$cat_breakdown_sql = "SELECT 
    c.name as cat_name,
    COUNT(p.id) as items_cnt,
    COALESCE(SUM(p.quantity), 0) as cat_qty,
    COALESCE(SUM(p.quantity * p.buy_price), 0) as cat_cost_val,
    COALESCE(SUM(p.quantity * p.sale_price), 0) as cat_sale_val
FROM categories c
LEFT JOIN products p ON c.catid = p.catid AND p.delete_status = 0
WHERE c.d_s = 0
GROUP BY c.catid, c.name
ORDER BY cat_cost_val DESC";
$res_cat_bd = $conn->query($cat_breakdown_sql);
$category_data = [];
if ($res_cat_bd) {
    while ($row = $res_cat_bd->fetch_assoc()) $category_data[] = $row;
}

// 3. جدول المنتجات المفصل
$products_sql = "SELECT 
    p.*, 
    c.name as category_name
FROM products p
LEFT JOIN categories c ON p.catid = c.catid
WHERE $where_sql
ORDER BY (p.quantity * p.buy_price) DESC";
$res_products = $conn->query($products_sql);
$products_list = [];
if ($res_products) {
    while ($r = $res_products->fetch_assoc()) $products_list[] = $r;
}
?>
<title>تقرير الجرد والمخزون الشامل — AQNEX POS</title>

<style>
@media print {
    #sidebar, .navbar-top, .no-print, .filter-panel, .ptb-actions { display: none !important; }
    #content { margin: 0 !important; padding: 0 !important; }
    body { background: #fff !important; font-size: 11pt; }
    .print-header { display: block !important; margin-bottom: 20px; border-bottom: 2px solid #000; padding-bottom: 10px; }
    .report-table th, .report-table td { border: 1px solid #000 !important; color: #000 !important; }
}
.print-header { display: none; }

/* ===== Page Title Bar ===== */
.page-title-bar {
    display: flex; align-items: center; justify-content: space-between;
    padding: 10px 0 14px; border-bottom: 2px solid #e2e8f0;
    margin-bottom: 18px; flex-wrap: wrap; gap: 10px;
}
.page-title-bar .ptb-left { display: flex; align-items: center; gap: 10px; }
.page-title-bar .icon-wrap {
    width: 36px; height: 36px;
    background: linear-gradient(135deg, #0c4a6e 0%, #0369a1 100%);
    display: flex; align-items: center; justify-content: center;
    color: #fff; font-size: 1rem;
}
.page-title-bar h4 { margin: 0; font-size: 0.95rem; font-weight: 700; color: var(--text-color); }
.page-title-bar small { font-size: 0.72rem; color: #64748b; display: block; }
.page-title-bar .ptb-actions { display: flex; gap: 8px; flex-wrap: wrap; }

/* ===== KPI Cards Grid ===== */
.kpi-row {
    display: grid; grid-template-columns: repeat(6, 1fr); gap: 10px; margin-bottom: 18px;
}
@media (max-width: 1200px) { .kpi-row { grid-template-columns: repeat(3, 1fr); } }
@media (max-width: 768px)  { .kpi-row { grid-template-columns: repeat(2, 1fr); } }

.kpi-card {
    background: #fff; border: 1px solid #e2e8f0; padding: 12px 14px;
    position: relative; overflow: hidden;
}
.kpi-card::before { content:''; position: absolute; top:0; right:0; width:4px; height:100%; }
.kpi-blue::before   { background: linear-gradient(180deg, #3b82f6, #1d4ed8); }
.kpi-green::before  { background: linear-gradient(180deg, #10b981, #059669); }
.kpi-indigo::before { background: linear-gradient(180deg, #6366f1, #4338ca); }
.kpi-teal::before   { background: linear-gradient(180deg, #14b8a6, #0f766e); }
.kpi-amber::before  { background: linear-gradient(180deg, #f59e0b, #d97706); }
.kpi-red::before    { background: linear-gradient(180deg, #ef4444, #b91c1c); }

.kpi-card .lbl { font-size: 0.68rem; font-weight: 700; color: #64748b; margin-bottom: 4px; }
.kpi-card .val { font-size: 1.1rem; font-weight: 800; color: #0f172a; line-height: 1; }
.kpi-card .sub { font-size: 0.62rem; color: #94a3b8; margin-top: 4px; }

/* ===== Filter Panel ===== */
.filter-panel {
    background: #f8fafc; border: 1px solid #e2e8f0; padding: 12px 16px; margin-bottom: 18px;
}
.filter-panel .form-label { font-size: 0.72rem; font-weight: 700; color: #475569; margin-bottom: 4px; }

/* ===== Report Tables ===== */
.report-table {
    width: 100%; border-collapse: collapse; background: #fff;
}
.report-table thead th {
    background: #f1f5f9; color: #334155; font-size: 0.76rem;
    font-weight: 700; padding: 9px 8px; border: 1px solid #cbd5e1; text-align: center;
}
.report-table tbody td {
    padding: 7px 8px; border: 1px solid #e2e8f0; font-size: 0.78rem; vertical-align: middle;
}
.report-table tbody tr:hover { background: #f8fafc; }

.badge-good { background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; font-size: 0.65rem; padding: 2px 6px; }
.badge-low  { background: #fef3c7; color: #b45309; border: 1px solid #fde68a; font-size: 0.65rem; padding: 2px 6px; }
.badge-zero { background: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5; font-size: 0.65rem; padding: 2px 6px; }
</style>

<div class="page-inner">

<!-- ترويسة الطباعة الرسمية -->
<div class="print-header text-center">
    <h3 class="font-weight-bold mb-1">التقرير السنوي الشامل لجرد وتقييم المخزون</h3>
    <p class="text-muted mb-0">تاريخ التقرير: <?php echo date("Y-m-d H:i"); ?> | طُبع بواسطة: <?php echo htmlspecialchars($_SESSION['SESS_FIRST_NAME'] ?? 'المدير'); ?></p>
</div>

<!-- رأس الصفحة -->
<div class="page-title-bar no-print">
    <div class="ptb-left">
        <div class="icon-wrap"><i class="bi bi-pie-chart-fill"></i></div>
        <div>
            <h4>تقرير الجرد والمخزون الشامل</h4>
            <small>كشف بقيمة المخزون، توزيع التصنيفات، والأصناف المنخفضة والنافذة</small>
        </div>
    </div>
    <div class="ptb-actions">
        <button onclick="window.print()" class="btn btn-sm btn-light" style="font-size:0.8rem; border:1px solid #cbd5e1;" title="طباعة تقرير المخزون">
            <i class="bi bi-printer ml-1"></i> طباعة التقرير
        </button>
        <a href="../home.php" class="btn btn-sm btn-light text-decoration-none" style="font-size:0.8rem; border:1px solid #cbd5e1;" title="العودة للرئيسية">
            <i class="bi bi-arrow-left ml-1"></i> عودة
        </a>
    </div>
</div>

<!-- KPI Cards -->
<div class="kpi-row no-print">
    <div class="kpi-card kpi-blue">
        <div class="lbl">إجمالي الأصناف</div>
        <div class="val"><?php echo number_format($stats['total_items']); ?> <small style="font-size:0.6rem;">صنف</small></div>
        <div class="sub">عدد منتجات المستودع</div>
    </div>
    <div class="kpi-card kpi-green">
        <div class="lbl">الكمية الإجمالية</div>
        <div class="val"><?php echo number_format($stats['total_qty']); ?> <small style="font-size:0.6rem;">قطعة</small></div>
        <div class="sub">إجمالي الوحدات بالمخزن</div>
    </div>
    <div class="kpi-card kpi-indigo">
        <div class="lbl">تكلفة الشراء (رأس المال)</div>
        <div class="val"><?php echo number_format($stats['total_cost_val'], 2); ?> <small style="font-size:0.6rem;">ر.ي</small></div>
        <div class="sub">قيمة التكلفة الفعلية</div>
    </div>
    <div class="kpi-card kpi-teal">
        <div class="lbl">القيمة البيعية المتوقعة</div>
        <div class="val"><?php echo number_format($stats['total_sale_val'], 2); ?> <small style="font-size:0.6rem;">ر.ي</small></div>
        <div class="sub">العائد عند بيع الكامل</div>
    </div>
    <div class="kpi-card kpi-amber">
        <div class="lbl">أرباح المخزون المتوقعة</div>
        <div class="val text-success"><?php echo number_format($total_profit_val, 2); ?> <small style="font-size:0.6rem;">ر.ي</small></div>
        <div class="sub">هامش: <?php echo number_format($profit_margin_pct, 1); ?>%</div>
    </div>
    <div class="kpi-card kpi-red">
        <div class="lbl">تنبيهات النقص والنفاذ</div>
        <div class="val <?php echo ($stats['zero_stock_cnt'] + $stats['low_stock_cnt']) > 0 ? 'text-danger' : ''; ?>">
            <?php echo number_format($stats['zero_stock_cnt'] + $stats['low_stock_cnt']); ?> <small style="font-size:0.6rem;">صنف</small>
        </div>
        <div class="sub">نافذ: <?php echo $stats['zero_stock_cnt']; ?> | منخفض: <?php echo $stats['low_stock_cnt']; ?></div>
    </div>
</div>

<!-- فلتر البحث -->
<div class="filter-panel no-print">
    <form method="GET" class="row align-items-end">
        <div class="col-md-4 mb-2 mb-md-0">
            <label class="form-label">البحث برقم الصنف / الباركود / الاسم</label>
            <input type="text" name="search" class="form-control form-control-sm" placeholder="ابحث..." value="<?php echo htmlspecialchars($search); ?>">
        </div>
        <div class="col-md-3 mb-2 mb-md-0">
            <label class="form-label">التصنيف المحاسبي</label>
            <select name="cat_id" class="form-control form-control-sm">
                <option value="0">-- جميع التصنيفات --</option>
                <?php foreach ($categories as $cat): ?>
                <option value="<?php echo $cat['catid']; ?>" <?php echo $cat['catid'] == $cat_filter ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($cat['name']); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3 mb-2 mb-md-0">
            <label class="form-label">حالة المخزون</label>
            <select name="stock_status" class="form-control form-control-sm">
                <option value="all" <?php echo $stock_status === 'all' ? 'selected' : ''; ?>>-- جميع الحالات --</option>
                <option value="good" <?php echo $stock_status === 'good' ? 'selected' : ''; ?>>متوفر بكثرة (طبيعي)</option>
                <option value="low" <?php echo $stock_status === 'low' ? 'selected' : ''; ?>>مخزون منخفض (أقل من الحد)</option>
                <option value="zero" <?php echo $stock_status === 'zero' ? 'selected' : ''; ?>>نفذت الكمية (0)</option>
            </select>
        </div>
        <div class="col-md-2">
            <button type="submit" class="btn btn-primary btn-sm btn-block" style="font-weight:700;">
                <i class="bi bi-search ml-1"></i> تصفية
            </button>
        </div>
    </form>
</div>

<!-- توزيع المخزون حسب التصنيف -->
<div class="card-flat mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="m-0 font-weight-bold"><i class="bi bi-diagram-2 ml-2 text-primary"></i>توزيع وقيمة المخزون حسب مجموعات الأصناف</h5>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="report-table sap-grid-table">
                <thead>
                    <tr>
                        <th style="width:5%;">#</th>
                        <th style="width:25%;">مجموعة الأصناف</th>
                        <th style="width:12%;">عدد الأصناف</th>
                        <th style="width:13%;">إجمالي الكمية</th>
                        <th style="width:15%;">قيمة التكلفة (ر.ي)</th>
                        <th style="width:15%;">القيمة البيعية (ر.ي)</th>
                        <th style="width:15%;">الربح المتوقع (ر.ي)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($category_data)): ?>
                        <?php $idx = 1; foreach ($category_data as $cd): 
                            $c_profit = $cd['cat_sale_val'] - $cd['cat_cost_val'];
                        ?>
                        <tr>
                            <td class="text-center font-weight-bold text-muted"><?php echo $idx++; ?></td>
                            <td class="font-weight-bold text-dark"><?php echo htmlspecialchars($cd['cat_name']); ?></td>
                            <td class="text-center"><?php echo number_format($cd['items_cnt']); ?></td>
                            <td class="text-center font-weight-bold"><?php echo number_format($cd['cat_qty']); ?></td>
                            <td class="text-center font-weight-bold"><?php echo number_format($cd['cat_cost_val'], 2); ?></td>
                            <td class="text-center font-weight-bold text-primary"><?php echo number_format($cd['cat_sale_val'], 2); ?></td>
                            <td class="text-center font-weight-bold text-success"><?php echo number_format($c_profit, 2); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="7" class="text-center py-3 text-muted">لا توجد بيانات تصنيفات</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- جدول المنتجات التفصيلي -->
<div class="card-flat">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="m-0 font-weight-bold"><i class="bi bi-box-seam ml-2 text-primary"></i>كشف جرد الأصناف التفصيلي (مرتبة حسب القيمة)</h5>
        <span class="badge badge-light border px-2 py-1">عدد المنتجات: <?php echo count($products_list); ?></span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="report-table sap-grid-table">
                <thead>
                    <tr>
                        <th style="width:6%;">رقم الصنف</th>
                        <th style="width:11%;">الباركود</th>
                        <th style="width:22%;">اسم المنتج / الصنف</th>
                        <th style="width:11%;">التصنيف</th>
                        <th style="width:8%;">حالة المخزون</th>
                        <th style="width:7%;">الكمية</th>
                        <th style="width:9%;">سعر الشراء</th>
                        <th style="width:9%;">سعر البيع</th>
                        <th style="width:10%;">إجمالي التكلفة</th>
                        <th style="width:7%;" class="no-print">إجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($products_list)): ?>
                        <?php foreach ($products_list as $p): 
                            $q = intval($p['quantity']);
                            $min_limit = intval($p['min_stock_alert'] ?? 5);
                            $buy_p = floatval($p['buy_price']);
                            $sale_p = floatval($p['sale_price']);
                            $total_c = $q * $buy_p;

                            $st_badge = '<span class="badge-good"><i class="bi bi-check-circle"></i> متوفر</span>';
                            if ($q <= 0) {
                                $st_badge = '<span class="badge-zero"><i class="bi bi-x-circle"></i> نفذت</span>';
                            } elseif ($q <= $min_limit) {
                                $st_badge = '<span class="badge-low"><i class="bi bi-exclamation-circle"></i> منخفض</span>';
                            }
                        ?>
                        <tr>
                            <td class="text-center font-weight-bold text-muted">#<?php echo $p['id']; ?></td>
                            <td class="text-center font-monospace"><code><?php echo htmlspecialchars($p['barcode'] ?: '-'); ?></code></td>
                            <td class="font-weight-bold text-dark"><?php echo htmlspecialchars($p['name']); ?></td>
                            <td class="text-center"><?php echo htmlspecialchars($p['category_name'] ?: 'عام'); ?></td>
                            <td class="text-center"><?php echo $st_badge; ?></td>
                            <td class="text-center font-weight-bold"><?php echo number_format($q); ?></td>
                            <td class="text-center"><?php echo number_format($buy_p, 2); ?></td>
                            <td class="text-center font-weight-bold text-primary"><?php echo number_format($sale_p, 2); ?></td>
                            <td class="text-center font-weight-bold text-dark"><?php echo number_format($total_c, 2); ?></td>
                            <td class="text-center no-print">
                                <button type="button" class="btn btn-xs btn-primary font-weight-bold" title="عرض تفاصيل كارت الصنف والمخزون في نافذة منفصلة" onclick="openDocumentViewerModal('كارت الصنف والمخزون', '<?php echo $p['id']; ?>', '<?php echo htmlspecialchars(addslashes($p['name'])); ?>');">
                                    <i class="bi bi-eye-fill"></i>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="10" class="text-center py-4 text-muted">لا توجد نتائج مطابقة لشروط البحث</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- تذييل الاعتماد والتوقيع المحاسبي الرسمي للطباعة -->
<div class="sap-signature-block">
    <div>
        <div>إعداد أخصائي المخزون</div>
        <div style="margin-top: 30px;">التوقيع: ..................</div>
    </div>
    <div>
        <div>مراجعة وتدقيق الجرد</div>
        <div style="margin-top: 30px;">التوقيع: ..................</div>
    </div>
    <div>
        <div>اعتماد مدير المستودعات والمالية</div>
        <div style="margin-top: 30px;">التوقيع: ..................</div>
    </div>
</div>

</div><!-- end .page-inner -->
<?php require_once($dir_prefix . 'includes/footer.php'); ?>
