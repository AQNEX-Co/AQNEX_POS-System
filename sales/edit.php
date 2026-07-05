<?php
$dir_prefix = '../';
$module = 'sales';

// معالجة إضافة العميل السريع عبر AJAX
if (isset($_POST['ajax_add_customer'])) {
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

    $cust_name = $conn->real_escape_string(trim($_POST['cust_name']));
    $phone = $conn->real_escape_string(trim($_POST['phone']));
    $email = $conn->real_escape_string(trim($_POST['email']));
    $address = $conn->real_escape_string(trim($_POST['address']));
    $credit_limit = doubleval($_POST['credit_limit']);
    $notes = $conn->real_escape_string(trim($_POST['notes']));

    if (empty($cust_name) || empty($phone)) {
        echo json_encode(['status' => 'error', 'message' => 'اسم العميل ورقم الجوال مطلوبان.']);
        exit;
    }

    $chk = $conn->query("SELECT cust_id FROM customers WHERE cust_name = '$cust_name' AND d_s = 0");
    if ($chk && $chk->num_rows > 0) {
        echo json_encode(['status' => 'error', 'message' => 'اسم العميل مسجل بالفعل.']);
        exit;
    }

    $sql = "INSERT INTO customers (cust_name, phone, email, address, credit_limit, notes, sale_date) 
            VALUES ('$cust_name', '$phone', '$email', '$address', $credit_limit, '$notes', '$today')";
    if ($conn->query($sql)) {
        $new_id = $conn->insert_id;
        echo json_encode(['status' => 'success', 'id' => $new_id, 'name' => $cust_name]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'خطأ أثناء الإضافة: ' . $conn->error]);
    }
    exit;
}

require_once($dir_prefix . 'includes/header.php');
@include_once($dir_prefix . 'includes/modules.php');

check_permission(['admin', 'cashier']);

$active_user_id = intval($_SESSION['SESS_MEMBER_ID']);
$active_user_role = trim($_SESSION['SESS_LAST_NAME']);
$is_admin = ($active_user_role === 'admin' || empty($active_user_role));

$settings = $global_settings;
$save_error = '';

// ==========================================
// جلب رقم الفاتورة المراد تعديلها
// ==========================================
$invoice_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($invoice_id <= 0) {
    echo "<script>alert('رقم الفاتورة غير صحيح.'); window.location='index.php';</script>";
    exit;
}

// جلب بيانات الفاتورة الرئيسية
$res_inv = $conn->query("SELECT * FROM sales WHERE id = $invoice_id AND delete_status = 0 LIMIT 1");
if (!$res_inv || $res_inv->num_rows === 0) {
    echo "<script>alert('الفاتورة غير موجودة أو تم حذفها.'); window.location='index.php';</script>";
    exit;
}
$invoice = $res_inv->fetch_assoc();

// جلب بنود الفاتورة
$invoice_items = [];
$res_items = $conn->query("SELECT * FROM sales_items WHERE sales_id = $invoice_id ORDER BY id ASC");
if ($res_items) {
    while ($item = $res_items->fetch_assoc()) {
        $invoice_items[] = $item;
    }
}

// جلب العملات
$currencies_list = [];
$res_curr = $conn->query("SELECT * FROM currencies ORDER BY id ASC");
if ($res_curr) {
    while($c = $res_curr->fetch_assoc()) {
        $currencies_list[] = $c;
    }
}

// ==========================================
// معالجة حفظ التعديلات
// ==========================================
if (isset($_POST['btn_update'])) {
    $build_date = date('Y-m-d', strtotime($_POST['build_date']));
    $customer_name = $conn->real_escape_string($_POST['customer_name']);
    $grand_paid = doubleval($_POST['grand_paid']);
    $grand_profit = doubleval($_POST['grand_profit']);
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

    // بيانات البنود الجديدة
    $products = $_POST['product_id'];
    $quantities = $_POST['quantity'];
    $unit_prices = $_POST['unit_price'];
    $paids = $_POST['paid_amount'];
    $discounts = $_POST['discount_amount'];
    $remainings = $_POST['remaining_amount'];
    $buy_prices = $_POST['buy_price'];

    // حساب المجاميع الجديدة
    $new_total_paid_base = 0;
    $new_total_remaining_base = 0;
    $new_total_discount_base = 0;
    $new_total_cost_base = 0;
    $count = count($products);

    for ($i = 0; $i < $count; $i++) {
        $p_id = intval($products[$i]);
        $qty = intval($quantities[$i]);
        if ($p_id > 0 && $qty > 0) {
            $new_total_paid_base += doubleval($paids[$i]) * $exchange_rate;
            $new_total_remaining_base += doubleval($remainings[$i]) * $exchange_rate;
            $new_total_discount_base += doubleval($discounts[$i]) * $exchange_rate;
            $new_total_cost_base += ($qty * doubleval($buy_prices[$i])) * $exchange_rate;
        }
    }

    $new_grand_profit_base = $grand_profit * $exchange_rate;
    $new_invoice_total_base = $new_total_paid_base + $new_total_remaining_base;

    // حساب المجاميع القديمة من الفاتورة الأصلية
    $old_total_paid_base = doubleval($invoice['total']) - doubleval($invoice['remaining_total']);
    $old_total_remaining_base = doubleval($invoice['remaining_total']);
    $old_invoice_total_base = doubleval($invoice['total']);
    $old_box_id = intval($invoice['box_id']);

    // التحقق من رصيد الصندوق
    if ($new_total_paid_base > 0) {
        $box_balance = get_box_balance($conn, $selected_box_id);
        // إضافة المبلغ القديم الذي سيتم إرجاعه
        $available_balance = $box_balance;
        if ($old_box_id == $selected_box_id && $old_total_paid_base > 0) {
            $available_balance += $old_total_paid_base;
        }
        if ($available_balance < $new_total_paid_base) {
            $save_error = "لا يمكن إتمام العملية لأن رصيد الصندوق المحدد (" . number_format($box_balance, 2) . ") أقل من إجمالي المقبوضات الجديد (" . number_format($new_total_paid_base, 2) . ").";
        }
    }

    // التحقق من حد الائتمان
    if (!empty($customer_name) && $customer_name !== 'عميل نقدي') {
        $cust_res = $conn->query("SELECT credit_limit, cust_madeen FROM customers WHERE cust_name = '$customer_name' AND d_s = 0 LIMIT 1");
        if ($cust_res && $cust_res->num_rows > 0) {
            $cust_row = $cust_res->fetch_assoc();
            $credit_limit = doubleval($cust_row['credit_limit']);
            $current_balance = doubleval($cust_row['cust_madeen']);
            // طرح المديونية القديمة
            $adjusted_balance = $current_balance - $old_total_remaining_base;
            if ($credit_limit > 0) {
                $new_balance = $adjusted_balance + $new_total_remaining_base;
                if ($new_balance > $credit_limit) {
                    $save_error = "لا يمكن إتمام العملية لأن مديونية العميل بعد هذه الفاتورة (" . number_format($new_balance,2) . ") ستتجاوز حد الائتمان المحدد (" . number_format($credit_limit,2) . ").";
                }
            }
        }
    }

    if (empty($save_error)) {
        $conn->begin_transaction();
        try {
            $user_display = $_SESSION['SESS_FIRST_NAME'];

            // ==========================================
            // المرحلة 1: عكس العمليات القديمة
            // ==========================================

            // 1.1 إعادة كميات المخزون القديمة
            $old_items_res = $conn->query("SELECT * FROM sales_items WHERE sales_id = $invoice_id");
            if ($old_items_res) {
                while ($old_item = $old_items_res->fetch_assoc()) {
                    $old_p_id = intval($old_item['id']);
                    $old_qty = intval($old_item['quantity']);
                    
                    // استخراج عامل التحويل
                    $old_conv_factor = 1.0;
                    if (preg_match('/\(([^)]+)\)/', $old_item['name'], $matches)) {
                        $old_unit_name = trim($matches[1]);
                        $unit_res = $conn->query("SELECT conversion_factor FROM product_units WHERE product_id = $old_p_id AND unit_name = '" . $conn->real_escape_string($old_unit_name) . "' LIMIT 1");
                        if ($unit_res && $unit_res->num_rows > 0) {
                            $old_conv_factor = doubleval($unit_res->fetch_assoc()['conversion_factor']);
                            if ($old_conv_factor <= 0) $old_conv_factor = 1.0;
                        }
                    }
                    $old_base_qty = $old_qty * $old_conv_factor;

                    // إعادة الكمية للمخزن
                    $conn->query("UPDATE products SET quantity = quantity + $old_base_qty WHERE id = $old_p_id");

                    // تسجيل حركة المخزون العكسية
                    $conn->query("INSERT INTO inventory_log (product_id, product_name, type, qty_change, new_qty, reason, user)
                                  SELECT id, name, 'sale_edit_cancel', $old_base_qty, quantity, 'إلغاء تعديل فاتورة #$invoice_id - استرجاع الكمية', '$user_display'
                                  FROM products WHERE id = $old_p_id LIMIT 1");

                    // إعادة دفعات الصلاحية
                    if (is_module_enabled('expiry_tracking')) {
                        $conn->query("UPDATE product_batches SET quantity = quantity + $old_base_qty WHERE product_id = $old_p_id AND d_s = '0'");
                    }

                    // إعادة الأرقام التسلسلية
                    if (is_module_enabled('serial_imei_tracking')) {
                        $conn->query("UPDATE product_serials SET status = 'available', sale_item_id = NULL WHERE sale_item_id = " . intval($old_item['id']));
                    }
                }
            }

            // 1.2 إعادة المديونية القديمة للعميل
            $old_customer = $invoice['cust_name'];
            if (!empty($old_customer) && $old_customer !== 'عميل نقدي' && $old_total_remaining_base > 0) {
                $old_cust_esc = $conn->real_escape_string($old_customer);
                $conn->query("UPDATE customers SET cust_madeen = GREATEST(0, cust_madeen - $old_total_remaining_base) WHERE cust_name = '$old_cust_esc'");
            }

            // 1.3 إرجاع المبلغ القديم إلى الصندوق
            if ($old_total_paid_base > 0) {
                update_box_balance($conn, $old_box_id, $old_total_paid_base, 'discount', "إلغاء إيداع فاتورة #$invoice_id للتعديل", date('Y-m-d'));
            }

            // 1.4 حذف القيود المحاسبية القديمة
            $conn->query("DELETE FROM accounting_journal WHERE ref_type = 'sale' AND ref_id = $invoice_id");
            $conn->query("DELETE FROM journal_entries WHERE ref_type = 'sale' AND ref_id = $invoice_id");

            // 1.5 حذف بنود الفاتورة القديمة
            $conn->query("DELETE FROM sales_items WHERE sales_id = $invoice_id");

            // ==========================================
            // المرحلة 2: إدراج البيانات الجديدة
            // ==========================================

            // 2.1 تحديث رأس الفاتورة
            $sql_update = "UPDATE `sales` SET 
                `build_date` = '$build_date',
                `cust_name` = '$customer_name',
                `total` = '$new_invoice_total_base',
                `prifet` = '$new_grand_profit_base',
                `remark` = '$remark',
                `currency_code` = '$currency_code',
                `exchange_rate` = '$exchange_rate',
                `remaining_total` = '$new_total_remaining_base',
                `box_id` = $active_box_id,
                `invoice_type` = '$invoice_type',
                `payment_method` = '$payment_method',
                `wallet_type` = '$wallet_type'
                WHERE id = $invoice_id";

            if (!$conn->query($sql_update)) {
                throw new Exception("فشل تحديث رأس الفاتورة");
            }

            // 2.2 إدراج البنود الجديدة
            for ($i = 0; $i < $count; $i++) {
                $p_id = intval($products[$i]);
                $qty = intval($quantities[$i]);
                $price = doubleval($unit_prices[$i]);
                $paid = doubleval($paids[$i]);
                $disc = doubleval($discounts[$i]);
                $rem = doubleval($remainings[$i]);

                if ($p_id > 0 && $qty > 0) {
                    $sql_p = "SELECT name, track_expiry FROM products WHERE id = $p_id";
                    $res_p = $conn->query($sql_p);
                    if (!$res_p || $res_p->num_rows == 0) {
                        throw new Exception("الصنف غير موجود أو تم حذفه");
                    }
                    $p_row = $res_p->fetch_assoc();
                    $product_name_db = $conn->real_escape_string($p_row['name']);

                    $conv_factor = isset($_POST['conversion_factor'][$i]) ? doubleval($_POST['conversion_factor'][$i]) : 1.0;
                    if ($conv_factor <= 0) $conv_factor = 1.0;
                    $unit_name = isset($_POST['unit_name'][$i]) ? trim($_POST['unit_name'][$i]) : '';

                    $product_field_val = "$p_id $product_name_db";
                    if (!empty($unit_name) && $unit_name !== 'الوحدة الأساسية') {
                        $product_field_val .= " ($unit_name)";
                    }

                    $price_base = $price * $exchange_rate;
                    $paid_base = $paid * $exchange_rate;
                    $disc_base = $disc * $exchange_rate;
                    $rem_base = $rem * $exchange_rate;
                    $line_total_base = ($qty * $price) * $exchange_rate;
                    $line_net_base = $paid_base + $rem_base;

                    $sql_item = "INSERT INTO `sales_items`(`sales_id`, `id`, `cust_name`, `name`, `quantity`, `unit_price`, `bush`, `d`, `dis`, `total`, `all_tot`, `build_date`) 
                                 VALUES ('$invoice_id', '$p_id', '$customer_name', '$product_field_val', '$qty', '$price_base', '$paid_base', '$disc_base', '$rem_base', '$line_net_base', '$line_total_base', '$build_date')";
                    if (!$conn->query($sql_item)) {
                        throw new Exception("فشل إدراج الصنف: " . $product_name_db);
                    }
                    $sales_item_id = $conn->insert_id;

                    // خصم المخزون
                    $base_qty = $qty * $conv_factor;
                    $conn->query("UPDATE products SET quantity = quantity - $base_qty WHERE id = $p_id");

                    $conn->query("INSERT INTO inventory_log (product_id, product_name, type, qty_change, new_qty, reason, user)
                                  SELECT id, name, 'sale_edit', -$base_qty, quantity, 'تعديل فاتورة مبيعات #$invoice_id', '$user_display'
                                  FROM products WHERE id = $p_id");

                    // تحديث دفعات الصلاحية
                    if (is_module_enabled('expiry_tracking') && intval($p_row['track_expiry']) === 1) {
                        $batch_id = isset($_POST['batch_id'][$i]) ? intval($_POST['batch_id'][$i]) : 0;
                        if ($batch_id > 0) {
                            $conn->query("UPDATE product_batches SET quantity = quantity - $base_qty WHERE id = $batch_id");
                        } else {
                            $rem_to_deduct = $base_qty;
                            $res_batches = $conn->query("SELECT id, quantity FROM product_batches WHERE product_id = $p_id AND quantity > 0 AND d_s = '0' ORDER BY expiry_date ASC, id ASC");
                            if ($res_batches) {
                                while ($b_row = $res_batches->fetch_assoc()) {
                                    if ($rem_to_deduct <= 0) break;
                                    $b_id = intval($b_row['id']);
                                    $b_qty = doubleval($b_row['quantity']);
                                    if ($b_qty >= $rem_to_deduct) {
                                        $conn->query("UPDATE product_batches SET quantity = quantity - $rem_to_deduct WHERE id = $b_id");
                                        $rem_to_deduct = 0;
                                    } else {
                                        $conn->query("UPDATE product_batches SET quantity = 0 WHERE id = $b_id");
                                        $rem_to_deduct -= $b_qty;
                                    }
                                }
                            }
                        }
                    }

                    // تحديث الأرقام التسلسلية
                    if (is_module_enabled('serial_imei_tracking')) {
                        $serial_ids_str = isset($_POST['serial_ids'][$i]) ? trim($_POST['serial_ids'][$i]) : '';
                        if (!empty($serial_ids_str)) {
                            $serial_ids = explode(',', $serial_ids_str);
                            foreach ($serial_ids as $s_id) {
                                $s_id = intval($s_id);
                                if ($s_id > 0) {
                                    $conn->query("UPDATE product_serials SET status = 'sold', sale_item_id = $sales_item_id WHERE id = $s_id");
                                }
                            }
                        }
                    }

                    // تحديث مديونية العميل
                    if (!empty($customer_name) && $customer_name !== 'عميل نقدي' && $rem_base > 0) {
                        $conn->query("UPDATE customers SET cust_madeen = cust_madeen + $rem_base WHERE cust_name = '$customer_name'");
                    }
                }
            }

            // 2.3 تسجيل القيود المحاسبية الجديدة
             if ($new_total_paid_base > 0) {
                post_journal_entry($conn, 'sale', $invoice_id, 'الصندوق - ' . $box_name, 'المبيعات', $new_total_paid_base, "مبيعات نقدية فاتورة #$invoice_id (معدل) - $customer_name", $user_display, $active_box_id, $currency_code, $exchange_rate, null);
             }

            if ($new_total_remaining_base > 0) {
                post_journal_entry($conn, 'sale', $invoice_id, 'الذمم المدينة - ' . $customer_name, 'المبيعات', $new_total_remaining_base, "مبيعات آجل فاتورة #$invoice_id (معدل) - $customer_name", $user_display, $active_box_id, $currency_code, $exchange_rate, null);
            }

            if ($new_total_discount_base > 0) {
                post_journal_entry($conn, 'sale', $invoice_id, 'الخصم المسموح به (مصروف)', 'المبيعات', $new_total_discount_base, "خصم مبيعات فاتورة #$invoice_id (معدل) - $customer_name", $user_display, $active_box_id, $currency_code, $exchange_rate, null);
            }

            if ($new_total_cost_base > 0) {
                post_journal_entry($conn, 'sale', $invoice_id, 'تكلفة البضاعة المباعة (مصروف)', 'المخزون / البضاعة', $new_total_cost_base, "إثبات تكلفة مبيعات فاتورة #$invoice_id (معدل)", $user_display, $active_box_id, $currency_code, $exchange_rate, null);
            }

            // 2.4 إيداع المبلغ الجديد في الصندوق
            if ($new_total_paid_base > 0) {
                update_box_balance($conn, $active_box_id, $new_total_paid_base, 'addition', "إيداع مبيعات نقدية فاتورة #$invoice_id (معدل)", $build_date);
                $conn->query("UPDATE sales SET is_transferred_to_box = 1 WHERE id = $invoice_id");
            }

            $conn->commit();

            echo "<script>window.location='view.php?id=$invoice_id&edited=1';</script>";
            exit;
        } catch (Exception $e) {
            $conn->rollback();
            $save_error = 'فشل حفظ التعديلات: ' . $e->getMessage();
        }
    }
}

$products_json = '[]';
?>
<title>تعديل فاتورة مبيعات #<?php echo $invoice_id; ?></title>

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
.invoice-summary .total-value.text-danger {
    color: #dc3545 !important;
}
.invoice-summary .total-value.text-success {
    color: #28a745 !important;
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

.edit-banner {
    background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
    color: #fff;
    padding: 12px 20px;
    border-radius: 6px;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 12px;
    font-weight: 600;
}
.edit-banner i {
    font-size: 1.5rem;
}
</style>

<!-- Loading Overlay -->
<div class="loading-overlay" id="loadingOverlay">
    <div class="loading-spinner"></div>
    <div class="loading-text">جاري التحميل...</div>
</div>

<!-- شريط التعديل -->
<div class="edit-banner no-print">
    <i class="bi bi-pencil-square"></i>
    <div>
        <div>تعديل فاتورة مبيعات رقم #<?php echo $invoice_id; ?></div>
        <small style="opacity: 0.9;">تاريخ الفاتورة الأصلي: <?php echo $invoice['build_date']; ?> | العميل: <?php echo htmlspecialchars($invoice['cust_name']); ?></small>
    </div>
</div>

<div class="card-flat">
    <div class="card-header">
        <h5><?php echo get_icon('edit', 'ml-2 text-warning'); ?> تعديل فاتورة المبيعات #<?php echo $invoice_id; ?></h5>
        <div>
            <a href="view.php?id=<?php echo $invoice_id; ?>" class="btn-flat btn-flat-secondary btn-sm text-decoration-none">
                <?php echo get_icon('eye', 'ml-1'); ?> عرض الفاتورة
            </a>
            <a href="index.php" class="btn-flat btn-flat-secondary btn-sm text-decoration-none">
                <?php echo get_icon('logout', 'ml-1'); ?> عودة
            </a>
        </div>
    </div>
    <div class="card-body">
        <?php if (!empty($save_error)): ?>
            <div class="alert alert-danger rounded-0 mb-4">
                <strong>خطأ في حفظ التعديلات:</strong> <?php echo htmlspecialchars($save_error); ?>
            </div>
        <?php endif; ?>

        <div class="alert alert-warning rounded-0 mb-4 no-print">
            <strong><i class="bi bi-exclamation-triangle ml-1"></i> تنبيه هام:</strong>
            سيتم إلغاء جميع العمليات القديمة المرتبطة بهذه الفاتورة (المخزون، الصندوق، مديونية العميل، القيود المحاسبية) وإعادة تنفيذها بالبيانات الجديدة.
        </div>

        <form method="POST" id="salesForm">
            <!-- ======================== الصف الأول ======================== -->
            <div class="row mb-3">
                <div class="col-md-2 col-sm-6 mb-3">
                    <label class="form-label font-weight-bold text-secondary">تاريخ البيع</label>
                    <input type="date" name="build_date" class="form-control rounded-0" value="<?php echo date('Y-m-d', strtotime($invoice['build_date'])); ?>" required>
                </div>

                <div class="col-md-3 col-sm-6 mb-3">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <label class="form-label font-weight-bold text-secondary mb-0">العميل</label>
                        <a href="javascript:void(0)" class="small font-weight-bold text-decoration-none" data-toggle="modal" data-target="#quickAddCustomerModal">
                            <i class="fa fa-plus-circle ml-1"></i>عميل جديد
                        </a>
                    </div>
                    <select name="customer_name" id="select2" class="form-control rounded-0" required>
                        <option value="">-- اختر عميل --</option>
                        <option value="عميل نقدي" <?php echo ($invoice['cust_name'] === 'عميل نقدي') ? 'selected' : ''; ?>>عميل نقدي</option>
                        <?php
                        $sql_cust = "SELECT cust_name FROM customers WHERE d_s = 0 ORDER BY cust_id DESC";
                        $res_cust = $conn->query($sql_cust);
                        if ($res_cust) {
                            while($row = $res_cust->fetch_assoc()) {
                                $sel = ($row['cust_name'] === $invoice['cust_name']) ? 'selected' : '';
                                echo "<option value='".htmlspecialchars($row['cust_name'])."' $sel>".htmlspecialchars($row['cust_name'])."</option>";
                            }
                        }
                        ?>
                    </select>
                </div>

                <div class="col-md-2 col-sm-6 mb-3">
                    <label class="form-label font-weight-bold text-secondary">نوع الفاتورة</label>
                    <select name="invoice_type" id="invoiceTypeSelect" class="form-control rounded-0" onchange="toggleSalesInvoiceType(this.value)" required>
                        <option value="cash" <?php echo ($invoice['invoice_type'] === 'cash') ? 'selected' : ''; ?>>نقد</option>
                        <option value="credit" <?php echo ($invoice['invoice_type'] === 'credit') ? 'selected' : ''; ?>>آجل</option>
                        <option value="account" <?php echo ($invoice['invoice_type'] === 'account') ? 'selected' : ''; ?>>من حساب</option>
                    </select>
                </div>

                <div class="col-md-2 col-sm-6 mb-3">
                    <label class="form-label font-weight-bold text-secondary">عملة الفاتورة</label>
                    <select name="currency_code" id="currencySelect" class="form-control rounded-0" required>
                        <?php 
                        $inv_currency = $invoice['currency_code'] ?? 'YER';
                        foreach($currencies_list as $c): 
                            $sel = ($c['code'] === $inv_currency) ? 'selected' : '';
                        ?>
                            <option value="<?php echo htmlspecialchars($c['code']); ?>" data-rate="<?php echo $c['exchange_rate']; ?>" data-symbol="<?php echo htmlspecialchars($c['symbol']); ?>" <?php echo $sel; ?>>
                                <?php echo htmlspecialchars($c['name']); ?> (<?php echo htmlspecialchars($c['symbol']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-2 col-sm-6 mb-3">
                    <label class="form-label font-weight-bold text-secondary">سعر الصرف</label>
                    <input type="number" step="any" name="exchange_rate" id="exchangeRateInput" class="form-control rounded-0 font-weight-bold text-center bg-light" value="<?php echo $invoice['exchange_rate'] ?? 1.0; ?>" <?php echo ($inv_currency === 'YER') ? 'readonly' : ''; ?> required>
                </div>
            </div>

            <!-- ======================== الصف الثاني ======================== -->
            <div class="row mb-3" id="salesPaymentRow">
                <div class="col-md-3 col-sm-6 mb-3" id="salesBoxSection">
                    <label class="form-label font-weight-bold text-secondary">الصندوق</label>
                    <?php if ($is_admin): ?>
                        <select name="box_id" id="boxSelect" class="form-control rounded-0" required>
                            <?php
                            $inv_box_id = intval($invoice['box_id']);
                            $res_b = $conn->query("SELECT box_id, name, mony FROM treasury WHERE is_active = 1 ORDER BY box_id ASC");
                            if ($res_b) {
                                while($b = $res_b->fetch_assoc()) {
                                    $selected_attr = ($b['box_id'] == $inv_box_id) ? 'selected' : '';
                                    echo "<option value='{$b['box_id']}' data-balance='{$b['mony']}' $selected_attr>" . htmlspecialchars($b['name']) . "</option>";
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
                </div>

                <div class="col-md-3 col-sm-6 mb-3" id="salesPaymentMethodSection">
                    <label class="form-label font-weight-bold text-secondary">طريقة الدفع</label>
                    <select name="payment_method" id="salesPaymentMethodSelect" class="form-control rounded-0" onchange="toggleSalesWalletSection(this.value)">
                        <option value="cash" <?php echo ($invoice['payment_method'] === 'cash') ? 'selected' : ''; ?>>نقداً</option>
                        <option value="wallet" <?php echo ($invoice['payment_method'] === 'wallet') ? 'selected' : ''; ?>>محفظة إلكترونية / بنك</option>
                    </select>
                </div>

                <div class="col-md-3 col-sm-6 mb-3 <?php echo ($invoice['payment_method'] !== 'wallet') ? 'd-none' : ''; ?>" id="salesWalletTypeSection">
                    <label class="form-label font-weight-bold text-secondary">نوع المحفظة / البنك</label>
                    <select id="salesWalletTypeSelect" class="form-control rounded-0" <?php echo ($invoice['payment_method'] === 'wallet') ? 'name="wallet_type"' : ''; ?>>
                        <option value="">-- اختر --</option>
                        <optgroup label="محافظ إلكترونية">
                            <option value="كاش يمن" <?php echo ($invoice['wallet_type'] === 'كاش يمن') ? 'selected' : ''; ?>>كاش يمن</option>
                            <option value="ام كاش" <?php echo ($invoice['wallet_type'] === 'ام كاش') ? 'selected' : ''; ?>>ام كاش</option>
                            <option value="زين كاش" <?php echo ($invoice['wallet_type'] === 'زين كاش') ? 'selected' : ''; ?>>زين كاش</option>
                            <option value="فلوسك" <?php echo ($invoice['wallet_type'] === 'فلوسك') ? 'selected' : ''; ?>>فلوسك</option>
                        </optgroup>
                        <optgroup label="بنوك">
                            <option value="بنك اليمن والخليج" <?php echo ($invoice['wallet_type'] === 'بنك اليمن والخليج') ? 'selected' : ''; ?>>بنك اليمن والخليج</option>
                            <option value="البنك الأهلي اليمني" <?php echo ($invoice['wallet_type'] === 'البنك الأهلي اليمني') ? 'selected' : ''; ?>>البنك الأهلي اليمني</option>
                        </optgroup>
                    </select>
                </div>
                <input type="hidden" name="wallet_type" id="salesWalletTypeHidden" value="<?php echo htmlspecialchars($invoice['wallet_type'] ?? ''); ?>">
            </div>

            <!-- شريط البحث -->
            <div class="card p-3 bg-light border-0 mb-4 no-print">
                <div class="row align-items-center">
                    <div class="col-md-6 text-md-left">
                        <button type="button" id="quickProductSearchBtn" class="btn btn-outline-primary rounded-0 px-4 font-weight-bold">
                            <i class="bi bi-search ml-1"></i> F2 - البحث السريع عن المنتج
                        </button>
                    </div>
                </div>
            </div>

            <!-- جدول المنتجات -->
            <div id="creditLimitWarning" class="alert alert-warning d-none mb-3 text-right" dir="rtl"></div>
            <div class="table-responsive">
                <table class="table-flat" id="itemsTable">
                    <thead>
                        <tr>
                            <th style="width: 28%;">المنتج</th>
                            <th style="width: 8%;">المخزن</th>
                            <th style="width: 8%;">الكمية</th>
                            <th style="width: 10%;">سعر البيع</th>
                            <th style="width: 10%;">المجموع</th>
                            <th style="width: 10%;">المدفوع</th>
                            <th style="width: 8%;">الخصم</th>
                            <th style="width: 10%;">الباقي</th>
                            <th class="no-print" style="width: 4%;">إجراء</th>
                        </tr>
                    </thead>
                    <tbody id="itemsContainer">
                        <?php if (!empty($invoice_items)): ?>
                            <?php foreach ($invoice_items as $idx => $item): 
                                // استخراج اسم المنتج ووحدة القياس
                                $item_name_full = $item['name'];
                                $product_id_db = intval($item['id']);
                                
                                // جلب بيانات المنتج الحالية
                                $p_res = $conn->query("SELECT id, name, sale_price, buy_price, quantity, conversion_factor, track_expiry, requires_serial FROM products WHERE id = $product_id_db LIMIT 1");
                                $p_data = ($p_res && $p_res->num_rows > 0) ? $p_res->fetch_assoc() : null;
                                
                                if (!$p_data) continue;
                                
                                $rate = doubleval($invoice['exchange_rate'] ?? 1);
                                if ($rate <= 0) $rate = 1;
                                
                                // القيم بالعملة الأصلية (قبل سعر الصرف)
                                $unit_price_orig = doubleval($item['unit_price']) / $rate;
                                $paid_orig = doubleval($item['bush']) / $rate;
                                $disc_orig = doubleval($item['d']) / $rate;
                                $rem_orig = doubleval($item['dis']) / $rate;
                                $buy_price_orig = doubleval($p_data['buy_price']) / $rate;
                                
                                // استخراج الوحدة
                                $unit_name = 'الوحدة الأساسية';
                                $conv_factor = 1.0;
                                $unit_id = '';
                                if (preg_match('/\(([^)]+)\)/', $item_name_full, $matches)) {
                                    $unit_name = trim($matches[1]);
                                    $u_res = $conn->query("SELECT id, conversion_factor FROM product_units WHERE product_id = $product_id_db AND unit_name = '" . $conn->real_escape_string($unit_name) . "' LIMIT 1");
                                    if ($u_res && $u_res->num_rows > 0) {
                                        $u_row = $u_res->fetch_assoc();
                                        $conv_factor = doubleval($u_row['conversion_factor']);
                                        $unit_id = $u_row['id'];
                                    }
                                }
                                
                                $stock_qty = floor(doubleval($p_data['quantity']) / $conv_factor);
                            ?>
                            <tr class="item-row" data-original-qty="<?php echo intval($item['quantity']); ?>">
                                <td>
                                    <div class="product-search-container">
                                        <input type="text" class="form-control product-search-input rounded-0" value="<?php echo htmlspecialchars($p_data['name']); ?>" autocomplete="off" readonly>
                                        <input type="hidden" name="product_id[]" class="select-product" value="<?php echo $product_id_db; ?>" data-base-sale-price="<?php echo doubleval($p_data['sale_price']); ?>" data-base-buy-price="<?php echo doubleval($p_data['buy_price']); ?>">
                                        <div class="autocomplete-dropdown d-none"></div>
                                    </div>
                                    <input type="hidden" name="buy_price[]" class="buy-price" value="<?php echo number_format($buy_price_orig, 2, '.', ''); ?>">
                                    <input type="hidden" name="conversion_factor[]" class="conversion-factor" value="<?php echo $conv_factor; ?>">
                                    <input type="hidden" name="unit_name[]" class="unit-name" value="<?php echo htmlspecialchars($unit_name); ?>">
                                    <input type="hidden" name="unit_id[]" class="unit-id" value="<?php echo $unit_id; ?>">
                                    <input type="hidden" name="serial_ids[]" class="row-serial-ids" value="">
                                    <input type="hidden" name="batch_id[]" class="row-batch-id" value="">

                                    <div class="serial-sec d-none mt-2 text-right">
                                        <small class="text-primary font-weight-bold d-block mb-1">الأرقام التسلسلية (IMEI):</small>
                                        <select class="form-control form-control-sm serial-select select-multiple" multiple style="height: 60px; font-size: 0.8rem;"></select>
                                    </div>
                                    <div class="batch-sec d-none mt-2 text-right">
                                        <small class="text-danger font-weight-bold d-block mb-1">دفعة الصلاحية:</small>
                                        <select class="form-control form-control-sm batch-select rounded-0"></select>
                                    </div>
                                </td>
                                <td>
                                    <input type="text" class="form-control stock-qty text-center bg-light rounded-0" readonly value="<?php echo $stock_qty; ?>">
                                </td>
                                <td>
                                    <input type="number" name="quantity[]" class="form-control quantity-input text-center rounded-0" min="1" value="<?php echo intval($item['quantity']); ?>" required>
                                    <span class="row-stock-warning text-danger font-weight-bold d-none" style="font-size:0.75rem; display:block; margin-top:4px; text-align:center;"></span>
                                </td>
                                <td>
                                    <input type="number" step="any" name="unit_price[]" class="form-control price-input text-center rounded-0" value="<?php echo number_format($unit_price_orig, 2, '.', ''); ?>" required>
                                </td>
                                <td>
                                    <input type="text" class="form-control total-input text-center bg-light rounded-0" readonly value="<?php echo number_format($unit_price_orig * intval($item['quantity']), 2, '.', ''); ?>">
                                </td>
                                <td>
                                    <input type="number" step="any" name="paid_amount[]" class="form-control paid-input text-center rounded-0" value="<?php echo number_format($paid_orig, 2, '.', ''); ?>" data-manually-edited="true">
                                </td>
                                <td>
                                    <input type="number" step="any" name="discount_amount[]" class="form-control discount-input text-center rounded-0" value="<?php echo number_format($disc_orig, 2, '.', ''); ?>">
                                </td>
                                <td>
                                    <input type="text" name="remaining_amount[]" class="form-control remaining-input text-center bg-light rounded-0" readonly value="<?php echo number_format($rem_orig, 2, '.', ''); ?>">
                                    <input type="hidden" class="profit-input" name="profit[]" value="0">
                                </td>
                                <td class="no-print">
                                    <button type="button" class="btn-flat btn-flat-danger btn-sm py-1 px-2 remove-item-btn">
                                        <?php echo get_icon('trash'); ?>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr class="item-row">
                                <td>
                                    <div class="product-search-container">
                                        <input type="text" class="form-control product-search-input rounded-0" placeholder="اضغط هنا للبحث عن منتج..." autocomplete="off" readonly required>
                                        <input type="hidden" name="product_id[]" class="select-product" value="">
                                        <div class="autocomplete-dropdown d-none"></div>
                                    </div>
                                    <input type="hidden" name="buy_price[]" class="buy-price" value="0">
                                    <input type="hidden" name="conversion_factor[]" class="conversion-factor" value="1.0000">
                                    <input type="hidden" name="unit_name[]" class="unit-name" value="الوحدة الأساسية">
                                    <input type="hidden" name="unit_id[]" class="unit-id" value="">
                                    <input type="hidden" name="serial_ids[]" class="row-serial-ids" value="">
                                    <input type="hidden" name="batch_id[]" class="row-batch-id" value="">
                                    <div class="serial-sec d-none mt-2 text-right">
                                        <small class="text-primary font-weight-bold d-block mb-1">الأرقام التسلسلية (IMEI):</small>
                                        <select class="form-control form-control-sm serial-select select-multiple" multiple style="height: 60px; font-size: 0.8rem;"></select>
                                    </div>
                                    <div class="batch-sec d-none mt-2 text-right">
                                        <small class="text-danger font-weight-bold d-block mb-1">دفعة الصلاحية:</small>
                                        <select class="form-control form-control-sm batch-select rounded-0"></select>
                                    </div>
                                </td>
                                <td><input type="text" class="form-control stock-qty text-center bg-light rounded-0" readonly value="0"></td>
                                <td>
                                    <input type="number" name="quantity[]" class="form-control quantity-input text-center rounded-0" min="1" value="1" required>
                                    <span class="row-stock-warning text-danger font-weight-bold d-none" style="font-size:0.75rem; display:block; margin-top:4px; text-align:center;"></span>
                                </td>
                                <td><input type="number" step="any" name="unit_price[]" class="form-control price-input text-center rounded-0" required></td>
                                <td><input type="text" class="form-control total-input text-center bg-light rounded-0" readonly value="0"></td>
                                <td><input type="number" step="any" name="paid_amount[]" class="form-control paid-input text-center rounded-0" value="0"></td>
                                <td><input type="number" step="any" name="discount_amount[]" class="form-control discount-input text-center rounded-0" value="0"></td>
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
                        <?php endif; ?>
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
                    <textarea name="remark" class="form-control rounded-0" rows="3" placeholder="ملاحظات..."><?php echo htmlspecialchars($invoice['remark'] ?? ''); ?></textarea>
                </div>
                <div class="col-lg-6">
                    <div class="invoice-summary">
                        <table>
                            <tr>
                                <td class="total-label">إجمالي المدفوع</td>
                                <td class="total-value">
                                    <input type="text" id="grandPaidDisplay" name="grand_paid" class="form-control text-left font-weight-bold bg-transparent border-0 rounded-0" readonly value="0">
                                </td>
                            </tr>
                            <tr>
                                <td class="total-label">إجمالي المتبقي</td>
                                <td class="total-value text-danger">
                                    <span id="grandRemainingDisplay">0.00</span> <span class="currency-symbol">ر.ي</span>
                                </td>
                            </tr>
                            <tr>
                                <td class="total-label">إجمالي الربح</td>
                                <td class="total-value text-success">
                                    <input type="text" id="grandProfitDisplay" name="grand_profit" class="form-control text-left text-success font-weight-bold bg-transparent border-0 rounded-0" readonly value="0">
                                </td>
                            </tr>
                        </table>
                    </div>

                    <div class="accounting-guide">
                        <h6><i class="fa fa-book ml-2"></i>الدليل المحاسبي - القيود الجديدة</h6>
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
                                    <td>المقبوضات النقدية</td>
                                    <td>الصندوق</td>
                                    <td><input type="number" step="any" class="editable-amount" id="acc_cash_received" value="0" readonly></td>
                                    <td>المبيعات</td>
                                    <td><input type="number" step="any" class="editable-amount" id="acc_cash_received_credit" value="0" readonly></td>
                                </tr>
                                <tr>
                                    <td>المديونية الآجلة</td>
                                    <td>الذمم المدينة</td>
                                    <td><input type="number" step="any" class="editable-amount" id="acc_credit_sales" value="0" readonly></td>
                                    <td>المبيعات</td>
                                    <td><input type="number" step="any" class="editable-amount" id="acc_credit_sales_credit" value="0" readonly></td>
                                </tr>
                                <tr>
                                    <td>الخصم المسموح</td>
                                    <td>مصروفات الخصم</td>
                                    <td><input type="number" step="any" class="editable-amount" id="acc_discount" value="0" readonly></td>
                                    <td>المبيعات</td>
                                    <td><input type="number" step="any" class="editable-amount" id="acc_discount_credit" value="0" readonly></td>
                                </tr>
                                <tr>
                                    <td>تكلفة البضاعة</td>
                                    <td>تكلفة المبيعات</td>
                                    <td><input type="number" step="any" class="editable-amount" id="acc_cogs" value="0" readonly></td>
                                    <td>المخزون</td>
                                    <td><input type="number" step="any" class="editable-amount" id="acc_cogs_credit" value="0" readonly></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="mt-4 no-print text-left">
                <button type="submit" name="btn_update" id="btnSaveSales" class="btn-flat btn-flat-warning btn-lg px-5">
                    <?php echo get_icon('save', 'ml-1'); ?> حفظ التعديلات على الفاتورة
                </button>
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
                <p class="mb-2 font-weight-bold">أنت على وشك مغادرة صفحة تعديل الفاتورة.</p>
                <p class="text-danger">جميع التعديلات ستضيع. هل تريد المتابعة؟</p>
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
const isSerialModuleEnabled = <?php echo is_module_enabled('serial_imei_tracking') ? 'true' : 'false'; ?>;
const isExpiryModuleEnabled = <?php echo is_module_enabled('expiry_tracking') ? 'true' : 'false'; ?>;
const currentInvoiceId = <?php echo $invoice_id; ?>;
const oldRemainingTotal = <?php echo doubleval($invoice['remaining_total']); ?>;

let salesFormDirty = false;
let salesFormSubmitting = false;
let pendingLeaveUrl = null;

document.addEventListener('DOMContentLoaded', function() {
    setTimeout(() => { salesFormDirty = false; }, 300);

    const salesForm = document.getElementById('salesForm');
    if (salesForm) {
        salesForm.addEventListener('change', () => { salesFormDirty = true; });
        salesForm.addEventListener('input',  () => { salesFormDirty = true; });
        salesForm.addEventListener('submit', () => { salesFormSubmitting = true; salesFormDirty = false; });
    }

    const btnSave = document.getElementById('btnSaveSales');
    if (btnSave) btnSave.addEventListener('click', () => { salesFormSubmitting = true; });

    document.querySelectorAll('a[href]').forEach(function(link) {
        const href = link.getAttribute('href');
        if (!href || href.startsWith('#') || href.startsWith('javascript') || href.startsWith('mailto')) return;
        link.addEventListener('click', function(e) {
            if (salesFormDirty && !salesFormSubmitting) {
                e.preventDefault();
                pendingLeaveUrl = link.href;
                $('#leavePageModal').modal('show');
            }
        });
    });

    const confirmLeaveBtn = document.getElementById('confirmLeaveBtn');
    if (confirmLeaveBtn) {
        confirmLeaveBtn.addEventListener('click', function() {
            salesFormDirty = false;
            if (pendingLeaveUrl) window.location.href = pendingLeaveUrl;
        });
    }
    
    // تحديث الحسابات عند تحميل الصفحة
    if (typeof updateGrandTotals === 'function') updateGrandTotals();
    if (typeof updateAccountingGuide === 'function') updateAccountingGuide();
});

window.addEventListener('beforeunload', function(e) {
    if (salesFormDirty && !salesFormSubmitting) {
        e.preventDefault();
        e.returnValue = '';
    }
});

function toggleSalesInvoiceType(val) {
    const boxSection      = document.getElementById('salesBoxSection');
    const paymentSection  = document.getElementById('salesPaymentMethodSection');

    if (val === 'credit') {
        boxSection.classList.add('d-none');
        paymentSection.classList.add('d-none');
        document.querySelectorAll('.paid-input').forEach(inp => {
            inp.value = '0';
            inp.setAttribute('data-manually-edited', 'true');
            inp.dispatchEvent(new Event('input', { bubbles: true }));
        });
    } else {
        boxSection.classList.remove('d-none');
        paymentSection.classList.remove('d-none');
        if (val === 'account') {
            boxSection.classList.add('d-none');
        }
    }
}

function toggleSalesWalletSection(val) {
    const walletSec    = document.getElementById('salesWalletTypeSection');
    const walletHidden = document.getElementById('salesWalletTypeHidden');
    const walletSelect = document.getElementById('salesWalletTypeSelect');
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

document.addEventListener("DOMContentLoaded", function() {
    const itemsContainer = document.getElementById("itemsContainer");
    const addItemBtn = document.getElementById("addItemBtn");
    const currencySelect = document.getElementById("currencySelect");
    const exchangeRateInput = document.getElementById("exchangeRateInput");

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

    function updateRowCalculations(row) {
        const qty = parseInt(row.querySelector(".quantity-input").value) || 0;
        const price = parseFloat(row.querySelector(".price-input").value) || 0;

        const lineTotal = qty * price;
        row.querySelector(".total-input").value = lineTotal.toFixed(2);

        const paidInput = row.querySelector(".paid-input");
        if (!paidInput.hasAttribute("data-manually-edited")) {
            paidInput.value = lineTotal.toFixed(2);
        }

        const paid = parseFloat(paidInput.value) || 0;
        const disc = parseFloat(row.querySelector(".discount-input").value) || 0;
        const buyPrice = parseFloat(row.querySelector(".buy-price").value) || 0;

        const remaining = Math.max(0, lineTotal - paid - disc);
        row.querySelector(".remaining-input").value = remaining.toFixed(2);

        const profit = lineTotal - (buyPrice * qty) - disc;
        row.querySelector(".profit-input").value = profit.toFixed(2);

        updateGrandTotals();
        updateAccountingGuide();
    }

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
        checkRealTimeWarnings();
    }

    function updateAccountingGuide() {
        let totalPaid = 0;
        let totalRemaining = 0;
        let totalDiscount = 0;
        let totalCost = 0;

        document.querySelectorAll(".item-row").forEach(function(row) {
            const productId = row.querySelector(".select-product").value;
            if (!productId) return;

            const qty = parseInt(row.querySelector(".quantity-input").value) || 0;
            const paid = parseFloat(row.querySelector(".paid-input").value) || 0;
            const remaining = parseFloat(row.querySelector(".remaining-input").value) || 0;
            const discount = parseFloat(row.querySelector(".discount-input").value) || 0;
            const buyPrice = parseFloat(row.querySelector(".buy-price").value) || 0;

            totalPaid += paid;
            totalRemaining += remaining;
            totalDiscount += discount;
            totalCost += (qty * buyPrice);
        });

        document.getElementById("acc_cash_received").value = totalPaid.toFixed(2);
        document.getElementById("acc_cash_received_credit").value = totalPaid.toFixed(2);
        document.getElementById("acc_credit_sales").value = totalRemaining.toFixed(2);
        document.getElementById("acc_credit_sales_credit").value = totalRemaining.toFixed(2);
        document.getElementById("acc_discount").value = totalDiscount.toFixed(2);
        document.getElementById("acc_discount_credit").value = totalDiscount.toFixed(2);
        document.getElementById("acc_cogs").value = totalCost.toFixed(2);
        document.getElementById("acc_cogs_credit").value = totalCost.toFixed(2);
    }

    function checkRealTimeWarnings() {
        const customerSelect = document.getElementById("select2");
        const customerName = customerSelect ? customerSelect.value : "";
        const remainingSpan = document.getElementById("grandRemainingDisplay");
        const remainingTotal = remainingSpan ? parseFloat(remainingSpan.textContent) || 0 : 0;
        
        const warningDiv = document.getElementById("creditLimitWarning");
        if (warningDiv) {
            if (customerName && customerName !== "عميل نقدي" && currentCustomerDetails.id > 0 && remainingTotal > 0) {
                const newBalance = currentCustomerDetails.balance - oldRemainingTotal + remainingTotal;
                if (newBalance > currentCustomerDetails.credit_limit) {
                    warningDiv.innerHTML = `⚠️ <strong>تجاوز حد الدين للعميل:</strong> مديونية العميل بعد تعديل هذه الفاتورة (${newBalance.toFixed(2)} ر.ي) ستتجاوز الحد الائتماني المسموح به (${currentCustomerDetails.credit_limit.toFixed(2)} ر.ي).`;
                    warningDiv.classList.remove("d-none");
                } else {
                    warningDiv.classList.add("d-none");
                }
            } else {
                warningDiv.classList.add("d-none");
            }
        }

        // Stock level warning for each row
        document.querySelectorAll(".item-row").forEach(row => {
            const qtyInput = row.querySelector(".quantity-input");
            const stockInput = row.querySelector(".stock-qty");
            const nameInput = row.querySelector(".product-search-input");
            const rowWarning = row.querySelector(".row-stock-warning");
            if (qtyInput && stockInput && rowWarning) {
                const qty = parseInt(qtyInput.value) || 0;
                const stock = parseInt(stockInput.value) || 0;
                const name = nameInput ? nameInput.value : "";
                
                const originalQty = parseInt(row.getAttribute("data-original-qty")) || 0;
                const actualAvailable = stock + originalQty;
                
                if (qty > actualAvailable && actualAvailable >= 0 && qty > 0 && name !== "") {
                    rowWarning.textContent = `⚠️ تجاوز المخزون (${actualAvailable})`;
                    rowWarning.classList.remove("d-none");
                } else {
                    rowWarning.classList.add("d-none");
                }
            }
        });
    }

    // جعل الدوال عامة
    window.updateRowCalculations = updateRowCalculations;
    window.updateGrandTotals = updateGrandTotals;
    window.updateAccountingGuide = updateAccountingGuide;

    addItemBtn.addEventListener("click", function() {
        const newRow = rowTemplate.cloneNode(true);
        newRow.querySelector(".product-search-input").value = "";
        newRow.querySelector(".product-search-input").removeAttribute("readonly");
        newRow.querySelector(".product-search-input").placeholder = "اضغط هنا للبحث عن منتج...";
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

        newRow.querySelector(".conversion-factor").value = "1.0000";
        newRow.querySelector(".unit-name").value = "الوحدة الأساسية";
        newRow.querySelector(".unit-id").value = "";
        newRow.querySelector(".row-serial-ids").value = "";
        newRow.querySelector(".row-batch-id").value = "";

        newRow.querySelector(".serial-sec").classList.add("d-none");
        newRow.querySelector(".serial-select").innerHTML = "";
        newRow.querySelector(".batch-sec").classList.add("d-none");
        newRow.querySelector(".batch-select").innerHTML = "";

        newRow.querySelector(".autocomplete-dropdown").classList.add("d-none");
        newRow.querySelector(".autocomplete-dropdown").innerHTML = "";

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

    itemsContainer.addEventListener("input", function(e) {
        if (e.target.matches(".quantity-input, .price-input, .paid-input, .discount-input")) {
            const row = e.target.closest(".item-row");

            if (e.target.classList.contains("paid-input")) {
                e.target.setAttribute("data-manually-edited", "true");
            }

            if (e.target.classList.contains("quantity-input")) {
                const qty = parseInt(e.target.value) || 0;
                const stockVal = parseFloat(row.querySelector(".stock-qty").value) || 0;

                if (qty > stockVal) {
                    alert("تنبيه: الكمية المدخلة أكبر من المتوفر في المخزن!");
                }
            }

            updateRowCalculations(row);
        }
    });

    itemsContainer.addEventListener("change", function(e) {
        if (e.target.matches(".serial-select")) {
            const row = e.target.closest(".item-row");
            const selectedOptions = Array.from(e.target.selectedOptions).map(opt => opt.value);
            row.querySelector(".row-serial-ids").value = selectedOptions.join(",");

            if (selectedOptions.length > 0) {
                row.querySelector(".quantity-input").value = selectedOptions.length;
                updateRowCalculations(row);
            }
        }
        if (e.target.matches(".batch-select")) {
            const row = e.target.closest(".item-row");
            row.querySelector(".row-batch-id").value = e.target.value;
        }
    });

    function closeProductSearchModal() {
        const modalEl = document.getElementById('quickProductSearchModal');
        if (!modalEl) return;
        
        if (typeof $ !== 'undefined' && typeof $.fn.modal === 'function') {
            $(modalEl).modal('hide');
        }
        
        modalEl.classList.remove('show');
        modalEl.setAttribute('aria-hidden', 'true');
        modalEl.style.display = 'none';
        document.body.classList.remove('modal-open');
        document.body.style.paddingRight = '';
        
        const backdrop = document.querySelector('.modal-backdrop');
        if (backdrop) backdrop.remove();
    }

    function openProductModalAndFocusSearch(query) {
        const modalEl = document.getElementById('quickProductSearchModal');
        if (!modalEl) return;
        
        const searchInput = document.getElementById('quickProductSearchInput');
        
        if (typeof $ !== 'undefined' && typeof $.fn.modal === 'function') {
            $('#quickProductSearchModal').modal('show');
        } else {
            modalEl.classList.add('show');
            modalEl.setAttribute('aria-hidden', 'false');
            modalEl.style.display = 'block';
            document.body.classList.add('modal-open');
        }
        
        setTimeout(() => {
            if (searchInput) {
                searchInput.value = query || '';
                searchInput.focus();
                searchInput.select();
                if (query) {
                    renderQuickProductResults(query);
                } else {
                    fetchModalProducts(true);
                }
            }
        }, 300);
    }

    itemsContainer.addEventListener("click", function(e) {
        if (e.target.matches(".product-search-input")) {
            const row = e.target.closest(".item-row");
            window.activeSearchRow = row;
            
            const selectProduct = row.querySelector(".select-product");
            if (selectProduct && selectProduct.value) {
                return;
            }
            
            openProductModalAndFocusSearch('');
        }
    });

    function selectProductForRow(row, product) {
        const container = row.querySelector(".product-search-container");
        const input = container.querySelector(".product-search-input");
        const hiddenInput = container.querySelector(".select-product");
        const dropdown = container.querySelector(".autocomplete-dropdown");

        input.value = product.name;
        input.setAttribute("readonly", "readonly");
        hiddenInput.value = product.id;
        hiddenInput.setAttribute("data-base-sale-price", product.sale_price);
        hiddenInput.setAttribute("data-base-buy-price", product.buy_price);

        const conversionFactor = parseFloat(product.conversion_factor) || 1.0;
        row.querySelector(".conversion-factor").value = conversionFactor;
        row.querySelector(".unit-name").value = product.unit_name || "الوحدة الأساسية";
        row.querySelector(".unit-id").value = product.unit_id || "";

        const rate = parseFloat(exchangeRateInput.value) || 1.0;

        row.querySelector(".buy-price").value = (product.buy_price / rate).toFixed(2);

        const stockQty = Math.floor(product.quantity / conversionFactor);
        row.querySelector(".stock-qty").value = stockQty;

        const salePriceConverted = (product.sale_price / rate).toFixed(2);
        row.querySelector(".price-input").value = salePriceConverted;
        row.querySelector(".paid-input").value = salePriceConverted;
        row.querySelector(".paid-input").removeAttribute("data-manually-edited");
        row.querySelector(".quantity-input").value = 1;
        row.querySelector(".discount-input").value = 0;

        dropdown.classList.add("d-none");
        dropdown.innerHTML = "";

        closeProductSearchModal();

        const serialSec = row.querySelector(".serial-sec");
        const serialSelect = row.querySelector(".serial-select");
        const hiddenSerialIds = row.querySelector(".row-serial-ids");

        if (isSerialModuleEnabled && product.requires_serial === 1) {
            serialSec.classList.remove("d-none");
            fetch(`api_get_serials.php?product_id=${product.id}`)
                .then(r => r.json())
                .then(serials => {
                    let html = "";
                    serials.forEach(s => {
                        const imeiText = s.imei_1 ? ` (IMEI: ${s.imei_1}${s.imei_2 ? ' / ' + s.imei_2 : ''})` : '';
                        html += `<option value="${s.id}">${s.serial_number}${imeiText}</option>`;
                    });
                    serialSelect.innerHTML = html;
                });
        } else {
            serialSec.classList.add("d-none");
            serialSelect.innerHTML = "";
            hiddenSerialIds.value = "";
        }

        const batchSec = row.querySelector(".batch-sec");
        const batchSelect = row.querySelector(".batch-select");
        const hiddenBatchId = row.querySelector(".row-batch-id");

        if (isExpiryModuleEnabled && product.track_expiry === 1) {
            batchSec.classList.remove("d-none");
            fetch(`api_get_batches.php?product_id=${product.id}`)
                .then(r => r.json())
                .then(batches => {
                    let html = '<option value="">-- سحب تلقائي FEFO --</option>';
                    batches.forEach(b => {
                        html += `<option value="${b.id}">${b.batch_number} (انتهاء: ${b.expiry_date} | متوفر: ${b.quantity})</option>`;
                    });
                    batchSelect.innerHTML = html;
                });
        } else {
            batchSec.classList.add("d-none");
            batchSelect.innerHTML = "";
            hiddenBatchId.value = "";
        }

        updateRowCalculations(row);

        if (stockQty <= 0) {
            alert("تنبيه: هذا المنتج غير متوفر في المخزن حالياً!");
        }

        setTimeout(() => {
            const qtyInput = row.querySelector(".quantity-input");
            if (qtyInput) {
                qtyInput.focus();
                qtyInput.select();
            }
        }, 100);
    }

    // معالجة التنقل
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
                const nextRow = row.nextElementSibling;
                if (nextRow && nextRow.classList.contains("item-row")) {
                    const nextSearch = nextRow.querySelector(".product-search-input");
                    if (nextSearch) {
                        nextSearch.focus();
                        window.activeSearchRow = nextRow;
                        openProductModalAndFocusSearch('');
                    }
                } else {
                    addItemBtn.click();
                    setTimeout(() => {
                        const rows = itemsContainer.querySelectorAll(".item-row");
                        const lastRow = rows[rows.length - 1];
                        if (lastRow) {
                            const lastSearch = lastRow.querySelector(".product-search-input");
                            if (lastSearch) {
                                window.activeSearchRow = lastRow;
                                openProductModalAndFocusSearch('');
                            }
                        }
                    }, 100);
                }
            }
        }
    });

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

        document.querySelectorAll(".currency-symbol").forEach(el => {
            el.textContent = symbol;
        });

        convertAllRowsToNewCurrency();
    });

    // ========== البحث السريع ==========
    let modalProductPage = 1;
    let modalProductLoading = false;
    let modalProductHasMore = true;
    let modalProductQuery = "";

    function fetchModalProducts(isNewSearch = false) {
        const results = document.getElementById('quickProductSearchResults');
        if (!results || modalProductLoading || (!isNewSearch && !modalProductHasMore)) return;

        modalProductLoading = true;

        if (isNewSearch) {
            results.innerHTML = '<tr class="loading-row"><td colspan="5"><div class="circular-loader"></div>جاري التحميل...</td></tr>';
            modalProductPage = 1;
            modalProductHasMore = true;
        }

        const url = `../api/search_products.php?q=${encodeURIComponent(modalProductQuery)}&page=${modalProductPage}`;
        fetch(url)
            .then(res => res.json())
            .then(products => {
                if (isNewSearch) results.innerHTML = "";

                if (products.length === 0) {
                    modalProductHasMore = false;
                    if (isNewSearch) {
                        results.innerHTML = '<tr><td colspan="5" class="text-center text-muted p-3">لم يتم العثور على منتج</td></tr>';
                    }
                    modalProductLoading = false;
                    return;
                }

                const rate = parseFloat(exchangeRateInput.value) || 1.0;
                let html = products.map(product => {
                    const pJson = JSON.stringify(product).replace(/'/g, "&#39;");
                    const priceConverted = (product.sale_price / rate).toFixed(2);
                    const stockVal = Math.floor(product.quantity / product.conversion_factor);

                    let stockBadgeClass = "badge-success";
                    if (stockVal <= 0) stockBadgeClass = "badge-danger";
                    else if (stockVal <= 5) stockBadgeClass = "badge-warning";

                    return `
                        <tr class="search-result-item" tabindex="0" data-product='${pJson}'>
                        <td>${escapeHtml(product.barcode || '-')}</td>
                        <td class="col-name">${escapeHtml(product.name)}</td>
                        <td class="price-cell">${priceConverted}</td>
                        <td><span class="badge ${stockBadgeClass}">${stockVal}</span></td>
                            <td><i class="bi bi-check-circle text-success"></i></td>
                        </tr>
                    `;
                }).join('');

                results.insertAdjacentHTML('beforeend', html);

                results.querySelectorAll('.search-result-item').forEach(row => {
                    if (row.dataset.clickRegistered) return;
                    row.dataset.clickRegistered = "true";
                    row.addEventListener('click', function() {
                        const product = JSON.parse(this.getAttribute('data-product'));
                        if (product) selectAndRouteFocus(product);
                    });
                });

                modalProductPage++;
                modalProductLoading = false;
            })
            .catch(err => {
                console.error(err);
                modalProductLoading = false;
            });
    }

    function selectAndRouteFocus(product) {
        let activeRow = window.activeSearchRow;

        if (activeRow) {
            selectProductForRow(activeRow, product);
        }

        closeProductSearchModal();

        if (activeRow) {
            setTimeout(() => {
                const qtyInput = activeRow.querySelector(".quantity-input");
                if (qtyInput) {
                    qtyInput.focus();
                    qtyInput.select();
                }
            }, 100);
        }
    }

    window.openQuickProductModal = function() {
        openProductModalAndFocusSearch('');
    }

    function renderQuickProductResults(query) {
        modalProductQuery = query;
        fetchModalProducts(true);
    }

    let searchTimeout = null;
    const quickSearchInput = document.getElementById('quickProductSearchInput');
    if (quickSearchInput) {
        quickSearchInput.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                renderQuickProductResults(this.value);
            }, 250);
        });
        quickSearchInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                const firstRow = document.querySelector('#quickProductSearchResults .search-result-item');
                if (firstRow) firstRow.click();
            }
        });
    }

    const quickProductBtn = document.getElementById('quickProductSearchBtn');
    if (quickProductBtn) {
        quickProductBtn.addEventListener('click', openQuickProductModal);
    }

    // ========== التحقق من العميل ==========
    let currentCustomerDetails = { id: 0, credit_limit: 999999999, balance: 0 };

    function fetchCustomerDetails(name) {
        if (name === "عميل نقدي" || !name) {
            currentCustomerDetails = { id: 0, credit_limit: 999999999, balance: 0 };
            return;
        }
        fetch(`../api/get_customer_details.php?name=${encodeURIComponent(name)}`)
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success') {
                    currentCustomerDetails = {
                        id: data.cust_id,
                        credit_limit: data.credit_limit,
                        balance: data.balance
                    };
                    checkRealTimeWarnings();
                }
            });
    }

    if (typeof $ !== 'undefined' && $('#select2').length) {
        $('#select2').on('change', function() {
            fetchCustomerDetails($(this).val());
        });
        fetchCustomerDetails($('#select2').val());
    }

    // ========== التحقق النهائي ==========
    document.getElementById("salesForm").addEventListener("submit", function(e) {
        let isValid = true;

        const boxSelect = document.getElementById("boxSelect");
        const userBoxId = document.getElementById("userBoxId");
        let boxBalance = 0;
        if (boxSelect && boxSelect.selectedIndex >= 0) {
            const selectedOption = boxSelect.options[boxSelect.selectedIndex];
            boxBalance = parseFloat(selectedOption.getAttribute("data-balance")) || 0;
        } else if (userBoxId) {
            boxBalance = parseFloat(userBoxId.getAttribute("data-balance")) || 0;
        }

        const totalPaid = Array.from(document.querySelectorAll(".item-row")).reduce((sum, row) => {
            return sum + (parseFloat(row.querySelector(".paid-input").value) || 0);
        }, 0);

        if (totalPaid > boxBalance) {
            alert("رصيد الصندوق المحدد غير كافٍ!");
            e.preventDefault();
            return false;
        }

        let hasProducts = false;
        document.querySelectorAll(".item-row").forEach(row => {
            const productId = row.querySelector(".select-product").value;
            if (productId && productId !== "-1") hasProducts = true;
        });
        if (!hasProducts) {
            alert("يجب إضافة صنف واحد على الأقل!");
            e.preventDefault();
            return false;
        }

        document.querySelectorAll(".item-row").forEach(row => {
            const productId = row.querySelector(".select-product").value;
            if (!productId) return;

            const name = row.querySelector(".product-search-input").value;
            const qty = parseInt(row.querySelector(".quantity-input").value) || 0;
            const stock = parseInt(row.querySelector(".stock-qty").value) || 0;

            if (qty > stock) {
                alert(`تحذير في "${name}": الكمية (${qty}) أكبر من المخزن (${stock})!`);
                isValid = false;
            }
        });

        if (!isValid) {
            e.preventDefault();
            return false;
        }

        let hasWarnings = false;
        document.querySelectorAll(".row-stock-warning").forEach(span => {
            if (!span.classList.contains("d-none")) hasWarnings = true;
        });
        const warningDiv = document.getElementById("creditLimitWarning");
        if (warningDiv && !warningDiv.classList.contains("d-none")) {
            hasWarnings = true;
        }
        
        if (hasWarnings) {
            alert("يرجى تصحيح الأخطاء والتحذيرات (تجاوز حد الدين أو كمية المخزن) قبل حفظ الفاتورة.");
            isValid = false;
        }

        if (!isValid) {
            e.preventDefault();
            return false;
        }
    });
});
</script>

<!-- مودال إضافة عميل -->
<div class="modal fade" id="quickAddCustomerModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="fa fa-user-plus ml-2"></i>إضافة عميل جديد</h5>
                <button type="button" class="close text-white" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <div class="modal-body text-right" dir="rtl">
                <div class="alert alert-danger d-none" id="quickAddCustomerError"></div>
                <form id="quickAddCustomerForm">
                    <div class="row">
                        <div class="col-md-6">
                            <label class="font-weight-bold">اسم العميل *</label>
                            <input type="text" class="form-control" name="cust_name" required>
                        </div>
                        <div class="col-md-6">
                            <label class="font-weight-bold">رقم الجوال *</label>
                            <input type="text" class="form-control" name="phone" required>
                        </div>
                        <div class="col-md-6">
                            <label class="font-weight-bold">البريد الإلكتروني</label>
                            <input type="email" class="form-control" name="email">
                        </div>
                        <div class="col-md-6">
                            <label class="font-weight-bold">حد الائتمان</label>
                            <input type="number" step="any" class="form-control" name="credit_limit" value="0">
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
                    <input type="hidden" name="ajax_add_customer" value="1">
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">إلغاء</button>
                <button type="submit" form="quickAddCustomerForm" class="btn btn-primary">حفظ</button>
            </div>
        </div>
    </div>
</div>

<!-- مودال البحث السريع -->
<div class="modal fade" id="quickProductSearchModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-search ml-1"></i> البحث السريع عن المنتج</h5>
                <button type="button" class="close text-white" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <div class="modal-body">
                <input type="text" id="quickProductSearchInput" class="form-control" placeholder="ابحث باسم المنتج أو الباركود..." autocomplete="off">
                <div class="quick-results-wrap mt-3" id="quickProductSearchResultsWrap">
                    <table class="quick-product-table">
                        <thead>
                            <tr>
                                <th style="width:20%;">الباركود</th>
                                <th class="col-name" style="width:37%;">اسم المنتج</th>
                                <th style="width:18%;">السعر</th>
                                <th style="width:15%;">المخزون</th>
                                <th style="width:10%;">تحديد</th>
                            </tr>
                        </thead>
                        <tbody id="quickProductSearchResults"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function() {
    const form = document.getElementById("quickAddCustomerForm");
    const errorDiv = document.getElementById("quickAddCustomerError");

    if (form) {
        form.addEventListener("submit", function(e) {
            e.preventDefault();
            errorDiv.classList.add("d-none");

            const formData = new FormData(form);

            fetch("edit.php?id=<?php echo $invoice_id; ?>", {
                method: "POST",
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.status === "success") {
                    const customerSelect = document.getElementById("select2");
                    if (customerSelect) {
                        const opt = document.createElement("option");
                        opt.value = data.name;
                        opt.text = data.name;
                        opt.selected = true;
                        customerSelect.add(opt);
                        customerSelect.value = data.name;
                    }

                    const modalEl = document.getElementById('quickAddCustomerModal');
                    if (modalEl) {
                        if (typeof $ !== 'undefined' && typeof $.fn.modal === 'function') {
                            $(modalEl).modal('hide');
                        }
                        modalEl.classList.remove('show');
                        modalEl.style.display = 'none';
                        const backdrop = document.querySelector('.modal-backdrop');
                        if (backdrop) backdrop.remove();
                        document.body.classList.remove('modal-open');
                    }
                    form.reset();
                } else {
                    errorDiv.innerText = data.message;
                    errorDiv.classList.remove("d-none");
                }
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
        if (typeof openQuickProductModal === 'function') openQuickProductModal();
    }
    if (e.key === 'F10') {
        e.preventDefault();
        const form = document.getElementById('salesForm');
        if (form && form.checkValidity()) {
            form.submit();
        } else if (form) {
            form.reportValidity();
        }
    }
});
</script>

<?php require_once($dir_prefix . 'includes/footer.php'); ?>