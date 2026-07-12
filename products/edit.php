<?php
$dir_prefix = '../';
$module = 'products';
require_once($dir_prefix . 'includes/header.php');

check_permission(['admin', 'inventory']);
$prod_id = intval($_GET['id']);

// معالجة إضافة وحدة جديدة مرتبطة بالصنف
if (isset($_POST['btn_add_product_unit'])) {
    $unit_name = $conn->real_escape_string(trim($_POST['unit_name']));
    $conversion_factor = doubleval($_POST['conversion_factor']);
    $purchase_price = doubleval($_POST['purchase_price']);
    $sale_price = doubleval($_POST['sale_price']);

    if (empty($unit_name)) {
        $unit_error = 'الرجاء اختيار اسم الوحدة.';
    } elseif ($conversion_factor <= 0) {
        $unit_error = 'يجب أن يكون معامل التحويل أكبر من الصفر.';
    } else {
        // التحقق من تكرار الوحدة لنفس المنتج
        $chk = $conn->query("SELECT id FROM product_units WHERE product_id = $prod_id AND unit_name = '$unit_name' AND d_s = '0' LIMIT 1");
        if ($chk && $chk->num_rows > 0) {
            $unit_error = 'هذه الوحدة مرتبطة بالمنتج مسبقاً.';
        } else {
            $stmt = $conn->prepare("INSERT INTO `product_units` (`product_id`, `unit_name`, `conversion_factor`, `purchase_price`, `sale_price`, `is_base_unit`, `d_s`) VALUES (?, ?, ?, ?, ?, 0, '0')");
            $stmt->bind_param("isddd", $prod_id, $unit_name, $conversion_factor, $purchase_price, $sale_price);
            if ($stmt->execute()) {
                $conn->query("UPDATE `products` SET `has_multiple_units` = 1 WHERE `id` = $prod_id");
                $unit_success = 'تم ربط وحدة القياس بالمنتج بنجاح.';
            } else {
                $unit_error = 'فشل ربط الوحدة بالمنتج: ' . $conn->error;
            }
            $stmt->close();
        }
    }
}

// معالجة حذف ربط وحدة بالصنف
if (isset($_GET['del_product_unit']) && is_numeric($_GET['del_product_unit'])) {
    $del_pu_id = intval($_GET['del_product_unit']);
    if ($conn->query("UPDATE `product_units` SET `d_s` = '1' WHERE `id` = $del_pu_id AND `product_id` = $prod_id")) {
        $unit_success = 'تم حذف ربط وحدة القياس بنجاح.';
        // التحقق مما إذا كان هناك وحدات نشطة متبقية للمنتج
        $check = $conn->query("SELECT id FROM `product_units` WHERE `product_id` = $prod_id AND `d_s` = '0'");
        if ($check && $check->num_rows == 0) {
            $conn->query("UPDATE `products` SET `has_multiple_units` = 0 WHERE `id` = $prod_id");
        }
    } else {
        $unit_error = 'فشل حذف ربط الوحدة: ' . $conn->error;
    }
}

if (isset($_POST['btn_save'])) {
    $name = $conn->real_escape_string($_POST['name']);
    $barcode = $conn->real_escape_string(trim($_POST['barcode']));
    $qty = intval($_POST['quantity']);
    $min_stock_alert = doubleval($_POST['min_stock_alert'] ?? 0);
    $buy_price = doubleval($_POST['buy_price']);
    $sale_price = doubleval($_POST['sale_price']);
    $catid = intval($_POST['catid']);
    $sector_id = isset($_POST['sector_id']) && $_POST['sector_id'] !== '' ? intval($_POST['sector_id']) : null;
    
    // التحقق من تكرار الباركود لمنتج آخر
    if (!empty($barcode)) {
        $chk_dup = $conn->query("SELECT id FROM products WHERE barcode = '$barcode' AND id != '$prod_id' AND delete_status = 0");
        if ($chk_dup && $chk_dup->num_rows > 0) {
            $error = "رمز الباركود this register already to another product!";
        }
    }
    
    if (!isset($error)) {
        $total_val = $qty * $buy_price;
        
        if (!empty($name) && $catid > 0) {
            $sql = "UPDATE products SET name='$name', barcode='$barcode', quantity='$qty', min_stock_alert='$min_stock_alert', buy_price='$buy_price', sale_price='$sale_price', catid='$catid', total='$total_val' WHERE id='$prod_id'";
            if ($conn->query($sql)) {
                // تحديث اسم الوحدة الأساسية إن تم إرساله
                if (!empty($_POST['unit_name'])) {
                    $new_unit_name = $conn->real_escape_string(trim($_POST['unit_name']));
                    $chk_bu = $conn->query("SELECT id FROM product_units WHERE product_id = $prod_id AND is_base_unit = 1 LIMIT 1");
                    if ($chk_bu && $chk_bu->num_rows > 0) {
                        $bu_row = $chk_bu->fetch_assoc();
                        $conn->query("UPDATE product_units SET unit_name = '$new_unit_name', sale_price = $sale_price, purchase_price = $buy_price WHERE id = {$bu_row['id']}");
                    } else {
                        // إنشاء وحدة أساسية إذا لم تكن موجودة
                        $conn->query("INSERT INTO product_units (product_id, unit_name, conversion_factor, sale_price, purchase_price, is_base_unit) VALUES ($prod_id, '$new_unit_name', 1.0000, $sale_price, $buy_price, 1)");
                    }
                }
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

// جلب اسم الوحدة الأساسية
$base_unit_name = 'حبة';
if ($details) {
    $res_u = $conn->query("SELECT unit_name FROM product_units WHERE product_id = $prod_id AND is_base_unit = 1 LIMIT 1");
    if ($res_u && $row_u = $res_u->fetch_assoc()) {
        $base_unit_name = $row_u['unit_name'];
    }
}
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
                        <div class="col-md-6 mb-3">
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

                        <!-- الوحدة الأساسية -->
                        <div class="col-md-6 mb-3">
                            <label class="form-label font-weight-bold text-secondary">الوحدة الأساسية (مثال: حبة، كرتون)</label>
                            <input type="text" name="unit_name" class="form-control rounded-0" value="<?php echo htmlspecialchars($base_unit_name); ?>" placeholder="حبة، كرتون، درزن...">
                            <small class="text-muted">يمكنك إضافة وحدات إضافية (كرتون، درزن...) من قسم الوحدات أدناه بعد الحفظ.</small>
                        </div>

                        <!-- الكمية -->
                        <div class="col-md-3 mb-3">
                            <label class="form-label font-weight-bold text-secondary">الكمية المتوفرة</label>
                            <input type="number" name="quantity" id="quantity" class="form-control rounded-0 text-center" value="<?php echo $details['quantity']; ?>" min="0" required>
                        </div>

                        <!-- حد تنبيه المخزون -->
                        <div class="col-md-3 mb-3">
                            <label class="form-label font-weight-bold text-secondary">حد التنبيه الأدنى</label>
                            <input type="number" name="min_stock_alert" id="min_stock_alert" class="form-control rounded-0 text-center" value="<?php echo isset($details['min_stock_alert']) ? intval($details['min_stock_alert']) : 5; ?>" min="0" required>
                        </div>

                        <!-- سعر الشراء -->
                        <div class="col-md-3 mb-3">
                            <label class="form-label font-weight-bold text-secondary">سعر الشراء الفردي</label>
                            <input type="number" step="any" name="buy_price" id="buy_price" class="form-control rounded-0 text-center" value="<?php echo $details['buy_price']; ?>" required>
                        </div>

                        <!-- سعر البيع -->
                        <div class="col-md-3 mb-3">
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

        <!-- بطاقة تهيئة وحدات قياس المنتج -->
        <div class="card-flat mt-4 text-right no-print" dir="rtl">
            <div class="card-header bg-light">
                <h5 class="mb-0 text-dark font-weight-bold"><i class="fa fa-tags ml-2 text-primary"></i> تهيئة وحدات القياس المتعددة للصنف</h5>
            </div>
            <div class="card-body">
                <?php if (isset($unit_success)): ?>
                    <div class="alert alert-success rounded-0 mb-3"><?php echo $unit_success; ?></div>
                <?php endif; ?>
                <?php if (isset($unit_error)): ?>
                    <div class="alert alert-danger rounded-0 mb-3"><?php echo $unit_error; ?></div>
                <?php endif; ?>

                <!-- عرض الوحدات الحالية -->
                <div class="table-responsive mb-4">
                    <table class="table-flat border mb-0">
                        <thead>
                            <tr>
                                <th>اسم الوحدة</th>
                                <th class="text-center">معامل التحويل (للأساسية)</th>
                                <th>سعر الشراء للوحدة</th>
                                <th>سعر البيع للوحدة</th>
                                <th class="text-center" style="width: 20%;">إجراء</th>
                            </tr>
                        </thead>
                        <tbody>
                            <!-- الوحدة الأساسية الافتراضية -->
                            <tr style="background-color: #f8fafc;">
                                <td class="font-weight-bold text-secondary">الوحدة الأساسية (حبة/قطعة)</td>
                                <td class="text-center font-weight-bold text-dark">1.0000</td>
                                <td class="font-weight-bold"><?php echo number_format($details['buy_price'], 2); ?> YER</td>
                                <td class="font-weight-bold"><?php echo number_format($details['sale_price'], 2); ?> YER</td>
                                <td class="text-center text-muted small">-</td>
                            </tr>
                            <?php
                            $res_pu = $conn->query("SELECT * FROM product_units WHERE product_id = $prod_id AND d_s = '0' ORDER BY conversion_factor ASC");
                            if ($res_pu && $res_pu->num_rows > 0) {
                                while ($pu = $res_pu->fetch_assoc()) {
                                    echo "<tr>
                                        <td class='font-weight-bold text-primary'>{$pu['unit_name']}</td>
                                        <td class='text-center font-weight-bold'>".number_format($pu['conversion_factor'], 4)."</td>
                                        <td class='font-weight-bold'>".number_format($pu['purchase_price'], 2)." ر.ي</td>
                                        <td class='font-weight-bold'>".number_format($pu['sale_price'], 2)." ر.ي</td>
                                        <td class='text-center'>
                                            <a href='edit.php?id={$prod_id}&del_product_unit={$pu['id']}' onclick=\"return confirm('هل تريد إزالة ربط هذه الوحدة بالصنف؟')\" class='btn-flat btn-flat-danger btn-sm py-1 px-3 text-decoration-none'>
                                                <i class='fa fa-times ml-1'></i> حذف
                                            </a>
                                        </td>
                                    </tr>";
                                }
                            }
                            ?>
                        </tbody>
                    </table>
                </div>

                <!-- نموذج إضافة وحدة جديدة للصنف -->
                <form method="POST" action="edit.php?id=<?php echo $prod_id; ?>">
                    <h6 class="font-weight-bold text-secondary border-bottom pb-2 mb-3"><i class="fa fa-plus-circle ml-1"></i> ربط صنف بوحدة فرعية جديدة</h6>
                    <div class="row">
                        <!-- اختيار اسم الوحدة من الوحدات العامة -->
                        <div class="col-md-3 mb-3">
                            <label class="form-label font-weight-bold text-secondary small">اسم الوحدة *</label>
                            <select name="unit_name" class="form-control rounded-0 font-weight-bold" required>
                                <option value="">-- اختر وحدة --</option>
                                <?php
                                $res_all_units = $conn->query("SELECT name FROM units WHERE d_s = '0' ORDER BY name ASC");
                                if ($res_all_units) {
                                    while ($unit_row = $res_all_units->fetch_assoc()) {
                                        echo "<option value='".htmlspecialchars($unit_row['name'])."'>".htmlspecialchars($unit_row['name'])."</option>";
                                    }
                                }
                                ?>
                            </select>
                        </div>

                        <!-- معامل التحويل -->
                        <div class="col-md-3 mb-3">
                            <label class="form-label font-weight-bold text-secondary small">معامل التحويل للوحدة الأساسية *</label>
                            <input type="number" step="any" name="conversion_factor" id="pu_conv_factor" class="form-control rounded-0 text-center font-weight-bold" placeholder="مثال: كرتون 12 حبة = 12" required oninput="autoCalcUnitPrices()">
                        </div>

                        <!-- سعر شراء الوحدة -->
                        <div class="col-md-3 mb-3">
                            <label class="form-label font-weight-bold text-secondary small">سعر شراء الوحدة *</label>
                            <input type="number" step="any" name="purchase_price" id="pu_purchase_price" class="form-control rounded-0 text-center font-weight-bold" required>
                        </div>

                        <!-- سعر بيع الوحدة -->
                        <div class="col-md-3 mb-3">
                            <label class="form-label font-weight-bold text-secondary small">سعر بيع الوحدة *</label>
                            <input type="number" step="any" name="sale_price" id="pu_sale_price" class="form-control rounded-0 text-center font-weight-bold" required>
                        </div>
                    </div>

                    <button type="submit" name="btn_add_product_unit" class="btn-flat btn-flat-success px-4 py-2 font-weight-bold">
                        <i class="fa fa-link ml-1"></i> ربط الوحدة بالصنف وحفظها
                    </button>
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

function autoCalcUnitPrices() {
    const factor = parseFloat(document.getElementById('pu_conv_factor').value) || 0;
    const baseBuy = parseFloat(document.getElementById('buy_price').value) || 0;
    const baseSale = parseFloat(document.getElementById('sale_price').value) || 0;
    
    if (factor > 0) {
        document.getElementById('pu_purchase_price').value = (baseBuy * factor).toFixed(2);
        document.getElementById('pu_sale_price').value = (baseSale * factor).toFixed(2);
    } else {
        document.getElementById('pu_purchase_price').value = '';
        document.getElementById('pu_sale_price').value = '';
    }
}
</script>

<?php
require_once($dir_prefix . 'includes/footer.php');
?>
