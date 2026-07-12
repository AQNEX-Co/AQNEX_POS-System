<?php
$dir_prefix = '../';
$module = 'products';
require_once($dir_prefix . 'includes/header.php');

check_permission(['admin', 'inventory']);
if (isset($_POST['btn_save'])) {
    $name = $conn->real_escape_string($_POST['name']);
    $barcode = $conn->real_escape_string(trim($_POST['barcode']));
    $qty = intval($_POST['quantity']);
    $min_stock_alert = doubleval($_POST['min_stock_alert'] ?? 0);
    $buy_price = doubleval($_POST['buy_price']);
    $sale_price = doubleval($_POST['sale_price']);
    $catid = intval($_POST['catid']);
    $sector_id = isset($_POST['sector_id']) && $_POST['sector_id'] !== '' ? intval($_POST['sector_id']) : null;
    
    // توليد باركود عشوائي فريد إذا كان فارغاً
    if (empty($barcode)) {
        do {
            $barcode = '629' . str_pad(mt_rand(0, 9999999999), 10, '0', STR_PAD_LEFT);
            $chk = $conn->query("SELECT id FROM products WHERE barcode = '$barcode'");
        } while ($chk && $chk->num_rows > 0);
    } else {
        // التحقق من تكرار الباركود المدخل يدوياً
        $chk_dup = $conn->query("SELECT id FROM products WHERE barcode = '$barcode' AND delete_status = 0");
        if ($chk_dup && $chk_dup->num_rows > 0) {
            $error = "رمز الباركود this register already to another product!";
        }
    }
    
    if (!isset($error)) {
        // إجمالي قيمة المخزون لهذا الصنف
        $total_val = $qty * $buy_price;
        $today = date("Y-m-d H:i:s");
        
        if (!empty($name) && $catid > 0) {
            $sql = "INSERT INTO products(name, barcode, quantity, min_stock_alert, buy_price, sale_price, catid, total, date, delete_status) 
                    VALUES ('$name', '$barcode', '$qty', '$min_stock_alert', '$buy_price', '$sale_price', '$catid', '$total_val', '$today', 0)";
            if ($conn->query($sql)) {
                $new_product_id = $conn->insert_id;
                $unit_name = isset($_POST['unit_name']) ? $conn->real_escape_string(trim($_POST['unit_name'])) : 'حبة';
                if (empty($unit_name)) $unit_name = 'حبة';
                
                // إضافة الوحدة الأساسية في جدول الوحدات
                $conn->query("INSERT INTO product_units (product_id, unit_name, conversion_factor, sale_price, purchase_price, is_base_unit) 
                              VALUES ($new_product_id, '$unit_name', 1.0000, $sale_price, $buy_price, 1)");

                // إضافة كمية بدفعة صلاحية افتراضية في المخازن لكي يظهر مخزن الصنف
                $batch_no = 'BATCH-INIT-' . $new_product_id;
                $conn->query("INSERT INTO product_batches (product_id, batch_number, quantity, cost_price) 
                              VALUES ($new_product_id, '$batch_no', $qty, $buy_price)");
                
                // تهيئة كمية الصنف في مستودع 1
                $conn->query("INSERT INTO warehouses_stock (warehouse_id, product_id, quantity) 
                              VALUES (1, $new_product_id, $qty) 
                              ON DUPLICATE KEY UPDATE quantity = quantity + $qty");

                echo "<script>window.location='index.php';</script>";
                exit;
            } else {
                $error = "حدث خطأ أثناء إضافة المنتج: " . $conn->error;
            }
        }
    }
}
?>
<title>إضافة منتج جديد للمستودع - تكنولوجيا فون</title>

<div class="row no-print mb-4">
    <div class="col-md-6">
        <h3 class="text-secondary font-weight-bold">
            <i class="fa fa-cubes ml-2"></i>إضافة منتج جديد للمستودع
        </h3>
    </div>
    <div class="col-md-6 text-left">
        <a href="index.php" class="btn-flat btn-flat-secondary btn-sm text-decoration-none">
            <i class="fa fa-arrow-left ml-1"></i>عودة لقائمة المنتجات
        </a>
    </div>
</div>

<div class="row justify-content-center">
    <div class="col-md-10 col-lg-8">
        <div class="card-flat">
            <div class="card-header">
                <h5>بيانات المنتج الجديد</h5>
            </div>
            <div class="card-body">
                <?php if (isset($error)): ?>
                    <div class="alert alert-danger rounded-0 mb-3"><?php echo $error; ?></div>
                <?php endif; ?>

                <form method="POST" id="productForm">
                    <div class="row">
                        <!-- اسم المنتج -->
                        <div class="col-md-6 mb-3">
                            <label class="form-label font-weight-bold text-secondary">اسم المنتج *</label>
                            <input type="text" name="name" class="form-control rounded-0" placeholder="اسم منتج فريد ومميز..." required>
                        </div>

                        <!-- الباركود -->
                        <div class="col-md-6 mb-3">
                            <label class="form-label font-weight-bold text-secondary">رمز الباركود (اختياري)</label>
                            <div class="input-group">
                                <input type="text" name="barcode" id="barcode" class="form-control rounded-0" placeholder="أدخل رمز الباركود أو اتركه فارغاً">
                                <div class="input-group-append">
                                    <button type="button" id="generateBarcode" class="btn btn-outline-secondary rounded-0 btn-sm">توليد عشوائي</button>
                                </div>
                            </div>
                        </div>
                        
                        <!-- الصنف -->
                        <div class="col-md-6 mb-3">
                            <label class="form-label font-weight-bold text-secondary">تصنيف المنتج *</label>
                            <select name="catid" class="form-control rounded-0" required>
                                <option value="">-- اختر التصنيف --</option>
                                <?php
                                $sql_cat = "SELECT catid, name FROM categories WHERE d_s = 0 ORDER BY catid DESC";
                                $res_cat = $conn->query($sql_cat);
                                if ($res_cat) {
                                    while($row = $res_cat->fetch_assoc()) {
                                        echo "<option value='".$row['catid']."'>".htmlspecialchars($row['name'])."</option>";
                                    }
                                }
                                ?>
                            </select>
                        </div>

                        <!-- الوحدة الأساسية -->
                        <div class="col-md-6 mb-3">
                            <label class="form-label font-weight-bold text-secondary">الوحدة الأساسية (مثال: حبة، كرتون) *</label>
                            <input type="text" name="unit_name" class="form-control rounded-0" placeholder="حبة، كرتون، درزن..." value="حبة" required>
                        </div>

                        <!-- الكمية -->
                        <div class="col-md-3 mb-3">
                            <label class="form-label font-weight-bold text-secondary">الكمية المتوفرة</label>
                            <input type="number" name="quantity" id="quantity" class="form-control rounded-0 text-center" value="0" min="0" required>
                        </div>

                        <!-- حد تنبيه المخزون -->
                        <div class="col-md-3 mb-3">
                            <label class="form-label font-weight-bold text-secondary">حد التنبيه الأدنى</label>
                            <input type="number" name="min_stock_alert" id="min_stock_alert" class="form-control rounded-0 text-center" value="5" min="0" required>
                        </div>

                        <!-- سعر الشراء -->
                        <div class="col-md-3 mb-3">
                            <label class="form-label font-weight-bold text-secondary">سعر الشراء الفردي</label>
                            <input type="number" step="any" name="buy_price" id="buy_price" class="form-control rounded-0 text-center" value="0" required>
                        </div>

                        <!-- سعر البيع -->
                        <div class="col-md-3 mb-3">
                            <label class="form-label font-weight-bold text-secondary">سعر البيع الفردي</label>
                            <input type="number" step="any" name="sale_price" id="sale_price" class="form-control rounded-0 text-center" value="0" required>
                        </div>

                        <!-- حسابات تلقائية -->
                        <div class="col-md-6 mb-3">
                            <label class="form-label font-weight-bold text-muted">إجمالي قيمة البضاعة بالمخزن (تلقائي)</label>
                            <input type="text" id="total_cost" name="too" class="form-control rounded-0 text-center font-weight-bold bg-light" readonly value="0.00">
                        </div>

                        <div class="col-md-6 mb-3">
                            <label class="form-label font-weight-bold text-muted">إجمالي الأرباح المتوقعة للكمية (تلقائي)</label>
                            <input type="text" id="expected_profit" class="form-control rounded-0 text-center font-weight-bold bg-light text-success" readonly value="0.00">
                        </div>
                    </div>

                    <div class="alert alert-info rounded-0 p-3 mt-3 text-right" dir="rtl">
                        <i class="fa fa-info-circle ml-1"></i>
                        <strong>ملاحظة:</strong> لتهيئة وحدات القياس المتعددة (مثل الكرتون والدرزن)، قم بحفظ وإضافة الصنف أولاً، ثم اذهب لتعديله لربطه بالوحدات المخصصة وتحديد أسعارها ومعامل التحويل.
                    </div>

                    <div class="mt-4 text-left">
                        <button type="submit" name="btn_save" class="btn-flat btn-flat-primary px-5">
                            <i class="fa fa-save ml-1"></i> إضافة المنتج
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script type="text/javascript">
document.addEventListener("DOMContentLoaded", function() {
    const qtyInput = document.getElementById("quantity");
    const buyInput = document.getElementById("buy_price");
    const saleInput = document.getElementById("sale_price");
    const totalCost = document.getElementById("total_cost");
    const expProfit = document.getElementById("expected_profit");
    
    function calculate() {
        const qty = parseInt(qtyInput.value) || 0;
        const buy = parseFloat(buyInput.value) || 0;
        const sale = parseFloat(saleInput.value) || 0;
        
        const cost = qty * buy;
        totalCost.value = cost.toFixed(2);
        
        const profit = (sale - buy) * qty;
        expProfit.value = profit.toFixed(2);
    }
    
    const barcodeInput = document.getElementById("barcode");
    const generateBtn = document.getElementById("generateBarcode");
    if (generateBtn && barcodeInput) {
        generateBtn.addEventListener("click", function() {
            let randDigits = "";
            for (let i = 0; i < 10; i++) {
                randDigits += Math.floor(Math.random() * 10);
            }
            barcodeInput.value = "629" + randDigits;
        });
    }

    qtyInput.addEventListener("input", calculate);
    buyInput.addEventListener("input", calculate);
    saleInput.addEventListener("input", calculate);
});
</script>

<?php
require_once($dir_prefix . 'includes/footer.php');
?>
