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

// ==========================================
// دالة تسجيل قيد محاسبي
// ==========================================
function post_journal($conn, $ref_type, $ref_id, $debit, $credit, $amount, $desc, $user, $curr='YER', $rate=1.0, $foreign=0) {
    $debit  = $conn->real_escape_string($debit);
    $credit = $conn->real_escape_string($credit);
    $desc   = $conn->real_escape_string($desc);
    $user   = $conn->real_escape_string($user);
    $curr   = $conn->real_escape_string($curr);
    $conn->query("INSERT INTO accounting_journal
        (ref_type, ref_id, account_debit, account_credit, amount, description, currency_code, exchange_rate, amount_foreign, user)
        VALUES ('$ref_type', $ref_id, '$debit', '$credit', $amount, '$desc', '$curr', $rate, $foreign, '$user')");
}

// ==========================================
// معالجة تسجيل مرتجع
// ==========================================
if (isset($_POST['btn_save_return'])) {
    $sales_id      = intval($_POST['sales_id']);
    $product_id    = intval($_POST['product_id']);
    $p_name        = $conn->real_escape_string(trim($_POST['product_name']));
    $qty           = intval($_POST['qty_return']);
    $unit_price    = doubleval($_POST['unit_price_yer']);   // بالريال اليمني دائماً
    $buy_price     = doubleval($_POST['buy_price_yer']);
    $refund_amount = $qty * $unit_price;                    // احتساب المبلغ الكلي
    $reason        = $conn->real_escape_string(trim($_POST['reason']));
    $return_date   = $conn->real_escape_string($_POST['return_date']);
    $user_name     = $conn->real_escape_string($_SESSION['SESS_FIRST_NAME']);
    $profit_impact = ($unit_price - $buy_price) * $qty;    // تأثير على الربح
    $refund_method = $_POST['refund_method'] === 'credit' ? 'credit' : 'cash';
    $refund_source = (isset($_POST['refund_source']) && $_POST['refund_source'] === 'box') ? 'box' : 'sales';

    $active_box_id = get_user_box_id($conn, $_SESSION['SESS_MEMBER_ID']);
    $box_name = get_box_name($conn, $active_box_id);

    if ($qty <= 0 || $product_id <= 0 || empty($p_name)) {
        $error = 'الرجاء تحديد المنتج والكمية المرتجعة بشكل صحيح.';
    } elseif ($sales_id <= 0) {
        $error = 'الرجاء تحديد فاتورة المبيعات الأصلية أولاً.';
    } else {
        // التحقق من الكمية المتاحة للإرجاع
        $res_chk = $conn->query("SELECT COALESCE(SUM(quantity),0) AS ret FROM sales_returns WHERE sales_id=$sales_id AND product_id=$product_id AND status='active'");
        $already_ret = $res_chk ? intval($res_chk->fetch_assoc()['ret']) : 0;

        $res_orig = $conn->query("SELECT quantity FROM sales_items WHERE sales_id=$sales_id AND id=$product_id LIMIT 1");
        $orig_qty = $res_orig ? intval($res_orig->fetch_assoc()['quantity']) : 0;
        $can_return = $orig_qty - $already_ret;

        if ($qty > $can_return) {
            $error = "لا يمكن إرجاع {$qty} وحدة. الكمية المتاحة للإرجاع: {$can_return} وحدة فقط (الكمية الأصلية: {$orig_qty} وحدة).";
        } else {
            // 1. إدراج سجل المرتجع مع الصندوق وطريقة الاسترداد ومصدر الخصم
            $sql_ins = "INSERT INTO sales_returns
                (sales_id, product_id, product_name, quantity, unit_price, refund_amount,
                 original_unit_price, original_buy_price, profit_impact, reason, return_date, user, status, box_id, refund_method, refund_source)
                VALUES
                ($sales_id, $product_id, '$p_name', $qty, $unit_price, $refund_amount,
                 $unit_price, $buy_price, -$profit_impact, '$reason', '$return_date', '$user_name', 'active', $active_box_id, '$refund_method', '$refund_source')";

            if (!$conn->query($sql_ins)) {
                $error = 'فشل تسجيل المرتجع: ' . $conn->error;
            } else {
                $saved_return_id = $conn->insert_id;

                // 2. إعادة الكمية للمخزن
                $conn->query("UPDATE products SET quantity = quantity + $qty WHERE id = $product_id");

                // 3. سجل حركة المخزن
                $conn->query("INSERT INTO inventory_log (product_id, product_name, type, qty_change, new_qty, reason, user)
                              SELECT id, name, 'manual', $qty, quantity, 'مرتجع بيع فاتورة #{$sales_id} - {$reason}', '$user_name'
                              FROM products WHERE id = $product_id LIMIT 1");

                // 4. خصم المبلغ من الخزينة أو العميل
                $res_sale = $conn->query("SELECT cust_name FROM sales WHERE id=$sales_id LIMIT 1");
                $sale_row = $res_sale ? $res_sale->fetch_assoc() : null;
                $customer_name = $sale_row ? $sale_row['cust_name'] : 'عميل نقدي';

                if ($refund_method === 'cash') {
                    // تحديث رصيد الصندوق فقط إذا كان الخصم من الصندوق مباشرة
                    if ($refund_source === 'box' && $refund_amount > 0) {
                        update_box_balance($conn, $active_box_id, $refund_amount, 'discount', "مرتجع بيع فاتورة #{$sales_id} - {$p_name}", $return_date);
                    }
                    // تعديل إجمالي المدفوع النقدي في الفاتورة الأصلية في كل الأحوال
                    $conn->query("UPDATE sales SET
                        total  = GREATEST(0, total  - $refund_amount),
                        prifet = prifet - $profit_impact
                        WHERE id = $sales_id");
                        
                    // قيد محاسبي للمرتجع النقدي
                    $credit_acc = ($refund_source === 'box') ? 'الصندوق - ' . $box_name : 'المبيعات';
                    post_journal_entry($conn, 'return', $saved_return_id, 'المردودات (مردودات المبيعات)', $credit_acc, $refund_amount, "مرتجع مبيعات نقداً #{$sales_id} - {$p_name} (مصدر الخصم: " . ($refund_source === 'box' ? 'الصندوق' : 'مبيعات اليوم') . ")", $user_name, $active_box_id);
                } else {
                    // خصم من المديونية للعميل
                    if ($refund_amount > 0 && !empty($customer_name) && $customer_name !== 'عميل نقدي') {
                        $cust_esc = $conn->real_escape_string($customer_name);
                        $conn->query("UPDATE customers SET cust_madeen = GREATEST(0, cust_madeen - $refund_amount) WHERE cust_name='$cust_esc'");
                    }
                    // تعديل إجمالي المتبقي (المديونية) في الفاتورة الأصلية
                    $conn->query("UPDATE sales SET
                        remaining_total = GREATEST(0, remaining_total - $refund_amount),
                        prifet          = prifet - $profit_impact
                        WHERE id = $sales_id");
                        
                    // قيد محاسبي للمرتجع الآجل
                    post_journal_entry($conn, 'return', $saved_return_id, 'المردودات (مردودات المبيعات)', 'الذمم المدينة - ' . $customer_name, $refund_amount, "مرتجع مبيعات آجل (خصم دين) #{$sales_id} - {$p_name}", $user_name, $active_box_id);
                }

                // قيد إعادة تكلفة المبيعات للمخزن
                if ($buy_price * $qty > 0) {
                    post_journal_entry($conn, 'return', $saved_return_id, 'المخزون / البضاعة', 'تكلفة البضاعة المباعة (مصروف)', $buy_price * $qty, "إعادة تكلفة مرتجع مبيعات #{$saved_return_id} للمخزون", $user_name, $active_box_id);
                }

                $success = "✓ تم تسجيل مرتجع {$qty} وحدة من \"{$p_name}\" بنجاح! طريقة الرد: " . ($refund_method === 'cash' ? 'نقداً من الصندوق' : 'خصم من مديونية العميل') . ". المبلغ: " . number_format($refund_amount, 2) . " ر.ي";
            }
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
        $conn->query("UPDATE sales_returns SET status='cancelled' WHERE id=$ret_id");
        
        $qty           = intval($ret_row['quantity']);
        $product_id    = intval($ret_row['product_id']);
        $refund        = doubleval($ret_row['refund_amount']);
        $profit_imp    = doubleval($ret_row['profit_impact']);
        $s_id          = intval($ret_row['sales_id']);
        $refund_method = $ret_row['refund_method'];
        $box_id        = intval($ret_row['box_id']);
        $box_name      = get_box_name($conn, $box_id);
        $uname         = $conn->real_escape_string($_SESSION['SESS_FIRST_NAME']);

        // جلب اسم العميل
        $res_sale = $conn->query("SELECT cust_name FROM sales WHERE id=$s_id LIMIT 1");
        $sale_row = $res_sale ? $res_sale->fetch_assoc() : null;
        $customer_name = $sale_row ? $sale_row['cust_name'] : 'عميل نقدي';

        // خصم الكمية من المخزن مجدداً
        $conn->query("UPDATE products SET quantity = quantity - $qty WHERE id=$product_id AND quantity >= $qty");

        if ($refund_method === 'cash') {
            $refund_source = $ret_row['refund_source'];
            // إعادة المبلغ للصندوق فقط إذا كان الخصم الأصلي من الصندوق
            if ($refund_source === 'box') {
                update_box_balance($conn, $box_id, $refund, 'addition', "إلغاء مرتجع بيع #{$ret_id}", $today_date);
            }
            // استعادة إجمالي الفاتورة المدفوع في كل الأحوال
            $conn->query("UPDATE sales SET total = total + $refund, prifet = prifet + (-$profit_imp) WHERE id=$s_id");
            // قيد عكسي
            $credit_acc = ($refund_source === 'box') ? 'الصندوق - ' . $box_name : 'المبيعات';
            post_journal_entry($conn, 'return', $ret_id, $credit_acc, 'المردودات (مردودات المبيعات)', $refund, "إلغاء مرتجع بيع نقدي #{$ret_id}", $uname, $box_id);
        } else {
            // إعادة مديونية العميل
            if (!empty($customer_name) && $customer_name !== 'عميل نقدي') {
                $cust_esc = $conn->real_escape_string($customer_name);
                $conn->query("UPDATE customers SET cust_madeen = cust_madeen + $refund WHERE cust_name='$cust_esc'");
            }
            // استعادة إجمالي المتبقي (المديونية)
            $conn->query("UPDATE sales SET remaining_total = remaining_total + $refund, prifet = prifet + (-$profit_imp) WHERE id=$s_id");
            // قيد عكسي
            post_journal_entry($conn, 'return', $ret_id, 'الذمم المدينة - ' . $customer_name, 'المردودات (مردودات المبيعات)', $refund, "إلغاء مرتجع بيع آجل #{$ret_id}", $uname, $box_id);
        }

        // قيد عكسي لتكلفة المبيعات
        $res_ret_detail = $conn->query("SELECT original_buy_price FROM sales_returns WHERE id=$ret_id LIMIT 1");
        $buy_price_val = ($res_ret_detail && $row = $res_ret_detail->fetch_assoc()) ? doubleval($row['original_buy_price']) : 0;
        if ($buy_price_val * $qty > 0) {
            post_journal_entry($conn, 'return', $ret_id, 'تكلفة البضاعة المباعة (مصروف)', 'المخزون / البضاعة', $buy_price_val * $qty, "إلغاء قيد تكلفة مرتجع مبيعات #{$ret_id}", $uname, $box_id);
        }

        $success = 'تم إلغاء المرتجع #' . $ret_id . ' بنجاح.';
    }
}

// جلب آخر الفواتير للبحث السريع
$recent_invoices = [];
$res_inv = $conn->query("SELECT id, cust_name, total, build_date FROM sales WHERE delete_status=0 ORDER BY id DESC LIMIT 200");
if ($res_inv) while($r = $res_inv->fetch_assoc()) $recent_invoices[] = $r;

// جلب سجل المرتجعات الكامل
$all_returns = [];
$res_all_ret = $conn->query("SELECT sr.*, s.build_date as sale_date, s.cust_name
                              FROM sales_returns sr
                              LEFT JOIN sales s ON sr.sales_id = s.id
                              ORDER BY sr.id DESC LIMIT 150");
if ($res_all_ret) while($r = $res_all_ret->fetch_assoc()) $all_returns[] = $r;
?>
<title>مردودات المبيعات - <?php echo htmlspecialchars($settings['store_name'] ?? 'النظام'); ?></title>

<style>
/* ===== Receipt Print Styles ===== */
@media print {
    #sidebar, .navbar-top, .no-print, .btn-flat { display: none !important; }
    #content { margin: 0 !important; padding: 0 !important; }
    .receipt-print { display: block !important; }
    body { background: #fff !important; }
}
.receipt-print { display: none; }
.invoice-items-table tr:hover { background: #f8f9fa; }
.ret-card { border: 2px solid var(--secondary); }
.search-invoice-input { font-size: 1.1rem; font-weight: bold; }
.invoice-badge { padding: 3px 8px; font-size: 0.78rem; font-weight: bold; }
.can-return { color: var(--accent-success); font-weight: bold; }
.no-return  { color: #ccc; }
.qty-input-inline { width: 70px !important; text-align: center; }
</style>

<?php if (!empty($success) && $saved_return_id > 0):
    // عرض إيصال المرتجع مباشرة
    $res_ret_view = $conn->query("SELECT sr.*, s.cust_name, s.build_date as sale_date FROM sales_returns sr LEFT JOIN sales s ON sr.sales_id=s.id WHERE sr.id=$saved_return_id LIMIT 1");
    $ret_view = $res_ret_view ? $res_ret_view->fetch_assoc() : null;
?>
<!-- ===== إيصال المرتجع (يظهر فوراً بعد الحفظ) ===== -->
<div class="alert alert-success rounded-0 mb-3 no-print">
    <?php echo $success; ?>
    <button onclick="window.print()" class="btn-flat btn-flat-primary btn-sm mr-3">
        <!-- <i class="fa fa-print ml-1"></i> طباعة الإيصال (أو اضغط Enter) -->
          <?php echo get_icon('print', 'ml-1'); ?>  
    </button>
    <a href="returns.php" class="btn-flat btn-flat-secondary btn-sm text-decoration-none mr-2">إدارة المردودات</a>
    <a href="index.php" class="btn-flat btn-flat-secondary btn-sm text-decoration-none">قائمة المبيعات</a>
</div>

<!-- إيصال المرتجع للطباعة -->
<div class="receipt-print" id="returnReceipt" style="display:block; border: 1px solid #333; max-width:350px; margin: 0 auto; padding: 15px; font-family: 'Courier New', monospace; direction: rtl;">
    <div style="text-align:center; border-bottom: 2px dashed #333; padding-bottom:10px; margin-bottom:10px;">
        <h4 style="margin:0; font-weight:bold;"><?php echo htmlspecialchars($settings['store_name'] ?? 'المتجر'); ?></h4>
        <small><?php echo htmlspecialchars($settings['address'] ?? ''); ?></small><br>
        <small>ت: <?php echo htmlspecialchars($settings['phone'] ?? ''); ?></small>
    </div>

    <div style="text-align:center; background:#f0f0f0; padding:5px; margin-bottom:10px;">
        <strong style="font-size:1.1rem;">إيصال مرتجع مبيعات</strong><br>
        <small>رقم المرتجع: <strong>#<?php echo $saved_return_id; ?></strong></small>
    </div>

    <?php if ($ret_view): ?>
    <table style="width:100%; font-size:0.85rem; margin-bottom:10px;">
        <tr><td>فاتورة أصلية:</td><td style="text-align:left;"><strong>#<?php echo $ret_view['sales_id']; ?></strong></td></tr>
        <tr><td>العميل:</td><td style="text-align:left;"><?php echo htmlspecialchars($ret_view['cust_name'] ?? '-'); ?></td></tr>
        <tr><td>تاريخ الإرجاع:</td><td style="text-align:left;"><?php echo htmlspecialchars($ret_view['return_date']); ?></td></tr>
        <tr><td>المنتج:</td><td style="text-align:left;"><strong><?php echo htmlspecialchars($ret_view['product_name']); ?></strong></td></tr>
        <tr><td>الكمية المرتجعة:</td><td style="text-align:left;"><?php echo intval($ret_view['quantity']); ?> وحدة</td></tr>
        <tr><td>سعر الوحدة:</td><td style="text-align:left;"><?php echo number_format($ret_view['unit_price'], 2); ?> ر.ي</td></tr>
        <tr style="font-size:1rem; font-weight:bold; border-top:2px solid #333;">
            <td>المبلغ المسترد:</td>
            <td style="text-align:left;"><?php echo number_format($ret_view['refund_amount'], 2); ?> ر.ي</td>
        </tr>
        <tr><td>السبب:</td><td style="text-align:left; font-size:0.8rem;"><?php echo htmlspecialchars($ret_view['reason']); ?></td></tr>
        <tr><td>أمين الصندوق:</td><td style="text-align:left;"><?php echo htmlspecialchars($ret_view['user']); ?></td></tr>
    </table>
    <?php endif; ?>

    <div style="text-align:center; border-top: 2px dashed #333; padding-top:8px; font-size:0.8rem;">
        تم استرداد المبلغ نقداً — <?php echo htmlspecialchars($settings['receipt_footer'] ?? ''); ?>
    </div>
</div>

<script>
// طباعة تلقائية بالضغط على Enter
document.addEventListener('keydown', function(e) {
    if (e.key === 'Enter') { e.preventDefault(); window.print(); }
});
</script>
<hr class="my-4 no-print">
<?php endif; ?>

<?php if (!empty($error)): ?>
<div class="alert alert-danger rounded-0 mb-4"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<!-- ===== رأس الصفحة ===== -->
<div class="row mb-3 no-print">
    <div class="col-md-7">
        <h3 class="text-secondary font-weight-bold mb-1">
            <i class="fa fa-undo ml-2 text-primary"></i> إدارة مردودات المبيعات
        </h3>
        <p class="text-muted small mb-0">ابحث عن فاتورة البيع، حدد المنتج المرتجع، وسيحتسب المبلغ تلقائياً.</p>
    </div>
    <div class="col-md-5 text-left">
        <a href="index.php" class="btn-flat btn-flat-secondary btn-sm text-decoration-none ml-2">
            <i class="fa fa-list ml-1"></i> قائمة المبيعات
        </a>
        <a href="create.php" class="btn-flat btn-flat-primary btn-sm text-decoration-none">
            <i class="fa fa-plus ml-1"></i> فاتورة مبيعات جديدة
        </a>
    </div>
</div>

<div class="row no-print">
    <!-- ===== نموذج تسجيل المرتجع ===== -->
    <div class="col-lg-6 mb-4">
        <div class="card-flat ret-card">
            <div class="card-header" style="background: var(--secondary); color: #fff;">
                <h5 class="mb-0"><i class="fa fa-undo ml-2"></i> تسجيل مرتجع جديد</h5>
            </div>
            <div class="card-body">

                <!-- بحث الفاتورة -->
                <div class="form-group mb-3">
                    <label class="font-weight-bold text-secondary mb-1">
                        <i class="fa fa-search ml-1"></i> رقم فاتورة البيع *
                    </label>
                    <div class="input-group">
                        <input type="number" id="invoiceSearchInput" class="form-control rounded-0 search-invoice-input"
                               placeholder="أدخل رقم الفاتورة..." min="1">
                        <div class="input-group-append">
                            <button type="button" class="btn-flat btn-flat-primary px-3" onclick="searchInvoice()">
                                <i class="fa fa-search ml-1"></i> بحث
                            </button>
                        </div>
                    </div>
                    <small class="text-muted">اضغط Enter أو زر بحث لتحميل بنود الفاتورة</small>
                </div>

                <!-- معلومات الفاتورة -->
                <div id="invoiceInfo" style="display:none;" class="alert alert-info rounded-0 p-2 mb-3">
                    <div class="row">
                        <div class="col-6"><strong>فاتورة #:</strong> <span id="invNum">-</span></div>
                        <div class="col-6"><strong>العميل:</strong> <span id="invCust">-</span></div>
                        <div class="col-6"><strong>التاريخ:</strong> <span id="invDate">-</span></div>
                        <div class="col-6"><strong>الإجمالي:</strong> <span id="invTotal">-</span></div>
                    </div>
                </div>

                <!-- جدول منتجات الفاتورة -->
                <div id="invoiceItemsSection" style="display:none;">
                    <label class="font-weight-bold text-secondary mb-2">
                        <i class="fa fa-list ml-1"></i> اختر المنتج المرتجع
                    </label>
                    <div class="table-responsive mb-3" style="max-height: 260px; overflow-y: auto; border: 1px solid #e2e8f0;">
                        <table class="table-flat mb-0" id="invoiceItemsTable">
                            <thead>
                                <tr>
                                    <th>المنتج</th>
                                    <th style="width:12%;" class="text-center">الكمية</th>
                                    <th style="width:18%;" class="text-center">قابل للإرجاع</th>
                                    <th style="width:16%;" class="text-center">سعر الوحدة</th>
                                    <th style="width:12%;" class="no-print text-center">تحديد</th>
                                </tr>
                            </thead>
                            <tbody id="invoiceItemsBody">
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- نموذج المرتجع -->
                <form method="POST" id="returnForm">
                    <input type="hidden" name="sales_id"       id="ret_sales_id" value="0">
                    <input type="hidden" name="product_id"     id="ret_product_id" value="0">
                    <input type="hidden" name="product_name"   id="ret_product_name" value="">
                    <input type="hidden" name="unit_price_yer" id="ret_unit_price" value="0">
                    <input type="hidden" name="buy_price_yer"  id="ret_buy_price" value="0">

                    <div id="returnFormFields" style="display:none;">
                        <!-- المنتج المحدد -->
                        <div class="alert alert-secondary rounded-0 p-2 mb-3" id="selectedProductInfo">
                            <i class="fa fa-cube ml-1"></i>
                            المنتج المحدد: <strong id="selectedProductLabel">-</strong>
                        </div>

                        <div class="row">
                            <div class="col-md-6 form-group mb-3">
                                <label class="font-weight-bold text-secondary mb-1">الكمية المرتجعة *</label>
                                <input type="number" name="qty_return" id="ret_qty" class="form-control rounded-0 text-center font-weight-bold"
                                       min="1" value="1" oninput="calcReturnAmount()">
                                <small class="text-muted">الحد: <span id="maxRetQty">0</span> وحدة</small>
                            </div>
                            <div class="col-md-6 form-group mb-3">
                                <label class="font-weight-bold text-secondary mb-1">المبلغ المسترد (ر.ي)</label>
                                <input type="text" id="ret_refund_display" class="form-control rounded-0 text-center font-weight-bold bg-light text-danger" readonly value="0.00">
                                <small class="text-muted">يُحتسب تلقائياً × سعر الفاتورة</small>
                            </div>
                        </div>

                        <div class="form-group mb-3">
                            <label class="font-weight-bold text-secondary mb-1">طريقة رد المبلغ *</label>
                            <select name="refund_method" id="refund_method" class="form-control rounded-0" required>
                                <option value="cash" selected>إرجاع نقدي من الصندوق</option>
                                <option value="credit" id="refund_credit_opt">خصم من مديونية الحساب (للعملاء الآجل)</option>
                            </select>
                        </div>

                        <div class="form-group mb-3">
                            <label class="font-weight-bold text-secondary mb-1">سبب الإرجاع *</label>
                            <select name="reason" class="form-control rounded-0" required>
                                <option value="">-- اختر السبب --</option>
                                <option value="منتج معيب أو تالف">منتج معيب أو تالف</option>
                                <option value="لا يطابق المواصفات">لا يطابق المواصفات المطلوبة</option>
                                <option value="رغبة العميل بالاسترجاع">رغبة العميل بالاسترجاع</option>
                                <option value="خطأ في الطلب">خطأ في الطلب</option>
                                <option value="منتج مكرر">منتج مكرر</option>
                            </select>
                        </div>

                        <div class="form-group mb-4">
                            <label class="font-weight-bold text-secondary mb-1">تاريخ الاسترجاع</label>
                            <input type="date" name="return_date" class="form-control rounded-0"
                                   value="<?php echo date('Y-m-d'); ?>" required>
                        </div>

                        <button type="submit" name="btn_save_return" class="btn-flat btn-flat-primary btn-block py-2">
                            <i class="fa fa-check ml-1"></i> تأكيد المرتجع وتحديث المخزون
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ===== سجل المردودات + المردودات السابقة للفاتورة ===== -->
    <div class="col-lg-6 mb-4">
        <!-- مردودات الفاتورة المحددة -->
        <div id="invoiceReturnsSection" style="display:none;" class="card-flat mb-3">
            <div class="card-header bg-warning">
                <h6 class="mb-0 font-weight-bold text-dark"><i class="fa fa-history ml-1"></i> مردودات سابقة لهذه الفاتورة</h6>
            </div>
            <div class="card-body p-0">
                <table class="table-flat mb-0" id="prevReturnsTable">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>المنتج</th>
                            <th class="text-center">الكمية</th>
                            <th>المسترد</th>
                            <th class="text-center">إلغاء</th>
                        </tr>
                    </thead>
                    <tbody id="prevReturnsBody">
                    </tbody>
                </table>
            </div>
        </div>

        <!-- سجل كل المردودات -->
        <div class="card-flat">
            <div class="card-header">
                <h5><i class="fa fa-list ml-2"></i> سجل المردودات الكامل</h5>
                <small class="text-muted">آخر 150 مرتجع</small>
            </div>
            <div class="card-body p-0" style="max-height:430px; overflow-y:auto;">
                <table class="table-flat mb-0">
                    <thead>
                        <tr>
                            <th style="width:7%;">#</th>
                            <th style="width:10%;">فاتورة</th>
                            <th>المنتج</th>
                            <th style="width:8%;" class="text-center">كمية</th>
                            <th style="width:13%;">المسترد</th>
                            <th style="width:10%;">الحالة</th>
                            <th style="width:12%;">التاريخ</th>
                            <th style="width:8%;" class="no-print">إلغاء</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($all_returns)): ?>
                        <tr><td colspan="8" class="text-center text-muted p-4">لا توجد مردودات مسجلة بعد</td></tr>
                        <?php else: ?>
                        <?php foreach ($all_returns as $r): ?>
                        <tr class="<?php echo $r['status']==='cancelled' ? 'text-muted' : ''; ?>"
                            style="<?php echo $r['status']==='cancelled' ? 'opacity:0.6; text-decoration:line-through;' : ''; ?>">
                            <td class="font-weight-bold text-secondary">#<?php echo $r['id']; ?></td>
                            <td>
                                <a href="view.php?id=<?php echo $r['sales_id']; ?>" class="text-primary font-weight-bold text-decoration-none">
                                    #<?php echo $r['sales_id']; ?>
                                </a>
                            </td>
                            <td class="small font-weight-bold"><?php echo htmlspecialchars($r['product_name']); ?></td>
                            <td class="text-center text-danger font-weight-bold"><?php echo $r['quantity']; ?></td>
                            <td class="font-weight-bold"><?php echo number_format($r['refund_amount'], 2); ?></td>
                            <td>
                                <?php if ($r['status'] === 'active'): ?>
                                    <span class="badge badge-success invoice-badge">نشط</span>
                                <?php else: ?>
                                    <span class="badge badge-secondary invoice-badge">ملغى</span>
                                <?php endif; ?>
                            </td>
                            <td class="small"><?php echo htmlspecialchars($r['return_date']); ?></td>
                            <td class="no-print">
                                <?php if ($r['status'] === 'active'): ?>
                                <a href="returns.php?cancel_ret=<?php echo $r['id']; ?>"
                                   onclick="return confirm('هل تريد إلغاء هذا المرتجع؟ سيتم عكس جميع العمليات.')"
                                   class=" btn-sm py-1 px-2 text-decoration-none">
                                    <i class="bi bi-x-circle text-danger"  title="الغاء المرتجع"></i>
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

<!-- ===== JavaScript ===== -->
<script>
const recentInvoices = <?php echo json_encode($recent_invoices, JSON_UNESCAPED_UNICODE); ?>;

// البحث بالضغط على Enter في حقل الرقم
document.getElementById('invoiceSearchInput').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') { e.preventDefault(); searchInvoice(); }
});

function searchInvoice() {
    const invId = parseInt(document.getElementById('invoiceSearchInput').value);
    if (!invId || invId <= 0) {
        alert('الرجاء إدخال رقم فاتورة صحيح.');
        return;
    }

    // إعادة تعيين الحقول
    resetReturnForm();

    // جلب بيانات الفاتورة عبر AJAX
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

    // عرض معلومات الفاتورة
    document.getElementById('invNum').textContent   = '#' + inv.id;
    document.getElementById('invCust').textContent  = inv.cust_name || 'عميل نقدي';
    document.getElementById('invDate').textContent  = inv.build_date;
    document.getElementById('invTotal').textContent = formatNum(inv.total) + ' ر.ي';
    document.getElementById('ret_sales_id').value   = inv.id;
    document.getElementById('invoiceInfo').style.display = 'block';

    // التحكم في خيار خصم المديونية بناء على اسم العميل
    const isCashCust = !inv.cust_name || inv.cust_name === 'عميل نقدي';
    const optCredit = document.getElementById('refund_credit_opt');
    const selectMethod = document.getElementById('refund_method');
    if (isCashCust) {
        optCredit.disabled = true;
        selectMethod.value = 'cash';
    } else {
        optCredit.disabled = false;
    }

    // عرض جدول المنتجات
    const tbody = document.getElementById('invoiceItemsBody');
    tbody.innerHTML = '';

    if (items.length === 0) {
        tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted p-3">لا توجد بنود في هذه الفاتورة</td></tr>';
    } else {
        items.forEach(item => {
            const canReturn = item.can_return;
            const disabledClass = canReturn <= 0 ? 'no-return' : 'can-return';
            const disabledAttr  = canReturn <= 0 ? 'disabled' : '';

            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td class="font-weight-bold small">${escHtml(item.name)}</td>
                <td class="text-center">${item.quantity}</td>
                <td class="text-center ${disabledClass}">${canReturn > 0 ? canReturn : 'مرتجع كلياً'}</td>
                <td class="text-center small">${formatNum(item.unit_price)}</td>
                <td class="text-center no-print">
                    <button type="button" class="btn-flat btn-primary btn-sm py-1 px-2" ${disabledAttr}
                        onclick="selectProduct(${item.product_id}, '${escHtml(item.name)}', ${item.unit_price}, ${item.buy_price}, ${canReturn})">
                        <i class="bi bi-check-square text-white" title="اختيار المنتج"></i> 
                    </button>
                </td>
            `;
            tbody.appendChild(tr);
        });
    }

    document.getElementById('invoiceItemsSection').style.display = 'block';

    // مردودات سابقة لهذه الفاتورة
    const prevBody = document.getElementById('prevReturnsBody');
    prevBody.innerHTML = '';
    if (prevReturns.length > 0) {
        prevReturns.forEach(r => {
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td class="text-secondary">#${r.id}</td>
                <td class="small">${escHtml(r.product)}</td>
                <td class="text-center text-danger font-weight-bold">${r.qty}</td>
                <td>${formatNum(r.refund)} ر.ي</td>
                <td class="text-center">
                    <a href="returns.php?cancel_ret=${r.id}"
                       onclick="return confirm('إلغاء هذا المرتجع؟')"
                       class=" btn-sm py-1 px-2 text-decoration-none">
                        <i class="bi bi-x-circle text-danger" title="الغاء المرتجع"></i>
                    </a>
                </td>
            `;
            prevBody.appendChild(tr);
        });
        document.getElementById('invoiceReturnsSection').style.display = 'block';
    } else {
        document.getElementById('invoiceReturnsSection').style.display = 'none';
    }
}

function selectProduct(productId, productName, unitPrice, buyPrice, maxQty) {
    document.getElementById('ret_product_id').value    = productId;
    document.getElementById('ret_product_name').value  = productName;
    document.getElementById('ret_unit_price').value    = unitPrice;
    document.getElementById('ret_buy_price').value     = buyPrice;
    document.getElementById('maxRetQty').textContent   = maxQty;
    document.getElementById('ret_qty').max             = maxQty;
    document.getElementById('ret_qty').value           = 1;
    document.getElementById('selectedProductLabel').textContent = productName;
    document.getElementById('returnFormFields').style.display = 'block';
    calcReturnAmount();

    // تمييز الصف المحدد
    document.querySelectorAll('#invoiceItemsTable tr').forEach(r => r.classList.remove('table-active'));
    event.target.closest('tr').classList.add('table-active');
}

function calcReturnAmount() {
    const qty       = parseInt(document.getElementById('ret_qty').value) || 0;
    const maxQty    = parseInt(document.getElementById('maxRetQty').textContent) || 0;
    const unitPrice = parseFloat(document.getElementById('ret_unit_price').value) || 0;

    if (qty > maxQty) {
        document.getElementById('ret_qty').value = maxQty;
        alert('لا يمكن إرجاع أكثر من ' + maxQty + ' وحدة!');
        return;
    }

    const refund = qty * unitPrice;
    document.getElementById('ret_refund_display').value = formatNum(refund) + ' ر.ي';
}

function resetReturnForm() {
    document.getElementById('invoiceInfo').style.display            = 'none';
    document.getElementById('invoiceItemsSection').style.display    = 'none';
    document.getElementById('returnFormFields').style.display       = 'none';
    document.getElementById('invoiceReturnsSection').style.display  = 'none';
    document.getElementById('ret_sales_id').value   = '0';
    document.getElementById('ret_product_id').value = '0';
    document.getElementById('ret_product_name').value = '';
    document.getElementById('ret_unit_price').value  = '0';
    document.getElementById('ret_buy_price').value   = '0';
    document.getElementById('invoiceItemsBody').innerHTML = '';
    document.getElementById('prevReturnsBody').innerHTML  = '';
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

<?php require_once($dir_prefix . 'includes/footer.php'); ?>
