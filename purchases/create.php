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

    $selected_box_id = isset($_POST['box_id']) ? intval($_POST['box_id']) : get_user_box_id($conn, $active_user_id);
    $active_box_id = $selected_box_id;
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

    $total_remaining_base = round(max(0, $grand_total_base - $total_paid_base), 4);

    if ($pay_from_box && $total_paid_base > 0) {
        $box_balance = get_box_balance($conn, $selected_box_id);
        if ($box_balance < $total_paid_base) {
            $save_error = "لا يمكن إتمام العملية لأن رصيد الصندوق المحدد (" . number_format($box_balance, 2) . ") أقل من المبلغ المدفوع (" . number_format($total_paid_base, 2) . ").";
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
            echo "<script>window.location='view.php?id=$billing_id';</script>";
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
.autocomplete-dropdown { position: absolute; top: 100%; right: 0; width: 100%; background: #fff; border: 1px solid #e2e8f0; border-top: none; max-height: 250px; overflow-y: auto; z-index: 1050; box-shadow: 0 4px 8px rgba(0,0,0,0.15); }
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
        <button type="button" class="tool-btn btn-delete" title="حذف وتصفية الفاتورة الحالية" onclick="if(confirm('هل أنت تأكد من رغبتك في حذف وتصفية الفاتورة؟')) resetSalesForm();">
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
            <div class="alert alert-danger rounded-0 mb-4" style="border-right: 4px solid #b91c1c;">
                <strong>خطأ في حفظ الفاتورة:</strong> <?php echo htmlspecialchars($save_error); ?>
            </div>
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
                    <div class="col-md-4">
                        <div class="aqnex-form-group mb-2">
                            <label class="aqnex-label">رقم الصندوق:</label>
                            <?php if ($is_admin): ?>
                                <?php $default_box_id = $editing_invoice ? intval($editing_invoice['box_id']) : get_user_box_id($conn, $active_user_id); ?>
                                <select name="box_id" id="boxSelect" class="aqnex-select" required onchange="updateBoxBalanceDisplay()">
                                    <option value=""></option>
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
                                <select name="supplier_name" id="supplierSelect2" class="aqnex-select" required>
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
                                <option value="cash">نقداً</option>
                                <option value="wallet">محفظة إلكترونية / بنك</option>
                            </select>
                        </div>
                        <div class="aqnex-form-group mb-2 d-none" id="walletTypeSection">
                            <label class="aqnex-label">نوع المحفظة / البنك:</label>
                            <select id="walletTypeSelect" class="aqnex-select">
                                <option value="">-- اختر --</option>
                                <option value="بنك الكريمي">بنك الكريمي</option>
                                <option value="جيب">جيب</option>
                                <option value="فلوسك">فلوسك</option>
                                <option value="جوالي">جوالي</option>
                                <option value="بنك آخر">بنك آخر</option>
                            </select>
                            <input type="hidden" name="wallet_type" id="walletTypeHidden" value="">
                        </div>
                    </div>
                </div>
            </div>


            <!-- شريط البحث -->
            <div class="card p-3 bg-light border-0 mb-4 no-print">
                <div class="row align-items-center">
                    <div class="col-md-6 mb-2 mb-md-0">
                        <div class="input-group">
                            <div class="input-group-prepend">
                                <span class="input-group-text bg-primary text-white border-0"><i class="bi bi-upc-scan"></i></span>
                            </div>
                            <input type="text" id="barcodeScanInput" class="form-control rounded-0 border-primary font-weight-bold text-center" placeholder="امسح باركود المنتج..." autofocus autocomplete="off">
                        </div>
                    </div>
                    <div class="col-md-6 text-md-left">
                        <button type="button" id="quickProductSearchBtn" class="btn btn-outline-primary rounded-0 px-4 font-weight-bold">
                            <i class="bi bi-search ml-1"></i> F2 - البحث السريع
                        </button>
                    </div>
                </div>
            </div>

            <!-- Datalists للإكمال التلقائي الفوري للأصناف والباركود والتصنيفات -->
            <datalist id="globalProductsDatalist">
                <?php
                $res_p_dl = $conn->query("SELECT id, name, barcode, buy_price, sale_price, catid FROM products WHERE delete_status = 0 ORDER BY name ASC");
                $p_dl_list = [];
                if ($res_p_dl) {
                    while ($p_dl = $res_p_dl->fetch_assoc()) {
                        $p_dl_list[] = $p_dl;
                        echo "<option value='" . htmlspecialchars($p_dl['name']) . "'>";
                    }
                }
                ?>
            </datalist>

            <datalist id="globalBarcodesDatalist">
                <?php
                foreach ($p_dl_list as $p_dl) {
                    if (!empty($p_dl['barcode'])) {
                        echo "<option value='" . htmlspecialchars($p_dl['barcode']) . "'>";
                    }
                }
                ?>
            </datalist>

            <datalist id="globalCategoriesDatalist">
                <?php
                foreach ($categories_list as $c_dl) {
                    echo "<option value='" . htmlspecialchars($c_dl['name']) . "'>";
                }
                ?>
            </datalist>

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
                        <?php if ($editing_invoice_id > 0 && !empty($editing_items)): ?>
                            <?php foreach ($editing_items as $ei): ?>
                            <?php
                                $ei_p_id = intval($ei['product_id'] ?? 0);
                                $ei_p_name = htmlspecialchars($ei['product_name'] ?? '');
                                $ei_unit = htmlspecialchars($ei['unit_name'] ?? 'حبة');
                                $ei_barcode = htmlspecialchars($ei['barcode'] ?? '');
                                $ei_qty = floatval($ei['quantity'] ?? 1);
                                $ei_cost = floatval($ei['unit_cost'] ?? 0);
                                $ei_total = floatval($ei['total_cost'] ?? ($ei_qty * $ei_cost));
                                
                                $ei_sale_price = 0;
                                $ei_cat_id = 0;
                                $ei_cat_name = '';
                                if ($ei_p_id > 0) {
                                    $p_res = $conn->query("SELECT sale_price, catid FROM products WHERE id = $ei_p_id LIMIT 1");
                                    if ($p_res && $p_row = $p_res->fetch_assoc()) {
                                        $ei_sale_price = floatval($p_row['sale_price']);
                                        $ei_cat_id = intval($p_row['catid']);
                                        if ($ei_cat_id > 0) {
                                            $c_res = $conn->query("SELECT name FROM categories WHERE catid = $ei_cat_id LIMIT 1");
                                            if ($c_res && $c_row = $c_res->fetch_assoc()) {
                                                $ei_cat_name = htmlspecialchars($c_row['name']);
                                            }
                                        }
                                    }
                                }
                                $ei_profit = max(0, ($ei_sale_price - $ei_cost) * $ei_qty);
                            ?>
                            <tr class="item-row">
                                <td>
                                    <div class="product-search-container">
                                        <input type="text" class="form-control product-search-input rounded-0" placeholder="ابحث أو اكتب اسم منتج..." autocomplete="off" value="<?php echo $ei_p_name; ?>">
                                        <input type="hidden" name="product_name[]" class="select-product-name" value="<?php echo $ei_p_name; ?>">
                                        <input type="hidden" name="product_id[]" class="select-product" value="<?php echo $ei_p_id; ?>">
                                        <div class="autocomplete-dropdown d-none"></div>
                                    </div>
                                    <input type="hidden" name="conversion_factor[]" class="conversion-factor" value="1.0000">
                                    <input type="hidden" name="unit_name[]" class="unit-name" value="<?php echo $ei_unit; ?>">
                                </td>
                                <td>
                                    <input type="text" name="unit_display[]" class="form-control unit-display-input text-center bg-light rounded-0" readonly value="<?php echo $ei_unit; ?>">
                                </td>
                                <td>
                                    <div class="barcode-search-container">
                                        <div class="input-group">
                                            <input type="text" name="barcode[]" class="form-control barcode-input text-center rounded-0" placeholder="باركود" autocomplete="off" value="<?php echo $ei_barcode; ?>">
                                            <div class="input-group-append">
                                                <button type="button" class="btn btn-sm btn-outline-secondary generate-barcode-btn" title="توليد باركود"><i class="bi bi-upc-scan"></i></button>
                                            </div>
                                        </div>
                                        <div class="autocomplete-dropdown d-none"></div>
                                    </div>
                                </td>
                                <td>
                                    <div class="category-search-container">
                                        <input type="text" name="category_name[]" class="form-control category-input rounded-0" placeholder="اختر التصنيف..." autocomplete="off" value="<?php echo $ei_cat_name; ?>">
                                        <input type="hidden" name="category_id[]" class="select-category" value="<?php echo $ei_cat_id; ?>">
                                        <div class="autocomplete-dropdown d-none"></div>
                                    </div>
                                </td>
                                <td><input type="number" name="quantity[]" class="form-control quantity-input text-center rounded-0" min="1" value="<?php echo $ei_qty; ?>"></td>
                                <td><input type="number" step="any" name="unit_price[]" class="form-control price-input text-center rounded-0" value="<?php echo $ei_cost; ?>"></td>
                                <td><input type="number" step="any" name="sale_price[]" class="form-control sale-price-input text-center rounded-0" value="<?php echo $ei_sale_price; ?>"></td>
                                <td><input type="text" class="form-control total-input text-center bg-light rounded-0" readonly value="<?php echo number_format($ei_total, 2, '.', ''); ?>"></td>
                                <td><input type="text" class="form-control profit-input text-center bg-light rounded-0" readonly value="<?php echo number_format($ei_profit, 2, '.', ''); ?>"></td>
                                <td class="no-print">
                                    <button type="button" class="btn-flat btn-flat-danger btn-sm py-1 px-2 remove-item-btn"><?php echo get_icon('trash'); ?></button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                        <tr class="item-row">
                            <td>
                                <div class="product-search-container">
                                    <input type="text" class="form-control product-search-input rounded-0" list="globalProductsDatalist" placeholder="ابحث أو اكتب اسم منتج..." autocomplete="off">
                                    <input type="hidden" name="product_name[]" class="select-product-name" value="">
                                    <input type="hidden" name="product_id[]" class="select-product" value="">
                                    <div class="autocomplete-dropdown d-none"></div>
                                </div>
                                <input type="hidden" name="conversion_factor[]" class="conversion-factor" value="1.0000">
                                <input type="hidden" name="unit_name[]" class="unit-name" value="حبة">
                                <small class="text-muted new-product-indicator d-none">
                                    <span class="new-product-badge">منتج جديد</span> سيتم إنشاؤه عند الحفظ
                                </small>
                            </td>
                            <td>
                                <input type="text" name="unit_display[]" class="form-control unit-display-input text-center bg-light rounded-0" readonly value="حبة">
                            </td>
                            <td>
                                <div class="barcode-search-container">
                                    <div class="input-group">
                                        <input type="text" name="barcode[]" class="form-control barcode-input text-center rounded-0" list="globalBarcodesDatalist" placeholder="باركود" autocomplete="off">
                                        <div class="input-group-append">
                                            <button type="button" class="btn btn-sm btn-outline-secondary generate-barcode-btn" title="توليد باركود"><i class="bi bi-upc-scan"></i></button>
                                        </div>
                                    </div>
                                    <div class="autocomplete-dropdown d-none"></div>
                                </div>
                            </td>
                            <td>
                                <div class="category-search-container">
                                    <input type="text" name="category_name[]" class="form-control category-input rounded-0" list="globalCategoriesDatalist" placeholder="اختر التصنيف..." autocomplete="off">
                                    <input type="hidden" name="category_id[]" class="select-category" value="">
                                    <div class="autocomplete-dropdown d-none"></div>
                                </div>
                            </td>
                            <td><input type="number" name="quantity[]" class="form-control quantity-input text-center rounded-0" min="1" value="1"></td>
                            <td><input type="number" step="any" name="unit_price[]" class="form-control price-input text-center rounded-0"></td>
                            <td><input type="number" step="any" name="sale_price[]" class="form-control sale-price-input text-center rounded-0"></td>
                            <td><input type="text" class="form-control total-input text-center bg-light rounded-0" readonly value="0"></td>
                            <td><input type="text" class="form-control profit-input text-center bg-light rounded-0" readonly value="0"></td>
                            <td class="no-print text-center">
                                <button type="button" class="btn btn-sm btn-outline-danger rounded-circle p-1 remove-item-btn" style="width:28px; height:28px; line-height:1;" title="حذف هذا الصنف من الفاتورة">
                                    <i class="bi bi-x-lg"></i>
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

            <!-- Onyx Summary Box & Remark Section (مطابق لشاشة المبيعات بعرض كامل واحترافي) -->
            <div class="row mt-3">
                <div class="col-md-7">
                    <div class="aqnex-form-group">
                        <label class="aqnex-label font-weight-bold text-secondary">ملاحظات الفاتورة والمستند:</label>
                        <textarea name="remark" class="aqnex-input" rows="4" placeholder="ملاحظات حول عملية الشراء وتفاصيل المستند..."><?php echo $editing_invoice ? htmlspecialchars($editing_invoice['remark'] ?? '') : ''; ?></textarea>
                    </div>
                    <div class="mt-3 no-print text-right">
                        <button type="submit" name="btn_save" id="btnSavePurchase" class="btn btn-primary font-weight-bold px-4 py-2" style="font-size:1rem; border-radius:4px;">
                            <?php echo get_icon('check', 'ml-1'); ?> حفظ الفاتورة وترحيلها المحاسبي (F10)
                        </button>
                    </div>
                </div>

                <div class="col-md-5">
                    <div class="aqnex-summary-box">
                        <div class="aqnex-summary-item">
                            <span class="label">إجمالي الفاتورة:</span>
                            <span class="value"><input type="text" id="grandTotalDisplay" name="grand_total" class="border-0 bg-transparent text-left font-weight-bold p-0" style="width:120px; outline:none;" readonly value="<?php echo $editing_invoice ? floatval($editing_invoice['total_amount']) : '0'; ?>"> <small class="currency-symbol">ر.ي</small></span>
                        </div>
                        <div class="aqnex-summary-item" style="background:#f0fdf4; border-color:#86efac;">
                            <span class="label font-weight-bold" style="color:#15803d;">المبلغ المدفوع (نقداً):</span>
                            <span class="value text-success"><input type="number" step="any" min="0" id="totalPaidInput" name="total_paid_invoice" class="border-0 bg-transparent text-left font-weight-bold text-success p-0" style="width:120px; outline:none;" value="<?php echo $editing_invoice ? floatval($editing_invoice['paid_amount']) : '0'; ?>"> <small class="currency-symbol">ر.ي</small></span>
                        </div>
                        <div class="aqnex-summary-item" style="background:#fef2f2; border-color:#fca5a5;">
                            <span class="label font-weight-bold" style="color:#b91c1c;">الرصيد المتبقي (آجل):</span>
                            <span class="value text-danger"><span id="totalRemainingDisplay">0.00</span> <small class="currency-symbol">ر.ي</small></span>
                        </div>
                    </div>
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

            <!-- Onyx Pro User Audit Bar -->
            <div class="aqnex-audit-bar no-print mt-4 d-flex justify-content-between p-2 rounded" style="background:#f8fafc; border:1px solid #e2e8f0; font-size:0.83rem; color:#64748b;">
                <div>
                    <i class="bi bi-person-fill ml-1 text-primary"></i> مدخل الفاتورة: <strong><?php echo htmlspecialchars($_SESSION['SESS_FIRST_NAME'] ?? 'مدير النظام'); ?></strong>
                </div>
                <div>
                    <i class="bi bi-clock-history ml-1 text-info"></i> التاريخ والوقت: <strong><?php echo date('Y-m-d H:i'); ?></strong>
                </div>
                <div>
                    <i class="bi bi-pc-display ml-1 text-success"></i> رقم الجهاز: <strong><?php echo gethostbyaddr($_SERVER['REMOTE_ADDR']); ?></strong>
                </div>
            </div>

        </form>
    </div>
</div>

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

<script type="text/javascript">
const categoriesList = <?php echo json_encode($categories_list, JSON_UNESCAPED_UNICODE); ?>;
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
    function fetchSupplierDetails(name) {
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
    }

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

    // دالة إخفاء/إظهار طريقة المحفظة بناءً على نوع الفاتورة
    window.togglePurchaseInvoiceType = function(val) {
        const paymentSection = document.getElementById('paymentMethodSection');
        const walletSection = document.getElementById('walletTypeSection');
        const totalPaidInput = document.getElementById('totalPaidInput');
        const paidRow = totalPaidInput ? totalPaidInput.closest('.aqnex-summary-item') : null;

        if (val === 'credit') {
            // فاتورة آجل: إخفاء المحفظة والمبلغ المدفوع
            if (paymentSection) paymentSection.style.display = 'none';
            if (walletSection) walletSection.classList.add('d-none');
            if (totalPaidInput) { totalPaidInput.value = '0'; totalPaidInput.readOnly = true; totalPaidInput.style.opacity = '0.5'; }
            if (paidRow) paidRow.style.opacity = '0.4';
        } else {
            // نقداً أو من حساب
            if (paymentSection) paymentSection.style.display = '';
            if (totalPaidInput) { totalPaidInput.readOnly = false; totalPaidInput.style.opacity = '1'; }
            if (paidRow) paidRow.style.opacity = '1';
        }
        updateGrandTotals();
    };

    // دالة إظهار/إخفاء نوع المحفظة
    window.toggleWalletSection = function(val) {
        const sec = document.getElementById('walletTypeSection');
        if (!sec) return;
        if (val === 'wallet') {
            sec.classList.remove('d-none');
        } else {
            sec.classList.add('d-none');
        }
    };

    // تطبيق الحالة الأولية بناءً على نوع الفاتورة الحالي
    const invoiceTypeSel = document.getElementById('invoiceTypeSelect');
    if (invoiceTypeSel) {
        window.togglePurchaseInvoiceType(invoiceTypeSel.value);
    }


    // 2. معالجة إضافة مورد جديد
    const quickForm = document.getElementById("quickAddSupplierForm");
    if (quickForm) {
        quickForm.addEventListener("submit", function(e) {
            e.preventDefault();
            document.getElementById("quickAddSupplierError").classList.add("d-none");
            const formData = new FormData(quickForm);

            fetch(window.location.href, { method: "POST", body: formData })
            .then(response => response.json())
            .then(data => {
                if (data.status === "success") {
                    if (supplierSelect) {
                        const opt = document.createElement("option");
                        opt.value = data.id;
                        opt.text = data.name;
                        opt.selected = true;
                        supplierSelect.add(opt);
                        supplierSelect.dispatchEvent(new Event('change'));
                    }
                    $('#quickAddSupplierModal').modal('hide');
                    quickForm.reset();
                } else {
                    const errDiv = document.getElementById("quickAddSupplierError");
                    errDiv.innerText = data.message;
                    errDiv.classList.remove("d-none");
                }
            });
        });
    }

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
        const qty = parseFloat(row.querySelector(".quantity-input").value) || 0;
        const buyPrice = parseFloat(row.querySelector(".price-input").value) || 0;
        const salePrice = parseFloat(row.querySelector(".sale-price-input").value) || 0;

        const total = qty * buyPrice;
        row.querySelector(".total-input").value = total.toFixed(2);

        if (salePrice > 0) {
            row.querySelector(".profit-input").value = ((salePrice - buyPrice) * qty).toFixed(2);
        } else {
            row.querySelector(".profit-input").value = ((buyPrice * 0.25) * qty).toFixed(2);
        }
        updateGrandTotals();
        updateBoxBalanceDisplay();
    }

    function updateGrandTotals() {
        let totalVal = 0;
        document.querySelectorAll(".item-row").forEach(function(row) {
            const name = row.querySelector(".product-search-input").value.trim();
            const id = row.querySelector(".select-product").value;
            if (name !== "" || id !== "") {
                totalVal += parseFloat(row.querySelector(".total-input").value) || 0;
            }
        });

        document.getElementById("grandTotalDisplay").value = totalVal.toFixed(2);
        const paid = parseFloat(document.getElementById("totalPaidInput").value) || 0;
        document.getElementById("totalRemainingDisplay").textContent = Math.max(0, totalVal - paid).toFixed(2);
        updateAccountingGuide();
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

    document.getElementById("totalPaidInput").addEventListener("input", function() {
        updateGrandTotals();
        updateBoxBalanceDisplay();
    });

    // إضافة صف جديد
    document.getElementById("addItemBtn").addEventListener("click", function() {
        const newRow = rowTemplate.cloneNode(true);
        newRow.querySelectorAll("input").forEach(input => {
            if(input.type !== 'hidden' || input.classList.contains('quantity-input')) {
                input.value = input.classList.contains('quantity-input') ? "1" : "";
            }
        });
        // التأكد من أن الوحدة الافتراضية هي "حبة"
        newRow.querySelector(".unit-name").value = "حبة";
        newRow.querySelector(".unit-display-input").value = "حبة";
        
        newRow.querySelector(".total-input").value = "0";
        newRow.querySelector(".profit-input").value = "0";
        newRow.querySelector(".new-product-indicator").classList.add("d-none");
        newRow.querySelectorAll(".autocomplete-dropdown").forEach(d => { d.classList.add("d-none"); d.innerHTML = ""; });
        itemsContainer.appendChild(newRow);
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

    // التعامل مع المدخلات (بحث، كميات، أسعار)
    itemsContainer.addEventListener("input", function(e) {
        if (e.target.matches(".quantity-input, .price-input, .sale-price-input")) {
            updateRowCalculations(e.target.closest(".item-row"));
        }
        
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

    // عرض قائمة المنتجات عند البحث
    function showProductAutocompleteDropdown(input) {
        const row = input.closest(".item-row");
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

            // تأكد من وجود هذا الملف في مسار api/search_products.php
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
                })
                .catch(err => {
                    console.error('Inline search error:', err);
                    dropdown.innerHTML = `<div class="autocomplete-item create-new" data-id="-1" data-new-name="${escapeHtml(query)}">
                        <i class="bi bi-plus-circle ml-1"></i> إنشاء منتج جديد: <strong>${escapeHtml(query)}</strong>
                    </div>`;
                });
        }, 200);
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
        
        // إذا كان المنتج يحتوي على وحدة، نستخدمها، وإلا نستخدم "حبة"
        const unit = product.unit_name || "حبة";
        row.querySelector(".unit-name").value = unit;
        row.querySelector(".unit-display-input").value = unit;

        if (product.barcode) barcodeInput.value = product.barcode;

        if (product.catid) {
            categoryHidden.value = product.catid;
            const cat = categoriesList.find(c => c.catid == product.catid);
            if (cat) categoryInput.value = cat.name;
        }

        const rate = parseFloat(exchangeRateInput.value) || 1.0;
        const buyPriceConverted = (product.buy_price / rate).toFixed(2);

        row.querySelector(".price-input").value = buyPriceConverted;
        row.querySelector(".quantity-input").value = 1;

        const suggestedSalePrice = (product.buy_price * 1.25 / rate).toFixed(2);
        salePriceInput.value = suggestedSalePrice;

        dropdown.classList.add("d-none");
        dropdown.innerHTML = "";
        if (indicator) indicator.classList.add("d-none");

        updateRowCalculations(row);
        setTimeout(() => {
            const qtyInput = row.querySelector(".quantity-input");
            if (qtyInput) { qtyInput.focus(); qtyInput.select(); }
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
            if (priceInput) { priceInput.focus(); priceInput.select(); }
        }, 100);
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
            } else if (item.closest(".category-search-container")) {
                const catId = item.getAttribute("data-id");
                const catName = item.getAttribute("data-name");
                selectCategory(row, catId, catName);
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
    // الترتيب: اسم المنتج -> الكمية -> سعر الشراء -> سعر البيع -> إنشاء صف جديد والتنقل لمنتجه
    itemsContainer.addEventListener("keydown", function(e) {
        if (e.key === "Enter") {
            const row = e.target.closest(".item-row");
            if (!row) return;

            const dropdown = row.querySelector(".autocomplete-dropdown:not(.d-none)");
            if (dropdown) {
                const activeItem = dropdown.querySelector(".autocomplete-item.active, .autocomplete-item");
                if (activeItem && e.target.matches(".product-search-input, .category-input, .barcode-input")) {
                    e.preventDefault();
                    activeItem.click();
                    return;
                }
            }

            if (e.target.classList.contains("product-search-input")) {
                e.preventDefault();
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
                const salePriceInput = row.querySelector(".sale-price-input");
                if (salePriceInput) {
                    salePriceInput.focus();
                    salePriceInput.select();
                }
            } else if (e.target.classList.contains("sale-price-input") || e.target.classList.contains("category-input") || e.target.classList.contains("barcode-input")) {
                e.preventDefault();
                const nextRow = row.nextElementSibling;
                if (nextRow && nextRow.classList.contains("item-row")) {
                    const nextSearch = nextRow.querySelector(".product-search-input");
                    if (nextSearch) {
                        nextSearch.focus();
                        nextSearch.select();
                    }
                } else {
                    // إنشاء صف جديد تلقائياً عند الوصول لنهاية الصف الحالي
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
            alert("يجب إضافة صنف واحد على الأقل!");
            e.preventDefault();
            return false;
        }

        const payFromBox = document.getElementById("payFromBox").checked;
        if (payFromBox) {
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
                alert("رصيد الصندوق غير كافٍ! الرصيد المتاح: " + boxBalance.toFixed(2) + " ر.ي\nالمبلغ المطلوب: " + totalPaid.toFixed(2) + " ر.ي");
                e.preventDefault();
                return false;
            }
        }
    });
});

function togglePurchaseInvoiceType(val) {
    const paidRow = document.getElementById('paidRow');
    const paymentSection = document.getElementById('paymentMethodSection');
    const boxSection = document.getElementById('boxSection');

    if (val === 'credit') {
        paidRow.style.opacity = '0.4'; paidRow.style.pointerEvents = 'none';
        document.getElementById('totalPaidInput').value = '0';
        paymentSection.classList.add('d-none');
        boxSection.classList.add('d-none');
    } else {
        paidRow.style.opacity = '1'; paidRow.style.pointerEvents = 'auto';
        paymentSection.classList.remove('d-none');
        boxSection.classList.toggle('d-none', val !== 'cash');
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
        boxSelect.classList.toggle('bg-light', !chk.checked);
    }
    updateBoxBalanceDisplay();
}

function updateBoxBalanceDisplay() {
    const payFromBox = document.getElementById('payFromBox').checked;
    const invoiceType = document.getElementById('invoiceTypeSelect').value;
    const display = document.getElementById('boxBalanceDisplay');
    const balanceSpan = document.getElementById('currentBoxBalance');
    
    let boxBalance = 0;
    const boxSelect = document.getElementById('boxSelect');
    const userBoxId = document.getElementById('userBoxId');
    
    if (boxSelect && boxSelect.selectedIndex >= 0) {
        boxBalance = parseFloat(boxSelect.options[boxSelect.selectedIndex].getAttribute('data-balance')) || 0;
    } else if (userBoxId) {
        boxBalance = parseFloat(userBoxId.getAttribute('data-balance')) || 0;
    }
    
    balanceSpan.textContent = boxBalance.toFixed(2);
    const totalPaid = parseFloat(document.getElementById('totalPaidInput').value) || 0;
    
    if (payFromBox && invoiceType === 'cash' && totalPaid > boxBalance) {
        display.classList.add('box-balance-warning');
        display.innerHTML = '<i class="bi bi-exclamation-triangle-fill ml-1"></i> تحذير: رصيد الصندوق غير كافٍ! المتبقي: <strong>' + boxBalance.toFixed(2) + '</strong> ر.ي';
    } else {
        display.classList.remove('box-balance-warning');
        display.innerHTML = '<i class="bi bi-wallet2 ml-1"></i> رصيد الصندوق: <strong>' + boxBalance.toFixed(2) + '</strong> ر.ي';
    }
}

function openSearchPurchaseModal() {
    $('#searchPurchaseModal').modal('show');
    setTimeout(() => {
        const inp = document.getElementById('searchPurchaseQuery');
        if (inp) { inp.focus(); inp.select(); }
    }, 250);
}

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
    if (confirm(`تأكيد الحذف النهائي:\n\nهل أنت متأكد من رغبتك في حذف فاتورة المشتريات رقم #${id}؟\nسيتم إرجاع كميات المخزون وتأثير رصيد المورد والصندوق والقيود المحاسبية نهائياً، ولن يمكن التراجع عن هذا الإجراء.`)) {
        window.location.href = 'delete.php?id=' + id;
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
                            $res_past_p = $conn->query("SELECT id, invoice_no, invoice_date, supp_name, total_amount, invoice_type FROM purchase_invoices_mst WHERE d_s = 0 ORDER BY id DESC LIMIT 50");
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