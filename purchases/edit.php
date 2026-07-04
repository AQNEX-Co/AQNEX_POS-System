<?php
$dir_prefix = '../';
$module = 'purchases';

// معالجة إضافة المورد السريع عبر AJAX قبل تضمين الهيدر لمنع تلوث الـ JSON بالـ HTML
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
    
    // التحقق من عدم التكرار
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

if (!isset($_GET['id']) || empty($_GET['id'])) {
    echo "<div class='alert alert-danger rounded-0'>خطأ: لم يتم تحديد رقم الفاتورة.</div>";
    require_once($dir_prefix . 'includes/footer.php');
    exit;
}

$invoice_id = intval($_GET['id']);
$res_invoice = $conn->query("SELECT * FROM purchases WHERE id = $invoice_id");
$invoice = $res_invoice ? $res_invoice->fetch_assoc() : null;

if (!$invoice) {
    echo "<div class='alert alert-danger rounded-0'>خطأ: الفاتورة غير موجودة.</div>";
    require_once($dir_prefix . 'includes/footer.php');
    exit;
}

$is_admin = (trim($_SESSION['SESS_LAST_NAME']) === 'admin' || empty(trim($_SESSION['SESS_LAST_NAME'])));

// Determine original payment state
$original_paid_base = max(0, doubleval($invoice['total']) - doubleval($invoice['remaining_total']));
$original_supplier = $invoice['supp_name'];
$original_box_id = intval($invoice['box_id']);
$original_pay_from_box = false;
$journal_check = $conn->query("SELECT COUNT(*) as cnt FROM accounting_journal WHERE ref_type = 'purchase' AND ref_id = $invoice_id AND credit_acc LIKE 'الصندوق - %'");
if ($journal_check) {
    $journal_row = $journal_check->fetch_assoc();
    $original_pay_from_box = intval($journal_row['cnt']) > 0;
}

$save_error = '';

if (isset($_POST['btn_save'])) {
    $build_date = date('Y-m-d', strtotime($_POST['build_date']));
    $supplier_name = $conn->real_escape_string(trim($_POST['supplier_name']));
    $supplier_id = 0;
    $supplier_res = $conn->query("SELECT supp_id FROM suppliers WHERE supp_name = '$supplier_name' LIMIT 1");
    if ($supplier_res && $supplier_res->num_rows > 0) {
        $supplier_id = intval($supplier_res->fetch_assoc()['supp_id']);
    }
    $remark = $conn->real_escape_string(trim($_POST['remark']));
    $currency_code = $conn->real_escape_string($_POST['currency_code']);
    $exchange_rate = doubleval($_POST['exchange_rate']);
    if ($exchange_rate <= 0) {
        $exchange_rate = 1.0;
    }

    $selected_box_id = isset($_POST['box_id']) ? intval($_POST['box_id']) : $original_box_id;
    $pay_from_box = isset($_POST['pay_from_box']) && intval($_POST['pay_from_box']) === 1;

    $grand_total = doubleval($_POST['grand_total']);
    $total_paid_invoice = isset($_POST['total_paid_invoice']) ? doubleval($_POST['total_paid_invoice']) : 0;
    $grand_total_base = $grand_total * $exchange_rate;
    $total_paid_base = $total_paid_invoice * $exchange_rate;
    $total_remaining_base = max(0, $grand_total_base - $total_paid_base);

    $products = isset($_POST['product_name']) ? $_POST['product_name'] : [];
    $product_ids = isset($_POST['product_id']) ? $_POST['product_id'] : [];
    $quantities = isset($_POST['quantity']) ? $_POST['quantity'] : [];
    $unit_prices = isset($_POST['unit_price']) ? $_POST['unit_price'] : [];
    $line_totals = isset($_POST['line_total']) ? $_POST['line_total'] : [];

    $conn->begin_transaction();
    try {
        // Prevent editing if there are active purchase returns on this invoice
        $returns_check = $conn->query("SELECT COUNT(*) as cnt FROM purchase_returns WHERE purchase_id = $invoice_id AND status = 'active'");
        if ($returns_check) {
            $returns_row = $returns_check->fetch_assoc();
            if (intval($returns_row['cnt']) > 0) {
                throw new Exception('لا يمكن تعديل الفاتورة لأنها تحتوي على مرتجعات مشتريات نشطة. قم بإلغاء المرتجعات أولاً أو أنشئ فاتورة جديدة.');
            }
        }

        // Reverse old line item inventory quantities
        $old_items_res = $conn->query("SELECT * FROM purchase_items WHERE purchase_id = $invoice_id");
        if ($old_items_res) {
            while ($old_item = $old_items_res->fetch_assoc()) {
                $item_name = $conn->real_escape_string($old_item['name']);
                $old_qty = intval($old_item['quantity']);
                if ($old_qty > 0) {
                    $product_lookup = $conn->query("SELECT id, quantity FROM products WHERE name = '$item_name' LIMIT 1");
                    if ($product_lookup && $product_lookup->num_rows > 0) {
                        $product_row = $product_lookup->fetch_assoc();
                        $product_id = intval($product_row['id']);
                        $conn->query("UPDATE products SET quantity = GREATEST(0, quantity - $old_qty), total = GREATEST(0, quantity * buy_price) WHERE id = $product_id");
                        $conn->query("INSERT INTO inventory_log (product_id, product_name, type, qty_change, new_qty, reason, user) SELECT $product_id, name, 'purchase', -$old_qty, quantity, 'عكس فاتورة مشتريات رقم #$invoice_id', '" . $_SESSION['SESS_FIRST_NAME'] . "' FROM products WHERE id = $product_id");
                    }
                }
            }
        }

        // Delete old invoice details and accounting entries
        $conn->query("DELETE FROM purchase_items WHERE purchase_id = $invoice_id");
        $conn->query("DELETE FROM journal_entries WHERE ref_type = 'purchase' AND ref_id = $invoice_id");
        $conn->query("DELETE FROM accounting_journal WHERE ref_type = 'purchase' AND ref_id = $invoice_id");

        // Update main invoice
        $sql_update = "UPDATE purchases SET date = '$build_date', supp_name = '$supplier_name', total = '$grand_total_base', remark = '$remark', currency_code = '$currency_code', exchange_rate = '$exchange_rate', box_id = $selected_box_id, remaining_total = '$total_remaining_base' WHERE id = $invoice_id";
        if (!$conn->query($sql_update)) {
            throw new Exception('فشل تحديث الفاتورة الرئيسية: ' . $conn->error);
        }

        // Add new purchase items and inventory effects
        $count = count($products);
        $items_to_save = [];
        $invoice_lines_base_total = 0;

        for ($i = 0; $i < $count; $i++) {
            $p_name = trim($products[$i]);
            $p_id = intval($product_ids[$i]);
            $qty = intval($quantities[$i]);
            $u_price = doubleval($unit_prices[$i]);
            $l_total = doubleval($line_totals[$i]);

            if (!empty($p_name) && $qty > 0 && $l_total > 0) {
                $line_total_base = $l_total * $exchange_rate;
                $items_to_save[] = [
                    'name' => $conn->real_escape_string($p_name),
                    'product_id' => $p_id,
                    'quantity' => $qty,
                    'unit_price_base' => $u_price * $exchange_rate,
                    'line_total_base' => $line_total_base,
                ];
                $invoice_lines_base_total += $line_total_base;
            }
        }

        if ($invoice_lines_base_total > 0) {
            $grand_total_base = $invoice_lines_base_total;
        }
        $total_remaining_base = max(0, $grand_total_base - $total_paid_base);

        $paid_ratio = ($invoice_lines_base_total > 0) ? min(1.0, $total_paid_base / $invoice_lines_base_total) : 0;
        $allocated_paid = 0;
        $line_count = count($items_to_save);

        foreach ($items_to_save as $index => $item) {
            $p_name = $item['name'];
            $p_id = intval($item['product_id']);
            $qty = intval($item['quantity']);
            $unit_buy_price_base = $item['unit_price_base'];
            $l_total_base = $item['line_total_base'];

            if ($line_count > 0 && $index === $line_count - 1) {
                $line_paid_base = max(0, $total_paid_base - $allocated_paid);
            } else {
                $line_paid_base = round($l_total_base * $paid_ratio, 2);
                $allocated_paid += $line_paid_base;
            }
            $line_remaining_base = max(0, $l_total_base - $line_paid_base);

            $sql_item = "INSERT INTO purchase_items (purchase_id, buys_date, supp_name, supp_id, name, quantity, buy_price, pushtosupp, total_d, s) VALUES ($invoice_id, '$build_date', '$supplier_name', $supplier_id, '$p_name', $qty, $l_total_base, $line_paid_base, $line_remaining_base, 0)";
            $conn->query($sql_item);

            if ($p_id <= 0) {
                $product_name_esc = $conn->real_escape_string($p_name);
                $chk_p = $conn->query("SELECT id FROM products WHERE name = '$product_name_esc' AND delete_status = 0 LIMIT 1");
                if ($chk_p && $chk_p->num_rows > 0) {
                    $p_id = intval($chk_p->fetch_assoc()['id']);
                } else {
                    $res_cat = $conn->query("SELECT catid FROM categories WHERE d_s = 0 LIMIT 1");
                    $cat_id = ($res_cat && $res_cat->num_rows > 0) ? intval($res_cat->fetch_assoc()['catid']) : 0;
                    if ($cat_id <= 0) {
                        $conn->query("INSERT INTO categories (name, d_s) VALUES ('عام', 0)");
                        $cat_id = $conn->insert_id;
                    }
                    $sale_price_val = $unit_buy_price_base * 1.25;
                    $conn->query("INSERT INTO products (name, quantity, buy_price, sale_price, catid, date, delete_status) VALUES ('$product_name_esc', 0, $unit_buy_price_base, $sale_price_val, $cat_id, NOW(), 0)");
                    $p_id = $conn->insert_id;
                }
            }

            if ($p_id > 0) {
                $conn->query("UPDATE products SET quantity = quantity + $qty, buy_price = $unit_buy_price_base, total = quantity * buy_price WHERE id = $p_id");
                $conn->query("INSERT INTO inventory_log (product_id, product_name, type, qty_change, new_qty, reason, user) SELECT $p_id, name, 'purchase', $qty, quantity, 'تعديل فاتورة مشتريات رقم #$invoice_id', '" . $_SESSION['SESS_FIRST_NAME'] . "' FROM products WHERE id = $p_id");
            } else {
                $conn->query("UPDATE products SET quantity = quantity + $qty, buy_price = $unit_buy_price_base, total = quantity * buy_price WHERE name = '$p_name'");
            }
        }

        // Adjust supplier debt after update
        if (!empty($supplier_name)) {
            if ($supplier_name === $original_supplier) {
                $debt_diff = $total_remaining_base - doubleval($invoice['remaining_total']);
                if ($debt_diff !== 0) {
                    $conn->query("UPDATE suppliers SET supp_daain = GREATEST(0, supp_daain + $debt_diff) WHERE supp_name = '" . $conn->real_escape_string($supplier_name) . "'");
                }
            } else {
                $conn->query("UPDATE suppliers SET supp_daain = GREATEST(0, supp_daain + $total_remaining_base) WHERE supp_name = '" . $conn->real_escape_string($supplier_name) . "'");
                if (!empty($original_supplier)) {
                    $conn->query("UPDATE suppliers SET supp_daain = GREATEST(0, supp_daain - " . doubleval($invoice['remaining_total']) . ") WHERE supp_name = '" . $conn->real_escape_string($original_supplier) . "'");
                }
            }
        }

        // Update box balances when payment source changed
        $box_name = get_box_name($conn, $selected_box_id);
        if ($pay_from_box) {
            if ($original_pay_from_box) {
                if ($selected_box_id === $original_box_id) {
                    $diff_paid = $total_paid_base - $original_paid_base;
                    if ($diff_paid !== 0) {
                        $type = $diff_paid > 0 ? 'discount' : 'add';
                        update_box_balance($conn, $selected_box_id, abs($diff_paid), $type, "تعديل دفعة فاتورة مشتريات رقم #$invoice_id", $build_date);
                    }
                } else {
                    if ($original_paid_base > 0) {
                        update_box_balance($conn, $original_box_id, $original_paid_base, 'add', "إلغاء دفعة فاتورة مشتريات رقم #$invoice_id من الصندوق القديم", $build_date);
                    }
                    if ($total_paid_base > 0) {
                        update_box_balance($conn, $selected_box_id, $total_paid_base, 'discount', "دفع فاتورة مشتريات رقم #$invoice_id بعد التعديل", $build_date);
                    }
                }
            } else {
                if ($total_paid_base > 0) {
                    update_box_balance($conn, $selected_box_id, $total_paid_base, 'discount', "دفع فاتورة مشتريات رقم #$invoice_id بعد التعديل", $build_date);
                }
            }
        } else {
            if ($original_pay_from_box && $original_paid_base > 0) {
                update_box_balance($conn, $original_box_id, $original_paid_base, 'add', "إرجاع دفعة فاتورة مشتريات رقم #$invoice_id بعد إلغاء الدفع من الصندوق", $build_date);
            }
        }

        // Repost journal entries for the edited invoice
        $user_display = $_SESSION['SESS_FIRST_NAME'];
        if ($total_paid_base > 0) {
            $credit_acc = $pay_from_box ? ('الصندوق - ' . $box_name) : 'رأس المال / دفع خارجي';
            $journal_box_id = $pay_from_box ? $selected_box_id : null;
            post_journal_entry($conn, 'purchase', $invoice_id, 'المخزون / البضاعة', $credit_acc, $total_paid_base, "شراء بضاعة (نقداً) فاتورة رقم #$invoice_id", $user_display, $journal_box_id, $currency_code, $exchange_rate);
        }
        if ($total_remaining_base > 0) {
            post_journal_entry($conn, 'purchase', $invoice_id, 'المخزون / البضاعة', 'الذمم الدائنة - ' . $supplier_name, $total_remaining_base, "شراء بضاعة (آجل) فاتورة رقم #$invoice_id", $user_display, $selected_box_id, $currency_code, $exchange_rate);
        }

        $conn->commit();
        echo "<script>window.location='view.php?id=$invoice_id';</script>";
        exit;
    } catch (Exception $e) {
        $conn->rollback();
        $save_error = 'فشل تعديل الفاتورة: ' . $e->getMessage();
    }
}

// جلب المنتجات للبحث المحلي
// تم إلغاء التحميل المسبق لكامل المنتجات لتسريع أداء الصفحة وتم استبداله بطلب AJAX عند البحث
$products_json = '[]';

// جلب العملات
$currencies_list = [];
$res_curr = $conn->query("SELECT * FROM currencies ORDER BY id ASC");
if ($res_curr) {
    while ($c = $res_curr->fetch_assoc()) {
        $currencies_list[] = $c;
    }
}

// جلب البنود الحالية للفاتورة
$items_list = [];
$res_items = $conn->query("SELECT * FROM purchase_items WHERE purchase_id = $invoice_id");
if ($res_items) {
    while ($row = $res_items->fetch_assoc()) {
        $item_name = $row['name'];
        $product_id = -1;
        $prod_lookup = $conn->query("SELECT id FROM products WHERE name = '" . $conn->real_escape_string($item_name) . "' LIMIT 1");
        if ($prod_lookup && $prod_lookup->num_rows > 0) {
            $product_id = intval($prod_lookup->fetch_assoc()['id']);
        }

        $qty = intval($row['quantity']);
        $line_total = doubleval($row['buy_price']) / doubleval($invoice['exchange_rate']);
        $unit_price = $qty > 0 ? ($line_total / $qty) : 0;

        $items_list[] = [
            'product_id' => $product_id,
            'name' => $item_name,
            'quantity' => $qty,
            'unit_price' => $unit_price,
            'line_total' => $line_total
        ];
    }
}

$old_paid_invoice = $original_paid_base / doubleval($invoice['exchange_rate']);
$old_total_remaining = doubleval($invoice['remaining_total']) / doubleval($invoice['exchange_rate']);
$old_grand_total = doubleval($invoice['total']) / doubleval($invoice['exchange_rate']);
$box_id = $original_box_id;
?>
<title>تعديل فاتورة مشتريات #<?php echo $invoice_id; ?> - تكنولوجيا فون</title>

<style>
.product-search-container { position: relative; }
.autocomplete-dropdown { position: absolute; top: 100%; right: 0; width: 100%; background: #fff; border: 1px solid var(--secondary); border-top: none; max-height: 250px; overflow-y: auto; z-index: 1050; box-shadow: 0 4px 8px rgba(0,0,0,0.15); }
.autocomplete-item { padding: 8px 12px; cursor: pointer; border-bottom: 1px solid #f1f5f9; font-size: 0.9rem; transition: background-color 0.2s; text-align: right; }
.autocomplete-item:hover, .autocomplete-item.active { background-color: #f8f9fa; color: var(--secondary); }
</style>

<div class="card-flat">
    <div class="card-header">
        <h5><?php echo get_icon('purchases', 'ml-2 text-primary'); ?> تعديل فاتورة مشتريات #<?php echo $invoice_id; ?></h5>
        <a href="view.php?id=<?php echo $invoice_id; ?>" class="btn-flat btn-flat-secondary btn-sm text-decoration-none">
            <?php echo get_icon('eye', 'ml-1'); ?> عرض الفاتورة
        </a>
        <a href="index.php" class="btn-flat btn-flat-secondary btn-sm text-decoration-none">
            <?php echo get_icon('logout', 'ml-1'); ?> عودة
        </a>
    </div>
    <div class="card-body">
        <?php if (!empty($save_error)): ?>
            <div class="alert alert-danger rounded-0"><?php echo htmlspecialchars($save_error); ?></div>
        <?php endif; ?>
        <form method="POST" id="purchaseEditForm">
            <div class="row mb-3">
                <div class="col-md-2 col-sm-6 mb-3">
                    <label class="form-label font-weight-bold text-secondary">تاريخ الشراء</label>
                    <input type="date" name="build_date" class="form-control rounded-0" value="<?php echo htmlspecialchars($invoice['date']); ?>" required>
                </div>
                <div class="col-md-3 col-sm-6 mb-3">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <label class="form-label font-weight-bold text-secondary mb-0">المورد</label>
                        <a href="javascript:void(0)" class="small font-weight-bold text-primary text-decoration-none" data-toggle="modal" data-target="#quickAddSupplierModal">
                            <i class="fa fa-plus-circle ml-1"></i>مورد جديد
                        </a>
                    </div>
                    <select name="supplier_name" id="supplierSelect" class="form-control rounded-0" required>
                        <option value="">-- اختر مورد --</option>
                        <?php
                        $sql_supp = "SELECT supp_name FROM suppliers WHERE d_s = 0 ORDER BY supp_id DESC";
                        $res_supp = $conn->query($sql_supp);
                        if ($res_supp) {
                            while ($row = $res_supp->fetch_assoc()) {
                                $selected = ($row['supp_name'] === $invoice['supp_name']) ? 'selected' : '';
                                echo "<option value='" . htmlspecialchars($row['supp_name']) . "' $selected>" . htmlspecialchars($row['supp_name']) . "</option>";
                            }
                        }
                        ?>
                    </select>
                </div>
                <div class="col-md-3 col-sm-6 mb-3">
                    <label class="form-label font-weight-bold text-secondary">الصندوق (للمدفوعات النقدية)</label>
                    <div class="form-check mb-1">
                        <input type="checkbox" name="pay_from_box" id="payFromBox" class="form-check-input" value="1" <?php echo $original_pay_from_box ? 'checked' : ''; ?> onchange="toggleBoxSelect(this)">
                        <label class="form-check-label small font-weight-bold text-dark" for="payFromBox">خصم المدفوع النقدي من الصندوق</label>
                    </div>
                    <?php if ($is_admin): ?>
                        <select name="box_id" id="boxSelect" class="form-control rounded-0" required>
                            <?php
                            $res_b = $conn->query("SELECT box_id, name, mony FROM treasury WHERE is_active = 1 ORDER BY box_id ASC");
                            if ($res_b) {
                                while ($b = $res_b->fetch_assoc()) {
                                    $selected = ($b['box_id'] == $box_id) ? 'selected' : '';
                                    echo "<option value='{$b['box_id']}' data-balance='" . floatval($b['mony']) . "' $selected>" . htmlspecialchars($b['name']) . " (" . number_format($b['mony'], 2) . " ر.ي)</option>";
                                }
                            }
                            ?>
                        </select>
                    <?php else: ?>
                        <?php
                        $user_box_id = get_user_box_id($conn, intval($_SESSION['SESS_MEMBER_ID']));
                        $box_res = $conn->query("SELECT mony FROM treasury WHERE box_id = $user_box_id");
                        $box_mony = ($box_res && $box_res->num_rows > 0) ? floatval($box_res->fetch_assoc()['mony']) : 0;
                        ?>
                        <input type="hidden" name="box_id" value="<?php echo $user_box_id; ?>" id="userBoxId" data-balance="<?php echo $box_mony; ?>">
                        <input type="text" class="form-control text-center font-weight-bold bg-light rounded-0" readonly value="<?php echo htmlspecialchars(get_box_name($conn, $user_box_id)); ?> (<?php echo number_format($box_mony, 2); ?> ر.ي)">
                    <?php endif; ?>
                </div>
                <div class="col-md-2 col-sm-6 mb-3">
                    <label class="form-label font-weight-bold text-secondary">عملة الفاتورة</label>
                    <select name="currency_code" id="currencySelect" class="form-control rounded-0" required>
                        <?php foreach($currencies_list as $c): ?>
                            <option value="<?php echo htmlspecialchars($c['code']); ?>" data-rate="<?php echo $c['exchange_rate']; ?>" data-symbol="<?php echo htmlspecialchars($c['symbol']); ?>" <?php echo ($c['code'] === $invoice['currency_code']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($c['name']); ?> (<?php echo htmlspecialchars($c['symbol']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2 col-sm-6 mb-3">
                    <label class="form-label font-weight-bold text-secondary">سعر الصرف (YER)</label>
                    <input type="number" step="any" name="exchange_rate" id="exchangeRateInput" class="form-control rounded-0 font-weight-bold text-center bg-light" value="<?php echo htmlspecialchars($invoice['exchange_rate']); ?>" readonly required>
                </div>
            </div>

            <!-- شريط البحث السريع والباركود -->
            <div class="card p-3 bg-light border-0 mb-4 no-print">
                <div class="row align-items-center">
                    <div class="col-md-6 mb-2 mb-md-0">
                        <div class="input-group">
                            <div class="input-group-prepend">
                                <span class="input-group-text bg-primary text-white border-0">
                                    <i class="bi bi-upc-scan"></i>
                                </span>
                            </div>
                            <input type="text" id="barcodeScanInput" class="form-control rounded-0 border-primary font-weight-bold text-center" placeholder="امسح باركود المنتج هنا للادخال السريع..." autofocus autocomplete="off">
                        </div>
                    </div>
                    <div class="col-md-6 text-md-left">
                        <button type="button" id="quickProductSearchBtn" class="btn btn-outline-primary rounded-0 px-4 font-weight-bold">
                            <i class="bi bi-search ml-1"></i> F2 - البحث السريع عن المنتج
                        </button>
                    </div>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table-flat" id="purchaseTable">
                    <thead>
                        <tr>
                            <th style="width: 35%;">المنتج</th>
                            <th style="width: 15%;">الكمية</th>
                            <th style="width: 20%;">سعر الشراء الفردي</th>
                            <th style="width: 25%;">المجموع الكلي</th>
                            <th class="no-print" style="width: 5%;">اجراء</th>
                        </tr>
                    </thead>
                    <tbody id="itemsContainer">
                        <?php if (!empty($items_list)): ?>
                            <?php foreach ($items_list as $item): ?>
                                <tr class="item-row">
                                    <td>
                                        <div class="product-search-container">
                                            <input type="text" class="form-control product-search-input rounded-0" placeholder="ابحث باسم المنتج أو الباركود..." autocomplete="off" value="<?php echo htmlspecialchars($item['name']); ?>" required>
                                            <input type="hidden" name="product_name[]" class="select-product-name" value="<?php echo htmlspecialchars($item['name']); ?>">
                                            <input type="hidden" name="product_id[]" class="select-product" value="<?php echo $item['product_id']; ?>">
                                            <div class="autocomplete-dropdown d-none"></div>
                                        </div>
                                    </td>
                                    <td>
                                        <input type="number" name="quantity[]" class="form-control quantity-input text-center rounded-0" min="1" value="<?php echo $item['quantity']; ?>" required>
                                    </td>
                                    <td>
                                        <input type="number" step="any" name="unit_price[]" class="form-control price-input text-center rounded-0" value="<?php echo number_format($item['unit_price'], 2); ?>" required>
                                    </td>
                                    <td>
                                        <input type="text" name="line_total[]" class="form-control total-input text-center bg-light rounded-0" readonly value="<?php echo number_format($item['line_total'], 2); ?>">
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
                                        <input type="text" class="form-control product-search-input rounded-0" placeholder="ابحث باسم المنتج أو الباركود..." autocomplete="off" required>
                                        <input type="hidden" name="product_name[]" class="select-product-name" value="">
                                        <input type="hidden" name="product_id[]" class="select-product" value="-1">
                                        <div class="autocomplete-dropdown d-none"></div>
                                    </div>
                                </td>
                                <td>
                                    <input type="number" name="quantity[]" class="form-control quantity-input text-center rounded-0" min="1" value="1" required>
                                </td>
                                <td>
                                    <input type="number" step="any" name="unit_price[]" class="form-control price-input text-center rounded-0" value="0" required>
                                </td>
                                <td>
                                    <input type="text" name="line_total[]" class="form-control total-input text-center bg-light rounded-0" readonly value="0.00">
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

            <div class="row">
                <div class="col-lg-6 mb-3">
                    <label class="form-label font-weight-bold text-secondary">ملاحظات الفاتورة</label>
                    <textarea name="remark" class="form-control rounded-0" rows="3" placeholder="ملاحظات حول عملية الشراء..."><?php echo htmlspecialchars($invoice['remark']); ?></textarea>
                </div>
                <div class="col-lg-6">
                    <div class="card bg-light border-0">
                        <div class="card-body">
                            <table class="w-100">
                                <tbody>
                                    <tr>
                                        <td class="py-2">إجمالي الفاتورة الكلي</td>
                                        <td class="text-left font-weight-bold" style="font-size: 1.2rem;">
                                            <input type="text" id="grandTotalDisplay" name="grand_total" class="form-control text-left font-weight-bold bg-transparent border-0 rounded-0 w-75 d-inline" readonly value="<?php echo number_format($old_grand_total, 2); ?>">
                                            <span class="currency-symbol"><?php echo htmlspecialchars($invoice['currency_code']); ?></span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td class="py-2">المبلغ المدفوع للمورد</td>
                                        <td class="text-left font-weight-bold" style="font-size: 1.2rem;">
                                            <input type="number" step="any" id="totalPaidInput" name="total_paid_invoice" class="form-control text-left font-weight-bold bg-white border-1 rounded-0 w-75 d-inline" value="<?php echo number_format($old_paid_invoice, 2); ?>">
                                            <span class="currency-symbol"><?php echo htmlspecialchars($invoice['currency_code']); ?></span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td class="py-2 text-danger">الرصيد المتبقي (آجل)</td>
                                        <td class="text-left font-weight-bold text-danger" style="font-size: 1.2rem;">
                                            <input type="text" id="totalRemainingDisplay" name="total_remaining_invoice" class="form-control text-left font-weight-bold bg-transparent border-0 rounded-0 w-75 d-inline" readonly value="<?php echo number_format($old_total_remaining, 2); ?>">
                                            <span class="currency-symbol"><?php echo htmlspecialchars($invoice['currency_code']); ?></span>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div class="mt-4 no-print text-left">
                <button type="submit" name="btn_save" class="btn-flat btn-flat-primary btn-lg px-5">
                    <?php echo get_icon('check', 'ml-1'); ?> حفظ التعديلات
                </button>
            </div>
        </form>
    </div>
</div>

<script type="text/javascript">
const availableProducts = <?php echo $products_json; ?>;

document.addEventListener("DOMContentLoaded", function() {
    const itemsContainer = document.getElementById("itemsContainer");
    const addItemBtn = document.getElementById("addItemBtn");
    const exchangeRateInput = document.getElementById("exchangeRateInput");

    const rowTemplate = document.querySelector(".item-row").cloneNode(true);

    function updateRowCalculations(row) {
        const qty = parseFloat(row.querySelector(".quantity-input").value) || 0;
        const price = parseFloat(row.querySelector(".price-input").value) || 0;
        const lineTotal = qty * price;
        row.querySelector(".total-input").value = lineTotal.toFixed(2);
        updateGrandTotals();
    }

    function updateGrandTotals() {
        let totalVal = 0;
        document.querySelectorAll(".item-row").forEach(function(row) {
            totalVal += parseFloat(row.querySelector(".total-input").value) || 0;
        });
        document.getElementById("grandTotalDisplay").value = totalVal.toFixed(2);
        const paid = parseFloat(document.getElementById("totalPaidInput").value) || 0;
        document.getElementById("totalRemainingDisplay").value = (totalVal - paid).toFixed(2);
    }

    document.getElementById("totalPaidInput").addEventListener("input", updateGrandTotals);

    addItemBtn.addEventListener("click", function() {
        const newRow = rowTemplate.cloneNode(true);
        newRow.querySelector(".product-search-input").value = "";
        newRow.querySelector(".select-product-name").value = "";
        newRow.querySelector(".select-product").value = "-1";
        newRow.querySelector(".quantity-input").value = "1";
        newRow.querySelector(".price-input").value = "0";
        newRow.querySelector(".total-input").value = "0.00";
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
            } else {
                alert("يجب أن تحتوي الفاتورة على صنف واحد على الأقل!");
            }
        }
    });

    itemsContainer.addEventListener("input", function(e) {
        if (e.target.matches(".product-search-input")) {
            const container = e.target.closest(".product-search-container");
            const hiddenInput = container.querySelector(".select-product");
            const nameInput = container.querySelector(".select-product-name");
            if (!hiddenInput.value || hiddenInput.value === "-1") {
                nameInput.value = e.target.value;
            }
            showAutocompleteDropdown(e.target);
        }

        if (e.target.matches(".quantity-input, .price-input")) {
            const row = e.target.closest(".item-row");
            updateRowCalculations(row);
        }
    });

    function showAutocompleteDropdown(input) {
        const row = input.closest(".item-row");
        window.activeSearchRow = row;
        const query = input.value.trim();
        openQuickProductModal();
        
        // Populate the modal search input and trigger search
        setTimeout(() => {
            const modalSearchInput = document.getElementById('quickProductSearchInput');
            if (modalSearchInput) {
                modalSearchInput.value = query;
                renderQuickProductResults(query);
            }
        }, 300);
    }

    function escapeHtml(text) {
        if (!text) return '';
        return text.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/\"/g, "&quot;").replace(/'/g, "&#039;");
    }

    function selectProductForRow(row, product) {
        const container = row.querySelector(".product-search-container");
        const input = container.querySelector(".product-search-input");
        const hiddenInput = container.querySelector(".select-product");
        const nameInput = container.querySelector(".select-product-name");
        const dropdown = container.querySelector(".autocomplete-dropdown");

        input.value = product.name;
        hiddenInput.value = product.id;
        nameInput.value = product.name;

        const buyPriceConverted = (product.buy_price / (parseFloat(exchangeRateInput.value) || 1.0)).toFixed(2);
        row.querySelector(".price-input").value = buyPriceConverted;
        row.querySelector(".quantity-input").value = 1;

        dropdown.classList.add("d-none");
        dropdown.innerHTML = "";

        updateRowCalculations(row);
    }

    itemsContainer.addEventListener("click", function(e) {
        const item = e.target.closest(".autocomplete-item");
        if (item) {
            const productId = item.getAttribute("data-id");
            const row = item.closest(".item-row");
            if (productId && row) {
                if (productId === "-1") {
                    const container = row.querySelector(".product-search-container");
                    const searchInput = container.querySelector(".product-search-input");
                    const newProdName = searchInput.value.trim();
                    container.querySelector(".select-product").value = -1;
                    container.querySelector(".select-product-name").value = newProdName;
                    searchInput.value = newProdName;
                    row.querySelector(".price-input").value = "0";
                    row.querySelector(".quantity-input").value = "1";
                    container.querySelector(".autocomplete-dropdown").classList.add("d-none");
                    setTimeout(() => {
                        row.querySelector(".quantity-input").focus();
                        row.querySelector(".quantity-input").select();
                    }, 10);
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

    itemsContainer.addEventListener("keydown", function(e) {
        if (e.key === "Enter") {
            e.preventDefault();
            const row = e.target.closest(".item-row");
            if (!row) return;
            if (e.target.matches(".product-search-input")) {
                const container = e.target.closest(".product-search-container");
                const dropdown = container.querySelector(".autocomplete-dropdown");
                const typedValue = e.target.value.trim();
                if (!typedValue) return;

                let activeItem = dropdown.querySelector(".autocomplete-item.active");
                let match = null;
                if (activeItem) {
                    const pId = activeItem.getAttribute("data-id");
                    if (pId && pId !== "-1") {
                        match = availableProducts.find(p => p.id == pId);
                    }
                }
                if (!match) {
                    match = availableProducts.find(p => p.name.toLowerCase() === typedValue.toLowerCase() || (p.barcode && p.barcode === typedValue));
                }
                if (match) {
                    selectProductForRow(row, match);
                } else {
                    const hiddenInput = container.querySelector(".select-product");
                    const nameInput = container.querySelector(".select-product-name");
                    hiddenInput.value = -1;
                    nameInput.value = typedValue;
                    e.target.value = typedValue;
                    row.querySelector(".price-input").value = "0";
                    row.querySelector(".quantity-input").value = "1";
                    dropdown.classList.add("d-none");
                }
            }
        }
    });

    updateGrandTotals();
});

function toggleBoxSelect(checkbox) {
    const boxSelect = document.getElementById('boxSelect');
    if (boxSelect) {
        boxSelect.disabled = !checkbox.checked;
    }
}
</script>

<!-- Modal إضافة مورد جديد سريعاً -->
<div class="modal fade modal-modern" id="quickAddSupplierModal" tabindex="-1" role="dialog" aria-labelledby="quickAddSupplierModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="quickAddSupplierModalLabel">
                    <i class="bi bi-person-plus-fill"></i> إضافة مورد جديد
                </h5>
                <button type="button" class="close ml-0" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true" style="font-size:1.4rem;line-height:1;">&times;</span>
                </button>
            </div>

            <div class="modal-body text-right" dir="rtl">
                <div class="alert alert-danger d-none" id="quickAddSupplierError"></div>
                <form id="quickAddSupplierForm">
                    <div class="form-group mb-3">
                        <label class="font-weight-bold text-secondary">اسم المورد <span class="text-danger">*</span></label>
                        <input type="text" class="form-control rounded-0" name="supp_name" placeholder="أدخل اسم المورد بالكامل" required>
                    </div>
                    <div class="form-group mb-3">
                        <label class="font-weight-bold text-secondary">رقم الجوال <span class="text-danger">*</span></label>
                        <input type="text" class="form-control rounded-0" name="phone" placeholder="أدخل رقم الجوال" required>
                    </div>
                    <div class="form-group mb-3">
                        <label class="font-weight-bold text-secondary">اسم الشركة</label>
                        <input type="text" class="form-control rounded-0" name="company_name" placeholder="أدخل اسم الشركة الموردة">
                    </div>
                    <div class="form-group mb-3">
                        <label class="font-weight-bold text-secondary">البريد الإلكتروني</label>
                        <input type="email" class="form-control rounded-0" name="email" placeholder="supplier@example.com">
                    </div>
                    <div class="form-group mb-3">
                        <label class="font-weight-bold text-secondary">العنوان</label>
                        <input type="text" class="form-control rounded-0" name="address" placeholder="المحافظة - المدينة - الشارع">
                    </div>
                    <div class="form-group mb-3">
                        <label class="font-weight-bold text-secondary">ملاحظات إضافية</label>
                        <textarea class="form-control rounded-0" name="notes" rows="2" placeholder="أدخل أي ملاحظات..."></textarea>
                    </div>
                    <input type="hidden" name="ajax_add_supplier" value="1">
                    <hr class="my-3">
                    <div class="text-left">
                        <button type="submit" class="btn btn-success rounded-0 font-weight-bold px-4">
                            <i class="fa fa-plus ml-1"></i>حفظ المورد
                        </button>
                        <button type="button" class="btn btn-secondary rounded-0 mr-2" data-dismiss="modal">إلغاء</button>
                    </div>
                </form>
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
                    const supplierSelect = document.getElementById("supplierSelect");
                    if (supplierSelect) {
                        const opt = document.createElement("option");
                        opt.value = data.name;
                        opt.text = data.name;
                        opt.selected = true;
                        opt.setAttribute('data-id', data.id || '');
                        supplierSelect.add(opt);
                        
                        if (typeof $(supplierSelect).trigger === "function") {
                            $(supplierSelect).val(data.name).trigger('change');
                        } else {
                            supplierSelect.value = data.name;
                        }
                    }
                    
                    // إغلاق المودال وإعادة تهيئة النموذج بطريقة آمنة
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

<!-- Modal البحث السريع عن المنتج (F2) -->
<div class="modal fade" id="quickProductSearchModal" tabindex="-1" role="dialog" aria-labelledby="quickProductSearchModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content rounded-0">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="quickProductSearchModalLabel"><i class="bi bi-search ml-2"></i> البحث السريع عن المنتج</h5>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <input type="text" id="quickProductSearchInput" class="form-control rounded-0" placeholder="ابحث باسم المنتج أو الباركود..." autocomplete="off">
                <div id="quickProductSearchResults" class="mt-3" style="max-height: 320px; overflow-y: auto;"></div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function() {
    const quickProductBtn = document.getElementById('quickProductSearchBtn');
    if (quickProductBtn) {
        quickProductBtn.addEventListener('click', openQuickProductModal);
    }

    let modalProductPage = 1;
    let modalProductLoading = false;
    let modalProductHasMore = true;
    let modalProductQuery = "";

    function fetchModalProducts(isNewSearch = false) {
        const results = document.getElementById('quickProductSearchResults');
        if (!results || modalProductLoading || (!isNewSearch && !modalProductHasMore)) return;

        modalProductLoading = true;
        
        if (isNewSearch) {
            results.innerHTML = '<div class="text-center p-3 text-muted"><div class="circular-loader"></div>جاري تحميل المنتجات...</div>';
            modalProductPage = 1;
            modalProductHasMore = true;
        } else {
            const spinner = document.createElement('div');
            spinner.className = 'text-center p-2 text-muted loading-spinner';
            spinner.innerHTML = '<div class="circular-loader"></div>تحميل المزيد...';
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
                        results.innerHTML = '<div class="text-danger text-center p-3">لم يتم العثور على منتج مطابق.</div>';
                    }
                    modalProductLoading = false;
                    return;
                }

                const rate = parseFloat(exchangeRateInput.value) || 1.0;
                let html = products.map(product => {
                    const pJson = JSON.stringify(product).replace(/'/g, "&#39;");
                    const priceConverted = (product.buy_price / rate).toFixed(2);
                    const stockVal = Math.floor(product.quantity / product.conversion_factor);
                    
                    let stockBadgeClass = "badge-success";
                    if (stockVal <= 0) {
                        stockBadgeClass = "badge-danger";
                    } else if (stockVal <= 5) {
                        stockBadgeClass = "badge-warning";
                    }

                    return `
                        <button type="button" class="btn btn-light btn-block text-right search-result-item" data-product='${pJson}'>
                            <div class="item-title">${escapeHtml(product.name)}</div>
                            <div class="item-meta">
                                <span>الباركود: <strong>${escapeHtml(product.barcode || '-')}</strong></span>
                                <span>تكلفة الشراء: <strong class="text-primary">${priceConverted} ر.ي</strong></span>
                                <span>المخزون: <strong class="badge ${stockBadgeClass}">${stockVal}</strong></span>
                            </div>
                        </button>
                    `;
                }).join('');

                results.insertAdjacentHTML('beforeend', html);

                results.querySelectorAll('.search-result-item').forEach(btn => {
                    if (btn.dataset.clickRegistered) return;
                    btn.dataset.clickRegistered = "true";
                    btn.addEventListener('click', function() {
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

    function selectAndRouteFocus(product) {
        let activeRow = window.activeSearchRow;
        
        if (activeRow) {
            selectProductForRow(activeRow, product);
        } else {
            addProductToInvoice(product);
            const rows = document.querySelectorAll(".item-row");
            activeRow = rows[rows.length - 1];
        }

        $('#quickProductSearchModal').modal('hide');

        if (activeRow) {
            setTimeout(() => {
                const qtyInput = activeRow.querySelector(".quantity-input");
                if (qtyInput) {
                    qtyInput.focus();
                    qtyInput.select();
                }
            }, 300);
        }
    }

    function openQuickProductModal() {
        const modalEl = document.getElementById('quickProductSearchModal');
        if (!modalEl) return;
        const searchInput = document.getElementById('quickProductSearchInput');
        const results = document.getElementById('quickProductSearchResults');
        if (searchInput) {
            searchInput.value = '';
        }
        modalProductPage = 1;
        modalProductHasMore = true;
        modalProductQuery = "";
        
        $('#quickProductSearchModal').modal('show');
        fetchModalProducts(true);
        
        setTimeout(() => {
            if (searchInput) searchInput.focus();
        }, 200);
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
                const firstBtn = document.querySelector('#quickProductSearchResults button.search-result-item');
                if (firstBtn) firstBtn.click();
            }
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                const firstBtn = document.querySelector('#quickProductSearchResults button.search-result-item');
                if (firstBtn) firstBtn.focus();
            }
        });
    }

    const resultsContainer = document.getElementById('quickProductSearchResults');
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

    const barcodeScanInput = document.getElementById("barcodeScanInput");
    if (barcodeScanInput) {
        barcodeScanInput.addEventListener("keydown", function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                const barcode = this.value.trim();
                if (barcode) {
                    fetchProductByBarcode(barcode);
                }
            }
        });
    }

    function fetchProductByBarcode(barcode) {
        fetch(`../api/search_products.php?q=${encodeURIComponent(barcode)}`)
            .then(res => res.json())
            .then(products => {
                const product = products.find(p => p.barcode === barcode);
                if (product) {
                    if (window.activeSearchRow) {
                        selectProductForRow(window.activeSearchRow, product);
                    } else {
                        addProductToInvoice(product);
                    }
                } else {
                    alert("عذراً، لم يتم العثور على أي منتج بهذا الباركود (" + barcode + ")!");
                }
                if (barcodeScanInput) {
                    barcodeScanInput.value = "";
                    barcodeScanInput.focus();
                }
            })
            .catch(err => {
                console.error("Barcode lookup error:", err);
            });
    }

});

document.addEventListener('keydown', function(e) {
    if (e.key === 'F2') {
        e.preventDefault();
        const barcodeInput = document.getElementById('barcodeScanInput');
        if (document.getElementById('quickProductSearchModal')) {
            const searchInput = document.getElementById('quickProductSearchInput');
            const results = document.getElementById('quickProductSearchResults');
            if (searchInput) {
                searchInput.value = '';
                if (results) results.innerHTML = '';
            }
            $('#quickProductSearchModal').modal('show');
            setTimeout(() => {
                if (searchInput) searchInput.focus();
            }, 200);
        } else if (barcodeInput) {
            barcodeInput.focus();
            barcodeInput.select();
        }
    }
});
</script>

<?php require_once($dir_prefix . 'includes/footer.php'); ?>
