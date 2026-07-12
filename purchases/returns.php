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

// ==========================================
// معالجة تسجيل مرتجع مشتريات (متعدد الأصناف)
// ==========================================
if (isset($_POST['btn_save_return'])) {
    $purchase_id   = intval($_POST['purchase_id']);
    $reason        = $conn->real_escape_string(trim($_POST['reason']));
    $return_date   = $conn->real_escape_string($_POST['return_date']);
    $refund_method = $_POST['refund_method'] === 'credit' ? 'credit' : 'cash';
    $refund_source = 'box'; // استرداد نقدي للصندوق مباشرة

    // جلب بيانات الفاتورة الأصلية للمقارنة
    $pur_res = $conn->query("SELECT * FROM purchases WHERE id = $purchase_id LIMIT 1");
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
        $supplier_name = $pur_row['supp_name'];
        $currency_code = $pur_row['currency_code'] ?? 'YER';
        $exchange_rate = doubleval($pur_row['exchange_rate'] ?? 1.0);
        if ($exchange_rate <= 0) $exchange_rate = 1.0;

        $conn->begin_transaction();
        try {
            $has_processed = false;
            $total_refund_amount = 0;

            $count = count($product_ids);
            for ($i = 0; $i < $count; $i++) {
                $product_id = intval($product_ids[$i]);
                $p_name = trim($product_names[$i]);
                $qty = intval($qty_returns[$i]);
                $unit_price = doubleval($unit_prices[$i]); // سعر البند بالفاتورة (YER)
                $unit_name = isset($unit_names[$i]) ? trim($unit_names[$i]) : 'الوحدة الأساسية';
                if (empty($unit_name)) $unit_name = 'الوحدة الأساسية';

                if ($qty <= 0) continue;

                $refund_amount = $qty * $unit_price;
                $p_name_esc = $conn->real_escape_string($p_name);
                $unit_name_esc = $conn->real_escape_string($unit_name);

                // التحقق من الكمية المتوفرة في المخزن لهذا المنتج بالوحدة الأساسية
                $res_prod = $conn->query("SELECT quantity FROM products WHERE id = $product_id LIMIT 1");
                $prod_qty = $res_prod ? intval($res_prod->fetch_assoc()['quantity']) : 0;

                // التحقق من الكمية الممكن إرجاعها من هذه الفاتورة
                $res_chk = $conn->query("SELECT COALESCE(SUM(quantity),0) AS ret FROM purchase_returns WHERE purchase_id=$purchase_id AND product_id=$product_id AND status='active'");
                $already_ret = $res_chk ? intval($res_chk->fetch_assoc()['ret']) : 0;

                // جلب الكمية المشتراة في الفاتورة الأصلية
                $p_name_store = $p_name;
                if (!empty($unit_name) && $unit_name !== 'الوحدة الأساسية' && strpos($p_name_store, "($unit_name)") === false) {
                    $p_name_store .= " ($unit_name)";
                }
                $p_name_store_esc = $conn->real_escape_string($p_name_store);
                
                $res_orig = $conn->query("SELECT quantity FROM purchase_items WHERE purchase_id = $purchase_id AND name = '$p_name_store_esc' LIMIT 1");
                $orig_qty = $res_orig ? intval($res_orig->fetch_assoc()['quantity']) : 0;
                $can_return = $orig_qty - $already_ret;

                if ($qty > $can_return) {
                    throw new Exception("لا يمكن إرجاع {$qty} وحدة من المنتج \"{$p_name}\". المتبقي القابل للإرجاع هو {$can_return} وحدة.");
                }

                // حساب كمية المرتجع بالوحدات الأساسية
                $conv_factor = 1.0;
                if (!empty($unit_name) && $unit_name !== 'الوحدة الأساسية') {
                    $u_res = $conn->query("SELECT conversion_factor FROM product_units WHERE product_id = $product_id AND unit_name = '$unit_name_esc' LIMIT 1");
                    if ($u_res && $u_res->num_rows > 0) {
                        $conv_factor = doubleval($u_res->fetch_assoc()['conversion_factor']);
                    }
                }
                $base_qty = $qty * $conv_factor;

                if ($base_qty > $prod_qty) {
                    throw new Exception("لا يمكن إرجاع الكمية المطلوبة من الصنف \"{$p_name}\". المتوفر بالمستودع حالياً هو {$prod_qty} وحدة أساسية (المطلوب سحبه: {$base_qty}).");
                }

                // 1. إدراج سجل المرتجع
                $sql_ins = "INSERT INTO purchase_returns
                    (purchase_id, product_id, product_name, quantity, unit_price, refund_amount,
                     reason, return_date, user, status, box_id, refund_method, refund_source, currency_code, exchange_rate, unit_name)
                    VALUES
                    ($purchase_id, $product_id, '$p_name_esc', $qty, $unit_price, $refund_amount,
                     '$reason', '$return_date', '$user_name', 'active', $active_box_id, '$refund_method', '$refund_source', '$currency_code', $exchange_rate, '$unit_name_esc')";
                
                if (!$conn->query($sql_ins)) {
                    throw new Exception('فشل حفظ المرتجع: ' . $conn->error);
                }
                $saved_return_id = $conn->insert_id;

                // 2. تخفيض الكمية في المخزن وتحديث القيمة الكلية
                if (!$conn->query("UPDATE products SET quantity = GREATEST(0, quantity - $base_qty), total = quantity * buy_price WHERE id = $product_id")) {
                    throw new Exception('فشل تحديث كمية المنتج في المخزن: ' . $conn->error);
                }

                // 3. إضافة سجل حركة المخزن
                if (!$conn->query("INSERT INTO inventory_log (product_id, product_name, type, qty_change, new_qty, reason, user)
                              VALUES ($product_id, '$p_name_esc', 'manual', -$base_qty, (SELECT quantity FROM products WHERE id = $product_id LIMIT 1), 'مرتجع شراء فاتورة رقم #{$purchase_id} - {$reason}', '$user_name')")) {
                    throw new Exception('فشل إضافة سجل حركة المخزون: ' . $conn->error);
                }

                // 4. استرداد المبلغ وتحديث فاتورة الشراء الأصلية
                if ($refund_amount > 0) {
                    if (!$conn->query("UPDATE purchases SET total = GREATEST(0, total - $refund_amount), remaining_total = GREATEST(0, remaining_total - $refund_amount) WHERE id = $purchase_id")) {
                        throw new Exception('فشل تحديث إجمالي الفاتورة الأصلية: ' . $conn->error);
                    }
                }

                if ($refund_method === 'cash') {
                    if ($refund_amount > 0) {
                        update_box_balance($conn, $active_box_id, $refund_amount, 'addition', "مرتجع شراء فاتورة #{$purchase_id} - {$p_name}", $return_date);
                    }
                    
                    $debit_acc = 'الصندوق - ' . $box_name;
                    if (!post_journal_entry($conn, 'return', $saved_return_id, $debit_acc, 'المخزون / البضاعة', $refund_amount, "مرتجع مشتريات نقداً #{$purchase_id} - {$p_name}", $user_name, $active_box_id)) {
                        throw new Exception('فشل تسجيل قيد مرتجع المشتريات نقداً');
                    }
                } else {
                    if ($refund_amount > 0 && !empty($supplier_name)) {
                        $supp_esc = $conn->real_escape_string($supplier_name);
                        if (!$conn->query("UPDATE suppliers SET supp_daain = GREATEST(0, supp_daain - $refund_amount) WHERE supp_name='$supp_esc'")) {
                            throw new Exception('فشل تحديث رصيد المورد: ' . $conn->error);
                        }
                    }
                    
                    if (!post_journal_entry($conn, 'return', $saved_return_id, 'الذمم الدائنة - ' . $supplier_name, 'المخزون / البضاعة', $refund_amount, "مرتجع مشتريات آجل (خصم مديونية) #{$purchase_id} - {$p_name}", $user_name, $active_box_id)) {
                        throw new Exception('فشل تسجيل قيد مرتجع المشتريات آجل');
                    }
                }
                $has_processed = true;
            }

            if (!$has_processed) {
                throw new Exception("الرجاء إدخال كمية مرتجعة أكبر من الصفر لصنف واحد على الأقل.");
            }

            $conn->commit();
            $success = '✓ تم تسجيل مردود المشتريات وتحديث المخازن والحسابات بنجاح.';
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
    $res_ret = $conn->query("SELECT * FROM purchase_returns WHERE id = $ret_id AND status = 'active' LIMIT 1");
    $ret_row = $res_ret ? $res_ret->fetch_assoc() : null;

    if ($ret_row) {
        $conn->begin_transaction();
        try {
            if (!$conn->query("UPDATE purchase_returns SET status = 'cancelled' WHERE id = $ret_id")) {
                throw new Exception("فشل إلغاء حركة المرتجع");
            }

            $p_id          = intval($ret_row['purchase_id']);
            $product_id    = intval($ret_row['product_id']);
            $qty           = intval($ret_row['quantity']);
            $refund        = doubleval($ret_row['refund_amount']);
            $refund_method = $ret_row['refund_method'];
            $box_id        = intval($ret_row['box_id']);
            $box_name      = get_box_name($conn, $box_id);
            $uname         = $conn->real_escape_string($_SESSION['SESS_FIRST_NAME']);
            $today_date    = date("Y-m-d H:i:s");

            // جلب المورد الأصلي
            $pur_check = $conn->query("SELECT supp_name FROM purchases WHERE id = $p_id LIMIT 1");
            $supplier_name = $pur_check ? $pur_check->fetch_assoc()['supp_name'] : '';

            // حساب كمية المرتجع بالوحدات الأساسية لعكسها بالمستودع
            $conv_factor = 1.0;
            $unit_name = $ret_row['unit_name'];
            if (!empty($unit_name)) {
                $unit_res = $conn->query("SELECT conversion_factor FROM product_units WHERE product_id = $product_id AND unit_name = '" . $conn->real_escape_string($unit_name) . "' LIMIT 1");
                if ($unit_res && $unit_res->num_rows > 0) {
                    $conv_factor = doubleval($unit_res->fetch_assoc()['conversion_factor']);
                }
            }
            $base_qty = $qty * $conv_factor;

            // إعادة إضافة الكمية للمخزن وتحديث القيمة الكلية
            if (!$conn->query("UPDATE products SET quantity = quantity + $base_qty, total = quantity * buy_price WHERE id=$product_id")) {
                throw new Exception("فشل إعادة الكمية للمخزن");
            }

            // سجل حركة المخزن
            if (!$conn->query("INSERT INTO inventory_log (product_id, product_name, type, qty_change, new_qty, reason, user)
                          SELECT id, name, 'manual', $base_qty, quantity, 'إلغاء مرتجع شراء #{$ret_id}', '$uname'
                          FROM products WHERE id = $product_id LIMIT 1")) {
                throw new Exception("فشل إضافة سجل حركة المخزون");
            }

            if ($refund_method === 'cash') {
                $refund_source = $ret_row['refund_source'];
                if ($refund_source === 'box') {
                    update_box_balance($conn, $box_id, $refund, 'discount', "إلغاء مرتجع شراء #{$ret_id}", $today_date);
                }
                
                $debit_acc = ($refund_source === 'box') ? 'الصندوق - ' . $box_name : 'المشتريات';
                if (!post_journal_entry($conn, 'return', $ret_id, 'المخزون / البضاعة', $debit_acc, $refund, "إلغاء مرتجع شراء نقدي #{$ret_id}", $uname, $box_id)) {
                    throw new Exception("فشل تسجيل قيد إلغاء مرتجع شراء نقدي");
                }
            } else {
                if (!empty($supplier_name)) {
                    $supp_esc = $conn->real_escape_string($supplier_name);
                    if (!$conn->query("UPDATE suppliers SET supp_daain = supp_daain + $refund WHERE supp_name='$supp_esc'")) {
                        throw new Exception("فشل إعادة مديونية المورد");
                    }
                }
                
                if (!post_journal_entry($conn, 'return', $ret_id, 'المخزون / البضاعة', 'الذمم الدائنة - ' . $supplier_name, $refund, "إلغاء مرتجع شراء آجل #{$ret_id}", $uname, $box_id)) {
                    throw new Exception("فشل تسجيل قيد إلغاء مرتجع شراء آجل");
                }
            }

            if (!$conn->query("UPDATE purchases SET total = total + $refund, remaining_total = remaining_total + $refund WHERE id = $p_id")) {
                throw new Exception("فشل تحديث الفاتورة الأصلية");
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
<title>فاتورة مردودات المشتريات - تكنولوجيا فون</title>

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

<div class="row no-print mb-4">
    <div class="col-md-7 text-right">
        <h3 class="text-secondary font-weight-bold mb-1">
            <i class="fa fa-undo ml-2 text-primary"></i> فاتورة مردودات المشتريات
        </h3>
        <p class="text-muted small mb-0">أدخل رقم فاتورة المشتريات أو اضغط F2 للبحث عنها، ثم حدد الكميات المرتجعة لحفظ المردود للمورد.</p>
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

<?php if (!empty($success)): ?>
    <div class="alert alert-success rounded-0 mb-4 text-right no-print"><?php echo htmlspecialchars($success); ?></div>
<?php endif; ?>
<?php if (!empty($error)): ?>
    <div class="alert alert-danger rounded-0 mb-4 text-right no-print"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<form method="POST" id="returnForm" class="no-print">
    <input type="hidden" name="purchase_id" id="ret_purchase_id" value="0">
    
    <!-- بطاقة الهيدر (تفاصيل الفاتورة مثل شاشة المشتريات تماماً) -->
    <div class="card p-3 mb-4 text-right" dir="rtl">
        <div class="row">
            <!-- حقل رقم الفاتورة للبحث -->
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

            <!-- تاريخ المردود -->
            <div class="col-md-2 mb-3">
                <label class="form-label font-weight-bold text-secondary">تاريخ المردود</label>
                <input type="date" name="return_date" class="form-control rounded-0 font-weight-bold text-center" value="<?php echo date('Y-m-d'); ?>" required>
            </div>

            <!-- المورد (تلقائي) -->
            <div class="col-md-3 mb-3">
                <label class="form-label font-weight-bold text-secondary">المورد</label>
                <input type="text" id="invSuppDisplay" class="form-control rounded-0 font-weight-bold text-center bg-light" readonly value="-">
            </div>

            <!-- تاريخ الفاتورة الأصلي (تلقائي) -->
            <div class="col-md-2 mb-3">
                <label class="form-label font-weight-bold text-secondary">تاريخ الفاتورة</label>
                <input type="text" id="invDateDisplay" class="form-control rounded-0 font-weight-bold text-center bg-light" readonly value="-">
            </div>

            <!-- الإجمالي الأصلي (تلقائي) -->
            <div class="col-md-2 mb-3">
                <label class="form-label font-weight-bold text-secondary">الإجمالي الأصلي</label>
                <input type="text" id="invTotalDisplay" class="form-control rounded-0 font-weight-bold text-center bg-light text-primary" readonly value="-">
            </div>
        </div>

        <!-- خيارات المردود (تظهر بعد تحميل الفاتورة) -->
        <div class="row" id="refundOptionsRow" style="display:none; border-top: 1px solid #eee; padding-top: 15px;">
            <!-- طريقة رد القيمة من المورد -->
            <div class="col-md-4 mb-3">
                <label class="form-label font-weight-bold text-secondary">طريقة رد القيمة من المورد *</label>
                <select name="refund_method" id="refund_method" class="form-control rounded-0 font-weight-bold" required>
                    <option value="cash" selected>استلام نقدي (تسليم فوري للصندوق)</option>
                    <option value="credit">آجل (خصم من مديونية المورد)</option>
                </select>
            </div>

            <!-- سبب الاسترجاع للمورد -->
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

            <!-- الصندوق المستلم إليه -->
            <div class="col-md-4 mb-3">
                <label class="form-label font-weight-bold text-secondary">الصندوق المستلم إليه</label>
                <input type="text" class="form-control rounded-0 font-weight-bold text-center bg-light" readonly value="<?php echo htmlspecialchars($box_name); ?>">
            </div>
        </div>
    </div>

    <!-- جدول الأصناف مرئي وكامل العرض (يظهر بعد اختيار الفاتورة) -->
    <div id="invoiceItemsSection" style="display:none;">
        <div class="card-flat ret-card mb-4">
            <div class="card-header bg-light">
                <h5 class="mb-0 text-dark font-weight-bold"><i class="fa fa-list ml-2 text-primary"></i> جدول الأصناف الفعلي بالفاتورة والكميات المسترجعة للمورد</h5>
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

        <!-- الملخص المالي والاعتماد -->
        <div class="row justify-content-end mb-4">
            <div class="col-md-5">
                <div class="card bg-light border-0 p-4 rounded-0 text-center">
                    <h5 class="text-secondary font-weight-bold mb-3">الملخص المالي للمردودات</h5>
                    <div style="font-size: 2rem;" class="font-weight-bold text-danger mb-3" id="grand_return_total_display">
                        0.00 <span style="font-size: 1.1rem;">ر.ي</span>
                    </div>
                    <button type="submit" name="btn_save_return" class="btn-flat btn-flat-danger btn-block py-3 font-weight-bold" style="font-size: 1.15rem;">
                        <i class="fa fa-check ml-2"></i> تأكيد وإعتماد الفاتورة للمورد
                    </button>
                </div>
            </div>
        </div>
    </div>
</form>

<!-- سجل المردودات السابقة للفاتورة -->
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
                <tbody id="prevReturnsBody">
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- سجل مردودات المشتريات الكامل بالنظام -->
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
                        <?php foreach ($all_returns as $r): ?>
                        <tr class="<?php echo $r['status']==='cancelled' ? 'text-muted' : ''; ?>" style="<?php echo $r['status']==='cancelled' ? 'opacity:0.6; text-decoration:line-through;' : ''; ?>">
                            <td class="font-weight-bold text-secondary">#<?php echo $r['id']; ?></td>
                            <td>
                                <a href="view.php?id=<?php echo $r['purchase_id']; ?>" class="text-primary font-weight-bold text-decoration-none">
                                    #<?php echo $r['purchase_id']; ?>
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
            const disabledAttr  = canReturn <= 0 ? 'disabled' : '';

            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td class="font-weight-bold small text-right">
                    ${escHtml(item.name)}
                    <input type="hidden" name="product_ids[]" value="${item.product_id}">
                    <input type="hidden" name="product_names[]" value="${escHtml(item.name)}">
                    <input type="hidden" name="unit_prices[]" value="${item.unit_price}">
                    <input type="hidden" name="unit_names[]" value="${escHtml(item.unit_name || 'الوحدة الأساسية')}">
                </td>
                <td class="text-center small">${escHtml(item.unit_name || 'الوحدة الأساسية')}</td>
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
