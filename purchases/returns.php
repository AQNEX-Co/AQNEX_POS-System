<?php
$dir_prefix = '../';
$module = 'purchases';
$no_print_header = true;
require_once($dir_prefix . 'includes/header.php');
check_permission(['admin', 'inventory', 'cashier']);

$settings = $global_settings;
$error = '';
$success = '';
$saved_return_id = 0;
$today_date = date('Y-m-d');

// ==========================================
// معالجة تسجيل مرتجع مشتريات
// ==========================================
if (isset($_POST['btn_save_return'])) {
    $purchase_id   = intval($_POST['purchase_id']);
    $product_id    = intval($_POST['product_id']);
    $p_name        = $conn->real_escape_string(trim($_POST['product_name']));
    $qty           = intval($_POST['qty_return']);
    $unit_price    = doubleval($_POST['unit_price_yer']);   // بالريال اليمني
    $refund_amount = $qty * $unit_price;
    $reason        = $conn->real_escape_string(trim($_POST['reason']));
    $return_date   = $conn->real_escape_string($_POST['return_date']);
    $user_name     = $conn->real_escape_string($_SESSION['SESS_FIRST_NAME']);
    $refund_method = $_POST['refund_method'] === 'credit' ? 'credit' : 'cash';
    $refund_source = (isset($_POST['refund_source']) && $_POST['refund_source'] === 'box') ? 'box' : 'purchases';

    $active_box_id = get_user_box_id($conn, $_SESSION['SESS_MEMBER_ID']);
    $box_name = get_box_name($conn, $active_box_id);

    // جلب تفاصيل الفاتورة
    $res_pur = $conn->query("SELECT * FROM purchases WHERE id = $purchase_id LIMIT 1");
    $pur_row = $res_pur ? $res_pur->fetch_assoc() : null;

    if ($qty <= 0 || $product_id <= 0 || empty($p_name)) {
        $error = 'الرجاء تحديد المنتج والكمية المرتجعة بشكل صحيح.';
    } elseif ($purchase_id <= 0 || !$pur_row) {
        $error = 'الرجاء تحديد فاتورة المشتريات الأصلية أولاً.';
    } else {
        $supplier_name = $pur_row['supp_name'];
        $currency_code = $pur_row['currency_code'] ?? 'YER';
        $exchange_rate = doubleval($pur_row['exchange_rate'] ?? 1.0);
        if ($exchange_rate <= 0) $exchange_rate = 1.0;

        // التحقق من الكمية المتوفرة في المخزن لهذا المنتج
        $res_prod = $conn->query("SELECT quantity FROM products WHERE id = $product_id LIMIT 1");
        $prod_qty = $res_prod ? intval($res_prod->fetch_assoc()['quantity']) : 0;

        // التحقق من الكمية الممكن إرجاعها من هذه الفاتورة
        $res_chk = $conn->query("SELECT COALESCE(SUM(quantity),0) AS ret FROM purchase_returns WHERE purchase_id=$purchase_id AND product_id=$product_id AND status='active'");
        $already_ret = $res_chk ? intval($res_chk->fetch_assoc()['ret']) : 0;

        // جلب الكمية المشتراة في الفاتورة الأصلية
        $res_orig = $conn->query("SELECT quantity FROM purchase_items WHERE buys_date = '" . $conn->real_escape_string($pur_row['date']) . "' AND supp_name = '" . $conn->real_escape_string($supplier_name) . "' AND name = '" . $conn->real_escape_string($p_name) . "' LIMIT 1");
        $orig_qty = $res_orig ? intval($res_orig->fetch_assoc()['quantity']) : 0;
        $can_return = $orig_qty - $already_ret;

        if ($qty > $can_return) {
            $error = "لا يمكن إرجاع {$qty} وحدة. المتبقي القابل للإرجاع من الفاتورة: {$can_return} وحدة (الكمية المشتراة: {$orig_qty} وحدة).";
        } elseif ($qty > $prod_qty) {
            $error = "لا يمكن إرجاع {$qty} وحدة. الكمية المتوفرة حالياً في المخزن هي {$prod_qty} وحدة فقط.";
        } else {
            // البدء في المعاملة المالية وقيد المرتجع
            $conn->begin_transaction();
            try {
                // 1. إدراج سجل المرتجع
                $sql_ins = "INSERT INTO purchase_returns
                    (purchase_id, product_id, product_name, quantity, unit_price, refund_amount,
                     reason, return_date, user, status, box_id, refund_method, refund_source, currency_code, exchange_rate)
                    VALUES
                    ($purchase_id, $product_id, '$p_name', $qty, $unit_price, $refund_amount,
                     '$reason', '$return_date', '$user_name', 'active', $active_box_id, '$refund_method', '$refund_source', '$currency_code', $exchange_rate)";
                
                if (!$conn->query($sql_ins)) {
                    throw new Exception('فشل حفظ المرتجع: ' . $conn->error);
                }
                
                $saved_return_id = $conn->insert_id;

                // 2. تخفيض الكمية في المخزن
                $conn->query("UPDATE products SET quantity = quantity - $qty WHERE id = $product_id");

                // 3. إضافة سجل حركة المخزن
                $conn->query("INSERT INTO inventory_log (product_id, product_name, type, qty_change, new_qty, reason, user)
                              SELECT id, name, 'manual', -$qty, quantity, 'مرتجع شراء فاتورة رقم #{$purchase_id} - {$reason}', '$user_name'
                              FROM products WHERE id = $product_id LIMIT 1");

                // 4. استرداد المبلغ
                if ($refund_method === 'cash') {
                    // إذا كان الاسترداد نقداً، تزيد سيولة الصندوق
                    if ($refund_source === 'box' && $refund_amount > 0) {
                        update_box_balance($conn, $active_box_id, $refund_amount, 'addition', "مرتجع شراء فاتورة #{$purchase_id} - {$p_name}", $return_date);
                    }
                    
                    // قيد محاسبي للمرتجع النقدي
                    $debit_acc = ($refund_source === 'box') ? 'الصندوق - ' . $box_name : 'المشتريات';
                    post_journal_entry($conn, 'return', $saved_return_id, $debit_acc, 'المخزون / البضاعة', $refund_amount, "مرتجع مشتريات نقداً #{$purchase_id} - {$p_name}", $user_name, $active_box_id);
                } else {
                    // خصم من مديونية المورد
                    if ($refund_amount > 0 && !empty($supplier_name)) {
                        $supp_esc = $conn->real_escape_string($supplier_name);
                        $conn->query("UPDATE suppliers SET supp_daain = GREATEST(0, supp_daain - $refund_amount) WHERE supp_name='$supp_esc'");
                    }
                    
                    // قيد محاسبي للمرتجع الآجل
                    post_journal_entry($conn, 'return', $saved_return_id, 'الذمم الدائنة - ' . $supplier_name, 'المخزون / البضاعة', $refund_amount, "مرتجع مشتريات آجل (خصم مديونية) #{$purchase_id} - {$p_name}", $user_name, $active_box_id);
                }

                $conn->commit();
                $success = "✓ تم تسجيل مرتجع شراء {$qty} وحدة من \"{$p_name}\" بنجاح! طريقة الرد: " . ($refund_method === 'cash' ? 'نقداً إلى الصندوق' : 'خصم من حساب المورد') . ". المبلغ: " . number_format($refund_amount, 2) . " ر.ي";
            } catch (Exception $e) {
                $conn->rollback();
                $error = 'حدث خطأ أثناء معالجة المرتجع: ' . $e->getMessage();
            }
        }
    }
}

// ==========================================
// معالجة إلغاء مرتجع مشتريات
// ==========================================
if (isset($_GET['cancel_ret']) && is_numeric($_GET['cancel_ret'])) {
    $ret_id = intval($_GET['cancel_ret']);
    $res_ret = $conn->query("SELECT * FROM purchase_returns WHERE id=$ret_id AND status='active' LIMIT 1");
    $ret_row = $res_ret ? $res_ret->fetch_assoc() : null;

    if ($ret_row) {
        $conn->begin_transaction();
        try {
            $conn->query("UPDATE purchase_returns SET status='cancelled' WHERE id=$ret_id");
            
            $qty           = intval($ret_row['quantity']);
            $product_id    = intval($ret_row['product_id']);
            $refund        = doubleval($ret_row['refund_amount']);
            $p_id          = intval($ret_row['purchase_id']);
            $refund_method = $ret_row['refund_method'];
            $box_id        = intval($ret_row['box_id']);
            $box_name      = get_box_name($conn, $box_id);
            $uname         = $conn->real_escape_string($_SESSION['SESS_FIRST_NAME']);

            // جلب اسم المورد
            $res_pur = $conn->query("SELECT supp_name FROM purchases WHERE id=$p_id LIMIT 1");
            $pur_row = $res_pur ? $res_pur->fetch_assoc() : null;
            $supplier_name = $pur_row ? $pur_row['supp_name'] : '';

            // إعادة إضافة الكمية للمخزن
            $conn->query("UPDATE products SET quantity = quantity + $qty WHERE id=$product_id");

            // سجل حركة المخزن
            $conn->query("INSERT INTO inventory_log (product_id, product_name, type, qty_change, new_qty, reason, user)
                          SELECT id, name, 'manual', $qty, quantity, 'إلغاء مرتجع شراء #{$ret_id}', '$uname'
                          FROM products WHERE id = $product_id LIMIT 1");

            if ($refund_method === 'cash') {
                $refund_source = $ret_row['refund_source'];
                // خصم المبلغ من الصندوق مجدداً
                if ($refund_source === 'box') {
                    update_box_balance($conn, $box_id, $refund, 'discount', "إلغاء مرتجع شراء #{$ret_id}", $today_date);
                }
                
                // قيد عكسي
                $debit_acc = ($refund_source === 'box') ? 'الصندوق - ' . $box_name : 'المشتريات';
                post_journal_entry($conn, 'return', $ret_id, 'المخزون / البضاعة', $debit_acc, $refund, "إلغاء مرتجع شراء نقدي #{$ret_id}", $uname, $box_id);
            } else {
                // إعادة مديونية المورد
                if (!empty($supplier_name)) {
                    $supp_esc = $conn->real_escape_string($supplier_name);
                    $conn->query("UPDATE suppliers SET supp_daain = supp_daain + $refund WHERE supp_name='$supp_esc'");
                }
                
                // قيد عكسي
                post_journal_entry($conn, 'return', $ret_id, 'المخزون / البضاعة', 'الذمم الدائنة - ' . $supplier_name, $refund, "إلغاء مرتجع شراء آجل #{$ret_id}", $uname, $box_id);
            }

            $conn->commit();
            $success = '✓ تم إلغاء مرتجع الشراء #' . $ret_id . ' بنجاح وإعادة الكمية للمخزون.';
        } catch (Exception $e) {
            $conn->rollback();
            $error = 'فشل إلغاء المرتجع: ' . $e->getMessage();
        }
    }
}

// جلب آخر فواتير المشتريات للبحث السريع
$recent_purchases = [];
$res_pur_list = $conn->query("SELECT id, supp_name, total, date FROM purchases ORDER BY id DESC LIMIT 200");
if ($res_pur_list) {
    while($r = $res_pur_list->fetch_assoc()) $recent_purchases[] = $r;
}

// جلب سجل المردودات الكامل للمشتريات
$all_returns = [];
$res_all_ret = $conn->query("SELECT pr.*, p.date as purchase_date, p.supp_name
                              FROM purchase_returns pr
                              LEFT JOIN purchases p ON pr.purchase_id = p.id
                              ORDER BY pr.id DESC LIMIT 150");
if ($res_all_ret) {
    while($r = $res_all_ret->fetch_assoc()) $all_returns[] = $r;
}
?>
<title>مردودات المشتريات - <?php echo htmlspecialchars($settings['store_name'] ?? 'النظام'); ?></title>

<style>
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
    $res_ret_view = $conn->query("SELECT pr.*, p.supp_name, p.date as purchase_date FROM purchase_returns pr LEFT JOIN purchases p ON pr.purchase_id=p.id WHERE pr.id=$saved_return_id LIMIT 1");
    $ret_view = $res_ret_view ? $res_ret_view->fetch_assoc() : null;
?>
<!-- ===== إيصال المرتجع للطباعة ===== -->
<div class="alert alert-success rounded-0 mb-3 no-print">
    <?php echo $success; ?>
    <button onclick="window.print()" class="btn-flat btn-flat-primary btn-sm mr-3">
          <?php echo get_icon('print', 'ml-1'); ?> طباعة إيصال المرتجع
    </button>
    <a href="returns.php" class="btn-flat btn-flat-secondary btn-sm text-decoration-none mr-2">إدارة المردودات</a>
    <a href="index.php" class="btn-flat btn-flat-secondary btn-sm text-decoration-none">قائمة المشتريات</a>
</div>

<!-- إيصال المرتجع للطباعة -->
<div class="receipt-print" id="returnReceipt" style="display:block; border: 1px solid #333; max-width:350px; margin: 0 auto; padding: 15px; font-family: 'Courier New', monospace; direction: rtl;">
    <div style="text-align:center; border-bottom: 2px dashed #333; padding-bottom:10px; margin-bottom:10px;">
        <h4 style="margin:0; font-weight:bold;"><?php echo htmlspecialchars($settings['store_name'] ?? 'المتجر'); ?></h4>
        <small><?php echo htmlspecialchars($settings['address'] ?? ''); ?></small><br>
        <small>ت: <?php echo htmlspecialchars($settings['phone'] ?? ''); ?></small>
    </div>

    <div style="text-align:center; background:#f0f0f0; padding:5px; margin-bottom:10px;">
        <strong style="font-size:1.1rem;">إيصال مرتجع مشتريات</strong><br>
        <small>رقم المرتجع: <strong>#<?php echo $saved_return_id; ?></strong></small>
    </div>

    <?php if ($ret_view): ?>
    <table style="width:100%; font-size:0.85rem; margin-bottom:10px;">
        <tr><td>فاتورة شراء أصلية:</td><td style="text-align:left;"><strong>#<?php echo $ret_view['purchase_id']; ?></strong></td></tr>
        <tr><td>المورد:</td><td style="text-align:left;"><?php echo htmlspecialchars($ret_view['supp_name'] ?? '-'); ?></td></tr>
        <tr><td>تاريخ المردود:</td><td style="text-align:left;"><?php echo htmlspecialchars($ret_view['return_date']); ?></td></tr>
        <tr><td>المنتج:</td><td style="text-align:left;"><strong><?php echo htmlspecialchars($ret_view['product_name']); ?></strong></td></tr>
        <tr><td>الكمية المرتجعة:</td><td style="text-align:left;"><?php echo intval($ret_view['quantity']); ?> وحدة</td></tr>
        <tr><td>سعر الوحدة:</td><td style="text-align:left;"><?php echo number_format($ret_view['unit_price'], 2); ?> ر.ي</td></tr>
        <tr style="font-size:1rem; font-weight:bold; border-top:2px solid #333;">
            <td>المبلغ المسترد:</td>
            <td style="text-align:left;"><?php echo number_format($ret_view['refund_amount'], 2); ?> ر.ي</td>
        </tr>
        <tr><td>السبب:</td><td style="text-align:left; font-size:0.8rem;"><?php echo htmlspecialchars($ret_view['reason']); ?></td></tr>
        <tr><td>المسؤول:</td><td style="text-align:left;"><?php echo htmlspecialchars($ret_view['user']); ?></td></tr>
    </table>
    <?php endif; ?>

    <div style="text-align:center; border-top: 2px dashed #333; padding-top:8px; font-size:0.8rem;">
        <?php echo htmlspecialchars($settings['receipt_footer'] ?? ''); ?>
    </div>
</div>

<script>
document.addEventListener('keydown', function(e) {
    if (e.key === 'Enter') { e.preventDefault(); window.print(); }
});
</script>
<hr class="my-4 no-print">
<?php endif; ?>

<?php if (!empty($error)): ?>
<div class="alert alert-danger rounded-0 mb-4 no-print"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<!-- ===== رأس الصفحة ===== -->
<div class="row mb-3 no-print">
    <div class="col-md-7">
        <h3 class="text-secondary font-weight-bold mb-1">
            <i class="fa fa-undo ml-2 text-primary"></i> إدارة مردودات المشتريات
        </h3>
        <p class="text-muted small mb-0">ابحث عن فاتورة الشراء، حدد البنود المرتجعة للمورد، وسيحتسب المبلغ تلقائياً.</p>
    </div>
    <div class="col-md-5 text-left">
        <a href="index.php" class="btn-flat btn-flat-secondary btn-sm text-decoration-none ml-2">
            <i class="fa fa-list ml-1"></i> قائمة المشتريات
        </a>
        <a href="create.php" class="btn-flat btn-flat-primary btn-sm text-decoration-none">
            <i class="fa fa-plus ml-1"></i> فاتورة مشتريات جديدة
        </a>
    </div>
</div>

<div class="row no-print">
    <!-- ===== نموذج تسجيل المرتجع ===== -->
    <div class="col-lg-6 mb-4">
        <div class="card-flat ret-card">
            <div class="card-header" style="background: var(--secondary); color: #fff;">
                <h5 class="mb-0"><i class="fa fa-undo ml-2"></i> تسجيل مرتجع شراء جديد</h5>
            </div>
            <div class="card-body">

                <!-- بحث الفاتورة -->
                <div class="form-group mb-3">
                    <label class="font-weight-bold text-secondary mb-1">
                        <i class="fa fa-search ml-1"></i> رقم فاتورة الشراء *
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
                    <small class="text-muted">اضغط Enter أو زر بحث لتحميل البنود المشتراة</small>
                </div>

                <!-- معلومات الفاتورة المجلوبة -->
                <div id="invoiceInfo" style="display:none;" class="alert alert-info rounded-0 p-2 mb-3">
                    <div class="row">
                        <div class="col-6"><strong>فاتورة مشتريات #:</strong> <span id="invNum">-</span></div>
                        <div class="col-6"><strong>المورد:</strong> <span id="invSupp">-</span></div>
                        <div class="col-6"><strong>التاريخ:</strong> <span id="invDate">-</span></div>
                        <div class="col-6"><strong>الإجمالي:</strong> <span id="invTotal">-</span></div>
                    </div>
                </div>

                <!-- جدول منتجات الفاتورة -->
                <div id="invoiceItemsSection" style="display:none;">
                    <label class="font-weight-bold text-secondary mb-2">
                        <i class="fa fa-list ml-1"></i> اختر المنتج المرتجع للمورد
                    </label>
                    <div class="table-responsive mb-3" style="max-height: 260px; overflow-y: auto; border: 1px solid #e2e8f0;">
                        <table class="table-flat mb-0" id="invoiceItemsTable">
                            <thead>
                                <tr>
                                    <th>المنتج</th>
                                    <th style="width:12%;" class="text-center">المشتراة</th>
                                    <th style="width:18%;" class="text-center">قابل للإرجاع</th>
                                    <th style="width:16%;" class="text-center">سعر الشراء</th>
                                    <th style="width:12%;" class="no-print text-center">تحديد</th>
                                </tr>
                            </thead>
                            <tbody id="invoiceItemsBody">
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- نموذج المرتجع تفصيلياً -->
                <form method="POST" id="returnForm">
                    <input type="hidden" name="purchase_id"    id="ret_purchase_id" value="0">
                    <input type="hidden" name="product_id"     id="ret_product_id" value="0">
                    <input type="hidden" name="product_name"   id="ret_product_name" value="">
                    <input type="hidden" name="unit_price_yer" id="ret_unit_price" value="0">

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
                                <small class="text-muted">الحد الأقصى: <span id="maxRetQty">0</span> وحدة</small>
                            </div>
                            <div class="col-md-6 form-group mb-3">
                                <label class="font-weight-bold text-secondary mb-1">المبلغ المسترد (ر.ي)</label>
                                <input type="text" id="ret_refund_display" class="form-control rounded-0 text-center font-weight-bold bg-light text-danger" readonly value="0.00">
                                <small class="text-muted">يُحتسب تلقائياً × سعر الشراء الأصلي</small>
                            </div>
                        </div>

                        <div class="form-group mb-3">
                            <label class="font-weight-bold text-secondary mb-1">طريقة رد المبلغ من المورد *</label>
                            <select name="refund_method" id="refund_method" class="form-control rounded-0" required>
                                <option value="cash" selected>استلام نقدي إلى الصندوق</option>
                                <option value="credit">خصم من حساب المورد (مديونية المورد)</option>
                            </select>
                        </div>

                        <div class="form-group mb-3">
                            <label class="font-weight-bold text-secondary mb-1">سبب الإرجاع للمورد *</label>
                            <select name="reason" class="form-control rounded-0" required>
                                <option value="">-- اختر السبب --</option>
                                <option value="منتج تالف / معيب من المصنع">منتج تالف / معيب من المصنع</option>
                                <option value="شحنة خاطئة / لا تطابق المطلوب">شحنة خاطئة / لا تطابق المطلوب</option>
                                <option value="انتهاء تاريخ الصلاحية أو الجودة">انتهاء تاريخ الصلاحية أو الجودة</option>
                                <option value="فائض عن حاجة المستودع">فائض عن حاجة المستودع</option>
                            </select>
                        </div>

                        <div class="form-group mb-4">
                            <label class="font-weight-bold text-secondary mb-1">تاريخ الاسترجاع</label>
                            <input type="date" name="return_date" class="form-control rounded-0"
                                   value="<?php echo date('Y-m-d'); ?>" required>
                        </div>

                        <button type="submit" name="btn_save_return" class="btn-flat btn-flat-primary btn-block py-2">
                            <i class="fa fa-check ml-1"></i> تأكيد المرتجع للمورد وتحديث المخزون
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

        <!-- سجل كل مردودات المشتريات -->
        <div class="card-flat">
            <div class="card-header">
                <h5><i class="fa fa-list ml-2"></i> سجل مردودات المشتريات الكامل</h5>
                <small class="text-muted">آخر 150 مرتجع شراء</small>
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
                        <tr><td colspan="8" class="text-center text-muted p-4">لا توجد مردودات مشتريات مسجلة بعد</td></tr>
                        <?php else: ?>
                        <?php foreach ($all_returns as $r): ?>
                        <tr class="<?php echo $r['status']==='cancelled' ? 'text-muted' : ''; ?>"
                            style="<?php echo $r['status']==='cancelled' ? 'opacity:0.6; text-decoration:line-through;' : ''; ?>">
                            <td class="font-weight-bold text-secondary">#<?php echo $r['id']; ?></td>
                            <td>
                                <a href="view.php?id=<?php echo $r['purchase_id']; ?>" class="text-primary font-weight-bold text-decoration-none">
                                    #<?php echo $r['purchase_id']; ?>
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
                                   onclick="return confirm('هل تريد إلغاء هذا المرتجع للمورد؟ سيتم إعادة البضاعة للمخزن وعكس الحركات المالية.')"
                                   class=" btn-sm py-1 px-2 text-decoration-none">
                                    <i class="bi bi-x-circle text-danger" title="إلغاء المرتجع"></i>
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
    document.getElementById('invSupp').textContent  = inv.supp_name || 'غير محدد';
    document.getElementById('invDate').textContent  = inv.date;
    document.getElementById('invTotal').textContent = formatNum(inv.total) + ' ر.ي';
    document.getElementById('ret_purchase_id').value = inv.id;
    document.getElementById('invoiceInfo').style.display = 'block';

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
                <td class="text-center ${disabledClass}">${canReturn > 0 ? canReturn + ' (المستودع: ' + item.current_stock + ')' : 'غير قابل للإرجاع'}</td>
                <td class="text-center small">${formatNum(item.unit_price)}</td>
                <td class="text-center no-print">
                    <button type="button" class="btn-flat btn-primary btn-sm py-1 px-2" ${disabledAttr}
                        onclick="selectProduct(${item.product_id}, '${escHtml(item.name)}', ${item.unit_price}, ${canReturn})">
                        <i class="bi bi-check-square text-white" title="اختيار المنتج"></i> 
                    </button>
                </td>
            `;
            tbody.appendChild(tr);
        });
    }

    document.getElementById('invoiceItemsSection').style.display = 'block';

    // عرض مردودات سابقة لهذه الفاتورة
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
                        <i class="bi bi-x-circle text-danger" title="إلغاء المرتجع"></i>
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

function selectProduct(productId, productName, unitPrice, maxQty) {
    document.getElementById('ret_product_id').value    = productId;
    document.getElementById('ret_product_name').value  = productName;
    document.getElementById('ret_unit_price').value    = unitPrice;
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
    document.getElementById('ret_purchase_id').value = '0';
    document.getElementById('ret_product_id').value = '0';
    document.getElementById('ret_product_name').value = '';
    document.getElementById('ret_unit_price').value  = '0';
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
