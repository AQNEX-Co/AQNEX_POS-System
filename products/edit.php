<?php
$dir_prefix = '../';
$module = 'products';
require_once($dir_prefix . 'includes/header.php');

check_permission(['admin', 'inventory']);
$prod_id = intval($_GET['id']);

if (isset($_POST['btn_save'])) {
    $name = $conn->real_escape_string($_POST['name']);
    $barcode = $conn->real_escape_string(trim($_POST['barcode']));
    $qty = intval($_POST['quantity']);
    $buy_price = doubleval($_POST['buy_price']);
    $sale_price = doubleval($_POST['sale_price']);
    $catid = intval($_POST['catid']);
    
    // التحقق من تكرار الباركود لمنتج آخر
    if (!empty($barcode)) {
        $chk_dup = $conn->query("SELECT id FROM products WHERE barcode = '$barcode' AND id != '$prod_id' AND delete_status = 0");
        if ($chk_dup && $chk_dup->num_rows > 0) {
            $error = "رمز الباركود هذا مسجل بالفعل لمنتج آخر!";
        }
    }
    
    if (!isset($error)) {
        $total_val = $qty * $buy_price;
        
        if (!empty($name) && $catid > 0) {
            $sql = "UPDATE products SET name='$name', barcode='$barcode', quantity='$qty', buy_price='$buy_price', sale_price='$sale_price', catid='$catid', total='$total_val' WHERE id='$prod_id'";
            if ($conn->query($sql)) {
                echo "<script>window.location='index.php';</script>";
                exit;
            } else {
                $error = "حدث خطأ أثناء حفظ التعديل: " . $conn->error;
            }
        }
    }
}

// جلب التفاصيل الحالية
$sql_details = "SELECT * FROM products WHERE id = $prod_id";
$res_details = $conn->query($sql_details);
$details = ($res_details) ? $res_details->fetch_assoc() : null;
?>
<title>تعديل بيانات المنتج - تكنولوجيا فون</title>

<div class="row no-print mb-4">
    <div class="col-md-6">
        <h3 class="text-secondary font-weight-bold">
            <i class="fa fa-edit ml-2"></i>تعديل بيانات المنتج
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
                <h5>بيانات المنتج</h5>
            </div>
            <div class="card-body">
                <?php if (isset($error)): ?>
                    <div class="alert alert-danger rounded-0 mb-3"><?php echo $error; ?></div>
                <?php endif; ?>

                <?php if ($details): ?>
                <form method="POST" id="productForm">
                    <div class="row">
                        <!-- اسم المنتج -->
                        <div class="col-md-6 mb-3">
                            <label class="form-label font-weight-bold text-secondary">اسم المنتج *</label>
                            <input type="text" name="name" class="form-control rounded-0" value="<?php echo htmlspecialchars($details['name']); ?>" required>
                        </div>

                        <!-- الباركود -->
                        <div class="col-md-6 mb-3">
                            <label class="form-label font-weight-bold text-secondary">رمز الباركود (اختياري)</label>
                            <div class="input-group">
                                <input type="text" name="barcode" id="barcode" class="form-control rounded-0" value="<?php echo htmlspecialchars($details['barcode']); ?>" placeholder="أدخل رمز الباركود أو اتركه فارغاً">
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
                                        $selected = ($row['catid'] == $details['catid']) ? 'selected' : '';
                                        echo "<option value='".$row['catid']."' $selected>".htmlspecialchars($row['name'])."</option>";
                                    }
                                }
                                ?>
                            </select>
                        </div>

                        <!-- الكمية -->
                        <div class="col-md-4 mb-3">
                            <label class="form-label font-weight-bold text-secondary">الكمية المتوفرة</label>
                            <input type="number" name="quantity" id="quantity" class="form-control rounded-0 text-center" value="<?php echo $details['quantity']; ?>" min="0" required>
                        </div>

                        <!-- سعر الشراء -->
                        <div class="col-md-4 mb-3">
                            <label class="form-label font-weight-bold text-secondary">سعر الشراء الفردي</label>
                            <input type="number" step="any" name="buy_price" id="buy_price" class="form-control rounded-0 text-center" value="<?php echo $details['buy_price']; ?>" required>
                        </div>

                        <!-- سعر البيع -->
                        <div class="col-md-4 mb-3">
                            <label class="form-label font-weight-bold text-secondary">سعر البيع الفردي</label>
                            <input type="number" step="any" name="sale_price" id="sale_price" class="form-control rounded-0 text-center" value="<?php echo $details['sale_price']; ?>" required>
                        </div>

                        <!-- حسابات تلقائية -->
                        <div class="col-md-6 mb-3">
                            <label class="form-label font-weight-bold text-muted">إجمالي قيمة البضاعة بالمخزن (تلقائي)</label>
                            <input type="text" id="total_cost" name="too" class="form-control rounded-0 text-center font-weight-bold bg-light" readonly value="<?php echo number_format($details['total'], 2); ?>">
                        </div>

                        <div class="col-md-6 mb-3">
                            <label class="form-label font-weight-bold text-muted">إجمالي الأرباح المتوقعة للكمية (تلقائي)</label>
                            <?php 
                            $old_profit = ($details['sale_price'] - $details['buy_price']) * $details['quantity']; 
                            ?>
                            <input type="text" id="expected_profit" class="form-control rounded-0 text-center font-weight-bold bg-light text-success" readonly value="<?php echo number_format($old_profit, 2); ?>">
                        </div>
                    </div>

                    <div class="mt-4 text-left">
                        <button type="submit" name="btn_save" class="btn-flat btn-flat-primary px-5">
                            <i class="fa fa-save ml-1"></i> حفظ التعديل
                        </button>
                    </div>
                </form>
                <?php else: ?>
                <div class="alert alert-danger text-center rounded-0">المنتج المطلوب غير موجود!</div>
                <?php endif; ?>
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
