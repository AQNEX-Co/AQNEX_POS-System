<?php
$dir_prefix = '../';
$module = 'sales';

// معالجة إضافة العميل السريع عبر AJAX قبل تضمين الهيدر لمنع تلوث الـ JSON بالـ HTML
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

$editing_invoice_id = isset($_GET['id']) ? intval($_GET['id']) : (isset($_GET['edit_id']) ? intval($_GET['edit_id']) : 0);
$editing_invoice = null;
$editing_items = [];

$actual_entries = [];
if ($editing_invoice_id > 0) {
    $res_edit_mst = $conn->query("SELECT * FROM sales_invoices_mst WHERE id = $editing_invoice_id AND d_s = 0 LIMIT 1");
    if ($res_edit_mst && $res_edit_mst->num_rows > 0) {
        $editing_invoice = $res_edit_mst->fetch_assoc();
        $editing_invoice['build_date'] = $editing_invoice['invoice_date'] ?? date('Y-m-d');
        $res_edit_dtl = $conn->query("SELECT * FROM sales_invoices_dtl WHERE invoice_id = $editing_invoice_id AND d_s = 0 ORDER BY id ASC");
        if ($res_edit_dtl) {
            while ($item_row = $res_edit_dtl->fetch_assoc()) {
                $editing_items[] = [
                    'id'          => $item_row['product_id'],
                    'p_id'        => $item_row['product_id'],
                    'name'        => $item_row['product_name'],
                    'unit_name'   => $item_row['unit_name'],
                    'quantity'    => $item_row['quantity'],
                    'unit_price'  => $item_row['unit_price'],
                    'all_tot'     => $item_row['total_price'],
                    'bush'        => $item_row['paid_amount'] ?? 0,
                    'd'           => $item_row['discount_amount'] ?? $item_row['discount'] ?? 0,
                    'dis'         => $item_row['remaining_amount'] ?? 0
                ];
            }
        }
    } else {
        $res_old = $conn->query("SELECT * FROM sales WHERE id = $editing_invoice_id AND delete_status = 0 LIMIT 1");
        if ($res_old && $res_old->num_rows > 0) {
            $editing_invoice = $res_old->fetch_assoc();
            $res_old_dtl = $conn->query("SELECT * FROM sales_items WHERE sales_id = $editing_invoice_id ORDER BY p_id ASC");
            if ($res_old_dtl) {
                while ($item_row = $res_old_dtl->fetch_assoc()) {
                    $editing_items[] = $item_row;
                }
            }
        }
    }

    if (!$editing_invoice) {
        $editing_invoice_id = 0;
    } else {
        $returns_map = [];
        $res_returns = $conn->query("SELECT product_id, SUM(quantity) as ret_qty, SUM(refund_amount) as ret_refund FROM sales_returns WHERE sales_id = $editing_invoice_id AND status = 'active' GROUP BY product_id");
        if ($res_returns) {
            while ($r_row = $res_returns->fetch_assoc()) {
                $returns_map[intval($r_row['product_id'])] = [
                    'qty' => floatval($r_row['ret_qty']),
                    'refund' => floatval($r_row['ret_refund'])
                ];
            }
        }

        $res_journal = $conn->query("SELECT * FROM journal_entries WHERE ref_id = $editing_invoice_id AND ref_type = 'sale' ORDER BY id ASC");
        if ($res_journal) {
            while ($j_row = $res_journal->fetch_assoc()) {
                $amt = doubleval($j_row['amount']);
                $actual_entries[] = [
                    'account_code' => '',
                    'account_name' => $j_row['account_debit'],
                    'debit'        => $amt,
                    'credit'       => 0,
                    'narration'    => $j_row['description'] ?? ''
                ];
                $actual_entries[] = [
                    'account_code' => '',
                    'account_name' => $j_row['account_credit'],
                    'debit'        => 0,
                    'credit'       => $amt,
                    'narration'    => $j_row['description'] ?? ''
                ];
            }
        }
    }
}

if (isset($_POST['btn_save'])) {
    $build_date = date('Y-m-d', strtotime($_POST['build_date']));
    $customer_name = $conn->real_escape_string($_POST['customer_name']);
    $sector_id = isset($_POST['sector_id']) && $_POST['sector_id'] !== '' ? intval($_POST['sector_id']) : null;
    $grand_paid = doubleval($_POST['grand_paid']);
    $grand_profit = doubleval($_POST['grand_profit']);
    $remark = $conn->real_escape_string($_POST['remark']);

    $currency_code = $conn->real_escape_string($_POST['currency_code']);
    $exchange_rate = doubleval($_POST['exchange_rate']);
    if ($exchange_rate <= 0) $exchange_rate = 1.0;

    $selected_box_id = (!empty($_POST['box_id'])) ? intval($_POST['box_id']) : get_user_box_id($conn, $active_user_id);
    $active_box_id = ($selected_box_id > 0) ? $selected_box_id : get_user_box_id($conn, $active_user_id);
    $box_name = get_box_name($conn, $active_box_id);

    $invoice_type   = in_array($_POST['invoice_type'] ?? '', ['cash','credit','account']) ? $_POST['invoice_type'] : 'cash';
    $payment_method = in_array($_POST['payment_method'] ?? '', ['cash','wallet']) ? $_POST['payment_method'] : 'cash';
    $wallet_type    = $conn->real_escape_string($_POST['wallet_type'] ?? '');

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
    $calculated_grand_profit_base = 0.0;
    $count = count($products);

    for ($i = 0; $i < $count; $i++) {
        $p_id = intval($products[$i]);
        $qty = intval($quantities[$i]);
        if ($p_id > 0 && $qty > 0) {
            $total_paid_base += doubleval($paids[$i]) * $exchange_rate;
            $total_remaining_base += doubleval($remainings[$i]) * $exchange_rate;
            $total_discount_base += doubleval($discounts[$i]) * $exchange_rate;
        }
    }

    $grand_profit_base = $grand_profit * $exchange_rate;
    $invoice_total_base = $total_paid_base + $total_remaining_base;

    // if ($total_paid_base > 0) {
    //     $box_balance = get_box_balance($conn, $selected_box_id);
    //     if ($box_balance < $total_paid_base) {
    //         $save_error = "لا يمكن إتمام العملية لأن رصيد الصندوق المحدد (" . number_format($box_balance, 2) . ") أقل من إجمالي المقبوضات (" . number_format($total_paid_base, 2) . ").";
    //     }
    // }

    if (!empty($customer_name) && $customer_name !== 'عميل نقدي') {
        $cust_res = $conn->query("SELECT credit_limit, cust_madeen FROM customers WHERE cust_name = '$customer_name' AND d_s = 0 LIMIT 1");
        if ($cust_res && $cust_res->num_rows > 0) {
            $cust_row = $cust_res->fetch_assoc();
            $credit_limit = doubleval($cust_row['credit_limit']);
            $current_balance = doubleval($cust_row['cust_madeen']);
            if ($credit_limit > 0) {
                $new_balance = $current_balance + $total_remaining_base;
                if ($new_balance > $credit_limit) {
                    $save_error = "لا يمكن إتمام العملية لأن مديونية العميل بعد هذه الفاتورة (" . number_format($new_balance,2) . ") ستتجاوز حد الائتمان المحدد (" . number_format($credit_limit,2) . ").";
                }
            }
        }
    }

    if (empty($save_error)) {
        $conn->begin_transaction();
        try {
            // جلب العميل أو إنشائه تلقائياً إذا لم يكن موجوداً
            $customer_id = 0;
            if (!empty($customer_name) && $customer_name !== 'عميل نقدي') {
                $cust_res = $conn->query("SELECT cust_id FROM customers WHERE cust_name = '$customer_name' AND d_s = 0 LIMIT 1");
                if ($cust_res && $cust_res->num_rows > 0) {
                    $customer_id = intval($cust_res->fetch_assoc()['cust_id']);
                } else {
                    // إنشاء العميل الجديد تلقائياً
                    $conn->query("INSERT INTO customers (cust_name, cust_madeen, cust_daain, sale_date, d_s) VALUES ('$customer_name', 0, 0, '$build_date', 0)");
                    $customer_id = $conn->insert_id;
                }
            }
            $editing_invoice_id = isset($_POST['editing_invoice_id']) ? intval($_POST['editing_invoice_id']) : 0;

            if ($editing_invoice_id > 0) {
                // 1. إرجاع الكميات المخصومة سابقاً إلى المخزن
                $prev_items = $conn->query("SELECT product_id, bush FROM sales_items WHERE sales_id = $editing_invoice_id");
                if ($prev_items) {
                    while ($prev = $prev_items->fetch_assoc()) {
                        $p_id_prev = intval($prev['product_id']);
                        $qty_prev = intval($prev['bush']);
                        if ($p_id_prev > 0 && $qty_prev > 0) {
                            $conn->query("UPDATE products SET quantity = quantity + $qty_prev, total = (quantity + $qty_prev) * buy_price WHERE id = $p_id_prev");
                        }
                    }
                }
                // 2. إلغاء القيود والتفاصيل القديمة
                $conn->query("DELETE FROM sales_items WHERE sales_id = $editing_invoice_id");
                $conn->query("DELETE FROM journal_entries WHERE ref_id = $editing_invoice_id AND ref_type = 'sale'");

                $sector_id_val = isset($_POST['sector_id']) && $_POST['sector_id'] !== '' ? intval($_POST['sector_id']) : 'NULL';

                // 3. تحديث الفاتورة الحالية في جداول الماستر السابقة والجديدة
                $sql_update = "UPDATE `sales` SET `build_date` = '$build_date', `cust_name` = '$customer_name', `total` = '$invoice_total_base', `prifet` = '$grand_profit_base', `remark` = '$remark', `currency_code` = '$currency_code', `exchange_rate` = '$exchange_rate', `remaining_total` = '$total_remaining_base', `box_id` = $active_box_id, `invoice_type` = '$invoice_type', `payment_method` = '$payment_method', `wallet_type` = '$wallet_type' WHERE `id` = $editing_invoice_id";
                if (!$conn->query($sql_update)) {
                    throw new Exception("فشل تحديث الفاتورة رقم #" . $editing_invoice_id);
                }
                $billing_id = $editing_invoice_id;

                $inv_no = 'INV-' . str_pad($billing_id, 6, '0', STR_PAD_LEFT);
                $conn->query("DELETE FROM sales_invoices_dtl WHERE invoice_id = $billing_id");
                $conn->query("UPDATE sales_invoices_mst SET invoice_date = '$build_date', cust_id = " . ($customer_id ?: 'NULL') . ", cust_name = '$customer_name', total_amount = '$invoice_total_base', net_amount = '$invoice_total_base', paid_amount = '$total_paid_base', remaining_amount = '$total_remaining_base', invoice_type = '$invoice_type', payment_method = '$payment_method', box_id = $active_box_id, sector_id = $sector_id_val, currency_code = '$currency_code', exchange_rate = '$exchange_rate', remark = '$remark' WHERE id = $billing_id OR invoice_no = '$inv_no'");
            } else {
                $sql_insert = "INSERT INTO `sales`(`build_date`, `cust_name`, `total`, `prifet`, `remark`, `delete_status`, `currency_code`, `exchange_rate`, `remaining_total`, `box_id`, `invoice_type`, `payment_method`, `wallet_type`) 
                               VALUES ('$build_date', '$customer_name', '$invoice_total_base', '$grand_profit_base', '$remark', 0, '$currency_code', '$exchange_rate', '$total_remaining_base', $active_box_id, '$invoice_type', '$payment_method', '$wallet_type')";
                if (!$conn->query($sql_insert)) {
                    throw new Exception("فشل حفظ رأس الفاتورة في قاعدة البيانات");
                }
                $billing_id = $conn->insert_id;

                $inv_no = 'INV-' . str_pad($billing_id, 6, '0', STR_PAD_LEFT);
                $sector_id_val = isset($_POST['sector_id']) && $_POST['sector_id'] !== '' ? intval($_POST['sector_id']) : 'NULL';
                $conn->query("INSERT INTO sales_invoices_mst (id, invoice_no, cust_id, cust_name, invoice_date, total_amount, discount_amount, net_amount, paid_amount, remaining_amount, invoice_type, payment_method, wallet_type, profit_total, box_id, sector_id, currency_code, exchange_rate, remark, d_s)
                              VALUES ($billing_id, '$inv_no', " . ($customer_id ?: 'NULL') . ", '$customer_name', '$build_date', '$invoice_total_base', 0, '$invoice_total_base', '$total_paid_base', '$total_remaining_base', '$invoice_type', '$payment_method', '$wallet_type', '$grand_profit_base', $active_box_id, $sector_id_val, '$currency_code', '$exchange_rate', '$remark', 0)
                              ON DUPLICATE KEY UPDATE cust_name = '$customer_name', total_amount = '$invoice_total_base', net_amount = '$invoice_total_base', paid_amount = '$total_paid_base', remaining_amount = '$total_remaining_base', sector_id = $sector_id_val");
            }

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
                if (empty($unit_name)) {
                    $unit_name = 'حبة';
                }

                $product_field_val = "$p_id $product_name_db";
                if (!empty($unit_name) && $unit_name !== 'حبة') {
                    $product_field_val .= " ($unit_name)";
                }

                $price_base = $price * $exchange_rate;
                $paid_base = $paid * $exchange_rate;
                $disc_base = $disc * $exchange_rate;
                $rem_base = $rem * $exchange_rate;
                $line_total_base = ($qty * $price) * $exchange_rate;
                $line_net_base = $paid_base + $rem_base;

                $unit_name_esc = $conn->real_escape_string($unit_name);
                $sql_item = "INSERT INTO `sales_items`(`sales_id`, `id`, `cust_name`, `name`, `quantity`, `unit_price`, `bush`, `d`, `dis`, `total`, `all_tot`, `build_date`, `unit_name`) 
                             VALUES ('$billing_id', '$p_id', '$customer_name', '$product_field_val', '$qty', '$price_base', '$paid_base', '$disc_base', '$rem_base', '$line_net_base', '$line_total_base', '$build_date', '$unit_name_esc')";
                if (!$conn->query($sql_item)) {
                    throw new Exception("فشل إدراج الصنف: " . $product_name_db);
                }
                $sales_item_id = $conn->insert_id;

                $conn->query("INSERT INTO sales_invoices_dtl (invoice_id, product_id, product_name, unit_name, quantity, unit_price, discount, total_price, d_s)
                              VALUES ($billing_id, $p_id, '$product_name_db', '$unit_name_esc', $qty, $price_base, $disc_base, $line_total_base, 0)");


                $base_qty = $qty * $conv_factor;
                
                // Decrement global products quantity
                $sql_update_qty = "UPDATE `products` SET `quantity` = `quantity` - $base_qty, total = quantity * buy_price WHERE `id` = $p_id";
                if (!$conn->query($sql_update_qty)) {
                    throw new Exception("فشل تحديث كمية المخزن للصنف: " . $product_name_db);
                }

                // Decrement warehouse stock (Main Warehouse ID 1)
                $conn->query("UPDATE `warehouses_stock` SET `quantity` = `quantity` - $base_qty WHERE `warehouse_id` = 1 AND `product_id` = $p_id");

                // Deduct from batches using FIFO and calculate COGS
                $cogs_base = \AQNEX\Services\InventoryService::deductStockAndGetCOGS($conn, $p_id, $base_qty);
                $item_profit_base = $line_total_base - $cogs_base - $disc_base;
                $calculated_grand_profit_base += $item_profit_base;
                $total_cost_base += $cogs_base;

                // Log dynamic inventory movement
                \AQNEX\Services\InventoryService::logInventoryAction($conn, [
                    'product_id'      => $p_id,
                    'action_type'     => 'out',
                    'quantity'        => $base_qty,
                    'cost_price'      => ($base_qty > 0) ? ($cogs_base / $base_qty) : 0,
                    'warehouse_id'    => 1,
                    'reference_table' => 'sales',
                    'reference_id'    => $billing_id,
                    'user_id'         => $_SESSION['SESS_FIRST_NAME'] ?? 'system'
                ]);

                if (is_module_enabled('serial_imei_tracking')) {
                    $serial_ids_str = isset($_POST['serial_ids'][$i]) ? trim($_POST['serial_ids'][$i]) : '';
                    if (!empty($serial_ids_str)) {
                        $serial_ids = explode(',', $serial_ids_str);
                        foreach ($serial_ids as $s_id) {
                            $s_id = intval($s_id);
                            if ($s_id > 0) {
                                if (!$conn->query("UPDATE `product_serials` SET `status` = 'sold', `sale_item_id` = $sales_item_id WHERE `id` = $s_id")) {
                                    throw new Exception("فشل حجز الأرقام التسلسلية IMEI");
                                }
                            }
                        }
                    }
                }

                if (!empty($customer_name) && $customer_name !== 'عميل نقدي' && $rem_base > 0) {
                    $sql_update_cust = "UPDATE `customers` SET `cust_madeen` = `cust_madeen` + $rem_base WHERE `cust_name` = '$customer_name'";
                    if (!$conn->query($sql_update_cust)) {
                        throw new Exception("فشل تحديث مديونية العميل المستحق");
                    }
                }
            }
        }

        // Update the sales record with true FIFO profit
        $conn->query("UPDATE `sales` SET `prifet` = $calculated_grand_profit_base WHERE `id` = $billing_id");

        $user_display = $_SESSION['SESS_FIRST_NAME'];

        if ($total_paid_base > 0) {
            if (!post_journal_entry($conn, 'sale', $billing_id, 'الصندوق - ' . $box_name, 'المبيعات', $total_paid_base, "مبيعات نقدية فاتورة #$billing_id - $customer_name", $user_display, $active_box_id, $currency_code, $exchange_rate, $sector_id)) {
                throw new Exception("فشل تسجيل قيد المقبوضات النقدية");
            }
        }

        if ($total_remaining_base > 0) {
            if (!post_journal_entry($conn, 'sale', $billing_id, 'الذمم المدينة - ' . $customer_name, 'المبيعات', $total_remaining_base, "مبيعات آجل فاتورة #$billing_id - $customer_name", $user_display, $active_box_id, $currency_code, $exchange_rate, $sector_id)) {
                throw new Exception("فشل تسجيل قيد مديونية العميل");
            }
        }

        if ($total_discount_base > 0) {
            if (!post_journal_entry($conn, 'sale', $billing_id, 'الخصم المسموح به (مصروف)', 'المبيعات', $total_discount_base, "خصم مبيعات فاتورة #$billing_id - $customer_name", $user_display, $active_box_id, $currency_code, $exchange_rate, $sector_id)) {
                throw new Exception("فشل تسجيل قيد الخصم المسموح به");
            }
        }

        if ($total_cost_base > 0) {
            if (!post_journal_entry($conn, 'sale', $billing_id, 'تكلفة البضاعة المباعة (مصروف)', 'المخزون / البضاعة', $total_cost_base, "إثبات تكلفة مبيعات فاتورة #$billing_id", $user_display, $active_box_id, $currency_code, $exchange_rate, $sector_id)) {
                throw new Exception("فشل تسجيل قيد تكلفة البضاعة المباعة");
            }
        }

        $conn->commit();

        if ($total_paid_base > 0) {
            update_box_balance($conn, $active_box_id, $total_paid_base, 'addition', "إيداع مبيعات نقدية فاتورة #$billing_id", $build_date);
            $conn->query("UPDATE sales SET is_transferred_to_box = 1 WHERE id = $billing_id");
        }

        /*
        if (!empty($customer_name) && $customer_name !== 'عميل نقدي') {
            $cust_esc = $conn->real_escape_string($customer_name);
            $res_cust_phone = $conn->query("SELECT phone FROM customers WHERE cust_name = '$cust_esc' LIMIT 1");
            if ($res_cust_phone && $res_cust_phone->num_rows > 0) {
                $cust_phone = $res_cust_phone->fetch_assoc()['phone'];
                if (!empty($cust_phone)) {
                    require_once($dir_prefix . 'app/Services/WhatsAppService.php');
                    $invoice_total = $total_paid_base + $total_remaining_base;
                    $msg = "شريكنا العزيز، تم تسجيل فاتورة مبيعات جديدة رقم #{$billing_id} باسمكم بمبلغ صافي: " . number_format($invoice_total, 2) . " ر.ي. شكراً لتعاملكم معنا.";
                    \AQNEX\Services\WhatsAppService::sendNotification($global_settings, $cust_phone, $msg);
                }
            }
        }
        */

        echo "<script>window.location='create.php?id=$billing_id&saved=1&autoprint=1&send_wa=1';</script>";
        exit;
    } catch (Exception $e) {
        $conn->rollback();
        $save_error = 'فشل حفظ الفاتورة: ' . $e->getMessage();
    }
    }
    }
?>
<?php
$products_json = '[]';

$currencies_list = [];
$res_curr = $conn->query("SELECT * FROM currencies ORDER BY id ASC");
if ($res_curr) {
    while($c = $res_curr->fetch_assoc()) {
        $currencies_list[] = $c;
    }
}
?>
<title>إضافة فاتورة مبيعات جديدة</title>

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
.modal-header.header-flat {
    background:var(--bg-primary);
    border-bottom: none;
    padding: 5px 8px;
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.modal-header.header-flat .close {
    color: #cfd3d8;
    opacity: 1;
    text-shadow: none;
    font-size: 1rem;
    font-weight: 400;
}
.modal-header.header-flat .close:hover {
    color: #ffffff;
}
.modal-header.header-flat .header-title-group {
    display: flex;
    align-items: center;
    gap: 10px;
}
.modal-header.header-flat .header-title-group .header-menu-icon {
    color: #cfd3d8;
    font-size: 0.95rem;
}
.modal-header.header-flat .modal-title {
    color: #f8fafc;
    font-size: 0.9rem;
    font-weight: 600;
    margin: 0;
    display: flex;
    align-items: center;
}
.modal-header.header-flat .modal-title i {
    font-size: 0.85rem;
    color: #cfd3d8;
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
.modal-body .form-control:focus {
    border-color: #1c2941;
    box-shadow: 0 0 0 0.15rem rgba(28, 41, 65, 0.12);
}
.modal-footer,
.modal-body .text-left {
    border-top: none;
}
.modal-body .btn-success {
    background-color: #11192c;
    border-color: #11192c;
    border-radius: 6px;
    font-size: 0.85rem;
}
.modal-body .btn-success:hover {
    background-color: #1c2941;
    border-color: #1c2941;
}
.modal-body .btn-secondary {
    border-radius: 6px;
    font-size: 0.85rem;
}
#quickAddCustomerError {
    font-size: 0.8rem;
    border-radius: 6px;
}
.quick-search-label {
    display: block;
    font-size: 0.85rem;
    font-weight: 700;
    color: #475569;
    margin-bottom: 6px;
}
#quickProductSearchInput {
    border-radius: 6px;
    font-size: 0.85rem;
    padding: 9px 12px;
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
.quick-product-table tbody .empty-row td,
.quick-product-table tbody .loading-row td {
    text-align: center;
    color: #94a3b8;
    padding: 22px 8px;
    border-bottom: none;
}
.quick-product-table .price-cell {
    color: #1c2941;
    font-weight: 700;
}
.quick-product-table .badge {
    font-size: 0.75rem;
    border-radius: 4px;
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
.accounting-guide .editable-amount:focus {
    border-color: #856404;
    outline: none;
    box-shadow: 0 0 0 0.2rem rgba(133, 100, 4, 0.25);
}

/* تحسين مظهر حقل البحث عن المنتج */
.product-search-input {
    cursor: pointer;
    transition: all 0.2s ease;
}
.product-search-input:focus {
    border-color: #1c2941;
    box-shadow: 0 0 0 0.15rem rgba(28, 41, 65, 0.12);
}

/* تبويبات المبيعات المتعددة */
.sales-tab-item {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 6px 14px;
    background: #f1f5f9;
    border: 1px solid #cbd5e1;
    color: #475569;
    font-size: 0.8rem;
    font-weight: 700;
    cursor: pointer;
    user-select: none;
    border-radius: 0 !important;
    transition: all 0.15s ease;
}
.sales-tab-item:hover {
    background: #e2e8f0;
    color: #1e293b;
}
.sales-tab-item.active {
    background: #1e40af;
    border-color: #1e40af;
    color: #ffffff;
}
.sales-tab-close-btn {
    border: none;
    background: transparent;
    color: inherit;
    font-size: 0.75rem;
    cursor: pointer;
    padding: 0 2px;
    opacity: 0.7;
    line-height: 1;
}
.sales-tab-close-btn:hover {
    opacity: 1;
}
</style>

<!-- Loading Overlay -->
<div class="loading-overlay" id="loadingOverlay">
    <div class="loading-spinner"></div>
    <div class="loading-text">جاري التحميل...</div>
    <div class="loading-bar">
        <div class="loading-progress"></div>
    </div>
</div>

<!-- Onyx Pro System Window Header Bar -->
<div class="aqnex-window-header no-print">
    <div>
        <i class="bi bi-window-stack text-primary ml-1"></i>
        <span>إدارة المبيعات - فاتورة مبيعات</span>
    </div>
    <div>
        <span class="ml-3">المستخدم: <strong><?php echo htmlspecialchars($_SESSION['SESS_FIRST_NAME'] ?? 'مدير النظام'); ?></strong></span>
        <span>التاريخ: <strong><?php echo date('Y/m/d'); ?></strong></span>
    </div>
</div>

<!-- Onyx Pro Action Toolbar (Large Icon Buttons with Hover Tooltips) -->
<div class="aqnex-toolbar no-print">
    <div style="display: flex; align-items: center; gap: 5px;">
        <!-- ➕ جديد (F2) -->
        <button type="button" class="tool-btn btn-new" title="جديد (F2) - فتح فاتورة جديدة بالمتصفح" onclick="window.open('create.php', '_blank');">
            <i class="bi bi-file-earmark-plus-fill"></i>
        </button>

        <!-- 💾 حفظ الفاتورة (F4 / Ctrl+S) -->
        <button type="submit" form="salesForm" name="btn_save" class="tool-btn btn-save btn-save-action" title="حفظ وترحيل الفاتورة (F10)">
            <i class="bi bi-floppy-fill"></i>
        </button>

        <!-- ✏️ تعديل الفاتورة -->
        <button type="button" class="tool-btn" title="تعديل فاتورة مبيعات محفوظة (F6)" onclick="openSearchInvoiceModal('edit');">
            <i class="bi bi-pencil-square" style="color: #d97706;"></i>
        </button>

        <!-- 🔍 البحث عن فاتورة مبيعات سابقة (F3) -->
        <button type="button" class="tool-btn btn-search" title="البحث عن فاتورة مبيعات سابقة (F3)" onclick="openSearchInvoiceModal('view');">
            <i class="bi bi-search"></i>
        </button>

        <!-- 🗑 حذف الفاتورة -->
        <button type="button" class="tool-btn btn-delete" title="حذف الفاتورة الحالية نهائياً من قاعدة البيانات" onclick="confirmDeleteCurrentInvoice();">
            <i class="bi bi-trash-fill"></i>
        </button>

        <!-- 📖 عرض القيود المحاسبية للفاتورة (F8) -->
        <button type="button" class="tool-btn" title="عرض القيود المحاسبية الآلية للفاتورة (F8)" onclick="openJournalModal();" style="color: #7c3aed; border-color: #ddd6fe;">
            <i class="bi bi-journal-bookmark-fill"></i>
        </button>

        <!-- 🔄 تراجع وتصفية الفاتورة -->
        <button type="button" class="tool-btn" title="تراجع وتصفية بيانات الفاتورة" onclick="resetSalesForm();">
            <i class="bi bi-arrow-counterclockwise" style="color: #0284c7;"></i>
        </button>
    </div>

    <!-- أزرار الجانب الأيسر -->
    <div style="margin-right: auto; display: flex; align-items: center; gap: 5px;">
        <!-- 💵 سند قبض -->
        <button type="button" class="tool-btn" title="تسجيل سند قبض جديد" onclick="window.location.href='../receipts/create.php';" style="color: #16a34a; border-color: #86efac;">
            <i class="bi bi-journal-plus"></i>
        </button>

        <!-- ↩️ مرتجع مبيعات -->
        <button type="button" class="tool-btn" title="الانتقال لشاشة مرتجعات المبيعات" onclick="window.location.href='returns.php';" style="color: #dc2626; border-color: #fca5a5;">
            <i class="bi bi-arrow-return-left"></i>
        </button>

        <!-- 🖨 طباعة (F9) -->
        <button type="button" class="tool-btn btn-print" title="طباعة تقرير الفاتورة (F9)" onclick="window.print();">
            <i class="bi bi-printer-fill"></i>
        </button>
    </div>
</div>

<form method="POST" id="salesForm">
<input type="hidden" name="editing_invoice_id" value="<?php echo $editing_invoice_id; ?>">

<?php if ($editing_invoice_id > 0): ?>
    <div class="alert alert-warning rounded-0 mb-3 text-right no-print" style="border: 1px solid #fbbf24; border-right: 4px solid #d97706 !important; background-color: #fffbeb; color: #92400e; padding: 10px 14px;">
        <i class="bi bi-pencil-square ml-1 font-weight-bold"></i>
        <strong>تأكيد التعديل:</strong> أنت تشاهد وتعدل الآن الفاتورة رقم <strong>#<?php echo $editing_invoice_id; ?></strong> (بتاريخ: <?php echo htmlspecialchars($editing_invoice['build_date'] ?? ''); ?> - العميل: <?php echo htmlspecialchars($editing_invoice['cust_name'] ?? ''); ?>). يمكنك تعديل أي حقل أو صنف ثم الضغط على <strong>حفظ (F10)</strong> لتحديث الفاتورة.
    <a href="create.php" class="btn btn-danger rounded text-white rounded btn-sm" title="الغاء التعديل والدخول لفاتورة جديدة" style="color: #fca5a5; cursor: pointer;"> <i class="bi bi-x-circle"></i></a>
    </div>
<?php endif; ?>

<?php if (!empty($returns_map)): ?>
    <div class="alert alert-info rounded-0 mb-3 text-right no-print" style="border: 1px solid #93c5fd; border-right: 4px solid #2563eb !important; background-color: #eff6ff; color: #1e40af; padding: 10px 14px;">
        <i class="bi bi-info-circle-fill ml-1 font-weight-bold"></i>
        <strong>تنبيه بشأن المردودات:</strong> تحتوي هذه الفاتورة على مردودات مبيعات مسجلة سابقاً. تم توضيح الكميات المرجعة لكل صنف للحفاظ على سلامة الحسابات والمخزون.
    </div>
<?php endif; ?>

<?php if (!empty($save_error)): ?>
    <script>
    document.addEventListener("DOMContentLoaded", function() {
        if (typeof showSystemAlert === 'function') {
            showSystemAlert("خطأ في حفظ الفاتورة", <?php echo json_encode($save_error); ?>, "danger");
        }
    });
    </script>
    <div class="alert alert-danger rounded-0 mb-3">
        <strong>خطأ في حفظ الفاتورة:</strong> <?php echo htmlspecialchars($save_error); ?>
    </div>
<?php endif; ?>

<!-- Onyx Pro Form Grid (البيانات الرئيسية) -->
<div class="aqnex-form-grid">
    <div class="row">
        <!-- العمود الأول -->
        <div class="col-md-4">
            <div class="aqnex-form-group">
                <label class="aqnex-label">القطاع / المركز:</label>
                <select name="sector_id" class="aqnex-select">
                    <option value="">عام</option>
                    <?php
                    $res_sec = $conn->query("SELECT id, name FROM sectors ORDER BY name ASC");
                    if ($res_sec) {
                        while($sec = $res_sec->fetch_assoc()) {
                            echo "<option value='{$sec['id']}'>" . htmlspecialchars($sec['name']) . "</option>";
                        }
                    }
                    ?>
                </select>
            </div>
            <div class="aqnex-form-group">
                <label class="aqnex-label">طريقة الدفع:</label>
                <select name="invoice_type" id="invoiceTypeSelect" class="aqnex-select" onchange="toggleSalesInvoiceType(this.value)" required>
                    <option value="cash" <?php echo ($editing_invoice && ($editing_invoice['invoice_type'] ?? '') === 'cash') ? 'selected' : ''; ?>>نقداً</option>
                    <option value="credit" <?php echo ($editing_invoice && ($editing_invoice['invoice_type'] ?? '') === 'credit') ? 'selected' : ''; ?>>آجل</option>
                    <option value="account" <?php echo ($editing_invoice && ($editing_invoice['invoice_type'] ?? '') === 'account') ? 'selected' : ''; ?>>من حساب</option>
                </select>
            </div>
        </div>

        <!-- العمود الثاني -->
        <div class="col-md-4">
            <div class="aqnex-form-group" id="salesBoxSection">
                <label class="aqnex-label">رقم الصندوق:</label>
                <?php if ($is_admin): ?>
                    <?php $default_box_id = $editing_invoice ? intval($editing_invoice['box_id']) : get_user_box_id($conn, $active_user_id); ?>
                    <select name="box_id" id="boxSelect" class="aqnex-select" required>
                        <?php
                        $res_b = $conn->query("SELECT box_id, name, mony FROM treasury WHERE is_active = 1 ORDER BY box_id ASC");
                        if ($res_b) {
                            while($b = $res_b->fetch_assoc()) {
                                $selected_attr = ($b['box_id'] == $default_box_id) ? 'selected' : '';
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
                    <input type="text" class="aqnex-input" readonly value="<?php echo htmlspecialchars(get_box_name($conn, $user_box_id)); ?>">
                <?php endif; ?>
            </div>

            <div class="aqnex-form-group">
                <label class="aqnex-label">العملة و الصرف:</label>
                <div style="display: flex; gap: 4px; width: 100%;">
                    <select name="currency_code" id="currencySelect" class="aqnex-select" required style="width: 60%;">
                        <?php foreach($currencies_list as $c): ?>
                            <option value="<?php echo htmlspecialchars($c['code']); ?>" data-rate="<?php echo $c['exchange_rate']; ?>" data-symbol="<?php echo htmlspecialchars($c['symbol']); ?>" <?php echo ($editing_invoice ? ($editing_invoice['currency_code'] === $c['code']) : ($c['code'] === 'YER')) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($c['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <input type="number" step="any" name="exchange_rate" id="exchangeRateInput" class="aqnex-input text-center" value="<?php echo $editing_invoice ? $editing_invoice['exchange_rate'] : '1.0'; ?>" readonly required style="width: 40%;">
                </div>
            </div>

            <div class="aqnex-form-group">
                <label class="aqnex-label">تاريخ الفاتورة:</label>
                <input type="date" name="build_date" class="aqnex-input" value="<?php echo $editing_invoice ? htmlspecialchars($editing_invoice['build_date']) : date('Y-m-d'); ?>" required>
            </div>
        </div>

        <!-- العمود الثالث -->
        <div class="col-md-4">
            <div class="aqnex-form-group">
                <label class="aqnex-label">اسم العميل:</label>
                <div style="display: flex; gap: 4px; width: 100%;">
                    <select name="customer_name" id="select2" class="aqnex-select" required>
                        <option value="عميل نقدي" <?php echo (!$editing_invoice || $editing_invoice['cust_name'] === 'عميل نقدي') ? 'selected' : ''; ?>>عميل نقدي</option>
                        <?php
                        $sql_cust = "SELECT cust_name FROM customers WHERE d_s = 0 ORDER BY cust_id DESC";
                        $res_cust = $conn->query($sql_cust);
                        if ($res_cust) {
                            while($row = $res_cust->fetch_assoc()) {
                                $sel_c = ($editing_invoice && $editing_invoice['cust_name'] === $row['cust_name']) ? 'selected' : '';
                                echo "<option value='".htmlspecialchars($row['cust_name'])."' $sel_c>".htmlspecialchars($row['cust_name'])."</option>";
                            }
                        }
                        ?>
                    </select>
                    <button type="button" class="btn btn-sm btn-outline-primary px-2" style="height: 26px; padding: 0 6px;" data-toggle="modal" data-target="#quickAddCustomerModal" title="عميل جديد">
                        <i class="bi bi-plus-lg"></i>
                    </button>
                </div>
            </div>

            <div class="aqnex-form-group" id="salesPaymentMethodSection">
                <label class="aqnex-label">طريقة المحفظة:</label>
                <select name="payment_method" id="salesPaymentMethodSelect" class="aqnex-select" onchange="toggleSalesWalletSection(this.value)">
                <option value=""></option>    
                <option value="cash">نقداً</option>
                    <option value="wallet">محفظة إلكترونية / بنك</option>
                </select>
            </div>

            <div class="aqnex-form-group d-none" id="salesWalletTypeSection">
                <label class="aqnex-label">الجهة البنكية:</label>
                <select id="salesWalletTypeSelect" class="aqnex-select">
<option value=""></option>
                    <option value="بنك الكريمي">بنك الكريمي</option>
                    <option value="جيب">جيب</option>
                    <option value="جوالي">جوالي</option>
                    <option value="بنك آخر">بنك آخر</option>
                </select>
            </div>
            <input type="hidden" name="wallet_type" id="salesWalletTypeHidden" value="">
        </div>
    </div>
</div>

            <div class="card p-3 bg-light border-0 mb-4 no-print">
                <div class="row align-items-center">
                    <div class="col-md-6 mb-2 mb-md-0">
                        <?php if ($settings && $settings['barcode_scanner'] == 1): ?>
                        <div class="input-group">
                            <div class="input-group-prepend">
                                <span class="input-group-text bg-primary text-white border-0">
                                    <i class="bi bi-upc-scan"></i>
                                </span>
                            </div>
                            <input type="text" id="barcodeScanInput" class="form-control rounded-0 border-primary font-weight-bold text-center" placeholder="امسح باركود المنتج هنا للادخال السريع..." autofocus autocomplete="off">
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-6 text-md-left">
                        <button type="button" id="quickProductSearchBtn" class="btn btn-outline-primary rounded-0 px-4 font-weight-bold">
                            <i class="bi bi-search ml-1"></i> F4 - البحث السريع عن المنتج
                        </button>
                    </div>
                </div>
            </div>

            <div id="creditLimitWarning" class="alert alert-warning d-none mb-3 text-right" dir="rtl"></div>

            <div class="table-responsive">
                <table class="aqnex-grid-table" id="itemsTable">
                    <thead>
                        <tr>
                            <th style="width: 25%;">اسم الصنف</th>
                            <th style="width: 8%;">الوحدة</th>
                            <th style="width: 8%;">المتوفر في المخزن</th>
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
                        <?php if ($editing_invoice_id > 0 && !empty($editing_items)): ?>
                            <?php foreach ($editing_items as $ei): ?>
                            <?php
                                // Fetch current stock and buy price for this product
                                $ei_stock = 0;
                                $ei_buy = 0;
                                $ei_product_id = intval($ei['id'] ?? ($ei['p_id'] ?? 0));
                                if ($ei_product_id > 0) {
                                    $stock_res = $conn->query("SELECT quantity, buy_price FROM products WHERE id = $ei_product_id LIMIT 1");
                                    if ($stock_res && $stock_res->num_rows > 0) {
                                        $st_row = $stock_res->fetch_assoc();
                                        $ei_stock = floatval($st_row['quantity']);
                                        $ei_buy   = floatval($st_row['buy_price']);
                                    }
                                }
                                $ei_qty        = floatval($ei['quantity'] ?? 1);
                                $ei_price      = floatval($ei['unit_price'] ?? 0);
                                $ei_total      = floatval($ei['all_tot'] ?? ($ei_qty * $ei_price));
                                $ei_paid       = floatval($ei['bush'] ?? 0);          // column: bush = paid
                                $ei_discount   = floatval($ei['d'] ?? 0);             // column: d = discount
                                $ei_remaining  = floatval($ei['dis'] ?? 0);           // column: dis = remaining
                                $ei_unit_name  = htmlspecialchars($ei['unit_name'] ?? 'حبة');
                                $ei_conv       = 1;
                                $ei_pname      = htmlspecialchars($ei['name'] ?? '');  // column: name

                                $ei_ret_qty = isset($returns_map[$ei_product_id]) ? floatval($returns_map[$ei_product_id]['qty']) : 0;
                            ?>
                            <tr class="item-row">
                                <td>
                                    <div class="product-search-container">
                                        <input type="text" class="aqnex-input product-search-input" placeholder="اكتب اسم أو باركود الصنف للبحث..." autocomplete="off" required style="height: 26px; text-align: right;" value="<?php echo $ei_pname; ?>">
                                        <input type="hidden" name="product_id[]" class="select-product" value="<?php echo $ei_product_id; ?>">
                                        <div class="autocomplete-dropdown d-none"></div>
                                    </div>
                                    <input type="hidden" name="buy_price[]" class="buy-price" value="<?php echo $ei_buy; ?>">
                                    <input type="hidden" name="conversion_factor[]" class="conversion-factor" value="<?php echo $ei_conv; ?>">
                                    <input type="hidden" name="unit_name[]" class="unit-name" value="<?php echo $ei_unit_name; ?>">
                                    <input type="hidden" name="unit_id[]" class="unit-id" value="">
                                    <input type="hidden" name="serial_ids[]" class="row-serial-ids" value="">
                                    <input type="hidden" name="batch_id[]" class="row-batch-id" value="">
                                    <div class="serial-sec d-none mt-2 text-right">
                                        <small class="text-primary font-weight-bold d-block mb-1">الأرقام التسلسلية المطلوبة (IMEI):</small>
                                        <select class="form-control form-control-sm serial-select select-multiple" multiple style="height: 60px; font-size: 0.8rem;"></select>
                                    </div>
                                    <div class="batch-sec d-none mt-2 text-right">
                                        <small class="text-danger font-weight-bold d-block mb-1">دفعة الصلاحية (FEFO تلقائي):</small>
                                        <select class="form-control form-control-sm batch-select rounded-0"></select>
                                    </div>
                                </td>
                                <td>
                                    <input type="text" class="aqnex-input unit-display-input text-center bg-light" readonly value="<?php echo $ei_unit_name; ?>">
                                </td>
                                <td>
                                    <input type="text" class="aqnex-input stock-qty text-center bg-light" readonly value="<?php echo $ei_stock; ?>">
                                </td>
                                <td>
                                    <input type="number" name="quantity[]" class="aqnex-input quantity-input text-center" min="1" value="<?php echo $ei_qty; ?>" required>
                                    <span class="row-stock-warning text-danger font-weight-bold d-none" style="font-size:0.75rem; display:block; margin-top:4px; text-align:center;"></span>
                                </td>
                                <td>
                                    <input type="number" step="any" name="unit_price[]" class="aqnex-input price-input text-center" required value="<?php echo $ei_price; ?>">
                                </td>
                                <td>
                                    <input type="text" class="aqnex-input total-input text-center bg-light" readonly value="<?php echo number_format($ei_total, 2, '.', ''); ?>">
                                </td>
                                <td>
                                    <input type="number" step="any" name="paid_amount[]" class="aqnex-input paid-input text-center" value="<?php echo $ei_paid; ?>">
                                </td>
                                <td>
                                    <input type="number" step="any" name="discount_amount[]" class="aqnex-input discount-input text-center" value="<?php echo $ei_discount; ?>">
                                </td>
                                <td>
                                    <input type="text" name="remaining_amount[]" class="aqnex-input remaining-input text-center bg-light" readonly value="<?php echo number_format($ei_remaining, 2, '.', ''); ?>">
                                    <input type="hidden" class="profit-input" name="profit[]" value="0">
                                </td>
                                <td class="no-print text-center">
                                    <button type="button" class="btn btn-sm btn-outline-danger p-1 remove-item-btn" style="height:24px; line-height:1;">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                        <tr class="item-row">
                            <td>
                                <div class="product-search-container">
                                    <input type="text" class="aqnex-input product-search-input" placeholder="اكتب اسم أو باركود الصنف للبحث..." autocomplete="off" required style="height: 26px; text-align: right;">
                                    <input type="hidden" name="product_id[]" class="select-product" value="">
                                    <div class="autocomplete-dropdown d-none"></div>
                                </div>
                                <input type="hidden" name="buy_price[]" class="buy-price" value="0">
                                <input type="hidden" name="conversion_factor[]" class="conversion-factor" value="1.0000">
                                <input type="hidden" name="unit_name[]" class="unit-name" value="حبةحبة">
                                <input type="hidden" name="unit_id[]" class="unit-id" value="">
                                <input type="hidden" name="serial_ids[]" class="row-serial-ids" value="">
                                <input type="hidden" name="batch_id[]" class="row-batch-id" value="">
                                <div class="serial-sec d-none mt-2 text-right">
                                    <small class="text-primary font-weight-bold d-block mb-1">الأرقام التسلسلية المطلوبة (IMEI):</small>
                                    <select class="form-control form-control-sm serial-select select-multiple" multiple style="height: 60px; font-size: 0.8rem;"></select>
                                </div>
                                <div class="batch-sec d-none mt-2 text-right">
                                    <small class="text-danger font-weight-bold d-block mb-1">دفعة الصلاحية (FEFO تلقائي):</small>
                                    <select class="form-control form-control-sm batch-select rounded-0"></select>
                                </div>
                            </td>
                            <td>
                                <input type="text" class="aqnex-input unit-display-input text-center bg-light" readonly value="حبة">
                            </td>
                            <td>
                                <input type="text" class="aqnex-input stock-qty text-center bg-light" readonly value="0">
                            </td>
                            <td>
                                <input type="number" name="quantity[]" class="aqnex-input quantity-input text-center" min="1" value="1" required>
                                <span class="row-stock-warning text-danger font-weight-bold d-none" style="font-size:0.75rem; display:block; margin-top:4px; text-align:center;"></span>
                            </td>
                            <td>
                                <input type="number" step="any" name="unit_price[]" class="aqnex-input price-input text-center" required>
                            </td>
                            <td>
                                <input type="text" class="aqnex-input total-input text-center bg-light" readonly value="0">
                            </td>
                            <td>
                                <input type="number" step="any" name="paid_amount[]" class="aqnex-input paid-input text-center" value="0">
                            </td>
                            <td>
                                <input type="number" step="any" name="discount_amount[]" class="aqnex-input discount-input text-center" value="0">
                            </td>
                            <td>
                                <input type="text" name="remaining_amount[]" class="aqnex-input remaining-input text-center bg-light" readonly value="0">
                                <input type="hidden" class="profit-input" name="profit[]" value="0">
                            </td>
                            <td class="no-print text-center">
                                <button type="button" class="btn btn-sm btn-outline-danger p-1 remove-item-btn" style="height:24px; line-height:1;">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="mt-2 no-print">
                <button type="button" id="addItemBtn" class="btn btn-sm font-weight-bold tool-btn">
                    <i class="bi bi-plus-circle ml-1 text-primary"  title="أضافة صنف جديد"></i>
                </button>
            </div>

            <!-- Onyx Summary Box -->
            <div class="row mt-3">
                <div class="col-md-7">
                    <div class="aqnex-form-group">
                        <label class="aqnex-label">بيان الفاتورة:</label>
                        <textarea name="remark" class=" w-100"  placeholder="ملاحظات الفاتورة وبيان العملية..." cols="30" rows=""><?php echo htmlspecialchars($editing_invoice['remark'] ?? ''); ?></textarea>
                    </div>
                </div>

                <div class="col-md-5">
                    <div class="aqnex-summary-box">
                        <div class="aqnex-summary-item">
                            <span class="label">إجمالي البنود:</span>
                            <span class="value"><span id="summarySubtotal">0.00</span> <small class="currency-symbol">ر.ي</small></span>
                        </div>
                        <div class="aqnex-summary-item">
                            <span class="label">الضريبة / الأعباء:</span>
                            <span class="value text-warning"><span id="summaryTax">0.00</span> <small class="currency-symbol">ر.ي</small></span>
                        </div>
                        <div class="aqnex-summary-item" style="background:#eff6ff; border-color:#93c5fd;">
                            <span class="label font-weight-bold" style="color:#1d4ed8;">الصافي الإجمالي:</span>
                            <span class="value text-primary" style="font-size:1.1rem;"><span id="summaryNetTotal">0.00</span> <small class="currency-symbol">ر.ي</small></span>
                        </div>
                        <div class="aqnex-summary-item">
                            <span class="label font-weight-bold" style="color:#15803d;">إجمالي المقبوض:</span>
                            <span class="value text-success"><input type="text" id="grandPaidDisplay" name="grand_paid" class="border-0 bg-transparent text-left font-weight-bold text-success p-0" style="width:90px; height:auto; outline:none;" readonly value="0"> <small class="currency-symbol">ر.ي</small></span>
                        </div>
                        <div class="aqnex-summary-item">
                            <span class="label font-weight-bold" style="color:#b91c1c;">إجمالي المتبقي:</span>
                            <span class="value text-danger"><span id="grandRemainingDisplay">0.00</span> <small class="currency-symbol">ر.ي</small></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Onyx Pro User Audit Bar -->
            <div class="aqnex-audit-bar no-print">
                <div>
                    <i class="bi bi-person-fill ml-1"></i> مدخل السجل: <strong><?php echo htmlspecialchars($_SESSION['SESS_FIRST_NAME'] ?? 'مدير النظام'); ?></strong>
                </div>
                <div>
                    <i class="bi bi-clock-history ml-1"></i> تاريخ الإدخال: <strong><?php echo date('Y-m-d H:i'); ?></strong>
                </div>
                <div>
                    <i class="bi bi-pc-display ml-1"></i> الجهاز: <strong><?php echo gethostbyaddr($_SERVER['REMOTE_ADDR']); ?></strong>
                </div>
            </div>

                    <div class="accounting-guide">
                        <h6><i class="fa fa-book ml-2"></i>الدليل المحاسبي - القيود المالية</h6>
                        <table>
                            <thead>
                                <tr>
                                    <th>البيان</th>
                                    <th>الحساب المدين</th>
                                    <th>المبلغ</th>
                                    <th>الحساب الدائن</th>
                                    <th>المبلغ</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>المقبوضات النقدية</td>
                                    <td>الصندوق / البنك</td>
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
                                    <td>الخصم المسموح به</td>
                                    <td>مصروفات الخصم</td>
                                    <td><input type="number" step="any" class="editable-amount" id="acc_discount" value="0" readonly></td>
                                    <td>المبيعات</td>
                                    <td><input type="number" step="any" class="editable-amount" id="acc_discount_credit" value="0" readonly></td>
                                </tr>
                                <tr>
                                    <td>تكلفة البضاعة المباعة</td>
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


        </form>
    </div>
</div>

<!-- مودال تأكيد الخروج من الصفحة -->
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
                <p class="mb-2 font-weight-bold">أنت على وشك مغادرة صفحة إنشاء الفاتورة.</p>
                <p class="text-danger">جميع البيانات المدخلة ستضيع ولن يتم حفظها. هل تريد المتابعة أم تأكيد الخروج؟</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">إلغاء (البقاء في الصفحة)</button>
                <button type="button" id="confirmLeaveBtn" class="btn btn-danger">خروج بدون حفظ</button>
            </div>
        </div>
    </div>
</div>

<script type="text/javascript">
const availableProducts = <?php echo $products_json; ?>;
const actualJournalEntries = <?php echo json_encode($actual_entries); ?>;
window.actualJournalEntries = actualJournalEntries; // expose globally for journal modal
const isSerialModuleEnabled = <?php echo is_module_enabled('serial_imei_tracking') ? 'true' : 'false'; ?>;
const isExpiryModuleEnabled = <?php echo is_module_enabled('expiry_tracking') ? 'true' : 'false'; ?>;
const taxPercent = <?php echo floatval($global_settings['tax_percent'] ?? 0); ?>;

let salesFormDirty = false;
let salesFormSubmitting = false;
let pendingLeaveUrl = null;

// إدارة تبويبات الفواتير المتعددة (Multi-Invoice Tab Management)
let tabs = [];
let activeTabId = null;
let nextTabId = 1;

function escapeHtml(text) {
    if (!text) return '';
    return text
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}

function resetRow(row) {
    row.querySelector('.select-product').value = '';
    row.querySelector('.select-product').removeAttribute('data-base-sale-price');
    row.querySelector('.select-product').removeAttribute('data-base-buy-price');
    row.querySelector('.product-search-input').value = '';
    row.querySelector('.buy-price').value = '0';
    row.querySelector('.conversion-factor').value = '1.0000';
    row.querySelector('.unit-name').value = 'حبة';
    row.querySelector('.unit-id').value = '';
    row.querySelector('.row-serial-ids').value = '';
    row.querySelector('.row-batch-id').value = '';
    row.querySelector('.stock-qty').value = '0';
    row.querySelector('.quantity-input').value = '1';
    row.querySelector('.price-input').value = '0.00';
    row.querySelector('.total-input').value = '0.00';
    row.querySelector('.paid-input').value = '0.00';
    row.querySelector('.paid-input').removeAttribute('data-manually-edited');
    row.querySelector('.discount-input').value = '0';
    row.querySelector('.remaining-input').value = '0.00';
    row.querySelector('.profit-input').value = '0.00';
    row.querySelector('.unit-display-input').value = 'حبة';
    row.querySelector('.serial-sec').classList.add('d-none');
    row.querySelector('.serial-select').innerHTML = '';
    row.querySelector('.batch-sec').classList.add('d-none');
    row.querySelector('.batch-select').innerHTML = '';
}

function saveCurrentTabState() {
    if (activeTabId === null) return;
    const tab = tabs.find(t => t.id === activeTabId);
    if (!tab) return;

    tab.build_date = document.querySelector('[name="build_date"]').value;
    tab.customer_name = document.querySelector('#select2').value;
    tab.invoice_type = document.querySelector('#invoiceTypeSelect').value;
    tab.currency_code = document.querySelector('#currencySelect').value;
    tab.exchange_rate = document.querySelector('#exchangeRateInput').value;
    tab.box_id = document.querySelector('#boxSelect') ? document.querySelector('#boxSelect').value : '';
    tab.payment_method = document.querySelector('#paymentMethodSelect') ? document.querySelector('#paymentMethodSelect').value : 'cash';
    tab.wallet_type = document.querySelector('#salesWalletTypeSelect') ? document.querySelector('#salesWalletTypeSelect').value : '';
    tab.remark = document.querySelector('[name="remark"]').value;

    tab.items = [];
    document.querySelectorAll('#itemsContainer .item-row').forEach(row => {
        const prodId = row.querySelector('.select-product').value;
        
        let serialsHtml = '';
        let serialsVal = [];
        const serialSelect = row.querySelector('.serial-select');
        if (serialSelect) {
            serialsHtml = serialSelect.innerHTML;
            serialsVal = Array.from(serialSelect.selectedOptions).map(o => o.value);
        }

        let batchesHtml = '';
        let batchesVal = '';
        const batchSelect = row.querySelector('.batch-select');
        if (batchSelect) {
            batchesHtml = batchSelect.innerHTML;
            batchesVal = batchSelect.value;
        }

        tab.items.push({
            product_id: prodId,
            product_search: row.querySelector('.product-search-input').value,
            buy_price: row.querySelector('.buy-price').value,
            conversion_factor: row.querySelector('.conversion-factor').value,
            unit_name: row.querySelector('.unit-name').value,
            unit_id: row.querySelector('.unit-id').value,
            serial_ids: row.querySelector('.row-serial-ids').value,
            batch_id: row.querySelector('.row-batch-id').value,
            stock: row.querySelector('.stock-qty').value,
            quantity: row.querySelector('.quantity-input').value,
            unit_price: row.querySelector('.price-input').value,
            total: row.querySelector('.total-input').value,
            paid: row.querySelector('.paid-input').value,
            paid_manually: row.querySelector('.paid-input').hasAttribute('data-manually-edited'),
            discount: row.querySelector('.discount-input').value,
            remaining: row.querySelector('.remaining-input').value,
            profit: row.querySelector('.profit-input').value,
            unit_display: row.querySelector('.unit-display-input').value,
            serial_sec_visible: !row.querySelector('.serial-sec').classList.contains('d-none'),
            batch_sec_visible: !row.querySelector('.batch-sec').classList.contains('d-none'),
            serials_html: serialsHtml,
            serials_val: serialsVal,
            batches_html: batchesHtml,
            batches_val: batchesVal
        });
    });
}

function loadTabState(tabId) {
    const tab = tabs.find(t => t.id === tabId);
    if (!tab) return;

    activeTabId = tabId;

    document.querySelector('[name="build_date"]').value = tab.build_date;
    document.querySelector('#select2').value = tab.customer_name;
    document.querySelector('#invoiceTypeSelect').value = tab.invoice_type;
    toggleSalesInvoiceType(tab.invoice_type);

    document.querySelector('#currencySelect').value = tab.currency_code;
    document.querySelector('#exchangeRateInput').value = tab.exchange_rate;
    if (document.querySelector('#boxSelect')) {
        document.querySelector('#boxSelect').value = tab.box_id;
    }
    if (document.querySelector('#paymentMethodSelect')) {
        document.querySelector('#paymentMethodSelect').value = tab.payment_method;
        toggleSalesWalletSection(tab.payment_method);
    }
    if (document.querySelector('#salesWalletTypeSelect')) {
        document.querySelector('#salesWalletTypeSelect').value = tab.wallet_type;
    }
    document.querySelector('[name="remark"]').value = tab.remark;

    if (typeof fetchCustomerDetails === 'function') {
        fetchCustomerDetails(tab.customer_name);
    }

    const container = document.getElementById("itemsContainer");
    if (!container) return;
    container.innerHTML = '';
    
    if (tab.items.length === 0) {
        const newRow = rowTemplate.cloneNode(true);
        resetRow(newRow);
        container.appendChild(newRow);
    } else {
        tab.items.forEach(item => {
            const row = rowTemplate.cloneNode(true);
            row.querySelector('.select-product').value = item.product_id;
            
            const rate = parseFloat(tab.exchange_rate) || 1.0;
            const baseSalePrice = (parseFloat(item.unit_price) * rate);
            const baseBuyPrice = (parseFloat(item.buy_price) * rate);
            row.querySelector('.select-product').setAttribute('data-base-sale-price', baseSalePrice);
            row.querySelector('.select-product').setAttribute('data-base-buy-price', baseBuyPrice);

            row.querySelector('.product-search-input').value = item.product_search;
            row.querySelector('.buy-price').value = item.buy_price;
            row.querySelector('.conversion-factor').value = item.conversion_factor;
            row.querySelector('.unit-name').value = item.unit_name;
            row.querySelector('.unit-id').value = item.unit_id;
            row.querySelector('.row-serial-ids').value = item.serial_ids;
            row.querySelector('.row-batch-id').value = item.batch_id;
            row.querySelector('.stock-qty').value = item.stock;
            row.querySelector('.quantity-input').value = item.quantity;
            row.querySelector('.price-input').value = item.unit_price;
            row.querySelector('.total-input').value = item.total;
            row.querySelector('.paid-input').value = item.paid;
            if (item.paid_manually) {
                row.querySelector('.paid-input').setAttribute('data-manually-edited', 'true');
            } else {
                row.querySelector('.paid-input').removeAttribute('data-manually-edited');
            }
            row.querySelector('.discount-input').value = item.discount;
            row.querySelector('.remaining-input').value = item.remaining;
            row.querySelector('.profit-input').value = item.profit;
            row.querySelector('.unit-display-input').value = item.unit_display;

            const serialSec = row.querySelector('.serial-sec');
            const serialSelect = row.querySelector('.serial-select');
            if (item.serial_sec_visible) {
                serialSec.classList.remove('d-none');
                serialSelect.innerHTML = item.serials_html;
                Array.from(serialSelect.options).forEach(opt => {
                    opt.selected = item.serials_val.includes(opt.value);
                });
            } else {
                serialSec.classList.add('d-none');
                serialSelect.innerHTML = '';
            }

            const batchSec = row.querySelector('.batch-sec');
            const batchSelect = row.querySelector('.batch-select');
            if (item.batch_sec_visible) {
                batchSec.classList.remove('d-none');
                batchSelect.innerHTML = item.batches_html;
                batchSelect.value = item.batches_val;
            } else {
                batchSec.classList.add('d-none');
                batchSelect.innerHTML = '';
            }

            container.appendChild(row);
        });
    }

    renderTabs();
    updateGrandTotals();
    updateAccountingGuide();
}

function openNewSalesTab() {
    if (activeTabId !== null) {
        saveCurrentTabState();
    }

    const tabId = nextTabId++;
    const tabName = `فاتورة عميل ${tabId}`;
    
    const newTab = {
        id: tabId,
        name: tabName,
        build_date: new Date().toISOString().split('T')[0],
        customer_name: '',
        invoice_type: 'cash',
        currency_code: 'YER',
        exchange_rate: '1.0000',
        box_id: document.querySelector('#boxSelect') ? document.querySelector('#boxSelect').options[0]?.value : '1',
        payment_method: 'cash',
        wallet_type: '',
        remark: '',
        items: []
    };

    tabs.push(newTab);
    loadTabState(tabId);
}

function closeTab(tabId, event) {
    if (event) event.stopPropagation();
    
    const idx = tabs.findIndex(t => t.id === tabId);
    if (idx === -1) return;

    tabs.splice(idx, 1);
    if (activeTabId === tabId) {
        if (tabs.length > 0) {
            const nextActive = tabs[Math.min(idx, tabs.length - 1)].id;
            loadTabState(nextActive);
        } else {
            activeTabId = null;
            openNewSalesTab();
        }
    } else {
        renderTabs();
    }
    saveTabsToLocalStorage();
}

function switchTab(tabId) {
    if (activeTabId === tabId) return;
    saveCurrentTabState();
    loadTabState(tabId);
    saveTabsToLocalStorage();
}

function renderTabs() {
    const container = document.getElementById("sales-tabs-list");
    if (!container) return;
    
    let html = "";
    tabs.forEach(tab => {
        const activeClass = tab.id === activeTabId ? "active" : "";
        html += `
            <div class="sales-tab-item ${activeClass}" onclick="switchTab(${tab.id})">
                <span>${escapeHtml(tab.name)}</span>
                <button type="button" class="sales-tab-close-btn" onclick="closeTab(${tab.id}, event)">&times;</button>
            </div>
        `;
    });
    container.innerHTML = html;
}

function saveTabsToLocalStorage() {
    saveCurrentTabState();
    localStorage.setItem('aqnex_sales_tabs', JSON.stringify(tabs));
    localStorage.setItem('aqnex_sales_active_tab_id', activeTabId);
}

function loadTabsFromLocalStorage() {
    try {
        const storedTabs = localStorage.getItem('aqnex_sales_tabs');
        const storedActiveId = localStorage.getItem('aqnex_sales_active_tab_id');
        if (storedTabs) {
            const parsed = JSON.parse(storedTabs);
            if (parsed && parsed.length > 0) {
                tabs = parsed;
                activeTabId = parseInt(storedActiveId);
                nextTabId = Math.max(...tabs.map(t => t.id), 0) + 1;
                
                if (activeTabId && tabs.some(t => t.id === activeTabId)) {
                    loadTabState(activeTabId);
                } else if (tabs.length > 0) {
                    loadTabState(tabs[0].id);
                }
                return true;
            }
        }
    } catch(e) {
        console.error("Error loading tabs from localStorage", e);
    }
    return false;
}

window.openNewSalesTab = openNewSalesTab;
window.switchTab = switchTab;
window.closeTab = closeTab;
window.saveCurrentTabState = saveCurrentTabState;
window.loadTabState = loadTabState;


document.addEventListener('DOMContentLoaded', function() {
    setTimeout(() => { salesFormDirty = false; }, 300);

    const salesForm = document.getElementById('salesForm');
    if (salesForm) {
        salesForm.addEventListener('change', () => { salesFormDirty = true; });
        salesForm.addEventListener('input',  () => { salesFormDirty = true; });
        salesForm.addEventListener('submit', () => { 
            saveCurrentTabState(); 
            // Remove the active tab from tabs array so it's not restored
            const activeIdx = tabs.findIndex(t => t.id === activeTabId);
            if (activeIdx !== -1) {
                tabs.splice(activeIdx, 1);
            }
            localStorage.setItem('aqnex_sales_tabs', JSON.stringify(tabs));
            localStorage.removeItem('aqnex_sales_active_tab_id');
            salesFormSubmitting = true; 
            salesFormDirty = false; 
        });

        let autoSaveTimeout = null;
        function triggerAutoSave() {
            clearTimeout(autoSaveTimeout);
            autoSaveTimeout = setTimeout(() => {
                saveTabsToLocalStorage();
            }, 500);
        }
        salesForm.addEventListener('input', triggerAutoSave);
        salesForm.addEventListener('change', triggerAutoSave);
    }

    const btnSave = document.getElementById('btnSaveSales');
    if (btnSave) {
        btnSave.addEventListener('click', () => { 
            salesFormSubmitting = true; 
        });
    }

    // تهيئة أو استرجاع التبويبات بعد تحميل الصفحة تماماً
    const editingInvoiceId = <?php echo intval($editing_invoice_id); ?>;

    setTimeout(() => {
        if (editingInvoiceId > 0) {
            // وضع التعديل: لا نستعيد من localStorage حتى لا يطغى على الأصناف المحملة من PHP
            localStorage.removeItem('aqnex_sales_tabs');
            localStorage.removeItem('aqnex_sales_active_tab_id');

            const firstTabId = nextTabId++;
            tabs.push({
                id: firstTabId,
                name: "تعديل فاتورة #" + editingInvoiceId,
                build_date: document.querySelector('[name="build_date"]').value,
                customer_name: document.querySelector('#select2').value,
                invoice_type: document.querySelector('#invoiceTypeSelect').value,
                currency_code: document.querySelector('#currencySelect').value,
                exchange_rate: document.querySelector('#exchangeRateInput').value,
                box_id: document.querySelector('#boxSelect') ? document.querySelector('#boxSelect').value : '',
                payment_method: document.querySelector('#paymentMethodSelect') ? document.querySelector('#paymentMethodSelect').value : 'cash',
                wallet_type: document.querySelector('#salesWalletTypeSelect') ? document.querySelector('#salesWalletTypeSelect').value : '',
                remark: document.querySelector('[name="remark"]').value,
                items: []
            });
            activeTabId = firstTabId;
            saveCurrentTabState();
            renderTabs();

            // احسب الإجماليات من الأصناف المحملة
            document.querySelectorAll("#itemsContainer .item-row").forEach(row => {
                updateRowCalculations(row);
            });
            updateGrandTotals();

            // طبّق حالة نوع الفاتورة (آجل يقفل المدفوع)
            const invType = document.querySelector('#invoiceTypeSelect');
            if (invType) toggleSalesInvoiceType(invType.value);

        } else {
            const loaded = loadTabsFromLocalStorage();
            if (!loaded) {
                const firstTabId = nextTabId++;
                tabs.push({
                    id: firstTabId,
                    name: "فاتورة عميل 1",
                    build_date: document.querySelector('[name="build_date"]').value,
                    customer_name: document.querySelector('#select2').value,
                    invoice_type: document.querySelector('#invoiceTypeSelect').value,
                    currency_code: document.querySelector('#currencySelect').value,
                    exchange_rate: document.querySelector('#exchangeRateInput').value,
                    box_id: document.querySelector('#boxSelect') ? document.querySelector('#boxSelect').value : '',
                    payment_method: document.querySelector('#paymentMethodSelect') ? document.querySelector('#paymentMethodSelect').value : 'cash',
                    wallet_type: document.querySelector('#salesWalletTypeSelect') ? document.querySelector('#salesWalletTypeSelect').value : '',
                    remark: document.querySelector('[name="remark"]').value,
                    items: []
                });
                activeTabId = firstTabId;
                saveCurrentTabState(); // Capture existing items/state
                renderTabs();
            }
        }
    }, 150);

    document.querySelectorAll('a[href]').forEach(function(link) {
        const href = link.getAttribute('href');
        if (!href || href.startsWith('#') || href.startsWith('javascript') || href.startsWith('mailto')) return;
        link.addEventListener('click', function(e) {
            if (salesFormDirty && !salesFormSubmitting) {
                e.preventDefault();
                const pendingUrl = link.href;
                if (typeof AqnexConfirm !== 'undefined') {
                    AqnexConfirm.show('تحذير: بيانات غير محفوظة! أنت على وشك مغادرة صفحة إنشاء الفاتورة. جميع البيانات المدخلة ستضيع ولن يتم حفظها. هل تريد المتابعة أم تأكيد الخروج؟', function(confirmed) {
                        if (confirmed) {
                            salesFormDirty = false;
                            window.location.href = pendingUrl;
                        }
                    });
                } else {
                    if (confirm('تحذير: بيانات غير محفوظة! هل تريد المتابعة أم تأكيد الخروج؟')) {
                        salesFormDirty = false;
                        window.location.href = pendingUrl;
                    }
                }
            }
        });
    });
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
        if (boxSection) {
            boxSection.classList.add('d-none');
            boxSection.style.cssText = 'display: none !important;';
        }
        if (paymentSection) {
            paymentSection.classList.add('d-none');
            paymentSection.style.cssText = 'display: none !important;';
        }
        document.querySelectorAll('.paid-input').forEach(inp => {
            inp.value = '0';
            inp.setAttribute('readonly', 'readonly');
            inp.setAttribute('data-manually-edited', 'true');
        });

        // تنبيه فوري لتحديد عميل آجل عند اختيار نوع الفاتورة آجل
        const custSelect = document.getElementById('select2');
        const custVal = custSelect ? custSelect.value.trim() : '';
        if (custVal === 'عميل نقدي' || custVal === '') {
            if (typeof AqnexAlert !== 'undefined') {
                AqnexAlert.show(122, AQNEX_MESSAGES[122] || 'تنبيه: الفاتورة الآجلة تتطلب تحديد عميل آجل مسجل وليس عميل نقدي!', custSelect);
            } else {
                alert('تنبيه: الفاتورة الآجلة تتطلب تحديد عميل آجل مسجل وليس عميل نقدي!');
            }
        }
    } else {
        if (boxSection) {
            boxSection.classList.remove('d-none');
            boxSection.style.cssText = '';
        }
        if (paymentSection) {
            paymentSection.classList.remove('d-none');
            paymentSection.style.cssText = '';
        }
        document.querySelectorAll('.paid-input').forEach(inp => {
            inp.removeAttribute('readonly');
            inp.removeAttribute('data-manually-edited');
        });
        if (val === 'cash') {
            if (boxSection) {
                boxSection.classList.remove('d-none');
                boxSection.style.cssText = '';
            }
        } else {
            if (boxSection) {
                boxSection.classList.add('d-none');
                boxSection.style.cssText = 'display: none !important;';
            }
        }
    }

    document.querySelectorAll(".item-row").forEach(function(row) {
        updateRowCalculations(row);
    });
    updateGrandTotals();
    checkRealTimeWarnings();
}
window.toggleSalesInvoiceType = toggleSalesInvoiceType;

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

    // دالة إغلاق المودال بشكل كامل وآمن
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
        if (backdrop) {
            backdrop.remove();
        }
    }

    function updateRowCalculations(row) {
        const invTypeSelect = document.getElementById('invoiceTypeSelect');
        const invType = invTypeSelect ? invTypeSelect.value : 'cash';

        const qty = parseInt(row.querySelector(".quantity-input").value) || 0;
        const price = parseFloat(row.querySelector(".price-input").value) || 0;

        const lineTotal = qty * price;
        row.querySelector(".total-input").value = lineTotal.toFixed(2);

        const paidInput = row.querySelector(".paid-input");

        if (invType === 'credit') {
            paidInput.value = '0';
            paidInput.setAttribute('readonly', 'readonly');
        } else {
            paidInput.removeAttribute('readonly');
            if (!paidInput.hasAttribute("data-manually-edited")) {
                paidInput.value = lineTotal.toFixed(2);
            }
        }

        const paid = (invType === 'credit') ? 0 : (parseFloat(paidInput.value) || 0);
        const disc = parseFloat(row.querySelector(".discount-input").value) || 0;
        const buyPrice = parseFloat(row.querySelector(".buy-price").value) || 0;

        const remaining = (invType === 'credit') ? Math.max(0, lineTotal - disc) : Math.max(0, lineTotal - paid - disc);
        row.querySelector(".remaining-input").value = remaining.toFixed(2);

        const profit = lineTotal - (buyPrice * qty) - disc;
        row.querySelector(".profit-input").value = profit.toFixed(2);

        updateGrandTotals();
        updateAccountingGuide();
    }

    function updateGrandTotals() {
        const invTypeSelect = document.getElementById('invoiceTypeSelect');
        const invType = invTypeSelect ? invTypeSelect.value : 'cash';

        let subtotal = 0;
        let totalPaid = 0;
        let totalDiscount = 0;
        let totalRemaining = 0;
        let totalProfit = 0;

        document.querySelectorAll(".item-row").forEach(function(row) {
            const qty = parseInt(row.querySelector(".quantity-input").value) || 0;
            const price = parseFloat(row.querySelector(".price-input").value) || 0;
            const disc = parseFloat(row.querySelector(".discount-input").value) || 0;
            const profit = parseFloat(row.querySelector(".profit-input").value) || 0;

            let paid = parseFloat(row.querySelector(".paid-input").value) || 0;
            if (invType === 'credit') {
                paid = 0;
                row.querySelector(".paid-input").value = '0';
                row.querySelector(".paid-input").setAttribute('readonly', 'readonly');
            }

            const rem = (invType === 'credit') ? Math.max(0, (qty * price) - disc) : parseFloat(row.querySelector(".remaining-input").value) || 0;

            subtotal += (qty * price);
            totalPaid += paid;
            totalDiscount += disc;
            totalRemaining += rem;
            totalProfit += profit;
        });

        const taxVal = (subtotal * taxPercent) / 100;
        const netTotal = subtotal + taxVal - totalDiscount;

        if (invType === 'credit') {
            totalPaid = 0;
            totalRemaining = netTotal;
        }

        const subtotalEl = document.getElementById("summarySubtotal");
        if (subtotalEl) subtotalEl.textContent = subtotal.toFixed(2);
        
        const taxEl = document.getElementById("summaryTax");
        if (taxEl) taxEl.textContent = taxVal.toFixed(2);

        const netTotalEl = document.getElementById("summaryNetTotal");
        if (netTotalEl) netTotalEl.textContent = netTotal.toFixed(2);

        const grandPaidEl = document.getElementById("grandPaidDisplay");
        if (grandPaidEl) grandPaidEl.value = totalPaid.toFixed(2);

        const grandRemEl = document.getElementById("grandRemainingDisplay");
        if (grandRemEl) grandRemEl.textContent = totalRemaining.toFixed(2);

        const grandProfEl = document.getElementById("grandProfitDisplay");
        if (grandProfEl) grandProfEl.value = totalProfit.toFixed(2);

        updateAccountingGuide();
        checkRealTimeWarnings();
    }

    function updateAccountingGuide() {
        const invTypeSelect = document.getElementById('invoiceTypeSelect');
        const invType = invTypeSelect ? invTypeSelect.value : 'cash';

        let totalPaid = 0;
        let totalRemaining = 0;
        let totalDiscount = 0;
        let totalCost = 0;

        document.querySelectorAll(".item-row").forEach(function(row) {
            const qty = parseInt(row.querySelector(".quantity-input")?.value) || 0;
            const paid = (invType === 'credit') ? 0 : (parseFloat(row.querySelector(".paid-input")?.value) || 0);
            const remaining = parseFloat(row.querySelector(".remaining-input")?.value) || 0;
            const discount = parseFloat(row.querySelector(".discount-input")?.value) || 0;
            const buyPrice = parseFloat(row.querySelector(".buy-price")?.value) || 0;

            totalPaid += paid;
            totalRemaining += remaining;
            totalDiscount += discount;
            totalCost += (qty * buyPrice);
        });

        const elCash = document.getElementById("acc_cash_received");
        if (elCash) elCash.value = totalPaid.toFixed(2);
        const elCashCred = document.getElementById("acc_cash_received_credit");
        if (elCashCred) elCashCred.value = totalPaid.toFixed(2);

        const elCredSales = document.getElementById("acc_credit_sales");
        if (elCredSales) elCredSales.value = totalRemaining.toFixed(2);
        const elCredSalesCred = document.getElementById("acc_credit_sales_credit");
        if (elCredSalesCred) elCredSalesCred.value = totalRemaining.toFixed(2);

        const elDisc = document.getElementById("acc_discount");
        if (elDisc) elDisc.value = totalDiscount.toFixed(2);
        const elDiscCred = document.getElementById("acc_discount_credit");
        if (elDiscCred) elDiscCred.value = totalDiscount.toFixed(2);

        const elCogs = document.getElementById("acc_cogs");
        if (elCogs) elCogs.value = totalCost.toFixed(2);
        const elCogsCred = document.getElementById("acc_cogs_credit");
        if (elCogsCred) elCogsCred.value = totalCost.toFixed(2);
    }

    function checkRealTimeWarnings() {
        const customerSelect = document.getElementById("select2");
        const customerName = customerSelect ? customerSelect.value : "";
        const remainingSpan = document.getElementById("grandRemainingDisplay");
        const remainingTotal = remainingSpan ? parseFloat(remainingSpan.textContent) || 0 : 0;
        
        const warningDiv = document.getElementById("creditLimitWarning");
        if (warningDiv) {
            if (customerName && customerName !== "عميل نقدي" && currentCustomerDetails.id > 0 && remainingTotal > 0) {
                const newBalance = currentCustomerDetails.balance + remainingTotal;
                if (newBalance > currentCustomerDetails.credit_limit) {
                    warningDiv.innerHTML = `⚠️ <strong>تجاوز حد الدين للعميل:</strong> مديونية العميل بعد هذه الفاتورة (${newBalance.toFixed(2)} ر.ي) ستتجاوز الحد الائتماني المسموح به (${currentCustomerDetails.credit_limit.toFixed(2)} ر.ي).`;
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
                if (qty > stock && stock >= 0 && qty > 0 && name !== "") {
                    rowWarning.textContent = `⚠️ تجاوز المخزون (${stock})`;
                    rowWarning.classList.remove("d-none");
                } else {
                    rowWarning.classList.add("d-none");
                }
            }
        });
    }

    addItemBtn.addEventListener("click", function() {
        const newRow = rowTemplate.cloneNode(true);
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

        newRow.querySelector(".conversion-factor").value = "1.0000";
        newRow.querySelector(".unit-name").value = "حبة";
        newRow.querySelector(".unit-display-input").value = "حبة";
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
                if (typeof AqnexAlert !== 'undefined') {
                    AqnexAlert.show(102, AQNEX_MESSAGES[102] || "يجب أن تحتوي الفاتورة على صنف واحد على الأقل!");
                } else {
                    alert("يجب أن تحتوي الفاتورة على صنف واحد على الأقل!");
                }
            }
        }
    });

    // معالجة التنقل بلوحة المفاتيح لقائمة الإكمال التلقائي
    itemsContainer.addEventListener("keydown", function(e) {
        if (e.target.matches(".product-search-input")) {
            const row = e.target.closest(".item-row");
            const dropdown = row.querySelector(".autocomplete-dropdown");
            if (!dropdown || dropdown.classList.contains("d-none")) return;

            const items = Array.from(dropdown.querySelectorAll(".autocomplete-item"));
            if (items.length === 0) return;

            const activeItem = dropdown.querySelector(".autocomplete-item.active");
            let idx = items.indexOf(activeItem);

            if (e.key === "ArrowDown") {
                e.preventDefault();
                e.stopPropagation();
                if (activeItem) activeItem.classList.remove("active");
                idx = (idx + 1) % items.length;
                items[idx].classList.add("active");
                items[idx].scrollIntoView({ block: "nearest" });
            } else if (e.key === "ArrowUp") {
                e.preventDefault();
                e.stopPropagation();
                if (activeItem) activeItem.classList.remove("active");
                idx = (idx - 1 + items.length) % items.length;
                items[idx].classList.add("active");
                items[idx].scrollIntoView({ block: "nearest" });
            } else if (e.key === "Enter") {
                if (activeItem) {
                    e.preventDefault();
                    e.stopPropagation();
                    activeItem.click(); // اختيار الصنف المحدد
                }
            }
        }
    });

    document.getElementById("salesForm").addEventListener("keydown", function(e) {
        if (e.key === "Enter") {
            if (e.target.tagName !== "BUTTON" && e.target.tagName !== "TEXTAREA") {
                e.preventDefault();
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
                    e.target.value = stockVal;
                    if (typeof AqnexAlert !== 'undefined') {
                        AqnexAlert.show(105, `تنبيه: الكمية المدخلة (${qty}) تتجاوز المتوفر في المخزن (${stockVal})!`, e.target);
                    } else {
                        alert("تنبيه: الكمية المدخلة أكبر من المتوفر في المخزن!");
                        e.target.focus();
                        e.target.select();
                    }
                }
            }

            updateRowCalculations(row);
        }
    });

    function updateRowCalculations(row) {
        if (!row) return;
        const qtyInp = row.querySelector(".quantity-input");
        const priceInp = row.querySelector(".price-input");
        const totalInp = row.querySelector(".total-input");
        const paidInp = row.querySelector(".paid-input");
        const discountInp = row.querySelector(".discount-input");
        const remInp = row.querySelector(".remaining-input");
        const profitInp = row.querySelector(".profit-input");
        const buyPriceInp = row.querySelector(".buy-price");

        const qty = parseFloat(qtyInp ? qtyInp.value : 0) || 0;
        const price = parseFloat(priceInp ? priceInp.value : 0) || 0;
        const buyPrice = parseFloat(buyPriceInp ? buyPriceInp.value : 0) || 0;
        const discount = parseFloat(discountInp ? discountInp.value : 0) || 0;

        const total = (qty * price) - discount;
        if (totalInp) totalInp.value = total > 0 ? total.toFixed(2) : "0.00";

        const isPaidEdited = paidInp && paidInp.getAttribute("data-manually-edited") === "true";
        let paid = 0;
        if (isPaidEdited) {
            paid = parseFloat(paidInp.value) || 0;
        } else {
            const invTypeSelect = document.querySelector("#invoiceTypeSelect");
            const invType = invTypeSelect ? invTypeSelect.value : "cash";
            if (invType === "cash") {
                paid = total;
            } else {
                paid = 0;
            }
            if (paidInp) paidInp.value = paid > 0 ? paid.toFixed(2) : "0.00";
        }

        const remaining = Math.max(0, total - paid);
        if (remInp) remInp.value = remaining.toFixed(2);

        if (profitInp) {
            const profit = (price - buyPrice) * qty;
            profitInp.value = profit.toFixed(2);
        }

        updateGrandTotals();
    }
    window.updateRowCalculations = updateRowCalculations;

    function updateGrandTotals() {
        let subtotal = 0;
        let totalDiscount = 0;
        let totalPaid = 0;
        let totalRemaining = 0;
        let totalProfit = 0;

        document.querySelectorAll(".item-row").forEach(function(row) {
            const qty = parseFloat(row.querySelector(".quantity-input")?.value) || 0;
            const price = parseFloat(row.querySelector(".price-input")?.value) || 0;
            const total = parseFloat(row.querySelector(".total-input")?.value) || 0;
            const paid = parseFloat(row.querySelector(".paid-input")?.value) || 0;
            const discount = parseFloat(row.querySelector(".discount-input")?.value) || 0;
            const remaining = parseFloat(row.querySelector(".remaining-input")?.value) || 0;
            const profit = parseFloat(row.querySelector(".profit-input")?.value) || 0;

            subtotal += (qty * price);
            totalDiscount += discount;
            totalPaid += paid;
            totalRemaining += remaining;
            totalProfit += profit;
        });

        const grandTotal = Math.max(0, subtotal - totalDiscount);

        const subtotalEl = document.getElementById("summarySubtotal");
        const discountEl = document.getElementById("summaryDiscount");
        const grandTotalEl = document.getElementById("summaryGrandTotal");
        const paidEl = document.getElementById("summaryPaid") || document.getElementById("totalPaidInput");
        const remainingEl = document.getElementById("summaryRemaining");
        const profitEl = document.getElementById("summaryProfit");

        if (subtotalEl) subtotalEl.textContent = subtotal.toFixed(2);
        if (discountEl) discountEl.textContent = totalDiscount.toFixed(2);
        if (grandTotalEl) grandTotalEl.textContent = grandTotal.toFixed(2);
        if (paidEl && paidEl.tagName === "INPUT") paidEl.value = totalPaid.toFixed(2);
        else if (paidEl) paidEl.textContent = totalPaid.toFixed(2);
        if (remainingEl) remainingEl.textContent = totalRemaining.toFixed(2);
        if (profitEl) profitEl.textContent = totalProfit.toFixed(2);
    }
    window.updateGrandTotals = updateGrandTotals;

    function confirmDeleteCurrentInvoice() {
        let invId = <?php echo $editing_invoice_id ?: 0; ?>;
        if (!invId || invId <= 0) {
            const urlParams = new URLSearchParams(window.location.search);
            invId = parseInt(urlParams.get('id') || urlParams.get('edit_id') || '0');
        }
        const hiddenEl = document.getElementById('editing_invoice_id_hidden') || document.getElementById('invoice_id');
        if ((!invId || invId <= 0) && hiddenEl) {
            invId = parseInt(hiddenEl.value || '0');
        }

        if (invId > 0) {
            const msgTitle = 'تأكيد الحذف النهائي';
            const msgBody = 'هل أنت متأكد من رغبتك في حذف فاتورة المبيعات رقم #' + invId + ' نهائياً؟\nسيتم إلغاء تأثير المخزون ورصيد العميل والصندوق والقيود المحاسبية. لا يمكن التراجع عن هذه العملية.';
            if (typeof AqnexConfirm !== 'undefined') {
                AqnexConfirm.show(msgTitle, msgBody, function(confirmed) {
                    if (confirmed) {
                        window.location.href = 'delete.php?id=' + invId;
                    }
                });
            } else {
                if (confirm(msgBody)) {
                    window.location.href = 'delete.php?id=' + invId;
                }
            }
        } else {
            if (typeof AqnexAlert !== 'undefined') {
                AqnexAlert.show(101, "برجاء اختيار أو فتح فاتورة مبيعات محفوظة أولاً من قائمة البحث لإمكانية حذفها.");
            } else {
                alert("برجاء اختيار أو فتح فاتورة مبيعات محفوظة أولاً من قائمة البحث لإمكانية حذفها.");
            }
        }
    }
    window.confirmDeleteCurrentInvoice = confirmDeleteCurrentInvoice;

    function resetSalesForm() {
        window.location.href = 'create.php';
    }
    window.resetSalesForm = resetSalesForm;

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

    // دالة فتح المودال ونقل المؤشر لخانة البحث
    function openProductModalAndFocusSearch(query) {
        const modalEl = document.getElementById('quickProductSearchModal');
        if (!modalEl) return;
        
        const searchInput = document.getElementById('quickProductSearchInput');
        
        // فتح المودال
        if (typeof $ !== 'undefined' && typeof $.fn.modal === 'function') {
            $('#quickProductSearchModal').modal('show');
        } else {
            modalEl.classList.add('show');
            modalEl.setAttribute('aria-hidden', 'false');
            modalEl.style.display = 'block';
            document.body.classList.add('modal-open');
        }
        
        // نقل المؤشر لخانة البحث وتعبئته بالقيمة إن وجدت
        setTimeout(() => {
            if (searchInput) {
                searchInput.value = query || '';
                searchInput.focus();
                searchInput.select();
                // تحديث النتائج
                if (query) {
                    renderQuickProductResults(query);
                } else {
                    fetchModalProducts(true);
                }
            }
        }, 300);
    }
    window.openProductModalAndFocusSearch = openProductModalAndFocusSearch;

    // عند التركيز أو النقر على حقل البحث عن المنتج، يتم حذف النص فوراً لسهولة البحث وإظهار القائمة الكاملة
    itemsContainer.addEventListener("click", function(e) {
        if (e.target.matches(".product-search-input")) {
            const row = e.target.closest(".item-row");
            window.activeSearchRow = row;
            e.target.value = "";
            showProductAutocompleteOptions(row, "");
        }
    });

    itemsContainer.addEventListener("focusin", function(e) {
        if (e.target.matches(".product-search-input")) {
            const row = e.target.closest(".item-row");
            window.activeSearchRow = row;
            e.target.value = "";
            showProductAutocompleteOptions(row, "");
        }
    });

    // الإكمال التلقائي الذكي عند الكتابة (Autocomplete)
    itemsContainer.addEventListener("input", function(e) {
        if (e.target.matches(".product-search-input")) {
            const row = e.target.closest(".item-row");
            const q = e.target.value.trim();
            showProductAutocompleteOptions(row, q);
        }
    });

    function showProductAutocompleteOptions(row, q) {
        const dropdown = row.querySelector(".autocomplete-dropdown");
        if (!dropdown) return;

        fetch(`../api/search_products.php?q=${encodeURIComponent(q)}`)
            .then(res => res.json())
            .then(data => {
                let html = "";
                if (data && data.length > 0) {
                    data.forEach(p => {
                        const pJson = escapeHtml(JSON.stringify(p));
                        html += `
                            <div class="autocomplete-item text-right" data-product="${pJson}" style="padding: 4px 8px; cursor: pointer; border-bottom: 1px solid #f1f5f9;">
                                <div class="font-weight-bold" style="font-size: 0.75rem;">${escapeHtml(p.name)}</div>
                                <div style="font-size: 0.70rem; color: #64748b;">
                                    باركود: ${escapeHtml(p.barcode || '—')} | المخزون: ${p.quantity} | السعر: ${p.sale_price} ر.ي
                                </div>
                            </div>
                        `;
                    });
                    dropdown.innerHTML = html;
                    dropdown.classList.remove("d-none");
                } else {
                    dropdown.innerHTML = '<div style="padding: 6px; color: #94a3b8; font-size: 0.73rem; text-align: center;">لا توجد نتائج</div>';
                    dropdown.classList.remove("d-none");
                }
            })
            .catch(err => {
                console.error("Autocomplete error:", err);
            });
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

        const conversionFactor = parseFloat(product.conversion_factor) || 1.0;
        row.querySelector(".conversion-factor").value = conversionFactor;
        row.querySelector(".unit-name").value = product.unit_name || "حبة";
        row.querySelector(".unit-display-input").value = product.unit_name || "حبة";
        row.querySelector(".unit-id").value = product.unit_id || "";

        const rate = parseFloat(exchangeRateInput.value) || 1.0;

        row.querySelector(".buy-price").value = (product.buy_price / rate).toFixed(2);

        const stockQty = Math.floor(product.quantity / conversionFactor);
        row.querySelector(".stock-qty").value = stockQty;

        const salePriceConverted = (product.sale_price / rate).toFixed(2);
        row.querySelector(".price-input").value = salePriceConverted;

        // تطبيق قاعدة الفاتورة الآجلة: إذا كانت الفاتورة آجلة يتم إيقاف المدفوع وجعله 0
        const invType = document.getElementById('invoiceTypeSelect') ? document.getElementById('invoiceTypeSelect').value : 'cash';
        if (invType === 'credit') {
            row.querySelector(".paid-input").value = 0;
            row.querySelector(".paid-input").setAttribute('readonly', 'readonly');
        } else {
            row.querySelector(".paid-input").value = salePriceConverted;
            row.querySelector(".paid-input").removeAttribute('readonly');
        }

        row.querySelector(".paid-input").removeAttribute("data-manually-edited");
        row.querySelector(".quantity-input").value = 1;
        row.querySelector(".discount-input").value = 0;

        dropdown.classList.add("d-none");
        dropdown.innerHTML = "";

        // إغلاق المودال فوراً بعد اختيار المنتج
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

                    if (product.scanned_serial) {
                        const opt = serialSelect.querySelector(`option[value="${product.scanned_serial.id}"]`);
                        if (opt) {
                            opt.selected = true;
                            hiddenSerialIds.value = product.scanned_serial.id;
                            row.querySelector(".quantity-input").value = 1;
                        }
                    }
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
            if (typeof AqnexAlert !== 'undefined') {
                AqnexAlert.show(105, "تنبيه: هذا المنتج غير متوفر في المخزن حالياً!");
            } else {
                alert("تنبيه: هذا المنتج غير متوفر في المخزن حالياً!");
            }
        }

        // الانتقال التلقائي لحقل الكمية وتحديده بعد اختيار الصنف
        setTimeout(() => {
            const qtyInput = row.querySelector(".quantity-input");
            if (qtyInput) {
                qtyInput.focus();
                qtyInput.select();
            }
        }, 100);
    }

    itemsContainer.addEventListener("click", function(e) {
        const item = e.target.closest(".autocomplete-item");
        if (item) {
            const row = item.closest(".item-row");
            const productData = item.getAttribute("data-product");
            if (productData && row) {
                const product = JSON.parse(productData);
                selectProductForRow(row, product);
            }
        }
    });

    // معالجة التنقل بأزرار الانتر بين الحقول داخل الصفوف
    // الترتيب: الصنف -> الكمية -> السعر -> المدفوع -> الخصم -> الباقي -> صف جديد وتلقائي
    itemsContainer.addEventListener("keydown", function(e) {
        if (e.key === "Enter") {
            const row = e.target.closest(".item-row");
            if (!row) return;

            if (e.target.classList.contains("product-search-input")) {
                e.preventDefault();
                const dropdown = row.querySelector('.product-search-container .autocomplete-dropdown');
                if (dropdown && !dropdown.classList.contains('d-none')) {
                    const firstItem = dropdown.querySelector('.autocomplete-item');
                    if (firstItem) {
                        firstItem.click();
                        return;
                    }
                }
                const qtyInput = row.querySelector(".quantity-input");
                if (qtyInput) {
                    qtyInput.focus();
                    qtyInput.select();
                }
            } else if (e.target.classList.contains("quantity-input")) {
                e.preventDefault();
                const priceInput = row.querySelector(".price-input");
                if (priceInput) {
                    priceInput.focus();
                    priceInput.select();
                }
            } else if (e.target.classList.contains("price-input")) {
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
            } else if (e.target.classList.contains("discount-input") || e.target.classList.contains("remaining-input")) {
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
                                lastSearch.focus();
                                openProductModalAndFocusSearch('');
                            }
                        }
                    }, 100);
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

    let modalProductPage = 1;
    let modalProductLoading = false;
    let modalProductHasMore = true;
    let modalProductQuery = "";

    function fetchModalProducts(isNewSearch = false) {
        const results = document.getElementById('quickProductSearchResults');
        if (!results || modalProductLoading || (!isNewSearch && !modalProductHasMore)) return;

        modalProductLoading = true;

        if (isNewSearch) {
            results.innerHTML = '<tr class="loading-row"><td colspan="5"><div class="circular-loader"></div>جاري تحميل المنتجات...</td></tr>';
            modalProductPage = 1;
            modalProductHasMore = true;
        } else {
            const spinner = document.createElement('tr');
            spinner.className = 'loading-row loading-spinner';
            spinner.innerHTML = '<td colspan="5"><div class="circular-loader"></div>تحميل المزيد...</td>';
            results.appendChild(spinner);
        }

        const url = `../api/search_products.php?q=${encodeURIComponent(modalProductQuery)}&page=${modalProductPage}`;
        fetch(url)
            .then(res => res.json())
            .then(products => {
                const spinner = results.querySelector('.loading-spinner');
                if (spinner) spinner.remove();

                if (isNewSearch) {
                    results.innerHTML = "";
                }

                if (products.length === 0) {
                    modalProductHasMore = false;
                    if (isNewSearch) {
                        results.innerHTML = '<tr class="empty-row"><td colspan="5">لم يتم العثور على منتج مطابق</td></tr>';
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
                    if (stockVal <= 0) {
                        stockBadgeClass = "badge-danger";
                    } else if (stockVal <= 5) {
                        stockBadgeClass = "badge-warning";
                    }

                    return `
                        <tr class="search-result-item" tabindex="0" data-product='${pJson}'>
                        <td>${escapeHtml(product.barcode || '-')}</td>
                        <td class="col-name">${escapeHtml(product.name)}</td>
                        <td class="price-cell">${priceConverted} ر.ي</td>
                        <td><span class="badge ${stockBadgeClass}">${stockVal}</span></td>
                            <td><i class="bi bi-check-circle select-icon"></i></td>
                        </tr>
                    `;
                }).join('');

                results.insertAdjacentHTML('beforeend', html);

                results.querySelectorAll('.search-result-item').forEach(row => {
                    if (row.dataset.clickRegistered) return;
                    row.dataset.clickRegistered = "true";
                    row.addEventListener('click', function() {
                        const product = JSON.parse(this.getAttribute('data-product'));
                        if (product) {
                            selectAndRouteFocus(product);
                        }
                    });
                });

                modalProductPage++;
                modalProductLoading = false;
            })
            .catch(err => {
                console.error("Modal fetch error:", err);
                modalProductLoading = false;
            });
    }

    function addScannedProduct(product) {
        let existingRow = null;
        document.querySelectorAll(".item-row").forEach(function(row) {
            const selectVal = row.querySelector(".select-product").value;
            const rowUnitId = row.querySelector(".unit-id").value;
            if (selectVal == product.id && rowUnitId == (product.unit_id || "")) {
                existingRow = row;
            }
        });

        if (existingRow) {
            let qtyInput = existingRow.querySelector(".quantity-input");
            let currentQty = parseInt(qtyInput.value) || 0;
            let newQty = currentQty + 1;
            let stock = parseInt(existingRow.querySelector(".stock-qty").value) || 0;
            if (newQty > stock) {
                if (typeof AqnexAlert !== 'undefined') {
                    AqnexAlert.show(105, `تحذير: الكمية المطلوبة (${newQty}) تتجاوز المتوفر في المخزن (${stock})!`, qtyInput);
                } else {
                    alert("تحذير: الكمية المطلوبة (" + newQty + ") تتجاوز المتوفر في المخزن (" + stock + ")!");
                }
                newQty = stock;
            }
            const serialSelect = existingRow.querySelector(".serial-select");
            if (isSerialModuleEnabled && product.scanned_serial && serialSelect) {
                const opt = serialSelect.querySelector(`option[value="${product.scanned_serial.id}"]`);
                if (opt && !opt.selected) {
                    opt.selected = true;
                    serialSelect.dispatchEvent(new Event("change"));
                } else {
                    if (typeof AqnexAlert !== 'undefined') {
                        AqnexAlert.show(104, "رقم IMEI مضاف مسبقاً في هذه الفاتورة!");
                    } else {
                        alert("رقم IMEI مضاف مسبقاً في هذه الفاتورة!");
                    }
                }
            } else {
                qtyInput.value = newQty;
                updateRowCalculations(existingRow);
            }
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

    const barcodeScanInput = document.getElementById("barcodeScanInput");
    if (barcodeScanInput) {
        barcodeScanInput.addEventListener("keydown", function(e) {
            if (e.key === "Enter") {
                e.preventDefault();
                const q = this.value.trim();
                if (!q) return;

                fetch(`../api/search_products.php?q=${encodeURIComponent(q)}`)
                    .then(res => res.json())
                    .then(data => {
                        if (data && data.length > 0) {
                            const match = data.find(p => p.barcode && p.barcode.trim() === q) || data[0];
                            addScannedProduct(match);
                            barcodeScanInput.value = "";
                            barcodeScanInput.focus();
                        } else {
                            if (typeof AqnexAlert !== 'undefined') {
                                AqnexAlert.show(105, 'عذراً، الصنف صاحب الباركود (' + q + ') غير موجود بالمخزون/الحسابات!', barcodeScanInput);
                            } else {
                                alert('عذراً، الصنف صاحب الباركود (' + q + ') غير موجود بالمخزون!');
                            }
                        }
                    })
                    .catch(err => {
                        console.error("Barcode scan error:", err);
                    });
            }
        });
    }

    document.addEventListener('keydown', function(e) {
        if (e.key === 'F4') {
            e.preventDefault();
            window.openQuickProductModal();
        }
    });

    const quickProductSearchBtn = document.getElementById('quickProductSearchBtn');
    if (quickProductSearchBtn) {
        quickProductSearchBtn.addEventListener('click', function(e) {
            e.preventDefault();
            window.openQuickProductModal();
        });
    }

    const salesForm = document.querySelector('form');
    if (salesForm) {
        salesForm.addEventListener('submit', function(e) {
            const invTypeSelect = document.getElementById('invoiceTypeSelect');
            const custSelect = document.getElementById('select2');
            const invType = invTypeSelect ? invTypeSelect.value : 'cash';
            const custName = custSelect ? custSelect.value.trim() : '';

            if (invType === 'credit') {
                if (custName === 'عميل نقدي' || custName === '') {
                    e.preventDefault();
                    if (typeof AqnexAlert !== 'undefined') {
                        AqnexAlert.show(122, 'لا يمكن حفظ فاتورة آجلة لـ (عميل نقدي)! يرجى تحديد عميل آجل مسجل أولاً.', custSelect);
                    } else {
                        alert('خطأ: لا يمكن حفظ فاتورة آجلة لـ (عميل نقدي)! يرجى تحديد عميل آجل أولاً.');
                    }
                    return false;
                }
            }
        });
    }
    function selectAndRouteFocus(product) {
        let activeRow = window.activeSearchRow;
        if (activeRow) {
            selectProductForRow(activeRow, product);
        } else {
            addScannedProduct(product);
            const rows = document.querySelectorAll(".item-row");
            activeRow = rows[rows.length - 1];
        }
        // إغلاق المودال بعد الاختيار
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
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                const firstRow = document.querySelector('#quickProductSearchResults .search-result-item');
                if (firstRow) firstRow.focus();
            }
        });
    }
    const resultsContainer = document.getElementById('quickProductSearchResultsWrap');
    if (resultsContainer) {
        resultsContainer.addEventListener('scroll', function() {
            if (resultsContainer.scrollTop + resultsContainer.clientHeight >= resultsContainer.scrollHeight - 15) {
                fetchModalProducts(false);
            }
        });
        resultsContainer.addEventListener('keydown', function(e) {
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault();
                const activeEl = document.activeElement;
                if (activeEl && activeEl.classList.contains('search-result-item')) {
                    const items = Array.from(resultsContainer.querySelectorAll('.search-result-item'));
                    const idx = items.indexOf(activeEl);
                    if (e.key === 'ArrowDown' && idx < items.length - 1) {
                        items[idx + 1].focus();
                    } else if (e.key === 'ArrowUp' && idx > 0) {
                        items[idx - 1].focus();
                    } else if (e.key === 'ArrowUp' && idx === 0) {
                        quickSearchInput.focus();
                    }
                }
            }
        });
    }
    window.addEventListener("barcodeScanned", function(e) {
        const barcode = e.detail.code;
        fetch(`../api/search_products.php?q=${encodeURIComponent(barcode)}`)
            .then(res => res.json())
            .then(products => {
                const match = products.find(p => p.barcode === barcode);
                if (match) {
                    if (window.activeSearchRow) {
                        selectProductForRow(window.activeSearchRow, match);
                    } else {
                        addScannedProduct(match);
                    }
                    closeProductSearchModal();
                }
            })
            .catch(err => console.error("Barcode event lookup error:", err));
    });
    const quickProductBtn = document.getElementById('quickProductSearchBtn');
    if (quickProductBtn) {
        quickProductBtn.addEventListener('click', openQuickProductModal);
    }
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
            })
            .catch(err => console.error("Error fetching customer details:", err));
    }
    if (typeof $ !== 'undefined' && $('#select2').length) {
        $('#select2').on('change', function() {
            fetchCustomerDetails($(this).val());
        });
        fetchCustomerDetails($('#select2').val());
    } else {
        const selectEl = document.getElementById('select2');
        if (selectEl) {
            selectEl.addEventListener('change', function() {
                fetchCustomerDetails(this.value);
            });
            fetchCustomerDetails(selectEl.value);
        }
    }
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
        // if (totalPaid > boxBalance) {
        //     alert("رصيد الصندوق المحدد غير كافٍ! لا يمكن حفظ الفاتورة قبل تقليل المبلغ المدفوع أو اختيار صندوق آخر.");
        //     e.preventDefault();
        //     return false;
        // }
        let hasProducts = false;
        document.querySelectorAll(".item-row").forEach(row => {
            const productId = row.querySelector(".select-product").value;
            if (productId && productId !== "-1") {
                hasProducts = true;
            }
        });
        if (!hasProducts) {
            if (typeof AqnexAlert !== 'undefined') {
                AqnexAlert.show(102, AQNEX_MESSAGES[102]);
            } else {
                alert("تحذير: يجب إضافة صنف واحد على الأقل إلى الفاتورة قبل الحفظ!");
            }
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
                if (typeof AqnexAlert !== 'undefined') {
                    AqnexAlert.show(105, `تحذير في الصنف "${name}": الكمية المطلوبة (${qty}) تتجاوز المتوفر في المخزن (${stock})!`, row.querySelector(".quantity-input"));
                } else {
                    alert(`تحذير في الصنف "${name}": الكمية المطلوبة (${qty}) تتجاوز المتوفر في المخزن (${stock})!`);
                }
                row.querySelector(".quantity-input").value = stock;
                isValid = false;
            }
            const serialSelect = row.querySelector(".serial-select");
            const isSerialRequired = serialSelect && !row.querySelector(".serial-sec").classList.contains("d-none");

            if (isSerialRequired) {
                const selectedCount = Array.from(serialSelect.selectedOptions).length;
                if (selectedCount !== qty) {
                    if (typeof AqnexAlert !== 'undefined') {
                        AqnexAlert.show(104, `خطأ في الصنف "${name}": يجب اختيار أرقام IMEI مساوية للكمية المطلوبة (${qty})! المختار حالياً: ${selectedCount}`, serialSelect);
                    } else {
                        alert(`خطأ في الصنف "${name}": يجب اختيار أرقام IMEI مساوية للكمية المطلوبة (${qty})! المختار حالياً: ${selectedCount}`);
                    }
                    isValid = false;
                }
            }
        });
        const remainingTotal = parseFloat(document.getElementById("grandRemainingDisplay").textContent) || 0;
        if (currentCustomerDetails.id > 0 && remainingTotal > 0) {
            const newBalance = currentCustomerDetails.balance + remainingTotal;
            if (newBalance > currentCustomerDetails.credit_limit) {
                if (typeof AqnexAlert !== 'undefined') {
                    AqnexAlert.show(104, `تحذير: لا يمكن إتمام العملية! مديونية العميل بعد هذه الفاتورة (${newBalance.toFixed(2)}) ستتجاوز الحد الائتماني المسموح به (${currentCustomerDetails.credit_limit.toFixed(2)})!`);
                } else {
                    alert(`تحذير: لا يمكن إتمام العملية! مديونية العميل بعد هذه الفاتورة (${newBalance.toFixed(2)}) ستتجاوز الحد الائتماني المسموح به (${currentCustomerDetails.credit_limit.toFixed(2)})!`);
                }
                isValid = false;
            }
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
            if (typeof AqnexAlert !== 'undefined') {
                AqnexAlert.show(104, "يرجى تصحيح الأخطاء والتحذيرات (تجاوز حد الدين أو كمية المخزن) قبل حفظ الفاتورة.");
            } else {
                alert("يرجى تصحيح الأخطاء والتحذيرات (تجاوز حد الدين أو كمية المخزن) قبل حفظ الفاتورة.");
            }
            isValid = false;
        }

        if (!isValid) {
            e.preventDefault();
            return false;
        }
    });
});
</script>

<?php
if (isset($_GET['ai_prefill'])) {
    $prefill_data = json_decode(base64_decode($_GET['ai_prefill']), true);
    if ($prefill_data) {
        $prefill_customer = $prefill_data['customer_name'] ?? '';
        $prefill_items = $prefill_data['items'] ?? [];
        $resolved_items = [];

        foreach ($prefill_items as $item) {
            $p_name = $conn->real_escape_string($item['product_name']);
            $res_p = $conn->query("SELECT id, name, sale_price, buy_price, quantity, track_expiry FROM products WHERE name LIKE '%$p_name%' AND delete_status='0' LIMIT 1");
            if ($res_p && $res_p->num_rows > 0) {
                $p_row = $res_p->fetch_assoc();
                $resolved_items[] = [
                    'id' => intval($p_row['id']),
                    'name' => $p_row['name'],
                    'qty' => intval($item['qty'] ?? 1),
                    'price' => doubleval($item['price'] ?? $p_row['sale_price']),
                    'buy_price' => doubleval($p_row['buy_price']),
                    'stock' => intval($p_row['quantity']),
                    'track_expiry' => intval($p_row['track_expiry'])
                ];
            }
        }

        if (!empty($resolved_items) || !empty($prefill_customer)) {
            ?>
            <script>
            document.addEventListener("DOMContentLoaded", function() {
                const customerSelect = document.getElementById("select2");
                const customerName = <?php echo json_encode($prefill_customer); ?>;
                if (customerSelect && customerName) {
                    let found = false;
                    for (let i = 0; i < customerSelect.options.length; i++) {
                        if (customerSelect.options[i].text === customerName || customerSelect.options[i].value === customerName) {
                            customerSelect.selectedIndex = i;
                            found = true;
                            break;
                        }
                    }
                    if (!found) {
                        const opt = document.createElement("option");
                        opt.value = customerName;
                        opt.text = customerName;
                        opt.selected = true;
                        customerSelect.add(opt);
                    }
                    if (typeof $(customerSelect).trigger === "function") {
                        $(customerSelect).val(customerName).trigger('change');
                    }
                }

                const resolvedItems = <?php echo json_encode($resolved_items); ?>;
                const itemsContainer = document.getElementById("itemsContainer");
                const addItemBtn = document.getElementById("addItemBtn");
                const firstRow = document.querySelector(".item-row");

                resolvedItems.forEach((item, index) => {
                    let currentRow;
                    if (index === 0 && firstRow) {
                        currentRow = firstRow;
                    } else {
                        addItemBtn.click();
                        const rows = document.querySelectorAll(".item-row");
                        currentRow = rows[rows.length - 1];
                    }

                    currentRow.querySelector(".product-search-input").value = item.name;
                    currentRow.querySelector(".select-product").value = item.id;
                    currentRow.querySelector(".select-product").setAttribute("data-base-sale-price", item.price);
                    currentRow.querySelector(".select-product").setAttribute("data-base-buy-price", item.buy_price);
                    currentRow.querySelector(".buy-price").value = item.buy_price;
                    currentRow.querySelector(".stock-qty").value = item.stock;
                    currentRow.querySelector(".quantity-input").value = item.qty;
                    currentRow.querySelector(".price-input").value = item.price;
                    currentRow.querySelector(".total-input").value = (item.qty * item.price);
                    currentRow.querySelector(".paid-input").value = (item.qty * item.price);
                    currentRow.querySelector(".remaining-input").value = 0;

                    if (typeof updateRowCalculations === "function") {
                        updateRowCalculations(currentRow);
                    }
                });

                if (typeof updateGrandTotals === "function") {
                    updateGrandTotals();
                }
                if (typeof updateAccountingGuide === "function") {
                    updateAccountingGuide();
                }
            });
            </script>
            <?php
        }
    }
}
?>

<!-- Modal إضافة عميل جديد سريعاً -->
<div class="modal fade modal-modern" id="quickAddCustomerModal" tabindex="-1" role="dialog" aria-labelledby="quickAddCustomerModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title" id="quickAddCustomerModalLabel">
                        <i class="fa fa-user-plus ml-2"></i>إضافة عميل جديد
                    </h5>
                    <p class="mb-0 small opacity-75">أدخل بيانات العميل مع حد الائتمان المسموح به</p>
                </div>
                <button type="button" class="close ml-0 text-white" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body text-right" dir="rtl">
                <div class="alert alert-danger d-none" id="quickAddCustomerError"></div>
                <form id="quickAddCustomerForm">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="font-weight-bold text-secondary mb-1">اسم العميل <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="cust_name" placeholder="أدخل اسم العميل بالكامل" required>
                        </div>
                        <div class="col-md-6">
                            <label class="font-weight-bold text-secondary mb-1">رقم الجوال <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="phone" placeholder="أدخل رقم الجوال" required>
                        </div>
                        <div class="col-md-6">
                            <label class="font-weight-bold text-secondary mb-1">البريد الإلكتروني</label>
                            <input type="email" class="form-control" name="email" placeholder="email@example.com">
                        </div>
                        <div class="col-md-6">
                            <label class="font-weight-bold text-secondary mb-1">حد الائتمان (ر.ي)</label>
                            <input type="number" step="any" class="form-control" name="credit_limit" value="0.00">
                        </div>
                        <div class="col-12">
                            <label class="font-weight-bold text-secondary mb-1">العنوان السكني / العمل</label>
                            <input type="text" class="form-control" name="address" placeholder="المحافظة - المديرية - الشارع">
                        </div>
                        <div class="col-12">
                            <label class="font-weight-bold text-secondary mb-1">ملاحظات إضافية</label>
                            <textarea class="form-control" name="notes" rows="2" placeholder="أدخل أي ملاحظات..."></textarea>
                        </div>
                    </div>
                    <input type="hidden" name="ajax_add_customer" value="1">
                    <div class="modal-helper mt-3">سيتم حفظ العميل مباشرةً وسيظهر في قائمة العملاء المعتمدة على الفاتورة.</div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">إلغاء</button>
                <button type="submit" form="quickAddCustomerForm" class="btn btn-primary">
                    <i class="fa fa-plus ml-1"></i>حفظ العميل
                </button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade modal-modern" id="quickProductSearchModal" tabindex="-1" role="dialog" aria-labelledby="quickProductSearchModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="quickProductSearchModalLabel">
                    <i class="bi bi-search"></i> البحث السريع عن المنتج
                </h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true" style="font-size:1.4rem;line-height:1;">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <input type="text" id="quickProductSearchInput" class="form-control" placeholder="ابحث باسم المنتج أو الباركود..." autocomplete="off">
                <div class="modal-helper mt-2">النتائج تظهر مباشرة مع السعر والمخزون</div>
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

            fetch("create.php", {
                method: "POST",
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.status === "success") {
                    if (typeof AqnexAlert !== 'undefined') {
                        AqnexAlert.show(119, "تم حفظ العميل بنجاح واختياره تلقائياً!");
                    } else {
                        alert("تم حفظ العميل بنجاح واختياره تلقائياً!");
                    }
                    const customerSelect = document.getElementById("select2");
                    if (customerSelect) {
                        if (typeof $ !== 'undefined' && $(customerSelect).data('select2')) {
                            var newOption = new Option(data.name, data.name, true, true);
                            $(customerSelect).append(newOption).trigger('change');
                        } else {
                            const opt = document.createElement("option");
                            opt.value = data.name;
                            opt.text = data.name;
                            opt.selected = true;
                            opt.setAttribute('data-id', data.id || '');
                            customerSelect.add(opt);
                            customerSelect.value = data.name;
                        }
                    }

                    const modalEl = document.getElementById('quickAddCustomerModal');
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

<!-- Modal: البحث عن فواتير مبيعات سابقة -->
<div class="modal fade" id="searchInvoiceModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
        <div class="modal-content" style="border-radius: 4px; border: 1px solid #94a3b8;">
            <div class="modal-header" style="background: linear-gradient(180deg, #f8fafc 0%, #e2e8f0 100%); padding: 8px 12px;">
                <h6 class="modal-title font-weight-bold" style="color: #0f172a; font-size: 0.85rem;">
                    <i class="bi bi-search text-primary ml-1"></i> البحث عن فواتير مبيعات سابقة
                </h6>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body p-3">
                <div class="row mb-3">
                    <div class="col-md-6">
                        <input type="text" id="searchInvoiceQuery" class="aqnex-input" placeholder="أدخل رقم الفاتورة أو اسم العميل للبحث..." onkeyup="filterPastInvoicesList()">
                    </div>
                    <div class="col-md-4">
                        <input type="date" id="searchInvoiceDate" class="aqnex-input" onchange="filterPastInvoicesList()">
                    </div>
                    <div class="col-md-2">
                        <button type="button" class="btn btn-sm btn-primary btn-block font-weight-bold" onclick="filterPastInvoicesList()" style="height: 24px; padding: 0 6px;">
                            <i class="bi bi-search ml-1"></i> بحث
                        </button>
                    </div>
                </div>

                <div class="table-responsive" style="max-height: 320px; overflow-y: auto;">
                    <table class="aqnex-grid-table">
                        <thead>
                            <tr>
                                <th>رقم الفاتورة</th>
                                <th>تاريخ الفاتورة</th>
                                <th>اسم العميل</th>
                                <th>نوع الفاتورة</th>
                                <th>الصافي الإجمالي</th>
                                <th>إجراء</th>
                            </tr>
                        </thead>
                        <tbody id="pastInvoicesTableBody">
                            <?php
                            $res_past = $conn->query("
                                SELECT id, invoice_date AS build_date, cust_name, invoice_type, net_amount AS total 
                                FROM sales_invoices_mst WHERE d_s = 0 
                                UNION ALL 
                                SELECT id, build_date, cust_name, invoice_type, total 
                                FROM sales WHERE delete_status = 0 AND id NOT IN (SELECT id FROM sales_invoices_mst WHERE d_s = 0)
                                ORDER BY id DESC LIMIT 50
                            ");
                            if ($res_past && $res_past->num_rows > 0):
                                $first = true;
                                while($inv = $res_past->fetch_assoc()):
                                    $cls = $first ? 'past-inv-row table-primary font-weight-bold' : 'past-inv-row';
                                    $first = false;
                            ?>
                            <tr class="<?php echo $cls; ?>" data-id="<?php echo $inv['id']; ?>" data-cust="<?php echo htmlspecialchars($inv['cust_name']); ?>" data-date="<?php echo $inv['build_date']; ?>" style="cursor:pointer;" onclick="selectPastInvoice(<?php echo $inv['id']; ?>)">
                                <td><strong>#<?php echo $inv['id']; ?></strong></td>
                                <td><?php echo $inv['build_date']; ?></td>
                                <td><?php echo htmlspecialchars($inv['cust_name']); ?></td>
                                <td><span class="badge badge-info"><?php echo ($inv['invoice_type'] === 'cash' || empty($inv['invoice_type'])) ? 'نقد' : 'آجل'; ?></span></td>
                                <td class="font-weight-bold text-primary"><?php echo number_format($inv['total'], 2); ?> ر.ي</td>
                                <td>
                                    <button type="button" class="tool-btn btn-xs btn-primary px-2" onclick="selectPastInvoice(<?php echo $inv['id']; ?>)" title="تنزيل / تعديل الفاتورة">
                                        <i class="bi bi-arrow-down-square-fill"></i>
                                    </button>
                                    <button type="button" class="tool-btn btn-xs btn-outline-danger px-2 ml-1" onclick="deletePastInvoice(<?php echo $inv['id']; ?>, event)" title="حذف الفاتورة نهائياً">
                                        <i class="bi bi-trash-fill"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endwhile; else: ?>
                            <tr>
                                <td colspan="6" class="text-muted py-3">لا توجد فواتير مبيعات مسجلة حالياً.</td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal: عرض القيود المحاسبية الآلية للفاتورة -->
<div class="modal fade" id="viewJournalModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
        <div class="modal-content" style="border-radius: 4px; border: 1px solid #94a3b8;">
            <div class="modal-header" style="background: linear-gradient(180deg, #f3e8ff 0%, #e9d5ff 100%); padding: 8px 12px;">
                <h6 class="modal-title font-weight-bold" style="color: #6b21a8; font-size: 0.85rem;">
                    <i class="bi bi-journal-bookmark-fill ml-1"></i> القيود المحاسبية الآلية للفاتورة (Double-Entry Journal)
                </h6>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body p-3">
                <div class="alert alert-info rounded-0 p-2 mb-3" style="font-size: 0.73rem;">
                    <i class="bi bi-info-circle ml-1"></i> القيود المحاسبية المتوازنة الناتجة عن عملية البيع الحالية:
                </div>

                <div class="table-responsive">
                    <table class="aqnex-grid-table">
                        <thead>
                            <tr>
                                <th>رقم الحساب</th>
                                <th>اسم الحساب</th>
                                <th>مدين (Debit)</th>
                                <th>دائن (Credit)</th>
                                <th>البيان / الشرح</th>
                            </tr>
                        </thead>
                        <tbody id="journalEntriesTableBody">
                            <!-- Populated via JS -->
                        </tbody>
                        <tfoot style="background: #f1f5f9; font-weight: bold;">
                            <tr>
                                <td colspan="2" class="text-right">الإجمالي المتوازن:</td>
                                <td id="journalTotalDebit" class="text-success">0.00</td>
                                <td id="journalTotalCredit" class="text-danger">0.00</td>
                                <td>-</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
let selectedPastInvoiceIndex = -1;

// فتح مودل البحث عن فواتير سابقة
function openSearchInvoiceModal(mode) {
    $('#searchInvoiceModal').modal('show');
    selectedPastInvoiceIndex = -1;
    setTimeout(() => {
        const inp = document.getElementById('searchInvoiceQuery');
        if (inp) {
            inp.focus();
            inp.select();
        }
    }, 250);
}

function deletePastInvoice(id, e) {
    if (e) e.stopPropagation();
    if (typeof AqnexConfirm !== 'undefined') {
        AqnexConfirm.show(`هل أنت متأكد من رغبتك في حذف فاتورة المبيعات رقم #${id} نهائياً؟ سيتم إلغاء تأثير المخزون ورصيد العميل والصندوق والقيود المحاسبية.`, function(confirmed) {
            if (confirmed) {
                window.location.href = 'delete.php?id=' + id;
            }
        });
    } else {
        if (confirm(`تأكيد الحذف النهائي:\n\nهل أنت متأكد من رغبتك في حذف فاتورة المبيعات رقم #${id}؟\nسيتم إلغاء تأثير الكميات من المخزون وتأثير رصيد العميل والصندوق والقيود المحاسبية نهائياً، ولن يمكن التراجع عن هذا الإجراء.`)) {
            window.location.href = 'delete.php?id=' + id;
        }
    }
}

// التنقل بالأسهم واختيار الفاتورة بزر الانتر والحذف بزر ديليت داخل الموديل
document.addEventListener('keydown', function(e) {
    const modal = document.getElementById('searchInvoiceModal');
    if (modal && (modal.classList.contains('show') || $(modal).is(':visible'))) {
        const rows = Array.from(document.querySelectorAll('.past-inv-row')).filter(r => r.style.display !== 'none');
        if (rows.length === 0) return;

        if (e.key === 'ArrowDown') {
            e.preventDefault();
            selectedPastInvoiceIndex = (selectedPastInvoiceIndex + 1) % rows.length;
            highlightPastInvoiceRow(rows, selectedPastInvoiceIndex);
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            selectedPastInvoiceIndex = (selectedPastInvoiceIndex - 1 + rows.length) % rows.length;
            highlightPastInvoiceRow(rows, selectedPastInvoiceIndex);
        } else if (e.key === 'Enter' && selectedPastInvoiceIndex >= 0 && selectedPastInvoiceIndex < rows.length) {
            e.preventDefault();
            const selectedRow = rows[selectedPastInvoiceIndex];
            const invId = selectedRow.getAttribute('data-id');
            if (invId) selectPastInvoice(invId);
        } else if (e.key === 'Delete' && selectedPastInvoiceIndex >= 0 && selectedPastInvoiceIndex < rows.length) {
            e.preventDefault();
            const selectedRow = rows[selectedPastInvoiceIndex];
            const invId = selectedRow.getAttribute('data-id');
            if (invId) deletePastInvoice(invId);
        }
    }
});

function highlightPastInvoiceRow(rows, index) {
    rows.forEach((r, idx) => {
        if (idx === index) {
            r.style.backgroundColor = '#dbeafe';
            r.style.fontWeight = 'bold';
            r.scrollIntoView({ block: 'nearest' });
        } else {
            r.style.backgroundColor = '';
            r.style.fontWeight = '';
        }
    });
}

// تصفية الفواتير في المودل
function filterPastInvoicesList() {
    const q = (document.getElementById('searchInvoiceQuery').value || '').toLowerCase();
    const d = document.getElementById('searchInvoiceDate').value;
    selectedPastInvoiceIndex = -1;

    document.querySelectorAll('.past-inv-row').forEach(row => {
        const id = row.getAttribute('data-id').toLowerCase();
        const cust = row.getAttribute('data-cust').toLowerCase();
        const date = row.getAttribute('data-date');

        let match = (id.includes(q) || cust.includes(q));
        if (d && date !== d) match = false;

        row.style.display = match ? '' : 'none';
        row.style.backgroundColor = '';
    });
}


// اختيار فاتورة من المودل والتوجيه لتعديلها في نفس الصفحة
function selectPastInvoice(invoiceId) {
    window.location.href = 'create.php?id=' + invoiceId;
}

// دالة الحذف الذكي - تحذف الفاتورة نهائياً إذا كانت محفوظة، وتصفي النموذج إذا كان جديداً
function confirmDeleteCurrentInvoice() {
    const invId = <?php echo $editing_invoice_id ?: 0; ?>;
    if (invId > 0) {
        const msgTitle = 'تأكيد الحذف النهائي';
        const msgBody = 'هل أنت متأكد من رغبتك في حذف فاتورة مبيعات رقم #' + invId + '؟\nسيتم حذف جميع البيانات، إلغاء القيود المحاسبية، وإعادة الكميات للمخزن. لا يمكن التراجع عن هذا الإجراء.';
        if (typeof AqnexConfirm !== 'undefined') {
            AqnexConfirm.show(msgTitle, msgBody, function() {
                window.location.href = 'delete.php?id=' + invId;
            });
        } else {
            if (confirm(msgBody)) {
                window.location.href = 'delete.php?id=' + invId;
            }
        }
    } else {
        // نموذج جديد فارغ - تصفية فقط
        if (typeof AqnexConfirm !== 'undefined') {
            AqnexConfirm.show('تأكيد التصفية', 'هل تريد مسح البيانات المدخلة وبدء فاتورة جديدة؟', function() {
                resetSalesForm();
            });
        } else {
            if (confirm('تصفية البيانات؟')) resetSalesForm();
        }
    }
}

// فتح مودال القيود المحاسبية
function openJournalModal() {
    const tbody = document.getElementById('journalEntriesTableBody');
    tbody.innerHTML = '';

    if (window.actualJournalEntries && window.actualJournalEntries.length > 0) {
        let rowsHtml = '';
        let totalDebit = 0;
        let totalCredit = 0;
        window.actualJournalEntries.forEach(entry => {
            const deb = parseFloat(entry.debit) || 0;
            const cred = parseFloat(entry.credit) || 0;
            totalDebit += deb;
            totalCredit += cred;
            rowsHtml += `<tr>
                <td><code>${entry.account_code || '110000'}</code></td>
                <td class="font-weight-bold text-right">${entry.account_name}</td>
                <td class="text-success font-weight-bold">${deb.toFixed(2)}</td>
                <td class="text-danger font-weight-bold">${cred.toFixed(2)}</td>
                <td class="text-right">${entry.narration || ''}</td>
            </tr>`;
        });
        tbody.innerHTML = rowsHtml;
        document.getElementById('journalTotalDebit').innerText = totalDebit.toFixed(2);
        document.getElementById('journalTotalCredit').innerText = totalCredit.toFixed(2);
        $('#viewJournalModal').modal('show');
        return;
    }

    const netTotal = parseFloat(document.getElementById('summaryNetTotal').innerText) || 0;
    const taxTotal = parseFloat(document.getElementById('summaryTax').innerText) || 0;
    const subTotal = parseFloat(document.getElementById('summarySubtotal').innerText) || 0;
    const paidAmount = parseFloat(document.getElementById('grandPaidDisplay').value) || 0;
    const invoiceType = document.getElementById('invoiceTypeSelect').value;

    if (netTotal <= 0) {
        tbody.innerHTML = '<tr><td colspan="5" class="text-muted py-3">قم بإضافة أصناف أولاً لعرض القيود المحاسبية المتوقعة.</td></tr>';
        document.getElementById('journalTotalDebit').innerText = '0.00';
        document.getElementById('journalTotalCredit').innerText = '0.00';
        $('#viewJournalModal').modal('show');
        return;
    }

    let rowsHtml = '';
    let totalDebit = 0;
    let totalCredit = 0;

    // 1. حساب المدين (الصندوق أو العميل)
    if (invoiceType === 'cash') {
        rowsHtml += `<tr>
            <td><code>110101</code></td>
            <td class="font-weight-bold text-right">حساب الخزينة / الصندوق الرئيسي</td>
            <td class="text-success font-weight-bold">${paidAmount.toFixed(2)}</td>
            <td>0.00</td>
            <td class="text-right">إثبات المقبوضات النقدية مبيعات</td>
        </tr>`;
        totalDebit += paidAmount;

        if (netTotal - paidAmount > 0.01) {
            const rem = netTotal - paidAmount;
            rowsHtml += `<tr>
                <td><code>110201</code></td>
                <td class="font-weight-bold text-right">حساب الذمم والعملاء</td>
                <td class="text-success font-weight-bold">${rem.toFixed(2)}</td>
                <td>0.00</td>
                <td class="text-right">إثبات الجزء المتبقي آجل على العميل</td>
            </tr>`;
            totalDebit += rem;
        }
    } else {
        rowsHtml += `<tr>
            <td><code>110201</code></td>
            <td class="font-weight-bold text-right">حساب العملاء والذمم المدينة</td>
            <td class="text-success font-weight-bold">${netTotal.toFixed(2)}</td>
            <td>0.00</td>
            <td class="text-right">إثبات فاتورة بيع آجل على العميل</td>
        </tr>`;
        totalDebit += netTotal;
    }

    // 2. حساب الدائن (إيراد المبيعات)
    rowsHtml += `<tr>
        <td><code>410101</code></td>
        <td class="font-weight-bold text-right">حساب إيرادات المبيعات</td>
        <td>0.00</td>
        <td class="text-danger font-weight-bold">${subTotal.toFixed(2)}</td>
        <td class="text-right">إثبات صافي مبيعات البضاعة</td>
    </tr>`;
    totalCredit += subTotal;

    // 3. حساب الضريبة إذا وجدت
    if (taxTotal > 0) {
        rowsHtml += `<tr>
            <td><code>210301</code></td>
            <td class="font-weight-bold text-right">حساب ضريبة المبيعات المستحقة</td>
            <td>0.00</td>
            <td class="text-danger font-weight-bold">${taxTotal.toFixed(2)}</td>
            <td class="text-right">إثبات قيمة الضريبة المضافة</td>
        </tr>`;
        totalCredit += taxTotal;
    }

    tbody.innerHTML = rowsHtml;
    document.getElementById('journalTotalDebit').innerText = totalDebit.toFixed(2) + ' ر.ي';
    document.getElementById('journalTotalCredit').innerText = totalCredit.toFixed(2) + ' ر.ي';

    $('#viewJournalModal').modal('show');
}

// تصفية الفاتورة وتفريغ كافة البيانات
function resetSalesForm() {
    const itemsContainer = document.getElementById('itemsContainer');
    if (itemsContainer) {
        const rows = itemsContainer.querySelectorAll('.item-row');
        rows.forEach((r, idx) => {
            if (idx > 0) r.remove();
            else {
                r.querySelector('.product-search-input').value = '';
                r.querySelector('.select-product').value = '';
                r.querySelector('.quantity-input').value = '1';
                r.querySelector('.price-input').value = '0';
                r.querySelector('.total-input').value = '0';
                r.querySelector('.paid-input').value = '0';
                r.querySelector('.discount-input').value = '0';
                r.querySelector('.remaining-input').value = '0';
            }
        });
    }

    document.getElementById('summarySubtotal').innerText = '0.00';
    document.getElementById('summaryTax').innerText = '0.00';
    document.getElementById('summaryNetTotal').innerText = '0.00';
    document.getElementById('grandPaidDisplay').value = '0';
    document.getElementById('grandRemainingDisplay').innerText = '0.00';

    if (typeof showSystemAlert === 'function') {
        showSystemAlert("تم تصفية الفاتورة", "تم تصفية وإعادة تعيين بيانات الفاتورة بنجاح.", "info");
    }
}

// تحسين تجربة Autocomplete On Focus
document.addEventListener('focusin', function(e) {
    if (e.target && e.target.classList.contains('product-search-input')) {
        e.target.value = "";
    }
});

// تحسين تجربة Autocomplete On Focus
document.addEventListener('focusin', function(e) {
    if (e.target && e.target.classList.contains('aqnex-form-group')) {
        e.target.value = "";
    }
});
</script>

<!-- F-Keys Shortcuts Script -->
<script>
document.addEventListener('keydown', function(e) {
    if (e.key === 'F2') {
        e.preventDefault();
        window.open('create.php', '_blank');
    }
    if (e.key === 'F3') {
        e.preventDefault();
        openSearchInvoiceModal('view');
    }
    if (e.key === 'F4') {
        e.preventDefault();
        if (typeof openProductModalAndFocusSearch === 'function') {
            openProductModalAndFocusSearch('');
        }
    }
    if (e.key === 'F6') {
        e.preventDefault();
        openSearchInvoiceModal('edit');
    }
    if (e.key === 'F8') {
        e.preventDefault();
        openJournalModal();
    }
    if (e.key === 'F9') {
        e.preventDefault();
        window.print();
    }
    if (e.key === 'F10' || (e.ctrlKey && e.key.toLowerCase() === 's')) {
        e.preventDefault();
        const form = document.getElementById('salesForm');
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