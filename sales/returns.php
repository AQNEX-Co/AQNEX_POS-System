<?php
$dir_prefix = '../';
$module = 'sales';
$no_print_header = true;
require_once($dir_prefix . 'includes/header.php');
check_permission(['admin', 'cashier']);

$settings = $global_settings;
$error = '';
$success = '';
$saved_return_id = 0;
$journal_entries = [];
$box_deduction_details = [];

// ==========================================
// معالجة تسجيل مرتجع متعدد الأصناف
// ==========================================
if (isset($_POST['btn_save_return'])) {
    $sales_id      = intval($_POST['sales_id']);
    $reason        = $conn->real_escape_string(trim($_POST['reason']));
    $return_date   = $conn->real_escape_string($_POST['return_date']);
    $user_name     = $conn->real_escape_string($_SESSION['SESS_FIRST_NAME']);
    $refund_method = $_POST['refund_method'] === 'credit' ? 'credit' : 'cash';
    
    $refund_source = isset($_POST['refund_source']) ? $_POST['refund_source'] : 'box';
    if (!in_array($refund_source, ['box', 'sales'])) {
        $refund_source = 'box';
    }

    $active_box_id = get_user_box_id($conn, $_SESSION['SESS_MEMBER_ID']);
    $box_name = get_box_name($conn, $active_box_id);

    $product_ids = isset($_POST['product_ids']) ? $_POST['product_ids'] : [];
    $product_names = isset($_POST['product_names']) ? $_POST['product_names'] : [];
    $qty_returns = isset($_POST['qty_returns']) ? $_POST['qty_returns'] : [];
    $unit_prices = isset($_POST['unit_prices']) ? $_POST['unit_prices'] : [];
    $buy_prices = isset($_POST['buy_prices']) ? $_POST['buy_prices'] : [];
    $unit_names = isset($_POST['unit_names']) ? $_POST['unit_names'] : [];

    if ($sales_id <= 0) {
        $error = 'الرجاء تحديد فاتورة المبيعات الأصلية أولاً.';
    } elseif (empty($product_ids)) {
        $error = 'لا توجد بنود قابلة للإرجاع.';
    } else {
        $conn->begin_transaction();
        try {
            $has_processed = false;
            $total_refund_amount = 0;

            $res_sale = $conn->query("SELECT cust_name FROM sales WHERE id=$sales_id LIMIT 1");
            $sale_row = $res_sale ? $res_sale->fetch_assoc() : null;
            $customer_name = $sale_row ? $sale_row['cust_name'] : 'عميل نقدي';

            // التحقق من رصيد الصندوق مسبقاً إذا كان الاسترداد نقداً ومن الصندوق
            if ($refund_method === 'cash' && $refund_source === 'box') {
                $required_refund = 0;
                $count = count($product_ids);
                for ($i = 0; $i < $count; $i++) {
                    $qty = intval($qty_returns[$i]);
                    $unit_price = doubleval($unit_prices[$i]);
                    if ($qty > 0) {
                        $required_refund += ($qty * $unit_price);
                    }
                }
                
                if ($required_refund > 0) {
                    $box_balance_before = get_box_balance($conn, $active_box_id);
                    if ($box_balance_before < $required_refund) {
                        throw new Exception("رصيد الصندوق الحالي (" . number_format($box_balance_before, 2) . " ر.ي) غير كافٍ لتغطية مبلغ الاسترداد الكلي للمردودات (" . number_format($required_refund, 2) . " ر.ي). يرجى اختيار مبيعات اليوم كمصدر أو تغذية الصندوق.");
                    }
                }
            }

            $count = count($product_ids);
            for ($i = 0; $i < $count; $i++) {
                $product_id = intval($product_ids[$i]);
                $p_name = trim($product_names[$i]);
                $qty = intval($qty_returns[$i]);
                $unit_price = doubleval($unit_prices[$i]);
                $buy_price = doubleval($buy_prices[$i]);
                $unit_name = isset($unit_names[$i]) ? trim($unit_names[$i]) : 'حبة';
                if (empty($unit_name)) $unit_name = 'حبة';

                if ($qty <= 0) continue;

                $refund_amount = $qty * $unit_price;
                $profit_impact = ($unit_price - $buy_price) * $qty;
                $p_name_esc = $conn->real_escape_string($p_name);
                $unit_name_esc = $conn->real_escape_string($unit_name);

                // التحقق من الكمية المتاحة للإرجاع
                $res_chk = $conn->query("SELECT COALESCE(SUM(quantity),0) AS ret FROM sales_returns WHERE sales_id=$sales_id AND product_id=$product_id AND status='active'");
                $already_ret = $res_chk ? intval($res_chk->fetch_assoc()['ret']) : 0;

                $res_orig = $conn->query("SELECT quantity FROM sales_items WHERE sales_id=$sales_id AND id=$product_id LIMIT 1");
                $orig_qty = $res_orig ? intval($res_orig->fetch_assoc()['quantity']) : 0;
                $can_return = $orig_qty - $already_ret;

                if ($qty > $can_return) {
                    throw new Exception("لا يمكن إرجاع {$qty} وحدة من المنتج \"{$p_name}\". الكمية المتاحة للإرجاع: {$can_return} وحدة فقط.");
                }

                // 1. إدراج سجل المرتجع
                $sql_ins = "INSERT INTO sales_returns
                    (sales_id, product_id, product_name, quantity, unit_price, refund_amount,
                     original_unit_price, original_buy_price, profit_impact, reason, return_date, user, status, box_id, refund_method, refund_source, unit_name)
                    VALUES
                    ($sales_id, $product_id, '$p_name_esc', $qty, $unit_price, $refund_amount,
                     $unit_price, $buy_price, -$profit_impact, '$reason', '$return_date', '$user_name', 'active', $active_box_id, '$refund_method', '$refund_source', '$unit_name_esc')";

                if (!$conn->query($sql_ins)) {
                    throw new Exception('فشل تسجيل المرتجع للصنف: ' . $p_name . ' - ' . $conn->error);
                }
                $saved_return_id = $conn->insert_id;

                // تحديد عامل تحويل الوحدة
                $conv_factor = 1.0;
                if (!empty($unit_name) && $unit_name !== 'حبة') {
                    $unit_res = $conn->query("SELECT conversion_factor FROM product_units WHERE product_id = $product_id AND unit_name = '$unit_name_esc' LIMIT 1");
                    if ($unit_res && $unit_res->num_rows > 0) {
                        $conv_factor = doubleval($unit_res->fetch_assoc()['conversion_factor']);
                        if ($conv_factor <= 0) $conv_factor = 1.0;
                    }
                }
                $base_qty = $qty * $conv_factor;

                // 2. إعادة الكمية للمخزن
                if (!$conn->query("UPDATE products SET quantity = quantity + $base_qty, total = quantity * buy_price WHERE id = $product_id")) {
                    throw new Exception("فشل تحديث المخزون للصنف: " . $p_name . ' - ' . $conn->error);
                }

                // 3. سجل حركة المخزن
                if (!$conn->query("INSERT INTO inventory_log (product_id, product_name, type, qty_change, new_qty, reason, user)
                              SELECT id, name, 'sale_return', $base_qty, quantity, 'مرتجع بيع فاتورة #{$sales_id} - {$reason}', '$user_name'
                              FROM products WHERE id = $product_id LIMIT 1")) {
                    throw new Exception("فشل تسجيل حركة المخزن للصنف: " . $p_name . ' - ' . $conn->error);
                }

                $total_refund_amount += $refund_amount;
                $has_processed = true;

                // 4. تسجيل قيد المرتجع لكل صنف
                if ($refund_method === 'cash') {
                    if ($refund_source === 'box' && $refund_amount > 0) {
                        update_box_balance($conn, $active_box_id, $refund_amount, 'discount', "مرتجع بيع فاتورة #{$sales_id} - {$p_name}", $return_date);
                    }
                    $credit_acc = 'الصندوق - ' . $box_name;
                    $debit_acc = 'مردودات المبيعات';
                    post_journal_entry($conn, 'return', $saved_return_id, $debit_acc, $credit_acc, $refund_amount, 
                        "مرتجع مبيعات نقداً فاتورة #{$sales_id} - {$p_name} | الكمية: {$qty} | المصدر: " . ($refund_source === 'box' ? 'الصندوق (خصم مباشر)' : 'مبيعات اليوم'), 
                        $user_name, $active_box_id, 'YER', 1.0, null);
                } else {
                    if ($refund_amount > 0 && !empty($customer_name) && $customer_name !== 'عميل نقدي') {
                        $cust_esc = $conn->real_escape_string($customer_name);
                        $conn->query("UPDATE customers SET cust_madeen = GREATEST(0, cust_madeen - $refund_amount) WHERE cust_name='$cust_esc'");
                    }
                    $debit_acc = 'مردودات المبيعات';
                    $credit_acc = 'الذمم المدينة - ' . $customer_name;
                    post_journal_entry($conn, 'return', $saved_return_id, $debit_acc, $credit_acc, $refund_amount, 
                        "مرتجع مبيعات آجل (خصم دين) فاتورة #{$sales_id} - {$p_name} | الكمية: {$qty}", 
                        $user_name, $active_box_id, 'YER', 1.0, null);
                }

                // 5. قيد تكلفة البضاعة المرجعة لكل صنف
                $cost_amount = $buy_price * $qty;
                if ($cost_amount > 0) {
                    $debit_acc = 'المخزون / البضاعة';
                    $credit_acc = 'تكلفة البضاعة المباعة (مصروف)';
                    post_journal_entry($conn, 'return', $saved_return_id, $debit_acc, $credit_acc, $cost_amount, 
                        "إعادة تكلفة مرتجع مبيعات #{$saved_return_id} للمخزون | المنتج: {$p_name} | الكمية: {$qty}", 
                        $user_name, $active_box_id, 'YER', 1.0, null);
                }
            }

            if (!$has_processed) {
                throw new Exception("الرجاء إدخال كمية مرتجعة أكبر من الصفر لصنف واحد على الأقل.");
            }

            // تحديث إجمالي المتبقي في الفاتورة الأصلية بالكامل إذا كان آجل
            if ($refund_method === 'credit' && $total_refund_amount > 0) {
                $conn->query("UPDATE sales SET remaining_total = GREATEST(0, remaining_total - $total_refund_amount) WHERE id = $sales_id");
            }

            $conn->commit();
            $success = '✓ تم تسجيل مردود المبيعات بنجاح وتحديث الحسابات والمخزون.';
        } catch (Exception $e) {
            $conn->rollback();
            $error = $e->getMessage();
        }
    }
}

// ==========================================
// معالجة إلغاء مرتجع
// ==========================================
if (isset($_GET['cancel_ret']) && is_numeric($_GET['cancel_ret'])) {
    $ret_id = intval($_GET['cancel_ret']);
    $res_ret = $conn->query("SELECT * FROM sales_returns WHERE id=$ret_id AND status='active' LIMIT 1");
    $ret_row = $res_ret ? $res_ret->fetch_assoc() : null;

    if ($ret_row) {
        $conn->begin_transaction();
        try {
            if (!$conn->query("UPDATE sales_returns SET status='cancelled' WHERE id=$ret_id")) {
                throw new Exception("فشل تحديث حالة المرتجع");
            }
            
            $qty           = intval($ret_row['quantity']);
            $product_id    = intval($ret_row['product_id']);
            $refund        = doubleval($ret_row['refund_amount']);
            $s_id          = intval($ret_row['sales_id']);
            $refund_method = $ret_row['refund_method'];
            $box_id        = intval($ret_row['box_id']);
            $box_name      = get_box_name($conn, $box_id);
            $uname         = $conn->real_escape_string($_SESSION['SESS_FIRST_NAME']);

            $res_sale = $conn->query("SELECT cust_name FROM sales WHERE id=$s_id LIMIT 1");
            $sale_row = $res_sale ? $res_sale->fetch_assoc() : null;
            $customer_name = $sale_row ? $sale_row['cust_name'] : 'عميل نقدي';

            // تحديد عامل تحويل الوحدة
            $conv_factor = 1.0;
            $unit_name = $ret_row['unit_name'];
            if (!empty($unit_name)) {
                $unit_res = $conn->query("SELECT conversion_factor FROM product_units WHERE product_id = $product_id AND unit_name = '" . $conn->real_escape_string($unit_name) . "' LIMIT 1");
                if ($unit_res && $unit_res->num_rows > 0) {
                    $conv_factor = doubleval($unit_res->fetch_assoc()['conversion_factor']);
                    if ($conv_factor <= 0) $conv_factor = 1.0;
                }
            }
            $base_qty = $qty * $conv_factor;

            if (!$conn->query("UPDATE products SET quantity = GREATEST(0, quantity - $base_qty), total = quantity * buy_price WHERE id=$product_id")) {
                throw new Exception("فشل إعادة خصم كمية المرتجع من المخزن");
            }

            if ($refund_method === 'cash') {
                $refund_source = $ret_row['refund_source'];
                $today_date = date("Y-m-d H:i:s");
                
                if ($refund_source === 'box') {
                    update_box_balance($conn, $box_id, $refund, 'addition', "إلغاء مرتجع بيع #{$ret_id}", $today_date);
                }
                
                $debit_acc = ($refund_source === 'box') ? 'الصندوق - ' . $box_name : 'المبيعات';
                $credit_acc = 'مردودات المبيعات';
                
                if (!post_journal_entry($conn, 'return', $ret_id, $debit_acc, $credit_acc, $refund, 
                    "إلغاء مرتجع بيع نقدي #{$ret_id} - عكس القيد الأصلي", 
                    $uname, $box_id, 'YER', 1.0, null)) {
                    throw new Exception("فشل تسجيل قيد إلغاء المرتجع النقدي");
                }
            } else {
                if (!empty($customer_name) && $customer_name !== 'عميل نقدي') {
                    $cust_esc = $conn->real_escape_string($customer_name);
                    $conn->query("UPDATE customers SET cust_madeen = cust_madeen + $refund WHERE cust_name='$cust_esc'");
                }
                
                if (!$conn->query("UPDATE sales SET remaining_total = remaining_total + $refund WHERE id=$s_id")) {
                    throw new Exception("فشل استعادة مديونية الفاتورة الأصلية");
                }
                
                $debit_acc = 'الذمم المدينة - ' . $customer_name;
                $credit_acc = 'مردودات المبيعات';
                
                if (!post_journal_entry($conn, 'return', $ret_id, $debit_acc, $credit_acc, $refund, 
                    "إلغاء مرتجع بيع آجل #{$ret_id} - عكس القيد الأصلي", 
                    $uname, $box_id, 'YER', 1.0, null)) {
                    throw new Exception("فشل تسجيل قيد إلغاء المرتجع الآجل");
                }
            }

            $cost_amount = doubleval($ret_row['original_buy_price']) * $qty;
            if ($cost_amount > 0) {
                $debit_acc = 'تكلفة البضاعة المباعة (مصروف)';
                $credit_acc = 'المخزون / البضاعة';
                
                if (!post_journal_entry($conn, 'return', $ret_id, $debit_acc, $credit_acc, $cost_amount, 
                    "إلغاء قيد تكلفة مرتجع مبيعات #{$ret_id}", 
                    $uname, $box_id, 'YER', 1.0, null)) {
                    throw new Exception("فشل تسجيل قيد إلغاء تكلفة البضاعة المرجعة");
                }
            }

            $conn->commit();
            $success = '✓ تم إلغاء المرتجع #' . $ret_id . ' بنجاح وعكس جميع العمليات الماليّة والمخزنية.';
        } catch (Exception $e) {
            $conn->rollback();
            $error = $e->getMessage();
        }
    }
}

$recent_invoices = [];
$res_inv = $conn->query("SELECT id, cust_name, total, build_date FROM sales WHERE delete_status=0 ORDER BY id DESC LIMIT 200");
if ($res_inv) while($r = $res_inv->fetch_assoc()) $recent_invoices[] = $r;

$all_returns = [];
$res_all_ret = $conn->query("SELECT sr.*, s.build_date as sale_date, s.cust_name
                              FROM sales_returns sr
                              LEFT JOIN sales s ON sr.sales_id = s.id
                              ORDER BY sr.id DESC LIMIT 150");
if ($res_all_ret) while($r = $res_all_ret->fetch_assoc()) $all_returns[] = $r;

$active_box_id = get_user_box_id($conn, $_SESSION['SESS_MEMBER_ID']);
$box_name = get_box_name($conn, $active_box_id);
?>
<title>فاتورة مردودات المبيعات - تكنولوجيا فون</title>

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

<div class="page-inner">
<!-- Onyx Pro System Window Header Bar -->
<div class="aqnex-window-header no-print">
    <div>
        <i class="bi bi-arrow-return-left text-danger ml-1"></i>
        <span>أنظمة العملاء - نظام إدارة المبيعات - فاتورة مردودات المبيعات</span>
    </div>
    <div>
        <span class="ml-3">المستخدم: <strong><?php echo htmlspecialchars($_SESSION['SESS_FIRST_NAME'] ?? 'مدير النظام'); ?></strong></span>
        <span>التاريخ: <strong><?php echo date('Y/m/d'); ?></strong></span>
    </div>
</div>

<!-- Onyx Pro Action Toolbar -->
<div class="aqnex-toolbar no-print">
    <!-- ➕ جديد (F2) -->
    <button type="button" class="tool-btn btn-new" title="فتح شاشة فاتورة مبيعات جديدة (F2)" onclick="window.location.href='create.php';">
        <i class="bi bi-file-earmark-plus-fill"></i>
    </button>

    <!-- 💾 حفظ المرتجع (F4 / Ctrl+S) -->
    <button type="submit" form="returnForm" name="btn_save_return" class="tool-btn btn-save btn-save-action" title="حفظ وتأكيد المرتجع (F4 / Ctrl+S)">
        <i class="bi bi-floppy-fill"></i>
    </button>

    <!-- 🔍 بحث عن فاتورة -->
    <button type="button" class="tool-btn btn-search" title="البحث عن رقم فاتورة مبيعات (F2)" onclick="document.getElementById('invoiceSearchInput').focus(); document.getElementById('invoiceSearchInput').select();">
        <i class="bi bi-search"></i>
    </button>

    <!-- 🖨 طباعة -->
    <button type="button" class="tool-btn btn-print" title="طباعة الفاتورة (F9)" onclick="window.print();">
        <i class="bi bi-printer-fill"></i>
    </button>

    <div class="aqnex-toolbar-divider"></div>

    <!-- ✖ إلغاء (Esc) -->
    <a href="index.php" class="tool-btn btn-delete text-decoration-none" title="إلغاء والعودة (Esc)">
        <i class="bi bi-x-circle-fill"></i>
    </a>
</div>

<?php if (!empty($success)): ?>
    <script>
    document.addEventListener("DOMContentLoaded", function() {
        if (typeof showSystemAlert === 'function') {
            showSystemAlert("تمت العملية بنجاح", <?php echo json_encode($success); ?>, "success");
        }
    });
    </script>
    <div class="alert alert-success rounded-0 mb-4 text-right no-print"><?php echo htmlspecialchars($success); ?></div>
<?php endif; ?>
<?php if (!empty($error)): ?>
    <script>
    document.addEventListener("DOMContentLoaded", function() {
        if (typeof showSystemAlert === 'function') {
            showSystemAlert("خطأ في العملية", <?php echo json_encode($error); ?>, "danger");
        }
    });
    </script>
    <div class="alert alert-danger rounded-0 mb-4 text-right no-print"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<form method="POST" id="returnForm" class="no-print">
    <input type="hidden" name="sales_id" id="ret_sales_id" value="0">
    
    <!-- بطاقة الهيدر (تفاصيل الفاتورة مثل شاشة المبيعات تماماً) -->
    <div class="card-flat p-3 mb-4 text-right" dir="rtl">
        <div class="row">
            <!-- حقل رقم الفاتورة للبحث -->
            <div class="col-md-3 mb-3">
                <label class="form-label font-weight-bold text-secondary">رقم فاتورة المبيعات للبحث *</label>
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

            <!-- تاريخ المردود -->
            <div class="col-md-2 mb-3">
                <label class="form-label font-weight-bold text-secondary">تاريخ المردود</label>
                <input type="date" name="return_date" class="form-control rounded-0 font-weight-bold text-center" value="<?php echo date('Y-m-d'); ?>" required>
            </div>

            <!-- العميل (تلقائي) -->
            <div class="col-md-3 mb-3">
                <label class="form-label font-weight-bold text-secondary">العميل</label>
                <input type="text" id="invCustDisplay" class="form-control rounded-0 font-weight-bold text-center bg-light" readonly value="-">
            </div>

            <!-- نوع الفاتورة (تلقائي) -->
            <div class="col-md-2 mb-3">
                <label class="form-label font-weight-bold text-secondary">نوع الفاتورة</label>
                <input type="text" id="invTypeDisplay" class="form-control rounded-0 font-weight-bold text-center bg-light" readonly value="-">
            </div>

            <!-- الإجمالي الأصلي (تلقائي) -->
            <div class="col-md-2 mb-3">
                <label class="form-label font-weight-bold text-secondary">الإجمالي الأصلي</label>
                <input type="text" id="invTotalDisplay" class="form-control rounded-0 font-weight-bold text-center bg-light text-primary" readonly value="-">
            </div>
        </div>

        <!-- خيارات الاسترداد (تظهر بعد تحميل الفاتورة) -->
        <div class="row" id="refundOptionsRow" style="display:none; border-top: 1px solid #eee; padding-top: 15px;">
            <!-- طريقة رد القيمة -->
            <div class="col-md-3 mb-3">
                <label class="form-label font-weight-bold text-secondary">طريقة رد القيمة للعميل *</label>
                <select name="refund_method" id="refund_method" class="form-control rounded-0 font-weight-bold" required onchange="toggleRefundSource()">
                    <option value="cash" selected>استلام نقدي (تسليم فوري للعميل)</option>
                    <option value="credit" id="refund_credit_opt">آجل (خصم من مديونية الحساب للعميل)</option>
                </select>
            </div>

            <!-- مصدر الخصم المالي -->
            <div class="col-md-3 mb-3" id="refundSourceSection">
                <label class="form-label font-weight-bold text-secondary">مصدر الخصم المالي *</label>
                <select name="refund_source" id="refund_source" class="form-control rounded-0 font-weight-bold" required>
                    <option value="box" selected>من الصندوق مباشرة (خصم فوري من رصيد الصندوق)</option>
                    <option value="sales">من مبيعات اليوم (لا يؤثر على رصيد الصندوق الإجمالي)</option>
                </select>
            </div>

            <!-- سبب الاسترجاع -->
            <div class="col-md-3 mb-3">
                <label class="form-label font-weight-bold text-secondary">سبب الاسترجاع *</label>
                <select name="reason" class="form-control rounded-0 font-weight-bold" required>
                    <option value="">-- اختر السبب --</option>
                    <option value="منتج معيب أو تالف">منتج معيب أو تالف</option>
                    <option value="لا يطابق المواصفات المطلوبة">لا يطابق المواصفات المطلوبة</option>
                    <option value="رغبة العميل بالاسترجاع">رغبة العميل بالاسترجاع</option>
                    <option value="خطأ في الطلب">خطأ في الطلب</option>
                    <option value="صنف مكرر بالفاتورة">صنف مكرر بالفاتورة</option>
                </select>
            </div>

            <!-- الصندوق النشط -->
            <div class="col-md-3 mb-3">
                <label class="form-label font-weight-bold text-secondary">الصندوق المسترجع منه</label>
                <input type="text" class="form-control rounded-0 font-weight-bold text-center bg-light" readonly value="<?php echo htmlspecialchars($box_name); ?>">
            </div>
        </div>
    </div>

    <!-- جدول الأصناف مرئي وكامل العرض (يظهر بعد اختيار الفاتورة) -->
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
                                <th style="width: 12%;" class="text-center">الكمية المباعة</th>
                                <th style="width: 15%;" class="text-center">القابلة للإرجاع</th>
                                <th style="width: 15%;" class="text-center">سعر البيع بالفاتورة</th>
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

        <!-- الملخص المالي والاعتماد -->
        <div class="row justify-content-end mb-4">
            <div class="col-md-5">
                <div class="card bg-light border-0 p-4 rounded-0 text-center">
                    <h5 class="text-secondary font-weight-bold mb-3">الملخص المالي للمردودات</h5>
                    <div style="font-size: 2rem;" class="font-weight-bold text-danger mb-3" id="grand_return_total_display">
                        0.00 <span style="font-size: 1.1rem;">ر.ي</span>
                    </div>
                    <button type="submit" name="btn_save_return" class="btn-flat btn-flat-danger btn-block py-3 font-weight-bold" style="font-size: 1.15rem;">
                        <i class="fa fa-check ml-2"></i> تأكيد وإعتماد الفاتورة
                    </button>
                </div>
            </div>
        </div>
    </div>
</form>

<!-- سجل المردودات السابقة للفاتورة -->
<div id="invoiceReturnsSection" style="display:none;" class="card-flat ret-card mt-4 no-print">
    <div class="card-header bg-warning text-right">
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
                <tbody id="prevReturnsBody">
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- سجل كافة المردودات العامة بالنظام -->
<div class="card-flat ret-card mt-4 no-print text-right">
    <div class="card-header bg-light">
        <h5 class="mb-0 text-dark font-weight-bold"><i class="fa fa-history ml-2"></i> آخر عمليات مردودات المبيعات بالنظام</h5>
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
                        <tr><td colspan="8" class="text-center text-muted p-4">لا توجد عمليات مردودات مبيعات مسجلة بالنظام حالياً.</td></tr>
                    <?php else: ?>
                        <?php foreach ($all_returns as $r): ?>
                        <tr class="<?php echo $r['status']==='cancelled' ? 'text-muted' : ''; ?>" style="<?php echo $r['status']==='cancelled' ? 'opacity:0.6; text-decoration:line-through;' : ''; ?>">
                            <td class="font-weight-bold text-secondary">#<?php echo $r['id']; ?></td>
                            <td>
                                <a href="view.php?id=<?php echo $r['sales_id']; ?>" class="text-primary font-weight-bold text-decoration-none">
                                    #<?php echo $r['sales_id']; ?>
                                </a>
                            </td>
                            <td class="small font-weight-bold"><?php echo htmlspecialchars($r['product_name']); ?></td>
                            <td class="text-center text-danger font-weight-bold"><?php echo $r['quantity']; ?></td>
                            <td class="font-weight-bold"><?php echo number_format($r['refund_amount'], 2); ?> YER</td>
                            <td>
                                <?php if ($r['status'] === 'active'): ?>
                                    <span class="badge badge-success invoice-badge">نشط</span>
                                <?php else: ?>
                                    <span class="badge badge-secondary invoice-badge">ملغى</span>
                                <?php endif; ?>
                            </td>
                            <td class="small"><?php echo htmlspecialchars($r['return_date']); ?></td>
                            <td class="no-print text-center">
                                <?php if ($r['status'] === 'active'): ?>
                                <a href="returns.php?cancel_ret=<?php echo $r['id']; ?>" onclick="return confirm('هل تريد إلغاء هذا المرتجع؟ سيتم عكس جميع العمليات والقيود المحاسبية.')" class="btn-flat btn-flat-danger btn-sm py-1 px-2 text-decoration-none">
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
</div>

<script>
document.getElementById('invoiceSearchInput').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') { e.preventDefault(); searchInvoice(); }
});

function toggleRefundSource() {
    const method = document.getElementById('refund_method').value;
    const sourceSection = document.getElementById('refundSourceSection');
    if (method === 'credit') {
        sourceSection.style.display = 'none';
    } else {
        sourceSection.style.display = 'block';
    }
}

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

    // تعبئة حقول الهيدر التلقائية
    document.getElementById('ret_sales_id').value = inv.id;
    document.getElementById('invCustDisplay').value = inv.cust_name || 'عميل نقدي';
    document.getElementById('invTypeDisplay').value = (inv.invoice_type === 'credit') ? 'آجل' : 'نقدي';
    document.getElementById('invTotalDisplay').value = formatNum(inv.total) + ' YER';

    const isCashCust = !inv.cust_name || inv.cust_name === 'عميل نقدي';
    const optCredit = document.getElementById('refund_credit_opt');
    const selectMethod = document.getElementById('refund_method');
    if (isCashCust) {
        optCredit.disabled = true;
        selectMethod.value = 'cash';
    } else {
        optCredit.disabled = false;
    }
    toggleRefundSource();

    const tbody = document.getElementById('invoiceItemsBody');
    tbody.innerHTML = '';

    if (items.length === 0) {
        tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted p-3">لا توجد بنود في هذه الفاتورة</td></tr>';
    } else {
        items.forEach(item => {
            const canReturn = item.can_return;
            const disabledClass = canReturn <= 0 ? 'text-muted' : 'font-weight-bold text-success';
            const disabledAttr  = canReturn <= 0 ? 'disabled' : '';

            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td class="font-weight-bold small text-right">
                    ${escHtml(item.name)}
                    <input type="hidden" name="product_ids[]" value="${item.product_id}">
                    <input type="hidden" name="product_names[]" value="${escHtml(item.name)}">
                    <input type="hidden" name="unit_prices[]" value="${item.unit_price}">
                    <input type="hidden" name="buy_prices[]" value="${item.buy_price}">
                    <input type="hidden" name="unit_names[]" value="${escHtml(item.unit_name || 'حبة')}">
                </td>
                <td class="text-center small">${escHtml(item.unit_name || 'حبة')}</td>
                <td class="text-center">${item.quantity}</td>
                <td class="text-center ${disabledClass}">${canReturn}</td>
                <td class="text-center small">${formatNum(item.unit_price)} YER</td>
                <td class="text-center no-print">
                    <input type="number" name="qty_returns[]" class="form-control text-center rounded-0 qty-return-input font-weight-bold"
                           style="max-width: 90px; margin: 0 auto; height: 32px;"
                           min="0" max="${canReturn}" value="0" ${disabledAttr}
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
    return parseFloat(n).toLocaleString('ar-YE', {minimumFractionDigits: 2, maximumFractionDigits: 2});
}

function escHtml(str) {
    const d = document.createElement('div');
    d.textContent = str;
    return d.innerHTML;
}
</script>

<div class="modal fade mt-3" id="invoiceListModal" tabindex="-1" role="dialog" aria-labelledby="invoiceListModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content rounded-0">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title font-weight-bold" id="invoiceListModalLabel">
                    <i class="fa fa-list ml-2"></i> نافذة اختيار فاتورة مبيعات اليوم
                </h5>
                <button type="button" class="close text-white rounded" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true" class="p-2">&times;</span>
                </button>
            </div>
            <div class="modal-body text-right" dir="rtl">
                <div class="form-group mb-3">
                    <label class="font-weight-bold text-secondary mb-1">ابحث باسم العميل أو رقم الفاتورة:</label>
                    <input type="text" id="modalInvoiceSearchInput" class="form-control rounded-0 font-weight-bold text-right" placeholder="ابحث...">
                </div>
                <div class="table-responsive" style="max-height: 350px; overflow-y: auto;">
                    <table class="table-flat mb-0">
                        <thead>
                            <tr>
                                <th style="width: 15%;">رقم الفاتورة</th>
                                <th>العميل</th>
                                <th style="width: 25%;">التاريخ</th>
                                <th style="width: 20%;">الإجمالي (ر.ي)</th>
                                <th style="width: 15%;" class="text-center">تحديد</th>
                            </tr>
                        </thead>
                        <tbody id="modalInvoicesTableBody">
                            <tr>
                                <td colspan="5" class="text-center text-muted p-3">جاري تحميل الفواتير...</td>
                            </tr>
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
                    <td class="text-right font-weight-bold text-secondary">${escHtml(inv.cust_name)}</td>
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

// اختصارات لوحة التحكم لوظائف المرتجعات (Shortcuts)
document.addEventListener('keydown', function(e) {
    if (e.key === 'F2') {
        e.preventDefault();
        var searchInput = document.getElementById('invoiceSearchInput');
        if (searchInput) {
            searchInput.focus();
            searchInput.select();
        }
    }
    if (e.key === 'F4' || (e.ctrlKey && e.key.toLowerCase() === 's')) {
        e.preventDefault();
        var form = document.getElementById('returnForm');
        if (form) {
            if (form.checkValidity()) {
                form.submit();
            } else {
                form.reportValidity();
            }
        }
    }
    if (e.key === 'Escape') {
        window.location.href = 'index.php';
    }
});
</script>

<?php require_once($dir_prefix . 'includes/footer.php'); ?>