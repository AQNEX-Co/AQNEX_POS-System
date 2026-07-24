<?php
$dir_prefix = '../';
$module = 'purchases';
$no_print_header = true;
require_once($dir_prefix . 'includes/header.php');
check_permission(['admin', 'cashier']);

$settings = $global_settings;
$error = '';
$success = '';
$saved_return_id = 0;

$active_box_id = get_user_box_id($conn, $_SESSION['SESS_MEMBER_ID']);
$box_name = get_box_name($conn, $active_box_id);
$user_name = $_SESSION['SESS_FIRST_NAME'];
$active_user_id = $_SESSION['SESS_MEMBER_ID'];

// ==========================================
// معالجة تسجيل مرتجع مشتريات (Master-Detail)
// ==========================================
if (isset($_POST['btn_save_return'])) {
    $purchase_id   = intval($_POST['purchase_id']);
    $reason        = $conn->real_escape_string(trim($_POST['reason']));
    $return_date   = $conn->real_escape_string($_POST['return_date']);
    $refund_method = $_POST['refund_method'] === 'credit' ? 'credit' : 'cash';
    $refund_source = 'box';

    // جلب بيانات الفاتورة الأصلية من جدول الماستر الجديد
    $pur_res = $conn->query("SELECT * FROM purchase_invoices_mst WHERE id = $purchase_id AND d_s = 0 LIMIT 1");
    $pur_row = $pur_res ? $pur_res->fetch_assoc() : null;

    $product_ids = isset($_POST['product_ids']) ? $_POST['product_ids'] : [];
    $product_names = isset($_POST['product_names']) ? $_POST['product_names'] : [];
    $qty_returns = isset($_POST['qty_returns']) ? $_POST['qty_returns'] : [];
    $unit_prices = isset($_POST['unit_prices']) ? $_POST['unit_prices'] : [];
    $unit_names = isset($_POST['unit_names']) ? $_POST['unit_names'] : [];

    if ($purchase_id <= 0 || !$pur_row) {
        $error = 'الرجاء تحديد فاتورة المشتريات الأصلية أولاً.';
    } elseif (empty($product_ids)) {
        $error = 'لا توجد بنود قابلة للإرجاع.';
    } else {
        $supplier_id = intval($pur_row['supp_id']);
        $supplier_name = $pur_row['supp_name'];
        $currency_code = $pur_row['currency_code'] ?? 'YER';
        $exchange_rate = doubleval($pur_row['exchange_rate'] ?? 1.0);
        if ($exchange_rate <= 0) $exchange_rate = 1.0;

        $conn->begin_transaction();
        try {
            $valid_items = [];
            $total_refund_amount = 0;
            $count = count($product_ids);

            // 1. مرحلة التحقق وحساب الإجماليات
            for ($i = 0; $i < $count; $i++) {
                $product_id = intval($product_ids[$i]);
                $p_name = trim($product_names[$i]);
                $qty = intval($qty_returns[$i]);
                $unit_price = doubleval($unit_prices[$i]);
                $unit_name = isset($unit_names[$i]) ? trim($unit_names[$i]) : 'حبة';
                if (empty($unit_name)) $unit_name = 'حبة';

                if ($qty <= 0) continue;

                $refund_amount = $qty * $unit_price;
                $p_name_esc = $conn->real_escape_string($p_name);
                $unit_name_esc = $conn->real_escape_string($unit_name);

                // التحقق من الكمية المتوفرة في المخزن
                $res_prod = $conn->query("SELECT quantity FROM products WHERE id = $product_id LIMIT 1");
                $prod_qty = $res_prod ? doubleval($res_prod->fetch_assoc()['quantity']) : 0;

                // التحقق من الكمية المرجعة سابقاً من هذه الفاتورة (من الجداول الجديدة)
                $res_chk = $conn->query("SELECT COALESCE(SUM(d.quantity), 0) AS ret 
                                         FROM purchase_returns_dtl d 
                                         JOIN purchase_returns_mst m ON d.return_id = m.id 
                                         WHERE m.original_purchase_id = $purchase_id 
                                         AND d.product_id = $product_id 
                                         AND m.d_s = 0");
                $already_ret = $res_chk ? doubleval($res_chk->fetch_assoc()['ret']) : 0;

                // جلب الكمية المشتراة في الفاتورة الأصلية
                $p_name_store = $p_name;
                if (!empty($unit_name) && $unit_name !== 'حبة' && strpos($p_name_store, "($unit_name)") === false) {
                    $p_name_store .= " ($unit_name)";
                }
                $p_name_store_esc = $conn->real_escape_string($p_name_store);
                
                $res_orig = $conn->query("SELECT quantity FROM purchase_invoices_dtl WHERE invoice_id = $purchase_id AND product_name = '$p_name_store_esc' LIMIT 1");
                if (!$res_orig || $res_orig->num_rows == 0) {
                     $res_orig = $conn->query("SELECT quantity FROM purchase_invoices_dtl WHERE invoice_id = $purchase_id AND product_id = $product_id LIMIT 1");
                }
                $orig_qty = $res_orig ? doubleval($res_orig->fetch_assoc()['quantity']) : 0;
                
                $can_return = $orig_qty - $already_ret;

                if ($qty > $can_return) {
                    throw new Exception("لا يمكن إرجاع {$qty} وحدة من المنتج \"{$p_name}\". المتبقي القابل للإرجاع هو {$can_return} وحدة.");
                }

                // حساب كمية المرتجع بالوحدات الأساسية
                $conv_factor = 1.0;
                if (!empty($unit_name) && $unit_name !== 'حبة') {
                    $u_res = $conn->query("SELECT conversion_factor FROM product_units WHERE product_id = $product_id AND unit_name = '$unit_name_esc' LIMIT 1");
                    if ($u_res && $u_res->num_rows > 0) {
                        $conv_factor = doubleval($u_res->fetch_assoc()['conversion_factor']);
                    }
                }
                $base_qty = $qty * $conv_factor;

                if ($base_qty > $prod_qty) {
                    throw new Exception("لا يمكن إرجاع الكمية المطلوبة من الصنف \"{$p_name}\". المتوفر بالمستودع حالياً هو {$prod_qty} وحدة أساسية (المطلوب سحبه: {$base_qty}).");
                }

                $valid_items[] = [
                    'product_id' => $product_id,
                    'product_name' => $p_name_esc,
                    'unit_name' => $unit_name_esc,
                    'quantity' => $qty,
                    'unit_price' => $unit_price,
                    'refund_amount' => $refund_amount,
                    'base_qty' => $base_qty
                ];
                $total_refund_amount += $refund_amount;
            }

            if (empty($valid_items)) {
                throw new Exception("الرجاء إدخال كمية مرتجعة أكبر من الصفر لصنف واحد على الأقل.");
            }

            // 2. إدراج رأس المرتجع (Master)
            $return_no = 'PR-' . date('Y') . '-' . str_pad(rand(1000, 9999), 4, '0', STR_PAD_LEFT);
            $sql_mst = "INSERT INTO `purchase_returns_mst` 
                (`return_no`, `original_purchase_id`, `supp_id`, `supp_name`, `return_date`, `total_amount`, `refund_method`, `box_id`, `currency_code`, `exchange_rate`, `reason`, `user_id`, `sector_id`, `d_s`) 
                VALUES ('$return_no', $purchase_id, $supplier_id, '$supplier_name', '$return_date', $total_refund_amount, '$refund_method', $active_box_id, '$currency_code', $exchange_rate, '$reason', $active_user_id, NULL, 0)";
            
            if (!$conn->query($sql_mst)) {
                throw new Exception('فشل حفظ رأس مرتجع المشتريات: ' . $conn->error);
            }
            $return_id = $conn->insert_id;

            // 3. معالجة التفاصيل وتحديث المخزون والحسابات
            foreach ($valid_items as $item) {
                // إدراج تفصيل المرتجع
                $sql_dtl = "INSERT INTO `purchase_returns_dtl` 
                    (`return_id`, `product_id`, `product_name`, `quantity`, `unit_cost`, `total_cost`, `d_s`) 
                    VALUES ($return_id, {$item['product_id']}, '{$item['product_name']}', {$item['quantity']}, {$item['unit_price']}, {$item['refund_amount']}, 0)";
                
                if (!$conn->query($sql_dtl)) {
                    throw new Exception('فشل حفظ تفصيل مرتجع المشتريات: ' . $conn->error);
                }

                // تحديث المخزون
                if (!$conn->query("UPDATE products SET quantity = GREATEST(0, quantity - {$item['base_qty']}), total = quantity * buy_price WHERE id = {$item['product_id']}")) {
                    throw new Exception('فشل تحديث كمية المنتج في المخزن: ' . $conn->error);
                }

                // سجل حركة المخزون
                if (!$conn->query("INSERT INTO inventory_log (product_id, product_name, type, qty_change, new_qty, reason, user)
                              SELECT id, name, 'return', -{$item['base_qty']}, quantity, 'مرتجع شراء فاتورة #$return_no', '$user_name'
                              FROM products WHERE id = {$item['product_id']} LIMIT 1")) {
                    throw new Exception('فشل إضافة سجل حركة المخزون: ' . $conn->error);
                }

                // التحديثات المالية والصندوقية
                if ($refund_method === 'cash') {
                    if ($item['refund_amount'] > 0) {
                        update_box_balance($conn, $active_box_id, $item['refund_amount'], 'addition', "مرتجع شراء فاتورة #$return_no - {$item['product_name']}", $return_date);
                    }
                    $debit_acc = 'الصندوق - ' . $box_name;
                    if (!post_journal_entry($conn, 'return', $return_id, $debit_acc, 'المخزون / البضاعة', $item['refund_amount'], "مرتجع مشتريات نقداً #$return_no - {$item['product_name']}", $user_name, $active_box_id)) {
                        throw new Exception('فشل تسجيل قيد مرتجع المشتريات نقداً');
                    }
                } else {
                    if ($item['refund_amount'] > 0 && $supplier_id > 0) {
                        if (!$conn->query("UPDATE suppliers SET supp_daain = GREATEST(0, supp_daain - {$item['refund_amount']}) WHERE supp_id = $supplier_id")) {
                            throw new Exception('فشل تحديث رصيد المورد: ' . $conn->error);
                        }
                    }
                    if (!post_journal_entry($conn, 'return', $return_id, 'الذمم الدائنة - ' . $supplier_name, 'المخزون / البضاعة', $item['refund_amount'], "مرتجع مشتريات آجل (خصم مديونية) #$return_no - {$item['product_name']}", $user_name, $active_box_id)) {
                        throw new Exception('فشل تسجيل قيد مرتجع المشتريات آجل');
                    }
                }
            }

            // 4. تحديث الرصيد المتبقي للفاتورة الأصلية
            if ($total_refund_amount > 0) {
                $conn->query("UPDATE purchase_invoices_mst SET remaining_total = GREATEST(0, remaining_total - $total_refund_amount) WHERE id = $purchase_id");
            }

            $conn->commit();
            $success = '✓ تم تسجيل مردود المشتريات وتحديث المخازن والحسابات بنجاح (رقم المرتجع: ' . $return_no . ').';
        } catch (Exception $e) {
            $conn->rollback();
            $error = 'فشل تسجيل المرتجع: ' . $e->getMessage();
        }
    }
}

// ==========================================
// معالجة إلغاء مرتجع مشتريات
// ==========================================
if (isset($_GET['cancel_ret']) && is_numeric($_GET['cancel_ret'])) {
    $ret_id = intval($_GET['cancel_ret']);
    
    // جلب بيانات المرتجع من جدول الماستر الجديد
    $res_ret = $conn->query("SELECT * FROM purchase_returns_mst WHERE id = $ret_id AND d_s = 0 LIMIT 1");
    $ret_row = $res_ret ? $res_ret->fetch_assoc() : null;

    if ($ret_row) {
        $conn->begin_transaction();
        try {
            // الحذف الناعم (إلغاء)
            if (!$conn->query("UPDATE purchase_returns_mst SET d_s = 1 WHERE id = $ret_id")) {
                throw new Exception("فشل إلغاء حركة المرتجع");
            }

            $p_id          = intval($ret_row['original_purchase_id']);
            $total_refund  = doubleval($ret_row['total_amount']);
            $refund_method = $ret_row['refund_method'];
            $box_id        = intval($ret_row['box_id']);
            $box_name      = get_box_name($conn, $box_id);
            $uname         = $conn->real_escape_string($_SESSION['SESS_FIRST_NAME']);
            $today_date    = date("Y-m-d");
            $return_no     = $ret_row['return_no'];

            // جلب المورد الأصلي
            $pur_check = $conn->query("SELECT supp_id, supp_name FROM purchase_invoices_mst WHERE id = $p_id LIMIT 1");
            $supplier_id = $pur_check ? intval($pur_check->fetch_assoc()['supp_id']) : 0;
            $supplier_name = $pur_check ? $pur_check->fetch_assoc()['supp_name'] : '';

            // جلب تفاصيل المرتجع لعكس حركات المخزون
            $res_dtl = $conn->query("SELECT * FROM purchase_returns_dtl WHERE return_id = $ret_id AND d_s = 0");
            while ($dtl = $res_dtl->fetch_assoc()) {
                $product_id = intval($dtl['product_id']);
                $qty = doubleval($dtl['quantity']);
                
                // جلب اسم الوحدة وعامل التحويل من الفاتورة الأصلية
                $res_inv_dtl = $conn->query("SELECT unit_name FROM purchase_invoices_dtl WHERE invoice_id = $p_id AND product_id = $product_id LIMIT 1");
                $inv_unit_name = $res_inv_dtl ? ($res_inv_dtl->fetch_assoc()['unit_name'] ?: 'حبة') : 'حبة';
                
                $conv_factor = 1.0;
                if (!empty($inv_unit_name) && $inv_unit_name !== 'حبة') {
                    $unit_res = $conn->query("SELECT conversion_factor FROM product_units WHERE product_id = $product_id AND unit_name = '" . $conn->real_escape_string($inv_unit_name) . "' LIMIT 1");
                    if ($unit_res && $unit_res->num_rows > 0) {
                        $conv_factor = doubleval($unit_res->fetch_assoc()['conversion_factor']);
                    }
                }
                $base_qty = $qty * $conv_factor;

                // إعادة الكمية للمخزن
                if (!$conn->query("UPDATE products SET quantity = quantity + $base_qty, total = quantity * buy_price WHERE id = $product_id")) {
                    throw new Exception("فشل إعادة الكمية للمخزن");
                }

                // سجل حركة المخزون
                if (!$conn->query("INSERT INTO inventory_log (product_id, product_name, type, qty_change, new_qty, reason, user)
                              SELECT id, name, 'return_cancel', $base_qty, quantity, 'إلغاء مرتجع شراء #$return_no', '$uname'
                              FROM products WHERE id = $product_id LIMIT 1")) {
                    throw new Exception("فشل إضافة سجل حركة المخزون");
                }
            }

            // عكس الحركات المالية والصندوقية
            if ($refund_method === 'cash') {
                update_box_balance($conn, $box_id, $total_refund, 'discount', "إلغاء مرتجع شراء #$return_no", $today_date);
                $debit_acc = 'الصندوق - ' . $box_name;
                if (!post_journal_entry($conn, 'return', $ret_id, 'المخزون / البضاعة', $debit_acc, $total_refund, "إلغاء مرتجع شراء نقدي #$return_no", $uname, $box_id)) {
                    throw new Exception("فشل تسجيل قيد إلغاء مرتجع شراء نقدي");
                }
            } else {
                if ($total_refund > 0 && $supplier_id > 0) {
                    if (!$conn->query("UPDATE suppliers SET supp_daain = supp_daain + $total_refund WHERE supp_id = $supplier_id")) {
                        throw new Exception("فشل إعادة مديونية المورد");
                    }
                }
                if (!post_journal_entry($conn, 'return', $ret_id, 'المخزون / البضاعة', 'الذمم الدائنة - ' . $supplier_name, $total_refund, "إلغاء مرتجع شراء آجل #$return_no", $uname, $box_id)) {
                    throw new Exception("فشل تسجيل قيد إلغاء مرتجع شراء آجل");
                }
            }

            // استعادة الرصيد المتبقي للفاتورة الأصلية
            if ($total_refund > 0) {
                $conn->query("UPDATE purchase_invoices_mst SET remaining_total = remaining_total + $total_refund WHERE id = $p_id");
            }

            $conn->commit();
            $success = '✓ تم إلغاء مرتجع الشراء #' . $ret_id . ' بنجاح وإعادة الكمية للمخزون.';
        } catch (Exception $e) {
            $conn->rollback();
            $error = 'فشل إلغاء المرتجع: ' . $e->getMessage();
        }
    }
}

// جلب آخر فواتير المشتريات للبحث السريع (من الجدول الجديد)
$recent_purchases = [];
$res_pur_list = $conn->query("SELECT id, supp_name, total_amount as total, invoice_date as date FROM purchase_invoices_mst WHERE d_s = 0 ORDER BY id DESC LIMIT 200");
if ($res_pur_list) {
    while($r = $res_pur_list->fetch_assoc()) $recent_purchases[] = $r;
}

// جلب سجل المردودات الكامل (بدمج الماستر والتفصيل)
$all_returns = [];
$res_all_ret = $conn->query("SELECT 
    m.id, 
    m.return_no, 
    m.original_purchase_id, 
    m.total_amount as refund_amount,
    m.return_date, 
    m.d_s as status, 
    m.refund_method,
    GROUP_CONCAT(d.product_name SEPARATOR ' | ') as product_name,
    SUM(d.quantity) as quantity,
    COUNT(d.id) as items_count
    FROM purchase_returns_mst m
    LEFT JOIN purchase_returns_dtl d ON m.id = d.return_id AND d.d_s = 0
    WHERE m.d_s IN (0, 1)
    GROUP BY m.id
    ORDER BY m.id DESC 
    LIMIT 150");
if ($res_all_ret) {
    while($r = $res_all_ret->fetch_assoc()) $all_returns[] = $r;
}
?>
<title>فاتورة مردودات المشتريات</title>

<style>
@media print {
    #sidebar, .navbar-top, .no-print, .btn-flat, hr, .card-header { display: none !important; }
    #content { margin: 0 !important; padding: 0 !important; }
    body { background: #fff !important; }
}
.ret-card { border: 1px solid var(--border-color); }
.search-invoice-input { font-size: 1.15rem; font-weight: bold; }
.invoice-badge { padding: 3px 8px; font-size: 0.78rem; font-weight: bold; }
</style>

<!-- AQNEX System Window Header Bar -->
<div class="aqnex-window-header no-print">
    <div>
        <i class="bi bi-arrow-return-left text-danger ml-1"></i>
        <span>أنظمة المشتريات - المردودات والمرتجعات - فاتورة مرتجع مشتريات</span>
    </div>
    <div>
        <span class="ml-3">المستخدم: <strong><?php echo htmlspecialchars($_SESSION['SESS_FIRST_NAME'] ?? 'مدير النظام'); ?></strong></span>
        <span>التاريخ: <strong><?php echo date('Y/m/d'); ?></strong></span>
    </div>
</div>

<!-- AQNEX Action Toolbar -->
<div class="aqnex-toolbar no-print">
    <div style="display: flex; align-items: center; gap: 5px;">
        <!-- ➕ جديد (F2) -->
        <button type="button" class="tool-btn btn-new" title="جديد (F2)" onclick="window.location.reload();">
            <i class="bi bi-plus-circle-fill"></i>
        </button>

        <!-- 💾 حفظ المرتجع (F10) -->
        <button type="submit" form="returnForm" name="btn_save_return" class="tool-btn btn-save btn-save-action" title="حفظ وتأكيد المرتجع (F10)">
            <i class="bi bi-check-circle-fill"></i>
        </button>

        <!-- 🔍 البحث عن فاتورة مشتريات -->
        <button type="button" class="tool-btn btn-search" title="البحث في المشتريات (F3)" onclick="window.location.href='index.php';">
            <i class="bi bi-search"></i>
        </button>

        <!-- 🗑 حذف وتصفية -->
        <button type="button" class="tool-btn btn-delete" title="تصفية البيانات" onclick="if(confirm('هل أنت متأكد من تصفية البيانات؟')) window.location.reload();">
            <i class="bi bi-trash3-fill"></i>
        </button>

        <!-- 🔄 تراجع -->
        <button type="button" class="tool-btn" title="تراجع وتصفية" onclick="window.location.reload();">
            <i class="bi bi-arrow-counterclockwise" style="color: #0284c7;"></i>
        </button>
    </div>

    <!-- أزرار الجانب الأيسر -->
    <div style="margin-right: auto; display: flex; align-items: center; gap: 5px;">
        <!-- 🖨 طباعة (F9) -->
        <button type="button" class="tool-btn btn-print" title="طباعة (F9)" onclick="window.print();">
            <i class="bi bi-printer-fill"></i>
        </button>
    </div>
</div>

<?php if (!empty($success)): ?>
    <div class="alert alert-success rounded-0 mb-4 text-right no-print"><?php echo htmlspecialchars($success); ?></div>
<?php endif; ?>
<?php if (!empty($error)): ?>
    <div class="alert alert-danger rounded-0 mb-4 text-right no-print"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<form method="POST" id="returnForm" class="no-print">
    <input type="hidden" name="purchase_id" id="ret_purchase_id" value="0">
    
    <div class="card p-3 mb-4 text-right" dir="rtl">
        <div class="row">
            <div class="col-md-3 mb-3">
                <label class="form-label font-weight-bold text-secondary">رقم فاتورة المشتريات للبحث *</label>
                <div class="input-group">
                    <input type="number" id="invoiceSearchInput" class="form-control rounded-0 font-weight-bold text-center border-primary" style="font-size: 1.1rem;" placeholder="أدخل رقم الفاتورة...">
                    <div class="input-group-append">
                        <button type="button" class="btn btn-primary rounded-0 px-3" onclick="searchInvoice()">
                            <i class="fa fa-search ml-1"></i> بحث
                        </button>
                    </div>
                </div>
                <small class="text-muted">اضغط <strong>F2</strong> لاختيار الفاتورة من القائمة.</small>
            </div>

            <div class="col-md-2 mb-3">
                <label class="form-label font-weight-bold text-secondary">تاريخ المردود</label>
                <input type="date" name="return_date" class="form-control rounded-0 font-weight-bold text-center" value="<?php echo date('Y-m-d'); ?>" required>
            </div>

            <div class="col-md-3 mb-3">
                <label class="form-label font-weight-bold text-secondary">المورد</label>
                <input type="text" id="invSuppDisplay" class="form-control rounded-0 font-weight-bold text-center bg-light" readonly value="-">
            </div>

            <div class="col-md-2 mb-3">
                <label class="form-label font-weight-bold text-secondary">تاريخ الفاتورة</label>
                <input type="text" id="invDateDisplay" class="form-control rounded-0 font-weight-bold text-center bg-light" readonly value="-">
            </div>

            <div class="col-md-2 mb-3">
                <label class="form-label font-weight-bold text-secondary">الإجمالي الأصلي</label>
                <input type="text" id="invTotalDisplay" class="form-control rounded-0 font-weight-bold text-center bg-light text-primary" readonly value="-">
            </div>
        </div>

        <div class="row" id="refundOptionsRow" style="display:none; border-top: 1px solid #eee; padding-top: 15px;">
            <div class="col-md-4 mb-3">
                <label class="form-label font-weight-bold text-secondary">طريقة رد القيمة من المورد *</label>
                <select name="refund_method" id="refund_method" class="form-control rounded-0 font-weight-bold" required>
                    <option value="cash" selected>استلام نقدي (تسليم فوري للصندوق)</option>
                    <option value="credit">آجل (خصم من مديونية المورد)</option>
                </select>
            </div>

            <div class="col-md-4 mb-3">
                <label class="form-label font-weight-bold text-secondary">سبب الاسترجاع للمورد *</label>
                <select name="reason" class="form-control rounded-0 font-weight-bold" required>
                    <option value="">-- اختر السبب --</option>
                    <option value="منتج تالف / معيب من المصنع">منتج تالف / معيب من المصنع</option>
                    <option value="شحنة خاطئة / لا تطابق المطلوب">شحنة خاطئة / لا تطابق المطلوب</option>
                    <option value="انتهاء تاريخ الصلاحية أو الجودة">انتهاء تاريخ الصلاحية أو الجودة</option>
                    <option value="فائض عن حاجة المستودع">فائض عن حاجة المستودع</option>
                </select>
            </div>

            <div class="col-md-4 mb-3">
                <label class="form-label font-weight-bold text-secondary">الصندوق المستلم إليه</label>
                <input type="text" class="form-control rounded-0 font-weight-bold text-center bg-light" readonly value="<?php echo htmlspecialchars($box_name); ?>">
            </div>
        </div>
    </div>

    <div id="invoiceItemsSection" style="display:none;">
        <div class="card-flat ret-card mb-4">
            <div class="card-header bg-light">
                <h5 class="mb-0 text-dark font-weight-bold"><i class="fa fa-list ml-2 text-primary"></i> جدول الأصناف الفعلي بالفاتورة والكميات المسترجعة</h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table-flat mb-0" id="invoiceItemsTable">
                        <thead>
                            <tr>
                                <th>اسم الصنف والوصف</th>
                                <th style="width: 12%;" class="text-center">الوحدة</th>
                                <th style="width: 12%;" class="text-center">الكمية المشتراة</th>
                                <th style="width: 15%;" class="text-center">القابلة للإرجاع</th>
                                <th style="width: 15%;" class="text-center">سعر الشراء بالفاتورة</th>
                                <th style="width: 15%;" class="text-center">كمية المرتجع</th>
                                <th style="width: 15%;" class="text-center">إجمالي الارتجاع</th>
                            </tr>
                        </thead>
                        <tbody id="invoiceItemsBody">
                            <tr><td colspan="7" class="text-center text-muted p-4">الرجاء اختيار الفاتورة أولاً لعرض الأصناف</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="row justify-content-end mb-4">
            <div class="col-md-5">
                <div class="card bg-light border-0 p-4 rounded-0 text-center">
                    <h5 class="text-secondary font-weight-bold mb-3">الملخص المالي للمردودات</h5>
                    <div style="font-size: 2rem;" class="font-weight-bold text-danger mb-3" id="grand_return_total_display">
                        0.00 <span style="font-size: 1.1rem;">ر.ي</span>
                    </div>
                    <div class="mt-4 no-print text-center">
                        <button type="submit" name="btn_save_return" class="btn-flat btn-flat-primary btn-lg px-5">
                            <?php echo get_icon('check', 'ml-1'); ?> حفظ الفاتورة وترحيلها
                        </button>
                        <button type="submit" name="btn_save_return" class="btn-flat btn-flat-primary btn-sm">
                            <!-- <i class="fa fa-check ml-2"></i> تأكيد وإعتماد الفاتورة للمورد -->
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</form>

<div id="invoiceReturnsSection" style="display:none;" class="card-flat ret-card mt-4 no-print text-right">
    <div class="card-header bg-warning">
        <h6 class="mb-0 font-weight-bold text-dark"><i class="fa fa-history ml-2"></i> عمليات مردودات سابقة مسجلة على هذه الفاتورة</h6>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table-flat mb-0">
                <thead>
                    <tr>
                        <th style="width:10%;">رقم المرتجع</th>
                        <th>الصنف</th>
                        <th style="width:15%;" class="text-center">الكمية المرجعة</th>
                        <th style="width:20%;">المبلغ المسترد</th>
                        <th style="width:15%;" class="text-center">إجراء</th>
                    </tr>
                </thead>
                <tbody id="prevReturnsBody"></tbody>
            </table>
        </div>
    </div>
</div>

<div class="card-flat ret-card mt-4 no-print text-right">
    <div class="card-header bg-light">
        <h5 class="mb-0 text-dark font-weight-bold"><i class="fa fa-history ml-2"></i> آخر عمليات مردودات المشتريات بالنظام</h5>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table-flat border mb-0">
                <thead>
                    <tr>
                        <th style="width:8%;">رقم المرتجع</th>
                        <th style="width:12%;">رقم الفاتورة الأصلية</th>
                        <th>المنتج المرتجع والوصف</th>
                        <th style="width:10%;" class="text-center">الكمية</th>
                        <th style="width:15%;">المبلغ المسترد</th>
                        <th style="width:10%;">الحالة</th>
                        <th style="width:12%;">تاريخ الاسترجاع</th>
                        <th style="width:10%;" class="no-print text-center">إلغاء المرتجع</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($all_returns)): ?>
                        <tr><td colspan="8" class="text-center text-muted p-4">لا توجد عمليات مردودات مشتريات مسجلة بالنظام حالياً.</td></tr>
                    <?php else: ?>
                        <?php foreach ($all_returns as $r): 
                            $is_cancelled = ($r['status'] == 1);
                        ?>
                        <tr class="<?php echo $is_cancelled ? 'text-muted' : ''; ?>" style="<?php echo $is_cancelled ? 'opacity:0.6; text-decoration:line-through;' : ''; ?>">
                            <td class="font-weight-bold text-secondary">#<?php echo $r['id']; ?></td>
                            <td>
                                <a href="view.php?id=<?php echo $r['original_purchase_id']; ?>" class="text-primary font-weight-bold text-decoration-none">
                                    #<?php echo $r['original_purchase_id']; ?>
                                </a>
                            </td>
                            <td class="small font-weight-bold"><?php echo htmlspecialchars($r['product_name']); ?></td>
<td class="text-center text-danger font-weight-bold">
    <?php echo intval($r['quantity']); ?>
    <?php if ($r['items_count'] > 1): ?>
        <small class="text-muted d-block">(<?php echo $r['items_count']; ?> أصناف)</small>
    <?php endif; ?>
</td>                            <td class="font-weight-bold"><?php echo number_format($r['refund_amount'], 2); ?> YER</td>
                            <td>
                                <?php if (!$is_cancelled): ?>
                                    <span class="badge badge-success invoice-badge">نشط</span>
                                <?php else: ?>
                                    <span class="badge badge-secondary invoice-badge">ملغى</span>
                                <?php endif; ?>
                            </td>
                            <td class="small"><?php echo htmlspecialchars($r['return_date']); ?></td>
                            <td class="no-print text-center">
                                <?php if (!$is_cancelled): ?>
                                <a href="returns.php?cancel_ret=<?php echo $r['id']; ?>" onclick="return confirm('هل تريد إلغاء هذا المرتجع للمورد؟ سيتم إعادة البضاعة للمخزن وعكس الحركات المالية.')" class="btn-flat btn-flat-danger btn-sm py-1 px-2 text-decoration-none">
                                    <i class="fa fa-times ml-1"></i> إلغاء
                                </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
document.getElementById('invoiceSearchInput').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') { e.preventDefault(); searchInvoice(); }
});
document.getElementById('returnForm').addEventListener('submit', function(e) {
    const purchaseId = parseInt(document.getElementById('ret_purchase_id').value) || 0;
    if (purchaseId <= 0) {
        e.preventDefault();
        alert('الرجاء اختيار فاتورة المشتريات أولاً.');
        return;
    }

    let hasQty = false;
    document.querySelectorAll('.qty-return-input').forEach(input => {
        if ((parseInt(input.value) || 0) > 0) hasQty = true;
    });

    if (!hasQty) {
        e.preventDefault();
        alert('الرجاء إدخال كمية مرتجعة أكبر من صفر لصنف واحد على الأقل.');
        return;
    }

    if (!confirm('هل أنت متأكد من اعتماد فاتورة المرتجع؟ لا يمكن التراجع عن هذه العملية إلا بإلغائها لاحقاً.')) {
        e.preventDefault();
    }
});

function searchInvoice() {
    const invId = parseInt(document.getElementById('invoiceSearchInput').value);
    if (!invId || invId <= 0) {
        alert('الرجاء إدخال رقم فاتورة صحيح.');
        return;
    }

    fetch(`ajax_invoice.php?invoice_id=${invId}`)
        .then(r => r.json())
        .then(data => {
            if (data.error) {
                alert('خطأ: ' + data.error);
                return;
            }
            displayInvoiceData(data);
        })
        .catch(err => {
            alert('خطأ في الاتصال: ' + err.message);
        });
}

function displayInvoiceData(data) {
    const inv = data.invoice;
    const items = data.items;
    const prevReturns = data.returns_history || [];

    document.getElementById('ret_purchase_id').value = inv.id;
    document.getElementById('invSuppDisplay').value = inv.supp_name || 'غير محدد';
    document.getElementById('invDateDisplay').value = inv.date;
    document.getElementById('invTotalDisplay').value = formatNum(inv.total) + ' YER';

    const tbody = document.getElementById('invoiceItemsBody');
    tbody.innerHTML = '';

    if (items.length === 0) {
        tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted p-3">لا توجد بنود في هذه الفاتورة</td></tr>';
    } else {
        items.forEach(item => {
            const canReturn = item.can_return;
            const disabledClass = canReturn <= 0 ? 'text-muted' : 'font-weight-bold text-success';
            const readonlyAttr  = canReturn <= 0 ? 'readonly style="background:#f1f1f1;"' : '';

            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td class="font-weight-bold small text-right">
                    ${escHtml(item.name)}
                    <input type="hidden" name="product_ids[]" value="${item.product_id}">
                    <input type="hidden" name="product_names[]" value="${escHtml(item.name)}">
                    <input type="hidden" name="unit_prices[]" value="${item.unit_price}">
                    <input type="hidden" name="unit_names[]" value="${escHtml(item.unit_name || 'حبة')}">
                </td>
                <td class="text-center small">${escHtml(item.unit_name || 'حبة')}</td>
                <td class="text-center">${item.quantity}</td>
                <td class="text-center ${disabledClass}">${canReturn}</td>
                <td class="text-center small">${formatNum(item.unit_price)} YER</td>
                <td class="text-center no-print">
                    <input type="number" name="qty_returns[]" class="form-control text-center rounded-0 qty-return-input font-weight-bold"
                           style="max-width: 90px; margin: 0 auto; height: 32px;"
                           min="0" max="${canReturn}" value="0" ${readonlyAttr}
                           oninput="calcLineTotal(this, ${item.unit_price})">
                </td>
                <td class="text-center line-total-display font-weight-bold text-danger">0.00 YER</td>
            `;
            tbody.appendChild(tr);
        });
    }

    document.getElementById('refundOptionsRow').style.display = 'flex';
    document.getElementById('invoiceItemsSection').style.display = 'block';

    const prevBody = document.getElementById('prevReturnsBody');
    prevBody.innerHTML = '';
    if (prevReturns.length > 0) {
        prevReturns.forEach(r => {
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td class="text-secondary font-weight-bold">#${r.id}</td>
                <td class="small">${escHtml(r.product)}</td>
                <td class="text-center text-danger font-weight-bold">${r.qty}</td>
                <td class="font-weight-bold">${formatNum(r.refund)} YER</td>
                <td class="text-center">
                    <a href="returns.php?cancel_ret=${r.id}" onclick="return confirm('إلغاء هذا المرتجع؟')" class="btn-flat btn-flat-danger btn-sm py-1 px-2 text-decoration-none">
                        <i class="fa fa-times ml-1"></i> إلغاء
                    </a>
                </td>
            `;
            prevBody.appendChild(tr);
        });
        document.getElementById('invoiceReturnsSection').style.display = 'block';
    } else {
        document.getElementById('invoiceReturnsSection').style.display = 'none';
    }
    calcGrandTotal();
}

function calcLineTotal(input, price) {
    const qty = parseInt(input.value) || 0;
    const maxVal = parseInt(input.max) || 0;
    if (qty < 0) input.value = 0;
    if (qty > maxVal) input.value = maxVal;
    
    const validQty = Math.min(Math.max(0, qty), maxVal);
    const lineTotal = validQty * price;
    const tr = input.closest('tr');
    tr.querySelector('.line-total-display').textContent = formatNum(lineTotal) + ' YER';
    
    calcGrandTotal();
}

function calcGrandTotal() {
    let grandTotal = 0;
    document.querySelectorAll('.qty-return-input').forEach(input => {
        const qty = parseInt(input.value) || 0;
        const price = parseFloat(input.closest('tr').querySelector('input[name="unit_prices[]"]').value) || 0;
        grandTotal += qty * price;
    });
    
    document.getElementById('grand_return_total_display').innerHTML = formatNum(grandTotal) + ' <span style="font-size: 1.1rem;">YER</span>';
}

function formatNum(n) {
    return parseFloat(n).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
}

function escHtml(str) {
    const d = document.createElement('div');
    d.textContent = str;
    return d.innerHTML;
}
</script>

<!-- مودال اختيار الفاتورة (نفس الكود السابق) -->
<div class="modal fade mt-3" id="invoiceListModal" tabindex="-1" role="dialog" aria-labelledby="invoiceListModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content rounded-0">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title font-weight-bold" id="invoiceListModalLabel">
                    <i class="fa fa-list ml-2"></i> نافذة اختيار فاتورة مشتريات
                </h5>
                <button type="button" class="close text-white rounded" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true" class="p-2">&times;</span>
                </button>
            </div>
            <div class="modal-body text-right" dir="rtl">
                <div class="form-group mb-3">
                    <label class="font-weight-bold text-secondary mb-1">ابحث باسم المورد أو رقم الفاتورة:</label>
                    <input type="text" id="modalInvoiceSearchInput" class="form-control rounded-0 font-weight-bold text-right" placeholder="ابحث...">
                </div>
                <div class="table-responsive" style="max-height: 350px; overflow-y: auto;">
                    <table class="table-flat mb-0">
                        <thead>
                            <tr>
                                <th style="width: 15%;">رقم الفاتورة</th>
                                <th>المورد</th>
                                <th style="width: 25%;">التاريخ</th>
                                <th style="width: 20%;">الإجمالي (ر.ي)</th>
                                <th style="width: 15%;" class="text-center">تحديد</th>
                            </tr>
                        </thead>
                        <tbody id="modalInvoicesTableBody">
                            <tr><td colspan="5" class="text-center text-muted p-3">جاري تحميل الفواتير...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('keydown', function(e) {
    if (e.key === 'F2') {
        e.preventDefault();
        openInvoiceLookupModal();
    }
});

function openInvoiceLookupModal() {
    if (typeof $ !== 'undefined' && $.fn.modal) {
        $('#invoiceListModal').modal('show');
    } else {
        var modal = document.getElementById('invoiceListModal');
        if (modal) {
            modal.classList.add('show');
            modal.style.display = 'block';
            document.body.classList.add('modal-open');
            var backdrop = document.createElement('div');
            backdrop.className = 'modal-backdrop fade show';
            document.body.appendChild(backdrop);
        }
    }
    loadModalInvoices('');
}

function loadModalInvoices(search) {
    const tbody = document.getElementById('modalInvoicesTableBody');
    tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted p-3">جاري البحث...</td></tr>';
    
    fetch(`ajax_invoice.php?action=list&search=${encodeURIComponent(search)}`)
        .then(r => r.json())
        .then(data => {
            tbody.innerHTML = '';
            const list = data.invoices || [];
            if (list.length === 0) {
                tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted p-3">لم يتم العثور على أي فواتير مطابقة</td></tr>';
                return;
            }
            list.forEach(inv => {
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td class="font-weight-bold">#${inv.id}</td>
                    <td class="text-right font-weight-bold text-secondary">${escHtml(inv.supp_name)}</td>
                    <td class="small">${inv.date}</td>
                    <td class="font-weight-bold">${formatNum(inv.total)} YER</td>
                    <td class="text-center">
                        <button type="button" class="btn-flat btn-flat-primary btn-sm py-1 px-2 text-decoration-none" onclick="selectModalInvoice(${inv.id})">
                            <i class="bi bi-check-square"></i>
                        </button>
                    </td>
                `;
                tbody.appendChild(tr);
            });
        })
        .catch(err => {
            tbody.innerHTML = `<tr><td colspan="5" class="text-center text-danger p-3">فشل جلب البيانات: ${err.message}</td></tr>`;
        });
}

function selectModalInvoice(id) {
    document.getElementById('invoiceSearchInput').value = id;
    if (typeof $ !== 'undefined' && $.fn.modal) {
        $('#invoiceListModal').modal('hide');
    } else {
        var modal = document.getElementById('invoiceListModal');
        if (modal) {
            modal.classList.remove('show');
            modal.style.display = 'none';
            document.body.classList.remove('modal-open');
            var backdrops = document.querySelectorAll('.modal-backdrop');
            backdrops.forEach(b => b.remove());
        }
    }
    searchInvoice();
}

document.getElementById('modalInvoiceSearchInput').addEventListener('input', function() {
    loadModalInvoices(this.value);
});
</script>

<?php require_once($dir_prefix . 'includes/footer.php'); ?>