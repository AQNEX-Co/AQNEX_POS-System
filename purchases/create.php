<?php
$dir_prefix = '../';
$module = 'purchases';
require_once($dir_prefix . 'includes/header.php');

check_permission(['admin', 'inventory', 'cashier']);

$active_user_id = intval($_SESSION['SESS_MEMBER_ID']);
$active_user_role = trim($_SESSION['SESS_LAST_NAME']);
$is_admin = ($active_user_role === 'admin' || empty($active_user_role));

if (isset($_POST['btn_save'])) {
    $selected_box_id = isset($_POST['box_id']) ? intval($_POST['box_id']) : get_user_box_id($conn, $active_user_id);
    $box_name = get_box_name($conn, $selected_box_id);

    $build_date = date('Y-m-d', strtotime($_POST['build_date']));
    $supplier_name = $conn->real_escape_string($_POST['supplier_name']);
    $grand_total = doubleval($_POST['grand_total']);
    $remark = $conn->real_escape_string($_POST['remark']);

    $currency_code = $conn->real_escape_string($_POST['currency_code']);
    $exchange_rate = doubleval($_POST['exchange_rate']);
    if ($exchange_rate <= 0) $exchange_rate = 1.0;

    $grand_total_base = $grand_total * $exchange_rate;

    // 1. إدراج الفاتورة في جدول المشتريات
    $sql_insert = "INSERT INTO `purchases`(`date`, `supp_name`, `total`, `remark`, `currency_code`, `exchange_rate`, `box_id`) 
                   VALUES ('$build_date', '$supplier_name', '$grand_total_base', '$remark', '$currency_code', '$exchange_rate', $selected_box_id)";
    if ($conn->query($sql_insert)) {
        $billing_id = $conn->insert_id;
        
        $products = $_POST['product_name'];
        $product_ids = $_POST['product_id'];
        $quantities = $_POST['quantity'];
        $unit_prices = $_POST['unit_price'];
        $line_totals = $_POST['line_total'];
        $paids = $_POST['paid_amount'];
        $remainings = $_POST['remaining_amount'];
        
        $count = count($products);
        $total_paid_base = 0;
        $total_remaining_base = 0;

        for ($i = 0; $i < $count; $i++) {
            $p_name = $conn->real_escape_string($products[$i]);
            $p_id = intval($product_ids[$i]);
            $qty = intval($quantities[$i]);
            $u_price = doubleval($unit_prices[$i]);
            $l_total = doubleval($line_totals[$i]);
            $paid = doubleval($paids[$i]);
            $rem = doubleval($remainings[$i]);
            
            if (!empty($p_name) && $qty > 0) {
                // تحويل المبالغ للعملة الأساسية (YER)
                $l_total_base = $l_total * $exchange_rate;
                $paid_base = $paid * $exchange_rate;
                $rem_base = $rem * $exchange_rate;
                $unit_buy_price_base = $u_price * $exchange_rate;
                
                $total_paid_base += $paid_base;
                $total_remaining_base += $rem_base;

                // إدراج تفاصيل الشراء
                $sql_item = "INSERT INTO `purchase_items`(`buys_date`, `supp_name`, `name`, `quantity`, `buy_price`, `pushtosupp`, `total_d`, `s`) 
                             VALUES ('$build_date', '$supplier_name', '$p_name', '$qty', '$l_total_base', '$paid_base', '$rem_base', 0)";
                $conn->query($sql_item);
                
                // تحديث كمية المنتج وسعر الشراء في المخزن
                if ($p_id > 0) {
                    $sql_update_qty = "UPDATE `products` SET `quantity` = `quantity` + $qty, `buy_price` = $unit_buy_price_base, `total` = `quantity` * `buy_price` WHERE `id` = $p_id";
                    $conn->query($sql_update_qty);
                    
                    // تسجيل الحركة في سجل المخازن
                    $sql_log = "INSERT INTO `inventory_log` (`product_id`, `product_name`, `type`, `qty_change`, `new_qty`, `reason`, `user`) 
                                SELECT $p_id, name, 'purchase', $qty, quantity, 'عملية شراء بفاتورة رقم #$billing_id', '" . $_SESSION['SESS_FIRST_NAME'] . "' 
                                FROM products WHERE id = $p_id";
                    $conn->query($sql_log);
                } else {
                    // احتياطي بالاسم
                    $sql_update_qty = "UPDATE `products` SET `quantity` = `quantity` + $qty, `buy_price` = $unit_buy_price_base, `total` = `quantity` * `buy_price` WHERE `name` = '$p_name'";
                    $conn->query($sql_update_qty);
                }
            }
        }
        
        // تحديث مديونية المورد بالعملة الأساسية
        if (!empty($supplier_name) && $total_remaining_base > 0) {
            $sql_update_supp = "UPDATE `suppliers` SET `supp_daain` = `supp_daain` + $total_remaining_base WHERE `supp_name` = '$supplier_name'";
            $conn->query($sql_update_supp);
        }
        
        // تحديث الخزينة (الصندوق المالي) - خصم المبلغ المدفوع
        if ($total_paid_base > 0) {
            update_box_balance($conn, $selected_box_id, $total_paid_base, 'discount', "مدفوعات فاتورة مشتريات رقم #$billing_id", $build_date);
        }

        // إثبات القيود اليومية المزدوجة
        if ($total_paid_base > 0) {
            post_journal_entry($conn, 'purchase', $billing_id, 'المخزون / البضاعة', 'الصندوق - ' . $box_name, $total_paid_base, "شراء بضاعة (نقداً) فاتورة رقم #$billing_id", $_SESSION['SESS_FIRST_NAME'], $selected_box_id);
        }
        if ($total_remaining_base > 0) {
            post_journal_entry($conn, 'purchase', $billing_id, 'المخزون / البضاعة', 'الذمم الدائنة - ' . $supplier_name, $total_remaining_base, "شراء بضاعة (آجل) فاتورة رقم #$billing_id", $_SESSION['SESS_FIRST_NAME'], $selected_box_id);
        }
        
        // إزالة القيود الفارغة
        $conn->query("DELETE FROM `purchase_items` WHERE `quantity` = 0 AND `buy_price` = 0");
        
        echo "<script>window.location='index.php';</script>";
        exit;
    }
}
?>
<?php
// جلب المنتجات للبحث المحلي
$products_list = [];
$res_prod = $conn->query("SELECT id, name, barcode, quantity, buy_price, sale_price FROM products WHERE delete_status = 0 ORDER BY id DESC");
if ($res_prod) {
    while($row = $res_prod->fetch_assoc()) {
        $products_list[] = [
            'id' => intval($row['id']),
            'name' => $row['name'],
            'barcode' => $row['barcode'],
            'quantity' => intval($row['quantity']),
            'buy_price' => doubleval($row['buy_price']),
            'sale_price' => doubleval($row['sale_price'])
        ];
    }
}
$products_json = json_encode($products_list, JSON_UNESCAPED_UNICODE);

// جلب العملات
$currencies_list = [];
$res_curr = $conn->query("SELECT * FROM currencies ORDER BY id ASC");
if ($res_curr) {
    while($c = $res_curr->fetch_assoc()) {
        $currencies_list[] = $c;
    }
}
?>
<title>تسجيل فاتورة مشتريات جديدة - تكنولوجيا فون</title>

<style>
.product-search-container {
    position: relative;
}
.autocomplete-dropdown {
    position: absolute;
    top: 100%;
    right: 0;
    width: 100%;
    background: #fff;
    border: 1px solid var(--secondary);
    border-top: none;
    max-height: 250px;
    overflow-y: auto;
    z-index: 1050;
    box-shadow: 0 4px 8px rgba(0,0,0,0.15);
}
.autocomplete-item {
    padding: 8px 12px;
    cursor: pointer;
    border-bottom: 1px solid #f1f5f9;
    font-size: 0.9rem;
    transition: background-color 0.2s;
    text-align: right;
}
.autocomplete-item:hover, .autocomplete-item.active {
    background-color: #f8f9fa;
    color: var(--secondary);
}
.autocomplete-item .item-meta {
    font-size: 0.75rem;
    color: #64748b;
}
</style>

<div class="card-flat">
    <div class="card-header">
        <h5><?php echo get_icon('purchases', 'ml-2 text-primary'); ?> تسجيل فاتورة مشتريات جديدة</h5>
        <a href="index.php" class="btn-flat btn-flat-secondary btn-sm text-decoration-none">
            <?php echo get_icon('logout', 'ml-1'); ?> عودة
        </a>
    </div>
    <div class="card-body">
        <form method="POST" id="purchaseForm">
            <!-- بيانات الفاتورة الأساسية -->
            <div class="row mb-4">
                <div class="col-md-2 col-sm-6 mb-3">
                    <label class="form-label font-weight-bold text-secondary">تاريخ الشراء</label>
                    <input type="date" name="build_date" class="form-control rounded-0" value="<?php echo date('Y-m-d'); ?>" required>
                </div>
                <div class="col-md-3 col-sm-6 mb-3">
                    <label class="form-label font-weight-bold text-secondary">المورد</label>
                    <select name="supplier_name" class="form-control rounded-0" required>
                        <option value="">-- اختر مورد --</option>
                        <?php
                        $sql_supp = "SELECT supp_name FROM suppliers WHERE d_s = 0 ORDER BY supp_id DESC";
                        $res_supp = $conn->query($sql_supp);
                        if ($res_supp) {
                            while($row = $res_supp->fetch_assoc()) {
                                echo "<option value='".htmlspecialchars($row['supp_name'])."'>".htmlspecialchars($row['supp_name'])."</option>";
                            }
                        }
                        ?>
                    </select>
                </div>
                <div class="col-md-3 col-sm-6 mb-3">
                    <label class="form-label font-weight-bold text-secondary">الصندوق (للمدفوعات النقدية)</label>
                    <?php if ($is_admin): ?>
                        <select name="box_id" class="form-control rounded-0" required>
                            <?php
                            $res_b = $conn->query("SELECT box_id, name, mony FROM treasury WHERE is_active = 1 ORDER BY box_id ASC");
                            if ($res_b) {
                                while($b = $res_b->fetch_assoc()) {
                                    echo "<option value='{$b['box_id']}' " . ($b['box_id'] == 1 ? 'selected' : '') . ">" . htmlspecialchars($b['name']) . " (" . number_format($b['mony'], 2) . " ر.ي)</option>";
                                }
                            }
                            ?>
                        </select>
                    <?php else: ?>
                        <?php $user_box_id = get_user_box_id($conn, $active_user_id); ?>
                        <input type="hidden" name="box_id" value="<?php echo $user_box_id; ?>">
                        <input type="text" class="form-control text-center font-weight-bold bg-light rounded-0" readonly value="<?php echo htmlspecialchars(get_box_name($conn, $user_box_id)) . ' (' . number_format(floatval($conn->query("SELECT mony FROM treasury WHERE box_id = $user_box_id")->fetch_assoc()['mony']), 2) . ' ر.ي)'; ?>">
                    <?php endif; ?>
                </div>
                <div class="col-md-2 col-sm-6 mb-3">
                    <label class="form-label font-weight-bold text-secondary">عملة الفاتورة</label>
                    <select name="currency_code" id="currencySelect" class="form-control rounded-0" required>
                        <?php foreach($currencies_list as $c): ?>
                            <option value="<?php echo htmlspecialchars($c['code']); ?>" data-rate="<?php echo $c['exchange_rate']; ?>" data-symbol="<?php echo htmlspecialchars($c['symbol']); ?>" <?php echo ($c['code'] === 'YER') ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($c['name']); ?> (<?php echo htmlspecialchars($c['symbol']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2 col-sm-6 mb-3">
                    <label class="form-label font-weight-bold text-secondary">سعر الصرف (YER)</label>
                    <input type="number" step="any" name="exchange_rate" id="exchangeRateInput" class="form-control rounded-0 font-weight-bold text-center bg-light" value="1.0" readonly required>
                </div>
            </div>

            <!-- جدول المواد المشتراة -->
            <div class="table-responsive">
                <table class="table-flat" id="purchaseTable">
                    <thead>
                        <tr>
                            <th style="width: 30%;">المنتج</th>
                            <th style="width: 10%;">الكمية</th>
                            <th style="width: 15%;">سعر الشراء الفردي</th>
                            <th style="width: 15%;">المجموع الكلي</th>
                            <th style="width: 15%;">المبلغ المدفوع للمورد</th>
                            <th style="width: 15%;">المبلغ المتبقي</th>
                            <th class="no-print" style="width: 5%;">اجراء</th>
                        </tr>
                    </thead>
                    <tbody id="itemsContainer">
                        <!-- صف البداية الافتراضي -->
                        <tr class="item-row">
                            <td>
                                <div class="product-search-container">
                                    <input type="text" class="form-control product-search-input rounded-0" placeholder="ابحث باسم المنتج أو الباركود..." autocomplete="off" required>
                                    <input type="hidden" name="product_name[]" class="select-product-name" value="">
                                    <input type="hidden" name="product_id[]" class="select-product" value="">
                                    <div class="autocomplete-dropdown d-none"></div>
                                </div>
                            </td>
                            <td>
                                <input type="number" name="quantity[]" class="form-control quantity-input text-center rounded-0" min="1" value="1" required>
                            </td>
                            <td>
                                <input type="number" step="any" name="unit_price[]" class="form-control price-input text-center rounded-0" required>
                            </td>
                            <td>
                                <input type="text" name="line_total[]" class="form-control total-input text-center bg-light rounded-0" readonly value="0">
                            </td>
                            <td>
                                <input type="number" step="any" name="paid_amount[]" class="form-control paid-input text-center rounded-0" value="0">
                            </td>
                            <td>
                                <input type="text" name="remaining_amount[]" class="form-control remaining-input text-center bg-light rounded-0" readonly value="0">
                            </td>
                            <td class="no-print">
                                <button type="button" class="btn-flat btn-flat-danger btn-sm py-1 px-2 remove-item-btn">
                                    <?php echo get_icon('trash'); ?>
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- أزرار الإجراء للجدول -->
            <div class="mt-3 no-print">
                <button type="button" id="addItemBtn" class="btn-flat btn-flat-success btn-sm">
                    <?php echo get_icon('plus', 'ml-1'); ?> إضافة صنف آخر
                </button>
            </div>

            <hr class="my-4">

            <!-- ملخص الفاتورة والمجاميع الكلية -->
            <div class="row">
                <div class="col-lg-6 mb-3">
                    <label class="form-label font-weight-bold text-secondary">ملاحظات الفاتورة</label>
                    <textarea name="remark" class="form-control rounded-0" rows="3" placeholder="ملاحظات حول عملية الشراء..."></textarea>
                </div>
                <div class="col-lg-6">
                    <div class="card bg-light border-0">
                        <div class="card-body">
                            <table class="w-100">
                                <tbody>
                                    <tr>
                                        <td class="py-2">إجمالي الفاتورة الكلي</td>
                                        <td class="text-left font-weight-bold" style="font-size: 1.2rem;">
                                            <input type="text" id="grandTotalDisplay" name="grand_total" class="form-control text-left font-weight-bold bg-transparent border-0 rounded-0 w-75 d-inline" readonly value="0">
                                            <span class="currency-symbol">ر.ي</span>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div class="mt-4 no-print text-left">
                <button type="submit" name="btn_save" class="btn-flat btn-flat-primary btn-lg px-5">
                    <?php echo get_icon('check', 'ml-1'); ?> حفظ الفاتورة
                </button>
            </div>
        </form>
    </div>
</div>

<script type="text/javascript">
const availableProducts = <?php echo $products_json; ?>;

document.addEventListener("DOMContentLoaded", function() {
    const itemsContainer = document.getElementById("itemsContainer");
    const addItemBtn = document.getElementById("addItemBtn");
    const currencySelect = document.getElementById("currencySelect");
    const exchangeRateInput = document.getElementById("exchangeRateInput");
    
    // حفظ نسخة من أول صف كنموذج
    const rowTemplate = document.querySelector(".item-row").cloneNode(true);
    
    function escapeHtml(text) {
        if (!text) return '';
        return text
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }

    // دالة تحديث الحسابات لصف معين
    function updateRowCalculations(row) {
        const qty = parseInt(row.querySelector(".quantity-input").value) || 0;
        const price = parseFloat(row.querySelector(".price-input").value) || 0;
        const paid = parseFloat(row.querySelector(".paid-input").value) || 0;
        
        // حساب الإجمالي والفرعي
        const lineTotal = qty * price;
        row.querySelector(".total-input").value = lineTotal.toFixed(2);
        
        const remaining = lineTotal - paid;
        row.querySelector(".remaining-input").value = remaining.toFixed(2);
        
        updateGrandTotals();
    }
    
    // دالة تحديث المجاميع الكلية للفاتورة
    function updateGrandTotals() {
        let totalVal = 0;
        
        document.querySelectorAll(".item-row").forEach(function(row) {
            totalVal += parseFloat(row.querySelector(".total-input").value) || 0;
        });
        
        document.getElementById("grandTotalDisplay").value = totalVal.toFixed(2);
    }
    
    // إضافة صنف جديد
    addItemBtn.addEventListener("click", function() {
        const newRow = rowTemplate.cloneNode(true);
        // إعادة تهيئة الحقول للقيم الافتراضية
        newRow.querySelector(".product-search-input").value = "";
        newRow.querySelector(".select-product-name").value = "";
        newRow.querySelector(".select-product").value = "";
        newRow.querySelector(".select-product").removeAttribute("data-base-buy-price");
        newRow.querySelector(".quantity-input").value = "1";
        newRow.querySelector(".price-input").value = "";
        newRow.querySelector(".total-input").value = "0";
        newRow.querySelector(".paid-input").value = "0";
        newRow.querySelector(".remaining-input").value = "0";
        newRow.querySelector(".autocomplete-dropdown").classList.add("d-none");
        newRow.querySelector(".autocomplete-dropdown").innerHTML = "";
        
        itemsContainer.appendChild(newRow);
    });
    
    // حذف صنف
    itemsContainer.addEventListener("click", function(e) {
        if (e.target.classList.contains("remove-item-btn") || e.target.closest(".remove-item-btn")) {
            const row = e.target.closest(".item-row");
            if (document.querySelectorAll(".item-row").length > 1) {
                row.remove();
                updateGrandTotals();
            } else {
                alert("يجب أن تحتوي الفاتورة على صنف واحد على الأقل!");
            }
        }
    });
    
    // أحداث التغيير داخل الجدول
    itemsContainer.addEventListener("input", function(e) {
        if (e.target.matches(".quantity-input, .price-input, .paid-input")) {
            const row = e.target.closest(".item-row");
            updateRowCalculations(row);
        }
    });

    // البحث التلقائي (Autocomplete)
    function showAutocompleteDropdown(input) {
        const query = input.value.trim().toLowerCase();
        const container = input.closest(".product-search-container");
        const dropdown = container.querySelector(".autocomplete-dropdown");
        const hiddenInput = container.querySelector(".select-product");
        const nameInput = container.querySelector(".select-product-name");
        const row = input.closest(".item-row");
        
        if (query === "") {
            dropdown.classList.add("d-none");
            dropdown.innerHTML = "";
            hiddenInput.value = "";
            nameInput.value = "";
            hiddenInput.removeAttribute("data-base-buy-price");
            row.querySelector(".quantity-input").value = "1";
            row.querySelector(".price-input").value = "";
            row.querySelector(".total-input").value = "0";
            row.querySelector(".paid-input").value = "0";
            row.querySelector(".remaining-input").value = "0";
            updateGrandTotals();
            return;
        }
        
        const rate = parseFloat(exchangeRateInput.value) || 1.0;
        const matches = availableProducts.filter(p => {
            return p.name.toLowerCase().includes(query) || (p.barcode && p.barcode.toLowerCase().includes(query));
        }).slice(0, 15);
        
        if (matches.length === 0) {
            dropdown.innerHTML = '<div class="autocomplete-item text-muted text-center">لا يوجد نتائج تطابق البحث</div>';
            dropdown.classList.remove("d-none");
            return;
        }
        
        let html = "";
        matches.forEach((p, idx) => {
            const buyPriceConverted = (p.buy_price / rate).toFixed(2);
            html += `
                <div class="autocomplete-item" data-id="${p.id}" data-idx="${idx}">
                    <div class="font-weight-bold text-dark">${escapeHtml(p.name)}</div>
                    <div class="item-meta d-flex justify-content-between">
                        <span>الباركود: ${escapeHtml(p.barcode || '-')}</span>
                        <span>تكلفة الشراء: ${buyPriceConverted} | المخزن الحالي: ${p.quantity}</span>
                    </div>
                </div>
            `;
        });
        
        dropdown.innerHTML = html;
        dropdown.classList.remove("d-none");
    }

    function selectProductForRow(row, product) {
        const container = row.querySelector(".product-search-container");
        const input = container.querySelector(".product-search-input");
        const hiddenInput = container.querySelector(".select-product");
        const nameInput = container.querySelector(".select-product-name");
        const dropdown = container.querySelector(".autocomplete-dropdown");
        
        input.value = product.name;
        hiddenInput.value = product.id;
        nameInput.value = product.name;
        hiddenInput.setAttribute("data-base-buy-price", product.buy_price);
        
        const rate = parseFloat(exchangeRateInput.value) || 1.0;
        const buyPriceConverted = (product.buy_price / rate).toFixed(2);
        
        row.querySelector(".price-input").value = buyPriceConverted;
        row.querySelector(".paid-input").value = buyPriceConverted;
        row.querySelector(".quantity-input").value = 1;
        
        dropdown.classList.add("d-none");
        dropdown.innerHTML = "";
        
        updateRowCalculations(row);
    }

    itemsContainer.addEventListener("input", function(e) {
        if (e.target.matches(".product-search-input")) {
            showAutocompleteDropdown(e.target);
        }
    });

    itemsContainer.addEventListener("click", function(e) {
        const item = e.target.closest(".autocomplete-item");
        if (item) {
            const productId = item.getAttribute("data-id");
            const row = item.closest(".item-row");
            if (productId && row) {
                const product = availableProducts.find(p => p.id == productId);
                if (product) {
                    selectProductForRow(row, product);
                }
            }
        }
    });

    itemsContainer.addEventListener("keydown", function(e) {
        if (e.target.matches(".product-search-input")) {
            const container = e.target.closest(".product-search-container");
            const dropdown = container.querySelector(".autocomplete-dropdown");
            if (dropdown.classList.contains("d-none")) return;
            
            const items = dropdown.querySelectorAll(".autocomplete-item");
            if (items.length === 0) return;
            
            let activeIdx = -1;
            items.forEach((item, idx) => {
                if (item.classList.contains("active")) {
                    activeIdx = idx;
                }
            });
            
            if (e.key === "ArrowDown") {
                e.preventDefault();
                if (activeIdx !== -1) items[activeIdx].classList.remove("active");
                activeIdx = (activeIdx + 1) % items.length;
                items[activeIdx].classList.add("active");
                items[activeIdx].scrollIntoView({ block: "nearest" });
            } else if (e.key === "ArrowUp") {
                e.preventDefault();
                if (activeIdx !== -1) items[activeIdx].classList.remove("active");
                activeIdx = (activeIdx - 1 + items.length) % items.length;
                items[activeIdx].classList.add("active");
                items[activeIdx].scrollIntoView({ block: "nearest" });
            } else if (e.key === "Enter") {
                e.preventDefault();
                if (activeIdx !== -1) {
                    items[activeIdx].click();
                } else if (items.length > 0) {
                    items[0].click();
                }
            }
        }
    });

    document.addEventListener("click", function(e) {
        if (!e.target.closest(".product-search-container")) {
            document.querySelectorAll(".autocomplete-dropdown").forEach(d => {
                d.classList.add("d-none");
            });
        }
    });

    // معالجة تغيير العملة وسعر الصرف
    function convertAllRowsToNewCurrency() {
        const rate = parseFloat(exchangeRateInput.value) || 1.0;
        document.querySelectorAll(".item-row").forEach(function(row) {
            const selectProd = row.querySelector(".select-product");
            const baseBuyPrice = parseFloat(selectProd.getAttribute("data-base-buy-price")) || 0;
            
            if (baseBuyPrice > 0) {
                const newBuyPrice = baseBuyPrice / rate;
                row.querySelector(".price-input").value = newBuyPrice.toFixed(2);
                row.querySelector(".paid-input").value = newBuyPrice.toFixed(2);
                
                updateRowCalculations(row);
            }
        });
    }

    currencySelect.addEventListener("change", function() {
        const selectedOpt = this.options[this.selectedIndex];
        const rate = parseFloat(selectedOpt.getAttribute("data-rate")) || 1.0;
        const symbol = selectedOpt.getAttribute("data-symbol") || "ر.ي";
        const isBase = selectedOpt.value === "YER";
        
        exchangeRateInput.value = rate;
        if (isBase) {
            exchangeRateInput.setAttribute("readonly", "readonly");
            exchangeRateInput.classList.add("bg-light");
        } else {
            exchangeRateInput.removeAttribute("readonly");
            exchangeRateInput.classList.remove("bg-light");
        }
        
        // تحديث رمز العملة
        document.querySelectorAll(".currency-symbol").forEach(el => {
            el.textContent = symbol;
        });
        
        convertAllRowsToNewCurrency();
    });
    
    exchangeRateInput.addEventListener("input", function() {
        convertAllRowsToNewCurrency();
    });
});
</script>

<?php
require_once($dir_prefix . 'includes/footer.php');
?>
