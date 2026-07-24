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
                // تحديث اسم حبة إن تم إرساله
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

// جلب اسم حبة
$base_unit_name = 'حبة';
if ($details) {
    $res_u = $conn->query("SELECT unit_name FROM product_units WHERE product_id = $prod_id AND is_base_unit = 1 LIMIT 1");
    if ($res_u && $row_u = $res_u->fetch_assoc()) {
        $base_unit_name = $row_u['unit_name'];
    }
}
?>
<title>تعديل بيانات المنتج — AQNEX POS</title>

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
.unit-tag {
    background: #f0f9ff; border: 1px solid #bae6fd;
    padding: 6px 10px; font-size: 0.78rem; margin-bottom: 6px;
    display: flex; align-items: center; justify-content: space-between;
}
.base-unit-tag { background: #f0fdf4; border: 1px solid #bbf7d0; }
</style>

<!-- رأس الصفحة -->
<div class="page-title-bar no-print">
    <div class="ptb-left">
        <div class="icon-wrap"><i class="bi bi-pencil-square"></i></div>
        <div>
            <h4>تعديل بيانات المنتج — <?php echo htmlspecialchars($details['name'] ?? ''); ?></h4>
            <small>تحديث بيانات الصنف، أسعاره، وإدارة وحدات القياس المتعددة</small>
        </div>
    </div>
    <div class="ptb-actions">
        <a href="index.php" class="btn btn-sm btn-light text-decoration-none" style="font-size:0.8rem; border:1px solid #cbd5e1;" title="العودة لقائمة المنتجات">
            <i class="bi bi-arrow-left ml-1"></i> العودة للقائمة
        </a>
    </div>
</div>

<div class="row justify-content-center">
    <div class="col-md-10 col-lg-9">

        <!-- ===== نموذج التعديل الأساسي ===== -->
        <div class="card-flat mb-4">
            <div class="card-header">
                <h5><i class="bi bi-pencil ml-2 text-primary"></i>تعديل بيانات الصنف</h5>
            </div>
            <div class="card-body">
                <?php if (isset($error)): ?>
                <div class="alert alert-danger rounded-0 mb-3" style="font-size:0.82rem; border-right:4px solid #b91c1c;">
                    <i class="bi bi-exclamation-triangle ml-2"></i><?php echo $error; ?>
                </div>
                <?php endif; ?>

                <?php if ($details): ?>
                <form method="POST" id="productForm">
                    <!-- قسم: البيانات الأساسية -->
                    <div class="form-section-header">
                        <i class="bi bi-info-circle text-primary"></i> البيانات الأساسية
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">اسم المنتج *</label>
                            <input type="text" name="name" class="form-control" value="<?php echo htmlspecialchars($details['name']); ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">رمز الباركود</label>
                            <div class="input-group">
                                <input type="text" name="barcode" id="barcode" class="form-control" value="<?php echo htmlspecialchars($details['barcode']); ?>" placeholder="أدخل الباركود أو اتركه فارغاً">
                                <div class="input-group-append">
                                    <button type="button" id="generateBarcode" class="btn btn-secondary btn-sm" title="توليد باركود جديد">
                                        <i class="bi bi-upc-scan ml-1"></i> توليد
                                    </button>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">تصنيف المنتج *</label>
                            <select name="catid" class="form-control" required>
                                <option value="">-- اختر التصنيف --</option>
                                <?php
                                $sql_cat = "SELECT catid, name FROM categories WHERE d_s = 0 ORDER BY catid DESC";
                                $res_cat = $conn->query($sql_cat);
                                if ($res_cat) {
                                    while($row = $res_cat->fetch_assoc()) {
                                        $selected = ($row['catid'] == $details['catid']) ? 'selected' : '';
                                        echo "<option value='".$row['catid']."' $selected>" . htmlspecialchars($row['name']) . "</option>";
                                    }
                                }
                                ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">اسم حبة</label>
                            <input type="text" name="unit_name" class="form-control" value="<?php echo htmlspecialchars($base_unit_name); ?>" placeholder="حبة، كرتون، درزن...">
                            <div class="field-hint">يمكنك إضافة وحدات فرعية إضافية من قسم إدارة الوحدات أدناه</div>
                        </div>
                    </div>

                    <!-- قسم: المخزون والأسعار -->
                    <div class="form-section-header mt-2">
                        <i class="bi bi-graph-up text-success"></i> المخزون والأسعار
                    </div>
                    <div class="row">
                        <div class="col-md-3 mb-3">
                            <label class="form-label">الكمية المتوفرة</label>
                            <input type="number" name="quantity" id="quantity" class="form-control text-center font-weight-bold" value="<?php echo $details['quantity']; ?>" min="0" required>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">حد التنبيه الأدنى</label>
                            <input type="number" name="min_stock_alert" id="min_stock_alert" class="form-control text-center" value="<?php echo isset($details['min_stock_alert']) ? intval($details['min_stock_alert']) : 5; ?>" min="0" required>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">سعر الشراء الفردي (ر.ي)</label>
                            <input type="number" step="any" name="buy_price" id="buy_price" class="form-control text-center" value="<?php echo $details['buy_price']; ?>" required>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">سعر البيع الفردي (ر.ي)</label>
                            <input type="number" step="any" name="sale_price" id="sale_price" class="form-control text-center" value="<?php echo $details['sale_price']; ?>" required>
                        </div>
                    </div>

                    <!-- حسابات تلقائية -->
                    <div class="row" style="background:#f8fafc; border:1px solid #e2e8f0; padding:12px 6px; margin:0 0 16px;">
                        <div class="col-md-6">
                            <label class="form-label text-muted">إجمالي قيمة البضاعة بالمخزن (تلقائي)</label>
                            <input type="text" id="total_cost" name="too" class="form-control text-center font-weight-bold" style="background:#f0f9ff; color:#0369a1;" readonly value="<?php echo number_format($details['total'], 2); ?>">
                        </div>
                        <div class="col-md-6">
                            <?php $old_profit = ($details['sale_price'] - $details['buy_price']) * $details['quantity']; ?>
                            <label class="form-label text-muted">إجمالي الأرباح المتوقعة للكمية (تلقائي)</label>
                            <input type="text" id="expected_profit" class="form-control text-center font-weight-bold" style="background:#f0fdf4; color:#15803d;" readonly value="<?php echo number_format($old_profit, 2); ?>">
                        </div>
                    </div>

                    <div class="d-flex justify-content-end">
                        <a href="index.php" class="btn btn-light text-decoration-none ml-2" style="font-size:0.85rem; border:1px solid #cbd5e1;">
                            <i class="bi bi-x ml-1"></i> إلغاء
                        </a>
                        <button type="submit" name="btn_save" class="btn btn-primary" style="font-size:0.85rem; font-weight:700; padding:8px 24px;">
                            <i class="bi bi-check2-circle ml-1"></i> حفظ التعديلات
                        </button>
                    </div>
                </form>
                <?php else: ?>
                <div class="alert alert-danger text-center rounded-0">المنتج المطلوب غير موجود!</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ===== بطاقة إدارة وحدات القياس ===== -->
        <div class="card-flat no-print">
            <div class="card-header">
                <h5><i class="bi bi-tags ml-2 text-primary"></i>إدارة وحدات القياس المتعددة للصنف</h5>
            </div>
            <div class="card-body">
                <?php if (isset($unit_success)): ?>
                <div class="alert alert-success rounded-0 mb-3" style="font-size:0.82rem; border-right:4px solid #15803d;">
                    <i class="bi bi-check-circle ml-2"></i><?php echo $unit_success; ?>
                </div>
                <?php endif; ?>
                <?php if (isset($unit_error)): ?>
                <div class="alert alert-danger rounded-0 mb-3" style="font-size:0.82rem; border-right:4px solid #b91c1c;">
                    <i class="bi bi-exclamation-triangle ml-2"></i><?php echo $unit_error; ?>
                </div>
                <?php endif; ?>

                <!-- حبة -->
                <div class="form-section-header">
                    <i class="bi bi-list-ul text-success"></i> الوحدات المرتبطة بالصنف
                </div>
                <div class="table-responsive mb-4">
                    <table class="table-flat mb-0" style="font-size:0.82rem;">
                        <thead>
                            <tr>
                                <th>اسم الوحدة</th>
                                <th class="text-center">معامل التحويل</th>
                                <th class="text-center">سعر الشراء</th>
                                <th class="text-center">سعر البيع</th>
                                <th class="text-center" style="width:18%;">إجراء</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr style="background:#f0fdf4;">
                                <td class="font-weight-bold text-success"><i class="bi bi-check-circle ml-1"></i> حبة</td>
                                <td class="text-center font-weight-bold">1.0000</td>
                                <td class="text-center"><?php echo number_format($details['buy_price'], 2); ?> ر.ي</td>
                                <td class="text-center"><?php echo number_format($details['sale_price'], 2); ?> ر.ي</td>
                                <td class="text-center text-muted" style="font-size:0.7rem;">—</td>
                            </tr>
                            <?php
                            $res_pu = $conn->query("SELECT * FROM product_units WHERE product_id = $prod_id AND d_s = '0' ORDER BY conversion_factor ASC");
                            if ($res_pu && $res_pu->num_rows > 0) {
                                while ($pu = $res_pu->fetch_assoc()) {
                                    echo "<tr>
                                        <td class='font-weight-bold text-primary'>{$pu['unit_name']}</td>
                                        <td class='text-center'>" . number_format($pu['conversion_factor'], 4) . "</td>
                                        <td class='text-center'>" . number_format($pu['purchase_price'], 2) . " ر.ي</td>
                                        <td class='text-center'>" . number_format($pu['sale_price'], 2) . " ر.ي</td>
                                        <td class='text-center'>
                                            <a href='edit.php?id={$prod_id}&del_product_unit={$pu['id']}' onclick=\"return confirm('تأكيد إزالة ربط الوحدة ({$pu['unit_name']}) من الصنف؟')\" class='btn btn-danger btn-sm py-0 px-2 text-decoration-none' style='font-size:0.7rem;'>
                                                <i class='bi bi-trash ml-1'></i>حذف
                                            </a>
                                        </td>
                                    </tr>";
                                }
                            }
                            ?>
                        </tbody>
                    </table>
                </div>

                <!-- نموذج إضافة وحدة فرعية -->
                <div class="form-section-header">
                    <i class="bi bi-plus-circle text-primary"></i> ربط وحدة قياس فرعية جديدة
                </div>
                <form method="POST" action="edit.php?id=<?php echo $prod_id; ?>">
                    <div class="row">
                        <div class="col-md-3 mb-3">
                            <label class="form-label">اسم الوحدة *</label>
                            <select name="unit_name" class="form-control" required>
                                <option value="">-- اختر وحدة --</option>
                                <?php
                                $res_all_units = $conn->query("SELECT name FROM units WHERE d_s = '0' ORDER BY name ASC");
                                if ($res_all_units) {
                                    while ($unit_row = $res_all_units->fetch_assoc()) {
                                        echo "<option value='" . htmlspecialchars($unit_row['name']) . "'>" . htmlspecialchars($unit_row['name']) . "</option>";
                                    }
                                }
                                ?>
                            </select>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">معامل التحويل للأساسية *</label>
                            <input type="number" step="any" name="conversion_factor" id="pu_conv_factor" class="form-control text-center" placeholder="مثال: كرتون 12 حبة = 12" required oninput="autoCalcUnitPrices()">
                            <div class="field-hint">عدد الوحدات الأساسية بداخل هذه الوحدة</div>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">سعر شراء الوحدة (ر.ي) *</label>
                            <input type="number" step="any" name="purchase_price" id="pu_purchase_price" class="form-control text-center" required>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">سعر بيع الوحدة (ر.ي) *</label>
                            <input type="number" step="any" name="sale_price" id="pu_sale_price" class="form-control text-center" required>
                        </div>
                    </div>
                    <button type="submit" name="btn_add_product_unit" class="btn btn-success" style="font-size:0.85rem; font-weight:700; padding:7px 20px;">
                        <i class="bi bi-link-45deg ml-1"></i> ربط الوحدة بالصنف وحفظها
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