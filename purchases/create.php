<?php
$dir_prefix = '../';
$module = 'purchases';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once($dir_prefix . 'includes/connect.php');

// ==========================================
// معالجة طلبات AJAX
// ==========================================

// 1. إضافة مورد سريع
if (isset($_POST['ajax_add_supplier'])) {
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

    $sql = "INSERT INTO suppliers (supp_name, phone, email, address, company_name, notes, buy_date, supp_madeen, supp_daain, d_s) 
            VALUES ('$supp_name', '$phone', '$email', '$address', '$company_name', '$notes', '$today', 0, 0, 0)";
    if ($conn->query($sql)) {
        $new_id = $conn->insert_id;
        echo json_encode(['status' => 'success', 'id' => $new_id, 'name' => $supp_name]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'خطأ أثناء الإضافة: ' . $conn->error]);
    }
    exit;
}

// 2. جلب معلومات ورصيد المورد
if (isset($_POST['ajax_get_supplier_info'])) {
    header('Content-Type: application/json; charset=utf-8');
    if (!isset($_SESSION['SESS_MEMBER_ID'])) {
        echo json_encode(['status' => 'error', 'message' => 'غير مصرح.']);
        exit;
    }
    $supp_id = intval($_POST['supp_id']);
    $res = $conn->query("SELECT supp_name, supp_daain, supp_madeen FROM suppliers WHERE supp_id = $supp_id AND d_s = 0 LIMIT 1");
    if ($row = $res->fetch_assoc()) {
        echo json_encode([
            'status' => 'success', 
            'name' => $row['supp_name'], 
            'daain' => floatval($row['supp_daain']),
            'madeen' => floatval($row['supp_madeen'])
        ]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'المورد غير موجود']);
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

$editing_invoice_id = isset($_GET['id']) ? intval($_GET['id']) : (isset($_GET['edit_id']) ? intval($_GET['edit_id']) : 0);
$editing_invoice = null;
$editing_items = [];

$actual_entries = [];
if ($editing_invoice_id > 0) {
    $res_edit_mst = $conn->query("SELECT * FROM purchase_invoices_mst WHERE id = $editing_invoice_id AND d_s = 0 LIMIT 1");
    if ($res_edit_mst && $res_edit_mst->num_rows > 0) {
        $editing_invoice = $res_edit_mst->fetch_assoc();
        $res_edit_dtl = $conn->query("SELECT * FROM purchase_invoices_dtl WHERE invoice_id = $editing_invoice_id AND d_s = 0 ORDER BY id ASC");
        if ($res_edit_dtl) {
            while ($item_row = $res_edit_dtl->fetch_assoc()) {
                $editing_items[] = $item_row;
            }
        }
        // جلب قيود اليومية الفعلية للفاتورة
        $res_journal = $conn->query("SELECT * FROM journal_entries WHERE ref_id = $editing_invoice_id AND ref_type = 'purchase' ORDER BY id ASC");
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
    } else {
        $editing_invoice_id = 0;
    }
}

// جلب التصنيفات
$categories_list = [];
$res_cat = $conn->query("SELECT catid, name FROM categories WHERE d_s = 0 ORDER BY name ASC");
if ($res_cat) {
    while($c = $res_cat->fetch_assoc()) {
        $categories_list[] = $c;
    }
}

// ==========================================
// معالجة حفظ الفاتورة
// ==========================================
if (isset($_POST['btn_save'])) {
    $build_date = date('Y-m-d', strtotime($_POST['build_date']));
    $grand_total = doubleval($_POST['grand_total']);
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

    // عند فاتورة آجل: المبلغ المدفوع = 0 ولا يتم الخصم من الصندوق
    if ($invoice_type === 'credit') {
        $total_paid_invoice = 0;
        $pay_from_box = 0;
        $payment_method = 'credit'; // آجل
    } else {
        $pay_from_box = isset($_POST['pay_from_box']) ? intval($_POST['pay_from_box']) : 1; // نقدي = خصم من الصندوق
        $total_paid_invoice = isset($_POST['total_paid_invoice']) ? doubleval($_POST['total_paid_invoice']) : 0;
    }

    $product_names = $_POST['product_name'] ?? [];
    $product_ids = $_POST['product_id'] ?? [];
    $barcodes = $_POST['barcode'] ?? [];
    $categories = $_POST['category_id'] ?? [];
    $category_names = $_POST['category_name'] ?? [];
    $quantities = $_POST['quantity'] ?? [];
    $unit_prices = $_POST['unit_price'] ?? [];
    $sale_prices = $_POST['sale_price'] ?? [];

    $total_paid_base  = round($total_paid_invoice * $exchange_rate, 4);
    $grand_total_base = 0;
    $count = count($product_names);

    for ($i = 0; $i < $count; $i++) {
        $p_name = trim($product_names[$i] ?? '');
        $p_id = trim($product_ids[$i] ?? '');
        $qty = intval($quantities[$i] ?? 0);
        $u_price = round(doubleval($unit_prices[$i] ?? 0), 4);
        
        if (empty($p_name) && empty($p_id)) continue;
        if ($qty > 0 && $u_price > 0) {
            $grand_total_base += round(($qty * $u_price) * $exchange_rate, 4);
        }
    }

    // عند اختيار شراء نقداً، إذا لم يحدد المدفوع صراحة نعتبره كامل إجمالي الفاتورة
    if ($invoice_type === 'cash' && $total_paid_base <= 0) {
        $total_paid_base = $grand_total_base;
    }
    $total_remaining_base = round(max(0, $grand_total_base - $total_paid_base), 4);

    if ($invoice_type === 'cash' && $total_paid_base > 0) {
        $box_balance = get_box_balance($conn, $selected_box_id);
        if ($box_balance < $total_paid_base) {
            $save_error = "لا يمكن إتمام الشراء النقدي لأن رصيد الصندوق المحدد (" . number_format($box_balance, 2) . " ر.ي) غير كافٍ لتغطية المبلغ النقدي (" . number_format($total_paid_base, 2) . " ر.ي).";
        }
    }

    if (empty($save_error)) {
        $conn->begin_transaction();
        try {
            $user_display = $_SESSION['SESS_FIRST_NAME'];
            
            // جلب المورد: أولوية لـ supplier_id ثم الاسم من supplier_name
            $supplier_id = 0;
            $supplier_name = "مورد عام";
            
            // 1. محاولة جلب الـ ID المباشر
            if (!empty($_POST['supplier_id']) && intval($_POST['supplier_id']) > 0) {
                $supplier_id = intval($_POST['supplier_id']);
                $res_s = $conn->query("SELECT supp_name FROM suppliers WHERE supp_id = $supplier_id AND d_s = 0 LIMIT 1");
                if ($res_s && $row_s = $res_s->fetch_assoc()) {
                    $supplier_name = $row_s['supp_name'];
                }
            }
            
            // 2. إذا لم يجد ID، ابحث عن المورد بالاسم المرسل من النموذج
            if ($supplier_id <= 0 && !empty($_POST['supplier_name'])) {
                $s_name_input = $conn->real_escape_string(trim($_POST['supplier_name']));
                $res_s = $conn->query("SELECT supp_id, supp_name FROM suppliers WHERE supp_name = '$s_name_input' AND d_s = 0 LIMIT 1");
                if ($res_s && $row_s = $res_s->fetch_assoc()) {
                    $supplier_id = intval($row_s['supp_id']);
                    $supplier_name = $row_s['supp_name'];
                } else {
                    // إنشاء مورد جديد باسمه
                    $conn->query("INSERT INTO suppliers (supp_name, supp_madeen, supp_daain, buy_date, d_s) VALUES ('$s_name_input', 0, 0, '$build_date', 0)");
                    $supplier_id = $conn->insert_id;
                    $supplier_name = $s_name_input;
                }
            }
            
            // 3. Fallback من الاسم البديل
            if ($supplier_id <= 0 && !empty($_POST['supplier_name_fallback'])) {
                $supplier_name = $conn->real_escape_string(trim($_POST['supplier_name_fallback']));
                $conn->query("INSERT INTO suppliers (supp_name, supp_madeen, supp_daain, buy_date, d_s) VALUES ('$supplier_name', 0, 0, '$build_date', 0)");
                $supplier_id = $conn->insert_id;
            }

            $editing_invoice_id = isset($_POST['editing_invoice_id']) ? intval($_POST['editing_invoice_id']) : 0;
            if ($editing_invoice_id > 0) {
                // 1. إلغاء تأثير أصناف الفاتورة القديمة من المخزون
                $prev_dtl = $conn->query("SELECT product_id, quantity FROM purchase_invoices_dtl WHERE invoice_id = $editing_invoice_id AND d_s = 0");
                if ($prev_dtl) {
                    while ($prev = $prev_dtl->fetch_assoc()) {
                        $p_id_prev = intval($prev['product_id']);
                        $qty_prev = floatval($prev['quantity']);
                        if ($p_id_prev > 0 && $qty_prev > 0) {
                            $conn->query("UPDATE products SET quantity = quantity - $qty_prev, total = (quantity - $qty_prev) * buy_price WHERE id = $p_id_prev");
                        }
                    }
                }
                // 2. إلغاء الأثر المالي والقيود والتفاصيل القديمة
                $prev_mst = $conn->query("SELECT supp_id, remaining_amount, paid_amount, box_id FROM purchase_invoices_mst WHERE id = $editing_invoice_id");
                if ($prev_mst && $prev_row = $prev_mst->fetch_assoc()) {
                    $prev_supp_id = intval($prev_row['supp_id']);
                    $prev_rem = floatval($prev_row['remaining_amount']);
                    $prev_paid = floatval($prev_row['paid_amount']);
                    $prev_box_id = intval($prev_row['box_id']);

                    if ($prev_rem > 0 && $prev_supp_id > 0) {
                        $conn->query("UPDATE suppliers SET supp_daain = supp_daain - $prev_rem WHERE supp_id = $prev_supp_id");
                    }
                    if ($prev_paid > 0 && $prev_box_id > 0) {
                        update_box_balance($conn, $prev_box_id, $prev_paid, 'addition', "إلغاء خصم فاتورة مشتريات #$editing_invoice_id للتعديل", date('Y-m-d'));
                    }
                }
                $conn->query("DELETE FROM journal_entries WHERE ref_id = $editing_invoice_id AND ref_type = 'purchase'");
                $conn->query("DELETE FROM accounting_journal WHERE ref_id = $editing_invoice_id AND ref_type = 'purchase'");
                $conn->query("DELETE FROM purchase_invoices_dtl WHERE invoice_id = $editing_invoice_id");

                $sql_update_mst = "UPDATE `purchase_invoices_mst` SET 
                    `invoice_date` = '$build_date', `supp_id` = $supplier_id, `supp_name` = '$supplier_name', `total_amount` = '$grand_total_base', `paid_amount` = '$total_paid_base', `remaining_amount` = '$total_remaining_base', `remark` = '$remark', `currency_code` = '$currency_code', `exchange_rate` = '$exchange_rate', `box_id` = $active_box_id, `invoice_type` = '$invoice_type', `payment_method` = '$payment_method', `wallet_type` = '$wallet_type' 
                    WHERE `id` = $editing_invoice_id";
                if (!$conn->query($sql_update_mst)) {
                    throw new Exception("فشل تحديث فاتورة المشتريات رقم #" . $editing_invoice_id);
                }
                $billing_id = $editing_invoice_id;
            } else {
                $invoice_no = 'PUR-' . date('Y') . '-' . str_pad(rand(1000, 9999), 4, '0', STR_PAD_LEFT);
                $sql_insert = "INSERT INTO `purchase_invoices_mst` 
                    (`invoice_no`, `invoice_date`, `supp_id`, `supp_name`, `total_amount`, `discount_amount`, `net_amount`, `paid_amount`, `remaining_amount`, `remark`, `currency_code`, `exchange_rate`, `box_id`, `invoice_type`, `payment_method`, `wallet_type`, `user_id`, `sector_id`, `d_s`) 
                    VALUES ('$invoice_no', '$build_date', $supplier_id, '$supplier_name', '$grand_total_base', 0, '$grand_total_base', '$total_paid_base', '$total_remaining_base', '$remark', '$currency_code', '$exchange_rate', $active_box_id, '$invoice_type', '$payment_method', '$wallet_type', $active_user_id, NULL, 0)";
                
                if (!$conn->query($sql_insert)) {
                    throw new Exception("فشل حفظ رأس الفاتورة: " . $conn->error);
                }
                $billing_id = $conn->insert_id;
            }

            $paid_ratio = ($grand_total_base > 0) ? min(1.0, $total_paid_base / $grand_total_base) : 0;
            $allocated_paid = 0;

            for ($i = 0; $i < $count; $i++) {
                $p_name = trim($product_names[$i] ?? '');
                $p_id = intval($product_ids[$i] ?? 0);
                $barcode = trim($barcodes[$i] ?? '');
                $cat_id = intval($categories[$i] ?? 0);
                $cat_name = trim($category_names[$i] ?? '');
                $qty = intval($quantities[$i] ?? 0);
                $u_price = doubleval($unit_prices[$i] ?? 0);
                $s_price = doubleval($sale_prices[$i] ?? 0);

                if (empty($p_name) && $p_id <= 0) continue;
                if ($qty <= 0 || $u_price <= 0) continue;

                $p_name_esc = $conn->real_escape_string($p_name);
                $unit_price_base = round($u_price * $exchange_rate, 4);
                $sale_price_base = round($s_price * $exchange_rate, 4);

                // معالجة التصنيف
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

                // معالجة المنتج (إنشاء جديد إذا لم يكن موجوداً)
                if ($p_id <= 0) {
                    $chk_p = $conn->query("SELECT id FROM products WHERE name = '$p_name_esc' AND delete_status = 0 LIMIT 1");
                    if ($chk_p && $chk_p->num_rows > 0) {
                        $p_id = intval($chk_p->fetch_assoc()['id']);
                    } else {
                        if ($cat_id <= 0) {
                            $res_cat_def = $conn->query("SELECT catid FROM categories WHERE d_s = 0 LIMIT 1");
                            $cat_id = ($res_cat_def && $res_cat_def->num_rows > 0) ? intval($res_cat_def->fetch_assoc()['catid']) : 0;
                            if ($cat_id <= 0) {
                                $conn->query("INSERT INTO categories (name, d_s) VALUES ('عام', 0)");
                                $cat_id = $conn->insert_id;
                            }
                        }
                        if ($sale_price_base <= 0) $sale_price_base = $unit_price_base * 1.25;
                        
                        // إصلاح خطأ الباركود المكرر: إذا كان فارغاً نضع NULL بدلاً من ''
                        $barcode_esc = $conn->real_escape_string($barcode);
                        $barcode_sql = empty($barcode_esc) ? 'NULL' : "'$barcode_esc'";
                        
                        $sql_ins_prod = "INSERT INTO products (name, quantity, buy_price, sale_price, catid, barcode, date, delete_status) 
                                         VALUES ('$p_name_esc', 0, $unit_price_base, $sale_price_base, $cat_id, $barcode_sql, NOW(), 0)";
                        if (!$conn->query($sql_ins_prod)) {
                            throw new Exception("فشل إنشاء المنتج الجديد: " . $conn->error);
                        }
                        $p_id = $conn->insert_id;
                    }
                } else {
                    $barcode_esc = $conn->real_escape_string($barcode);
                    if (!empty($barcode)) {
                        $conn->query("UPDATE products SET barcode = '$barcode_esc' WHERE id = $p_id");
                    }
                    if ($cat_id > 0) {
                        $conn->query("UPDATE products SET catid = $cat_id WHERE id = $p_id");
                    }
                    if ($sale_price_base > 0) {
                        $conn->query("UPDATE products SET sale_price = $sale_price_base WHERE id = $p_id");
                    }
                }

                if ($i === $count - 1) {
                    $line_paid_base = max(0, $total_paid_base - $allocated_paid);
                } else {
                    $line_paid_base = round(($qty * $unit_price_base) * $paid_ratio, 4);
                    $allocated_paid += $line_paid_base;
                }
                $line_remaining_base = max(0, ($qty * $unit_price_base) - $line_paid_base);

                $conv_factor = isset($_POST['conversion_factor'][$i]) ? doubleval($_POST['conversion_factor'][$i]) : 1.0;
                if ($conv_factor <= 0) $conv_factor = 1.0;
                $unit_name = isset($_POST['unit_name'][$i]) ? trim($_POST['unit_name'][$i]) : 'حبة';

                $p_name_store = $p_name;
                if ($unit_name !== 'حبة' && strpos($p_name_store, "($unit_name)") === false) {
                    $p_name_store .= " ($unit_name)";
                }
                $p_name_store_esc = $conn->real_escape_string($p_name_store);
                $unit_name_esc = $conn->real_escape_string($unit_name);

                $base_qty = round($qty * $conv_factor, 4);
                $base_buy_price = round($unit_price_base / $conv_factor, 4);
                $line_total_base = round($base_qty * $base_buy_price, 4);

                $sql_item = "INSERT INTO `purchase_invoices_dtl` 
                    (`invoice_id`, `product_id`, `product_name`, `barcode`, `unit_name`, `quantity`, `unit_cost`, `total_cost`, `d_s`) 
                    VALUES ('$billing_id', $p_id, '$p_name_store_esc', '$barcode_esc', '$unit_name_esc', '$base_qty', '$base_buy_price', '$line_total_base', 0)";
                
                if (!$conn->query($sql_item)) {
                    throw new Exception("فشل إدراج الصنف: " . $conn->error);
                }

                // 3. مزامنة جداول الحسابات القديمة (buys) إن وجدت لضمان دقة العمليات التقاريرية
                $chk_buys_tbl = $conn->query("SHOW TABLES LIKE 'buys'");
                if ($chk_buys_tbl && $chk_buys_tbl->num_rows > 0) {
                    $conn->query("INSERT INTO `buys` (`supp_name`, `supp_id`, `name`, `buy_price`, `quantity`, `buys_date`, `total_d`, `s`) 
                                  VALUES ('$supplier_name', $supplier_id, '$p_name_store_esc', $base_buy_price, $base_qty, '$build_date', $line_total_base, 0)");
                }

                $sql_update_qty = "UPDATE `products` 
                    SET `quantity`  = `quantity` + $base_qty,
                        `buy_price` = $base_buy_price,
                        `total`     = (`quantity` + $base_qty) * $base_buy_price
                    WHERE `id` = $p_id";
                if (!$conn->query($sql_update_qty)) {
                    throw new Exception("فشل تحديث المخزن: " . $conn->error);
                }

                $sql_log = "INSERT INTO `inventory_log` (`product_id`, `product_name`, `type`, `qty_change`, `new_qty`, `reason`, `user`) 
                            SELECT $p_id, name, 'purchase', $base_qty, quantity, 'شراء فاتورة #$billing_id', '$user_display' 
                            FROM products WHERE id = $p_id";
                $conn->query($sql_log);
            }

            // مزامنة جدول allbuys وجدول purchases القديم لضمان مطابقة جميع الشاشات في النظام
            $chk_allb_tbl = $conn->query("SHOW TABLES LIKE 'allbuys'");
            if ($chk_allb_tbl && $chk_allb_tbl->num_rows > 0) {
                $conn->query("INSERT INTO `allbuys` (`supp_name`, `total`, `remark`, `date`) 
                              VALUES ('$supplier_name', $grand_total_base, '$remark', '$build_date')");
            }
            $chk_pur_tbl = $conn->query("SHOW TABLES LIKE 'purchases'");
            if ($chk_pur_tbl && $chk_pur_tbl->num_rows > 0) {
                $conn->query("INSERT INTO `purchases` (`supp_id`, `supp_name`, `total`, `remark`, `date`, `currency_code`, `exchange_rate`, `box_id`, `remaining_total`, `invoice_type`, `payment_method`, `wallet_type`) 
                              VALUES ($supplier_id, '$supplier_name', $grand_total_base, '$remark', '$build_date', '$currency_code', '$exchange_rate', $active_box_id, $total_remaining_base, '$invoice_type', '$payment_method', '$wallet_type')");
            }

            // ============= تحديث مديونية المورد (الذمم الدائنة) =============
            // الآجل: كامل قيمة الفاتورة تضاف لذمم المورد
            // النقدي: المبلغ غير المدفوع فقط (إن وجد)
            if ($total_remaining_base > 0 && $supplier_id > 0) {
                $conn->query("UPDATE `suppliers` SET `supp_daain` = `supp_daain` + $total_remaining_base WHERE `supp_id` = $supplier_id");
            }

            // ============= تحديث رصيد الصندوق =============
            if ($invoice_type === 'cash' && $total_paid_base > 0 && $active_box_id > 0) {
                if (!update_box_balance($conn, $active_box_id, $total_paid_base, 'discount', "مدفوعات فاتورة مشتريات #$billing_id", $build_date)) {
                    throw new Exception("فشل تحديث رصيد الصندوق");
                }
            }

            // ============= القيود المحاسبية =============
            // قيد الشراء النقدي: (مدين: المخزون) <-> (دائن: الصندوق)
            if ($invoice_type === 'cash' && $total_paid_base > 0) {
                $credit_acc = 'الصندوق - ' . $box_name;
                if ($payment_method === 'wallet') {
                    $credit_acc = 'محفظة / بنك - ' . ($wallet_type ?: 'غير محدد');
                }
                post_journal_entry($conn, 'purchase', $billing_id, 'المخزون / البضاعة', $credit_acc, $total_paid_base, "شراء نقدي فاتورة #$billing_id", $user_display, $active_box_id, $currency_code, $exchange_rate, null);
            }

            // قيد الشراء الآجل: (مدين: المخزون) <-> (دائن: الذمم الدائنة - المورد)
            if ($total_remaining_base > 0) {
                $s_name_journal = $supplier_name ?: 'مورد';
                post_journal_entry($conn, 'purchase', $billing_id, 'المخزون / البضاعة', 'الذمم الدائنة - ' . $s_name_journal, $total_remaining_base, ($invoice_type === 'credit' ? 'شراء آجل' : 'جزء آجل') . " فاتورة #$billing_id", $user_display, null, $currency_code, $exchange_rate, null);
            }

            $conn->commit();
            echo "<script>window.location='create.php?id=$billing_id&saved=1';</script>";
            exit;
        } catch (Exception $e) {
            $conn->rollback();
            $save_error = 'فشل حفظ الفاتورة: ' . $e->getMessage();
        }
    }
}

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
.product-search-container, .barcode-search-container, .category-search-container { position: relative; }
.autocomplete-dropdown { position: absolute; top: 100%; right: 0; min-width: 320px; width: 100%; background: #ffffff !important; border: 1px solid #cbd5e1 !important; border-top: none; max-height: 260px; overflow-y: auto; z-index: 999999 !important; box-shadow: 0 10px 25px rgba(0,0,0,0.2) !important; border-bottom-left-radius: 4px; border-bottom-right-radius: 4px; }
.autocomplete-item { padding: 8px 12px; cursor: pointer; font-size: 0.85rem; text-align: right; }
.autocomplete-item:hover, .autocomplete-item.active { background-color: #f8f9fa; }
.autocomplete-item .item-meta { font-size: 0.75rem; color: #64748b; }
.autocomplete-item.create-new { background: #fef3c7; border-top: 2px dashed #f59e0b; font-weight: 700; color: #92400e; }
.table-flat { font-size: 0.85rem; }
.table-flat thead th { font-size: 0.8rem; font-weight: 700; padding: 10px 8px; background-color: #f8f9fa; border-bottom: 2px solid #dee2e6; }
.table-flat tbody td { padding: 8px; vertical-align: middle; }
.table-flat .form-control { font-size: 0.85rem; padding: 6px 8px; }
.invoice-summary { background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 6px; padding: 15px; }
.invoice-summary table { width: 100%; }
.invoice-summary td { padding: 8px 0; font-size: 0.9rem; }
.invoice-summary .total-label { font-weight: 600; color: #495057; }
.invoice-summary .total-value { font-weight: 700; font-size: 1.1rem; text-align: left; }
.accounting-guide { background: #fff3cd; border: 1px solid #ffc107; border-radius: 6px; padding: 15px; margin-top: 15px; }
.accounting-guide table { width: 100%; font-size: 0.85rem; }
.accounting-guide th { background-color: #ffe69c; color: #856404; font-weight: 700; padding: 8px; text-align: center; }
.accounting-guide td { padding: 6px 8px; border-bottom: 1px solid #ffe69c; }
.accounting-guide .editable-amount { background: #fff; border: 1px solid #ced4da; border-radius: 4px; padding: 4px 8px; font-weight: 600; width: 100%; text-align: center; }
.new-product-badge { display: inline-block; background: #fef3c7; color: #92400e; padding: 2px 8px; border-radius: 4px; font-size: 0.7rem; font-weight: 700; margin-right: 5px; }
.box-balance-display, .supplier-balance-display { background: #e7f3ff; border: 1px solid #b3d9ff; border-radius: 4px; padding: 8px 12px; margin-top: 8px; font-size: 0.85rem; font-weight: 600; color: #004085; }
.box-balance-warning { background: #fff3cd; border: 1px solid #ffc107; color: #856404; }
.supplier-balance-display.has-debt { background: #f8d7da; border-color: #f5c6cb; color: #721c24; }
.supplier-balance-display.has-credit { background: #d4edda; border-color: #c3e6cb; color: #155724; }
</style>

<div class="loading-overlay" id="loadingOverlay">
    <div class="loading-spinner"></div>
    <div class="loading-text">جاري التحميل...</div>
</div>


<!-- Onyx Pro System Window Header Bar -->
<div class="aqnex-window-header no-print">
    <div>
        <i class="bi bi-window-stack text-primary ml-1"></i>
        <span> نظام إدارة المشتريات - فاتورة المشتريات</span>
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
        <button type="submit" form="purchaseForm" name="btn_save" class="tool-btn btn-save btn-save-action" title="حفظ وترحيل الفاتورة (F10)">
            <i class="bi bi-floppy-fill"></i>
        </button>

        <!-- ✏️ تعديل الفاتورة -->
        <button type="button" class="tool-btn" title="تعديل فاتورة مشتريات محفوظة (F6)" onclick="openSearchPurchaseModal('edit');">
            <i class="bi bi-pencil-square" style="color: #d97706;"></i>
        </button>

        <!-- 🔍 البحث عن فاتورة مبيعات سابقة (F3) -->
        <button type="button" class="tool-btn btn-search" title="البحث عن فاتورة مشتريات سابقة (F3)" onclick="openSearchPurchaseModal('view');">
            <i class="bi bi-search"></i>
        </button>

        <!-- 🗑 حذف الفاتورة -->
        <button type="button" class="tool-btn btn-delete" title="حذف فاتورة المشتريات الحالية نهائياً" onclick="confirmDeleteCurrentPurchase();">
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
        <!-- 💵 سند صرف -->
        <button type="button" class="tool-btn" title="تسجيل سند صرف جديد" onclick="window.location.href='../expenses/create.php';" style="color: #16a34a; border-color: #86efac;">
            <i class="bi bi-journal-arrow-down"></i>
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

<div class="card-flat">
    <div class="card-body p-3">
        <?php if ($editing_invoice_id > 0): ?>
            <div class="alert alert-warning rounded-0 mb-3 text-right no-print" style="border: 1px solid #fbbf24; border-right: 4px solid #d97706 !important; background-color: #fffbeb; color: #92400e; padding: 10px 14px;">
                <i class="bi bi-pencil-square ml-1 font-weight-bold"></i>
                <strong>تأكيد التعديل:</strong> أنت تشاهد وتعدل الآن فاتورة المشتريات رقم <strong>#<?php echo $editing_invoice_id; ?></strong> (بتاريخ: <?php echo htmlspecialchars($editing_invoice['invoice_date'] ?? ''); ?> - المورد: <?php echo htmlspecialchars($editing_invoice['supp_name'] ?? ''); ?>). يمكنك تعديل أي حقل أو صنف ثم الضغط على <strong>حفظ (F10)</strong> لتحديث الفاتورة.
                <a href="create.php" class="btn btn-xs btn-outline-danger font-weight-bold mr-3" style="color: #dc2626; border-color: #fca5a5;"><i class="bi bi-arrow-counterclockwise"></i> إلغاء التعديل</a>
            </div>
        <?php endif; ?>

        <?php if (!empty($save_error)): ?>
            <script>
            document.addEventListener('DOMContentLoaded', function() {
                if (typeof AqnexAlert !== 'undefined') {
                    AqnexAlert.show(110, <?php echo json_encode("خطأ في حفظ الفاتورة: " . $save_error, JSON_UNESCAPED_UNICODE); ?>);
                }
            });
            </script>
        <?php endif; ?>

        <form method="POST" id="purchaseForm">
            <input type="hidden" name="editing_invoice_id" value="<?php echo $editing_invoice_id; ?>">
            <!-- ======================== بطاقة بيانات الفاتورة الرئيسية (مطابقة لشاشة المبيعات) ======================== -->
            <div class="card p-3 mb-3 no-print" style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:4px;">
                <div class="row">
                    <!-- العمود الأول -->
                    <div class="col-md-4">
                        <div class="aqnex-form-group mb-2">
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
                        <div class="aqnex-form-group mb-2">
                            <label class="aqnex-label">طريقة الدفع:</label>
                            <select name="invoice_type" id="invoiceTypeSelect" class="aqnex-select" onchange="togglePurchaseInvoiceType(this.value)" required>
                                <option value="cash" <?php echo ($editing_invoice && ($editing_invoice['invoice_type'] ?? '') === 'cash') ? 'selected' : ''; ?>>نقداً</option>
                                <option value="credit" <?php echo ($editing_invoice && ($editing_invoice['invoice_type'] ?? '') === 'credit') ? 'selected' : ''; ?>>آجل</option>
                                <option value="account" <?php echo ($editing_invoice && ($editing_invoice['invoice_type'] ?? '') === 'account') ? 'selected' : ''; ?>>من حساب</option>
                            </select>
                        </div>
                    </div>

                    <!-- العمود الثاني -->
                    <div class="col-md-4" id="boxSectionCol">
                        <div class="aqnex-form-group mb-2" id="boxSection">
                            <label class="aqnex-label">رقم الصندوق:</label>
                            <?php if ($is_admin): ?>
                                <?php $default_box_id = $editing_invoice ? intval($editing_invoice['box_id']) : get_user_box_id($conn, $active_user_id); ?>
                                <select name="box_id" id="boxSelect" class="aqnex-select" required onchange="updateBoxBalanceDisplay()">
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
                            <div class="small text-muted mt-1" id="boxBalanceDisplay">
                                <i class="bi bi-wallet2 ml-1"></i> رصيد الصندوق: <strong id="currentBoxBalance" class="text-primary">0.00</strong> ر.ي
                            </div>
                        </div>

                        <div class="aqnex-form-group mb-2">
                            <label class="aqnex-label">العملة والصرف:</label>
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

                        <div class="aqnex-form-group mb-2">
                            <label class="aqnex-label">تاريخ الشراء:</label>
                            <input type="date" name="build_date" class="aqnex-input" value="<?php echo $editing_invoice ? htmlspecialchars($editing_invoice['invoice_date']) : date('Y-m-d'); ?>" required>
                        </div>
                    </div>

                    <!-- العمود الثالث -->
                    <div class="col-md-4">
                        <div class="aqnex-form-group mb-2">
                            <label class="aqnex-label">اسم المورد:</label>
                            <div style="display: flex; gap: 4px; width: 100%;">
                                <select name="supplier_name" id="supplierSelect2" class="aqnex-select">

                                    <option value="" <?php echo (!$editing_invoice) ? 'selected' : ''; ?>>اختر المورد</option>
                                    <?php
                                    $sql_supp = "SELECT supp_id, supp_name, phone, COALESCE(supp_daain,0) as supp_daain, COALESCE(supp_madeen,0) as supp_madeen FROM suppliers WHERE d_s = 0 ORDER BY supp_id DESC";
                                    $res_supp = $conn->query($sql_supp);
                                    if ($res_supp) {
                                        while($row = $res_supp->fetch_assoc()) {
                                            $sel_c = ($editing_invoice && $editing_invoice['supp_name'] === $row['supp_name']) ? 'selected' : '';
                                            $net = floatval($row['supp_daain']) - floatval($row['supp_madeen']);
                                            echo "<option value='".htmlspecialchars($row['supp_name'])."' data-id='{$row['supp_id']}' data-daain='{$row['supp_daain']}' data-madeen='{$row['supp_madeen']}' data-balance='{$net}' $sel_c>".htmlspecialchars($row['supp_name'])."</option>";
                                        }
                                    }
                                    ?>
                                </select>                                <button type="button" class="btn btn-sm btn-outline-primary px-2" style="height: 26px; padding: 0 6px;" data-toggle="modal" data-target="#quickAddSupplierModal" title="مورد جديد">
                                    <i class="bi bi-plus-lg"></i>
                                </button>
                            </div>
                            <!-- شارة عرض رصيد المورد التفاعلية المباشرة مثل شاشة المبيعات -->
                            <div id="supplierBalanceBox" class="mt-2 p-2 border rounded font-weight-bold" style="background:#fff; border-color:#e2e8f0; font-size:0.8rem; display:none;">
                                <i class="bi bi-wallet2 ml-1 text-primary"></i> رصيد المورد: <span id="supplierBalanceVal" class="badge badge-info px-2 py-1" style="font-size:0.82rem;">0.00 ر.ي</span>
                            </div>
                            <!-- hidden field يحمل supplier_id الحقيقي -->
                            <input type="hidden" name="supplier_id" id="supplierHiddenId" value="<?php echo $editing_invoice ? intval($editing_invoice['supp_id']) : ''; ?>">
                        </div>

                        <div class="aqnex-form-group mb-2" id="paymentMethodSection">
                            <label class="aqnex-label">طريقة المحفظة:</label>
                            <select name="payment_method" id="paymentMethodSelect" class="aqnex-select" onchange="toggleWalletSection(this.value)">
                                <option value=""></option>
                                <option value="cash">نقداً</option>
                                <option value="wallet">محفظة إلكترونية / بنك</option>
                            </select>

                        </div>
                    </div>
                </div>
            </div>

            <!-- شريط البحث السريع والباركود والاستيراد -->
            <div class="card p-3 bg-light border-0 mb-3 no-print" style="border-radius: 4px;">
                <div class="row align-items-center">
                    <div class="col-md-6 mb-2 mb-md-0">
                        <div class="input-group">
                            <div class="input-group-prepend">
                                <span class="input-group-text bg-primary text-white border-0" style="padding: 0 10px; line-height: 28px; height: 28px;"><i class="bi bi-upc-scan"></i></span>
                            </div>
                            <input type="text" id="barcodeScanInput" class="form-control text-center font-weight-bold" placeholder="امسح باركود المنتج لإضافته مباشرة..." autocomplete="off" style="height: 28px; font-size: 0.85rem; border-color: #cbd5e1;">
                        </div>
                    </div>
                    <div class="col-md-6 text-md-left text-center">
                        <button type="button" id="quickProductSearchBtn" class="btn btn-sm btn-outline-primary px-3 font-weight-bold" style="height: 28px; font-size: 0.8rem; border-radius: 4px;">
                            <i class="bi bi-search ml-1"></i> F2 - البحث السريع
                        </button>
                        <button type="button" id="importExcelBtn" class="btn btn-sm btn-outline-success px-3 font-weight-bold mr-2" style="height: 28px; font-size: 0.8rem; border-radius: 4px;">
                            <i class="bi bi-file-earmark-excel ml-1"></i> استيراد من إكسل (CSV)
                        </button>
                        <input type="file" id="excelFileInput" accept=".csv, .txt" class="d-none">
                    </div>
                </div>
            </div>

            <!-- جدول المنتجات -->
            <div class="table-responsive">

                <table class="aqnex-grid-table" id="itemsTable">
                    <thead>
                        <tr>
                            <th style="width: 20%;">اسم الصنف</th>
                            <th style="width: 10%;">الباركود</th>
                            <th style="width: 12%;">التصنيف / المجموعة</th>
                            <th style="width: 8%;">الوحدة</th>
                            <th style="width: 8%;">المتوفر</th>
                            <th style="width: 8%;">الكمية</th>
                            <th style="width: 8%;">سعر الشراء</th>
                            <th style="width: 8%;">سعر البيع</th>
                            <th style="width: 8%;">المجموع</th>
                            <th style="width: 8%;">المدفوع</th>
                            <th style="width: 6%;">الخصم</th>
                            <th style="width: 8%;">الباقي</th>
                            <th class="no-print" style="width: 4%;">إجراء</th>
                        </tr>
                    </thead>
                    <tbody id="itemsContainer">
                        <?php if ($editing_invoice_id > 0 && !empty($editing_items)): ?>
                            <?php foreach ($editing_items as $ei): ?>
                            <?php
                                $ei_p_id = intval($ei['product_id'] ?? 0);
                                $ei_p_name = htmlspecialchars($ei['product_name'] ?? '');
                                $ei_unit = htmlspecialchars($ei['unit_name'] ?? 'حبة');
                                $ei_qty = floatval($ei['quantity'] ?? 1);
                                $ei_cost = floatval($ei['unit_cost'] ?? 0);
                                $ei_total = floatval($ei['total_cost'] ?? ($ei_qty * $ei_cost));
                                $ei_barcode = htmlspecialchars($ei['barcode'] ?? '');
                                
                                $ei_stock = 0;
                                $ei_cat_id = 0;
                                $ei_sale_price = 0;
                                if ($ei_p_id > 0) {
                                    $p_res = $conn->query("SELECT catid, sale_price, quantity, barcode FROM products WHERE id = $ei_p_id LIMIT 1");
                                    if ($p_res && $p_row = $p_res->fetch_assoc()) {
                                        $ei_stock = floatval($p_row['quantity']);
                                        $ei_cat_id = intval($p_row['catid']);
                                        $ei_sale_price = floatval($p_row['sale_price']);
                                        if (empty($ei_barcode)) {
                                            $ei_barcode = htmlspecialchars($p_row['barcode'] ?? '');
                                        }
                                    }
                                }
                                $ei_paid = floatval($ei['paid_amount'] ?? $ei_total);
                                $ei_discount = floatval($ei['discount_amount'] ?? 0);
                                $ei_remaining = max(0, $ei_total - $ei_paid - $ei_discount);
                            ?>
                            <tr class="item-row">
                                <td>
                                    <div class="product-search-container">
                                        <input type="text" class="aqnex-input product-search-input" placeholder="اسم أو باركود الصنف..." autocomplete="off" style="height: 26px; text-align: right;" value="<?php echo $ei_p_name; ?>">
                                        <input type="hidden" name="product_name[]" class="select-product-name" value="<?php echo $ei_p_name; ?>">
                                        <input type="hidden" name="product_id[]" class="select-product" value="<?php echo $ei_p_id; ?>">
                                        <div class="autocomplete-dropdown d-none"></div>
                                    </div>
                                    <div class="new-product-indicator d-none" style="font-size:0.68rem; color:#22c55e; margin-top:2px;">
                                        <i class="bi bi-plus-circle-fill"></i> صنف جديد
                                    </div>
                                    <input type="hidden" name="conversion_factor[]" class="conversion-factor" value="1.0000">
                                    <input type="hidden" name="unit_name[]" class="unit-name" value="<?php echo $ei_unit; ?>">
                                </td>
                                <td>
                                    <input type="text" name="barcode[]" class="aqnex-input barcode-input text-center" style="height: 26px;" placeholder="الباركود" value="<?php echo $ei_barcode; ?>">
                                </td>
                                <td>
                                    <div class="category-search-container" style="display: flex; gap: 2px; align-items: center;">
                                        <select name="category_id[]" class="form-control select-category" style="height: 26px; font-size: 0.82rem; padding: 2px; flex: 1;">
                                            <option value="">اختر المجموعة...</option>
                                            <?php foreach ($categories_list as $cat): ?>
                                                <option value="<?php echo $cat['catid']; ?>" <?php echo ($cat['catid'] == $ei_cat_id) ? 'selected' : ''; ?>><?php echo htmlspecialchars($cat['name']); ?></option>
                                            <?php endforeach; ?>
                                            <option value="new" style="font-weight: bold; color: #007bff;">+ إضافة مجموعة جديدة...</option>
                                        </select>
                                        <button type="button" class="btn btn-sm btn-outline-primary px-1 add-category-btn" title="إضافة مجموعة جديدة" style="height: 26px; min-width: 24px; line-height: 1;">
                                            <i class="bi bi-plus-lg"></i>
                                        </button>
                                    </div>
                                </td>
                                <td>
                                    <input type="text" name="unit_display[]" class="aqnex-input unit-display-input text-center bg-light" readonly value="<?php echo $ei_unit; ?>">
                                </td>
                                <td>
                                    <input type="text" class="aqnex-input stock-qty text-center bg-light" readonly value="<?php echo $ei_stock; ?>">
                                </td>
                                <td><input type="number" name="quantity[]" class="aqnex-input quantity-input text-center" min="1" value="<?php echo $ei_qty; ?>"></td>
                                <td><input type="number" step="any" name="unit_price[]" class="aqnex-input price-input text-center" value="<?php echo $ei_cost; ?>"></td>
                                <td><input type="number" step="any" name="sale_price[]" class="aqnex-input sale-price-input text-center" value="<?php echo $ei_sale_price; ?>"></td>
                                <td><input type="text" class="aqnex-input total-input text-center bg-light" readonly value="<?php echo number_format($ei_total, 2, '.', ''); ?>"></td>
                                <td><input type="number" step="any" name="paid_amount[]" class="aqnex-input paid-input text-center" value="<?php echo $ei_paid; ?>"></td>
                                <td><input type="number" step="any" name="discount_amount[]" class="aqnex-input discount-input text-center" value="<?php echo $ei_discount; ?>"></td>
                                <td><input type="text" name="remaining_amount[]" class="aqnex-input remaining-input text-center bg-light" readonly value="<?php echo number_format($ei_remaining, 2, '.', ''); ?>"></td>
                                <td class="no-print text-center">
                                    <button type="button" class="btn btn-sm btn-outline-danger p-1 remove-item-btn" style="height:24px; line-height:1;"><i class="bi bi-trash"></i></button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                        <tr class="item-row">
                            <td>
                                <div class="product-search-container">
                                    <input type="text" class="aqnex-input product-search-input" placeholder="اسم أو باركود الصنف..." autocomplete="off" style="height: 26px; text-align: right;">
                                    <input type="hidden" name="product_name[]" class="select-product-name" value="">
                                    <input type="hidden" name="product_id[]" class="select-product" value="">
                                    <div class="autocomplete-dropdown d-none"></div>
                                </div>
                                <div class="new-product-indicator d-none" style="font-size:0.68rem; color:#22c55e; margin-top:2px;">
                                    <i class="bi bi-plus-circle-fill"></i> صنف جديد
                                </div>
                                <input type="hidden" name="conversion_factor[]" class="conversion-factor" value="1.0000">
                                <input type="hidden" name="unit_name[]" class="unit-name" value="حبة">
                            </td>
                            <td>
                                <input type="text" name="barcode[]" class="aqnex-input barcode-input text-center" style="height: 26px;" placeholder="الباركود">
                            </td>
                            <td>
                                <div class="category-search-container" style="display: flex; gap: 2px; align-items: center;">
                                    <select name="category_id[]" class="form-control select-category" style="height: 26px; font-size: 0.82rem; padding: 2px; flex: 1;">
                                        <option value="">اختر المجموعة...</option>
                                        <?php foreach ($categories_list as $cat): ?>
                                            <option value="<?php echo $cat['catid']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                                        <?php endforeach; ?>
                                        <option value="new" style="font-weight: bold; color: #007bff;">+ إضافة مجموعة جديدة...</option>
                                    </select>
                                    <button type="button" class="btn btn-sm btn-outline-primary px-1 add-category-btn" title="إضافة مجموعة جديدة" style="height: 26px; min-width: 24px; line-height: 1;">
                                        <i class="bi bi-plus-lg"></i>
                                    </button>
                                </div>
                            </td>
                            <td>
                                <input type="text" name="unit_display[]" class="aqnex-input unit-display-input text-center bg-light" readonly value="حبة">
                            </td>
                            <td>
                                <input type="text" class="aqnex-input stock-qty text-center bg-light" readonly value="0">
                            </td>
                            <td><input type="number" name="quantity[]" class="aqnex-input quantity-input text-center" min="1" value="1"></td>
                            <td><input type="number" step="any" name="unit_price[]" class="aqnex-input price-input text-center" value="0"></td>
                            <td><input type="number" step="any" name="sale_price[]" class="aqnex-input sale-price-input text-center" value="0"></td>
                            <td><input type="text" class="aqnex-input total-input text-center bg-light" readonly value="0.00"></td>
                            <td><input type="number" step="any" name="paid_amount[]" class="aqnex-input paid-input text-center" value="0"></td>
                            <td><input type="number" step="any" name="discount_amount[]" class="aqnex-input discount-input text-center" value="0"></td>
                            <td><input type="text" name="remaining_amount[]" class="aqnex-input remaining-input text-center bg-light" readonly value="0.00"></td>
                            <td class="no-print text-center">
                                <button type="button" class="btn btn-sm btn-outline-danger p-1 remove-item-btn" style="height:24px; line-height:1;" title="حذف هذا الصنف من الفاتورة">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </td>
                        </tr>
                        <?php endif; ?>

                    </tbody>
                </table>
            </div>

            <div class="mt-3 no-print">
                <button type="button" id="addItemBtn" class="btn btn-sm btn-success font-weight-bold" style="border-radius:4px;">
                    <?php echo get_icon('plus', 'ml-1'); ?> إضافة صنف آخر
                </button>
            </div>

            <hr class="my-4">

            <!-- Onyx Summary Box & Remark Section (مطابق 100% لشاشة المبيعات) -->
            <div class="row mt-3">
                <div class="col-md-7">
                    <div class="aqnex-form-group">
                        <label class="aqnex-label font-weight-bold text-secondary">ملاحظات الفاتورة والمستند:</label>
                        <textarea name="remark" class="aqnex-input" rows="4" placeholder="ملاحظات حول عملية الشراء وتفاصيل المستند..."><?php echo $editing_invoice ? htmlspecialchars($editing_invoice['remark'] ?? '') : ''; ?></textarea>
                    </div>
                    <div class="mt-3 no-print text-right">
                        <button type="submit" name="btn_save" id="btnSavePurchase" class="btn btn-success font-weight-bold px-4 py-2" style="font-size:1rem; border-radius:4px;">
                            <?php echo get_icon('check', 'ml-1'); ?> حفظ الفاتورة وترحيلها المحاسبي (F10)
                        </button>
                    </div>
                </div>

                <div class="col-md-5">
                    <div class="aqnex-summary-box">
                        <div class="aqnex-summary-item">
                            <span class="label">إجمالي البنود:</span>
                            <span class="value"><span id="summarySubtotal">0.00</span> <small class="currency-symbol">ر.ي</small></span>
                        </div>
                        <div class="aqnex-summary-item">
                            <span class="label">الخصم المكتسب:</span>
                            <span class="value text-warning"><span id="summaryDiscount">0.00</span> <small class="currency-symbol">ر.ي</small></span>
                        </div>
                        <div class="aqnex-summary-item" style="background:#eff6ff; border-color:#93c5fd;">
                            <span class="label font-weight-bold" style="color:#1d4ed8;">صافي إجمالي المشتريات:</span>
                            <span class="value text-primary" style="font-size:1.1rem;"><input type="text" id="grandTotalDisplay" name="grand_total" class="border-0 bg-transparent text-left font-weight-bold text-primary p-0" style="width:120px; outline:none;" readonly value="<?php echo $editing_invoice ? floatval($editing_invoice['total_amount']) : '0'; ?>"> <small class="currency-symbol">ر.ي</small></span>
                        </div>
                        <div class="aqnex-summary-item" id="paidRow" style="background:#f0fdf4; border-color:#86efac;">
                            <span class="label font-weight-bold" style="color:#15803d;">إجمالي المدفوع (نقداً):</span>
                            <span class="value text-success"><input type="number" step="any" min="0" id="totalPaidInput" name="total_paid_invoice" class="border-0 bg-transparent text-left font-weight-bold text-success p-0" style="width:110px; outline:none;" value="<?php echo $editing_invoice ? floatval($editing_invoice['paid_amount']) : '0'; ?>"> <small class="currency-symbol">ر.ي</small></span>
                        </div>
                        <div class="aqnex-summary-item" style="background:#fef2f2; border-color:#fca5a5;">
                            <span class="label font-weight-bold" style="color:#b91c1c;">إجمالي المتبقي (آجل):</span>
                            <span class="value text-danger"><span id="totalRemainingDisplay">0.00</span> <small class="currency-symbol">ر.ي</small></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Onyx Pro User Audit Bar (مطابق لشاشة المبيعات) -->
            <div class="aqnex-audit-bar no-print mt-3">
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

            <!-- الدليل المحاسبي بعرض الصفحة الكامل والاحترافي (Full Width Accounting Guide) -->
            <div class="accounting-guide mt-4" style="width:100%;">
                <h6 style="color:#1e293b; font-weight:700;"><i class="bi bi-book-half ml-2 text-primary"></i>الدليل المحاسبي - القيود المالية الآلية للفاتورة</h6>
                <table style="width:100%; border-collapse:collapse; margin-top:8px;">
                    <thead>
                        <tr style="background:#f1f5f9; color:#475569;">
                            <th style="padding:8px 12px; border:1px solid #cbd5e1;">البيان المحاسبي</th>
                            <th style="padding:8px 12px; border:1px solid #cbd5e1;">الحساب المدين</th>
                            <th style="padding:8px 12px; border:1px solid #cbd5e1;">المبلغ المدين</th>
                            <th style="padding:8px 12px; border:1px solid #cbd5e1;">الحساب الدائن</th>
                            <th style="padding:8px 12px; border:1px solid #cbd5e1;">المبلغ الدائن</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td style="padding:8px 12px; border:1px solid #e2e8f0; font-weight:600;">الشراء النقدي (خصم من الصندوق)</td>
                            <td style="padding:8px 12px; border:1px solid #e2e8f0;">المخزون / البضاعة</td>
                            <td style="padding:8px 12px; border:1px solid #e2e8f0;"><input type="number" step="any" class="editable-amount border-0 bg-light text-center font-weight-bold" id="acc_purchase_cash" value="0" readonly style="width:100%;"></td>
                            <td style="padding:8px 12px; border:1px solid #e2e8f0;">الصندوق / البنك</td>
                            <td style="padding:8px 12px; border:1px solid #e2e8f0;"><input type="number" step="any" class="editable-amount border-0 bg-light text-center font-weight-bold" id="acc_purchase_cash_credit" value="0" readonly style="width:100%;"></td>
                        </tr>
                        <tr>
                            <td style="padding:8px 12px; border:1px solid #e2e8f0; font-weight:600;">الشراء الآجل (مستحق للمورد)</td>
                            <td style="padding:8px 12px; border:1px solid #e2e8f0;">المخزون / البضاعة</td>
                            <td style="padding:8px 12px; border:1px solid #e2e8f0;"><input type="number" step="any" class="editable-amount border-0 bg-light text-center font-weight-bold" id="acc_purchase_credit" value="0" readonly style="width:100%;"></td>
                            <td style="padding:8px 12px; border:1px solid #e2e8f0;">الذمم الدائنة - حساب المورد</td>
                            <td style="padding:8px 12px; border:1px solid #e2e8f0;"><input type="number" step="any" class="editable-amount border-0 bg-light text-center font-weight-bold" id="acc_purchase_credit_credit" value="0" readonly style="width:100%;"></td>
                        </tr>
                    </tbody>
                </table>
            </div>


        </form>
    </div>
</div>

<!-- مودال إضافة مورد جديد سريع (نسخة واحدة موحّدة وشغّالة عبر AJAX) -->
<div class="modal fade" id="quickAddSupplierModal" tabindex="-1" role="dialog" aria-hidden="true" style="z-index: 99999;">
    <div class="modal-dialog modal-dialog-centered" role="document" style="max-width: 440px;">
        <div class="modal-content text-right border-0 shadow">
            <div class="modal-header bg-primary text-white py-2">
                <h5 class="modal-title font-weight-bold" style="font-size: 1rem;"><i class="bi bi-person-plus-fill ml-2"></i>إضافة مورد جديد</h5>
                <button type="button" class="close text-white" data-dismiss="modal" data-bs-dismiss="modal" aria-label="إغلاق">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body p-3">
                <div class="alert alert-danger d-none" id="quickAddSupplierError"></div>
                <div class="form-group mb-2">
                    <label class="font-weight-bold small">اسم المورد <span class="text-danger">*</span></label>
                    <input type="text" id="newSupplierNameInput" class="aqnex-input font-weight-bold" placeholder="أدخل اسم المورد هنا..." required>
                </div>
                <div class="form-group mb-0">
                    <label class="font-weight-bold small">رقم الهاتف / الجوال</label>
                    <input type="text" id="newSupplierPhoneInput" class="aqnex-input" placeholder="أدخل رقم هاتف المورد...">
                </div>
            </div>
            <div class="modal-footer bg-light py-2">
                <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal" data-bs-dismiss="modal">إلغاء</button>
                <button type="button" id="saveQuickSupplierBtn" class="btn btn-primary btn-sm font-weight-bold px-4">
                    <i class="bi bi-check-circle ml-1"></i> حفظ وتحديد المورد
                </button>
            </div>
        </div>
    </div>
<!-- Modal: عرض القيود المحاسبية الآلية للفاتورة -->
<div class="modal fade" id="viewJournalModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
        <div class="modal-content" style="border-radius: 4px; border: 1px solid #94a3b8;">
            <div class="modal-header" style="background: linear-gradient(180deg, #f3e8ff 0%, #e9d5ff 100%); padding: 8px 12px;">
                <h6 class="modal-title font-weight-bold" style="color: #6b21a8; font-size: 0.85rem;">
                    <i class="bi bi-journal-bookmark-fill ml-1"></i> القيود المحاسبية للفاتورة (Double-Entry Journal)
                </h6>
                <button type="button" class="close" data-dismiss="modal" data-bs-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body p-3">
                <div class="alert alert-info rounded-0 p-2 mb-3" style="font-size: 0.73rem;">
                    <i class="bi bi-info-circle ml-1"></i> القيود المحاسبية الفعلية الناتجة عن عملية الشراء الحالية:
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
            <div class="modal-footer py-2 bg-light">
                <button type="button" class="btn btn-secondary btn-sm px-4" data-dismiss="modal" data-bs-dismiss="modal">إغلاق النافذة</button>
            </div>
        </div>
    </div>
</div>

<script type="text/javascript">
const categoriesList = <?php echo json_encode($categories_list, JSON_UNESCAPED_UNICODE); ?>;
const actualJournalEntries = <?php echo json_encode($actual_entries); ?>;
window.actualJournalEntries = actualJournalEntries; // expose globally for journal modal
let purchaseFormDirty = false;
let purchaseFormSubmitting = false;

document.addEventListener('DOMContentLoaded', function() {
    setTimeout(() => { purchaseFormDirty = false; }, 300);
    const purchaseForm = document.getElementById('purchaseForm');
    if (purchaseForm) {
        purchaseForm.addEventListener('change', () => { purchaseFormDirty = true; });
        purchaseForm.addEventListener('input',  () => { purchaseFormDirty = true; });
        purchaseForm.addEventListener('submit', () => { purchaseFormSubmitting = true; purchaseFormDirty = false; });
    }

    // 1. معالجة اختيار المورد وعرض رصيده المالي المباشر (مطابق لشاشة المبيعات)
    window.fetchSupplierDetails = function(name) {
        const box = document.getElementById('supplierBalanceBox');
        const valSpan = document.getElementById('supplierBalanceVal');
        const suppSelect = document.getElementById('supplierSelect2');

        if (!name || name.trim() === '') {
            if (box) box.style.display = 'none';
            return;
        }

        if (suppSelect && suppSelect.selectedIndex >= 0) {
            const selectedOpt = suppSelect.options[suppSelect.selectedIndex];
            const daain = parseFloat(selectedOpt.getAttribute('data-daain')) || 0;
            const madeen = parseFloat(selectedOpt.getAttribute('data-madeen')) || 0;
            const net = daain - madeen;

            if (box && valSpan) {
                box.style.display = 'block';
                if (net > 0) {
                    valSpan.className = 'badge badge-danger px-2 py-1';
                    valSpan.innerHTML = `<i class="bi bi-exclamation-triangle-fill ml-1"></i>دائن للمورد (له علينا مستحقات): ${net.toFixed(2)} ر.ي`;
                } else if (net < 0) {
                    valSpan.className = 'badge badge-success px-2 py-1';
                    valSpan.innerHTML = `<i class="bi bi-check-circle-fill ml-1"></i>مدين (عليه مبالغ لنا): ${Math.abs(net).toFixed(2)} ر.ي`;
                } else {
                    valSpan.className = 'badge badge-info px-2 py-1';
                    valSpan.innerHTML = `مُتزن / لا توجد ديون معلقة (0.00 ر.ي)`;
                }
            }
        }

        // استدعاء API لجلب التأكيد والتحديث المباشر من قاعدة البيانات
        fetch(`../api/get_supplier_details.php?name=${encodeURIComponent(name)}`)
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success' && box && valSpan) {
                    box.style.display = 'block';
                    const net = parseFloat(data.balance) || 0;
                    if (net > 0) {
                        valSpan.className = 'badge badge-danger px-2 py-1';
                        valSpan.innerHTML = `<i class="bi bi-exclamation-triangle-fill ml-1"></i>دائن للمورد (له علينا مستحقات): ${net.toFixed(2)} ر.ي`;
                    } else if (net < 0) {
                        valSpan.className = 'badge badge-success px-2 py-1';
                        valSpan.innerHTML = `<i class="bi bi-check-circle-fill ml-1"></i>مدين (عليه مبالغ لنا): ${Math.abs(net).toFixed(2)} ر.ي`;
                    } else {
                        valSpan.className = 'badge badge-info px-2 py-1';
                        valSpan.innerHTML = `مُتزن / لا توجد ديون معلقة (0.00 ر.ي)`;
                    }
                }
            })
            .catch(err => console.error("Supplier details fetch error:", err));
    };

    if (typeof $ !== 'undefined' && $('#supplierSelect2').length) {
        $('#supplierSelect2').on('change', function() {
            const selectedOpt = this.options[this.selectedIndex];
            const suppId = selectedOpt ? (selectedOpt.getAttribute('data-id') || '') : '';
            document.getElementById('supplierHiddenId').value = suppId;
            fetchSupplierDetails($(this).val());
        });
        if ($('#supplierSelect2').val()) {
            const selOpt = $('#supplierSelect2')[0].options[$('#supplierSelect2')[0].selectedIndex];
            if (selOpt) document.getElementById('supplierHiddenId').value = selOpt.getAttribute('data-id') || '';
            fetchSupplierDetails($('#supplierSelect2').val());
        }
    } else {
        const selEl = document.getElementById('supplierSelect2');
        if (selEl) {
            selEl.addEventListener('change', function() {
                const selectedOpt = this.options[this.selectedIndex];
                document.getElementById('supplierHiddenId').value = selectedOpt ? (selectedOpt.getAttribute('data-id') || '') : '';
                fetchSupplierDetails(this.value);
            });
            if (selEl.value) {
                const opt = selEl.options[selEl.selectedIndex];
                if (opt) document.getElementById('supplierHiddenId').value = opt.getAttribute('data-id') || '';
                fetchSupplierDetails(selEl.value);
            }
        }
    }

    // تحديث رصيد الصندوق عند تغيير الصندوق المالي (النسخة الوحيدة المعتمدة)
    window.updateBoxBalanceDisplay = function() {
        const boxSelect = document.getElementById("boxSelect");
        const userBoxId = document.getElementById("userBoxId");
        const currentBoxBalance = document.getElementById("currentBoxBalance");
        const boxBalanceDisplay = document.getElementById("boxBalanceDisplay");
        const totalPaidInput = document.getElementById("totalPaidInput");
        const invoiceTypeSelect = document.getElementById("invoiceTypeSelect");

        if (!currentBoxBalance) return;

        let balance = 0;
        if (boxSelect && boxSelect.selectedIndex >= 0) {
            const opt = boxSelect.options[boxSelect.selectedIndex];
            balance = parseFloat(opt ? opt.getAttribute("data-balance") : 0) || 0;
        } else if (userBoxId) {
            balance = parseFloat(userBoxId.getAttribute("data-balance")) || 0;
        }

        currentBoxBalance.textContent = balance.toFixed(2);

        if (boxBalanceDisplay) {
            const invType = invoiceTypeSelect ? invoiceTypeSelect.value : 'cash';
            const totalPaid = totalPaidInput ? (parseFloat(totalPaidInput.value) || 0) : 0;
            boxBalanceDisplay.style.display = 'block';
            if (invType === 'cash' && totalPaid > balance) {
                boxBalanceDisplay.classList.add('box-balance-warning');
                boxBalanceDisplay.innerHTML = '<i class="bi bi-exclamation-triangle-fill ml-1"></i> تحذير: رصيد الصندوق غير كافٍ! المتوفر: <strong>' + balance.toFixed(2) + '</strong> ر.ي';
            } else {
                boxBalanceDisplay.classList.remove('box-balance-warning');
                boxBalanceDisplay.innerHTML = '<i class="bi bi-wallet2 ml-1"></i> رصيد الصندوق: <strong id="currentBoxBalance" class="text-primary">' + balance.toFixed(2) + '</strong> ر.ي';
            }
        }
    };

    // دالة إخفاء/إظهار طريقة المحفظة والصندوق بناءً على نوع الفاتورة (النسخة الوحيدة المعتمدة)
    window.togglePurchaseInvoiceType = function(val) {
        const boxCol = document.getElementById('boxSectionCol');
        const boxSection = document.getElementById('boxSection');
        const paymentSection = document.getElementById('paymentMethodSection');
        const walletSection = document.getElementById('walletTypeSection');
        const totalPaidInput = document.getElementById('totalPaidInput');
        const paidRow = document.getElementById('paidRow');

        if (val === 'credit') {
            if (boxCol) {
                boxCol.classList.add('d-none');
                boxCol.style.cssText = 'display: none !important;';
            }
            if (boxSection) {
                boxSection.classList.add('d-none');
                boxSection.style.cssText = 'display: none !important;';
            }
            if (paymentSection) {
                paymentSection.classList.add('d-none');
                paymentSection.style.cssText = 'display: none !important;';
            }
            if (walletSection) {
                walletSection.classList.add('d-none');
                walletSection.style.cssText = 'display: none !important;';
            }
            if (totalPaidInput) { totalPaidInput.value = '0'; totalPaidInput.readOnly = true; totalPaidInput.style.opacity = '0.5'; }
            if (paidRow) paidRow.style.opacity = '0.4';

            const suppSelect = document.getElementById('supplierSelect2');
            const suppVal = suppSelect ? suppSelect.value.trim() : '';
            if (suppVal === 'مورد نقدي' || suppVal === '') {
                if (typeof AqnexAlert !== 'undefined') {
                    AqnexAlert.show(122, AQNEX_MESSAGES[122], suppSelect);
                } else {
                    alert('تنبيه: فاتورة الشراء الآجلة تتطلب تحديد مورد آجل مسجل وليس مورد نقدي!');
                }
            }
        } else {
            if (boxCol) {
                boxCol.classList.remove('d-none');
                boxCol.style.cssText = '';
            }
            if (boxSection) {
                boxSection.classList.remove('d-none');
                boxSection.style.cssText = '';
            }
            if (paymentSection) {
                paymentSection.classList.remove('d-none');
                paymentSection.style.cssText = '';
            }
            if (totalPaidInput) { totalPaidInput.readOnly = false; totalPaidInput.style.opacity = '1'; }
            if (paidRow) paidRow.style.opacity = '1';
            if (typeof window.updateBoxBalanceDisplay === 'function') window.updateBoxBalanceDisplay();
        }
        updateGrandTotals();
    };

    // دالة إظهار/إخفاء نوع المحفظة (النسخة الوحيدة المعتمدة)
    window.toggleWalletSection = function(val) {
        const sec = document.getElementById('walletTypeSection');
        const walletHidden = document.getElementById('walletTypeHidden');
        const walletSelect = document.getElementById('walletTypeSelect');
        if (!sec) return;
        if (val === 'wallet') {
            sec.classList.remove('d-none');
            if (walletSelect) walletSelect.name = 'wallet_type';
            if (walletHidden) walletHidden.name = '';
        } else {
            sec.classList.add('d-none');
            if (walletSelect) walletSelect.name = '';
            if (walletHidden) { walletHidden.name = 'wallet_type'; walletHidden.value = ''; }
        }
    };

    // تطبيق الحالة الأولية بناءً على نوع الفاتورة الحالي
    const invoiceTypeSel = document.getElementById('invoiceTypeSelect');
    if (invoiceTypeSel) {
        window.togglePurchaseInvoiceType(invoiceTypeSel.value);
    }


    // 2. معالجة إضافة مورد جديد (النسخة الوحيدة المعتمدة عبر AJAX)
    const saveQuickSupplierBtn = document.getElementById('saveQuickSupplierBtn');
    const quickAddSupplierError = document.getElementById('quickAddSupplierError');
    if (saveQuickSupplierBtn) {
        saveQuickSupplierBtn.addEventListener('click', function() {
            const nameInp = document.getElementById('newSupplierNameInput');
            const phoneInp = document.getElementById('newSupplierPhoneInput');
            const suppName = nameInp ? nameInp.value.trim() : '';
            const phone = phoneInp ? phoneInp.value.trim() : '';

            if (quickAddSupplierError) quickAddSupplierError.classList.add('d-none');

            if (!suppName) {
                if (typeof AqnexAlert !== 'undefined') {
                    AqnexAlert.show(101, 'يرجى كتابة اسم المورد أولاً!', nameInp);
                } else {
                    alert('يرجى كتابة اسم المورد أولاً!');
                }
                if (nameInp) nameInp.focus();
                return;
            }
            if (!phone) {
                if (typeof AqnexAlert !== 'undefined') {
                    AqnexAlert.show(124, AQNEX_MESSAGES[124], phoneInp);
                } else {
                    alert('يرجى كتابة رقم جوال المورد!');
                }
                if (phoneInp) phoneInp.focus();
                return;
            }

            const formData = new FormData();
            formData.append('ajax_add_supplier', '1');
            formData.append('supp_name', suppName);
            formData.append('phone', phone);
            formData.append('email', '');
            formData.append('address', '');
            formData.append('company_name', '');
            formData.append('notes', '');

            fetch('create.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success') {
                    const suppSelect = document.getElementById('supplierSelect2');
                    if (suppSelect) {
                        let opt = Array.from(suppSelect.options).find(o => o.value === data.name);
                        if (!opt) {
                            opt = new Option(data.name, data.name, true, true);
                            opt.setAttribute('data-id', data.id);
                            opt.setAttribute('data-daain', '0');
                            opt.setAttribute('data-madeen', '0');
                            opt.setAttribute('data-balance', '0');
                            suppSelect.add(opt);
                        }
                        suppSelect.value = data.name;
                        suppSelect.dispatchEvent(new Event('change', { bubbles: true }));

                        // مزامنة حقل الإكمال التلقائي بالهيدر (إن وُجد)
                        const wrapperInput = suppSelect.parentNode.querySelector('input.header-autocomplete-input');
                        if (wrapperInput) {
                            wrapperInput.value = data.name;
                        }
                    }
                    const suppHidden = document.getElementById('supplierHiddenId');
                    if (suppHidden) suppHidden.value = data.id;

                    if (typeof $ !== 'undefined' && $.fn && $.fn.modal) {
                        $('#quickAddSupplierModal').modal('hide');
                    }
                    if (nameInp) nameInp.value = '';
                    if (phoneInp) phoneInp.value = '';
                } else {
                    if (quickAddSupplierError) {
                        quickAddSupplierError.textContent = data.message || 'فشل إضافة المورد!';
                        quickAddSupplierError.classList.remove('d-none');
                    } else if (typeof AqnexAlert !== 'undefined') {
                        AqnexAlert.show(500, data.message || 'فشل إضافة المورد!');
                    } else {
                        alert(data.message || 'فشل إضافة المورد!');
                    }
                }
            })
            .catch(err => {
                console.error('Add supplier error:', err);
                if (quickAddSupplierError) {
                    quickAddSupplierError.textContent = 'حدث خطأ أثناء إرسال البيانات.';
                    quickAddSupplierError.classList.remove('d-none');
                }
            });
        });
    }

    // تحديث رصيد الصندوق عند تغيير الصندوق المالي (النسخة الوحيدة المعتمدة)
    window.updateBoxBalanceDisplay = function() {
        const boxSelect = document.getElementById("boxSelect");
        const userBoxId = document.getElementById("userBoxId");
        const currentBoxBalance = document.getElementById("currentBoxBalance");
        const boxBalanceDisplay = document.getElementById("boxBalanceDisplay");
        const totalPaidInput = document.getElementById("totalPaidInput");
        const invoiceTypeSelect = document.getElementById("invoiceTypeSelect");

        if (!currentBoxBalance) return;

        let balance = 0;
        if (boxSelect && boxSelect.selectedIndex >= 0) {
            const opt = boxSelect.options[boxSelect.selectedIndex];
            balance = parseFloat(opt ? opt.getAttribute("data-balance") : 0) || 0;
        } else if (userBoxId) {
            balance = parseFloat(userBoxId.getAttribute("data-balance")) || 0;
        }

        currentBoxBalance.textContent = balance.toFixed(2);

        if (boxBalanceDisplay) {
            const invType = invoiceTypeSelect ? invoiceTypeSelect.value : 'cash';
            const totalPaid = totalPaidInput ? (parseFloat(totalPaidInput.value) || 0) : 0;
            boxBalanceDisplay.style.display = 'block';
            if (invType === 'cash' && totalPaid > balance) {
                boxBalanceDisplay.classList.add('box-balance-warning');
                boxBalanceDisplay.innerHTML = '<i class="bi bi-exclamation-triangle-fill ml-1"></i> تحذير: رصيد الصندوق غير كافٍ! المتوفر: <strong>' + balance.toFixed(2) + '</strong> ر.ي';
            } else {
                boxBalanceDisplay.classList.remove('box-balance-warning');
                boxBalanceDisplay.innerHTML = '<i class="bi bi-wallet2 ml-1"></i> رصيد الصندوق: <strong id="currentBoxBalance" class="text-primary">' + balance.toFixed(2) + '</strong> ر.ي';
            }
        }
    };
    window.updateBoxBalanceDisplay();

    // 3. وظائف الجدول والحسابات
    const itemsContainer = document.getElementById("itemsContainer");
    const rowTemplate = document.querySelector(".item-row").cloneNode(true);
    const exchangeRateInput = document.getElementById("exchangeRateInput");

    function generateRandomBarcode() {
        const timestamp = Date.now().toString(36).toUpperCase();
        const random = Math.random().toString(36).substring(2, 6).toUpperCase();
        return 'PRD' + timestamp + random;
    }

    function updateRowCalculations(row) {
        if (!row) return;
        const qtyInp = row.querySelector(".quantity-input");
        const priceInp = row.querySelector(".price-input");
        const salePriceInp = row.querySelector(".sale-price-input");
        const totalInp = row.querySelector(".total-input");
        const profitInp = row.querySelector(".profit-input");

        const qty = qtyInp ? (parseFloat(qtyInp.value) || 0) : 0;
        const buyPrice = priceInp ? (parseFloat(priceInp.value) || 0) : 0;
        const salePrice = salePriceInp ? (parseFloat(salePriceInp.value) || 0) : 0;

        const total = qty * buyPrice;
        if (totalInp) totalInp.value = total.toFixed(2);

        if (profitInp) {
            if (salePrice > 0) {
                profitInp.value = ((salePrice - buyPrice) * qty).toFixed(2);
            } else {
                profitInp.value = ((buyPrice * 0.25) * qty).toFixed(2);
            }
        }

        updateGrandTotals();
        if (typeof window.updateBoxBalanceDisplay === 'function') {
            window.updateBoxBalanceDisplay();
        }
    }

    function updateGrandTotals() {
        let totalVal = 0;
        document.querySelectorAll(".item-row").forEach(function(row) {
            const totInp = row.querySelector(".total-input");
            if (totInp) totalVal += parseFloat(totInp.value) || 0;
        });

        const subtotalDisplay = document.getElementById("summarySubtotal");
        if (subtotalDisplay) subtotalDisplay.textContent = totalVal.toFixed(2);

        const discountDisplay = document.getElementById("summaryDiscount");
        if (discountDisplay) discountDisplay.textContent = "0.00";

        const grandDisplay = document.getElementById("grandTotalDisplay");
        if (grandDisplay) grandDisplay.value = totalVal.toFixed(2);

        const paidInput = document.getElementById("totalPaidInput");
        const paid = paidInput ? (parseFloat(paidInput.value) || 0) : 0;

        const remainingDisplay = document.getElementById("totalRemainingDisplay");
        if (remainingDisplay) remainingDisplay.textContent = Math.max(0, totalVal - paid).toFixed(2);

        updateAccountingGuide();
    }


    function updateAccountingGuide() {
        const grandDisp = document.getElementById("grandTotalDisplay");
        const totalVal = grandDisp ? (parseFloat(grandDisp.value) || 0) : 0;
        const paidInput = document.getElementById("totalPaidInput");
        const paid = paidInput ? (parseFloat(paidInput.value) || 0) : 0;
        const remaining = Math.max(0, totalVal - paid);

        const accCash = document.getElementById("acc_purchase_cash");
        if (accCash) accCash.value = paid.toFixed(2);
        const accCashCr = document.getElementById("acc_purchase_cash_credit");
        if (accCashCr) accCashCr.value = paid.toFixed(2);
        const accCred = document.getElementById("acc_purchase_credit");
        if (accCred) accCred.value = remaining.toFixed(2);
        const accCredCr = document.getElementById("acc_purchase_credit_credit");
        if (accCredCr) accCredCr.value = remaining.toFixed(2);
    }

    window.updateRowCalculations = updateRowCalculations;
    window.updateGrandTotals = updateGrandTotals;
    window.updateAccountingGuide = updateAccountingGuide;

    document.getElementById("totalPaidInput").addEventListener("input", function() {
        updateGrandTotals();
        updateBoxBalanceDisplay();
    });

    function initCategorySelect2(selectElement) {
        if (window.jQuery && $.fn.select2) {
            $(selectElement).select2({
                dir: 'rtl',
                language: 'ar',
                width: '100%',
                placeholder: 'اختر المجموعة...'
            });
        }
    }

    // تهيئة القوائم للتصنيف عند التحميل
    document.querySelectorAll(".select-category").forEach(sel => {
        initCategorySelect2(sel);
    });

    // إضافة صف جديد
    document.getElementById("addItemBtn").addEventListener("click", function() {
        const newRow = rowTemplate.cloneNode(true);
        newRow.querySelectorAll("input").forEach(input => {
            if (input.classList.contains('quantity-input')) {
                input.value = "1";
            } else if (input.classList.contains('total-input') || input.classList.contains('profit-input')) {
                input.value = "0";
            } else {
                input.value = "";
            }
        });
        const selectProduct = newRow.querySelector(".select-product");
        if (selectProduct) selectProduct.value = "";
        const selectName = newRow.querySelector(".select-product-name");
        if (selectName) selectName.value = "";
        const unitName = newRow.querySelector(".unit-name");
        if (unitName) unitName.value = "حبة";
        const unitDisplay = newRow.querySelector(".unit-display-input");
        if (unitDisplay) unitDisplay.value = "حبة";
        
        const indicator = newRow.querySelector(".new-product-indicator");
        if (indicator) indicator.classList.add("d-none");
        newRow.querySelectorAll(".autocomplete-dropdown").forEach(d => { d.classList.add("d-none"); d.innerHTML = ""; });
        
        // تنظيف وتهيئة Select2 للتصنيف في الصف الجديد
        const newCatSelect = newRow.querySelector(".select-category");
        if (newCatSelect) {
            newRow.querySelectorAll(".select2-container").forEach(el => el.remove());
            if (window.jQuery && $(newCatSelect).data('select2')) {
                try { $(newCatSelect).select2('destroy'); } catch(e){}
            }
            newCatSelect.removeAttribute("data-select2-id");
            newCatSelect.value = "";
        }
        
        itemsContainer.appendChild(newRow);
        
        if (newCatSelect) {
            initCategorySelect2(newCatSelect);
        }
    });

    // حذف صف
    itemsContainer.addEventListener("click", function(e) {
        if (e.target.classList.contains("remove-item-btn") || e.target.closest(".remove-item-btn")) {
            if (document.querySelectorAll(".item-row").length > 1) {
                e.target.closest(".item-row").remove();
                updateGrandTotals();
            }
        }
    });

    // توليد باركود عند الضغط على الزر
    itemsContainer.addEventListener("click", function(e) {
        if (e.target.closest(".generate-barcode-btn")) {
            const row = e.target.closest(".item-row");
            const barcodeInput = row.querySelector(".barcode-input");
            barcodeInput.value = generateRandomBarcode();
            barcodeInput.focus();
            barcodeInput.select();
        }
    });

    // اختيار المنتج من القائمة عند الضغط عليه
    itemsContainer.addEventListener("click", function(e) {
        const item = e.target.closest(".autocomplete-item");
        if (item) {
            const row = item.closest(".item-row");
            const productData = item.getAttribute("data-product");
            if (productData && row) {
                const product = JSON.parse(productData);
                selectProductForRow(row, product);
            } else if (item.classList.contains("create-new")) {
                const newName = item.getAttribute("data-new-name");
                selectNewProduct(row, newName);
            }
        }
    });

    // فتح قائمة المنتجات مباشرة عند التركيز أو النقر
    itemsContainer.addEventListener("focusin", function(e) {
        if (e.target.matches(".product-search-input")) {
            const row = e.target.closest(".item-row");
            if (!row) return;
            window.activeSearchRow = row;
            e.target.value = "";
            showProductAutocompleteDropdown(e.target);
        }
    });

    itemsContainer.addEventListener("click", function(e) {
        if (e.target.matches(".product-search-input")) {
            const row = e.target.closest(".item-row");
            if (!row) return;
            window.activeSearchRow = row;
            e.target.value = "";
            showProductAutocompleteDropdown(e.target);
        }
    });

    // التعامل مع المدخلات (بحث، كميات، أسعار) والحساب الفوري المباشر
    function handleRowInputChange(e) {
        if (e.target.matches(".quantity-input, .price-input, .sale-price-input")) {
            const row = e.target.closest(".item-row");
            if (row) updateRowCalculations(row);
        }
    }
    itemsContainer.addEventListener("input", handleRowInputChange);
    itemsContainer.addEventListener("change", handleRowInputChange);
    itemsContainer.addEventListener("keyup", handleRowInputChange);

    itemsContainer.addEventListener("input", function(e) {
        if (e.target.matches(".product-search-input")) {
            const row = e.target.closest(".item-row");
            const indicator = row.querySelector(".new-product-indicator");
            const hiddenId = row.querySelector(".select-product");
            
            if (!hiddenId.value || hiddenId.value === "-1") {
                row.querySelector(".select-product-name").value = e.target.value;
                hiddenId.value = "-1";
                indicator.classList.remove("d-none");
            } else {
                indicator.classList.add("d-none");
            }
            showProductAutocompleteDropdown(e.target);
        }

        if (e.target.matches(".category-input")) {
            showCategoryAutocompleteDropdown(e.target);
        }
    });

    // منع حفظ الفاتورة تلقائياً عند الضغط على زر الانتر في الحقول
    if (purchaseForm) {
        purchaseForm.addEventListener("keydown", function(e) {
            if (e.key === "Enter") {
                if (e.target.tagName !== "BUTTON" && e.target.tagName !== "TEXTAREA" && e.target.type !== "submit") {
                    e.preventDefault();
                }
            }
        });

        purchaseForm.addEventListener("submit", function(e) {
            const suppSelect = document.getElementById("supplierSelect2");
            const suppVal = suppSelect ? suppSelect.value.trim() : "";
            if (!suppVal) {
                e.preventDefault();
                e.stopPropagation();
                if (typeof AqnexAlert !== 'undefined' && AqnexAlert.show) {
                    AqnexAlert.show(101, AQNEX_MESSAGES[101] || 'فضلاً اختر اسم المورد / العمـيل', suppSelect);
                } else {
                    alert("فضلاً اختر اسم المورد أولاً!");
                }
                return false;
            }

            const pMethodSelect = document.getElementById("invoiceTypeSelect");
            const pMethodVal = pMethodSelect ? pMethodSelect.value.trim() : "";
            if (!pMethodVal) {
                e.preventDefault();
                e.stopPropagation();
                if (typeof AqnexAlert !== 'undefined' && AqnexAlert.show) {
                    AqnexAlert.show(886, AQNEX_MESSAGES[886] || 'فضلاً حدد نوع طريقة الدفع', pMethodSelect);
                } else {
                    alert("فضلاً حدد نوع طريقة الدفع!");
                }
                return false;
            }
        });
    }

    // معالجة التنقل بزر الانتر داخل الصفوف (منع الحفظ التلقائي ومطابقة شاشة المبيعات)
    itemsContainer.addEventListener("keydown", function(e) {
        if (e.key === "Enter") {
            e.preventDefault();
            e.stopPropagation();
            const row = e.target.closest(".item-row");
            if (!row) return;

            if (e.target.classList.contains("product-search-input")) {
                const dropdown = row.querySelector('.product-search-container .autocomplete-dropdown');
                if (dropdown && !dropdown.classList.contains('d-none')) {
                    const firstItem = dropdown.querySelector('.autocomplete-item');
                    if (firstItem) {
                        firstItem.click();
                        return;
                    }
                }
                const qtyInput = row.querySelector(".quantity-input");
                if (qtyInput) { qtyInput.focus(); qtyInput.select(); }
            } else if (e.target.classList.contains("quantity-input")) {
                const priceInput = row.querySelector(".price-input");
                if (priceInput) {
                    priceInput.focus();
                    priceInput.select();
                }
            } else if (e.target.classList.contains("price-input")) {
                const paidInput = row.querySelector(".paid-input");
                if (paidInput) {
                    paidInput.focus();
                    paidInput.select();
                } else {
                    moveToNextPurchaseRowOrCreateNew(row);
                }
            } else if (e.target.classList.contains("paid-input")) {
                const discountInput = row.querySelector(".discount-input");
                if (discountInput) {
                    discountInput.focus();
                    discountInput.select();
                } else {
                    moveToNextPurchaseRowOrCreateNew(row);
                }
            } else if (e.target.classList.contains("discount-input")) {
                moveToNextPurchaseRowOrCreateNew(row);
            }
        }
    });


    function moveToNextPurchaseRowOrCreateNew(row) {
        const nextRow = row.nextElementSibling;
        if (nextRow && nextRow.classList.contains("item-row")) {
            const nextSearch = nextRow.querySelector(".product-search-input");
            if (nextSearch) {
                window.activeSearchRow = nextRow;
                nextSearch.focus();
                nextSearch.value = "";
                showProductAutocompleteDropdown(nextSearch);
            }
        } else {
            const addItemBtn = document.getElementById("addItemBtn");
            if (addItemBtn) addItemBtn.click();
            const rows = itemsContainer.querySelectorAll(".item-row");
            const lastRow = rows[rows.length - 1];
            if (lastRow) {
                const lastSearch = lastRow.querySelector(".product-search-input");
                if (lastSearch) {
                    window.activeSearchRow = lastRow;
                    lastSearch.focus();
                    lastSearch.value = "";
                    showProductAutocompleteDropdown(lastSearch);
                }
            }
        }
    }

    // عرض قائمة المنتجات عند البحث المباشر (مطابق للمبيعات فورياً)
    function showProductAutocompleteDropdown(input) {
        const row = input.closest(".item-row");
        if (!row) return;
        const dropdown = row.querySelector('.product-search-container .autocomplete-dropdown');
        if (!dropdown) return;
        const query = input.value.trim();

        fetch(`../api/search_products.php?q=${encodeURIComponent(query)}`)
            .then(res => res.json())
            .then(products => {
                let html = '';
                if (products && products.length > 0) {
                    const rate = parseFloat(exchangeRateInput ? exchangeRateInput.value : 1.0) || 1.0;
                    html = products.map(product => {
                        const pJson = escapeHtml(JSON.stringify(product));
                        const priceConverted = (parseFloat(product.buy_price || 0) / rate).toFixed(2);
                        return `
                            <div class="autocomplete-item text-right" data-id="${product.id}" data-product='${pJson}' style="padding: 6px 10px; cursor: pointer; border-bottom: 1px solid #f1f5f9; background: #ffffff;">
                                <div class="font-weight-bold" style="font-size: 0.8rem; color: #1e293b;">${escapeHtml(product.name)}</div>
                                <div style="font-size: 0.72rem; color: #64748b;">
                                    باركود: ${escapeHtml(product.barcode || '—')} | المخزون: ${parseFloat(product.quantity || 0)} | سعر الشراء: <strong class="text-primary">${priceConverted}</strong> ر.ي
                                </div>
                            </div>
                        `;
                    }).join('');
                    dropdown.innerHTML = html;
                    dropdown.classList.remove('d-none');
                } else {
                    dropdown.innerHTML = '<div style="padding: 8px; color: #94a3b8; font-size: 0.78rem; text-align: center;">لا توجد نتائج</div>';
                    dropdown.classList.remove('d-none');
                }

                if (query) {
                    const createNewHtml = `<div class="autocomplete-item create-new" data-id="-1" data-new-name="${escapeHtml(query)}" style="padding: 6px 10px; cursor: pointer; background: #f0fdf4; border-top: 1px dashed #22c55e; color: #166534; font-weight: bold;">
                        <i class="bi bi-plus-circle ml-1"></i> إنشاء منتج جديد: <strong>${escapeHtml(query)}</strong>
                    </div>`;
                    dropdown.innerHTML += createNewHtml;
                }
            })
            .catch(err => {
                console.error('Inline search error:', err);
            });
    }

    document.addEventListener("click", function(e) {

        if (!e.target.closest(".product-search-container") && !e.target.closest(".category-search-container") && !e.target.closest(".barcode-search-container")) {
            document.querySelectorAll(".autocomplete-dropdown").forEach(d => d.classList.add("d-none"));
        }
    });

    // عرض قائمة التصنيفات

    function showCategoryAutocompleteDropdown(input) {
        const row = input.closest(".item-row");
        const container = input.closest(".category-search-container");
        const dropdown = container.querySelector('.autocomplete-dropdown');
        const query = input.value.trim().toLowerCase();

        dropdown.classList.remove('d-none');
        
        let filteredCategories = categoriesList;
        if (query) {
            filteredCategories = categoriesList.filter(cat => cat.name.toLowerCase().includes(query));
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
    }

    // معرّضة على window ليتمكن مودال البحث السريع العام (F4) في الفوتر من استدعائها مباشرة
    window.selectProductForRow = function selectProductForRow(row, product) {
        if (!row || !product) return;
        const container = row.querySelector(".product-search-container");
        if (!container) return;
        const input = container.querySelector(".product-search-input");
        const hiddenInput = container.querySelector(".select-product");
        const nameInput = container.querySelector(".select-product-name");
        const dropdown = container.querySelector(".autocomplete-dropdown");
        const indicator = row.querySelector(".new-product-indicator");

        if (input) input.value = product.name || "";
        if (hiddenInput) {
            hiddenInput.value = product.id || "";
            hiddenInput.setAttribute("data-base-buy-price", product.buy_price || 0);
        }
        if (nameInput) nameInput.value = product.name || "";

        const conversionFactor = parseFloat(product.conversion_factor) || 1.0;
        const convInp = row.querySelector(".conversion-factor");
        if (convInp) convInp.value = conversionFactor;
        
        const unit = product.unit_name || "حبة";
        const unitNameInp = row.querySelector(".unit-name");
        if (unitNameInp) unitNameInp.value = unit;
        const unitDispInp = row.querySelector(".unit-display-input");
        if (unitDispInp) unitDispInp.value = unit;

        const stockQtyInp = row.querySelector(".stock-qty");
        if (stockQtyInp) stockQtyInp.value = Math.floor((parseFloat(product.quantity) || 0) / conversionFactor);

        const barcodeInp = row.querySelector(".barcode-input");
        if (barcodeInp) barcodeInp.value = product.barcode || "";

        const catSelect = row.querySelector(".select-category");
        if (catSelect && product.catid) {
            catSelect.value = product.catid;
            if (window.jQuery && $(catSelect).data('select2')) {
                $(catSelect).trigger('change.select2');
            }
        }

        const rate = parseFloat(exchangeRateInput ? exchangeRateInput.value : 1.0) || 1.0;
        const buyPriceConverted = (parseFloat(product.buy_price || 0) / rate).toFixed(2);
        const salePriceConverted = (parseFloat(product.sale_price || 0) / rate).toFixed(2);

        const priceInp = row.querySelector(".price-input");
        if (priceInp) priceInp.value = buyPriceConverted;

        const salePriceInp = row.querySelector(".sale-price-input");
        if (salePriceInp) salePriceInp.value = salePriceConverted;

        const qtyInp = row.querySelector(".quantity-input");
        if (qtyInp) qtyInp.value = 1;

        if (dropdown) {
            dropdown.classList.add("d-none");
            dropdown.innerHTML = "";
        }
        if (indicator) indicator.classList.add("d-none");

        updateRowCalculations(row);

        setTimeout(() => {
            if (qtyInp) { qtyInp.focus(); qtyInp.select(); }
        }, 100);
    };

    const selectProductForRow = window.selectProductForRow;

    // معرّضة على window لنفس سبب selectProductForRow
    window.addScannedPurchaseProduct = function addScannedPurchaseProduct(product) {
        let existingRow = null;
        document.querySelectorAll(".item-row").forEach(function(row) {
            const selectVal = row.querySelector(".select-product").value;
            if (selectVal == product.id) {
                existingRow = row;
            }
        });

        if (existingRow) {
            let qtyInput = existingRow.querySelector(".quantity-input");
            let currentQty = parseFloat(qtyInput.value) || 0;
            qtyInput.value = currentQty + 1;
            updateRowCalculations(existingRow);
        } else {
            let rows = document.querySelectorAll(".item-row");
            let targetRow = null;
            if (rows.length === 1 && rows[0].querySelector(".select-product").value === "") {
                targetRow = rows[0];
            } else {
                document.getElementById("addItemBtn").click();
                const newRows = document.querySelectorAll(".item-row");
                targetRow = newRows[newRows.length - 1];
            }
            selectProductForRow(targetRow, product);
        }
    };
    const addScannedPurchaseProduct = window.addScannedPurchaseProduct;

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
                            addScannedPurchaseProduct(match);
                            barcodeScanInput.value = "";
                            barcodeScanInput.focus();
                        } else {
                            if (typeof AqnexAlert !== 'undefined') {
                                AqnexAlert.show(105, 'عذراً، الصنف صاحب الباركود (' + q + ') غير موجود بالمخزون/الحسابات!', barcodeScanInput);
                            } else {
                                alert("عذراً، الصنف صاحب الباركود (" + q + ") غير موجود بالمخزون!");
                            }
                        }
                    })
                    .catch(err => {
                        console.error("Barcode scan error:", err);
                    });
            }
        });
    }

    const purchaseFormEl = document.querySelector('form');
    if (purchaseFormEl) {
        purchaseFormEl.addEventListener('submit', function(e) {
            const invTypeSelect = document.getElementById('invoiceTypeSelect');
            const suppSelect = document.getElementById('supplierSelect2');
            const invType = invTypeSelect ? invTypeSelect.value : 'cash';
            const suppName = suppSelect ? suppSelect.value.trim() : '';

            if (invType === 'credit') {
                if (suppName === 'مورد نقدي' || suppName === '') {
                    e.preventDefault();
                    if (typeof AqnexAlert !== 'undefined') {
                        AqnexAlert.show(122, AQNEX_MESSAGES[122], suppSelect);
                    } else {
                        alert('خطأ: لا يمكن حفظ فاتورة شراء آجلة لـ (مورد نقدي)! يرجى تحديد مورد آجل أولاً.');
                    }
                    return false;
                }
            }
        });
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
            if (priceInput) { priceInput.focus(); priceInput.select(); }
        }, 100);
    }

    // مستمع حدث تغيير القائمة المنسدلة للتصنيفات لإضافة مجموعة جديدة فورياً
    itemsContainer.addEventListener("change", function(e) {
        if (e.target.classList.contains("select-category")) {
            const selectEl = e.target;
            if (selectEl.value === "new") {
                const newCatName = prompt("أدخل اسم المجموعة/التصنيف الجديد:");
                if (newCatName && newCatName.trim() !== "") {
                    fetch('../api/add_category.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded'
                        },
                        body: `name=${encodeURIComponent(newCatName.trim())}`
                    })
                    .then(res => res.json())
                    .then(data => {
                        if (data.status === 'success') {
                            // إضافة التصنيف الجديد لكل القوائم المنسدلة في الصفحة
                            document.querySelectorAll('.select-category').forEach(sel => {
                                const opt = new Option(data.name, data.id, false, false);
                                const lastOpt = sel.options[sel.options.length - 1];
                                sel.insertBefore(opt, lastOpt);
                                if (window.jQuery && $(sel).data('select2')) {
                                    $(sel).trigger('change.select2');
                                }
                            });
                            selectEl.value = data.id;
                            if (window.jQuery && $(selectEl).data('select2')) {
                                $(selectEl).trigger('change.select2');
                            }
                        } else {
                            if (typeof AqnexAlert !== 'undefined') {
                                AqnexAlert.show(500, data.message || "حدث خطأ أثناء إضافة المجموعة", selectEl);
                            } else {
                                alert(data.message || "حدث خطأ أثناء إضافة المجموعة");
                            }
                            selectEl.value = "";
                        }
                    })
                    .catch(err => {
                        console.error("Error adding category:", err);
                        selectEl.value = "";
                    });
                } else {
                    selectEl.value = "";
                }
            }
        }
    });

    // مستمع حدث النقر على زر + الخاص بتصميم إضافة مجموعة جديدة فورياً
    itemsContainer.addEventListener("click", function(e) {
        const addCatBtn = e.target.closest(".add-category-btn");
        if (addCatBtn) {
            const container = addCatBtn.closest(".category-search-container");
            const selectEl = container ? container.querySelector(".select-category") : null;
            const newCatName = prompt("أدخل اسم المجموعة/التصنيف الجديد:");
            if (newCatName && newCatName.trim() !== "") {
                fetch('../api/add_category.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `name=${encodeURIComponent(newCatName.trim())}`
                })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        document.querySelectorAll('.select-category').forEach(sel => {
                            const opt = new Option(data.name, data.id, false, false);
                            const lastOpt = sel.options[sel.options.length - 1];
                            if (lastOpt) sel.insertBefore(opt, lastOpt); else sel.add(opt);
                            if (window.jQuery && $(sel).data('select2')) { $(sel).trigger('change.select2'); }
                        });
                        if (selectEl) {
                            selectEl.value = data.id;
                            if (window.jQuery && $(selectEl).data('select2')) { $(selectEl).trigger('change.select2'); }
                        }
                    } else {
                        if (typeof AqnexAlert !== 'undefined') {
                            AqnexAlert.show(500, data.message || "حدث خطأ أثناء إضافة المجموعة", selectEl);
                        } else {
                            alert(data.message || "حدث خطأ أثناء إضافة المجموعة");
                        }
                    }
                })
                .catch(err => console.error("Error adding category:", err));
            }
        }
    });

    // معالجة النقر على عناصر autocomplete
    itemsContainer.addEventListener("click", function(e) {
        const item = e.target.closest(".autocomplete-item");
        if (item) {
            const row = item.closest(".item-row");
            const productId = item.getAttribute("data-id");
            
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
            }
        }
    });

    // معالجة التنقل بالأسهم ⬆ ⬇ داخل قائمة الإكمال التلقائي Autocomplete
    itemsContainer.addEventListener("keydown", function(e) {
        if (e.key === "ArrowDown" || e.key === "ArrowUp") {
            const row = e.target.closest(".item-row");
            if (!row) return;
            const dropdown = row.querySelector(".autocomplete-dropdown:not(.d-none)");
            if (!dropdown) return;

            const items = Array.from(dropdown.querySelectorAll(".autocomplete-item"));
            if (items.length === 0) return;

            e.preventDefault();
            const activeItem = dropdown.querySelector(".autocomplete-item.active");
            let idx = items.indexOf(activeItem);

            if (e.key === "ArrowDown") {
                if (activeItem) activeItem.classList.remove("active");
                idx = (idx + 1) % items.length;
                items[idx].classList.add("active");
                items[idx].scrollIntoView({ block: "nearest" });
            } else if (e.key === "ArrowUp") {
                if (activeItem) activeItem.classList.remove("active");
                idx = (idx - 1 + items.length) % items.length;
                items[idx].classList.add("active");
                items[idx].scrollIntoView({ block: "nearest" });
            }
        }
    });

    // معالجة التنقل الذكي بأزرار Enter بين حقول الصف وخلق صف جديد تلقائياً عند الوصول للحقل الأخير (مطابق لشاشة المبيعات)
    // الترتيب: اسم المنتج -> الباركود -> الكمية -> سعر الشراء -> سعر البيع -> إنشاء صف جديد والتنقل لمنتجه
    itemsContainer.addEventListener("keydown", function(e) {
        if (e.key === "Enter") {
            const row = e.target.closest(".item-row");
            if (!row) return;

            const dropdown = row.querySelector(".autocomplete-dropdown:not(.d-none)");
            if (dropdown) {
                const activeItem = dropdown.querySelector(".autocomplete-item.active, .autocomplete-item");
                if (activeItem && e.target.matches(".product-search-input")) {
                    e.preventDefault();
                    activeItem.click();
                    return;
                }
            }

            if (e.target.classList.contains("product-search-input")) {
                e.preventDefault();
                const barcodeInput = row.querySelector(".barcode-input");
                if (barcodeInput) {
                    barcodeInput.focus();
                    barcodeInput.select();
                }
            } else if (e.target.classList.contains("barcode-input")) {
                e.preventDefault();
                const barcodeVal = e.target.value.trim();
                if (!barcodeVal) {
                    const qtyInput = row.querySelector(".quantity-input");
                    if (qtyInput) { qtyInput.focus(); qtyInput.select(); }
                    return;
                }
                fetch(`../api/search_products.php?q=${encodeURIComponent(barcodeVal)}`)
                    .then(res => res.json())
                    .then(data => {
                        if (data && data.length > 0) {
                            const match = data.find(p => p.barcode && p.barcode.trim() === barcodeVal) || data[0];
                            selectProductForRow(row, match);
                            const qtyInput = row.querySelector(".quantity-input");
                            if (qtyInput) { qtyInput.focus(); qtyInput.select(); }
                        } else {
                            const selectProduct = row.querySelector(".select-product");
                            if (selectProduct) selectProduct.value = "-1";
                            const indicator = row.querySelector(".new-product-indicator");
                            if (indicator) indicator.classList.remove("d-none");
                            
                            const searchInput = row.querySelector(".product-search-input");
                            if (searchInput) {
                                searchInput.focus();
                                searchInput.select();
                            }
                            if (typeof window.showSystemAlert === 'function') {
                                window.showSystemAlert('صنف جديد بالباركود', 'هذا الباركود غير مسجل كصنف سابق. تم اعتباره كصنف جديد، يرجى كتابة اسم الصنف وتحديد المجموعة وسعر البيع.', 'warning', searchInput);
                            }
                        }
                    })
                    .catch(err => {
                        console.error("Barcode inline query error:", err);
                        const qtyInput = row.querySelector(".quantity-input");
                        if (qtyInput) { qtyInput.focus(); qtyInput.select(); }
                    });
            } else if (e.target.classList.contains("quantity-input")) {
                e.preventDefault();
                const priceInput = row.querySelector(".price-input");
                if (priceInput) {
                    priceInput.focus();
                    priceInput.select();
                }
            } else if (e.target.classList.contains("price-input")) {
                e.preventDefault();
                const salePriceInput = row.querySelector(".sale-price-input");
                if (salePriceInput) {
                    salePriceInput.focus();
                    salePriceInput.select();
                }
            } else if (e.target.classList.contains("sale-price-input")) {
                e.preventDefault();
                const nextRow = row.nextElementSibling;
                if (nextRow && nextRow.classList.contains("item-row")) {
                    const nextSearch = nextRow.querySelector(".product-search-input");
                    if (nextSearch) {
                        nextSearch.focus();
                        nextSearch.select();
                    }
                } else {
                    document.getElementById("addItemBtn").click();
                    setTimeout(() => {
                        const rows = itemsContainer.querySelectorAll(".item-row");
                        const lastRow = rows[rows.length - 1];
                        if (lastRow) {
                            const lastSearch = lastRow.querySelector(".product-search-input");
                            if (lastSearch) {
                                lastSearch.focus();
                                lastSearch.select();
                            }
                        }
                    }, 80);
                }
            }
        }
    });

    // إغلاق القوائم عند النقر خارجها
    document.addEventListener("click", function(e) {
        if (!e.target.closest(".product-search-container") && !e.target.closest(".category-search-container")) {
            document.querySelectorAll(".autocomplete-dropdown").forEach(d => d.classList.add("d-none"));
        }
    });


    function escapeHtml(text) {
        if (!text) return '';
        return text.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
    }

    // التحقق قبل الإرسال
    document.getElementById("purchaseForm").addEventListener("submit", function(e) {
        let hasProducts = false;
        document.querySelectorAll(".item-row").forEach(row => {
            const name = row.querySelector(".product-search-input").value.trim();
            const id = row.querySelector(".select-product").value;
            if (name !== "" || id !== "") hasProducts = true;
        });

        if (!hasProducts) {
            if (typeof AqnexAlert !== 'undefined') {
                AqnexAlert.show(102, AQNEX_MESSAGES[102]);
            } else {
                alert("يجب إضافة صنف واحد على الأقل!");
            }
            e.preventDefault();
            return false;
        }

        const invType = document.getElementById("invoiceTypeSelect") ? document.getElementById("invoiceTypeSelect").value : 'cash';
        if (invType === 'cash') {
            let boxBalance = 0;
            const boxSelect = document.getElementById("boxSelect");
            const userBoxId = document.getElementById("userBoxId");
            if (boxSelect && boxSelect.selectedIndex >= 0) {
                boxBalance = parseFloat(boxSelect.options[boxSelect.selectedIndex].getAttribute("data-balance")) || 0;
            } else if (userBoxId) {
                boxBalance = parseFloat(userBoxId.getAttribute("data-balance")) || 0;
            }
            const totalPaid = parseFloat(document.getElementById("totalPaidInput").value) || 0;
            if (totalPaid > boxBalance) {
                if (typeof AqnexAlert !== 'undefined') {
                    AqnexAlert.show(110, AQNEX_MESSAGES[110] + "\nالرصيد المتاح: " + boxBalance.toFixed(2) + " ر.ي\nالمبلغ المطلوب: " + totalPaid.toFixed(2) + " ر.ي");
                } else {
                    alert("رصيد الصندوق غير كافٍ! الرصيد المتاح: " + boxBalance.toFixed(2) + " ر.ي\nالمبلغ المطلوب: " + totalPaid.toFixed(2) + " ر.ي");
                }
                e.preventDefault();
                return false;
            }
        }
    });
});

function openSearchPurchaseModal() {
    const modalEl = document.getElementById('searchPurchaseModal');
    if (modalEl && modalEl.parentNode !== document.body) {
        document.body.appendChild(modalEl);
    }
    if (typeof $ !== 'undefined' && $.fn && $.fn.modal) {
        $('#searchPurchaseModal').modal('show');
    } else if (modalEl) {
        modalEl.classList.add('show');
        modalEl.style.display = 'block';
    }
    setTimeout(() => {
        const inp = document.getElementById('searchPurchaseQuery');
        if (inp) { inp.focus(); inp.select(); }
    }, 150);
}

function confirmDeleteCurrentPurchase() {
    const editingId = parseInt(document.querySelector('[name="editing_invoice_id"]')?.value || '0');
    const urlParams = new URLSearchParams(window.location.search);
    const invoiceId = editingId || parseInt(urlParams.get('id') || '0');

    if (invoiceId <= 0) {
        if (typeof AqnexAlert !== 'undefined') {
            AqnexAlert.show(101, "برجاء اختيار أو فتح فاتورة مشتريات محفوظة أولاً لإمكانية حذفها.");
        } else {
            alert("برجاء اختيار أو فتح فاتورة مشتريات محفوظة أولاً لإمكانية حذفها.");
        }
        return;
    }

    if (typeof AqnexConfirm !== 'undefined') {
        AqnexConfirm.show(`هل أنت متأكد من حذف فاتورة المشتريات رقم #${invoiceId} نهائياً؟ لا يمكن التراجع عن هذا الإجراء وسيتم استرجاع الكميات والمبالغ المخصومة.`, function(confirmed) {
            if (confirmed) {
                window.location.href = 'delete.php?id=' + invoiceId;
            }
        });
    } else {
        if (confirm(`هل أنت متأكد من حذف فاتورة المشتريات رقم #${invoiceId} نهائياً؟`)) {
            window.location.href = 'delete.php?id=' + invoiceId;
        }
    }
}
window.confirmDeleteCurrentPurchase = confirmDeleteCurrentPurchase;

function resetSalesForm() {
    window.location.href = 'create.php';
}
window.resetSalesForm = resetSalesForm;

function selectPastPurchaseInvoice(id) {
    window.location.href = 'create.php?id=' + id;
}

function filterPastPurchasesList() {
    const q = (document.getElementById('searchPurchaseQuery').value || '').toLowerCase();
    const d = document.getElementById('searchPurchaseDate').value;
    selectedPurchaseModalIndex = 0;
    const visibleRows = [];
    document.querySelectorAll('.past-pur-row').forEach(row => {
        const id = row.getAttribute('data-id').toLowerCase();
        const no = row.getAttribute('data-no').toLowerCase();
        const supp = row.getAttribute('data-supp').toLowerCase();
        const date = row.getAttribute('data-date');
        let match = (id.includes(q) || no.includes(q) || supp.includes(q));
        if (d && date !== d) match = false;
        row.style.display = match ? '' : 'none';
        if (match) visibleRows.push(row);
    });
    highlightPastPurchaseRow(visibleRows, 0);
}

let selectedPurchaseModalIndex = 0;

function highlightPastPurchaseRow(rows, index) {
    if (!rows) {
        rows = Array.from(document.querySelectorAll('.past-pur-row')).filter(r => r.style.display !== 'none');
    }
    rows.forEach(r => r.classList.remove('table-success', 'font-weight-bold'));
    if (rows.length > 0) {
        if (index < 0) index = 0;
        if (index >= rows.length) index = rows.length - 1;
        selectedPurchaseModalIndex = index;
        rows[selectedPurchaseModalIndex].classList.add('table-success', 'font-weight-bold');
        rows[selectedPurchaseModalIndex].scrollIntoView({ block: 'nearest' });
    }
}

function deletePastPurchaseInvoice(id, e) {
    if (e) e.stopPropagation();
    if (typeof AqnexConfirm !== 'undefined') {
        AqnexConfirm.show(`هل أنت متأكد من رغبتك في حذف فاتورة المشتريات رقم #${id} نهائياً؟ سيتم إرجاع كميات المخزون وتأثير رصيد المورد والصندوق والقيود المحاسبية.`, function(confirmed) {
            if (confirmed) {
                window.location.href = 'delete.php?id=' + id;
            }
        });
    } else {
        if (confirm(`تأكيد الحذف النهائي:\n\nهل أنت متأكد من رغبتك في حذف فاتورة المشتريات رقم #${id}؟\nسيتم إرجاع كميات المخزون وتأثير رصيد المورد والصندوق والقيود المحاسبية نهائياً، ولن يمكن التراجع عن هذا الإجراء.`)) {
            window.location.href = 'delete.php?id=' + id;
        }
    }
}

document.addEventListener('keydown', function(e) {
    const modalOpen = $('#searchPurchaseModal').is(':visible');
    if (modalOpen) {
        const visibleRows = Array.from(document.querySelectorAll('.past-pur-row')).filter(r => r.style.display !== 'none');
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            highlightPastPurchaseRow(visibleRows, selectedPurchaseModalIndex + 1);
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            highlightPastPurchaseRow(visibleRows, selectedPurchaseModalIndex - 1);
        } else if (e.key === 'Enter') {
            e.preventDefault();
            if (visibleRows[selectedPurchaseModalIndex]) {
                const id = visibleRows[selectedPurchaseModalIndex].getAttribute('data-id');
                if (id) selectPastPurchaseInvoice(id);
            }
        } else if (e.key === 'Delete') {
            e.preventDefault();
            if (visibleRows[selectedPurchaseModalIndex]) {
                const id = visibleRows[selectedPurchaseModalIndex].getAttribute('data-id');
                if (id) deletePastPurchaseInvoice(id);
            }
        }
    } else if (e.key === 'F3' || e.key === 'F6') {
        e.preventDefault();
        openSearchPurchaseModal();
    }
});

// مستمع حدث الباركود المباشر من مسدس الباركود - تمت إزالته لتجنب التكرار حيث تم تعريفه داخل DOMContentLoaded سابقاً.

// مستمع حدث استيراد ملف الإكسل (CSV)
const importExcelBtn = document.getElementById("importExcelBtn");
const excelFileInput = document.getElementById("excelFileInput");
if (importExcelBtn && excelFileInput) {
    importExcelBtn.addEventListener("click", () => {
        excelFileInput.click();
    });

    excelFileInput.addEventListener("change", function(e) {
        const file = e.target.files[0];
        if (!file) return;

        const reader = new FileReader();
        reader.onload = function(evt) {
            const text = evt.target.result;
            parseCSVToInvoiceRows(text);
            excelFileInput.value = ""; // Reset
        };
        reader.readAsText(file, "UTF-8");
    });
}

function generateRandomBarcode() {
    return '690' + Math.floor(1000000000 + Math.random() * 9000000000);
}

function parseCSVToInvoiceRows(csvText) {
    const lines = [];
    let row = [""];
    let inQuotes = false;

    for (let i = 0; i < csvText.length; i++) {
        const char = csvText[i];
        const nextChar = csvText[i+1];

        if (char === '"') {
            if (inQuotes && nextChar === '"') {
                row[row.length - 1] += '"';
                i++;
            } else {
                inQuotes = !inQuotes;
            }
        } else if (char === ',' && !inQuotes) {
            row.push('');
        } else if ((char === '\r' || char === '\n') && !inQuotes) {
            if (char === '\r' && nextChar === '\n') {
                i++;
            }
            lines.push(row);
            row = [''];
        } else {
            row[row.length - 1] += char;
        }
    }
    if (row.length > 1 || row[0] !== '') {
        lines.push(row);
    }

    if (lines.length <= 1) {
        if (typeof AqnexAlert !== 'undefined') {
            AqnexAlert.show(118, "خطأ: الملف المختار فارغ أو لا يحتوي على بنود صالحة!");
        } else {
            alert("خطأ: الملف المختار فارغ أو لا يحتوي على بنود صالحة!");
        }
        return;
    }

    const initialRows = document.querySelectorAll(".item-row");
    if (initialRows.length === 1 && initialRows[0].querySelector(".select-product").value === "") {
        initialRows[0].remove();
    }

    let importedCount = 0;
    const startIndex = (lines[0][0].includes("اسم") || lines[0][0].includes("product") || lines[0][0].includes("barcode")) ? 1 : 0;

    for (let i = startIndex; i < lines.length; i++) {
        const line = lines[i];
        if (line.length < 2 || !line[0].trim()) continue;

        // الأعمدة: اسم الصنف، المجموعة، الكمية، سعر الشراء، سعر البيع، الباركود
        const prodName = line[0].trim();
        const catName = line[1] ? line[1].trim() : "";
        const qty = parseFloat(line[2]) || 1;
        const buyPrice = parseFloat(line[3]) || 0;
        const salePrice = parseFloat(line[4]) || 0;
        const barcode = line[5] ? line[5].trim() : "";

        let matchedCatId = "";
        if (catName && typeof categoriesList !== 'undefined') {
            const foundCat = categoriesList.find(c => c.name.toLowerCase() === catName.toLowerCase());
            if (foundCat) matchedCatId = foundCat.catid;
        }

        document.getElementById("addItemBtn").click();
        const newRows = document.querySelectorAll(".item-row");
        const targetRow = newRows[newRows.length - 1];

        targetRow.querySelector(".product-search-input").value = prodName;
        targetRow.querySelector(".select-product-name").value = prodName;
        targetRow.querySelector(".select-product").value = "-1"; // صنف جديد
        targetRow.querySelector(".new-product-indicator").classList.remove("d-none");
        targetRow.querySelector(".quantity-input").value = qty;
        targetRow.querySelector(".price-input").value = buyPrice;
        targetRow.querySelector(".sale-price-input").value = salePrice;
        
        targetRow.querySelector(".barcode-input").value = barcode || generateRandomBarcode();

        if (matchedCatId) {
            const catSel = targetRow.querySelector(".select-category");
            catSel.value = matchedCatId;
            if (window.jQuery && $(catSel).data('select2')) {
                $(catSel).trigger('change.select2');
            }
        }

        updateRowCalculations(targetRow);
        importedCount++;
    }

    if (importedCount > 0) {
        updateGrandTotals();
        if (typeof AqnexAlert !== 'undefined') {
            AqnexAlert.show(117, `تم استيراد ${importedCount} صنف بنجاح!`);
        } else {
            alert(`تم استيراد ${importedCount} صنف بنجاح!`);
        }
    } else {
        if (typeof AqnexAlert !== 'undefined') {
            AqnexAlert.show(118, "لم يتم استيراد أي بنود. يرجى التحقق من الملف.");
        } else {
            alert("لم يتم استيراد أي بنود. يرجى التحقق من الملف.");
        }
    }
}

// فتح مودل القيود المحاسبية للمشتريات
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

    // Expected Journal Entries for Purchases
    const netTotal = parseFloat(document.getElementById('grandTotalInput').value) || 0;
    const paidAmount = parseFloat(document.getElementById('totalPaidInput').value) || 0;
    const invoiceType = document.getElementById('invoiceTypeSelect').value;
    const supplierName = document.getElementById('supplierSelect2').value || 'المورد';

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

    // 1. حساب المدين (المخازن)
    rowsHtml += `<tr>
        <td><code>120101</code></td>
        <td class="font-weight-bold text-right">حساب المخزون / البضاعة</td>
        <td class="text-success font-weight-bold">${netTotal.toFixed(2)}</td>
        <td>0.00</td>
        <td class="text-right">إثبات مشتريات بضاعة للفاتورة</td>
    </tr>`;
    totalDebit += netTotal;

    // 2. حساب الدائن (الصندوق و/أو المورد)
    if (invoiceType === 'cash') {
        rowsHtml += `<tr>
            <td><code>110101</code></td>
            <td class="font-weight-bold text-right">حساب الخزينة / الصندوق الرئيسي</td>
            <td>0.00</td>
            <td class="text-danger font-weight-bold">${paidAmount.toFixed(2)}</td>
            <td class="text-right">دفع نقدي قيمة مشتريات</td>
        </tr>`;
        totalCredit += paidAmount;

        if (netTotal - paidAmount > 0.01) {
            const rem = netTotal - paidAmount;
            rowsHtml += `<tr>
                <td><code>210101</code></td>
                <td class="font-weight-bold text-right">الذمم الدائنة - ${supplierName}</td>
                <td>0.00</td>
                <td class="text-danger font-weight-bold">${rem.toFixed(2)}</td>
                <td class="text-right">إثبات الجزء المتبقي كدين للمورد</td>
            </tr>`;
            totalCredit += rem;
        }
    } else {
        rowsHtml += `<tr>
            <td><code>210101</code></td>
            <td class="font-weight-bold text-right">الذمم الدائنة - ${supplierName}</td>
            <td>0.00</td>
            <td class="text-danger font-weight-bold">${netTotal.toFixed(2)}</td>
            <td class="text-right">إثبات قيمة المشتريات الآجلة كدين للمورد</td>
        </tr>`;
        totalCredit += netTotal;
    }

    tbody.innerHTML = rowsHtml;
    document.getElementById('journalTotalDebit').innerText = totalDebit.toFixed(2);
    document.getElementById('journalTotalCredit').innerText = totalCredit.toFixed(2);
    $('#viewJournalModal').modal('show');
}
</script>

<!-- مودل البحث عن فواتير المشتريات السابقة -->
<div class="modal fade" id="searchPurchaseModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
        <div class="modal-content rounded-0">
            <div class="modal-header bg-success text-white py-2">
                <h6 class="modal-title font-weight-bold"><i class="bi bi-search ml-1"></i> البحث عن فاتورة مشتريات سابقة للتعديل / الإنزال</h6>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body p-3">
                <div class="row mb-3">
                    <div class="col-md-7">
                        <input type="text" id="searchPurchaseQuery" class="form-control rounded-0" placeholder="ابحث برقم الفاتورة أو اسم المورد... (استخدم الأسهم و Enter للإنزال السريع)" oninput="filterPastPurchasesList()">
                    </div>
                    <div class="col-md-5">
                        <input type="date" id="searchPurchaseDate" class="form-control rounded-0" onchange="filterPastPurchasesList()">
                    </div>
                </div>
                <div class="table-responsive" style="max-height: 350px; overflow-y: auto;">
                    <table class="table table-sm table-bordered table-hover text-center mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th># رقم الفاتورة</th>
                                <th>التاريخ</th>
                                <th>اسم المورد</th>
                                <th>الإجمالي</th>
                                <th>طريقة الدفع</th>
                                <th>إجراء</th>
                            </tr>
                        </thead>
                        <tbody id="pastPurchasesTableBody">
                            <?php
                            $res_past_p = $conn->query("
                                SELECT id, invoice_no, invoice_date, supp_name, net_amount AS total_amount, invoice_type 
                                FROM purchase_invoices_mst WHERE d_s = 0 
                                UNION ALL 
                                SELECT id, id AS invoice_no, date AS invoice_date, supp_name, total AS total_amount, invoice_type 
                                FROM purchases WHERE id NOT IN (SELECT id FROM purchase_invoices_mst WHERE d_s = 0)
                                ORDER BY id DESC LIMIT 50
                            ");
                            if ($res_past_p && $res_past_p->num_rows > 0) {
                                $first = true;
                                while($p_row = $res_past_p->fetch_assoc()) {
                                    $inv_type_lbl = ($p_row['invoice_type'] === 'cash') ? 'نقداً' : 'آجل';
                                    $cls = $first ? 'past-pur-row table-success font-weight-bold' : 'past-pur-row';
                                    $first = false;
                                    echo "<tr class='$cls' data-id='{$p_row['id']}' data-no='" . htmlspecialchars($p_row['invoice_no']) . "' data-supp='" . htmlspecialchars($p_row['supp_name']) . "' data-date='{$p_row['invoice_date']}' style='cursor:pointer;' onclick='selectPastPurchaseInvoice({$p_row['id']})'>
                                        <td>#{$p_row['id']} ({$p_row['invoice_no']})</td>
                                        <td>{$p_row['invoice_date']}</td>
                                        <td>" . htmlspecialchars($p_row['supp_name']) . "</td>
                                        <td class='font-weight-bold text-success'>" . number_format($p_row['total_amount'], 2) . "</td>
                                        <td><span class='badge badge-info'>{$inv_type_lbl}</span></td>
                                        <td>
                                            <button type='button' class='tool-btn btn-xs btn-primary px-2' onclick='selectPastPurchaseInvoice({$p_row['id']})'><i class='bi bi-arrow-down-square-fill mr-1'></i></button>
                                            <button type='button' class='tool-btn btn-xs btn-outline-danger px-2 ml-1' onclick='deletePastPurchaseInvoice({$p_row['id']}, event)' title='حذف الفاتورة نهائياً'><i class='bi bi-trash-fill'></i></button>
                                        </td>
                                    </tr>";
                                }
                            } else {
                                echo "<tr><td colspan='6' class='text-muted py-3'>لا توجد فواتير مشتريات مسجلة سابقة.</td></tr>";
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer py-2 justify-content-between">
                <small class="text-muted"><i class="bi bi-keyboard ml-1"></i> نصيحة: التنقل بالأسهم ⬆ ⬇ والضغط على Enter للإنزال السريع أو Delete للحذف</small>
                <button type="button" class="btn btn-secondary btn-sm rounded-0" data-dismiss="modal">إغلاق</button>
            </div>
        </div>
    </div>
</div>

<?php require_once($dir_prefix . 'includes/footer.php'); ?>

<script type="text/javascript">
function confirmDeleteCurrentPurchase() {
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
        const msgBody = 'هل أنت متأكد من رغبتك في حذف فاتورة المشتريات رقم #' + invId + ' نهائياً؟\nسيتم خضم الكميات المضافة من المخزن، تعديل رصيد المورد والصندوق، وإلغاء القيود المحاسبية. لا يمكن التراجع.';
        if (typeof AqnexConfirm !== 'undefined') {
            AqnexConfirm.show(msgTitle, msgBody, function(confirmed) {
                if (confirmed) {
                    window.location.href = 'delete.php?id=' + invId;
                }
            });
        } else {
            if (confirm(msgBody)) window.location.href = 'delete.php?id=' + invId;
        }
    } else {
        if (typeof AqnexAlert !== 'undefined') {
            AqnexAlert.show(101, "برجاء اختيار أو فتح فاتورة مشتريات محفوظة من قائمة البحث أولاً لإمكانية حذفها.");
        } else {
            alert("برجاء اختيار أو فتح فاتورة مشتريات محفوظة من قائمة البحث أولاً لإمكانية حذفها.");
        }
    }
}
window.confirmDeleteCurrentPurchase = confirmDeleteCurrentPurchase;

// عرض مودال القيود المحاسبية لفاتورة المشتريات
function openJournalModal() {
    const tbody = document.getElementById('purchJournalTableBody');
    if (!tbody) return;
    tbody.innerHTML = '';
    const entries = window.actualJournalEntries || [];
    if (entries.length === 0) {
        tbody.innerHTML = '<tr><td colspan="5" class="text-muted text-center py-3">لا توجد قيود محاسبية لهذه الفاتورة. احفظ الفاتورة أولاً.</td></tr>';
    } else {
        let totalDebit = 0, totalCredit = 0, html = '';
        entries.forEach(e => {
            const deb = parseFloat(e.debit) || 0;
            const cred = parseFloat(e.credit) || 0;
            totalDebit += deb; totalCredit += cred;
            html += `<tr>
                <td><code>${e.account_code || '---'}</code></td>
                <td class="font-weight-bold text-right">${e.account_name || ''}</td>
                <td class="text-success font-weight-bold">${deb > 0 ? deb.toFixed(2) : '-'}</td>
                <td class="text-danger font-weight-bold">${cred > 0 ? cred.toFixed(2) : '-'}</td>
                <td class="text-right text-muted" style="font-size:0.85em;">${e.narration || ''}</td>
            </tr>`;
        });
        html += `<tr class="table-info font-weight-bold">
            <td colspan="2">الإجمالي</td>
            <td>${totalDebit.toFixed(2)}</td>
            <td>${totalCredit.toFixed(2)}</td>
            <td>${Math.abs(totalDebit - totalCredit) < 0.01 ? '✓ ميزان محقق' : '⚠ ميزان غير محقق'}</td>
        </tr>`;
        tbody.innerHTML = html;
        document.getElementById('purchJournalTotalDebit').innerText = totalDebit.toFixed(2);
        document.getElementById('purchJournalTotalCredit').innerText = totalCredit.toFixed(2);
    }
    if (typeof $ !== 'undefined') $('#purchasesJournalModal').modal('show');
}
</script>

<!-- مودال القيود المحاسبية لفاتورة المشتريات -->
<div class="modal fade" id="purchasesJournalModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered" role="document">
        <div class="modal-content rounded-0">
            <div class="modal-header bg-warning py-2">
                <h6 class="modal-title font-weight-bold">
                    <i class="bi bi-journal-bookmark-fill ml-1"></i>
                    القيود المحاسبية - فاتورة مشتريات
                    <?php if ($editing_invoice_id > 0): ?>
                    <span class="badge badge-light mr-2">#<?php echo $editing_invoice_id; ?></span>
                    <?php endif; ?>
                </h6>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body p-3">
                <div class="table-responsive">
                    <table class="table table-sm table-bordered text-center">
                        <thead class="bg-light">
                            <tr>
                                <th style="width:10%">كود الحساب</th>
                                <th style="width:35%">الحساب</th>
                                <th style="width:15%">مدين</th>
                                <th style="width:15%">دائن</th>
                                <th style="width:25%">البيان</th>
                            </tr>
                        </thead>
                        <tbody id="purchJournalTableBody"></tbody>
                        <tfoot>
                            <tr class="bg-light font-weight-bold">
                                <td colspan="2">المجموع</td>
                                <td id="purchJournalTotalDebit" class="text-success">0.00</td>
                                <td id="purchJournalTotalCredit" class="text-danger">0.00</td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-secondary btn-sm rounded-0" data-dismiss="modal">إغلاق</button>
            </div>
        </div>
    </div>
</div>
