<?php
$dir_prefix = '../';
$module = 'inventory';
require_once($dir_prefix . 'includes/header.php');

check_permission(['admin', 'inventory']);

$active_user_id = intval($_SESSION['SESS_MEMBER_ID']);
$user_display = $_SESSION['SESS_FIRST_NAME'] ?? 'مستخدم';
$save_error = '';
$save_success = '';

// جلب التصنيفات
$categories_list = [];
$res_cat = $conn->query("SELECT catid, name FROM categories WHERE d_s = 0 ORDER BY name ASC");
if ($res_cat) {
    while($c = $res_cat->fetch_assoc()) $categories_list[] = $c;
}

// جلب المستودعات
$warehouses_list = [];
$res_wh = $conn->query("SELECT id, name FROM warehouses WHERE d_s = 0 ORDER BY id ASC");
if ($res_wh) {
    while($w = $res_wh->fetch_assoc()) $warehouses_list[] = $w;
}

// ======================== معالجة الحفظ ========================
if (isset($_POST['btn_save'])) {
    $entry_date   = $conn->real_escape_string($_POST['entry_date'] ?? date('Y-m-d'));
    $warehouse_id = intval($_POST['warehouse_id'] ?? 1);
    $notes        = $conn->real_escape_string($_POST['notes'] ?? '');
    $source_type  = in_array($_POST['source_type'] ?? '', ['initial','manual','return']) ? $_POST['source_type'] : 'initial';

    $product_names  = $_POST['product_name'] ?? [];
    $product_ids    = $_POST['product_id'] ?? [];
    $category_ids   = $_POST['category_id'] ?? [];
    $category_names = $_POST['category_name'] ?? [];
    $barcodes       = $_POST['barcode'] ?? [];
    $quantities     = $_POST['quantity'] ?? [];
    $cost_prices    = $_POST['cost_price'] ?? [];
    $sale_prices    = $_POST['sale_price'] ?? [];
    $unit_names     = $_POST['unit_name'] ?? [];
    $batch_numbers  = $_POST['batch_number'] ?? [];
    $expiry_dates   = $_POST['expiry_date'] ?? [];

    $count = count($product_names);

    if ($count === 0) {
        $save_error = "يجب إضافة صنف واحد على الأقل.";
    } else {
        $conn->begin_transaction();
        try {
            $total_entry_value = 0;

            for ($i = 0; $i < $count; $i++) {
                $p_name = trim($product_names[$i]);
                $p_id   = intval($product_ids[$i]);
                $qty    = doubleval($quantities[$i]);
                $cost   = doubleval($cost_prices[$i]);
                $s_price = doubleval($sale_prices[$i]);
                $barcode  = $conn->real_escape_string(trim($barcodes[$i] ?? ''));
                $cat_id   = intval($category_ids[$i] ?? 0);
                $cat_name = trim($category_names[$i] ?? '');
                $unit_nm  = $conn->real_escape_string(trim($unit_names[$i] ?? 'حبة'));
                $batch_no = $conn->real_escape_string(trim($batch_numbers[$i] ?? ''));
                $exp_date = trim($expiry_dates[$i] ?? '');

                if (empty($p_name) || $qty <= 0) continue;

                $p_name_esc = $conn->real_escape_string($p_name);

                // تحقق من التصنيف أو أنشئه
                if ($cat_id <= 0 && !empty($cat_name)) {
                    $cat_name_esc = $conn->real_escape_string($cat_name);
                    $r = $conn->query("SELECT catid FROM categories WHERE name='$cat_name_esc' AND d_s=0 LIMIT 1");
                    if ($r && $r->num_rows > 0) {
                        $cat_id = intval($r->fetch_assoc()['catid']);
                    } else {
                        $conn->query("INSERT INTO categories(name, d_s) VALUES('$cat_name_esc', 0)");
                        $cat_id = $conn->insert_id;
                    }
                }
                if ($cat_id <= 0) {
                    $r = $conn->query("SELECT catid FROM categories WHERE d_s=0 LIMIT 1");
                    if ($r && $r->num_rows > 0) {
                        $cat_id = intval($r->fetch_assoc()['catid']);
                    } else {
                        $conn->query("INSERT INTO categories(name, d_s) VALUES('عام', 0)");
                        $cat_id = $conn->insert_id;
                    }
                }

                // تحقق من وجود المنتج أو أنشئه
                if ($p_id <= 0) {
                    $r = $conn->query("SELECT id FROM products WHERE name='$p_name_esc' AND delete_status=0 LIMIT 1");
                    if ($r && $r->num_rows > 0) {
                        $p_id = intval($r->fetch_assoc()['id']);
                    } else {
                        // إنشاء منتج جديد
                        if (empty($barcode)) {
                            do {
                                $barcode = '629' . str_pad(mt_rand(0, 9999999999), 10, '0', STR_PAD_LEFT);
                                $chk_b = $conn->query("SELECT id FROM products WHERE barcode='$barcode'");
                            } while ($chk_b && $chk_b->num_rows > 0);
                        }
                        $auto_sale = $s_price > 0 ? $s_price : ($cost * 1.25);
                        $conn->query("INSERT INTO products(name, barcode, quantity, buy_price, sale_price, catid, date, delete_status)
                                      VALUES('$p_name_esc', '$barcode', 0, $cost, $auto_sale, $cat_id, NOW(), 0)");
                        $p_id = $conn->insert_id;
                        if (!$p_id) throw new Exception("فشل إنشاء المنتج: $p_name");

                        // وحدة أساسية للمنتج الجديد
                        $conn->query("INSERT INTO product_units(product_id, unit_name, conversion_factor, sale_price, purchase_price, is_base_unit)
                                      VALUES($p_id, '$unit_nm', 1.0000, $auto_sale, $cost, 1)");
                    }
                }

                // تحديث سعر الشراء والتصنيف والباركود للمنتج الموجود
                $upd_fields = "buy_price=$cost, catid=$cat_id";
                if (!empty($barcode)) $upd_fields .= ", barcode='$barcode'";
                if ($s_price > 0) $upd_fields .= ", sale_price=$s_price";
                $conn->query("UPDATE products SET $upd_fields WHERE id=$p_id");

                // تحديث كمية المخزن
                $conn->query("UPDATE products SET quantity = quantity + $qty, total = (quantity + $qty) * cost_price WHERE id=$p_id");

                // رقم الباتش
                if (empty($batch_no)) {
                    $batch_no = 'SIN-' . date('Ymd') . '-' . $p_id . '-' . mt_rand(100, 999);
                }
                $exp_val = (!empty($exp_date)) ? "'$exp_date'" : "NULL";

                // إدراج باتش جديد
                $conn->query("INSERT INTO product_batches(product_id, batch_number, quantity, cost_price, expiry_date)
                              VALUES($p_id, '$batch_no', $qty, $cost, $exp_val)");

                // تحديث كمية المستودع
                $conn->query("INSERT INTO warehouses_stock(warehouse_id, product_id, quantity)
                              VALUES($warehouse_id, $p_id, $qty)
                              ON DUPLICATE KEY UPDATE quantity = quantity + $qty");

                // سجل الحركات
                $src_label = ['initial'=>'رصيد افتتاحي','manual'=>'توريد يدوي','return'=>'مرتجع من عميل'][$source_type] ?? 'توريد';
                $notes_esc = $conn->real_escape_string($notes);
                $conn->query("INSERT INTO inventory_audit_log(product_id, warehouse_id, movement_type, quantity_change, cost_price, reference_type, reference_id, notes, user, created_at)
                              VALUES($p_id, $warehouse_id, 'IN', $qty, $cost, 'stock_in', $warehouse_id, '$notes_esc - $src_label', '$user_display', NOW())");

                $total_entry_value += $qty * $cost;
            }

            $conn->commit();
            $save_success = "تم حفظ التوريد المخزني بنجاح! إجمالي قيمة البضاعة المضافة: " . number_format($total_entry_value, 2);
        } catch (Exception $e) {
            $conn->rollback();
            $save_error = "فشل الحفظ: " . $e->getMessage();
        }
    }
}
?>
<title>التوريد المخزني - AQNEX POS</title>

<style>
.product-search-container, .barcode-search-container, .category-search-container {
    position: relative;
}
.autocomplete-dropdown {
    position: absolute;
    top: 100%;
    right: 0;
    width: 100%;
    background: #fff;
    border: 1px solid #dee2e6;
    border-top: none;
    max-height: 250px;
    overflow-y: auto;
    z-index: 1050;
    box-shadow: 0 4px 12px rgba(0,0,0,0.12);
}
.autocomplete-item {
    padding: 8px 12px;
    cursor: pointer;
    font-size: 0.85rem;
    text-align: right;
    border-bottom: 1px solid #f8f9fa;
}
.autocomplete-item:hover, .autocomplete-item.active {
    background: #f0f4ff;
}
.autocomplete-item .item-meta {
    font-size: 0.75rem;
    color: #64748b;
}
.autocomplete-item.create-new {
    background: #fef3c7;
    border-top: 2px dashed #f59e0b;
    font-weight: 700;
    color: #92400e;
}
.autocomplete-item.create-new:hover { background: #fde68a; }

.table-stockin thead th {
    font-size: 0.8rem;
    font-weight: 700;
    padding: 10px 6px;
    background: #f8f9fa;
    border-bottom: 2px solid #dee2e6;
    white-space: nowrap;
}
.table-stockin tbody td {
    padding: 6px 5px;
    vertical-align: middle;
}
.table-stockin .form-control {
    font-size: 0.82rem;
    padding: 5px 7px;
}

.summary-box {
    background: #f0f4ff;
    border: 1px solid #c7d2fe;
    border-radius: 8px;
    padding: 15px;
}
.summary-box .total-val {
    font-size: 1.4rem;
    font-weight: 800;
    color: #1e3a8a;
}

.badge-source {
    padding: 3px 10px;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 700;
}
.loading-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(255,255,255,0.8);
    z-index: 9999;
    justify-content: center;
    align-items: center;
    flex-direction: column;
}
.loading-overlay.active { display: flex; }
.loading-spinner {
    width: 45px; height: 45px;
    border: 5px solid #e2e8f0;
    border-top-color: #3b82f6;
    border-radius: 50%;
    animation: spin 0.8s linear infinite;
}
@keyframes spin { to { transform: rotate(360deg); } }
</style>

<!-- Loading Overlay -->
<div class="loading-overlay" id="loadingOverlay">
    <div class="loading-spinner"></div>
    <div class="mt-3 text-secondary">جاري الحفظ...</div>
</div>

<div class="row no-print mb-4">
    <div class="col-md-6">
        <h3 class="text-secondary font-weight-bold">
            <i class="bi bi-box-arrow-in-down ml-2"></i>التوريد المخزني - إدخال رصيد
        </h3>
    </div>
    <div class="col-md-6 text-left">
        <a href="audit_log.php" class="btn-flat btn-flat-secondary btn-sm text-decoration-none ml-2">
            <i class="bi bi-clock-history ml-1"></i>سجل الحركات
        </a>
        <a href="valuation.php" class="btn-flat btn-flat-secondary btn-sm text-decoration-none">
            <i class="bi bi-calculator ml-1"></i>تقييم المخزون
        </a>
    </div>
</div>

<?php if (!empty($save_error)): ?>
    <div class="alert alert-danger rounded-0 mb-4">
        <i class="bi bi-exclamation-triangle ml-1"></i><strong>خطأ:</strong> <?php echo htmlspecialchars($save_error); ?>
    </div>
<?php endif; ?>
<?php if (!empty($save_success)): ?>
    <div class="alert alert-success rounded-0 mb-4">
        <i class="bi bi-check-circle ml-1"></i><?php echo $save_success; ?>
    </div>
<?php endif; ?>

<div class="alert alert-info rounded-0 mb-4">
    <i class="bi bi-info-circle ml-1"></i>
    <strong>ملاحظة:</strong> يمكنك إدخال أصناف غير موجودة في النظام، سيتم إنشاؤها تلقائياً مع تحديد التكلفة ورقم الباتش. استخدم هذه الصفحة لإدخال الرصيد الافتتاحي أو التوريد اليدوي بدون فاتورة مشتريات رسمية.
</div>

<form method="POST" id="stockInForm" onsubmit="document.getElementById('loadingOverlay').classList.add('active')">
    <div class="card-flat mb-4">
        <div class="card-header">
            <h5><i class="bi bi-sliders ml-2"></i>معلومات التوريد</h5>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-3 mb-3">
                    <label class="form-label font-weight-bold text-secondary">تاريخ التوريد</label>
                    <input type="date" name="entry_date" class="form-control rounded-0" value="<?php echo date('Y-m-d'); ?>" required>
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label font-weight-bold text-secondary">المستودع</label>
                    <select name="warehouse_id" class="form-control rounded-0">
                        <?php foreach($warehouses_list as $wh): ?>
                            <option value="<?php echo $wh['id']; ?>"><?php echo htmlspecialchars($wh['name']); ?></option>
                        <?php endforeach; ?>
                        <?php if (empty($warehouses_list)): ?>
                            <option value="1">المستودع الرئيسي</option>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label font-weight-bold text-secondary">نوع التوريد</label>
                    <select name="source_type" class="form-control rounded-0">
                        <option value="initial">رصيد افتتاحي</option>
                        <option value="manual">توريد يدوي</option>
                        <option value="return">مرتجع من عميل</option>
                    </select>
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label font-weight-bold text-secondary">ملاحظات</label>
                    <input type="text" name="notes" class="form-control rounded-0" placeholder="سبب التوريد...">
                </div>
            </div>
        </div>
    </div>

    <div class="card-flat mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5><i class="bi bi-table ml-2"></i>الأصناف والكميات</h5>
            <button type="button" id="addItemBtn" class="btn-flat btn-flat-success btn-sm">
                <i class="bi bi-plus-circle ml-1"></i> إضافة صنف
            </button>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-flat table-stockin table-bordered mb-0">
                    <thead>
                        <tr>
                            <th style="width:22%">اسم الصنف</th>
                            <th style="width:8%">الوحدة</th>
                            <th style="width:12%">الباركود</th>
                            <th style="width:10%">التصنيف</th>
                            <th style="width:7%">الكمية</th>
                            <th style="width:9%">سعر التكلفة</th>
                            <th style="width:9%">سعر البيع</th>
                            <th style="width:9%">رقم الباتش</th>
                            <th style="width:9%">تاريخ الانتهاء</th>
                            <th style="width:6%">الإجمالي</th>
                            <th class="no-print" style="width:4%"></th>
                        </tr>
                    </thead>
                    <tbody id="itemsContainer">
                        <tr class="item-row">
                            <td>
                                <div class="product-search-container">
                                    <input type="text" class="form-control product-search-input rounded-0" placeholder="ابحث أو اكتب اسم صنف جديد..." autocomplete="off">
                                    <input type="hidden" name="product_name[]" class="selected-product-name" value="">
                                    <input type="hidden" name="product_id[]" class="selected-product-id" value="0">
                                    <div class="autocomplete-dropdown d-none"></div>
                                </div>
                                <small class="text-warning new-product-indicator d-none"><i class="bi bi-stars ml-1"></i>سيتم إنشاؤه تلقائياً</small>
                            </td>
                            <td>
                                <input type="text" name="unit_name[]" class="form-control text-center rounded-0 unit-input" value="حبة" placeholder="حبة">
                            </td>
                            <td>
                                <div class="barcode-search-container">
                                    <div class="input-group">
                                        <input type="text" name="barcode[]" class="form-control barcode-input text-center rounded-0" placeholder="باركود" autocomplete="off">
                                        <div class="input-group-append">
                                            <button type="button" class="btn btn-sm btn-outline-secondary rounded-0 gen-barcode-btn" title="توليد"><i class="bi bi-upc-scan"></i></button>
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div class="category-search-container">
                                    <input type="text" name="category_name[]" class="form-control category-input rounded-0" placeholder="التصنيف..." autocomplete="off">
                                    <input type="hidden" name="category_id[]" class="selected-cat-id" value="0">
                                    <div class="autocomplete-dropdown d-none"></div>
                                </div>
                            </td>
                            <td>
                                <input type="number" name="quantity[]" class="form-control quantity-input text-center rounded-0" min="0.01" step="any" value="1" required>
                            </td>
                            <td>
                                <input type="number" name="cost_price[]" class="form-control cost-input text-center rounded-0" min="0" step="any" value="0" placeholder="تكلفة">
                            </td>
                            <td>
                                <input type="number" name="sale_price[]" class="form-control sale-input text-center rounded-0" min="0" step="any" value="0" placeholder="بيع">
                            </td>
                            <td>
                                <input type="text" name="batch_number[]" class="form-control text-center rounded-0" placeholder="BATCH-001">
                            </td>
                            <td>
                                <input type="date" name="expiry_date[]" class="form-control rounded-0">
                            </td>
                            <td>
                                <input type="text" class="form-control line-total text-center bg-light rounded-0" readonly value="0.00">
                            </td>
                            <td class="no-print text-center">
                                <button type="button" class="btn btn-sm btn-outline-danger rounded-0 remove-row-btn"><i class="bi bi-trash"></i></button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-5 offset-md-7">
            <div class="summary-box mb-4">
                <div class="d-flex justify-content-between align-items-center">
                    <span class="font-weight-bold text-secondary">إجمالي قيمة التوريد:</span>
                    <span class="total-val" id="grandTotalDisplay">0.00</span>
                </div>
                <div class="d-flex justify-content-between mt-2">
                    <span class="text-muted">عدد الأصناف:</span>
                    <span class="font-weight-bold" id="itemCountDisplay">0</span>
                </div>
            </div>
            <button type="submit" name="btn_save" class="btn-flat btn-flat-primary btn-block py-3 font-weight-bold" style="font-size:1.1rem">
                <i class="bi bi-save ml-2"></i> حفظ التوريد المخزني
            </button>
        </div>
    </div>
</form>

<script>
var categoriesData = <?php echo json_encode($categories_list); ?>;

function generateBarcode() {
    var digits = '';
    for (var i = 0; i < 10; i++) digits += Math.floor(Math.random() * 10);
    return '629' + digits;
}

function calcRowTotal(row) {
    var qty = parseFloat(row.querySelector('.quantity-input').value) || 0;
    var cost = parseFloat(row.querySelector('.cost-input').value) || 0;
    var total = qty * cost;
    row.querySelector('.line-total').value = total.toFixed(2);
    calcGrandTotal();
}

function calcGrandTotal() {
    var rows = document.querySelectorAll('#itemsContainer .item-row');
    var grand = 0, count = 0;
    rows.forEach(function(row) {
        grand += parseFloat(row.querySelector('.line-total').value) || 0;
        var nm = row.querySelector('.selected-product-name').value.trim();
        if (nm) count++;
    });
    document.getElementById('grandTotalDisplay').textContent = grand.toFixed(2);
    document.getElementById('itemCountDisplay').textContent = count;
}

function setupRowEvents(row) {
    // تلقائي لإجمالي البند
    row.querySelector('.quantity-input').addEventListener('input', function() { calcRowTotal(row); });
    row.querySelector('.cost-input').addEventListener('input', function() { calcRowTotal(row); });

    // زر توليد الباركود
    var genBtn = row.querySelector('.gen-barcode-btn');
    if (genBtn) {
        genBtn.addEventListener('click', function() {
            row.querySelector('.barcode-input').value = generateBarcode();
        });
    }

    // زر حذف الصف
    row.querySelector('.remove-row-btn').addEventListener('click', function() {
        if (document.querySelectorAll('#itemsContainer .item-row').length > 1) {
            row.remove();
            calcGrandTotal();
        }
    });

    // البحث في الأصناف
    var searchInput = row.querySelector('.product-search-input');
    var dropdown = row.querySelector('.product-search-container .autocomplete-dropdown');
    var hiddenName = row.querySelector('.selected-product-name');
    var hiddenId   = row.querySelector('.selected-product-id');
    var newBadge   = row.querySelector('.new-product-indicator');
    var barcodeInput = row.querySelector('.barcode-input');
    var costInput = row.querySelector('.cost-input');
    var saleInput = row.querySelector('.sale-input');
    var unitInput = row.querySelector('.unit-input');

    var ajaxTimer = null;
    searchInput.addEventListener('input', function() {
        var q = this.value.trim();
        hiddenName.value = q;
        hiddenId.value = 0;
        clearTimeout(ajaxTimer);
        if (q.length < 2) { dropdown.classList.add('d-none'); dropdown.innerHTML = ''; return; }
        ajaxTimer = setTimeout(function() {
            fetch('../products/ajax_product_search.php?q=' + encodeURIComponent(q) + '&t=' + Date.now())
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    dropdown.innerHTML = '';
                    var found = false;
                    (data.products || []).forEach(function(p) {
                        found = true;
                        var item = document.createElement('div');
                        item.className = 'autocomplete-item';
                        item.innerHTML = '<div class="font-weight-bold">' + p.name + '</div>' +
                            '<div class="item-meta">الكمية: ' + (p.quantity ?? 0) + ' | سعر التكلفة: ' + (p.buy_price ?? 0) + '</div>';
                        item.addEventListener('click', function() {
                            searchInput.value = p.name;
                            hiddenName.value  = p.name;
                            hiddenId.value    = p.id;
                            if (p.buy_price) costInput.value = p.buy_price;
                            if (p.sale_price) saleInput.value = p.sale_price;
                            if (p.barcode && !barcodeInput.value) barcodeInput.value = p.barcode;
                            newBadge.classList.add('d-none');
                            dropdown.classList.add('d-none');
                            calcRowTotal(row);
                        });
                        dropdown.appendChild(item);
                    });
                    // خيار إنشاء صنف جديد
                    var createItem = document.createElement('div');
                    createItem.className = 'autocomplete-item create-new';
                    createItem.innerHTML = '<i class="bi bi-plus-circle ml-1"></i> إنشاء صنف جديد: <strong>' + q + '</strong>';
                    createItem.addEventListener('click', function() {
                        searchInput.value = q;
                        hiddenName.value  = q;
                        hiddenId.value    = 0;
                        newBadge.classList.remove('d-none');
                        dropdown.classList.add('d-none');
                    });
                    dropdown.appendChild(createItem);
                    dropdown.classList.remove('d-none');
                })
                .catch(function() {});
        }, 300);
    });

    // إغلاق الـ dropdown عند النقر خارجه
    document.addEventListener('click', function(e) {
        if (!row.contains(e.target)) dropdown.classList.add('d-none');
    });

    // البحث في التصنيفات
    var catInput  = row.querySelector('.category-input');
    var catDrop   = row.querySelector('.category-search-container .autocomplete-dropdown');
    var catHidden = row.querySelector('.selected-cat-id');

    catInput.addEventListener('input', function() {
        var q = this.value.toLowerCase().trim();
        catDrop.innerHTML = '';
        if (!q) { catDrop.classList.add('d-none'); return; }
        var filtered = categoriesData.filter(function(c) { return c.name.toLowerCase().indexOf(q) !== -1; });
        filtered.forEach(function(c) {
            var item = document.createElement('div');
            item.className = 'autocomplete-item';
            item.textContent = c.name;
            item.addEventListener('click', function() {
                catInput.value = c.name;
                catHidden.value = c.catid;
                catDrop.classList.add('d-none');
            });
            catDrop.appendChild(item);
        });
        // إنشاء تصنيف جديد
        var createCat = document.createElement('div');
        createCat.className = 'autocomplete-item create-new';
        createCat.innerHTML = '<i class="bi bi-plus ml-1"></i> تصنيف جديد: <strong>' + this.value + '</strong>';
        createCat.addEventListener('click', function() {
            catHidden.value = 0;
            catDrop.classList.add('d-none');
        });
        catDrop.appendChild(createCat);
        catDrop.classList.remove('d-none');
    });
    document.addEventListener('click', function(e) {
        if (!row.querySelector('.category-search-container').contains(e.target)) catDrop.classList.add('d-none');
    });
}

// تهيئة الصف الأول
setupRowEvents(document.querySelector('#itemsContainer .item-row'));

// إضافة صف جديد
document.getElementById('addItemBtn').addEventListener('click', function() {
    var template = document.querySelector('#itemsContainer .item-row').cloneNode(true);

    // تفريغ حقول النسخة الجديدة
    template.querySelectorAll('input[type="text"], input[type="number"], input[type="date"]').forEach(function(inp) {
        if (inp.classList.contains('unit-input')) { inp.value = 'حبة'; return; }
        if (inp.classList.contains('quantity-input')) { inp.value = 1; return; }
        if (inp.name && inp.name.startsWith('cost')) { inp.value = 0; return; }
        if (inp.name && inp.name.startsWith('sale')) { inp.value = 0; return; }
        inp.value = '';
    });
    template.querySelector('.selected-product-id').value = 0;
    template.querySelector('.selected-product-name').value = '';
    template.querySelector('.line-total').value = '0.00';
    template.querySelector('.new-product-indicator').classList.add('d-none');
    template.querySelector('.autocomplete-dropdown').classList.add('d-none');
    template.querySelector('.autocomplete-dropdown').innerHTML = '';
    template.querySelectorAll('.category-search-container .autocomplete-dropdown').forEach(function(d) {
        d.classList.add('d-none'); d.innerHTML = '';
    });

    document.getElementById('itemsContainer').appendChild(template);
    setupRowEvents(template);
    template.querySelector('.product-search-input').focus();
});
</script>

<?php require_once($dir_prefix . 'includes/footer.php'); ?>
