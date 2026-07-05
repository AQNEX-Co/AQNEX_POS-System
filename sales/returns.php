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
// معالجة تسجيل مرتجع
// ==========================================
if (isset($_POST['btn_save_return'])) {
    $sales_id      = intval($_POST['sales_id']);
    $product_id    = intval($_POST['product_id']);
    $p_name        = $conn->real_escape_string(trim($_POST['product_name']));
    $qty           = intval($_POST['qty_return']);
    $unit_price    = doubleval($_POST['unit_price_yer']);
    $buy_price     = doubleval($_POST['buy_price_yer']);
    $refund_amount = $qty * $unit_price;
    $reason        = $conn->real_escape_string(trim($_POST['reason']));
    $return_date   = $conn->real_escape_string($_POST['return_date']);
    $user_name     = $conn->real_escape_string($_SESSION['SESS_FIRST_NAME']);
    $profit_impact = ($unit_price - $buy_price) * $qty;
    $refund_method = $_POST['refund_method'] === 'credit' ? 'credit' : 'cash';
    
    // تحديد مصدر الخصم: box (من الصندوق مباشرة) أو sales (من مبيعات اليوم)
    $refund_source = isset($_POST['refund_source']) ? $_POST['refund_source'] : 'box';
    if (!in_array($refund_source, ['box', 'sales'])) {
        $refund_source = 'box';
    }

    $active_box_id = get_user_box_id($conn, $_SESSION['SESS_MEMBER_ID']);
    $box_name = get_box_name($conn, $active_box_id);

    if ($qty <= 0 || $product_id <= 0 || empty($p_name)) {
        $error = 'الرجاء تحديد المنتج والكمية المرتجعة بشكل صحيح.';
    } elseif ($sales_id <= 0) {
        $error = 'الرجاء تحديد فاتورة المبيعات الأصلية أولاً.';
    } else {
        $conn->begin_transaction();
        try {
            // التحقق من الكمية المتاحة للإرجاع
            $res_chk = $conn->query("SELECT COALESCE(SUM(quantity),0) AS ret FROM sales_returns WHERE sales_id=$sales_id AND product_id=$product_id AND status='active'");
            $already_ret = $res_chk ? intval($res_chk->fetch_assoc()['ret']) : 0;

            $res_orig = $conn->query("SELECT quantity FROM sales_items WHERE sales_id=$sales_id AND id=$product_id LIMIT 1");
            $orig_qty = $res_orig ? intval($res_orig->fetch_assoc()['quantity']) : 0;
            $can_return = $orig_qty - $already_ret;

            if ($qty > $can_return) {
                throw new Exception("لا يمكن إرجاع {$qty} وحدة. الكمية المتاحة للإرجاع: {$can_return} وحدة فقط.");
            }

            // التحقق من رصيد الصندوق إذا كان الخصم منه مباشرة
            $box_balance_before = 0;
            if ($refund_method === 'cash' && $refund_source === 'box' && $refund_amount > 0) {
                $box_balance_before = get_box_balance($conn, $active_box_id);
                if ($box_balance_before < $refund_amount) {
                    throw new Exception("رصيد الصندوق الحالي (" . number_format($box_balance_before, 2) . " ر.ي) أقل من مبلغ الاسترداد (" . number_format($refund_amount, 2) . " ر.ي). يرجى اختيار مصدر آخر (مبيعات اليوم) أو إضافة رصيد للصندوق.");
                }
            }

            // 1. إدراج سجل المرتجع
            $sql_ins = "INSERT INTO sales_returns
                (sales_id, product_id, product_name, quantity, unit_price, refund_amount,
                 original_unit_price, original_buy_price, profit_impact, reason, return_date, user, status, box_id, refund_method, refund_source)
                VALUES
                ($sales_id, $product_id, '$p_name', $qty, $unit_price, $refund_amount,
                 $unit_price, $buy_price, -$profit_impact, '$reason', '$return_date', '$user_name', 'active', $active_box_id, '$refund_method', '$refund_source')";

            if (!$conn->query($sql_ins)) {
                throw new Exception('فشل تسجيل المرتجع: ' . $conn->error);
            }
            $saved_return_id = $conn->insert_id;

            // تحديد عامل تحويل الوحدة
            $conv_factor = 1.0;
            if (preg_match('/\(([^)]+)\)/', $p_name, $matches)) {
                $unit_name = trim($matches[1]);
                $unit_res = $conn->query("SELECT conversion_factor FROM product_units WHERE product_id = $product_id AND unit_name = '" . $conn->real_escape_string($unit_name) . "' LIMIT 1");
                if ($unit_res && $unit_res->num_rows > 0) {
                    $conv_factor = doubleval($unit_res->fetch_assoc()['conversion_factor']);
                    if ($conv_factor <= 0) $conv_factor = 1.0;
                }
            }
            $base_qty = $qty * $conv_factor;

            // 2. إعادة الكمية للمخزن
            if (!$conn->query("UPDATE products SET quantity = quantity + $base_qty, total = quantity * buy_price WHERE id = $product_id")) {
                throw new Exception("فشل تحديث المخزون: " . $conn->error);
            }

            // 3. سجل حركة المخزن
            if (!$conn->query("INSERT INTO inventory_log (product_id, product_name, type, qty_change, new_qty, reason, user)
                          SELECT id, name, 'sale_return', $base_qty, quantity, 'مرتجع بيع فاتورة #{$sales_id} - {$reason}', '$user_name'
                          FROM products WHERE id = $product_id LIMIT 1")) {
                throw new Exception("فشل تسجيل حركة المخزن: " . $conn->error);
            }

            // 4. خصم المبلغ من الخزينة أو العميل
            $res_sale = $conn->query("SELECT cust_name FROM sales WHERE id=$sales_id LIMIT 1");
            $sale_row = $res_sale ? $res_sale->fetch_assoc() : null;
            $customer_name = $sale_row ? $sale_row['cust_name'] : 'عميل نقدي';

            if ($refund_method === 'cash') {
                // خصم مباشر من الصندوق أو من مبيعات اليوم
                if ($refund_source === 'box' && $refund_amount > 0) {
                    // الخصم المباشر من الصندوق
                    $update_result = update_box_balance($conn, $active_box_id, $refund_amount, 'discount', "مرتجع بيع فاتورة #{$sales_id} - {$p_name}", $return_date);
                    
                    if (!$update_result) {
                        throw new Exception("فشل خصم المبلغ من الصندوق. يرجى المحاولة مرة أخرى.");
                    }
                    
                    $box_balance_after = get_box_balance($conn, $active_box_id);
                    
                    $box_deduction_details = [
                        'box_name' => $box_name,
                        'box_id' => $active_box_id,
                        'amount_deducted' => $refund_amount,
                        'balance_before' => $box_balance_before,
                        'balance_after' => $box_balance_after,
                        'description' => "مرتجع بيع فاتورة #{$sales_id} - {$p_name}"
                    ];
                    
                    $journal_entries[] = [
                        'type' => 'box_deduction',
                        'description' => 'خصم مباشر من الصندوق',
                        'box_name' => $box_name,
                        'amount' => $refund_amount,
                        'balance_before' => $box_balance_before,
                        'balance_after' => $box_balance_after
                    ];
                }
                // تسجيل القيد المحاسبي: مردودات المبيعات (مدين) / الصندوق (دائن)
                $credit_acc = 'الصندوق - ' . $box_name;
                $debit_acc = 'مردودات المبيعات';
                
                if (!post_journal_entry($conn, 'return', $saved_return_id, $debit_acc, $credit_acc, $refund_amount, 
                    "مرتجع مبيعات نقداً فاتورة #{$sales_id} - {$p_name} | الكمية: {$qty} | السعر: " . number_format($unit_price, 2) . " | المصدر: " . ($refund_source === 'box' ? 'الصندوق (خصم مباشر)' : 'مبيعات اليوم'), 
                    $user_name, $active_box_id, 'YER', 1.0, null)) {
                    throw new Exception("فشل تسجيل قيد المرتجع النقدي");
                }
                
                $journal_entries[] = [
                    'type' => 'journal',
                    'description' => 'قيد مرتجع نقدي',
                    'debit' => $debit_acc,
                    'credit' => $credit_acc,
                    'amount' => $refund_amount,
                    'details' => "فاتورة #{$sales_id} | منتج: {$p_name} | كمية: {$qty}"
                ];
            } else {
                // خصم من مديونية العميل
                if ($refund_amount > 0 && !empty($customer_name) && $customer_name !== 'عميل نقدي') {
                    $cust_esc = $conn->real_escape_string($customer_name);
                    $conn->query("UPDATE customers SET cust_madeen = GREATEST(0, cust_madeen - $refund_amount) WHERE cust_name='$cust_esc'");
                    
                    $journal_entries[] = [
                        'type' => 'customer_deduction',
                        'description' => 'خصم من مديونية العميل',
                        'customer' => $customer_name,
                        'amount' => $refund_amount
                    ];
                }
                
                // تحديث إجمالي المتبقي في الفاتورة الأصلية
                if (!$conn->query("UPDATE sales SET remaining_total = GREATEST(0, remaining_total - $refund_amount) WHERE id = $sales_id")) {
                    throw new Exception("فشل تعديل الفاتورة الأصلية: " . $conn->error);
                }
                
                // تسجيل القيد المحاسبي
                $debit_acc = 'مردودات المبيعات';
                $credit_acc = 'الذمم المدينة - ' . $customer_name;
                
                if (!post_journal_entry($conn, 'return', $saved_return_id, $debit_acc, $credit_acc, $refund_amount, 
                    "مرتجع مبيعات آجل (خصم دين) فاتورة #{$sales_id} - {$p_name} | الكمية: {$qty} | السعر: " . number_format($unit_price, 2), 
                    $user_name, $active_box_id, 'YER', 1.0, null)) {
                    throw new Exception("فشل تسجيل قيد المرتجع الآجل");
                }
                
                $journal_entries[] = [
                    'type' => 'journal',
                    'description' => 'قيد مرتجع آجل',
                    'debit' => $debit_acc,
                    'credit' => $credit_acc,
                    'amount' => $refund_amount,
                    'details' => "فاتورة #{$sales_id} | عميل: {$customer_name}"
                ];
            }

            // 5. قيد تكلفة البضاعة المرجعة
            $cost_amount = $buy_price * $qty;
            if ($cost_amount > 0) {
                $debit_acc = 'المخزون / البضاعة';
                $credit_acc = 'تكلفة البضاعة المباعة (مصروف)';
                
                if (!post_journal_entry($conn, 'return', $saved_return_id, $debit_acc, $credit_acc, $cost_amount, 
                    "إعادة تكلفة مرتجع مبيعات #{$saved_return_id} للمخزون | المنتج: {$p_name} | الكمية: {$qty} | تكلفة الوحدة: " . number_format($buy_price, 2), 
                    $user_name, $active_box_id, 'YER', 1.0, null)) {
                    throw new Exception("فشل تسجيل قيد تكلفة البضاعة المرجعة");
                }
                
                $journal_entries[] = [
                    'type' => 'journal',
                    'description' => 'قيد تكلفة البضاعة المرجعة',
                    'debit' => $debit_acc,
                    'credit' => $credit_acc,
                    'amount' => $cost_amount,
                    'details' => "إعادة {$qty} وحدة للمخزن"
                ];
            }
            $conn->commit();
            echo "<script>window.location='view.php?id=$sales_id&send_wa=2';</script>";
            exit;
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

            $conv_factor = 1.0;
            if (preg_match('/\(([^)]+)\)/', $ret_row['product_name'], $matches)) {
                $unit_name = trim($matches[1]);
                $unit_res = $conn->query("SELECT conversion_factor FROM product_units WHERE product_id = $product_id AND unit_name = '" . $conn->real_escape_string($unit_name) . "' LIMIT 1");
                if ($unit_res && $unit_res->num_rows > 0) {
                    $conv_factor = doubleval($unit_res->fetch_assoc()['conversion_factor']);
                    if ($conv_factor <= 0) $conv_factor = 1.0;
                }
            }
            $base_qty = $qty * $conv_factor;

            if (!$conn->query("UPDATE products SET quantity = quantity - $base_qty, total = quantity * buy_price WHERE id=$product_id AND quantity >= $base_qty")) {
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

            $res_ret_detail = $conn->query("SELECT original_buy_price FROM sales_returns WHERE id=$ret_id LIMIT 1");
            $detail_row = ($res_ret_detail && $res_ret_detail->num_rows > 0) ? $res_ret_detail->fetch_assoc() : null;
            $buy_price_val = $detail_row ? doubleval($detail_row['original_buy_price']) : 0;
            $cost_amount = $buy_price_val * $qty;
            
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
            $success = 'تم إلغاء المرتجع #' . $ret_id . ' بنجاح وعكس جميع القيود المحاسبية.';
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
?>
<title>مردودات المبيعات - <?php echo htmlspecialchars($settings['store_name'] ?? 'النظام'); ?></title>

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

.journal-entries-section {
    background: #f8f9fa;
    border: 2px solid #28a745;
    border-radius: 8px;
    padding: 20px;
    margin-top: 20px;
}
.journal-entries-section h5 {
    color: #28a745;
    font-weight: 700;
    margin-bottom: 15px;
    border-bottom: 2px solid #28a745;
    padding-bottom: 10px;
}
.journal-table {
    width: 100%;
    background: #fff;
    border-collapse: collapse;
}
.journal-table th {
    background: #28a745;
    color: #fff;
    padding: 10px;
    text-align: center;
    font-weight: 700;
}
.journal-table td {
    padding: 10px;
    border: 1px solid #dee2e6;
    text-align: center;
}
.journal-table .debit {
    color: #dc3545;
    font-weight: 700;
}
.journal-table .credit {
    color: #28a745;
    font-weight: 700;
}

.box-deduction-box {
    background: #fff3cd;
    border: 2px solid #ffc107;
    border-radius: 8px;
    padding: 15px;
    margin-top: 15px;
}
.box-deduction-box h6 {
    color: #856404;
    font-weight: 700;
    margin-bottom: 10px;
}
.box-balance-change {
    font-size: 1.1rem;
    font-weight: 700;
    padding: 8px;
    border-radius: 4px;
}
.box-balance-change.before {
    background: #e2e3e5;
    color: #383d41;
}
.box-balance-change.after {
    background: #d4edda;
    color: #155724;
}
</style>

<?php if (!empty($success) && $saved_return_id > 0):
    $res_ret_view = $conn->query("SELECT sr.*, s.cust_name, s.build_date as sale_date FROM sales_returns sr LEFT JOIN sales s ON sr.sales_id=s.id WHERE sr.id=$saved_return_id LIMIT 1");
    $ret_view = $res_ret_view ? $res_ret_view->fetch_assoc() : null;
?>
<div class="alert alert-success rounded-0 mb-3 no-print">
    <strong><?php echo $success; ?></strong>
    <button onclick="window.print()" class="btn-flat btn-flat-primary btn-sm mr-3">
        <?php echo get_icon('print', 'ml-1'); ?>  
    </button>
    <a href="returns.php" class="btn-flat btn-flat-secondary btn-sm text-decoration-none mr-2">إدارة المردودات</a>
    <a href="index.php" class="btn-flat btn-flat-secondary btn-sm text-decoration-none">قائمة المبيعات</a>
</div>

<?php if (!empty($box_deduction_details)): ?>
<div class="box-deduction-box no-print">
    <h6><i class="fa fa-money-bill-wave ml-2"></i> تفاصيل الخصم المباشر من الصندوق</h6>
    <table class="table table-sm table-bordered bg-white">
        <tr>
            <th style="width: 30%;">اسم الصندوق:</th>
            <td><strong><?php echo htmlspecialchars($box_deduction_details['box_name']); ?></strong></td>
        </tr>
        <tr>
            <th>الرصيد قبل الخصم:</th>
            <td class="box-balance-change before"><?php echo number_format($box_deduction_details['balance_before'], 2); ?> ر.ي</td>
        </tr>
        <tr>
            <th>المبلغ المخصوم:</th>
            <td class="text-danger font-weight-bold" style="font-size: 1.2rem;">- <?php echo number_format($box_deduction_details['amount_deducted'], 2); ?> ر.ي</td>
        </tr>
        <tr>
            <th>الرصيد بعد الخصم:</th>
            <td class="box-balance-change after"><?php echo number_format($box_deduction_details['balance_after'], 2); ?> ر.ي</td>
        </tr>
        <tr>
            <th>الوصف:</th>
            <td><?php echo htmlspecialchars($box_deduction_details['description']); ?></td>
        </tr>
    </table>
</div>
<?php endif; ?>

<?php if (!empty($journal_entries)): ?>
<div class="journal-entries-section no-print">
    <h5><i class="fa fa-book ml-2"></i> القيود المحاسبية المُنشأة</h5>
    
    <table class="journal-table">
        <thead>
            <tr>
                <th style="width: 5%;">#</th>
                <th style="width: 30%;">نوع القيد</th>
                <th style="width: 25%;">الحساب المدين</th>
                <th style="width: 25%;">الحساب الدائن</th>
                <th style="width: 15%;">المبلغ (ر.ي)</th>
            </tr>
        </thead>
        <tbody>
            <?php $entry_num = 1; foreach ($journal_entries as $entry): ?>
                <?php if ($entry['type'] === 'journal'): ?>
                <tr>
                    <td><?php echo $entry_num++; ?></td>
                    <td class="text-right font-weight-bold"><?php echo htmlspecialchars($entry['description']); ?></td>
                    <td class="text-right debit"><?php echo htmlspecialchars($entry['debit']); ?></td>
                    <td class="text-right credit"><?php echo htmlspecialchars($entry['credit']); ?></td>
                    <td class="font-weight-bold"><?php echo number_format($entry['amount'], 2); ?></td>
                </tr>
                <?php endif; ?>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

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
        <tr><td>طريقة الاسترداد:</td><td style="text-align:left;"><?php echo $ret_view['refund_method'] === 'cash' ? ($ret_view['refund_source'] === 'box' ? 'نقداً من الصندوق (خصم مباشر)' : 'خصم من مبيعات اليوم') : 'خصم من المديونية'; ?></td></tr>
        <tr><td>السبب:</td><td style="text-align:left; font-size:0.8rem;"><?php echo htmlspecialchars($ret_view['reason']); ?></td></tr>
        <tr><td>أمين الصندوق:</td><td style="text-align:left;"><?php echo htmlspecialchars($ret_view['user']); ?></td></tr>
    </table>
    <?php endif; ?>

    <div style="text-align:center; border-top: 2px dashed #333; padding-top:8px; font-size:0.8rem;">
        تم الاسترداد — <?php echo htmlspecialchars($settings['receipt_footer'] ?? ''); ?>
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
<div class="alert alert-danger rounded-0 mb-4"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<div class="row mb-3 no-print">
    <div class="col-md-7">
        <h3 class="text-secondary font-weight-bold mb-1">
            <i class="fa fa-undo ml-2 text-primary"></i> إدارة مردودات المبيعات
        </h3>
        <p class="text-muted small mb-0">ابحث عن فاتورة البيع، حدد المنتج المرتجع، وسيتم خصم المبلغ مباشرة من الصندوق.</p>
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
    <div class="col-lg-6 mb-4">
        <div class="card-flat ret-card">
            <div class="card-header" style="background: var(--secondary); color: #fff;">
                <h5 class="mb-0"><i class="fa fa-undo ml-2"></i> تسجيل مرتجع جديد</h5>
            </div>
            <div class="card-body">

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
                    <small class="text-muted">اضغط Enter أو زر بحث لتحميل بنود الفاتورة , اضغط زر F2 لاظهار فواتير المبيعات</small>
                </div>

                <div id="invoiceInfo" style="display:none;" class="alert alert-info rounded-0 p-2 mb-3">
                    <div class="row">
                        <div class="col-6"><strong>فاتورة #:</strong> <span id="invNum">-</span></div>
                        <div class="col-6"><strong>العميل:</strong> <span id="invCust">-</span></div>
                        <div class="col-6"><strong>التاريخ:</strong> <span id="invDate">-</span></div>
                        <div class="col-6"><strong>الإجمالي:</strong> <span id="invTotal">-</span></div>
                    </div>
                </div>

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

                <form method="POST" id="returnForm">
                    <input type="hidden" name="sales_id"       id="ret_sales_id" value="0">
                    <input type="hidden" name="product_id"     id="ret_product_id" value="0">
                    <input type="hidden" name="product_name"   id="ret_product_name" value="">
                    <input type="hidden" name="unit_price_yer" id="ret_unit_price" value="0">
                    <input type="hidden" name="buy_price_yer"  id="ret_buy_price" value="0">

                    <div id="returnFormFields" style="display:none;">
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
                            <select name="refund_method" id="refund_method" class="form-control rounded-0" required onchange="toggleRefundSource()">
                                <option value="cash" selected>إرجاع نقدي (خصم مباشر)</option>
                                <option value="credit" id="refund_credit_opt">خصم من مديونية الحساب (للعملاء الآجل)</option>
                            </select>
                        </div>

                        <div class="form-group mb-3" id="refundSourceSection">
                            <label class="font-weight-bold text-secondary mb-1">مصدر الخصم *</label>
                            <select name="refund_source" id="refund_source" class="form-control rounded-0" required>
                                <option value="box" selected>من الصندوق مباشرة (خصم فوري)</option>
                                <option value="sales">من مبيعات اليوم (لا يؤثر على رصيد الصندوق)</option>
                            </select>
                            <small class="text-info">
                                <i class="fa fa-info-circle ml-1"></i>
                                <strong>من الصندوق مباشرة:</strong> يتم خصم المبلغ فوراً من رصيد الصندوق الحالي<br>
                                <strong>من مبيعات اليوم:</strong> يتم خصم المبلغ من إجمالي مبيعات اليوم ولا يؤثر على الصندوق
                            </small>
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

                        <div class="alert alert-warning rounded-0 p-2 mb-3">
                            <strong><i class="fa fa-info-circle ml-1"></i> ملاحظات هامة:</strong>
                            <ul class="mb-0 mt-2 small">
                                <li>سيتم خصم المبلغ من الصندوق مباشرة عند اختيار "من الصندوق مباشرة"</li>
                                <li>سيتم إنشاء قيود محاسبية دقيقة ومتوازنة تلقائياً</li>
                                <li>سيتم تحديث المخزون ومديونية العميل تلقائياً</li>
                                <li>لا يمكن التراجع عن العملية بعد التأكيد إلا من خلال إلغاء المرتجع</li>
                            </ul>
                        </div>

                        <button type="submit" name="btn_save_return" class="btn-flat btn-flat-primary btn-block py-2">
                            <i class="fa fa-check ml-1"></i> تأكيد المرتجع وخصم المبلغ من الصندوق
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-6 mb-4">
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
                                   onclick="return confirm('هل تريد إلغاء هذا المرتجع؟ سيتم عكس جميع العمليات والقيود المحاسبية.')"
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

<script>
const recentInvoices = <?php echo json_encode($recent_invoices, JSON_UNESCAPED_UNICODE); ?>;

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

    resetReturnForm();

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

    document.getElementById('invNum').textContent   = '#' + inv.id;
    document.getElementById('invCust').textContent  = inv.cust_name || 'عميل نقدي';
    document.getElementById('invDate').textContent  = inv.build_date;
    document.getElementById('invTotal').textContent = formatNum(inv.total) + ' ر.ي';
    document.getElementById('ret_sales_id').value   = inv.id;
    document.getElementById('invoiceInfo').style.display = 'block';

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
            <div class="modal-body">
                <div class="form-group mb-3 text-right">
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
                    <td class="font-weight-bold">${formatNum(inv.total)}</td>
                    <td class="text-center">
                        <button type="button" class="btn-flat btn-flat-primary btn-sm py-1 px-2" onclick="selectModalInvoice(${inv.id})">
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