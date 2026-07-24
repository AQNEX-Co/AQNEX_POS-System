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
            $error = "رمز الباركود هذا مسجل بالفعل لصنف آخر!";
        }
    }

    if (!isset($error)) {
        $total_val = $qty * $buy_price;
        $today = date("Y-m-d H:i:s");

        if (!empty($name) && $catid > 0) {
            $sql = "INSERT INTO products(name, barcode, quantity, min_stock_alert, buy_price, sale_price, catid, total, date, delete_status) 
                    VALUES ('$name', '$barcode', '$qty', '$min_stock_alert', '$buy_price', '$sale_price', '$catid', '$total_val', '$today', 0)";
            if ($conn->query($sql)) {
                $new_product_id = $conn->insert_id;
                $unit_name = isset($_POST['unit_name']) ? $conn->real_escape_string(trim($_POST['unit_name'])) : 'حبة';
                if (empty($unit_name)) $unit_name = 'حبة';

                // إضافة حبة
                $conn->query("INSERT INTO product_units (product_id, unit_name, conversion_factor, sale_price, purchase_price, is_base_unit) 
                              VALUES ($new_product_id, '$unit_name', 1.0000, $sale_price, $buy_price, 1)");

                $batch_no = 'BATCH-INIT-' . $new_product_id;
                $conn->query("INSERT INTO product_batches (product_id, batch_number, quantity, cost_price) 
                              VALUES ($new_product_id, '$batch_no', $qty, $buy_price)");

                $conn->query("INSERT INTO warehouses_stock (warehouse_id, product_id, quantity) 
                              VALUES (1, $new_product_id, $qty) 
                              ON DUPLICATE KEY UPDATE quantity = quantity + $qty");

                echo "<script>window.location='index.php';</script>";
                exit;
            } else {
                $error = "حدث خطأ أثناء إضافة الصنف: " . $conn->error;
            }
        }
    }
}
?>
<title>إضافة صنف جديد للمستودع — AQNEX POS</title>

<style>
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
.page-title-bar .ptb-actions { display: flex; gap: 8px; }
.form-label { font-size: 0.8rem; font-weight: 700; color: #334155; margin-bottom: 4px; }
.form-section-header {
    font-size: 0.8rem; font-weight: 700; color: #1e3a8a;
    border-bottom: 1px solid #e2e8f0; padding-bottom: 6px; margin-bottom: 14px;
    display: flex; align-items: center; gap: 6px;
}
.field-hint { font-size: 0.68rem; color: #94a3b8; margin-top: 3px; }
</style>

<!-- رأس الصفحة -->
<div class="page-title-bar no-print">
    <div class="ptb-left">
        <div class="icon-wrap"><i class="bi bi-box-seam"></i></div>
        <div>
            <h4>إضافة صنف جديد للمستودع</h4>
            <small>تسجيل بيانات صنف جديد في قاعدة البيانات وإضافته للمخزون</small>
        </div>
    </div>
    <div class="ptb-actions">
        <a href="index.php" class="btn btn-sm btn-light text-decoration-none" style="font-size:0.8rem; border:1px solid #cbd5e1;" title="العودة لقائمة الصنفات">
            <i class="bi bi-arrow-left ml-1"></i> العودة للقائمة
        </a>
    </div>
</div>

<div class="row justify-content-center">
    <div class="col-md-10 col-lg-9">
        <div class="card-flat">
            <div class="card-header">
                <h5><i class="bi bi-plus-circle ml-2 text-success"></i>بيانات الصنف الجديد</h5>
            </div>
            <div class="card-body">
                <?php if (isset($error)): ?>
                <div class="alert alert-danger rounded-0 mb-3" style="font-size:0.82rem; border-right:4px solid #b91c1c;">
                    <i class="bi bi-exclamation-triangle ml-2"></i><?php echo $error; ?>
                </div>
                <?php endif; ?>

                <form method="POST" id="productForm">

                    <!-- قسم: البيانات الأساسية -->
                    <div class="form-section-header">
                        <i class="bi bi-info-circle text-primary"></i> البيانات الأساسية
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">اسم الصنف *</label>
                            <input type="text" name="name" class="form-control" placeholder="اسم الصنف الفريد..." required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">رمز الباركود (اختياري)</label>
                            <div class="input-group">
                                <input type="text" name="barcode" id="barcode" class="form-control" placeholder="أدخل الباركود أو اتركه فارغاً">
                                <div class="input-group-append">
                                    <button type="button" id="generateBarcode" class="btn btn-secondary btn-sm" title="توليد باركود عشوائي">
                                        <i class="bi bi-upc-scan ml-1"></i> توليد
                                    </button>
                                </div>
                            </div>
                            <div class="field-hint">إذا تُرك فارغاً سيتم توليد باركود تلقائياً عند الحفظ</div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">تصنيف الصنف *</label>
                            <select name="catid" class="form-control" required>
                                <option value="">-- اختر التصنيف --</option>
                                <?php
                                $sql_cat = "SELECT catid, name FROM categories WHERE d_s = 0 ORDER BY catid DESC";
                                $res_cat = $conn->query($sql_cat);
                                if ($res_cat) {
                                    while($row = $res_cat->fetch_assoc()) {
                                        echo "<option value='".$row['catid']."'>" . htmlspecialchars($row['name']) . "</option>";
                                    }
                                }
                                ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">حبة *</label>
                            <input type="text" name="unit_name" class="form-control" placeholder="حبة، كرتون، درزن..." value="حبة" required>
                            <div class="field-hint">يمكن إضافة وحدات متعددة بعد حفظ الصنف من خيار التعديل</div>
                        </div>
                    </div>

                    <!-- قسم: المخزون والأسعار -->
                    <div class="form-section-header mt-2">
                        <i class="bi bi-graph-up text-success"></i> المخزون والأسعار
                    </div>
                    <div class="row">
                        <div class="col-md-3 mb-3">
                            <label class="form-label">الكمية المتوفرة</label>
                            <input type="number" name="quantity" id="quantity" class="form-control text-center font-weight-bold" value="0" min="0" required>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">حد التنبيه الأدنى</label>
                            <input type="number" name="min_stock_alert" id="min_stock_alert" class="form-control text-center" value="5" min="0" required>
                            <div class="field-hint">عند انخفاض المخزون لهذا الحد يظهر تنبيه</div>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">سعر الشراء الفردي (ر.ي)</label>
                            <input type="number" step="any" name="buy_price" id="buy_price" class="form-control text-center" value="0" required>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">سعر البيع الفردي (ر.ي)</label>
                            <input type="number" step="any" name="sale_price" id="sale_price" class="form-control text-center" value="0" required>
                        </div>
                    </div>

                    <!-- حسابات تلقائية -->
                    <div class="row" style="background:#f8fafc; border:1px solid #e2e8f0; padding:12px 6px; margin:0 0 16px;">
                        <div class="col-md-6">
                            <label class="form-label text-muted">إجمالي قيمة البضاعة بالمخزن (تلقائي)</label>
                            <input type="text" id="total_cost" class="form-control text-center font-weight-bold" style="background:#f0f9ff; color:#0369a1;" readonly value="0.00">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-muted">إجمالي الأرباح المتوقعة للكمية (تلقائي)</label>
                            <input type="text" id="expected_profit" class="form-control text-center font-weight-bold" style="background:#f0fdf4; color:#15803d;" readonly value="0.00">
                        </div>
                    </div>

                    <div class="alert rounded-0 mb-4" style="background:#eff6ff; border:1px solid #bfdbfe; border-right:4px solid #1d4ed8; font-size:0.8rem;">
                        <i class="bi bi-info-circle ml-2 text-primary"></i>
                        <strong>ملاحظة:</strong> لتهيئة وحدات القياس المتعددة (مثل الكرتون والدرزن)، قم بحفظ الصنف أولاً، ثم اذهب لتعديله لإضافة الوحدات ومعاملات التحويل.
                    </div>

                    <div class="d-flex justify-content-end">
                        <a href="index.php" class="btn btn-light text-decoration-none ml-2" style="font-size:0.85rem; border:1px solid #cbd5e1;">
                            <i class="bi bi-x ml-1"></i> إلغاء
                        </a>
                        <button type="submit" name="btn_save" class="btn btn-success" style="font-size:0.85rem; font-weight:700; padding:8px 24px;">
                            <i class="bi bi-check2-circle ml-1"></i> حفظ وإضافة الصنف
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
        totalCost.value = (qty * buy).toFixed(2);
        expProfit.value = ((sale - buy) * qty).toFixed(2);
    }

    const barcodeInput = document.getElementById("barcode");
    const generateBtn = document.getElementById("generateBarcode");
    if (generateBtn && barcodeInput) {
        generateBtn.addEventListener("click", function() {
            let randDigits = "";
            for (let i = 0; i < 10; i++) { randDigits += Math.floor(Math.random() * 10); }
            barcodeInput.value = "629" + randDigits;
        });
    }
    qtyInput.addEventListener("input", calculate);
    buyInput.addEventListener("input", calculate);
    saleInput.addEventListener("input", calculate);
});
</script>

<?php require_once($dir_prefix . 'includes/footer.php'); ?>
