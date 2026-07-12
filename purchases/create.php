<?php
$dir_prefix = '../';
$module = 'purchases';

// معالجة إضافة المورد السريع عبر AJAX
if (isset($_POST['ajax_add_supplier'])) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    require_once($dir_prefix . 'includes/connect.php');
    header('Content-Type: application/json; charset=utf-8');

    if (!isset($_SESSION['SESS_MEMBER_ID'])) {
        echo json_encode(['status' => 'error', 'message' => 'غير مصرح بالعملية.']);
        exit;
    }

    date_default_timezone_set("Asia/Aden");
    $today = date("Y-m-d H:i:s");

    $supp_name = $conn->real_escape_string(trim($_POST['supp_name']));
    $phone = $conn->real_escape_string(trim($_POST['phone']));
    $email = $conn->real_escape_string(trim($_POST['email']));
    $address = $conn->real_escape_string(trim($_POST['address']));
    $company_name = $conn->real_escape_string(trim($_POST['company_name']));
    $notes = $conn->real_escape_string(trim($_POST['notes']));

    if (empty($supp_name) || empty($phone)) {
        echo json_encode(['status' => 'error', 'message' => 'اسم المورد ورقم الجوال مطلوبان.']);
        exit;
    }

    $chk = $conn->query("SELECT supp_id FROM suppliers WHERE supp_name = '$supp_name' AND d_s = 0");
    if ($chk && $chk->num_rows > 0) {
        echo json_encode(['status' => 'error', 'message' => 'اسم المورد مسجل بالفعل.']);
        exit;
    }

    $sql = "INSERT INTO suppliers (supp_name, phone, email, address, company_name, notes, buy_date) 
            VALUES ('$supp_name', '$phone', '$email', '$address', '$company_name', '$notes', '$today')";
    if ($conn->query($sql)) {
        $new_id = $conn->insert_id;
        echo json_encode(['status' => 'success', 'id' => $new_id, 'name' => $supp_name]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'خطأ أثناء الإضافة: ' . $conn->error]);
    }
    exit;
}

require_once($dir_prefix . 'includes/header.php');

check_permission(['admin', 'inventory', 'cashier']);

$active_user_id = intval($_SESSION['SESS_MEMBER_ID']);
$active_user_role = trim($_SESSION['SESS_LAST_NAME']);
$is_admin = ($active_user_role === 'admin' || empty($active_user_role));

$settings = $global_settings;
$save_error = '';

// جلب التصنيفات للاختيار
$categories_list = [];
$res_cat = $conn->query("SELECT catid, name FROM categories WHERE d_s = 0 ORDER BY name ASC");
if ($res_cat) {
    while($c = $res_cat->fetch_assoc()) {
        $categories_list[] = $c;
    }
}

if (isset($_POST['btn_save'])) {
    $build_date = date('Y-m-d', strtotime($_POST['build_date']));
    $supplier_name = $conn->real_escape_string($_POST['supplier_name']);
    $grand_total = doubleval($_POST['grand_total']);
    $remark = $conn->real_escape_string($_POST['remark']);

    $currency_code = $conn->real_escape_string($_POST['currency_code']);
    $exchange_rate = doubleval($_POST['exchange_rate']);
    if ($exchange_rate <= 0) $exchange_rate = 1.0;

    $selected_box_id = isset($_POST['box_id']) ? intval($_POST['box_id']) : get_user_box_id($conn, $active_user_id);
    $active_box_id = $selected_box_id;
    $box_name = get_box_name($conn, $active_box_id);

    $invoice_type   = in_array($_POST['invoice_type'] ?? '', ['cash','credit','account']) ? $_POST['invoice_type'] : 'cash';
    $payment_method = in_array($_POST['payment_method'] ?? '', ['cash','wallet']) ? $_POST['payment_method'] : 'cash';
    $wallet_type    = $conn->real_escape_string($_POST['wallet_type'] ?? '');
    $pay_from_box   = isset($_POST['pay_from_box']) ? intval($_POST['pay_from_box']) : 0;

    $total_paid_invoice = isset($_POST['total_paid_invoice']) ? doubleval($_POST['total_paid_invoice']) : 0;

    // بيانات البنود
    $product_names = $_POST['product_name'];
    $product_ids = $_POST['product_id'];
    $barcodes = $_POST['barcode'];
    $categories = $_POST['category_id'];
    $category_names = $_POST['category_name'] ?? [];
    $quantities = $_POST['quantity'];
    $unit_prices = $_POST['unit_price'];
    $sale_prices = $_POST['sale_price'];

    $total_paid_base = $total_paid_invoice * $exchange_rate;
    $grand_total_base = 0;
    $total_cost_base = 0;
    $count = count($product_names);

    for ($i = 0; $i < $count; $i++) {
        $p_name = trim($product_names[$i]);
        $qty = intval($quantities[$i]);
        $u_price = doubleval($unit_prices[$i]);
        if (!empty($p_name) && $qty > 0 && $u_price > 0) {
            $grand_total_base += ($qty * $u_price) * $exchange_rate;
            $total_cost_base += ($qty * $u_price) * $exchange_rate;
        }
    }

    $total_remaining_base = max(0, $grand_total_base - $total_paid_base);

    // التحقق من رصيد الصندوق
    if ($pay_from_box && $total_paid_base > 0) {
        $box_balance = get_box_balance($conn, $selected_box_id);
        if ($box_balance < $total_paid_base) {
            $save_error = "لا يمكن إتمام العملية لأن رصيد الصندوق المحدد (" . number_format($box_balance, 2) . ") أقل من المبلغ المدفوع (" . number_format($total_paid_base, 2) . ").";
        }
    }

    // جلب معرف المورد
    $supplier_id = 0;
    $supplier_res = $conn->query("SELECT supp_id FROM suppliers WHERE supp_name = '$supplier_name' AND d_s = 0 LIMIT 1");
    if ($supplier_res && $supplier_res->num_rows > 0) {
        $supplier_id = intval($supplier_res->fetch_assoc()['supp_id']);
    }

    if (empty($save_error)) {
        $conn->begin_transaction();
        try {
            $user_display = $_SESSION['SESS_FIRST_NAME'];

            // 1. إدراج الفاتورة
            $sql_insert = "INSERT INTO `purchases`(`date`, `supp_name`, `total`, `remark`, `currency_code`, `exchange_rate`, `box_id`, `remaining_total`, `invoice_type`, `payment_method`, `wallet_type`) 
                           VALUES ('$build_date', '$supplier_name', '$grand_total_base', '$remark', '$currency_code', '$exchange_rate', $active_box_id, '$total_remaining_base', '$invoice_type', '$payment_method', '$wallet_type')";
            if (!$conn->query($sql_insert)) {
                throw new Exception("فشل حفظ رأس الفاتورة في قاعدة البيانات");
            }
            $billing_id = $conn->insert_id;

            // 2. معالجة كل بند
            $paid_ratio = ($grand_total_base > 0) ? min(1.0, $total_paid_base / $grand_total_base) : 0;
            $allocated_paid = 0;

            for ($i = 0; $i < $count; $i++) {
                $p_name = trim($product_names[$i]);
                $p_id = intval($product_ids[$i]);
                $barcode = trim($barcodes[$i]);
                $cat_id = intval($categories[$i]);
                $cat_name = isset($category_names[$i]) ? trim($category_names[$i]) : '';
                $qty = intval($quantities[$i]);
                $u_price = doubleval($unit_prices[$i]);
                $s_price = doubleval($sale_prices[$i]);

                if (empty($p_name) || $qty <= 0 || $u_price <= 0) continue;

                $p_name_esc = $conn->real_escape_string($p_name);
                $barcode_esc = $conn->real_escape_string($barcode);
                $unit_price_base = $u_price * $exchange_rate;
                $sale_price_base = $s_price * $exchange_rate;
                $line_total_base = ($qty * $u_price) * $exchange_rate;

                // التحقق من وجود التصنيف بالاسم إذا لم يتم تحديد معرف تصنيف صالح
                if ($cat_id <= 0 && !empty($cat_name)) {
                    $cat_name_esc = $conn->real_escape_string($cat_name);
                    $chk_c = $conn->query("SELECT catid FROM categories WHERE name = '$cat_name_esc' AND d_s = 0 LIMIT 1");
                    if ($chk_c && $chk_c->num_rows > 0) {
                        $cat_id = intval($chk_c->fetch_assoc()['catid']);
                    } else {
                        $conn->query("INSERT INTO categories (name, d_s) VALUES ('$cat_name_esc', 0)");
                        $cat_id = $conn->insert_id;
                    }
                }

                // إذا كان المنتج غير موجود (p_id = 0 أو -1)، نقوم بإنشائه
                if ($p_id <= 0) {
                    // التحقق من وجود المنتج بالاسم
                    $chk_p = $conn->query("SELECT id FROM products WHERE name = '$p_name_esc' AND delete_status = 0 LIMIT 1");
                    if ($chk_p && $chk_p->num_rows > 0) {
                        $p_id = intval($chk_p->fetch_assoc()['id']);
                    } else {
                        // التحقق من وجود التصنيف
                        if ($cat_id <= 0) {
                            $res_cat = $conn->query("SELECT catid FROM categories WHERE d_s = 0 LIMIT 1");
                            $cat_id = ($res_cat && $res_cat->num_rows > 0) ? intval($res_cat->fetch_assoc()['catid']) : 0;
                            if ($cat_id <= 0) {
                                $conn->query("INSERT INTO categories (name, d_s) VALUES ('عام', 0)");
                                $cat_id = $conn->insert_id;
                            }
                        }
                        
                        // إذا لم يتم تحديد سعر البيع، احسبه تلقائياً
                        if ($sale_price_base <= 0) {
                            $sale_price_base = $unit_price_base * 1.25;
                        }
                        
                        $sql_ins_prod = "INSERT INTO products (name, quantity, buy_price, sale_price, catid, barcode, date, delete_status) 
                                         VALUES ('$p_name_esc', 0, $unit_price_base, $sale_price_base, $cat_id, '$barcode_esc', NOW(), 0)";
                        if ($conn->query($sql_ins_prod)) {
                            $p_id = $conn->insert_id;
                        } else {
                            throw new Exception("فشل إنشاء المنتج الجديد: " . $p_name);
                        }
                    }
                } else {
                    // تحديث الباركود والتصنيف للمنتج الموجود
                    if (!empty($barcode)) {
                        $conn->query("UPDATE products SET barcode = '$barcode_esc' WHERE id = $p_id");
                    }
                    if ($cat_id > 0) {
                        $conn->query("UPDATE products SET catid = $cat_id WHERE id = $p_id");
                    }
                }

                // حساب المدفوع والمتبقي للبند للتوزيع العادل
                if ($i === $count - 1) {
                    $line_paid_base = max(0, $total_paid_base - $allocated_paid);
                } else {
                    $line_paid_base = round($line_total_base * $paid_ratio, 2);
                    $allocated_paid += $line_paid_base;
                }
                $line_remaining_base = max(0, $line_total_base - $line_paid_base);

                $conv_factor = isset($_POST['conversion_factor'][$i]) ? doubleval($_POST['conversion_factor'][$i]) : 1.0;
                if ($conv_factor <= 0) $conv_factor = 1.0;
                $unit_name = isset($_POST['unit_name'][$i]) ? trim($_POST['unit_name'][$i]) : '';
                if (empty($unit_name)) {
                    $unit_name = 'الوحدة الأساسية';
                }

                $p_name_store = $p_name;
                if (!empty($unit_name) && $unit_name !== 'الوحدة الأساسية' && strpos($p_name_store, "($unit_name)") === false) {
                    $p_name_store .= " ($unit_name)";
                }
                $p_name_store_esc = $conn->real_escape_string($p_name_store);
                $unit_name_esc = $conn->real_escape_string($unit_name);

                // إدراج بند الفاتورة
                $sql_item = "INSERT INTO `purchase_items`(`purchase_id`, `buys_date`, `supp_name`, `supp_id`, `name`, `quantity`, `buy_price`, `pushtosupp`, `total_d`, `s`, `unit_name`) 
                             VALUES ('$billing_id', '$build_date', '$supplier_name', $supplier_id, '$p_name_store_esc', '$qty', '$line_total_base', '$line_paid_base', '$line_remaining_base', 0, '$unit_name_esc')";
                if (!$conn->query($sql_item)) {
                    throw new Exception("فشل إدراج الصنف: " . $p_name);
                }

                // تحديث المخزون
                $base_qty = $qty * $conv_factor;
                $base_buy_price = $unit_price_base / $conv_factor;
                $sql_update_qty = "UPDATE `products` SET `quantity` = `quantity` + $base_qty, `buy_price` = $base_buy_price, `total` = `quantity` * `buy_price` WHERE `id` = $p_id";
                if (!$conn->query($sql_update_qty)) {
                    throw new Exception("فشل تحديث كمية المخزن للصنف: " . $p_name);
                }

                // تسجيل حركة المخزون
                $sql_log = "INSERT INTO `inventory_log` (`product_id`, `product_name`, `type`, `qty_change`, `new_qty`, `reason`, `user`) 
                            SELECT $p_id, name, 'purchase', $base_qty, quantity, 'عملية شراء بفاتورة رقم #$billing_id', '$user_display' 
                            FROM products WHERE id = $p_id";
                if (!$conn->query($sql_log)) {
                    throw new Exception("فشل تسجيل حركة المخزون للصنف: " . $p_name);
                }
            }

            // 3. تحديث مديونية المورد
            if ($total_remaining_base > 0) {
                if ($supplier_id > 0) {
                    $sql_update_supp = "UPDATE `suppliers` SET `supp_daain` = `supp_daain` + $total_remaining_base WHERE `supp_id` = $supplier_id";
                } else {
                    $sql_update_supp = "UPDATE `suppliers` SET `supp_daain` = `supp_daain` + $total_remaining_base WHERE `supp_name` = '$supplier_name' AND d_s = 0";
                }
                if (!$conn->query($sql_update_supp)) {
                    throw new Exception("فشل تحديث مديونية المورد");
                }
            }

            // 4. خصم المبلغ من الصندوق
            if ($pay_from_box && $total_paid_base > 0) {
                if (!update_box_balance($conn, $active_box_id, $total_paid_base, 'discount', "مدفوعات فاتورة مشتريات #$billing_id", $build_date)) {
                    throw new Exception("فشل تحديث رصيد الصندوق");
                }
            }

            // 5. تسجيل القيود المحاسبية
            // قيد الشراء النقدي
            if ($total_paid_base > 0) {
                $credit_acc = $pay_from_box ? ('الصندوق - ' . $box_name) : 'رأس المال / دفع خارجي';
                if (!post_journal_entry($conn, 'purchase', $billing_id, 'المخزون / البضاعة', $credit_acc, $total_paid_base, "شراء بضاعة (نقداً) فاتورة #$billing_id - $supplier_name", $user_display, $pay_from_box ? $active_box_id : null, $currency_code, $exchange_rate, null)) {
                    throw new Exception("فشل تسجيل قيد الشراء النقدي");
                }
            }

            // قيد الشراء الآجل
            if ($total_remaining_base > 0) {
                if (!post_journal_entry($conn, 'purchase', $billing_id, 'المخزون / البضاعة', 'الذمم الدائنة - ' . $supplier_name, $total_remaining_base, "شراء بضاعة (آجل) فاتورة #$billing_id - $supplier_name", $user_display, $active_box_id, $currency_code, $exchange_rate, null)) {
                    throw new Exception("فشل تسجيل قيد الشراء الآجل");
                }
            }

            $conn->commit();

            echo "<script>window.location='view.php?id=$billing_id';</script>";
            exit;
        } catch (Exception $e) {
            $conn->rollback();
            $save_error = 'فشل حفظ الفاتورة: ' . $e->getMessage();
        }
    }
}

$products_json = '[]';

$currencies_list = [];
$res_curr = $conn->query("SELECT * FROM currencies ORDER BY id ASC");
if ($res_curr) {
    while($c = $res_curr->fetch_assoc()) {
        $currencies_list[] = $c;
    }
}
?>
<title>إضافة فاتورة مشتريات جديدة</title>

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
    border-top: none;
    max-height: 250px;
    overflow-y: auto;
    z-index: 1050;
    box-shadow: 0 4px 8px rgba(0,0,0,0.15);
}
.autocomplete-item {
    padding: 8px 12px;
    cursor: pointer;
    font-size: 0.85rem;
    text-align: right;
}
.autocomplete-item:hover, .autocomplete-item.active {
    background-color: #f8f9fa;
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
.autocomplete-item.create-new:hover {
    background: #fde68a;
}

.modal-content {
    border: none;
    overflow: hidden;
}
.modal-body {
    padding: 18px 20px;
    font-size: 0.85rem;
    color: #334155;
    background: #ffffff;
}
.modal-body .form-label,
.modal-body label {
    font-size: 0.8rem;
    color: #475569;
    font-weight: 700;
}
.modal-body .form-control {
    font-size: 0.85rem;
    border-radius: 6px;
    border: 1px solid #e2e8f0;
}

.quick-results-wrap {
    max-height: 320px;
    overflow-y: auto;
    border: 1px solid #f1f5f9;
    border-radius: 6px;
}
.quick-product-table {
    width: 100%;
    margin-bottom: 0;
    font-size: 0.85rem;
    border-collapse: collapse;
}
.quick-product-table thead th {
    background-color: #f1f5f9;
    color: #475569;
    font-weight: 700;
    font-size: 0.8rem;
    text-align: center;
    padding: 10px 8px;
    border-bottom: 1px solid #e2e8f0;
    position: sticky;
    top: 0;
    z-index: 2;
}
.quick-product-table thead th.col-name {
    text-align: right;
}
.quick-product-table tbody td {
    padding: 9px 8px;
    text-align: center;
    border-bottom: 1px solid #f1f5f9;
    vertical-align: middle;
}
.quick-product-table tbody td.col-name {
    text-align: right;
    font-weight: 700;
    color: #1c2941;
}
.quick-product-table .price-cell {
    color: #1c2941;
    font-weight: 700;
}

.table-flat {
    font-size: 0.85rem;
}
.table-flat thead th {
    font-size: 0.8rem;
    font-weight: 700;
    padding: 10px 8px;
    background-color: #f8f9fa;
    border-bottom: 2px solid #dee2e6;
}
.table-flat tbody td {
    padding: 8px;
    vertical-align: middle;
}
.table-flat .form-control {
    font-size: 0.85rem;
    padding: 6px 8px;
}

.invoice-summary {
    background: #f8f9fa;
    border: 1px solid #dee2e6;
    border-radius: 6px;
    padding: 15px;
}
.invoice-summary table {
    width: 100%;
}
.invoice-summary td {
    padding: 8px 0;
    font-size: 0.9rem;
}
.invoice-summary .total-label {
    font-weight: 600;
    color: #495057;
}
.invoice-summary .total-value {
    font-weight: 700;
    font-size: 1.1rem;
    text-align: left;
}

.accounting-guide {
    background: #fff3cd;
    border: 1px solid #ffc107;
    border-radius: 6px;
    padding: 15px;
    margin-top: 15px;
}
.accounting-guide h6 {
    font-size: 0.95rem;
    font-weight: 700;
    color: #856404;
    margin-bottom: 10px;
}
.accounting-guide table {
    width: 100%;
    font-size: 0.85rem;
}
.accounting-guide th {
    background-color: #ffe69c;
    color: #856404;
    font-weight: 700;
    padding: 8px;
    text-align: center;
}
.accounting-guide td {
    padding: 6px 8px;
    border-bottom: 1px solid #ffe69c;
}
.accounting-guide .editable-amount {
    background: #fff;
    border: 1px solid #ced4da;
    border-radius: 4px;
    padding: 4px 8px;
    font-weight: 600;
    width: 100%;
    text-align: center;
}

.new-product-badge {
    display: inline-block;
    background: #fef3c7;
    color: #92400e;
    padding: 2px 8px;
    border-radius: 4px;
    font-size: 0.7rem;
    font-weight: 700;
    margin-right: 5px;
}

.product-search-input, .barcode-input, .category-input {
    cursor: pointer;
}

.barcode-input {
    font-family: 'Courier New', monospace;
    font-weight: bold;
}

.generate-barcode-btn {
    padding: 0.25rem 0.5rem;
    font-size: 0.875rem;
}

.box-balance-display {
    background: #e7f3ff;
    border: 1px solid #b3d9ff;
    border-radius: 4px;
    padding: 8px 12px;
    margin-top: 8px;
    font-size: 0.85rem;
    font-weight: 600;
    color: #004085;
}

.box-balance-warning {
    background: #fff3cd;
    border: 1px solid #ffc107;
    color: #856404;
}
</style>

<!-- Loading Overlay -->
<div class="loading-overlay" id="loadingOverlay">
    <div class="loading-spinner"></div>
    <div class="loading-text">جاري التحميل...</div>
</div>

<div class="card-flat">
    <div class="card-header">
        <h5><?php echo get_icon('purchases', 'ml-2 text-primary'); ?> إضافة فاتورة مشتريات جديدة</h5>
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

        <div class="alert alert-info rounded-0 mb-4 no-print">
            <strong><i class="bi bi-info-circle ml-1"></i> ملاحظة:</strong>
            يمكنك إدخال منتجات غير موجودة في النظام. سيتم إنشاؤها تلقائياً عند حفظ الفاتورة مع تحديد سعر الشراء كسعر تكلفة.
        </div>

        <form method="POST" id="purchaseForm">
            <!-- ======================== الصف الأول ======================== -->
            <div class="row mb-3">
                <div class="col-md-2 col-sm-6 mb-3">
                    <label class="form-label font-weight-bold text-secondary">تاريخ الشراء</label>
                    <input type="date" name="build_date" class="form-control rounded-0" value="<?php echo date('Y-m-d'); ?>" required>
                </div>

                <div class="col-md-3 col-sm-6 mb-3">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <label class="form-label font-weight-bold text-secondary mb-0">المورد</label>
                        <a href="javascript:void(0)" class="small font-weight-bold text-decoration-none" data-toggle="modal" data-target="#quickAddSupplierModal">
                            <i class="fa fa-plus-circle ml-1"></i>مورد جديد
                        </a>
                    </div>
                    <select name="supplier_name" id="supplierSelect" class="form-control rounded-0" required>
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

                <div class="col-md-2 col-sm-6 mb-3">
                    <label class="form-label font-weight-bold text-secondary">نوع الفاتورة</label>
                    <select name="invoice_type" id="invoiceTypeSelect" class="form-control rounded-0" onchange="togglePurchaseInvoiceType(this.value)" required>
                        <option value="cash">نقد</option>
                        <option value="credit">آجل</option>
                        <option value="account">من حساب</option>
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
                    <label class="form-label font-weight-bold text-secondary">سعر الصرف</label>
                    <input type="number" step="any" name="exchange_rate" id="exchangeRateInput" class="form-control rounded-0 font-weight-bold text-center bg-light" value="1.0" readonly required>
                </div>
            </div>

            <!-- ======================== الصف الثاني ======================== -->
            <div class="row mb-3" id="paymentRow">
                <div class="col-md-3 col-sm-6 mb-3" id="boxSection">
                    <label class="form-label font-weight-bold text-secondary">الصندوق</label>
                    <div class="form-check mb-1">
                        <input type="checkbox" name="pay_from_box" id="payFromBox" class="form-check-input" value="1" checked onchange="toggleBoxSelect(this); updateBoxBalanceDisplay();">
                        <label class="form-check-label small font-weight-bold text-dark" for="payFromBox">خصم المدفوع من الصندوق</label>
                    </div>
                    <?php if ($is_admin): ?>
                        <select name="box_id" id="boxSelect" class="form-control rounded-0" required onchange="updateBoxBalanceDisplay()">
                            <?php
                            $res_b = $conn->query("SELECT box_id, name, mony FROM treasury WHERE is_active = 1 ORDER BY box_id ASC");
                            if ($res_b) {
                                while($b = $res_b->fetch_assoc()) {
                                    echo "<option value='{$b['box_id']}' data-balance='{$b['mony']}' " . ($b['box_id'] == 1 ? 'selected' : '') . ">" . htmlspecialchars($b['name']) . "</option>";
                                }
                            }
                            ?>
                        </select>
                    <?php else: ?>
                        <?php 
                        $user_box_id = get_user_box_id($conn, $active_user_id); 
                        $box_res = $conn->query("SELECT mony FROM treasury WHERE box_id = $user_box_id");
                        $box_mony = ($box_res && $box_res->num_rows > 0) ? floatval($box_res->fetch_assoc()['mony']) : 0;
                        ?>
                        <input type="hidden" name="box_id" value="<?php echo $user_box_id; ?>" id="userBoxId" data-balance="<?php echo $box_mony; ?>">
                        <input type="text" class="form-control text-center font-weight-bold bg-light rounded-0" readonly value="<?php echo htmlspecialchars(get_box_name($conn, $user_box_id)); ?>">
                    <?php endif; ?>
                    <div class="box-balance-display" id="boxBalanceDisplay">
                        <i class="bi bi-wallet2 ml-1"></i>
                        رصيد الصندوق: <strong id="currentBoxBalance">0.00</strong> ر.ي
                    </div>
                </div>

                <div class="col-md-3 col-sm-6 mb-3" id="paymentMethodSection">
                    <label class="form-label font-weight-bold text-secondary">طريقة الدفع</label>
                    <select name="payment_method" id="paymentMethodSelect" class="form-control rounded-0" onchange="toggleWalletSection(this.value)">
                        <option value="cash">نقداً</option>
                        <option value="wallet">محفظة إلكترونية / بنك</option>
                    </select>
                </div>

                <div class="col-md-3 col-sm-6 mb-3 d-none" id="walletTypeSection">
                    <label class="form-label font-weight-bold text-secondary">نوع المحفظة / البنك</label>
                    <select id="walletTypeSelect" class="form-control rounded-0">
                        <option value="">-- اختر --</option>
                        <optgroup label="محافظ إلكترونية">
                            <option value="جيب">جيب </option>
                            <option value="فلوسك">فلوسك</option>
                            <option value="جوالي">جوالي</option>
                            <option value="ايزي">ايزي </option>
                            <option value="محفظة أخرى">محفظة أخرى</option>
                        </optgroup>
                        <optgroup label="بنوك">
                            <option value="بنك الكريمي">بنك الكريمي</option>
                            <option value="بنك اليمن والخليج">بنك اليمن والخليج</option>
                            <option value="بنك التضامن">بنك التضامن</option>
                            <option value="بنك آخر">بنك آخر</option>
                        </optgroup>
                    </select>
                </div>
                <input type="hidden" name="wallet_type" id="walletTypeHidden" value="">
            </div>

            <!-- شريط البحث -->
            <div class="card p-3 bg-light border-0 mb-4 no-print">
                <div class="row align-items-center">
                    <div class="col-md-6 mb-2 mb-md-0">
                        <div class="input-group">
                            <div class="input-group-prepend">
                                <span class="input-group-text bg-primary text-white border-0">
                                    <i class="bi bi-upc-scan"></i>
                                </span>
                            </div>
                            <input type="text" id="barcodeScanInput" class="form-control rounded-0 border-primary font-weight-bold text-center" placeholder="امسح باركود المنتج..." autofocus autocomplete="off">
                        </div>
                    </div>
                    <div class="col-md-6 text-md-left">
                        <button type="button" id="quickProductSearchBtn" class="btn btn-outline-primary rounded-0 px-4 font-weight-bold">
                            <i class="bi bi-search ml-1"></i> F2 - البحث السريع عن المنتج
                        </button>
                    </div>
                </div>
            </div>

            <!-- جدول المنتجات -->
            <div class="table-responsive">
                <table class="table-flat" id="itemsTable">
                    <thead>
                        <tr>
                            <th style="width: 18%;">المنتج</th>
                            <th style="width: 10%;">الوحدة</th>
                            <th style="width: 12%;">الباركود</th>
                            <th style="width: 12%;">التصنيف</th>
                            <th style="width: 8%;">الكمية</th>
                            <th style="width: 10%;">سعر الشراء</th>
                            <th style="width: 10%;">سعر البيع</th>
                            <th style="width: 10%;">المجموع</th>
                            <th style="width: 10%;">الربح المتوقع</th>
                            <th class="no-print" style="width: 5%;">إجراء</th>
                        </tr>
                    </thead>
                    <tbody id="itemsContainer">
                        <tr class="item-row">
                            <td>
                                <div class="product-search-container">
                                    <input type="text" class="form-control product-search-input rounded-0" placeholder="ابحث أو اكتب اسم منتج جديد..." autocomplete="off" required>
                                    <input type="hidden" name="product_name[]" class="select-product-name" value="">
                                    <input type="hidden" name="product_id[]" class="select-product" value="">
                                    <div class="autocomplete-dropdown d-none"></div>
                                </div>
                                <input type="hidden" name="conversion_factor[]" class="conversion-factor" value="1.0000">
                                <input type="hidden" name="unit_name[]" class="unit-name" value="الوحدة الأساسية">
                                <input type="hidden" name="unit_id[]" class="unit-id" value="">
                                <small class="text-muted new-product-indicator d-none">
                                    <span class="new-product-badge">منتج جديد</span> سيتم إنشاؤه عند الحفظ
                                </small>
                            </td>
                            <td>
                                <input type="text" name="unit_display[]" class="form-control unit-display-input text-center bg-light rounded-0" readonly value="الوحدة الأساسية">
                            </td>
                            <td>
                                <div class="barcode-search-container">
                                    <div class="input-group">
                                        <input type="text" name="barcode[]" class="form-control barcode-input text-center rounded-0" placeholder="باركود" autocomplete="off">
                                        <div class="input-group-append">
                                            <button type="button" class="btn btn-sm btn-outline-secondary generate-barcode-btn" title="توليد باركود عشوائي">
                                                <i class="bi bi-upc-scan"></i>
                                            </button>
                                        </div>
                                    </div>
                                    <div class="autocomplete-dropdown d-none"></div>
                                </div>
                            </td>
                            <td>
                                <div class="category-search-container">
                                    <input type="text" name="category_name[]" class="form-control category-input rounded-0" placeholder="اختر التصنيف..." autocomplete="off">
                                    <input type="hidden" name="category_id[]" class="select-category" value="">
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
                                <input type="number" step="any" name="sale_price[]" class="form-control sale-price-input text-center rounded-0">
                            </td>
                            <td>
                                <input type="text" class="form-control total-input text-center bg-light rounded-0" readonly value="0">
                            </td>
                            <td>
                                <input type="text" class="form-control profit-input text-center bg-light rounded-0" readonly value="0">
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

            <div class="mt-3 no-print">
                <button type="button" id="addItemBtn" class="btn-flat btn-flat-success btn-sm">
                    <?php echo get_icon('plus', 'ml-1'); ?> إضافة صنف آخر
                </button>
            </div>

            <hr class="my-4">

            <!-- الملخص -->
            <div class="row">
                <div class="col-lg-6 mb-3">
                    <label class="form-label font-weight-bold text-secondary">ملاحظات الفاتورة</label>
                    <textarea name="remark" class="form-control rounded-0" rows="3" placeholder="ملاحظات حول عملية الشراء..."></textarea>
                    <div class="mt-4 no-print text-center">
                        <button type="submit" name="btn_save" id="btnSavePurchase" class="btn-flat btn-flat-primary btn-lg px-5">
                            <?php echo get_icon('check', 'ml-1'); ?> حفظ الفاتورة وترحيلها
                        </button>
                    </div>
                </div>
                
                <div class="col-lg-6">
                    <div class="invoice-summary">
                        <table>
                            <tr>
                                <td class="total-label">إجمالي الفاتورة</td>
                                <td class="total-value">
                                    <input type="text" id="grandTotalDisplay" name="grand_total" class="form-control text-left font-weight-bold bg-transparent border-0 rounded-0" readonly value="0">
                                    <span class="currency-symbol">ر.ي</span>
                                </td>
                            </tr>
                            <tr id="paidRow">
                                <td class="total-label text-success">المبلغ المدفوع</td>
                                <td class="total-value">
                                    <input type="number" step="any" min="0" id="totalPaidInput" name="total_paid_invoice" class="form-control text-left font-weight-bold bg-transparent border-0 rounded-0" value="0" style="border: 2px solid #28a745;">
                                    <span class="currency-symbol">ر.ي</span>
                                </td>
                            </tr>
                            <tr>
                                <td class="total-label text-danger">الرصيد المتبقي (آجل)</td>
                                <td class="total-value text-danger">
                                    <span id="totalRemainingDisplay">0.00</span> <span class="currency-symbol">ر.ي</span>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <div class="accounting-guide">
                        <h6><i class="fa fa-book ml-2"></i>الدليل المحاسبي - القيود المالية</h6>
                        <table>
                            <thead>
                                <tr>
                                    <th>البيان</th>
                                    <th>مدين</th>
                                    <th>المبلغ</th>
                                    <th>دائن</th>
                                    <th>المبلغ</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>الشراء النقدي</td>
                                    <td>المخزون / البضاعة</td>
                                    <td><input type="number" step="any" class="editable-amount" id="acc_purchase_cash" value="0" readonly></td>
                                    <td>الصندوق / رأس المال</td>
                                    <td><input type="number" step="any" class="editable-amount" id="acc_purchase_cash_credit" value="0" readonly></td>
                                </tr>
                                <tr>
                                    <td>الشراء الآجل</td>
                                    <td>المخزون / البضاعة</td>
                                    <td><input type="number" step="any" class="editable-amount" id="acc_purchase_credit" value="0" readonly></td>
                                    <td>الذمم الدائنة - المورد</td>
                                    <td><input type="number" step="any" class="editable-amount" id="acc_purchase_credit_credit" value="0" readonly></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- مودال تأكيد الخروج -->
<div class="modal fade" id="leavePageModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content rounded-0">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title">
                    <i class="bi bi-exclamation-triangle-fill ml-2"></i>
                    تحذير: بيانات غير محفوظة!
                </h5>
            </div>
            <div class="modal-body text-right" dir="rtl">
                <p class="mb-2 font-weight-bold">أنت على وشك مغادرة صفحة إنشاء فاتورة المشتريات.</p>
                <p class="text-danger">جميع البيانات المدخلة ستضيع. هل تريد المتابعة؟</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">إلغاء</button>
                <button type="button" id="confirmLeaveBtn" class="btn btn-danger">خروج بدون حفظ</button>
            </div>
        </div>
    </div>
</div>

<script type="text/javascript">
const availableProducts = <?php echo $products_json; ?>;
const categoriesList = <?php echo json_encode($categories_list, JSON_UNESCAPED_UNICODE); ?>;

let purchaseFormDirty = false;
let purchaseFormSubmitting = false;
let pendingLeaveUrl = null;

document.addEventListener('DOMContentLoaded', function() {
    setTimeout(() => { purchaseFormDirty = false; }, 300);

    const purchaseForm = document.getElementById('purchaseForm');
    if (purchaseForm) {
        purchaseForm.addEventListener('change', () => { purchaseFormDirty = true; });
        purchaseForm.addEventListener('input',  () => { purchaseFormDirty = true; });
        purchaseForm.addEventListener('submit', () => { purchaseFormSubmitting = true; purchaseFormDirty = false; });
    }

    const btnSave = document.getElementById('btnSavePurchase');
    if (btnSave) btnSave.addEventListener('click', () => { purchaseFormSubmitting = true; });

    document.querySelectorAll('a[href]').forEach(function(link) {
        const href = link.getAttribute('href');
        if (!href || href.startsWith('#') || href.startsWith('javascript') || href.startsWith('mailto')) return;
        link.addEventListener('click', function(e) {
            if (purchaseFormDirty && !purchaseFormSubmitting) {
                e.preventDefault();
                pendingLeaveUrl = link.href;
                $('#leavePageModal').modal('show');
            }
        });
    });

    const confirmLeaveBtn = document.getElementById('confirmLeaveBtn');
    if (confirmLeaveBtn) {
        confirmLeaveBtn.addEventListener('click', function() {
            purchaseFormDirty = false;
            if (pendingLeaveUrl) window.location.href = pendingLeaveUrl;
        });
    }

    // تحديث عرض رصيد الصندوق
    updateBoxBalanceDisplay();
});

window.addEventListener('beforeunload', function(e) {
    if (purchaseFormDirty && !purchaseFormSubmitting) {
        e.preventDefault();
        e.returnValue = '';
    }
});

function togglePurchaseInvoiceType(val) {
    const paidRow = document.getElementById('paidRow');
    const paymentSection = document.getElementById('paymentMethodSection');
    const boxSection = document.getElementById('boxSection');

    if (val === 'credit') {
        paidRow.style.opacity = '0.4';
        paidRow.style.pointerEvents = 'none';
        document.getElementById('totalPaidInput').value = '0';
        document.getElementById('totalPaidInput').dispatchEvent(new Event('input'));
        paymentSection.classList.add('d-none');
        boxSection.classList.add('d-none');
    } else {
        paidRow.style.opacity = '1';
        paidRow.style.pointerEvents = 'auto';
        paymentSection.classList.remove('d-none');
        if (val === 'cash') {
            boxSection.classList.remove('d-none');
        } else {
            boxSection.classList.add('d-none');
        }
    }
    updateBoxBalanceDisplay();
}

function toggleWalletSection(val) {
    const walletSec = document.getElementById('walletTypeSection');
    const walletHidden = document.getElementById('walletTypeHidden');
    const walletSelect = document.getElementById('walletTypeSelect');
    if (val === 'wallet') {
        walletSec.classList.remove('d-none');
        walletSelect.name = 'wallet_type';
        walletHidden.name = '';
    } else {
        walletSec.classList.add('d-none');
        walletSelect.name = '';
        walletHidden.name = 'wallet_type';
        walletHidden.value = '';
    }
}

function toggleBoxSelect(chk) {
    const boxSelect = document.getElementById('boxSelect');
    if (boxSelect) {
        boxSelect.disabled = !chk.checked;
        if (!chk.checked) {
            boxSelect.classList.add('bg-light');
        } else {
            boxSelect.classList.remove('bg-light');
        }
    }
    updateBoxBalanceDisplay();
}

// تحديث عرض رصيد الصندوق
function updateBoxBalanceDisplay() {
    const payFromBox = document.getElementById('payFromBox').checked;
    const invoiceType = document.getElementById('invoiceTypeSelect').value;
    const display = document.getElementById('boxBalanceDisplay');
    const balanceSpan = document.getElementById('currentBoxBalance');
    
    let boxBalance = 0;
    const boxSelect = document.getElementById('boxSelect');
    const userBoxId = document.getElementById('userBoxId');
    
    if (boxSelect && boxSelect.selectedIndex >= 0) {
        const selectedOption = boxSelect.options[boxSelect.selectedIndex];
        boxBalance = parseFloat(selectedOption.getAttribute('data-balance')) || 0;
    } else if (userBoxId) {
        boxBalance = parseFloat(userBoxId.getAttribute('data-balance')) || 0;
    }
    
    balanceSpan.textContent = boxBalance.toFixed(2);
    
    // تحذير إذا كان الرصيد غير كافٍ
    const totalPaid = parseFloat(document.getElementById('totalPaidInput').value) || 0;
    if (payFromBox && invoiceType === 'cash' && totalPaid > boxBalance) {
        display.classList.add('box-balance-warning');
        display.innerHTML = '<i class="bi bi-exclamation-triangle-fill ml-1"></i> تحذير: رصيد الصندوق غير كافٍ! المتبقي: <strong>' + boxBalance.toFixed(2) + '</strong> ر.ي';
    } else {
        display.classList.remove('box-balance-warning');
        display.innerHTML = '<i class="bi bi-wallet2 ml-1"></i> رصيد الصندوق: <strong>' + boxBalance.toFixed(2) + '</strong> ر.ي';
    }
}

document.addEventListener("DOMContentLoaded", function() {
    const itemsContainer = document.getElementById("itemsContainer");
    const addItemBtn = document.getElementById("addItemBtn");
    const currencySelect = document.getElementById("currencySelect");
    const exchangeRateInput = document.getElementById("exchangeRateInput");

    const rowTemplate = document.querySelector(".item-row").cloneNode(true);

    function escapeHtml(text) {
        if (!text) return '';
        return text.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
    }

    // توليد باركود عشوائي
    function generateRandomBarcode() {
        const timestamp = Date.now().toString(36).toUpperCase();
        const random = Math.random().toString(36).substring(2, 8).toUpperCase();
        return 'PRD' + timestamp + random;
    }

    function updateRowCalculations(row) {
        const qty = parseFloat(row.querySelector(".quantity-input").value) || 0;
        const buyPrice = parseFloat(row.querySelector(".price-input").value) || 0;
        const salePrice = parseFloat(row.querySelector(".sale-price-input").value) || 0;

        const total = qty * buyPrice;
        row.querySelector(".total-input").value = total.toFixed(2);

        // حساب الربح
        if (salePrice > 0) {
            const profit = (salePrice - buyPrice) * qty;
            row.querySelector(".profit-input").value = profit.toFixed(2);
        } else {
            const suggestedSale = buyPrice * 1.25;
            const profit = (suggestedSale - buyPrice) * qty;
            row.querySelector(".profit-input").value = profit.toFixed(2);
        }

        updateGrandTotals();
        updateAccountingGuide();
        updateBoxBalanceDisplay();
    }

    function updateGrandTotals() {
        let totalVal = 0;
        document.querySelectorAll(".item-row").forEach(function(row) {
            totalVal += parseFloat(row.querySelector(".total-input").value) || 0;
        });

        document.getElementById("grandTotalDisplay").value = totalVal.toFixed(2);

        const paid = parseFloat(document.getElementById("totalPaidInput").value) || 0;
        const remaining = Math.max(0, totalVal - paid);
        document.getElementById("totalRemainingDisplay").textContent = remaining.toFixed(2);
    }

    function updateAccountingGuide() {
        const totalVal = parseFloat(document.getElementById("grandTotalDisplay").value) || 0;
        const paid = parseFloat(document.getElementById("totalPaidInput").value) || 0;
        const remaining = Math.max(0, totalVal - paid);

        document.getElementById("acc_purchase_cash").value = paid.toFixed(2);
        document.getElementById("acc_purchase_cash_credit").value = paid.toFixed(2);
        document.getElementById("acc_purchase_credit").value = remaining.toFixed(2);
        document.getElementById("acc_purchase_credit_credit").value = remaining.toFixed(2);
    }

    window.updateRowCalculations = updateRowCalculations;
    window.updateGrandTotals = updateGrandTotals;
    window.updateAccountingGuide = updateAccountingGuide;
    window.updateBoxBalanceDisplay = updateBoxBalanceDisplay;

    document.getElementById("totalPaidInput").addEventListener("input", function() {
        updateGrandTotals();
        updateAccountingGuide();
        updateBoxBalanceDisplay();
    });

    // توليد باركود عشوائي عند الضغط على الزر
    itemsContainer.addEventListener("click", function(e) {
        if (e.target.closest(".generate-barcode-btn")) {
            const row = e.target.closest(".item-row");
            const barcodeInput = row.querySelector(".barcode-input");
            barcodeInput.value = generateRandomBarcode();
            barcodeInput.focus();
            barcodeInput.select();
        }
    });

    addItemBtn.addEventListener("click", function() {
        const newRow = rowTemplate.cloneNode(true);
        newRow.querySelector(".product-search-input").value = "";
        newRow.querySelector(".select-product-name").value = "";
        newRow.querySelector(".select-product").value = "";
        newRow.querySelector(".barcode-input").value = "";
        newRow.querySelector(".category-input").value = "";
        newRow.querySelector(".select-category").value = "";
        newRow.querySelector(".quantity-input").value = "1";
        newRow.querySelector(".price-input").value = "";
        newRow.querySelector(".sale-price-input").value = "";
        newRow.querySelector(".total-input").value = "0";
        newRow.querySelector(".profit-input").value = "0";
        newRow.querySelector(".conversion-factor").value = "1.0000";
        newRow.querySelector(".unit-name").value = "الوحدة الأساسية";
        newRow.querySelector(".unit-display-input").value = "الوحدة الأساسية";
        newRow.querySelector(".unit-id").value = "";
        newRow.querySelectorAll(".autocomplete-dropdown").forEach(d => {
            d.classList.add("d-none");
            d.innerHTML = "";
        });
        newRow.querySelector(".new-product-indicator").classList.add("d-none");

        itemsContainer.appendChild(newRow);
    });

    itemsContainer.addEventListener("click", function(e) {
        if (e.target.classList.contains("remove-item-btn") || e.target.closest(".remove-item-btn")) {
            const row = e.target.closest(".item-row");
            if (document.querySelectorAll(".item-row").length > 1) {
                row.remove();
                updateGrandTotals();
                updateAccountingGuide();
            } else {
                alert("يجب أن تحتوي الفاتورة على صنف واحد على الأقل!");
            }
        }
    });

    // معالجة إدخال المنتج
    itemsContainer.addEventListener("input", function(e) {
        if (e.target.matches(".product-search-input")) {
            const container = e.target.closest(".product-search-container");
            const nameInput = container.querySelector(".select-product-name");
            const hiddenInput = container.querySelector(".select-product");
            const indicator = e.target.closest(".item-row").querySelector(".new-product-indicator");

            if (!hiddenInput.value || hiddenInput.value === "-1" || hiddenInput.value === "") {
                nameInput.value = e.target.value;
                hiddenInput.value = "-1";
                if (indicator) indicator.classList.remove("d-none");
            } else {
                if (indicator) indicator.classList.add("d-none");
            }

            showProductAutocompleteDropdown(e.target);
        }

        if (e.target.matches(".quantity-input, .price-input, .sale-price-input")) {
            const row = e.target.closest(".item-row");
            updateRowCalculations(row);
        }

        // معالجة إدخال الباركود
        if (e.target.matches(".barcode-input")) {
            const row = e.target.closest(".item-row");
            const barcode = e.target.value.trim();
            
            if (barcode.length >= 3) {
                showBarcodeAutocompleteDropdown(e.target, barcode);
            } else {
                const dropdown = e.target.closest(".barcode-search-container").querySelector(".autocomplete-dropdown");
                dropdown.classList.add("d-none");
                dropdown.innerHTML = "";
            }
        }

        // معالجة إدخال التصنيف
        if (e.target.matches(".category-input")) {
            showCategoryAutocompleteDropdown(e.target);
        }
    });

    // عرض قائمة المنتجات عند البحث
    function showProductAutocompleteDropdown(input) {
        const row = input.closest(".item-row");
        window.activeSearchRow = row;
        const dropdown = row.querySelector('.product-search-container .autocomplete-dropdown');
        const query = input.value.trim();

        if (!query) {
            dropdown.classList.add('d-none');
            dropdown.innerHTML = '';
            return;
        }

        if (row.__productSearchTimer) clearTimeout(row.__productSearchTimer);
        row.__productSearchTimer = setTimeout(() => {
            dropdown.classList.remove('d-none');
            dropdown.innerHTML = '<div class="text-center p-2 text-muted">جاري البحث...</div>';

            fetch(`../api/search_products.php?q=${encodeURIComponent(query)}`)
                .then(res => res.json())
                .then(products => {
                    let html = '';

                    if (products && products.length > 0) {
                        const rate = parseFloat(exchangeRateInput.value) || 1.0;
                        html = products.map(product => {
                            const pJson = JSON.stringify(product).replace(/'/g, "&#39;");
                            const priceConverted = (product.buy_price / rate).toFixed(2);
                            return `
                                <div class="autocomplete-item" tabindex="0" data-id="${product.id}" data-product='${pJson}'>
                                    <div class="item-title font-weight-bold">${escapeHtml(product.name)}</div>
                                    <div class="item-meta">
                                        <span>الباركود: <strong>${escapeHtml(product.barcode || '-')}</strong></span> | 
                                        <span>سعر الشراء: <strong class="text-primary">${priceConverted}</strong></span>
                                    </div>
                                </div>
                            `;
                        }).join('');
                    }

                    html += `<div class="autocomplete-item create-new" data-id="-1" data-new-name="${escapeHtml(query)}">
                        <i class="bi bi-plus-circle ml-1"></i> إنشاء منتج جديد: <strong>${escapeHtml(query)}</strong>
                    </div>`;

                    dropdown.innerHTML = html;

                    dropdown.querySelectorAll('.autocomplete-item').forEach(it => {
                        it.addEventListener('keydown', function(e){
                            if (e.key === 'Enter') {
                                e.preventDefault();
                                this.click();
                            }
                        });
                    });
                })
                .catch(err => {
                    console.error('Inline search error:', err);
                    dropdown.innerHTML = `<div class="autocomplete-item create-new" data-id="-1" data-new-name="${escapeHtml(query)}">
                        <i class="bi bi-plus-circle ml-1"></i> إنشاء منتج جديد: <strong>${escapeHtml(query)}</strong>
                    </div>`;
                });
        }, 180);
    }

    // عرض قائمة المنتجات عند البحث بالباركود
    function showBarcodeAutocompleteDropdown(input, barcode) {
        const row = input.closest(".item-row");
        const container = input.closest(".barcode-search-container");
        const dropdown = container.querySelector('.autocomplete-dropdown');

        if (row.__barcodeSearchTimer) clearTimeout(row.__barcodeSearchTimer);
        row.__barcodeSearchTimer = setTimeout(() => {
            dropdown.classList.remove('d-none');
            dropdown.innerHTML = '<div class="text-center p-2 text-muted">جاري البحث...</div>';

            fetch(`../api/search_products.php?q=${encodeURIComponent(barcode)}`)
                .then(res => res.json())
                .then(products => {
                    let html = '';

                    if (products && products.length > 0) {
                        const rate = parseFloat(exchangeRateInput.value) || 1.0;
                        html = products.map(product => {
                            const pJson = JSON.stringify(product).replace(/'/g, "&#39;");
                            const priceConverted = (product.buy_price / rate).toFixed(2);
                            return `
                                <div class="autocomplete-item" tabindex="0" data-id="${product.id}" data-product='${pJson}'>
                                    <div class="item-title font-weight-bold">${escapeHtml(product.name)}</div>
                                    <div class="item-meta">
                                        <span>الباركود: <strong>${escapeHtml(product.barcode || '-')}</strong></span> | 
                                        <span>سعر الشراء: <strong class="text-primary">${priceConverted}</strong></span>
                                    </div>
                                </div>
                            `;
                        }).join('');
                    } else {
                        html = '<div class="text-center p-2 text-muted">لم يتم العثور على منتج بهذا الباركود</div>';
                    }

                    dropdown.innerHTML = html;

                    dropdown.querySelectorAll('.autocomplete-item').forEach(it => {
                        it.addEventListener('keydown', function(e){
                            if (e.key === 'Enter') {
                                e.preventDefault();
                                this.click();
                            }
                        });
                    });
                })
                .catch(err => {
                    console.error('Barcode search error:', err);
                    dropdown.innerHTML = '<div class="text-center p-2 text-muted">خطأ في البحث</div>';
                });
        }, 180);
    }

    // عرض قائمة التصنيفات
    function showCategoryAutocompleteDropdown(input) {
        const row = input.closest(".item-row");
        const container = input.closest(".category-search-container");
        const dropdown = container.querySelector('.autocomplete-dropdown');
        const query = input.value.trim().toLowerCase();

        dropdown.classList.remove('d-none');
        
        let filteredCategories = categoriesList;
        if (query) {
            filteredCategories = categoriesList.filter(cat => 
                cat.name.toLowerCase().includes(query)
            );
        }

        let html = '';
        if (filteredCategories.length > 0) {
            html = filteredCategories.map(cat => {
                return `<div class="autocomplete-item" tabindex="0" data-id="${cat.catid}" data-name="${escapeHtml(cat.name)}">
                    ${escapeHtml(cat.name)}
                </div>`;
            }).join('');
        } else {
            html = '<div class="text-center p-2 text-muted">لا توجد تصنيفات مطابقة</div>';
        }

        if (query && !categoriesList.some(cat => cat.name.toLowerCase() === query.toLowerCase())) {
            html += `<div class="autocomplete-item create-new-category" tabindex="0" data-id="-1" data-name="${escapeHtml(query)}" style="background: #f0fdf4; border-top: 1px dashed #22c55e; color: #166534; font-weight: bold; padding: 8px 12px; cursor: pointer;">
                <i class="bi bi-plus-circle ml-1"></i> إضافة تصنيف جديد: <strong>${escapeHtml(query)}</strong>
            </div>`;
        }

        dropdown.innerHTML = html;

        dropdown.querySelectorAll('.autocomplete-item').forEach(it => {
            it.addEventListener('keydown', function(e){
                if (e.key === 'Enter') {
                    e.preventDefault();
                    this.click();
                }
            });
        });
    }

    function selectProductForRow(row, product) {
        const container = row.querySelector(".product-search-container");
        const input = container.querySelector(".product-search-input");
        const hiddenInput = container.querySelector(".select-product");
        const nameInput = container.querySelector(".select-product-name");
        const dropdown = container.querySelector(".autocomplete-dropdown");
        const indicator = row.querySelector(".new-product-indicator");
        const barcodeInput = row.querySelector(".barcode-input");
        const salePriceInput = row.querySelector(".sale-price-input");
        const categoryInput = row.querySelector(".category-input");
        const categoryHidden = row.querySelector(".select-category");

        input.value = product.name;
        hiddenInput.value = product.id;
        nameInput.value = product.name;
        hiddenInput.setAttribute("data-base-buy-price", product.buy_price);

        const conversionFactor = parseFloat(product.conversion_factor) || 1.0;
        row.querySelector(".conversion-factor").value = conversionFactor;
        row.querySelector(".unit-name").value = product.unit_name || "الوحدة الأساسية";
        row.querySelector(".unit-display-input").value = product.unit_name || "الوحدة الأساسية";
        row.querySelector(".unit-id").value = product.unit_id || "";

        // ملء الباركود
        if (product.barcode) {
            barcodeInput.value = product.barcode;
        }

        // ملء التصنيف
        if (product.catid) {
            categoryHidden.value = product.catid;
            const cat = categoriesList.find(c => c.catid == product.catid);
            if (cat) {
                categoryInput.value = cat.name;
            }
        }

        const rate = parseFloat(exchangeRateInput.value) || 1.0;
        const buyPriceConverted = (product.buy_price / rate).toFixed(2);

        row.querySelector(".price-input").value = buyPriceConverted;
        row.querySelector(".quantity-input").value = 1;

        // اقتراح سعر البيع
        const suggestedSalePrice = (product.buy_price * 1.25 / rate).toFixed(2);
        salePriceInput.value = suggestedSalePrice;

        dropdown.classList.add("d-none");
        dropdown.innerHTML = "";

        if (indicator) indicator.classList.add("d-none");

        updateRowCalculations(row);

        setTimeout(() => {
            const qtyInput = row.querySelector(".quantity-input");
            if (qtyInput) {
                qtyInput.focus();
                qtyInput.select();
            }
        }, 100);
    }

    function selectNewProduct(row, newName) {
        const container = row.querySelector(".product-search-container");
        const input = container.querySelector(".product-search-input");
        const hiddenInput = container.querySelector(".select-product");
        const nameInput = container.querySelector(".select-product-name");
        const dropdown = container.querySelector(".autocomplete-dropdown");
        const indicator = row.querySelector(".new-product-indicator");

        input.value = newName;
        hiddenInput.value = "-1";
        nameInput.value = newName;

        dropdown.classList.add("d-none");
        dropdown.innerHTML = "";

        if (indicator) indicator.classList.remove("d-none");

        row.querySelector(".price-input").value = "";
        row.querySelector(".quantity-input").value = 1;

        updateRowCalculations(row);

        setTimeout(() => {
            const priceInput = row.querySelector(".price-input");
            if (priceInput) {
                priceInput.focus();
                priceInput.select();
            }
        }, 100);
    }

    function selectBarcodeProduct(row, product) {
        selectProductForRow(row, product);
        const container = row.querySelector(".barcode-search-container");
        const dropdown = container.querySelector(".autocomplete-dropdown");
        dropdown.classList.add("d-none");
        dropdown.innerHTML = "";
    }

    function selectCategory(row, catId, catName) {
        const container = row.querySelector(".category-search-container");
        const input = container.querySelector(".category-input");
        const hiddenInput = container.querySelector(".select-category");
        const dropdown = container.querySelector(".autocomplete-dropdown");

        input.value = catName;
        hiddenInput.value = catId;

        if (catId == "-1") {
            if (!categoriesList.some(cat => cat.name.toLowerCase() === catName.toLowerCase())) {
                categoriesList.push({ catid: "-1", name: catName });
            }
        }

        dropdown.classList.add("d-none");
        dropdown.innerHTML = "";
    }

    // معالجة النقر على عناصر autocomplete
    itemsContainer.addEventListener("click", function(e) {
        const item = e.target.closest(".autocomplete-item");
        if (item) {
            const row = item.closest(".item-row");
            const productId = item.getAttribute("data-id");
            
            // تحديد نوع الـ autocomplete
            if (item.closest(".product-search-container")) {
                if (productId == "-1") {
                    const newName = item.getAttribute("data-new-name") || row.querySelector(".product-search-input").value.trim();
                    selectNewProduct(row, newName);
                } else {
                    const productData = item.getAttribute("data-product");
                    if (productData) {
                        const product = JSON.parse(productData);
                        selectProductForRow(row, product);
                    }
                }
            } else if (item.closest(".barcode-search-container")) {
                const productData = item.getAttribute("data-product");
                if (productData) {
                    const product = JSON.parse(productData);
                    selectBarcodeProduct(row, product);
                }
            } else if (item.closest(".category-search-container")) {
                const catId = item.getAttribute("data-id");
                const catName = item.getAttribute("data-name");
                selectCategory(row, catId, catName);
            }
        }
    });

    // التنقل بـ Enter
    itemsContainer.addEventListener("keydown", function(e) {
        if (e.key === "Enter") {
            e.preventDefault();
            const row = e.target.closest(".item-row");
            if (!row) return;

            if (e.target.matches(".product-search-input")) {
                const container = e.target.closest(".product-search-container");
                const dropdown = container.querySelector(".autocomplete-dropdown");
                const activeItem = dropdown.querySelector(".autocomplete-item.active");
                if (activeItem) {
                    activeItem.click();
                } else {
                    const quantityInput = row.querySelector(".quantity-input");
                    if (quantityInput) {
                        quantityInput.focus();
                        quantityInput.select();
                    }
                }
            } else if (e.target.matches(".barcode-input")) {
                const container = e.target.closest(".barcode-search-container");
                const dropdown = container.querySelector(".autocomplete-dropdown");
                const activeItem = dropdown.querySelector(".autocomplete-item.active");
                if (activeItem) {
                    activeItem.click();
                } else {
                    const categoryInput = row.querySelector(".category-input");
                    if (categoryInput) {
                        categoryInput.focus();
                        categoryInput.select();
                    }
                }
            } else if (e.target.matches(".category-input")) {
                const container = e.target.closest(".category-search-container");
                const dropdown = container.querySelector(".autocomplete-dropdown");
                const activeItem = dropdown.querySelector(".autocomplete-item.active");
                if (activeItem) {
                    activeItem.click();
                } else {
                    const typedVal = e.target.value.trim();
                    if (typedVal) {
                        selectCategory(row, "-1", typedVal);
                    }
                    const quantityInput = row.querySelector(".quantity-input");
                    if (quantityInput) {
                        quantityInput.focus();
                        quantityInput.select();
                    }
                }
            } else if (e.target.matches(".quantity-input")) {
                const priceInput = row.querySelector(".price-input");
                if (priceInput) {
                    priceInput.focus();
                    priceInput.select();
                }
            } else if (e.target.matches(".price-input")) {
                const salePriceInput = row.querySelector(".sale-price-input");
                if (salePriceInput) {
                    salePriceInput.focus();
                    salePriceInput.select();
                }
            } else if (e.target.matches(".sale-price-input")) {
                let nextRow = row.nextElementSibling;
                if (!nextRow || !nextRow.classList.contains("item-row")) {
                    addItemBtn.click();
                    nextRow = itemsContainer.querySelector(".item-row:last-child");
                }
                setTimeout(() => {
                    const nextSearch = nextRow.querySelector(".product-search-input");
                    if (nextSearch) {
                        nextSearch.focus();
                        nextSearch.select();
                    }
                }, 100);
            }
        }
    });

    document.addEventListener("click", function(e) {
        if (!e.target.closest(".product-search-container") && 
            !e.target.closest(".barcode-search-container") && 
            !e.target.closest(".category-search-container")) {
            document.querySelectorAll(".autocomplete-dropdown").forEach(d => d.classList.add("d-none"));
        }
    });

    // معالجة تغيير العملة
    function convertAllRowsToNewCurrency() {
        const rate = parseFloat(exchangeRateInput.value) || 1.0;
        document.querySelectorAll(".item-row").forEach(function(row) {
            const selectProd = row.querySelector(".select-product");
            const baseBuyPrice = parseFloat(selectProd.getAttribute("data-base-buy-price")) || 0;

            if (baseBuyPrice > 0) {
                const newBuyPrice = baseBuyPrice / rate;
                row.querySelector(".price-input").value = newBuyPrice.toFixed(2);
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

        document.querySelectorAll(".currency-symbol").forEach(el => el.textContent = symbol);
        convertAllRowsToNewCurrency();
    });

    // ========== التحقق النهائي ==========
    document.getElementById("purchaseForm").addEventListener("submit", function(e) {
        let isValid = true;

        const payFromBox = document.getElementById("payFromBox").checked;
        const invoiceType = document.getElementById("invoiceTypeSelect").value;
        
        if (payFromBox) {
            let boxBalance = 0;
            const boxSelect = document.getElementById("boxSelect");
            const userBoxId = document.getElementById("userBoxId");

            if (boxSelect && boxSelect.selectedIndex >= 0) {
                const selectedOption = boxSelect.options[boxSelect.selectedIndex];
                boxBalance = parseFloat(selectedOption.getAttribute("data-balance")) || 0;
            } else if (userBoxId) {
                boxBalance = parseFloat(userBoxId.getAttribute("data-balance")) || 0;
            }

            const totalPaid = parseFloat(document.getElementById("totalPaidInput").value) || 0;

            if (totalPaid > boxBalance) {
                alert("رصيد الصندوق غير كافٍ! الرصيد المتاح: " + boxBalance.toFixed(2) + " ر.ي\nالمبلغ المطلوب: " + totalPaid.toFixed(2) + " ر.ي\n\nيرجى تقليل المبلغ المدفوع أو إلغاء خيار 'خصم المدفوع من الصندوق'.");
                e.preventDefault();
                return false;
            }
        }

        let hasProducts = false;
        document.querySelectorAll(".item-row").forEach(row => {
            const name = row.querySelector(".select-product-name").value;
            if (name && name.trim() !== "") hasProducts = true;
        });
        if (!hasProducts) {
            alert("يجب إضافة صنف واحد على الأقل!");
            e.preventDefault();
            return false;
        }

        document.querySelectorAll(".item-row").forEach(row => {
            const name = row.querySelector(".product-search-input").value;
            const qty = parseInt(row.querySelector(".quantity-input").value) || 0;
            const price = parseFloat(row.querySelector(".price-input").value) || 0;

            if (qty <= 0 && name) {
                alert(`خطأ في "${name}": الكمية يجب أن تكون أكبر من صفر!`);
                isValid = false;
            }
            if (price <= 0 && name) {
                alert(`خطأ في "${name}": السعر يجب أن يكون أكبر من صفر!`);
                isValid = false;
            }
        });

        if (!isValid) {
            e.preventDefault();
            return false;
        }
    });

    // فتح المودال عند النقر على حقل البحث
    itemsContainer.addEventListener("click", function(e) {
        if (e.target.matches(".product-search-input")) {
            const row = e.target.closest(".item-row");
            window.activeSearchRow = row;

            const selectProduct = row.querySelector(".select-product");
            if (selectProduct && selectProduct.value && selectProduct.value !== "-1") {
                return;
            }

            showProductAutocompleteDropdown(e.target);
        } else if (e.target.matches(".category-input")) {
            const row = e.target.closest(".item-row");
            window.activeSearchRow = row;
            showCategoryAutocompleteDropdown(e.target);
        }
    });
});
</script>

<!-- مودال إضافة مورد -->
<div class="modal fade" id="quickAddSupplierModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="fa fa-user-plus ml-2"></i>إضافة مورد جديد</h5>
                <button type="button" class="close text-white" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <div class="modal-body text-right" dir="rtl">
                <div class="alert alert-danger d-none" id="quickAddSupplierError"></div>
                <form id="quickAddSupplierForm">
                    <div class="row">
                        <div class="col-md-6">
                            <label class="font-weight-bold">اسم المورد *</label>
                            <input type="text" class="form-control" name="supp_name" required>
                        </div>
                        <div class="col-md-6">
                            <label class="font-weight-bold">رقم الجوال *</label>
                            <input type="text" class="form-control" name="phone" required>
                        </div>
                        <div class="col-md-6">
                            <label class="font-weight-bold">اسم الشركة</label>
                            <input type="text" class="form-control" name="company_name">
                        </div>
                        <div class="col-md-6">
                            <label class="font-weight-bold">البريد الإلكتروني</label>
                            <input type="email" class="form-control" name="email">
                        </div>
                        <div class="col-12">
                            <label class="font-weight-bold">العنوان</label>
                            <input type="text" class="form-control" name="address">
                        </div>
                        <div class="col-12">
                            <label class="font-weight-bold">ملاحظات</label>
                            <textarea class="form-control" name="notes" rows="2"></textarea>
                        </div>
                    </div>
                    <input type="hidden" name="ajax_add_supplier" value="1">
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">إلغاء</button>
                <button type="submit" form="quickAddSupplierForm" class="btn btn-primary">حفظ</button>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function() {
    const form = document.getElementById("quickAddSupplierForm");
    const errorDiv = document.getElementById("quickAddSupplierError");

    if (form) {
        form.addEventListener("submit", function(e) {
            e.preventDefault();
            errorDiv.classList.add("d-none");

            const formData = new FormData(form);

            fetch(window.location.href, {
                method: "POST",
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.status === "success") {
                    alert("تم حفظ المورد بنجاح واختياره تلقائياً!");
                    const supplierSelect = document.getElementById("supplierSelect");
                    if (supplierSelect) {
                        const opt = document.createElement("option");
                        opt.value = data.name;
                        opt.text = data.name;
                        opt.selected = true;
                        supplierSelect.add(opt);
                        supplierSelect.value = data.name;
                    }

                    const modalEl = document.getElementById('quickAddSupplierModal');
                    if (modalEl) {
                        if (typeof $ !== 'undefined' && typeof $.fn.modal === 'function') {
                            $(modalEl).modal('hide');
                        }
                        modalEl.classList.remove('show');
                        modalEl.setAttribute('aria-hidden', 'true');
                        modalEl.style.display = 'none';
                        const backdrop = document.querySelector('.modal-backdrop');
                        if (backdrop) backdrop.remove();
                        document.body.classList.remove('modal-open');
                        document.body.style.paddingRight = '';
                    }
                    form.reset();
                } else {
                    errorDiv.innerText = data.message;
                    errorDiv.classList.remove("d-none");
                }
            })
            .catch(err => {
                console.error(err);
                errorDiv.innerText = "حدث خطأ أثناء إرسال البيانات.";
                errorDiv.classList.remove("d-none");
            });
        });
    }
});
</script>

<!-- اختصارات F-Keys -->
<script>
document.addEventListener('keydown', function(e) {
    if (e.key === 'F2') {
        e.preventDefault();
        const barcodeInput = document.getElementById('barcodeScanInput');
        if (barcodeInput) {
            barcodeInput.focus();
            barcodeInput.select();
        }
    }
    if (e.key === 'F4') {
        e.preventDefault();
        const supplierSelect = document.getElementById('supplierSelect');
        if (supplierSelect) {
            supplierSelect.focus();
        }
    }
    if (e.key === 'F10') {
        e.preventDefault();
        const form = document.getElementById('purchaseForm');
        if (form) {
            if (form.checkValidity()) {
                form.submit();
            } else {
                form.reportValidity();
            }
        }
    }
});
</script>

<?php require_once($dir_prefix . 'includes/footer.php'); ?>