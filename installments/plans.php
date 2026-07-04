<?php
$dir_prefix = '../';
$module = 'installments';
require_once($dir_prefix . 'includes/header.php');
@include_once($dir_prefix . 'includes/modules.php');
@include_once($dir_prefix . 'includes/accounting_helper.php');

// التحقق من الصلاحيات
check_permission(['admin', 'cashier']);

if (!is_module_enabled('installments')) {
    echo '
    <div class="card-flat">
        <div class="card-body text-center py-5">
            <h4 class="text-danger mb-3">' . get_icon('money', 'ml-2') . 'موديول الأقساط غير مفعل</h4>
            <p class="text-muted">يرجى تفعيل موديول البيع بالتقسيط من إعدادات النظام لتتمكن من استخدام هذه الشاشة.</p>
            ' . (($_SESSION['SESS_LAST_NAME'] === 'admin') ? '<a href="../settings/modules.php" class="btn btn-primary rounded-0 px-4">لوحة إدارة الموديولات</a>' : '') . '
        </div>
    </div>';
    require_once($dir_prefix . 'includes/footer.php');
    exit;
}

$error = '';
$success = '';

// إنشاء خطة تقسيط جديدة لفاتورة مبيعات
if (isset($_POST['btn_create_plan'])) {
    $sale_id = intval($_POST['sale_id']);
    $down_payment = doubleval($_POST['down_payment']);
    $installments_count = intval($_POST['installments_count']);
    $first_due_date = $conn->real_escape_string($_POST['first_due_date']);

    // جلب تفاصيل الفاتورة
    $res_sale = $conn->query("SELECT * FROM sales WHERE id = $sale_id AND remaining_total > 0 AND delete_status = 0 LIMIT 1");
    $sale = $res_sale ? $res_sale->fetch_assoc() : null;

    if (!$sale) {
        $error = 'الفاتورة المحددة غير صالحة أو تم سدادها بالكامل بالفعل.';
    } else if ($installments_count <= 0) {
        $error = 'يرجى تحديد عدد دفعات أقساط صالح.';
    } else if (empty($first_due_date)) {
        $error = 'يرجى تحديد تاريخ أول قسط.';
    } else {
        // التحقق من عدم وجود خطة نشطة لنفس الفاتورة مسبقاً
        $res_exists = $conn->query("SELECT id FROM installment_plans WHERE sale_id = $sale_id");
        if ($res_exists && $res_exists->num_rows > 0) {
            $error = 'هناك خطة تقسيط منشأة بالفعل لهذه الفاتورة.';
        } else {
            $customer_name = $sale['cust_name'];
            
            // جلب العميل
            $res_cust = $conn->query("SELECT cust_id FROM customers WHERE cust_name = '" . $conn->real_escape_string($customer_name) . "' AND d_s = 0 LIMIT 1");
            $cust_row = $res_cust ? $res_cust->fetch_assoc() : null;
            $customer_id = $cust_row ? intval($cust_row['cust_id']) : 0;

            if ($customer_id === 0) {
                $error = 'لا يمكن تقسيط مبيعات لعميل نقدي افتراضي، يرجى ربط الفاتورة بعميل مسجل أولاً.';
            } else {
                $total_amount = doubleval($sale['remaining_total']); // المبلغ المتبقي لتقسيطه
                $remaining_amount = $total_amount - $down_payment;
                
                if ($remaining_amount < 0) {
                    $error = 'مبلغ الدفعة الأولى أكبر من إجمالي المبلغ المتبقي للفاتورة.';
                } else {
                    $conn->begin_transaction();
                    try {
                        // 1. إدراج خطة التقسيط
                        $stmt = $conn->prepare("INSERT INTO `installment_plans` (`sale_id`, `customer_id`, `total_amount`, `down_payment`, `remaining_amount`, `installments_count`, `status`) VALUES (?, ?, ?, ?, ?, ?, 'active')");
                        $stmt->bind_param("iidddi", $sale_id, $customer_id, $total_amount, $down_payment, $remaining_amount, $installments_count);
                        $stmt->execute();
                        $plan_id = $conn->insert_id;
                        $stmt->close();

                        // 2. جدولة الدفعات (الأقساط الشهرية)
                        $monthly_amount = $remaining_amount / $installments_count;
                        $due_date = new DateTime($first_due_date);
                        
                        $stmt_sched = $conn->prepare("INSERT INTO `installment_schedule` (`plan_id`, `installment_number`, `due_date`, `amount_due`, `amount_paid`, `status`) VALUES (?, ?, ?, ?, 0.00, 'pending')");
                        
                        for ($i = 1; $i <= $installments_count; $i++) {
                            if ($i > 1) {
                                $due_date->modify('+1 month');
                            }
                            $due_date_str = $due_date->format('Y-m-d');
                            $stmt_sched->bind_param("iisd", $plan_id, $i, $due_date_str, $monthly_amount);
                            $stmt_sched->execute();
                        }
                        $stmt_sched->close();

                        // 3. معالجة الدفعة الأولى (Down Payment) كاش كقيد محاسبي
                        $active_box_id = get_user_box_id($conn, $_SESSION['SESS_MEMBER_ID']);
                        $box_name = get_box_name($conn, $active_box_id);
                        $user_display = $_SESSION['SESS_FIRST_NAME'];

                        if ($down_payment > 0) {
                            // تخفيض مديونية العميل بالدفعة الأولى المستلمة نقداً
                            $conn->query("UPDATE customers SET cust_madeen = cust_madeen - $down_payment WHERE cust_id = $customer_id");
                            
                            // قيد محاسبي
                            post_journal_entry($conn, 'receipt', $sale_id, 'الصندوق - ' . $box_name, 'الذمم المدينة - ' . $customer_name, $down_payment, "دفعة أولى مقدمة أقساط فاتورة مبيعات #$sale_id - $customer_name", $user_display, $active_box_id);
                        }

                        $conn->commit();
                        $success = 'تم إنشاء وجدولة خطة التقسيط بنجاح.';
                    } catch (Exception $e) {
                        $conn->rollback();
                        $error = 'فشل معالجة وإنشاء خطة التقسيط: ' . $e->getMessage();
                    }
                }
            }
        }
    }
}

// جلب خطط التقسيط
$plans = [];
$res_plans = $conn->query("
    SELECT ip.*, c.cust_name, s.remaining_total as sale_remaining
    FROM installment_plans ip
    LEFT JOIN customers c ON ip.customer_id = c.cust_id
    LEFT JOIN sales s ON ip.sale_id = s.id
    ORDER BY ip.id DESC
");
if ($res_plans) {
    while($row = $res_plans->fetch_assoc()) {
        $plans[] = $row;
    }
}

// جلب الفواتير غير المسددة المتاحة للتقسيط
$unpaid_sales = [];
$res_unpaid = $conn->query("
    SELECT s.id, s.cust_name, s.total, s.remaining_total, s.build_date 
    FROM sales s 
    LEFT JOIN customers c ON s.cust_name = c.cust_name
    WHERE s.remaining_total > 0 
      AND s.delete_status = 0 
      AND c.cust_id IS NOT NULL
      AND s.id NOT IN (SELECT sale_id FROM installment_plans)
    ORDER BY s.id DESC
");
if ($res_unpaid) {
    while($row = $res_unpaid->fetch_assoc()) {
        $unpaid_sales[] = $row;
    }
}
?>

<title>البيع بالتقسيط - تكنولوجيا فون</title>

<div class="card-flat">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5><?php echo get_icon('money', 'ml-2 text-primary'); ?> خطط المبيعات المقسطة وجدولة الأقساط</h5>
        <?php if (!empty($unpaid_sales)): ?>
            <button type="button" class="btn-flat btn-flat-success btn-sm" data-toggle="modal" data-target="#createPlanModal">
                <?php echo get_icon('plus', 'ml-1'); ?> إنشاء خطة تقسيط جديدة
            </button>
        <?php endif; ?>
    </div>
    
    <div class="card-body">
        <?php if (!empty($error)): ?>
            <div class="alert alert-danger rounded-0 mb-4"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <?php if (!empty($success)): ?>
            <div class="alert alert-success rounded-0 mb-4"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <div class="table-responsive">
            <table class="table-flat border">
                <thead>
                    <tr>
                        <th>رقم الخطة</th>
                        <th>العميل</th>
                        <th>رقم فاتورة البيع</th>
                        <th>المبلغ الإجمالي المتبقي للتقسيط</th>
                        <th>الدفعة الأولى المدفوعة</th>
                        <th>المبلغ المتبقي بالأقساط</th>
                        <th>عدد الدفعات</th>
                        <th>الحالة</th>
                        <th>تاريخ الإنشاء</th>
                        <th class="no-print">إجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($plans)): ?>
                        <tr>
                            <td colspan="10" class="text-center text-muted py-4">لا توجد خطط تقسيط مضافة حالياً.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach($plans as $p): ?>
                            <tr>
                                <td class="font-weight-bold">PLN-<?php echo $p['id']; ?></td>
                                <td class="font-weight-bold"><?php echo htmlspecialchars($p['cust_name']); ?></td>
                                <td>
                                    <a href="../sales/view.php?id=<?php echo $p['sale_id']; ?>" target="_blank">
                                        فاتورة مبيعات #<?php echo $p['sale_id']; ?>
                                    </a>
                                </td>
                                <td><?php echo number_format($p['total_amount'], 2); ?> ر.ي</td>
                                <td><?php echo number_format($p['down_payment'], 2); ?> ر.ي</td>
                                <td class="text-danger font-weight-bold"><?php echo number_format($p['remaining_amount'], 2); ?> ر.ي</td>
                                <td><?php echo $p['installments_count']; ?> أشهر</td>
                                <td>
                                    <?php 
                                    switch($p['status']) {
                                        case 'active': 
                                            echo '<span class="badge badge-success px-2 py-1 rounded-0">نشطة (قيد التحصيل)</span>'; 
                                            break;
                                        case 'completed': 
                                            echo '<span class="badge badge-dark px-2 py-1 rounded-0">مسددة بالكامل</span>'; 
                                            break;
                                        case 'defaulted': 
                                            echo '<span class="badge badge-danger px-2 py-1 rounded-0">متعثرة</span>'; 
                                            break;
                                    }
                                    ?>
                                </td>
                                <td><?php echo date('Y-m-d', strtotime($p['created_at'])); ?></td>
                                <td class="no-print">
                                    <a href="pay.php?plan_id=<?php echo $p['id']; ?>" class="btn btn-primary btn-sm rounded-0">
                                        عرض جدول الأقساط وتحصيلها
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal إنشاء خطة تقسيط -->
<?php if (!empty($unpaid_sales)): ?>
<div class="modal fade" id="createPlanModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content rounded-0">
            <div class="modal-header bg-success text-white rounded-0">
                <h5 class="modal-title font-weight-bold">تقسيط فاتورة مبيعات</h5>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form method="POST">
                <div class="modal-body text-right">
                    <div class="form-group mb-3">
                        <label class="font-weight-bold text-secondary">اختر الفاتورة المتاحة للتقسيط *</label>
                        <select name="sale_id" id="planSaleSelect" class="form-control rounded-0" required>
                            <option value="">-- اختر الفاتورة والعميل --</option>
                            <?php foreach($unpaid_sales as $s): ?>
                                <option value="<?php echo $s['id']; ?>" data-total="<?php echo $s['remaining_total']; ?>">
                                    فاتورة #<?php echo $s['id']; ?> | العميل: <?php echo htmlspecialchars($s['cust_name']); ?> (المتبقي: <?php echo $s['remaining_total']; ?> ر.ي)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 form-group mb-3">
                            <label class="font-weight-bold text-secondary">الدفعة الأولى المقدمة (الكاش) *</label>
                            <input type="number" step="any" name="down_payment" id="downPaymentInput" class="form-control rounded-0 text-center font-weight-bold" value="0.00" min="0" required>
                        </div>
                        <div class="col-md-6 form-group mb-3">
                            <label class="font-weight-bold text-secondary">عدد دفعات التقسيط (أشهر) *</label>
                            <input type="number" name="installments_count" class="form-control rounded-0 text-center" value="6" min="1" required>
                        </div>
                        <div class="col-md-12 form-group mb-3">
                            <label class="font-weight-bold text-secondary">تاريخ استحقاق أول قسط شهري *</label>
                            <input type="date" name="first_due_date" class="form-control rounded-0 text-center" value="<?php echo date('Y-m-d', strtotime('+1 month')); ?>" required>
                        </div>
                    </div>
                </div>
                <div class="modal-footer rounded-0">
                    <button type="submit" name="btn_create_plan" class="btn-flat btn-flat-success px-4">تأكيد الجدولة والتقسيط</button>
                    <button type="button" class="btn btn-secondary rounded-0" data-dismiss="modal">إلغاء</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require_once($dir_prefix . 'includes/footer.php'); ?>
