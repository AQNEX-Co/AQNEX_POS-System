<?php
$dir_prefix = '../';
$module = 'purchases';
require_once($dir_prefix . 'includes/header.php');

check_permission(['admin', 'inventory']);

$error = '';
$success = '';
$import_results = [];

// جلب الموردين المتاحين
$res_supp = $conn->query("SELECT supp_name FROM suppliers WHERE d_s = 0 ORDER BY supp_id ASC");
$suppliers_list = [];
if ($res_supp) {
    while($s = $res_supp->fetch_assoc()) {
        $suppliers_list[] = $s['supp_name'];
    }
}

// معالجة الاستيراد من ملف CSV
if (isset($_POST['btn_import']) && isset($_FILES['import_file']) && $_FILES['import_file']['error'] === 0) {
    $supplier_override = $conn->real_escape_string(trim($_POST['supplier_override']));
    $currency_code = $conn->real_escape_string(trim($_POST['currency_code']));
    $exchange_rate = doubleval($_POST['exchange_rate']);
    if ($exchange_rate <= 0) $exchange_rate = 1.0;
    
    $file_tmp = $_FILES['import_file']['tmp_name'];
    $file_ext = strtolower(pathinfo($_FILES['import_file']['name'], PATHINFO_EXTENSION));
    
    if (!in_array($file_ext, ['csv', 'txt'])) {
        $error = 'الملف المرفق غير مدعوم. يرجى تحميل ملف CSV فقط.';
    } else {
        $handle = fopen($file_tmp, 'r');
        if ($handle === false) {
            $error = 'فشل قراءة الملف المرفوع.';
        } else {
            // تخطي الصف الأول (الترويسة)
            $header = fgetcsv($handle, 1000, ',');
            
            $build_date = date('Y-m-d');
            $supp_name_for_invoice = $supplier_override ? $supplier_override : 'مورد عام';
            $supp_esc = $conn->real_escape_string($supp_name_for_invoice);
            
            $total_val_base = 0;
            $row_num = 1;
            $items_processed = [];
            
            while (($row = fgetcsv($handle, 1000, ',')) !== false) {
                $row_num++;
                
                // هيكل الأعمدة المتوقع:
                // 0: اسم_المنتج, 1: الكمية, 2: سعر_الشراء_الفردي, 3: المبلغ_المدفوع
                if (count($row) < 3) {
                    $import_results[] = ['row' => $row_num, 'status' => 'skip', 'msg' => 'صف ناقص أو فارغ - تخطي'];
                    continue;
                }
                
                $p_name = $conn->real_escape_string(trim($row[0]));
                $qty = intval($row[1]);
                $unit_price = doubleval($row[2]);
                $paid = isset($row[3]) ? doubleval($row[3]) : 0;
                
                if (empty($p_name) || $qty <= 0 || $unit_price <= 0) {
                    $import_results[] = ['row' => $row_num, 'status' => 'skip', 'msg' => "تخطي: بيانات منتج '{$p_name}' غير صالحة (الكمية أو السعر صفر)"];
                    continue;
                }
                
                $line_total = $qty * $unit_price;
                $remaining = $line_total - $paid;
                
                // تحويل للعملة الأساسية (YER)
                $line_total_base = $line_total * $exchange_rate;
                $paid_base = $paid * $exchange_rate;
                $rem_base = $remaining * $exchange_rate;
                $unit_buy_base = $unit_price * $exchange_rate;
                
                $total_val_base += $line_total_base;
                
                $items_processed[] = [
                    'name' => $p_name,
                    'qty' => $qty,
                    'unit_price' => $unit_price,
                    'line_total_base' => $line_total_base,
                    'paid_base' => $paid_base,
                    'rem_base' => $rem_base,
                    'unit_buy_base' => $unit_buy_base
                ];
                
                $import_results[] = ['row' => $row_num, 'status' => 'ok', 'msg' => "✓ {$p_name}: كمية {$qty}، وحدة ".number_format($unit_price,2)." {$currency_code}"];
            }
            fclose($handle);
            
            if (!empty($items_processed)) {
                // جلب معرف الصندوق الرئيسي ديناميكياً لتفادي تعارض الـ ID
                $res_main = $conn->query("SELECT box_id FROM treasury WHERE name = 'الصندوق الرئيسي' LIMIT 1");
                $main_box_id = ($res_main && $res_main->num_rows > 0) ? intval($res_main->fetch_assoc()['box_id']) : 2;
                $selected_box_id = isset($_POST['box_id']) ? intval($_POST['box_id']) : $main_box_id;
                $pay_from_box = isset($_POST['pay_from_box']) && intval($_POST['pay_from_box']) === 1;
                $box_name = get_box_name($conn, $selected_box_id);
                $total_remaining_base = 0;
                $total_paid_base = 0;
                foreach ($items_processed as $item) {
                    $total_paid_base += doubleval($item['paid_base']);
                    $total_remaining_base += doubleval($item['rem_base']);
                }

                // إدراج الفاتورة الرئيسية
                $sql_inv = "INSERT INTO `purchases`(`date`, `supp_name`, `total`, `remark`, `currency_code`, `exchange_rate`, `box_id`, `remaining_total`) 
                            VALUES ('$build_date', '$supp_esc', '$total_val_base', 'استيراد من ملف CSV', '$currency_code', '$exchange_rate', $selected_box_id, '$total_remaining_base')";
                if ($conn->query($sql_inv)) {
                    $billing_id = $conn->insert_id;
                    $total_remaining_base = 0;
                    $total_paid_base = 0;
                    
                    foreach ($items_processed as $item) {
                        $nm = $item['name'];
                        $qty = $item['qty'];
                        $l_base = $item['line_total_base'];
                        $p_base = $item['paid_base'];
                        $r_base = $item['rem_base'];
                        $ub = $item['unit_buy_base'];
                        
                        $total_paid_base += $p_base;
                        $total_remaining_base += $r_base;
                        
                        // 1. التحقق من وجود المنتج أو إنشائه تلقائياً
                        $nm_esc = $conn->real_escape_string($nm);
                        $chk = $conn->query("SELECT id FROM products WHERE name = '$nm_esc' AND delete_status = 0 LIMIT 1");
                        if ($chk && $chk->num_rows > 0) {
                            $p_id = intval($chk->fetch_assoc()['id']);
                            // تحديث المنتج الحالي
                            $conn->query("UPDATE `products` SET `quantity` = `quantity` + $qty, `buy_price` = $ub, `total` = `quantity` * `buy_price` WHERE `id` = $p_id");
                        } else {
                            // إنشاء منتج جديد
                            $res_cat = $conn->query("SELECT catid FROM categories WHERE d_s = 0 LIMIT 1");
                            $cat_id = ($res_cat && $res_cat->num_rows > 0) ? intval($res_cat->fetch_assoc()['catid']) : 0;
                            if ($cat_id <= 0) {
                                $conn->query("INSERT INTO categories (name, d_s) VALUES ('عام', 0)");
                                $cat_id = $conn->insert_id;
                            }
                            $sale_price_val = $ub * 1.25; // هامش 25%
                            $conn->query("INSERT INTO products (name, quantity, buy_price, sale_price, catid, date, delete_status) 
                                          VALUES ('$nm_esc', $qty, $ub, $sale_price_val, $cat_id, NOW(), 0)");
                            $p_id = $conn->insert_id;
                        }

                        // 2. إدراج بند الشراء (مع ربطه بالفاتورة الأصلية عبر purchase_id)
                        $supplier_id = 0;
                        $supplier_res = $conn->query("SELECT supp_id FROM suppliers WHERE supp_name = '$supp_esc' LIMIT 1");
                        if ($supplier_res && $supplier_res->num_rows > 0) {
                            $supplier_id = intval($supplier_res->fetch_assoc()['supp_id']);
                        }
                        $conn->query("INSERT INTO `purchase_items`(`purchase_id`, `buys_date`, `supp_name`, `supp_id`, `name`, `quantity`, `buy_price`, `pushtosupp`, `total_d`, `s`) 
                                      VALUES ($billing_id, '$build_date', '$supp_esc', $supplier_id, '$nm_esc', '$qty', '$l_base', '$p_base', '$r_base', 0)");
                        
                        // 3. سجل المخازن
                        $user_name = $_SESSION['SESS_FIRST_NAME'];
                        $conn->query("INSERT INTO `inventory_log`(`product_id`, `product_name`, `type`, `qty_change`, `new_qty`, `reason`, `user`)
                                      VALUES ($p_id, '$nm_esc', 'purchase', $qty, (SELECT quantity FROM products WHERE id = $p_id), 'استيراد CSV فاتورة #$billing_id', '$user_name')");
                    }
                    
                    // تحديث مديونية المورد
                    if ($total_remaining_base > 0 && $supp_esc !== 'مورد عام') {
                        $conn->query("UPDATE `suppliers` SET `supp_daain` = `supp_daain` + $total_remaining_base WHERE `supp_name` = '$supp_esc'");
                    }
                    
                    // تحديث الخزينة والصندوق المالي (إذا تم التمكين)
                    if ($total_paid_base > 0 && $pay_from_box) {
                        update_box_balance($conn, $selected_box_id, $total_paid_base, 'discount', "استيراد مشتريات CSV فاتورة #$billing_id", $build_date);
                    }
                    
                    // إثبات القيود اليومية المزدوجة الدقيقة
                    if ($total_paid_base > 0) {
                        $credit_acc = $pay_from_box ? ('الصندوق - ' . $box_name) : 'رأس المال / دفع خارجي';
                        $journal_box_id = $pay_from_box ? $selected_box_id : null;
                        post_journal_entry($conn, 'purchase', $billing_id, 'المخزون / البضاعة', $credit_acc, $total_paid_base, "شراء بضاعة استيراد CSV (نقداً) فاتورة رقم #$billing_id", $_SESSION['SESS_FIRST_NAME'], $journal_box_id);
                    }
                    if ($total_remaining_base > 0) {
                        post_journal_entry($conn, 'purchase', $billing_id, 'المخزون / البضاعة', 'الذمم الدائنة - ' . $supp_name_for_invoice, $total_remaining_base, "شراء بضاعة استيراد CSV (آجل) فاتورة رقم #$billing_id", $_SESSION['SESS_FIRST_NAME'], $selected_box_id);
                    }
                    
                    $success = "تم استيراد " . count($items_processed) . " صنف بنجاح في فاتورة رقم #$billing_id";
                } else {
                    $error = 'فشل إنشاء الفاتورة في قاعدة البيانات: ' . $conn->error;
                }
            } else {
                $error = 'لم يتم العثور على أي صفوف صالحة في الملف للاستيراد.';
            }
        }
    }
}
?>
<title>استيراد المشتريات من إكسل/CSV - تكنولوجيا فون</title>

<div class="row mb-4">
    <div class="col-md-6">
        <h3 class="text-secondary font-weight-bold">
            <?php echo get_icon('import', 'ml-2 text-primary'); ?> استيراد فاتورة مشتريات من إكسل
        </h3>
        <p class="text-muted small mb-0">استيراد بيانات الشراء من ملف CSV لتعبئة الفاتورة وتحديث المخزن تلقائياً</p>
    </div>
    <div class="col-md-6 text-left">
        <a href="index.php" class="btn-flat btn-flat-secondary btn-sm text-decoration-none">
            <?php echo get_icon('logout', 'ml-1'); ?> عودة للمشتريات
        </a>
    </div>
</div>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger rounded-0 mb-4"><?php echo $error; ?></div>
<?php endif; ?>
<?php if (!empty($success)): ?>
    <div class="alert alert-success rounded-0 mb-4">
        <?php echo get_icon('check', 'ml-1'); ?> <?php echo $success; ?>
        <a href="index.php" class="ml-3 text-success font-weight-bold">عرض قائمة المشتريات &larr;</a>
    </div>
<?php endif; ?>

<div class="row">
    <!-- نموذج الاستيراد -->
    <div class="col-md-6 mb-4">
        <div class="card-flat">
            <div class="card-header">
                <h5><?php echo get_icon('import', 'ml-1'); ?> رفع ملف الاستيراد</h5>
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <a href="../files/purchases_template.csv" class="btn btn-outline-info rounded-0 btn-sm text-decoration-none" download>
                        <i class="fa fa-download ml-1"></i> تحميل نموذج ملف استيراد الفاتورة (CSV)
                    </a>
                </div>
                <form method="POST" enctype="multipart/form-data">
                    <div class="form-group mb-3">
                        <label class="font-weight-bold text-secondary mb-2">ملف CSV للاستيراد *</label>
                        <input type="file" name="import_file" class="form-control rounded-0" accept=".csv,.txt" required>
                        <small class="text-muted">الملف بصيغة CSV. الأعمدة: اسم_المنتج، الكمية، سعر_الشراء_الفردي، المبلغ_المدفوع</small>
                    </div>
                    <div class="form-group mb-3">
                        <label class="font-weight-bold text-secondary mb-2">تعيين المورد (اختياري)</label>
                        <select name="supplier_override" class="form-control rounded-0">
                            <option value="">-- مورد عام --</option>
                            <?php foreach($suppliers_list as $s): ?>
                                <option value="<?php echo htmlspecialchars($s); ?>"><?php echo htmlspecialchars($s); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group mb-3">
                        <label class="font-weight-bold text-secondary mb-2">طريقة دفع الفاتورة المستوردة</label>
                        <div class="form-check mb-1">
                            <input type="checkbox" name="pay_from_box" id="payFromBoxImport" class="form-check-input" value="1" checked onchange="toggleBoxSelectImport(this)">
                            <label class="form-check-label small font-weight-bold text-dark" for="payFromBoxImport">خصم المدفوع النقدي من الصندوق</label>
                        </div>
                    </div>
                    <div class="form-group mb-3" id="boxSelectContainer">
                        <label class="font-weight-bold text-secondary mb-2">الصندوق المالي للخصم</label>
                        <select name="box_id" id="boxIdImport" class="form-control rounded-0">
                            <?php
                            $res_b = $conn->query("SELECT box_id, name, mony FROM treasury WHERE is_active = 1 ORDER BY box_id ASC");
                            if ($res_b) {
                                while($b = $res_b->fetch_assoc()) {
                                    echo "<option value='{$b['box_id']}' " . ($b['name'] === 'الصندوق الرئيسي' ? 'selected' : '') . ">" . htmlspecialchars($b['name']) . " (" . number_format($b['mony'], 2) . " ر.ي)</option>";
                                }
                            }
                            ?>
                        </select>
                    </div>
                    <div class="row">
                        <div class="col-md-6 form-group mb-3">
                            <label class="font-weight-bold text-secondary mb-2">عملة الملف</label>
                            <select name="currency_code" id="csvCurrencySelect" class="form-control rounded-0">
                                <?php
                                $res_curr2 = $conn->query("SELECT * FROM currencies ORDER BY id ASC");
                                if ($res_curr2) {
                                    while($c = $res_curr2->fetch_assoc()) {
                                        $sel = ($c['code'] === 'YER') ? 'selected' : '';
                                        echo "<option value='".htmlspecialchars($c['code'])."' data-rate='".$c['exchange_rate']."' $sel>".htmlspecialchars($c['name'])." (".htmlspecialchars($c['symbol']).")</option>";
                                    }
                                }
                                ?>
                            </select>
                        </div>
                        <div class="col-md-6 form-group mb-3">
                            <label class="font-weight-bold text-secondary mb-2">سعر الصرف (YER)</label>
                            <input type="number" step="any" name="exchange_rate" id="csvExchangeRate" class="form-control rounded-0 font-weight-bold text-center bg-light" value="1.0" readonly>
                        </div>
                    </div>
                    <button type="submit" name="btn_import" class="btn-flat btn-flat-primary btn-block px-5 mt-3">
                        <?php echo get_icon('import', 'ml-1'); ?> تنفيذ الاستيراد
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- الهيكل المتوقع وتحميل نموذج -->
    <div class="col-md-6 mb-4">
        <div class="card-flat">
            <div class="card-header">
                <h5><?php echo get_icon('reports', 'ml-1'); ?> هيكل ملف الاستيراد المعتمد</h5>
            </div>
            <div class="card-body">
                <p class="text-muted small mb-3">يجب أن يحتوي ملف CSV على الأعمدة التالية بالترتيب التام (الصف الأول ترويسة يتم تجاهله):</p>
                <div class="table-responsive mb-3">
                    <table class="table-flat border">
                        <thead>
                            <tr>
                                <th>العمود A</th>
                                <th>العمود B</th>
                                <th>العمود C</th>
                                <th>العمود D</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr class="table-secondary">
                                <td class="font-weight-bold text-secondary">اسم_المنتج</td>
                                <td class="font-weight-bold text-secondary">الكمية</td>
                                <td class="font-weight-bold text-secondary">سعر_الشراء_الفردي</td>
                                <td class="font-weight-bold text-secondary">المبلغ_المدفوع</td>
                            </tr>
                            <tr class="text-muted">
                                <td>سماعة ابل اير بودز</td>
                                <td>5</td>
                                <td>12500</td>
                                <td>30000</td>
                            </tr>
                            <tr class="text-muted">
                                <td>بطارية lg استايل</td>
                                <td>10</td>
                                <td>3500</td>
                                <td>25000</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="alert alert-warning rounded-0 p-3 small mb-3">
                    <strong>ملاحظة هامة:</strong> يجب أن يكون اسم المنتج في الملف <strong>مطابقاً تماماً</strong> لاسم المنتج المسجل في النظام لكي يتم تحديث المخزن بصورة صحيحة.
                </div>
                <a href="../includes/export.php?type=purchases_template" class="btn-flat btn-flat-success btn-block py-2 mt-3 text-decoration-none" style="background-color: var(--accent-success); color: #fff;">
                    <i class="fa fa-download ml-1"></i> تحميل نموذج CSV فارغ
                </a>
            </div>
        </div>

        <?php if (!empty($import_results)): ?>
        <div class="card-flat mt-3">
            <div class="card-header">
                <h6 class="mb-0">نتائج الاستيراد التفصيلية</h6>
            </div>
            <div class="card-body p-0" style="max-height: 300px; overflow-y: auto;">
                <table class="table-flat border">
                    <thead>
                        <tr>
                            <th style="width: 12%;">رقم الصف</th>
                            <th>التفاصيل</th>
                            <th style="width: 15%;">الحالة</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($import_results as $r): ?>
                        <tr>
                            <td class="text-center"><?php echo $r['row']; ?></td>
                            <td class="small"><?php echo htmlspecialchars($r['msg']); ?></td>
                            <td>
                                <?php if ($r['status'] === 'ok'): ?>
                                    <span class="badge badge-success px-2 py-1 font-weight-normal">نجاح</span>
                                <?php else: ?>
                                    <span class="badge badge-warning px-2 py-1 font-weight-normal">تخطي</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
document.getElementById('csvCurrencySelect').addEventListener('change', function() {
    const rate = this.options[this.selectedIndex].getAttribute('data-rate');
    const isBase = this.value === 'YER';
    const rateInput = document.getElementById('csvExchangeRate');
    rateInput.value = rate;
    if (isBase) {
        rateInput.setAttribute('readonly', 'readonly');
        rateInput.classList.add('bg-light');
    } else {
        rateInput.removeAttribute('readonly');
        rateInput.classList.remove('bg-light');
    }
});

function toggleBoxSelectImport(chk) {
    const boxSelect = document.getElementById("boxIdImport");
    if (boxSelect) {
        boxSelect.disabled = !chk.checked;
        if (!chk.checked) {
            boxSelect.classList.add('bg-light');
        } else {
            boxSelect.classList.remove('bg-light');
        }
    }
}
</script>

<?php
require_once($dir_prefix . 'includes/footer.php');
?>
