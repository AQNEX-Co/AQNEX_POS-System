<?php
$dir_prefix = '../';
$module = 'products';
require_once($dir_prefix . 'includes/header.php');

check_permission(['admin', 'inventory']);
if (isset($_POST['btn_save'])) {
    $name = $conn->real_escape_string($_POST['name']);
    $barcode = $conn->real_escape_string(trim($_POST['barcode']));
    $qty = intval($_POST['quantity']);
    $buy_price = doubleval($_POST['buy_price']);
    $sale_price = doubleval($_POST['sale_price']);
    $catid = intval($_POST['catid']);
    
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
            $error = "رمز الباركود هذا مسجل بالفعل لمنتج آخر!";
        }
    }
    
    if (!isset($error)) {
        // إجمالي قيمة المخزون لهذا الصنف
        $total_val = $qty * $buy_price;
        $today = date("Y-m-d H:i:s");
        
        if (!empty($name) && $catid > 0) {
            $sql = "INSERT INTO products(name, barcode, quantity, buy_price, sale_price, catid, total, date, delete_status) 
                    VALUES ('$name', '$barcode', '$qty', '$buy_price', '$sale_price', '$catid', '$total_val', '$today', 0)";
            if ($conn->query($sql)) {
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
                        <div class="col-md-12 mb-3">
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

                        <!-- الكمية -->
                        <div class="col-md-4 mb-3">
                            <label class="form-label font-weight-bold text-secondary">الكمية المتوفرة</label>
                            <input type="number" name="quantity" id="quantity" class="form-control rounded-0 text-center" value="0" min="0" required>
                        </div>

                        <!-- سعر الشراء -->
                        <div class="col-md-4 mb-3">
                            <label class="form-label font-weight-bold text-secondary">سعر الشراء الفردي</label>
                            <input type="number" step="any" name="buy_price" id="buy_price" class="form-control rounded-0 text-center" value="0" required>
                        </div>

                        <!-- سعر البيع -->
                        <div class="col-md-4 mb-3">
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
