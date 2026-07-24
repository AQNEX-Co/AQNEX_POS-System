<?php
$dir_prefix = '../';
$module = 'products';
require_once($dir_prefix . 'includes/header.php');

check_permission(['admin', 'inventory']);

// جلب الفئات لاستخدامها في الفلترة
$categories_res = $conn->query("SELECT catid, name FROM categories ORDER BY name ASC");
$categories_list = [];
if ($categories_res) {
    while ($c = $categories_res->fetch_assoc()) {
        $categories_list[] = $c;
    }
}

// الاستعلام الرئيسي للجرد
$sql = "SELECT p.*, c.name AS category_name 
        FROM products p 
        LEFT JOIN categories c ON p.catid = c.catid 
        WHERE p.delete_status = '0' 
        ORDER BY p.id DESC";
$result = $conn->query($sql);

// حساب إحصائيات الجرد الكلي للمخزن
$stats_sql = "SELECT 
    COUNT(id) AS total_items,
    SUM(quantity) AS total_qty,
    SUM(quantity * buy_price) AS total_cost_val,
    SUM(quantity * sale_price) AS total_sale_val,
    SUM(CASE WHEN quantity <= IFNULL(min_stock_alert, 5) THEN 1 ELSE 0 END) AS low_stock_count
    FROM products 
    WHERE delete_status = '0'";
$stats_res = $conn->query($stats_sql);
$stats = ($stats_res && $st_row = $stats_res->fetch_assoc()) ? $st_row : [
    'total_items' => 0, 'total_qty' => 0, 'total_cost_val' => 0, 'total_sale_val' => 0, 'low_stock_count' => 0
];

$total_items = intval($stats['total_items']);
$total_qty = intval($stats['total_qty']);
$total_cost = floatval($stats['total_cost_val']);
$total_sale = floatval($stats['total_sale_val']);
$total_profit = $total_sale - $total_cost;
$low_stock = intval($stats['low_stock_count']);
?>
<title>إدارة وجرد منتجات المستودع — AQNEX POS</title>

<style>
@media print {
    #sidebar, .navbar-top, .no-print, .btn, .filter-bar { display: none !important; }
    #content { margin: 0 !important; padding: 0 !important; }
    body { background: #fff !important; font-size: 11pt; }
    .print-header { display: block !important; margin-bottom: 20px; border-bottom: 2px solid #000; padding-bottom: 10px; }
    .table-inventory { border: 1px solid #000 !important; }
    .table-inventory th, .table-inventory td { border: 1px solid #000 !important; color: #000 !important; }
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

/* كروت الإحصائيات المتقدمة */
.stats-card-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 12px;
    margin-bottom: 20px;
}
.kpi-box {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    padding: 14px 16px;
    position: relative;
    overflow: hidden;
    transition: all 0.2s ease;
}
.kpi-box:hover { transform: translateY(-2px); box-shadow: 0 6px 15px rgba(0,0,0,0.08) !important; }
.kpi-box::after {
    content: ''; position: absolute; top: 0; right: 0; width: 4px; height: 100%;
}
.kpi-box.kpi-items::after { background: #3b82f6; }
.kpi-box.kpi-qty::after { background: #10b981; }
.kpi-box.kpi-cost::after { background: #6366f1; }
.kpi-box.kpi-sale::after { background: #059669; }
.kpi-box.kpi-profit::after { background: #8b5cf6; }
.kpi-box.kpi-warning::after { background: #ef4444; }

.kpi-title {
    font-size: 0.75rem; color: #64748b; font-weight: 700; margin-bottom: 6px;
    display: flex; align-items: center; justify-content: space-between;
}
.kpi-value {
    font-size: 1.15rem; font-weight: 800; color: #0f172a;
    font-family: 'Segoe UI', system-ui, sans-serif;
}
.kpi-sub { font-size: 0.68rem; color: #94a3b8; margin-top: 4px; }

/* شريط الفلاتر */
.filter-card {
    background: #f8fafc; border: 1px solid #e2e8f0; padding: 12px 16px; margin-bottom: 18px;
}
.stock-badge { padding: 3px 8px; font-size: 0.7rem; font-weight: 700; }
.stock-good { background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; }
.stock-low { background: #fef3c7; color: #b45309; border: 1px solid #fde68a; }
.stock-zero { background: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5; }

.table-inventory th {
    background: #f8fafc; color: #334155; font-size: 0.78rem;
    font-weight: 700; border-bottom: 2px solid #cbd5e1;
}
.table-inventory td { font-size: 0.8rem; vertical-align: middle; }
</style>

<!-- ترويسة الطباعة الرسمية -->
<div class="print-header text-center">
    <h3 class="font-weight-bold mb-1">التقرير السنوي / كشف جرد المستودع والاصناف</h3>
    <p class="text-muted mb-0">تاريخ التقرير: <?php echo date("Y-m-d H:i"); ?> | طُبع بواسطة: <?php echo htmlspecialchars($_SESSION['SESS_FIRST_NAME'] ?? 'المدير'); ?></p>
</div>

<!-- رأس الصفحة بالتصميم الرسمي الجديد -->
<div class="page-title-bar no-print">
    <div class="ptb-left">
        <div class="icon-wrap"><i class="bi bi-boxes"></i></div>
        <div>
            <h4>إدارة وجرد منتجات المستودع</h4>
            <small>عرض وإدارة جرد البضائع، تنبيهات المخزون المنخفض، والتقرير المالي التراكمي</small>
        </div>
    </div>
    <div class="ptb-actions">
        <a href="create.php" class="btn btn-sm btn-success text-decoration-none" style="font-size:0.8rem;" title="إضافة منتج جديد">
            <i class="bi bi-plus-circle ml-1"></i> إضافة منتج
        </a>
        <a href="import.php" class="btn btn-sm btn-light text-decoration-none" style="font-size:0.8rem; border:1px solid #cbd5e1;" title="استيراد من ملف إكسل">
            <i class="bi bi-file-earmark-arrow-up ml-1"></i> استيراد إكسل
        </a>
        <a href="../includes/export.php?type=products" class="btn btn-sm btn-light text-decoration-none" style="font-size:0.8rem; border:1px solid #cbd5e1;" title="تصدير الجرد لإكسل">
            <i class="bi bi-file-earmark-excel ml-1"></i> تصدير إكسل
        </a>
        <button onclick="window.print()" class="btn btn-sm btn-light" style="font-size:0.8rem; border:1px solid #cbd5e1;" title="طباعة كشف الجرد">
            <i class="bi bi-printer ml-1"></i> طباعة
        </button>
        <a href="../home.php" class="btn btn-sm btn-light text-decoration-none" style="font-size:0.8rem; border:1px solid #cbd5e1;" title="العودة للرئيسية">
            <i class="bi bi-arrow-left ml-1"></i> عودة
        </a>
    </div>
</div>


<!-- KPI Cards -->
<div class="stats-card-grid no-print">
    <div class="kpi-box kpi-items">
        <div class="kpi-title"><span>إجمالي الاصناف</span><i class="bi bi-box-seam text-primary"></i></div>
        <div class="kpi-value"><?php echo number_format($total_items); ?> <small style="font-size:0.7rem;">صنف</small></div>
        <div class="kpi-sub">عدد الأكواد المسجلة</div>
    </div>
    <div class="kpi-box kpi-qty">
        <div class="kpi-title"><span>الكميات بالمخزن</span><i class="bi bi-layers text-success"></i></div>
        <div class="kpi-value"><?php echo number_format($total_qty); ?> <small style="font-size:0.7rem;">قطعة</small></div>
        <div class="kpi-sub">المجموع المتوفر الفعلي</div>
    </div>
    <div class="kpi-box kpi-cost">
        <div class="kpi-title"><span>قيمة الشراء (التكلفة)</span><i class="bi bi-wallet2 text-indigo"></i></div>
        <div class="kpi-value"><?php echo number_format($total_cost, 2); ?> <small style="font-size:0.7rem;">ر.ي</small></div>
        <div class="kpi-sub">رأس المال المستثمر بالمخزون</div>
    </div>
    <div class="kpi-box kpi-sale">
        <div class="kpi-title"><span>القيمة البيعية الكلية</span><div class="bi bi-currency-dollar text-emerald"></div></div>
        <div class="kpi-value"><?php echo number_format($total_sale, 2); ?> <small style="font-size:0.7rem;">ر.ي</small></div>
        <div class="kpi-sub">العائد المتوقع عند البيع الكامل</div>
    </div>
    <div class="kpi-box kpi-profit">
        <div class="kpi-title"><span>الربح الإجمالي المتوقع</span><i class="bi bi-graph-up-arrow text-purple"></i></div>
        <div class="kpi-value text-success"><?php echo number_format($total_profit, 2); ?> <small style="font-size:0.7rem;">ر.ي</small></div>
        <div class="kpi-sub">الفارق بين الشراء والبيع</div>
    </div>
    <div class="kpi-box kpi-warning">
        <div class="kpi-title"><span>تنبيهات المخزون المنخفض</span><i class="bi bi-exclamation-triangle text-danger"></i></div>
        <div class="kpi-value <?php echo $low_stock > 0 ? 'text-danger font-weight-bold' : 'text-muted'; ?>">
            <?php echo number_format($low_stock); ?> <small style="font-size:0.7rem;">صنف</small>
        </div>
        <div class="kpi-sub">أقل من حد الأمان المطلوب</div>
    </div>
</div>

<!-- Filter Bar -->
<div class="filter-card no-print">
    <div class="row align-items-center">
        <div class="col-md-5 mb-2 mb-md-0">
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text bg-light border-right-0"><i class="bi bi-search text-muted"></i></span>
                </div>
                <input type="text" id="searchInput" class="form-control border-left-0" placeholder="ابحث برقم المنتج، الباركود، أو اسم المنتج..." oninput="filterProductsTable()">
            </div>
        </div>
        <div class="col-md-4 mb-2 mb-md-0">
            <select id="categoryFilter" class="form-control" onchange="filterProductsTable()">
                <option value="">-- جميع الأقسام / التصنيفات --</option>
                <?php foreach ($categories_list as $cat): ?>
                    <option value="<?php echo htmlspecialchars($cat['name']); ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <select id="stockFilter" class="form-control" onchange="filterProductsTable()">
                <option value="all">-- حالة التوفر بالمخزن --</option>
                <option value="good">متوفر بكثرة (طبيعي)</option>
                <option value="low">منخفض (أقل من حد الأمان)</option>
                <option value="zero">نفذت الكمية (0 قطعة)</option>
            </select>
        </div>
    </div>
</div>

<!-- Main Inventory Table -->
<div class="card border-0 shadow-sm rounded-0">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-bordered table-hover table-inventory text-center mb-0" id="productsTable">
                <thead>
                    <tr>
                        <th style="width: 8%;">رقم المنتج</th>
                        <th style="width: 14%;">الباركود</th>
                        <th style="width: 25%;">اسم المنتج / البيان</th>
                        <th style="width: 12%;">التصنيف</th>
                        <th style="width: 10%;">حالة المخزون</th>
                        <th style="width: 8%;">المتوفر</th>
                        <th style="width: 10%;">سعر الشراء</th>
                        <th style="width: 10%;">سعر البيع</th>
                        <th style="width: 12%;">إجمالي قيمة الشراء</th>
                        <th class="no-print" style="width: 14%;">الإجراءات والتعديل</th>
                    </tr>
                </thead>
                <tbody id="productsTableBody">
                    <?php
                    if ($result && $result->num_rows > 0) {
                        while ($row = $result->fetch_assoc()) {
                            $qty = intval($row['quantity']);
                            $min_limit = intval($row['min_stock_alert'] ?? 5);
                            $buy_p = floatval($row['buy_price']);
                            $sale_p = floatval($row['sale_price']);
                            $item_total = floatval($row['total']);
                            if ($item_total <= 0) $item_total = $qty * $buy_p;

                            $stock_class = 'good';
                            $stock_badge = '<span class="stock-badge stock-good"><i class="bi bi-check-circle ml-1"></i> متوفر</span>';
                            if ($qty <= 0) {
                                $stock_class = 'zero';
                                $stock_badge = '<span class="stock-badge stock-zero"><i class="bi bi-x-circle ml-1"></i> نفذت</span>';
                            } else if ($qty <= $min_limit) {
                                $stock_class = 'low';
                                $stock_badge = '<span class="stock-badge stock-low"><i class="bi bi-exclamation-circle ml-1"></i> منخفض</span>';
                            }
                            ?>
                            <tr class="product-row" data-stock-type="<?php echo $stock_class; ?>" data-cat="<?php echo htmlspecialchars($row['category_name'] ?? ''); ?>">
                                <td class="font-weight-bold">#<?php echo $row['id']; ?></td>
                                <td><code><?php echo htmlspecialchars($row['barcode'] ?? '-'); ?></code></td>
                                <td class="font-weight-bold text-dark text-right pr-3"><?php echo htmlspecialchars($row['name']); ?></td>
                                <td><span class="badge badge-light border px-2 py-1"><?php echo htmlspecialchars($row['category_name'] ?? 'عام'); ?></span></td>
                                <td><?php echo $stock_badge; ?></td>
                                <td class="font-weight-bold style-qty"><?php echo number_format($qty); ?></td>
                                <td><?php echo number_format($buy_p, 2); ?></td>
                                <td class="font-weight-bold text-primary"><?php echo number_format($sale_p, 2); ?></td>
                                <td class="font-weight-bold text-dark"><?php echo number_format($item_total, 2); ?></td>
                                <td class="no-print">
                                    <a href="edit.php?id=<?php echo $row['id']; ?>" class="btn btn-xs btn-primary px-2" title="تعديل المنتج">
                                        <i class="bi bi-pencil-square ml-1"></i> تعديل
                                    </a>
                                    <a href="movement.php?id=<?php echo urlencode($row['name']); ?>" class="btn btn-xs btn-outline-success px-2 ml-1" title="تتبع حركة الصنف">
                                        <i class="bi bi-arrow-left-right"></i> حركة
                                    </a>
                                    <a href="delete.php?id=<?php echo $row['id']; ?>" onclick="return confirm('تأكيد الحذف: هل أنت متأكد من حذف المنتج (<?php echo htmlspecialchars($row['name']); ?>) نهائياً؟')" class="btn btn-xs btn-outline-danger px-2 ml-1" title="حذف">
                                        <i class="bi bi-trash"></i>
                                    </a>
                                </td>
                            </tr>
                            <?php
                        }
                    } else {
                        echo '<tr><td colspan="10" class="text-center text-muted p-4">لا توجد منتجات مسجلة في المستودع حالياً.</td></tr>';
                    }
                    ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script type="text/javascript">
function filterProductsTable() {
    const q = (document.getElementById("searchInput").value || '').toLowerCase();
    const cat = (document.getElementById("categoryFilter").value || '').toLowerCase();
    const stockType = document.getElementById("stockFilter").value;

    document.querySelectorAll(".product-row").forEach(function(row) {
        const text = row.innerText.toLowerCase();
        const rowCat = (row.getAttribute("data-cat") || '').toLowerCase();
        const rowStock = row.getAttribute("data-stock-type");

        let matchQuery = text.includes(q);
        let matchCat = (!cat || rowCat.includes(cat));
        let matchStock = (stockType === 'all' || !stockType || rowStock === stockType);

        if (matchQuery && matchCat && matchStock) {
            row.style.display = "";
        } else {
            row.style.display = "none";
        }
    });
}
</script>

<?php require_once($dir_prefix . 'includes/footer.php'); ?>
