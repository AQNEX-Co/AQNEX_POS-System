<?php
$dir_prefix = '../';
$module = 'box';
require_once($dir_prefix . 'includes/header.php');

check_permission(['admin', 'cashier']);

$active_user_id = intval($_SESSION['SESS_MEMBER_ID']);
$active_user_role = trim($_SESSION['SESS_LAST_NAME']);
$is_admin = ($active_user_role === 'admin' || empty($active_user_role));

$success = '';
$error = '';
$today_date = date("Y-m-d");

// 1. تحديد الصندوق المطلوب إقفاله
$box_id = isset($_GET['box_id']) ? intval($_GET['box_id']) : 0;
if ($box_id <= 0) {
    // الكاشير يغلق صندوقه، المدير يغلق الصندوق الرئيسي افتراضياً
    $box_id = get_user_box_id($conn, $active_user_id);
}

// الكاشير لا يمكنه إقفال صندوق غير صندوقه
if (!$is_admin && $box_id !== get_user_box_id($conn, $active_user_id)) {
    echo "<script>alert('غير مصرح لك بإجراء إقفال لصناديق موظفين آخرين.'); window.location='index.php';</script>";
    exit;
}

// جلب تفاصيل الصندوق الحالي
$res_box = $conn->query("SELECT * FROM treasury WHERE box_id = $box_id LIMIT 1");
$box = $res_box ? $res_box->fetch_assoc() : null;
if (!$box) {
    echo "<script>alert('الصندوق المحدد غير موجود.'); window.location='index.php';</script>";
    exit;
}

$box_name = $box['name'];
$expected_balance = doubleval($box['mony']);

// 2. احتساب تفاصيل الحركات اليوم لهذا الصندوق لتوضيح التدفق المالي
// مبيعات نقدية اليوم
$sql_sales = "SELECT SUM(total) as cash_sales FROM sales WHERE build_date = '$today_date' AND box_id = $box_id AND delete_status = 0";
$row_sales = $conn->query($sql_sales)->fetch_assoc();
$cash_sales = isset($row_sales['cash_sales']) ? floatval($row_sales['cash_sales']) : 0.0;

// مقبوضات (سندات القبض) اليوم
$sql_receipts = "SELECT SUM(q_price) as total_receipts FROM receipts WHERE q_date = '$today_date' AND box_id = $box_id AND s = 0";
$row_receipts = $conn->query($sql_receipts)->fetch_assoc();
$total_receipts = isset($row_receipts['total_receipts']) ? floatval($row_receipts['total_receipts']) : 0.0;

// مردودات مبيعات نقدية اليوم
$sql_returns = "SELECT SUM(refund_amount) as cash_returns FROM sales_returns WHERE return_date = '$today_date' AND box_id = $box_id AND refund_method = 'cash' AND status = 'active'";
$row_returns = $conn->query($sql_returns)->fetch_assoc();
$cash_returns = isset($row_returns['cash_returns']) ? floatval($row_returns['cash_returns']) : 0.0;

// مصروفات (سندات صرف) اليوم
$sql_expenses = "SELECT SUM(sprice) as total_expenses FROM treasury_expenses WHERE sdate = '$today_date' AND box_id = $box_id AND s = 0";
$row_expenses = $conn->query($sql_expenses)->fetch_assoc();
$total_expenses = isset($row_expenses['total_expenses']) ? floatval($row_expenses['total_expenses']) : 0.0;

// مشتريات نقدية اليوم
$sql_purchases = "SELECT SUM(total) as cash_purchases FROM purchases WHERE date = '$today_date' AND box_id = $box_id";
$row_purchases = $conn->query($sql_purchases)->fetch_assoc();
$cash_purchases = isset($row_purchases['cash_purchases']) ? floatval($row_purchases['cash_purchases']) : 0.0;

// حركات تسوية يدوية أخرى اليوم (إيداع يدوي وسحب يدوي)
$sql_man_add = "SELECT SUM(mony) as man_add FROM treasury_transactions WHERE datte = '$today_date' AND box_id = $box_id AND statue = 'addition' AND remark NOT LIKE 'مبيعات%' AND remark NOT LIKE 'قبض%' AND remark NOT LIKE 'تحويل%' AND remark NOT LIKE 'رصيد%'";
$row_man_add = $conn->query($sql_man_add)->fetch_assoc();
$man_add = isset($row_man_add['man_add']) ? floatval($row_man_add['man_add']) : 0.0;

$sql_man_sub = "SELECT SUM(mony) as man_sub FROM treasury_transactions WHERE datte = '$today_date' AND box_id = $box_id AND statue = 'discount' AND remark NOT LIKE 'مرتجع%' AND remark NOT LIKE 'مدفوعات%' AND remark NOT LIKE 'سداد%' AND remark NOT LIKE 'تحويل%'";
$row_man_sub = $conn->query($sql_man_sub)->fetch_assoc();
$man_sub = isset($row_man_sub['man_sub']) ? floatval($row_man_sub['man_sub']) : 0.0;

// تحويلات مالية اليوم (صادرة وواردة)
$sql_trans_in = "SELECT SUM(mony) as trans_in FROM treasury_transactions WHERE datte = '$today_date' AND box_id = $box_id AND statue = 'addition' AND remark LIKE 'تحويل وارد%'";
$row_trans_in = $conn->query($sql_trans_in)->fetch_assoc();
$trans_in = isset($row_trans_in['trans_in']) ? floatval($row_trans_in['trans_in']) : 0.0;

$sql_trans_out = "SELECT SUM(mony) as trans_out FROM treasury_transactions WHERE datte = '$today_date' AND box_id = $box_id AND statue = 'discount' AND remark LIKE 'تحويل صادر%'";
$row_trans_out = $conn->query($sql_trans_out)->fetch_assoc();
$trans_out = isset($row_trans_out['trans_out']) ? floatval($row_trans_out['trans_out']) : 0.0;

// حساب الرصيد الافتتاحي المقدر
$total_today_additions = $cash_sales + $total_receipts + $man_add + $trans_in;
$total_today_subtractions = $cash_returns + $total_expenses + $cash_purchases + $man_sub + $trans_out;
$calculated_opening = $expected_balance - $total_today_additions + $total_today_subtractions;

// ==========================================
// 3. معالجة الإقفال عند إرسال النموذج
// ==========================================
if (isset($_POST['btn_confirm_close'])) {
    $actual_cash = doubleval($_POST['actual_cash']);
    $transferred_amount = doubleval($_POST['transferred_amount']);
    $notes = $conn->real_escape_string(trim($_POST['notes']));
    $user_display = $_SESSION['SESS_FIRST_NAME'];

    // 1. حساب الفرق
    $difference = $actual_cash - $expected_balance;

    // البدء في المعاملة
    $conn->begin_transaction();
    try {
        // 2. تسجيل الفروقات وتسوية الصندوق إن وجدت
        if ($difference != 0) {
            if ($difference > 0) {
                // زيادة نقدية
                update_box_balance($conn, $box_id, $difference, 'addition', 'تسوية فائض الصندوق عند الإقفال', $today_date);
                post_journal_entry($conn, 'adjustment', $box_id, 'الصندوق - ' . $box_name, 'زيادات وفروقات الصناديق (إيراد)', $difference, 'تسوية فائض الصندوق عند الإقفال اليومي', $user_display, $box_id);
            } else {
                // عجز نقدي
                $deficit = abs($difference);
                update_box_balance($conn, $box_id, $deficit, 'discount', 'تسوية عجز الصندوق عند الإقفال', $today_date);
                post_journal_entry($conn, 'adjustment', $box_id, 'عجز وفروقات الصناديق (مصروف)', 'الصندوق - ' . $box_name, $deficit, 'تسوية عجز الصندوق عند الإقفال اليومي', $user_display, $box_id);
            }
        }

        // 3. ترحيل النقدية إلى الصندوق الرئيسي
        if ($transferred_amount > 0) {
            // التحقق من أن المبلغ لا يتجاوز النقدية الفعلية المتاحة بالصندوق بعد التسوية
            if ($transferred_amount > $actual_cash) {
                throw new Exception('لا يمكن ترحيل مبلغ أكبر من الرصيد الفعلي الموجود بالصندوق.');
            }
            
            // خصم من الصندوق الحالي
            update_box_balance($conn, $box_id, $transferred_amount, 'discount', "ترحيل صادر إلى الصندوق الرئيسي - إقفال $today_date", $today_date);
            // إضافة للصندوق الرئيسي
            update_box_balance($conn, 1, $transferred_amount, 'addition', "ترحيل وارد من صندوق $box_name - إقفال $today_date", $today_date);
            // قيد يومية
            post_journal_entry($conn, 'adjustment', $box_id, 'الصندوق - الصندوق الرئيسي', 'الصندوق - ' . $box_name, $transferred_amount, "ترحيل نقدية من صندوق ($box_name) إلى الصندوق الرئيسي", $user_display);
        }

        // 4. تسجيل حركة الإقفال في الأرشيف
        $sql_close_log = "INSERT INTO treasury_closings 
            (box_id, close_date, expected_balance, actual_balance, difference, transferred_amount, user, notes) 
            VALUES 
            ($box_id, '$today_date', $expected_balance, $actual_cash, $difference, $transferred_amount, '$user_display', '$notes')";
        $conn->query($sql_close_log);

        $conn->commit();
        echo "<script>alert('✓ تم إقفال الصندوق وترحيل المبالغ بنجاح!'); window.location='index.php';</script>";
        exit;
    } catch (Exception $e) {
        $conn->rollback();
        $error = 'فشلت عملية الإقفال: ' . $e->getMessage();
    }
}
?>
<title>إقفال الوردية والترحيل اليومي - <?php echo htmlspecialchars($box_name); ?></title>

<div class="row justify-content-center">
    <div class="col-lg-10">
        <div class="card-flat">
            <div class="card-header bg-light">
                <h5 class="mb-0 font-weight-bold text-dark">
                    <i class="fa fa-share-square-o ml-1 text-success"></i> إقفال الوردية والترحيل لليوم: <?php echo htmlspecialchars($box_name); ?>
                </h5>
                <a href="index.php" class="btn-flat btn-flat-secondary btn-sm text-decoration-none">
                    <i class="fa fa-arrow-left ml-1"></i> عودة
                </a>
            </div>
            <div class="card-body">
                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger rounded-0 mb-4"><?php echo $error; ?></div>
                <?php endif; ?>

                <div class="row">
                    <!-- تفاصيل تدفق الحركات -->
                    <div class="col-md-6 mb-4">
                        <h6 class="font-weight-bold text-secondary border-bottom pb-2">خلاصة التدفق المالي لليوم (تاريخ: <?php echo $today_date; ?>)</h6>
                        <table class="table-flat" style="font-size: 0.9rem;">
                            <tbody>
                                <tr>
                                    <td class="text-right font-weight-bold">الرصيد الافتتاحي المقدر</td>
                                    <td class="text-left font-weight-bold"><?php echo number_format($calculated_opening, 2); ?> ر.ي</td>
                                </tr>
                                <tr>
                                    <td class="text-right text-success font-weight-bold">(+) مبيعات نقدية مستلمة</td>
                                    <td class="text-left text-success font-weight-bold">+<?php echo number_format($cash_sales, 2); ?></td>
                                </tr>
                                <tr>
                                    <td class="text-right text-success font-weight-bold">(+) سندات قبض عملاء</td>
                                    <td class="text-left text-success font-weight-bold">+<?php echo number_format($total_receipts, 2); ?></td>
                                </tr>
                                <tr>
                                    <td class="text-right text-success font-weight-bold">(+) تحويلات / إيداعات أخرى</td>
                                    <td class="text-left text-success font-weight-bold">+<?php echo number_format($man_add + $trans_in, 2); ?></td>
                                </tr>
                                <tr>
                                    <td class="text-right text-danger font-weight-bold">(-) مردودات مبيعات نقدية</td>
                                    <td class="text-left text-danger font-weight-bold">-<?php echo number_format($cash_returns, 2); ?></td>
                                </tr>
                                <tr>
                                    <td class="text-right text-danger font-weight-bold">(-) فواتير شراء نقدية</td>
                                    <td class="text-left text-danger font-weight-bold">-<?php echo number_format($cash_purchases, 2); ?></td>
                                </tr>
                                <tr>
                                    <td class="text-right text-danger font-weight-bold">(-) مصروفات عمومية وصرف</td>
                                    <td class="text-left text-danger font-weight-bold">-<?php echo number_format($total_expenses, 2); ?></td>
                                </tr>
                                <tr>
                                    <td class="text-right text-danger font-weight-bold">(-) تحويلات صادرة أخرى</td>
                                    <td class="text-left text-danger font-weight-bold">-<?php echo number_format($man_sub + $trans_out, 2); ?></td>
                                </tr>
                                <tr class="bg-light" style="font-size:1.1rem; border-top:2px solid #000;">
                                    <td class="text-right font-weight-bold text-dark">الرصيد الدفتري المتوقع بالصندوق</td>
                                    <td class="text-left font-weight-bold text-primary"><?php echo number_format($expected_balance, 2); ?> ر.ي</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- نموذج الإقفال والترحيل -->
                    <div class="col-md-6">
                        <h6 class="font-weight-bold text-secondary border-bottom pb-2">مطابقة النقدية والترحيل للصندوق الرئيسي</h6>
                        <form method="POST" id="closeForm">
                            <!-- المبلغ الفعلي -->
                            <div class="form-group mb-3">
                                <label class="font-weight-bold text-dark mb-1">المبلغ الفعلي الموجود بالصندوق (بعد العد المالي):</label>
                                <input type="number" step="any" name="actual_cash" id="actual_cash" 
                                       class="form-control rounded-0 text-center font-weight-bold text-success" 
                                       style="font-size: 1.5rem;" 
                                       value="<?php echo $expected_balance; ?>" 
                                       oninput="calcDiff()" required>
                                <small class="text-muted">قم بعد النقدية بالصندوق بدقة واكتب الإجمالي هنا.</small>
                            </div>

                            <!-- العجز أو الزيادة -->
                            <div class="form-group mb-3">
                                <label class="font-weight-bold text-secondary mb-1">الفارق (عجز / زيادة):</label>
                                <input type="text" id="diff_display" class="form-control rounded-0 text-center font-weight-bold bg-light" 
                                       style="font-size: 1.2rem;" value="0.00 ر.ي" readonly>
                            </div>

                            <!-- المبلغ المراد ترحيله -->
                            <div class="form-group mb-3">
                                <label class="font-weight-bold text-dark mb-1">المبلغ المراد ترحيله للصندوق الرئيسي (المدير):</label>
                                <input type="number" step="any" name="transferred_amount" id="transferred_amount" 
                                       class="form-control rounded-0 text-center font-weight-bold text-danger" 
                                       style="font-size: 1.3rem;" value="0" min="0" oninput="calcNetRemaining()">
                                <small class="text-muted">أدخل المبلغ الذي ستقوم بتسليمه للمدير. القيمة المتبقية ستبقى كعوامة/رصيد افتتاحي لليوم التالي.</small>
                            </div>

                            <!-- الرصيد المتبقي لليوم التالي -->
                            <div class="form-group mb-3">
                                <label class="font-weight-bold text-secondary mb-1">الرصيد المتبقي لليوم التالي (رصيد افتتاحي):</label>
                                <input type="text" id="remaining_display" class="form-control rounded-0 text-center font-weight-bold bg-light text-primary" 
                                       style="font-size: 1.2rem;" readonly>
                            </div>

                            <!-- الملاحظات -->
                            <div class="form-group mb-4">
                                <label class="font-weight-bold text-secondary mb-1">ملاحظات الإقفال:</label>
                                <textarea name="notes" class="form-control rounded-0" rows="2" placeholder="اكتب ملاحظات حول العجز أو الزيادة أو تفاصيل التوريد..."></textarea>
                            </div>

                            <button type="submit" name="btn_confirm_close" class="btn-flat btn-flat-success btn-lg btn-block font-weight-bold" onclick="return confirm('هل أنت متأكد من صحة البيانات وتأكيد إقفال الوردية وترحيل النقدية؟')">
                                <i class="fa fa-check ml-1"></i> اعتماد وإثبات إقفال الصندوق
                            </button>
                        </form>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>

<script>
const expectedBal = <?php echo $expected_balance; ?>;

function calcDiff() {
    const actual = parseFloat(document.getElementById('actual_cash').value) || 0;
    const diff = actual - expectedBal;
    
    const diffEl = document.getElementById('diff_display');
    if (diff === 0) {
        diffEl.value = "0.00 ر.ي (مطابق)";
        diffEl.className = "form-control rounded-0 text-center font-weight-bold bg-light text-muted";
    } else if (diff > 0) {
        diffEl.value = "+" + diff.toFixed(2) + " ر.ي (زيادة في النقدية)";
        diffEl.className = "form-control rounded-0 text-center font-weight-bold bg-light text-success";
    } else {
        diffEl.value = diff.toFixed(2) + " ر.ي (عجز في النقدية)";
        diffEl.className = "form-control rounded-0 text-center font-weight-bold bg-light text-danger";
    }
    calcNetRemaining();
}

function calcNetRemaining() {
    const actual = parseFloat(document.getElementById('actual_cash').value) || 0;
    const trans = parseFloat(document.getElementById('transferred_amount').value) || 0;
    const rem = actual - trans;
    
    document.getElementById('remaining_display').value = rem.toFixed(2) + " ر.ي";
    
    if (trans > actual) {
        document.getElementById('transferred_amount').setCustomValidity('لا يمكن ترحيل مبلغ أكبر من المتوفر الفعلي.');
    } else {
        document.getElementById('transferred_amount').setCustomValidity('');
    }
}

document.addEventListener("DOMContentLoaded", function() {
    calcDiff();
});
</script>

<?php
require_once($dir_prefix . 'includes/footer.php');
?>
