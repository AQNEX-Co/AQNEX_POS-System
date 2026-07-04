<?php
$dir_prefix = '../';
$module = 'installments';
require_once($dir_prefix . 'includes/header.php');
@include_once($dir_prefix . 'includes/modules.php');
@include_once($dir_prefix . 'includes/accounting_helper.php');

// التحقق من الصلاحيات
check_permission(['admin', 'cashier']);

if (!is_module_enabled('installments')) {
    echo '<div class="alert alert-danger rounded-0">موديول التقسيط غير مفعل.</div>';
    require_once($dir_prefix . 'includes/footer.php');
    exit;
}

if (!isset($_GET['plan_id']) || empty($_GET['plan_id'])) {
    echo "<div class='alert alert-danger rounded-0'>خطأ: لم يتم تحديد خطة التقسيط.</div>";
    require_once($dir_prefix . 'includes/footer.php');
    exit;
}

$plan_id = intval($_GET['plan_id']);
$error = '';
$success = '';

// 1. معالجة تسديد قسط معين
if (isset($_POST['btn_pay_installment'])) {
    $schedule_id = intval($_POST['schedule_id']);
    $amount_paid = doubleval($_POST['amount_paid']);
    
    // جلب القسط المراد دفعه
    $res_sched = $conn->query("SELECT * FROM `installment_schedule` WHERE `id` = $schedule_id AND `plan_id` = $plan_id AND `status` != 'paid' LIMIT 1");
    $sched = $res_sched ? $res_sched->fetch_assoc() : null;
    
    if (!$sched) {
        $error = 'القسط المحدد غير متاح للسداد أو مسدد بالفعل.';
    } else if ($amount_paid <= 0) {
        $error = 'يرجى إدخال مبلغ دفع صحيح أكبر من صفر.';
    } else {
        $conn->begin_transaction();
        try {
            // جلب بيانات الخطة والعميل لربط المحاسبة
            $res_plan = $conn->query("
                SELECT ip.*, c.cust_name, c.cust_id 
                FROM installment_plans ip
                INNER JOIN customers c ON ip.customer_id = c.cust_id
                WHERE ip.id = $plan_id LIMIT 1
            ");
            $plan = $res_plan ? $res_plan->fetch_assoc() : null;

            if ($plan) {
                $customer_id = intval($plan['cust_id']);
                $customer_name = $plan['cust_name'];
                
                // تحديث جدول جدولة الدفعات
                $new_paid = doubleval($sched['amount_paid']) + $amount_paid;
                $status = ($new_paid >= doubleval($sched['amount_due'])) ? 'paid' : 'pending';
                
                $stmt = $conn->prepare("UPDATE `installment_schedule` SET `amount_paid` = ?, `status` = ?, `paid_at` = NOW() WHERE `id` = ?");
                $stmt->bind_param("dsi", $new_paid, $status, $schedule_id);
                $stmt->execute();
                $stmt->close();

                // تحديث خطة التقسيط
                $conn->query("UPDATE `installment_plans` SET `remaining_amount` = `remaining_amount` - $amount_paid WHERE `id` = $plan_id");

                // تحديث مديونية العميل
                $conn->query("UPDATE `customers` SET `cust_madeen` = `cust_madeen` - $amount_paid WHERE `cust_id` = $customer_id");

                // التحقق من انتهاء الخطة بالكامل
                $res_chk = $conn->query("SELECT remaining_amount FROM `installment_plans` WHERE `id` = $plan_id LIMIT 1");
                $chk_row = $res_chk->fetch_assoc();
                if (doubleval($chk_row['remaining_amount']) <= 0) {
                    $conn->query("UPDATE `installment_plans` SET `status` = 'completed' WHERE `id` = $plan_id");
                }

                // تسجيل القيود المحاسبية التلقائية وحركة الصندوق
                $active_box_id = get_user_box_id($conn, $_SESSION['SESS_MEMBER_ID']);
                $box_name = get_box_name($conn, $active_box_id);
                $user_display = $_SESSION['SESS_FIRST_NAME'];

                post_journal_entry(
                    $conn, 
                    'receipt', 
                    $plan['sale_id'], 
                    'الصندوق - ' . $box_name, 
                    'الذمم المدينة - ' . $customer_name, 
                    $amount_paid, 
                    "تحصيل قسط شهري رقم #{$sched['installment_number']} من خطة التقسيط رقم #$plan_id - $customer_name", 
                    $user_display, 
                    $active_box_id
                );

                $conn->commit();
                $success = 'تم تحصيل وسداد القسط بنجاح، وتحديث حسابات الصندوق والعملاء.';
            } else {
                throw new Exception("لم يتم العثور على خطة التقسيط.");
            }
        } catch (Exception $e) {
            $conn->rollback();
            $error = 'فشل سداد القسط: ' . $e->getMessage();
        }
    }
}

// 2. جلب بيانات الخطة والعميل والفاتورة
$res_plan = $conn->query("
    SELECT ip.*, c.cust_name, s.currency_code 
    FROM installment_plans ip
    INNER JOIN customers c ON ip.customer_id = c.cust_id
    INNER JOIN sales s ON ip.sale_id = s.id
    WHERE ip.id = $plan_id LIMIT 1
");
$plan = $res_plan ? $res_plan->fetch_assoc() : null;

if (!$plan) {
    echo '<div class="alert alert-danger rounded-0">خطة التقسيط غير موجودة.</div>';
    require_once($dir_prefix . 'includes/footer.php');
    exit;
}

// جلب جدولة الأقساط
$schedules = [];
$res_sched = $conn->query("SELECT * FROM `installment_schedule` WHERE `plan_id` = $plan_id ORDER BY `installment_number` ASC");
if ($res_sched) {
    while($row = $res_sched->fetch_assoc()) {
        $schedules[] = $row;
    }
}
?>

<title>جدولة أقساط خطة #<?php echo $plan_id; ?> - تكنولوجيا فون</title>

<div class="card-flat">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5><?php echo get_icon('money', 'ml-2 text-primary'); ?> جدول سداد الأقساط للخطة: PLN-<?php echo $plan_id; ?></h5>
        <a href="plans.php" class="btn-flat btn-flat-secondary btn-sm text-decoration-none">
            <?php echo get_icon('logout', 'ml-1'); ?> عودة لقائمة الخطط
        </a>
    </div>
    
    <div class="card-body">
        <?php if (!empty($error)): ?>
            <div class="alert alert-danger rounded-0 mb-4"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <?php if (!empty($success)): ?>
            <div class="alert alert-success rounded-0 mb-4"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <!-- ملخص حالة الخطة -->
        <div class="row mb-4">
            <div class="col-md-4 mb-3">
                <div class="p-3 bg-light border text-center">
                    <span class="text-muted d-block small">إجمالي مبلغ التقسيط</span>
                    <strong class="text-dark" style="font-size: 1.3rem;"><?php echo number_format($plan['total_amount'], 2); ?> ر.ي</strong>
                </div>
            </div>
            <div class="col-md-4 mb-3">
                <div class="p-3 bg-light border text-center">
                    <span class="text-muted d-block small">الدفعة الأولى المدفوعة</span>
                    <strong class="text-success" style="font-size: 1.3rem;"><?php echo number_format($plan['down_payment'], 2); ?> ر.ي</strong>
                </div>
            </div>
            <div class="col-md-4 mb-3">
                <div class="p-3 bg-light border text-center">
                    <span class="text-muted d-block small">المبلغ المتبقي للتحصيل</span>
                    <strong class="text-danger" style="font-size: 1.3rem;"><?php echo number_format($plan['remaining_amount'], 2); ?> ر.ي</strong>
                </div>
            </div>
            <div class="col-md-12">
                <table class="table-flat border bg-white">
                    <tr>
                        <th style="width: 25%;">العميل المستفيد</th>
                        <td><strong><?php echo htmlspecialchars($plan['cust_name']); ?></strong></td>
                        <th style="width: 25%;">حالة الخطة العامة</th>
                        <td>
                            <?php 
                            switch($plan['status']) {
                                case 'active': echo '<span class="badge badge-success px-2 py-1">نشطة (قيد التحصيل)</span>'; break;
                                case 'completed': echo '<span class="badge badge-dark px-2 py-1">مسددة ومغلقة</span>'; break;
                                case 'defaulted': echo '<span class="badge badge-danger px-2 py-1">متعثرة عن السداد</span>'; break;
                            }
                            ?>
                        </td>
                    </tr>
                </table>
            </div>
        </div>

        <!-- جدول الأقساط المجدولة تفصيلياً -->
        <h6 class="font-weight-bold text-secondary border-bottom pb-2 mb-3">جدول تواريخ الاستحقاق والتحصيلات</h6>
        <div class="table-responsive">
            <table class="table-flat border">
                <thead>
                    <tr>
                        <th style="width: 10%;">رقم الدفعة</th>
                        <th>تاريخ الاستحقاق</th>
                        <th>المبلغ المطلوب سداده</th>
                        <th>المبلغ المدفوع</th>
                        <th>حالة الدفعة</th>
                        <th>تاريخ التحصيل</th>
                        <th class="no-print" style="width: 15%;">إجراء</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($schedules as $s): ?>
                        <tr class="<?php echo ($s['status'] === 'overdue') ? 'bg-light-danger' : ''; ?>">
                            <td class="font-weight-bold">قسط #<?php echo $s['installment_number']; ?></td>
                            <td><strong><?php echo $s['due_date']; ?></strong></td>
                            <td class="text-primary font-weight-bold"><?php echo number_format($s['amount_due'], 2); ?> ر.ي</td>
                            <td class="text-success"><?php echo number_format($s['amount_paid'], 2); ?> ر.ي</td>
                            <td>
                                <?php 
                                if ($s['status'] === 'paid') {
                                    echo '<span class="badge badge-success px-2 py-1 rounded-0">تم السداد</span>';
                                } else {
                                    // إذا تجاوز تاريخ الاستحقاق ولم يسدد بالكامل
                                    $is_overdue = (strtotime($s['due_date']) < time());
                                    if ($is_overdue) {
                                        echo '<span class="badge badge-danger px-2 py-1 rounded-0">متأخر / مستحق</span>';
                                    } else {
                                        echo '<span class="badge badge-warning px-2 py-1 rounded-0">قيد الانتظار</span>';
                                    }
                                }
                                ?>
                            </td>
                            <td><?php echo $s['paid_at'] ? date('Y-m-d H:i', strtotime($s['paid_at'])) : '—'; ?></td>
                            <td class="no-print">
                                <?php if ($s['status'] !== 'paid'): ?>
                                    <button type="button" class="btn btn-success btn-sm rounded-0 pay-inst-btn" 
                                            data-id="<?php echo $s['id']; ?>"
                                            data-num="<?php echo $s['installment_number']; ?>"
                                            data-due="<?php echo number_format($s['amount_due'] - $s['amount_paid'], 2, '.', ''); ?>"
                                            data-toggle="modal" data-target="#payInstallmentModal">
                                        تحصيل القسط
                                    </button>
                                <?php else: ?>
                                    <span class="text-muted small">سداد مكتمل</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal سداد القسط -->
<div class="modal fade" id="payInstallmentModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content rounded-0">
            <div class="modal-header bg-success text-white rounded-0">
                <h5 class="modal-title font-weight-bold">تحصيل دفعة قسط شهري</h5>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form method="POST">
                <input type="hidden" name="schedule_id" id="pay_schedule_id">
                <div class="modal-body text-right">
                    <p class="font-weight-bold text-secondary mb-3">تحصيل مبلغ القسط رقم <span id="pay_installment_num" class="text-primary"></span></p>
                    <div class="form-group mb-3">
                        <label class="font-weight-bold text-secondary">المبلغ المستلم نقداً (المقبوض) *</label>
                        <div class="input-group">
                            <input type="number" step="any" name="amount_paid" id="pay_amount_paid" class="form-control rounded-0 font-weight-bold text-center form-control-lg border-success" required>
                            <div class="input-group-append">
                                <span class="input-group-text rounded-0 bg-success text-white">ر.ي</span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer rounded-0">
                    <button type="submit" name="btn_pay_installment" class="btn-flat btn-flat-success px-4">تأكيد تحصيل المبلغ</button>
                    <button type="button" class="btn btn-secondary rounded-0" data-dismiss="modal">إلغاء</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function() {
    document.querySelectorAll(".pay-inst-btn").forEach(btn => {
        btn.addEventListener("click", function() {
            const id = this.getAttribute("data-id");
            const num = this.getAttribute("data-num");
            const due = this.getAttribute("data-due");

            document.getElementById("pay_schedule_id").value = id;
            document.getElementById("pay_installment_num").textContent = "#" + num;
            document.getElementById("pay_amount_paid").value = due;
            document.getElementById("pay_amount_paid").max = due; // منع إدخال أكبر من المستحق
        });
    });
});
</script>

<?php require_once($dir_prefix . 'includes/footer.php'); ?>
