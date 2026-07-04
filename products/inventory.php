<?php
$dir_prefix = '../';
$module = 'products';
require_once($dir_prefix . 'includes/header.php');

check_permission(['admin', 'inventory']);

$settings_res = $conn->query("SELECT * FROM settings WHERE id = 1");
$settings = $settings_res ? $settings_res->fetch_assoc() : null;
$low_stock_threshold = $settings ? intval($settings['low_stock_threshold']) : 5;

$error = '';
$success = '';

// معالجة التسوية اليدوية للمخزون
if (isset($_POST['btn_adjust'])) {
    $p_id = intval($_POST['adj_product_id']);
    $adj_type = $conn->real_escape_string($_POST['adj_type']); // 'increase' or 'decrease'
    $adj_qty = intval($_POST['adj_qty']);
    $adj_reason = $conn->real_escape_string(trim($_POST['adj_reason']));
    $user_name = $_SESSION['SESS_FIRST_NAME'];

    if ($p_id <= 0 || $adj_qty <= 0) {
        $error = 'الرجاء تحديد منتج صالح وكمية أكبر من الصفر.';
    } elseif (empty($adj_reason)) {
        $error = 'الرجاء ذكر سبب التسوية (تالف، جرد، هدية، وغيرها).';
    } else {
        $qty_change = ($adj_type === 'increase') ? $adj_qty : -$adj_qty;

        // جلب الكمية الحالية للتحقق من الصلاحية
        $res_p = $conn->query("SELECT quantity, name FROM products WHERE id = $p_id");
        $p_row = $res_p ? $res_p->fetch_assoc() : null;

        if (!$p_row) {
            $error = 'المنتج غير موجود.';
        } elseif ($adj_type === 'decrease' && $p_row['quantity'] < $adj_qty) {
            $error = 'الكمية المطلوب خصمها (' . $adj_qty . ') أكبر من المتوفر في المخزن (' . $p_row['quantity'] . ')!';
        } else {
            // تحديث الكمية
            $conn->query("UPDATE products SET quantity = quantity + ($qty_change), total = (quantity + ($qty_change)) * buy_price WHERE id = $p_id");

            // تسجيل الحركة في سجل المخازن
            $p_name_esc = $conn->real_escape_string($p_row['name']);
            $conn->query("INSERT INTO inventory_log (product_id, product_name, type, qty_change, new_qty, reason, user)
                          SELECT id, name, 'manual', $qty_change, quantity, '$adj_reason', '$user_name'
                          FROM products WHERE id = $p_id");

            $action = ($adj_type === 'increase') ? 'إضافة' : 'خصم';
            $success = 'تمت تسوية المخزون بنجاح: ' . $action . ' ' . $adj_qty . ' وحدة من المنتج "' . $p_row['name'] . '" - السبب: ' . $adj_reason;
        }
    }
}

// إحصائيات المخزون
$stats = [];

$res = $conn->query("SELECT COUNT(*) AS total FROM products WHERE delete_status = 0");
$stats['total'] = $res ? intval($res->fetch_assoc()['total']) : 0;

$res = $conn->query("SELECT COUNT(*) AS cnt FROM products WHERE delete_status = 0 AND quantity > 0 AND quantity <= COALESCE(NULLIF(min_stock_alert, 0), $low_stock_threshold)");
$stats['low'] = $res ? intval($res->fetch_assoc()['cnt']) : 0;

$res = $conn->query("SELECT COUNT(*) AS cnt FROM products WHERE delete_status = 0 AND quantity = 0");
$stats['out'] = $res ? intval($res->fetch_assoc()['cnt']) : 0;

$res = $conn->query("SELECT SUM(total) AS val FROM products WHERE delete_status = 0");
$stats['value'] = $res ? doubleval($res->fetch_assoc()['val']) : 0;

// جلب قائمة المنتجات مع الكميات
$search_q = '';
if (!empty($_GET['q'])) {
    $search_q = $conn->real_escape_string(trim($_GET['q']));
}

$filter_status = isset($_GET['status']) ? $_GET['status'] : 'all';

$sql_products = "SELECT p.*, c.name AS cat_name 
                 FROM products p 
                 LEFT JOIN categories c ON p.catid = c.catid
                 WHERE p.delete_status = 0";
if (!empty($search_q)) {
    $sql_products .= " AND (p.name LIKE '%$search_q%' OR p.barcode LIKE '%$search_q%')";
}
if ($filter_status === 'low') {
    $sql_products .= " AND p.quantity > 0 AND p.quantity <= COALESCE(NULLIF(p.min_stock_alert, 0), $low_stock_threshold)";
} elseif ($filter_status === 'out') {
    $sql_products .= " AND p.quantity = 0";
} elseif ($filter_status === 'ok') {
    $sql_products .= " AND p.quantity > COALESCE(NULLIF(p.min_stock_alert, 0), $low_stock_threshold)";
}
$sql_products .= " ORDER BY p.quantity ASC, p.id DESC";
$result_products = $conn->query($sql_products);
$all_products_list = [];
if ($result_products) {
    while($row = $result_products->fetch_assoc()) {
        $all_products_list[] = $row;
    }
}
?>
<title>لوحة إدارة المخزون والتسويات - تكنولوجيا فون</title>

<style>
/* نافذة التسوية اليدوية */
#adjustModal .modal-dialog { max-width: 550px; }
#adjustModal .modal-content { border-radius: 0 !important; }
#adjustModal .modal-header { background: var(--secondary); color: #fff; }
.stat-card {
    border: 1px solid #e2e8f0;
    padding: 20px;
    text-align: center;
    background: #fff;
}
.stat-card .stat-val {
    font-size: 2rem;
    font-weight: 900;
    display: block;
    line-height: 1.1;
}
.stat-card .stat-lbl {
    font-size: 0.8rem;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.05em;
}
.badge-stock-ok    { background-color: var(--accent-success); color: #fff; }
.badge-stock-low   { background-color: #e67e22; color: #fff; }
.badge-stock-out   { background-color: var(--accent-danger); color: #fff; }
.filter-btn.active { background: var(--secondary) !important; color: #fff !important; }
</style>

<div class="row mb-4 no-print">
    <div class="col-md-6">
        <h3 class="text-secondary font-weight-bold">
            <?php echo get_icon('products', 'ml-2 text-primary'); ?> لوحة إدارة المخزون والتسويات الجردية
        </h3>
        <p class="text-muted small mb-0">مراقبة الكميات، تنبيهات النقص، التسويات اليدوية، وسجل حركة المخازن</p>
    </div>
    <div class="col-md-6 text-left">
        <button onclick="window.print()" class="btn-flat btn-flat-success btn-sm ml-2">
            <?php echo get_icon('print', 'ml-1'); ?> طباعة كشف الجرد
        </button>
        <a href="../products/index.php" class="btn-flat btn-flat-primary btn-sm ml-2 text-decoration-none">
            <?php echo get_icon('products', 'ml-1'); ?> إدارة المنتجات
        </a>
        <a href="../home.php" class="btn-flat btn-flat-secondary btn-sm text-decoration-none">
            <?php echo get_icon('logout', 'ml-1'); ?> عودة
        </a>
    </div>
</div>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger rounded-0 mb-4"><?php echo $error; ?></div>
<?php endif; ?>
<?php if (!empty($success)): ?>
    <div class="alert alert-success rounded-0 mb-4"><?php echo get_icon('check', 'ml-1'); ?> <?php echo $success; ?></div>
<?php endif; ?>

<!-- بطاقات الإحصاء -->
<div class="row mb-4 no-print">
    <div class="col-md-3 col-sm-6 mb-3">
        <div class="stat-card">
            <span class="stat-val text-secondary"><?php echo number_format($stats['total']); ?></span>
            <span class="stat-lbl mt-1">إجمالي الأصناف</span>
        </div>
    </div>
    <div class="col-md-3 col-sm-6 mb-3">
        <div class="stat-card" style="border-top: 3px solid var(--accent-success);">
            <span class="stat-val text-success"><?php echo number_format($stats['value'], 0); ?></span>
            <span class="stat-lbl mt-1">قيمة المخزون (ر.ي)</span>
        </div>
    </div>
    <div class="col-md-3 col-sm-6 mb-3">
        <div class="stat-card" style="border-top: 3px solid #e67e22;">
            <span class="stat-val" style="color: #e67e22;"><?php echo number_format($stats['low']); ?></span>
            <span class="stat-lbl mt-1">منخفضة الكمية (أقل من <?php echo $low_stock_threshold; ?>)</span>
        </div>
    </div>
    <div class="col-md-3 col-sm-6 mb-3">
        <div class="stat-card" style="border-top: 3px solid var(--accent-danger);">
            <span class="stat-val text-danger"><?php echo number_format($stats['out']); ?></span>
            <span class="stat-lbl mt-1">أصناف نافذة (صفر)</span>
        </div>
    </div>
</div>

<!-- جدول المخزون -->
<div class="card-flat mb-4">
    <div class="card-header no-print">
        <h5><?php echo get_icon('products', 'ml-2'); ?> مستوى الكميات ومراقبة المخزون</h5>
        <div class="d-flex align-items-center">
            <form method="GET" class="d-flex align-items-center ml-3">
                <input type="hidden" name="status" value="<?php echo htmlspecialchars($filter_status); ?>">
                <input type="text" name="q" class="form-control form-control-sm rounded-0" style="width: 220px;" placeholder="بحث بالاسم أو الباركود..." value="<?php echo htmlspecialchars($search_q); ?>">
                <button type="submit" class="btn-flat btn-flat-primary btn-sm py-1 px-3 mr-1"><?php echo get_icon('search'); ?></button>
            </form>
            <div class="btn-group" role="group">
                <a href="?status=all<?php echo $search_q ? '&q='.urlencode($search_q) : ''; ?>" class="btn-flat btn-flat-secondary btn-sm py-1 px-2 filter-btn <?php echo ($filter_status === 'all') ? 'active' : ''; ?>">الكل</a>
                <a href="?status=ok<?php echo $search_q ? '&q='.urlencode($search_q) : ''; ?>" class="btn-flat btn-flat-secondary btn-sm py-1 px-2 filter-btn <?php echo ($filter_status === 'ok') ? 'active' : ''; ?>">متوفر</a>
                <a href="?status=low<?php echo $search_q ? '&q='.urlencode($search_q) : ''; ?>" class="btn-flat btn-flat-secondary btn-sm py-1 px-2 filter-btn <?php echo ($filter_status === 'low') ? 'active' : ''; ?>">منخفض</a>
                <a href="?status=out<?php echo $search_q ? '&q='.urlencode($search_q) : ''; ?>" class="btn-flat btn-flat-secondary btn-sm py-1 px-2 filter-btn <?php echo ($filter_status === 'out') ? 'active' : ''; ?>">نافذ</a>
            </div>
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table-flat">
                <thead>
                    <tr>
                        <th style="width: 8%;">#</th>
                        <th>اسم المنتج</th>
                        <th style="width: 10%;">الباركود</th>
                        <th style="width: 10%;">التصنيف</th>
                        <th style="width: 10%;">الكمية المتوفرة</th>
                        <th style="width: 10%;">حالة المخزون</th>
                        <th style="width: 10%;">سعر الشراء</th>
                        <th style="width: 10%;">سعر البيع</th>
                        <th style="width: 12%;">قيمة المخزون</th>
                        <th class="no-print" style="width: 10%;">تسوية يدوية</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($all_products_list)): ?>
                        <tr><td colspan="10" class="text-center text-muted p-4">لا توجد منتجات تطابق المعايير المحددة</td></tr>
                    <?php else: ?>
                        <?php foreach ($all_products_list as $prod):
                            $qty = intval($prod['quantity']);
                            $prod_alert_limit = ($prod['min_stock_alert'] > 0 ? intval($prod['min_stock_alert']) : $low_stock_threshold);
                            if ($qty <= 0) {
                                $stock_badge = '<span class="badge badge-stock-out px-2 py-1 font-weight-normal">نافذ</span>';
                            } elseif ($qty <= $prod_alert_limit) {
                                $stock_badge = '<span class="badge badge-stock-low px-2 py-1 font-weight-normal">منخفض</span>';
                            } else {
                                $stock_badge = '<span class="badge badge-stock-ok px-2 py-1 font-weight-normal">متوفر</span>';
                            }
                        ?>
                        <tr class="<?php echo ($qty <= 0) ? 'table-danger' : (($qty <= $prod_alert_limit) ? 'table-warning' : ''); ?>" style="<?php echo ($qty <= 0) ? 'background: #fff5f5;' : (($qty <= $prod_alert_limit) ? 'background: #fffbeb;' : ''); ?>">
                            <td class="font-weight-bold text-muted">#<?php echo $prod['id']; ?></td>
                            <td class="font-weight-bold text-right pr-3"><?php echo htmlspecialchars($prod['name']); ?></td>
                            <td class="small font-weight-bold text-muted"><?php echo htmlspecialchars($prod['barcode'] ?? '-'); ?></td>
                            <td class="small"><?php echo htmlspecialchars($prod['cat_name'] ?? '-'); ?></td>
                            <td class="font-weight-bold text-center" style="font-size: 1.1rem;"><?php echo $qty; ?></td>
                            <td class="text-center"><?php echo $stock_badge; ?></td>
                            <td><?php echo number_format($prod['buy_price'], 2); ?></td>
                            <td><?php echo number_format($prod['sale_price'], 2); ?></td>
                            <td class="font-weight-bold"><?php echo number_format($prod['total'], 2); ?></td>
                            <td class="no-print text-center">
                                <button type="button" class="btn-flat btn-flat-primary btn-sm py-1 px-2 adjust-btn"
                                    data-prod-id="<?php echo $prod['id']; ?>"
                                    data-prod-name="<?php echo htmlspecialchars($prod['name'], ENT_QUOTES); ?>"
                                    data-prod-qty="<?php echo $qty; ?>">
                                    <?php echo get_icon('edit', 'ml-1'); ?> تسوية
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- سجل حركة المخازن -->
<div class="card-flat mb-4 no-print">
    <div class="card-header">
        <h5><?php echo get_icon('reports', 'ml-2'); ?> سجل حركة المخازن الأخيرة (آخر 100 حركة)</h5>
    </div>
    <div class="card-body p-0" style="max-height: 400px; overflow-y: auto;">
        <div class="table-responsive">
            <table class="table-flat">
                <thead>
                    <tr>
                        <th style="width: 8%;">#</th>
                        <th>المنتج</th>
                        <th style="width: 12%;">نوع الحركة</th>
                        <th style="width: 10%;">تغيير الكمية</th>
                        <th style="width: 10%;">الكمية بعد</th>
                        <th>السبب / الوصف</th>
                        <th style="width: 12%;">المسؤول</th>
                        <th style="width: 14%;">التاريخ والوقت</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $res_log = $conn->query("SELECT * FROM inventory_log ORDER BY id DESC LIMIT 100");
                if ($res_log && $res_log->num_rows > 0) {
                    while ($log = $res_log->fetch_assoc()) {
                        $qty_ch = intval($log['qty_change']);
                        $type_label = '';
                        $qty_class = '';
                        switch($log['type']) {
                            case 'purchase': $type_label = '<span class="badge badge-primary px-2 py-1 font-weight-normal">شراء</span>'; $qty_class = 'text-success'; break;
                            case 'sale':     $type_label = '<span class="badge badge-info px-2 py-1 font-weight-normal">بيع</span>'; $qty_class = 'text-danger'; break;
                            case 'manual':   $type_label = '<span class="badge badge-warning px-2 py-1 font-weight-normal">تسوية</span>'; $qty_class = $qty_ch > 0 ? 'text-success' : 'text-danger'; break;
                            default:         $type_label = '<span class="badge badge-secondary px-2 py-1 font-weight-normal">أخرى</span>'; $qty_class = '';
                        }
                        $qty_prefix = ($qty_ch >= 0) ? '+' : '';
                        echo "<tr>";
                        echo "<td class='font-weight-bold text-muted'>#" . $log['id'] . "</td>";
                        echo "<td class='font-weight-bold text-right pr-3'>" . htmlspecialchars($log['product_name']) . "</td>";
                        echo "<td class='text-center'>" . $type_label . "</td>";
                        echo "<td class='font-weight-bold text-center $qty_class'>{$qty_prefix}{$qty_ch}</td>";
                        echo "<td class='text-center font-weight-bold'>" . intval($log['new_qty']) . "</td>";
                        echo "<td class='small text-muted'>" . htmlspecialchars($log['reason'] ?? '-') . "</td>";
                        echo "<td class='small'>" . htmlspecialchars($log['user']) . "</td>";
                        echo "<td class='small'>" . htmlspecialchars($log['created_at']) . "</td>";
                        echo "</tr>";
                    }
                } else {
                    echo "<tr><td colspan='8' class='text-center text-muted p-4'>لا توجد حركات مسجلة في سجل المخازن بعد</td></tr>";
                }
                ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- مودال التسوية اليدوية -->
<div class="modal fade" id="adjustModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title font-weight-bold">
                    <?php echo get_icon('edit', 'ml-2'); ?> تسوية يدوية للمخزون
                </h5>
                <button type="button" class="close" data-dismiss="modal">
                    <span>&times;</span>
                </button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="adj_product_id" id="adj_product_id" value="">
                    <div class="alert alert-secondary rounded-0 p-3 mb-4">
                        <strong>المنتج:</strong> <span id="adj_product_name" class="font-weight-bold text-primary"></span><br>
                        <strong>الكمية الحالية في المخزن:</strong> <span id="adj_current_qty" class="font-weight-bold text-secondary"></span>
                    </div>
                    <div class="form-group mb-3">
                        <label class="font-weight-bold text-secondary mb-2">نوع التسوية *</label>
                        <div class="d-flex">
                            <div class="custom-control custom-radio ml-4">
                                <input type="radio" class="custom-control-input" id="adj_increase" name="adj_type" value="increase" checked>
                                <label class="custom-control-label text-success font-weight-bold" for="adj_increase">زيادة الكمية (+)</label>
                            </div>
                            <div class="custom-control custom-radio">
                                <input type="radio" class="custom-control-input" id="adj_decrease" name="adj_type" value="decrease">
                                <label class="custom-control-label text-danger font-weight-bold" for="adj_decrease">خصم الكمية (-)</label>
                            </div>
                        </div>
                    </div>
                    <div class="form-group mb-3">
                        <label class="font-weight-bold text-secondary mb-2">الكمية المراد تسويتها *</label>
                        <input type="number" name="adj_qty" id="adj_qty" class="form-control rounded-0 text-center font-weight-bold" min="1" value="1" required>
                    </div>
                    <div class="form-group mb-3">
                        <label class="font-weight-bold text-secondary mb-2">سبب التسوية *</label>
                        <select name="adj_reason" class="form-control rounded-0" required>
                            <option value="">-- اختر السبب --</option>
                            <option value="جرد سنوي">جرد سنوي / إعادة عد</option>
                            <option value="بضاعة تالفة">بضاعة تالفة أو مكسورة</option>
                            <option value="هدايا ومنح">هدايا ومنح للعملاء</option>
                            <option value="خسارة أو سرقة">خسارة غير مبررة / سرقة</option>
                            <option value="مرتجع من عميل">مرتجع وارد من عميل</option>
                            <option value="تصحيح خطأ إدخال">تصحيح خطأ في الإدخال السابق</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-flat btn-flat-secondary px-4" data-dismiss="modal">إلغاء</button>
                    <button type="submit" name="btn_adjust" class="btn-flat btn-flat-primary px-5">
                        <?php echo get_icon('check', 'ml-1'); ?> تثبيت التسوية
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openAdjustModal(productId, productName, currentQty) {
    document.getElementById('adj_product_id').value = productId;
    document.getElementById('adj_product_name').textContent = productName;
    document.getElementById('adj_current_qty').textContent = currentQty + ' وحدة';
    document.getElementById('adj_qty').value = 1;
    document.querySelector('input[name="adj_type"][value="increase"]').checked = true;
    
    if (typeof $ !== 'undefined' && $.fn.modal) {
        $('#adjustModal').modal('show');
    } else {
        // Fallback in case jQuery/Bootstrap JS is slow to load
        var modal = document.getElementById('adjustModal');
        if (modal) {
            modal.classList.add('show');
            modal.style.display = 'block';
            modal.setAttribute('aria-hidden', 'false');
            document.body.classList.add('modal-open');
            var backdrop = document.createElement('div');
            backdrop.className = 'modal-backdrop fade show';
            document.body.appendChild(backdrop);
        }
    }
}

// Event delegation to capture clicks on dynamic or statically loaded buttons safely
function setupAdjustmentEvents() {
    document.body.addEventListener('click', function(e) {
        var btn = e.target.closest('.adjust-btn');
        if (btn) {
            e.preventDefault();
            var id = btn.getAttribute('data-prod-id');
            var name = btn.getAttribute('data-prod-name');
            var qty = btn.getAttribute('data-prod-qty');
            openAdjustModal(id, name, qty);
        }
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', setupAdjustmentEvents);
} else {
    setupAdjustmentEvents();
}
</script>

<!-- أنماط CSS للمساعد الذكي العائم -->
<style>
@keyframes pulse-red {
    0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(99, 102, 241, 0.7); }
    70% { transform: scale(1); box-shadow: 0 0 0 10px rgba(99, 102, 241, 0); }
    100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(99, 102, 241, 0); }
}
#aiInventoryBtn:hover {
    transform: scale(1.1);
    box-shadow: 0 6px 20px rgba(79, 70, 229, 0.6) !important;
}
</style>

<!-- زر المساعد الذكي العائم للمخازن -->
<button type="button" id="aiInventoryBtn" class="no-print" style="position: fixed; bottom: 30px; left: 30px; z-index: 1040; width: 60px; height: 60px; border-radius: 50%; background: linear-gradient(135deg, #6366f1, #4f46e5); border: none; box-shadow: 0 4px 15px rgba(79, 70, 229, 0.4); display: flex; align-items: center; justify-content: center; cursor: pointer; transition: transform 0.2s, box-shadow 0.2s;">
    <i class="bi bi-robot text-white" style="font-size: 1.8rem; pointer-events: none;"></i>
    <span style="position: absolute; top: 0; right: 0; width: 14px; height: 14px; background: #10b981; border-radius: 50%; border: 2px solid #fff; animation: pulse-red 2s infinite;"></span>
</button>

<!-- لوحة المساعد الذكي الجانبية -->
<div id="aiInventoryPanel" class="no-print" style="position: fixed; top: 0; left: -420px; width: 400px; height: 100%; background: #fff; box-shadow: 4px 0 25px rgba(0,0,0,0.15); z-index: 1050; transition: left 0.3s ease; display: flex; flex-direction: column; text-align: right;">
    <!-- هيدر اللوحة -->
    <div style="padding: 20px; background: linear-gradient(135deg, #1e1e38, #2a2a50); color: #fff; display: flex; justify-content: space-between; align-items: center;">
        <h5 class="m-0 font-weight-bold" style="font-size: 1.1rem; display: flex; align-items: center;">
            <i class="bi bi-robot ml-2 text-primary" style="font-size: 1.3rem;"></i> مستشار الجرد والمخازن الذكي
        </h5>
        <button type="button" id="closeAiPanelBtn" style="background: none; border: none; color: #fff; font-size: 1.5rem; cursor: pointer;">&times;</button>
    </div>
    
    <!-- جسم اللوحة (محتوى الشات والتحليلات) -->
    <div id="aiChatBody" style="flex: 1; padding: 20px; overflow-y: auto; background: #f8fafc; display: flex; flex-direction: column; gap: 15px;">
        <div class="ai-message" style="background: #eef2f6; padding: 12px 16px; border-radius: 12px 12px 0 12px; max-width: 85%; align-self: flex-start; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
            <p class="m-0 font-weight-bold text-secondary mb-1" style="font-size: 0.8rem;">المساعد الذكي</p>
            <p class="m-0" style="font-size: 0.9rem; line-height: 1.5; color: #334155;">
                مرحباً بك! يمكنني فحص مستويات المخزون، وتحليل الأصناف الأكثر نقصاً، وتقديم توصيات حول إعادة الطلب والسلع الراكدة.
            </p>
            <button type="button" onclick="triggerQuickInsights()" class="btn-flat btn-flat-primary btn-sm mt-3 py-1 px-3 w-100" style="font-size: 0.8rem;">
                <i class="bi bi-activity ml-1"></i> تشغيل تحليل الجرد الفوري والمخاطر
            </button>
        </div>
    </div>
    
    <!-- أسفل اللوحة (شريط الكتابة) -->
    <div style="padding: 15px; border-top: 1px solid #e2e8f0; background: #fff; display: flex; gap: 8px;">
        <input type="text" id="aiChatInput" class="form-control rounded-0" placeholder="اسألني عن المخزون هنا..." style="font-size: 0.9rem;">
        <button type="button" id="sendAiChatBtn" class="btn-flat btn-flat-primary py-2 px-3">إرسال</button>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const aiBtn = document.getElementById('aiInventoryBtn');
    const aiPanel = document.getElementById('aiInventoryPanel');
    const closePanelBtn = document.getElementById('closeAiPanelBtn');
    const sendBtn = document.getElementById('sendAiChatBtn');
    const chatInput = document.getElementById('aiChatInput');
    const chatBody = document.getElementById('aiChatBody');
    
    if (aiBtn && aiPanel && closePanelBtn) {
        aiBtn.addEventListener('click', function() {
            aiPanel.style.left = '0px';
        });
        closePanelBtn.addEventListener('click', function() {
            aiPanel.style.left = '-420px';
        });
    }
    
    function appendMessage(role, text) {
        const msgDiv = document.createElement('div');
        msgDiv.style.maxWidth = '85%';
        msgDiv.style.padding = '12px 16px';
        msgDiv.style.borderRadius = '12px';
        msgDiv.style.boxShadow = '0 1px 3px rgba(0,0,0,0.05)';
        msgDiv.style.fontSize = '0.9rem';
        msgDiv.style.lineHeight = '1.5';
        
        if (role === 'user') {
            msgDiv.style.background = '#e0f2fe';
            msgDiv.style.color = '#0369a1';
            msgDiv.style.alignSelf = 'flex-end';
            msgDiv.style.borderRadius = '12px 12px 12px 0';
            msgDiv.innerHTML = `<p class="m-0 font-weight-bold mb-1" style="font-size: 0.8rem;">أنت</p><p class="m-0">${escapeHtml(text)}</p>`;
        } else {
            msgDiv.style.background = '#eef2f6';
            msgDiv.style.color = '#334155';
            msgDiv.style.alignSelf = 'flex-start';
            msgDiv.style.borderRadius = '12px 12px 0 12px';
            msgDiv.innerHTML = `<p class="m-0 font-weight-bold text-secondary mb-1" style="font-size: 0.8rem;">المساعد الذكي</p><p class="m-0">${markdownToHtml(text)}</p>`;
        }
        chatBody.appendChild(msgDiv);
        chatBody.scrollTop = chatBody.scrollHeight;
    }
    
    function escapeHtml(str) {
        return str.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;");
    }
    
    function markdownToHtml(text) {
        let html = escapeHtml(text);
        html = html.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
        html = html.replace(/\*(.*?)\*/g, '<em>$1</em>');
        html = html.replace(/\[(.*?)\]\((.*?)\)/g, '<a href="$2" target="_blank" class="text-primary font-weight-bold">$1</a>');
        html = html.replace(/\n/g, '<br>');
        return html;
    }
    
    function sendMessage(text) {
        if (!text.trim()) return;
        appendMessage('user', text);
        chatInput.value = '';
        
        const typingDiv = document.createElement('div');
        typingDiv.id = 'aiTypingIndicator';
        typingDiv.style.alignSelf = 'flex-start';
        typingDiv.style.color = '#64748b';
        typingDiv.style.fontSize = '0.85rem';
        typingDiv.style.padding = '5px';
        typingDiv.textContent = 'جاري التحليل وصياغة الرد...';
        chatBody.appendChild(typingDiv);
        chatBody.scrollTop = chatBody.scrollHeight;
        
        fetch('../api/ai_assistant.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'message=' + encodeURIComponent(text)
        })
        .then(r => r.json())
        .then(data => {
            const indicator = document.getElementById('aiTypingIndicator');
            if (indicator) indicator.remove();
            
            if (data.status === 'success') {
                appendMessage('model', data.message);
            } else {
                appendMessage('model', 'عذراً، حدث خطأ: ' + (data.message || 'خطأ غير معروف.'));
            }
        })
        .catch(err => {
            const indicator = document.getElementById('aiTypingIndicator');
            if (indicator) indicator.remove();
            appendMessage('model', 'فشل الاتصال بخادم الذكاء الاصطناعي: ' + err.message);
        });
    }
    
    window.triggerQuickInsights = function() {
        sendMessage('قم بإعطائي تحليلاً ذكياً لحالة جرد المخزن وتحديد المخاطر والأصناف الأكثر نقصاً');
    };
    
    if (sendBtn && chatInput) {
        sendBtn.addEventListener('click', function() {
            sendMessage(chatInput.value);
        });
        chatInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                sendMessage(chatInput.value);
            }
        });
    }
});
</script>

<?php
require_once($dir_prefix . 'includes/footer.php');
?>
