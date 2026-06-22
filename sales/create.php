<?php
$dir_prefix = '../';
$module = 'sales';
require_once($dir_prefix . 'includes/header.php');

check_permission(['admin', 'cashier']);

// الإعدادات متاحة عبر $global_settings من header.php
$settings = $global_settings;

$save_error = ''; // لعرض أخطاء الحفظ للمستخدم

if (isset($_POST['btn_save'])) {
    $build_date = date('Y-m-d', strtotime($_POST['build_date']));
    $customer_name = $conn->real_escape_string($_POST['customer_name']);
    $grand_paid = doubleval($_POST['grand_paid']);
    $grand_profit = doubleval($_POST['grand_profit']);
    $remark = $conn->real_escape_string($_POST['remark']);
    
    $currency_code = $conn->real_escape_string($_POST['currency_code']);
    $exchange_rate = doubleval($_POST['exchange_rate']);
    if ($exchange_rate <= 0) $exchange_rate = 1.0;

    $active_box_id = get_user_box_id($conn, $_SESSION['SESS_MEMBER_ID']);
    $box_name = get_box_name($conn, $active_box_id);

    // حساب تفاصيل المجاميع بالعملة الأساسية
    $products = $_POST['product_id'];
    $quantities = $_POST['quantity'];
    $unit_prices = $_POST['unit_price'];
    $paids = $_POST['paid_amount'];
    $discounts = $_POST['discount_amount'];
    $remainings = $_POST['remaining_amount'];
    $buy_prices = $_POST['buy_price'];
    
    $total_paid_base = 0;
    $total_remaining_base = 0;
    $total_discount_base = 0;
    $total_cost_base = 0;
    $count = count($products);
    
    for ($i = 0; $i < $count; $i++) {
        $p_id = intval($products[$i]);
        $qty = intval($quantities[$i]);
        if ($p_id > 0 && $qty > 0) {
            $total_paid_base += doubleval($paids[$i]) * $exchange_rate;
            $total_remaining_base += doubleval($remainings[$i]) * $exchange_rate;
            $total_discount_base += doubleval($discounts[$i]) * $exchange_rate;
            $total_cost_base += ($qty * doubleval($buy_prices[$i])) * $exchange_rate;
        }
    }

    $grand_profit_base = $grand_profit * $exchange_rate;

    // 1. إدراج الفاتورة في جدول المبيعات
    $sql_insert = "INSERT INTO `sales`(`build_date`, `cust_name`, `total`, `prifet`, `remark`, `delete_status`, `currency_code`, `exchange_rate`, `remaining_total`, `box_id`) 
                   VALUES ('$build_date', '$customer_name', '$total_paid_base', '$grand_profit_base', '$remark', 0, '$currency_code', '$exchange_rate', '$total_remaining_base', $active_box_id)";
    if ($conn->query($sql_insert)) {
        $billing_id = $conn->insert_id;
        
        for ($i = 0; $i < $count; $i++) {
            $p_id = intval($products[$i]);
            $qty = intval($quantities[$i]);
            $price = doubleval($unit_prices[$i]);
            $paid = doubleval($paids[$i]);
            $disc = doubleval($discounts[$i]);
            $rem = doubleval($remainings[$i]);
            
            if ($p_id > 0 && $qty > 0) {
                $sql_p = "SELECT name FROM products WHERE id = $p_id";
                $res_p = $conn->query($sql_p);
                $p_row = $res_p->fetch_assoc();
                $product_name_db = $conn->real_escape_string($p_row['name']);
                $product_field_val = "$p_id $product_name_db";
                
                $price_base = $price * $exchange_rate;
                $paid_base = $paid * $exchange_rate;
                $disc_base = $disc * $exchange_rate;
                $rem_base = $rem * $exchange_rate;
                $line_total_base = ($qty * $price) * $exchange_rate;
                
                $sql_item = "INSERT INTO `sales_items`(`sales_id`, `id`, `cust_name`, `name`, `quantity`, `unit_price`, `bush`, `d`, `dis`, `total`, `all_tot`, `build_date`) 
                             VALUES ('$billing_id', '$p_id', '$customer_name', '$product_field_val', '$qty', '$price_base', '$paid_base', '$disc_base', '$rem_base', '$paid_base', '$line_total_base', '$build_date')";
                $conn->query($sql_item);
                
                $sql_update_qty = "UPDATE `products` SET `quantity` = `quantity` - $qty WHERE `id` = $p_id";
                $conn->query($sql_update_qty);
                
                $sql_log = "INSERT INTO `inventory_log` (`product_id`, `product_name`, `type`, `qty_change`, `new_qty`, `reason`, `user`) 
                            SELECT $p_id, name, 'sale', -$qty, quantity, 'عملية بيع بفاتورة رقم #$billing_id', '" . $_SESSION['SESS_FIRST_NAME'] . "' 
                            FROM products WHERE id = $p_id";
                $conn->query($sql_log);
                
                if (!empty($customer_name) && $customer_name !== 'عميل نقدي' && $rem_base > 0) {
                    $sql_update_cust = "UPDATE `customers` SET `cust_madeen` = `cust_madeen` + $rem_base WHERE `cust_name` = '$customer_name'";
                    $conn->query($sql_update_cust);
                }
            }
        }
        
        // 2. تحديث رصيد الصندوق النشط وحفظ الحركة (تم إلغاء التحديث التلقائي للصندوق بناءً على طلب المستخدم - يتم الترحيل والمطابقة نهاية اليوم يدوياً)
        // if ($total_paid_base > 0) {
        //     update_box_balance($conn, $active_box_id, $total_paid_base, 'addition', "مبيعات نقدية فاتورة رقم #$billing_id", $build_date);
        // }

        // 3. تسجيل القيود المحاسبية للفاتورة بالتفصيل المالي
        $user_display = $_SESSION['SESS_FIRST_NAME'];
        
        // قيد المقبوض النقدي (إن وجد)
        if ($total_paid_base > 0) {
            post_journal_entry($conn, 'sale', $billing_id, 'الصندوق - ' . $box_name, 'المبيعات', $total_paid_base, "مبيعات نقدية فاتورة #$billing_id - $customer_name", $user_display, $active_box_id, $currency_code, $exchange_rate);
        }
        
        // قيد المديونية الآجلة (إن وجد)
        if ($total_remaining_base > 0) {
            post_journal_entry($conn, 'sale', $billing_id, 'الذمم المدينة - ' . $customer_name, 'المبيعات', $total_remaining_base, "مبيعات آجل فاتورة #$billing_id - $customer_name", $user_display, $active_box_id, $currency_code, $exchange_rate);
        }
        
        // قيد الخصم المسموح به (إن وجد)
        if ($total_discount_base > 0) {
            post_journal_entry($conn, 'sale', $billing_id, 'الخصم المسموح به (مصروف)', 'المبيعات', $total_discount_base, "خصم مبيعات فاتورة #$billing_id - $customer_name", $user_display, $active_box_id, $currency_code, $exchange_rate);
        }

        // قيد تكلفة البضاعة المباعة والمخزون
        if ($total_cost_base > 0) {
            post_journal_entry($conn, 'sale', $billing_id, 'تكلفة البضاعة المباعة (مصروف)', 'المخزون / البضاعة', $total_cost_base, "إثبات تكلفة مبيعات فاتورة #$billing_id", $user_display, $active_box_id, $currency_code, $exchange_rate);
        }

        echo "<script>window.location='view.php?id=$billing_id&autoprint=1';</script>";
        exit;
    } else {
        $save_error = 'فشل حفظ الفاتورة: ' . $conn->error;
    }
}
?>
<?php
// جلب قائمة المنتجات لاستخدامها في البحث التلقائي المحلي
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

// جلب قائمة العملات لتحديد العملة وسعر الصرف
$currencies_list = [];
$res_curr = $conn->query("SELECT * FROM currencies ORDER BY id ASC");
if ($res_curr) {
    while($c = $res_curr->fetch_assoc()) {
        $currencies_list[] = $c;
    }
}
?>
<title>إضافة فاتورة مبيعات جديدة - تكنولوجيا فون</title>

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
        <h5><?php echo get_icon('sales', 'ml-2 text-primary'); ?> إضافة فاتورة مبيعات جديدة</h5>
        <a href="index.php" class="btn-flat btn-flat-secondary btn-sm text-decoration-none">
            <?php echo get_icon('logout', 'ml-1'); ?> عودة
        </a>
    </div>
    <div class="card-body">
        <?php if (!empty($save_error)): ?>
            <div class="alert alert-danger rounded-0 mb-4">
                <strong>خطأ في حفظ الفاتورة:</strong> <?php echo htmlspecialchars($save_error); ?>
            </div>
        <?php endif; ?>
        <form method="POST" id="salesForm">
            <!-- بيانات الفاتورة الأساسية -->
            <div class="row mb-4">
                <div class="col-md-2 col-sm-6 mb-3">
                    <label class="form-label font-weight-bold text-secondary">تاريخ البيع</label>
                    <input type="date" name="build_date" class="form-control rounded-0" value="<?php echo date('Y-m-d'); ?>" required>
                </div>
                <div class="col-md-3 col-sm-6 mb-3">
                    <label class="form-label font-weight-bold text-secondary">العميل</label>
                    <select name="customer_name" class="form-control rounded-0" required>
                        <option value="">-- اختر عميل --</option>
                        <option value="عميل نقدي" selected>عميل نقدي</option>
                        <?php
                        $sql_cust = "SELECT cust_name FROM customers WHERE d_s = 0 ORDER BY cust_id DESC";
                        $res_cust = $conn->query($sql_cust);
                        if ($res_cust) {
                            while($row = $res_cust->fetch_assoc()) {
                                echo "<option value='".htmlspecialchars($row['cust_name'])."'>".htmlspecialchars($row['cust_name'])."</option>";
                            }
                        }
                        ?>
                    </select>
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
                <?php if ($settings && $settings['barcode_scanner'] == 1): ?>
                <div class="col-md-3 col-sm-12 mb-3">
                    <label class="form-label font-weight-bold text-primary">قارئ الباركود (مسح سريع)</label>
                    <div class="input-group">
                        <div class="input-group-prepend">
                            <span class="input-group-text">
                                <i class="bi bi-upc-scan mt-1 text-white bg-primary"></i>
                            </span>
                        </div>
                        <input type="text" id="barcodeScanInput" class="form-control rounded-0 border-primary font-weight-bold text-center" placeholder="امسح باركود المنتج هنا..." autofocus autocomplete="off">
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- جدول المنتجات مبني ديناميكياً -->
            <div class="table-responsive">
                <table class="table-flat" id="itemsTable">
                    <thead>
                        <tr>
                            <th style="width: 25%;">المنتج</th>
                            <th style="width: 10%;">المخزن</th>
                            <th style="width: 10%;">الكمية</th>
                            <th style="width: 12%;">سعر البيع</th>
                            <th style="width: 12%;">المجموع</th>
                            <th style="width: 12%;">المدفوع</th>
                            <th style="width: 10%;">الخصم</th>
                            <th style="width: 12%;">الباقي</th>
                            <th class="no-print" style="width: 5%;">اجراء</th>
                        </tr>
                    </thead>
                    <tbody id="itemsContainer">
                        <!-- صف البداية الافتراضي -->
                        <tr class="item-row">
                            <td>
                                <div class="product-search-container">
                                    <input type="text" class="form-control product-search-input rounded-0" placeholder="ابحث باسم المنتج أو الباركود..." autocomplete="off" required>
                                    <input type="hidden" name="product_id[]" class="select-product" value="">
                                    <div class="autocomplete-dropdown d-none"></div>
                                </div>
                                <input type="hidden" name="buy_price[]" class="buy-price" value="0">
                            </td>
                            <td>
                                <input type="text" class="form-control stock-qty text-center bg-light rounded-0" readonly value="0">
                            </td>
                            <td>
                                <input type="number" name="quantity[]" class="form-control quantity-input text-center rounded-0" min="1" value="1" required>
                            </td>
                            <td>
                                <input type="number" step="any" name="unit_price[]" class="form-control price-input text-center rounded-0" required>
                            </td>
                            <td>
                                <input type="text" class="form-control total-input text-center bg-light rounded-0" readonly value="0">
                            </td>
                            <td>
                                <input type="number" step="any" name="paid_amount[]" class="form-control paid-input text-center rounded-0" value="0">
                            </td>
                            <td>
                                <input type="number" step="any" name="discount_amount[]" class="form-control discount-input text-center rounded-0" value="0">
                            </td>
                            <td>
                                <input type="text" name="remaining_amount[]" class="form-control remaining-input text-center bg-light rounded-0" readonly value="0">
                                <input type="hidden" class="profit-input" name="profit[]" value="0">
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
                    <textarea name="remark" class="form-control rounded-0" rows="3" placeholder="ملاحظات حول عملية البيع..."></textarea>
                </div>
                <div class="col-lg-6">
                    <div class="card bg-light border-0">
                        <div class="card-body">
                            <table class="w-100">
                                <tr class="border-bottom">
                                    <td class="py-2">إجمالي الفاتورة المدفوع (المقبوض)</td>
                                    <td class="text-left font-weight-bold" style="font-size: 1.2rem;">
                                        <input type="text" id="grandPaidDisplay" name="grand_paid" class="form-control text-left font-weight-bold bg-transparent border-0 rounded-0" readonly value="0">
                                    </td>
                                </tr>
                                <tr class="border-bottom">
                                    <td class="py-2">إجمالي المتبقي (المديونية)</td>
                                    <td class="text-left text-danger font-weight-bold" style="font-size: 1.2rem;">
                                        <span id="grandRemainingDisplay">0.00</span> <span class="currency-symbol">ر.ي</span>
                                    </td>
                                </tr>
                                <tr>
                                    <td class="py-2">إجمالي الربح المتوقع</td>
                                    <td class="text-left text-success font-weight-bold" style="font-size: 1.2rem;">
                                        <input type="text" id="grandProfitDisplay" name="grand_profit" class="form-control text-left text-success font-weight-bold bg-transparent border-0 rounded-0" readonly value="0">
                                    </td>
                                </tr>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div class="mt-4 no-print text-left">
                <button type="submit" name="btn_save" class="btn-flat btn-flat-primary btn-lg px-5">
                    <?php echo get_icon('check', 'ml-1'); ?> حفظ الفاتورة وترحيلها
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
        
        // حساب الإجمالي والربح والفرعي
        const lineTotal = qty * price;
        row.querySelector(".total-input").value = lineTotal.toFixed(2);
        
        const paidInput = row.querySelector(".paid-input");
        if (!paidInput.hasAttribute("data-manually-edited")) {
            paidInput.value = lineTotal.toFixed(2);
        }
        
        const paid = parseFloat(paidInput.value) || 0;
        const disc = parseFloat(row.querySelector(".discount-input").value) || 0;
        const buyPrice = parseFloat(row.querySelector(".buy-price").value) || 0;
        
        const remaining = lineTotal - paid - disc;
        row.querySelector(".remaining-input").value = remaining.toFixed(2);
        
        // الربح = السعر المباع - سعر الشراء مطروحاً منه الخصم
        const profit = lineTotal - (buyPrice * qty) - disc;
        row.querySelector(".profit-input").value = profit.toFixed(2);
        
        updateGrandTotals();
    }
    
    // دالة تحديث المجاميع الكلية للفاتورة
    function updateGrandTotals() {
        let totalPaid = 0;
        let totalRemaining = 0;
        let totalProfit = 0;
        
        document.querySelectorAll(".item-row").forEach(function(row) {
            totalPaid += parseFloat(row.querySelector(".paid-input").value) || 0;
            totalRemaining += parseFloat(row.querySelector(".remaining-input").value) || 0;
            totalProfit += parseFloat(row.querySelector(".profit-input").value) || 0;
        });
        
        document.getElementById("grandPaidDisplay").value = totalPaid.toFixed(2);
        document.getElementById("grandRemainingDisplay").textContent = totalRemaining.toFixed(2);
        document.getElementById("grandProfitDisplay").value = totalProfit.toFixed(2);
    }
    
    // إضافة صنف جديد
    addItemBtn.addEventListener("click", function() {
        const newRow = rowTemplate.cloneNode(true);
        // إعادة تهيئة الحقول للقيم الافتراضية
        newRow.querySelector(".product-search-input").value = "";
        newRow.querySelector(".select-product").value = "";
        newRow.querySelector(".select-product").removeAttribute("data-base-sale-price");
        newRow.querySelector(".select-product").removeAttribute("data-base-buy-price");
        newRow.querySelector(".buy-price").value = "0";
        newRow.querySelector(".stock-qty").value = "0";
        newRow.querySelector(".quantity-input").value = "1";
        newRow.querySelector(".price-input").value = "";
        newRow.querySelector(".total-input").value = "0";
        newRow.querySelector(".paid-input").value = "0";
        newRow.querySelector(".paid-input").removeAttribute("data-manually-edited");
        newRow.querySelector(".discount-input").value = "0";
        newRow.querySelector(".remaining-input").value = "0";
        newRow.querySelector(".profit-input").value = "0";
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
    
    // منع إرسال النموذج عند الضغط على Enter في حقول الإدخال
    document.getElementById("salesForm").addEventListener("keydown", function(e) {
        if (e.key === "Enter") {
            if (e.target.tagName !== "BUTTON" && e.target.tagName !== "TEXTAREA") {
                e.preventDefault();
            }
        }
    });
    
    // أحداث التغيير داخل الجدول
    itemsContainer.addEventListener("input", function(e) {
        if (e.target.matches(".quantity-input, .price-input, .paid-input, .discount-input")) {
            const row = e.target.closest(".item-row");
            
            if (e.target.classList.contains("paid-input")) {
                e.target.setAttribute("data-manually-edited", "true");
            }
            
            // تحقق المخزون
            if (e.target.classList.contains("quantity-input")) {
                const qty = parseInt(e.target.value) || 0;
                const stock = parseInt(row.querySelector(".stock-qty").value) || 0;
                if (qty > stock) {
                    alert("تحذير: الكمية المطلوبة (" + qty + ") تتجاوز المتوفر في المخزن (" + stock + ")!");
                    e.target.value = stock; // إرجاع القيمة للكمية المتاحة كحد أقصى لمنع البيع بالسالب
                }
            }
            updateRowCalculations(row);
        }
    });

    // البحث التلقائي (Autocomplete)
    function showAutocompleteDropdown(input) {
        const query = input.value.trim().toLowerCase();
        const container = input.closest(".product-search-container");
        const dropdown = container.querySelector(".autocomplete-dropdown");
        const hiddenInput = container.querySelector(".select-product");
        const row = input.closest(".item-row");
        
        if (query === "") {
            dropdown.classList.add("d-none");
            dropdown.innerHTML = "";
            hiddenInput.value = "";
            hiddenInput.removeAttribute("data-base-sale-price");
            hiddenInput.removeAttribute("data-base-buy-price");
            row.querySelector(".buy-price").value = "0";
            row.querySelector(".stock-qty").value = "0";
            row.querySelector(".price-input").value = "";
            row.querySelector(".total-input").value = "0";
            row.querySelector(".paid-input").value = "0";
            row.querySelector(".paid-input").removeAttribute("data-manually-edited");
            row.querySelector(".discount-input").value = "0";
            row.querySelector(".remaining-input").value = "0";
            row.querySelector(".profit-input").value = "0";
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
            const salePriceConverted = (p.sale_price / rate).toFixed(2);
            html += `
                <div class="autocomplete-item" data-id="${p.id}" data-idx="${idx}">
                    <div class="font-weight-bold text-dark">${escapeHtml(p.name)}</div>
                    <div class="item-meta d-flex justify-content-between">
                        <span>الباركود: ${escapeHtml(p.barcode || '-')}</span>
                        <span>السعر: ${salePriceConverted} | المخزن: ${p.quantity}</span>
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
        const dropdown = container.querySelector(".autocomplete-dropdown");
        
        input.value = product.name;
        hiddenInput.value = product.id;
        hiddenInput.setAttribute("data-base-sale-price", product.sale_price);
        hiddenInput.setAttribute("data-base-buy-price", product.buy_price);
        
        const rate = parseFloat(exchangeRateInput.value) || 1.0;
        
        row.querySelector(".buy-price").value = (product.buy_price / rate).toFixed(2);
        row.querySelector(".stock-qty").value = product.quantity;
        
        const salePriceConverted = (product.sale_price / rate).toFixed(2);
        row.querySelector(".price-input").value = salePriceConverted;
        row.querySelector(".paid-input").value = salePriceConverted;
        row.querySelector(".paid-input").removeAttribute("data-manually-edited");
        row.querySelector(".quantity-input").value = 1;
        row.querySelector(".discount-input").value = 0;
        
        dropdown.classList.add("d-none");
        dropdown.innerHTML = "";
        
        updateRowCalculations(row);
        
        if (parseInt(product.quantity) <= 0) {
            alert("تنبيه: هذا المنتج غير متوفر في المخزن حالياً!");
        }
        
        // الانتقال التلقائي لحقل الكمية وتحديده بعد اختيار الصنف
        setTimeout(() => {
            const qtyInput = row.querySelector(".quantity-input");
            if (qtyInput) {
                qtyInput.focus();
                qtyInput.select();
            }
        }, 50);
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

    // معالجة التنقل بأزرار الانتر بين الحقول داخل الصفوف
    itemsContainer.addEventListener("keydown", function(e) {
        if (e.key === "Enter") {
            const row = e.target.closest(".item-row");
            if (!row) return;
            
            if (e.target.classList.contains("quantity-input")) {
                e.preventDefault();
                const paidInput = row.querySelector(".paid-input");
                if (paidInput) {
                    paidInput.focus();
                    paidInput.select();
                }
            } else if (e.target.classList.contains("paid-input")) {
                e.preventDefault();
                const discountInput = row.querySelector(".discount-input");
                if (discountInput) {
                    discountInput.focus();
                    discountInput.select();
                }
            } else if (e.target.classList.contains("discount-input")) {
                e.preventDefault();
                // الانتقال لصنف جديد
                const nextRow = row.nextElementSibling;
                if (nextRow && nextRow.classList.contains("item-row")) {
                    const nextSearch = nextRow.querySelector(".product-search-input");
                    if (nextSearch) {
                        nextSearch.focus();
                    }
                } else {
                    // إضافة سطر جديد تلقائياً
                    addItemBtn.click();
                    setTimeout(() => {
                        const rows = itemsContainer.querySelectorAll(".item-row");
                        const lastRow = rows[rows.length - 1];
                        if (lastRow) {
                            const lastSearch = lastRow.querySelector(".product-search-input");
                            if (lastSearch) {
                                lastSearch.focus();
                            }
                        }
                    }, 50);
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
            const baseSalePrice = parseFloat(selectProd.getAttribute("data-base-sale-price")) || 0;
            const baseBuyPrice = parseFloat(selectProd.getAttribute("data-base-buy-price")) || 0;
            
            if (baseSalePrice > 0) {
                const newSalePrice = baseSalePrice / rate;
                row.querySelector(".price-input").value = newSalePrice.toFixed(2);
                row.querySelector(".paid-input").value = newSalePrice.toFixed(2);
                row.querySelector(".paid-input").removeAttribute("data-manually-edited");
                row.querySelector(".buy-price").value = (baseBuyPrice / rate).toFixed(2);
                
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

    // معالجة قارئ الباركود التلقائي (من الحقل العلوي إذا كان مفعلاً)
    const barcodeScanInput = document.getElementById("barcodeScanInput");
    if (barcodeScanInput) {
        barcodeScanInput.addEventListener("keydown", function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                const barcode = this.value.trim();
                if (barcode) {
                    fetchProductByBarcode(barcode);
                }
            }
        });
    }

    function fetchProductByBarcode(barcode) {
        const product = availableProducts.find(p => p.barcode === barcode);
        if (product) {
            addScannedProduct(product);
        } else {
            alert("عذراً، لم يتم العثور على أي منتج بهذا الباركود (" + barcode + ")!");
        }
        if (barcodeScanInput) {
            barcodeScanInput.value = "";
            barcodeScanInput.focus();
        }
    }

    function addScannedProduct(product) {
        let existingRow = null;
        document.querySelectorAll(".item-row").forEach(function(row) {
            const selectVal = row.querySelector(".select-product").value;
            if (selectVal == product.id) {
                existingRow = row;
            }
        });
        
        if (existingRow) {
            let qtyInput = existingRow.querySelector(".quantity-input");
            let currentQty = parseInt(qtyInput.value) || 0;
            let newQty = currentQty + 1;
            let stock = parseInt(existingRow.querySelector(".stock-qty").value) || 0;
            if (newQty > stock) {
                alert("تحذير: الكمية المطلوبة (" + newQty + ") تتجاوز المتوفر في المخزن (" + stock + ")!");
                newQty = stock;
            }
            qtyInput.value = newQty;
            updateRowCalculations(existingRow);
        } else {
            let rows = document.querySelectorAll(".item-row");
            let targetRow = null;
            if (rows.length === 1 && rows[0].querySelector(".select-product").value === "") {
                targetRow = rows[0];
            } else {
                addItemBtn.click();
                const newRows = document.querySelectorAll(".item-row");
                targetRow = newRows[newRows.length - 1];
            }
            
            selectProductForRow(targetRow, product);
        }
    }
});
</script>
