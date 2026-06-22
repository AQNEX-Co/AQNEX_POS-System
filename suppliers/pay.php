<?php
$dir_prefix = '../';
$module = 'suppliers';
require_once($dir_prefix . 'includes/header.php');

check_permission(['admin', 'inventory', 'cashier']);

$active_user_id = intval($_SESSION['SESS_MEMBER_ID']);
$active_user_role = trim($_SESSION['SESS_LAST_NAME']);
$is_admin = ($active_user_role === 'admin' || empty($active_user_role));

if (isset($_POST['btn'])) {
    date_default_timezone_set("Asia/Aden");
    
    $date = $conn->real_escape_string($_POST['date']);
    $supplier_name = $conn->real_escape_string($_POST['select']);
    $amount = doubleval($_POST['pr']);
    $remark = $conn->real_escape_string($_POST['r']);
    $selected_box_id = isset($_POST['box_id']) ? intval($_POST['box_id']) : get_user_box_id($conn, $active_user_id);
    $box_name = get_box_name($conn, $selected_box_id);
    
    // 1. تحديث حساب المورد (خصم المبلغ المدفوع من دائنية المورد)
    $sql_update = "UPDATE Suppliers SET supp_daain = supp_daain - $amount WHERE supp_name = '$supplier_name'";
    $conn->query($sql_update);
    
    // 2. تسجيل عملية تسديد المورد في جدول supplier_payments
    $sql_bush = "INSERT INTO supplier_payments (supp_name, bush_price, remark, bush_date) VALUES ('$supplier_name', '$amount', '$remark', '$date')";
    $conn->query($sql_bush);
    
    // 3. تسجيل العملية كمصروف في الصندوق المالي (جدول treasury_expenses)
    $sql_ms = "INSERT INTO treasury_expenses (st, sname, sdate, sprice, sremark, tot, box_id) VALUES ('تسديد مورد', '$supplier_name', '$date', '$amount', '$remark', '$amount', $selected_box_id)";
    if ($conn->query($sql_ms)) {
        $sid = $conn->insert_id;
        
        // 4. خصم المبلغ من الصندوق وتسجيل العملية
        update_box_balance($conn, $selected_box_id, $amount, 'discount', "تسديد مورد: $supplier_name - بيان: $remark", $date);
        
        // 5. قيد يومية محاسبي مزدوج
        post_journal_entry($conn, 'expense', $sid, 'الذمم الدائنة - ' . $supplier_name, 'الصندوق - ' . $box_name, $amount, "تسديد حساب مورد: $supplier_name - $remark", $_SESSION['SESS_FIRST_NAME'], $selected_box_id);
    }
    
    echo "<script>window.location='index.php';</script>";
    exit;
}
?>
<title>تسديد حساب مورد - تكنولوجيا فون</title>

<div class="row no-print mb-4">
    <div class="col-md-6">
        <h3 class="text-secondary font-weight-bold">
            <i class="fa fa-money ml-2"></i>تسديد حساب مورد
        </h3>
        <p class="text-muted small mb-0">تسجيل سند صرف جديد وتخفيض مديونية المورد وتوثيق الحركة في الصندوق.</p>
    </div>
    <div class="col-md-6 text-left">
        <a href="index.php" class="btn-flat btn-flat-secondary btn-sm text-decoration-none">
            <i class="fa fa-arrow-left ml-1"></i>عودة لقائمة الموردين
        </a>
    </div>
</div>

<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="card-flat">
            <div class="card-header">
                <h5>بيانات سند الصرف للتسديد</h5>
            </div>
            <div class="card-body">
                <form method="POST">
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <div class="form-group">
                                <label class="font-weight-bold text-secondary">تاريخ التسديد <span class="text-danger">*</span></label>
                                <input type="date" name="date" class="form-control rounded-0" value="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                        </div>
                        <div class="col-md-4 mb-3">
                            <div class="form-group">
                                <label class="font-weight-bold text-secondary">الصندوق المستهدف <span class="text-danger">*</span></label>
                                <?php if ($is_admin): ?>
                                    <select name="box_id" class="form-control rounded-0" required>
                                        <?php
                                        $res_b = $conn->query("SELECT box_id, name, mony FROM treasury WHERE is_active = 1 ORDER BY box_id ASC");
                                        if ($res_b) {
                                            while($b = $res_b->fetch_assoc()) {
                                                echo "<option value='{$b['box_id']}' " . ($b['box_id'] == 1 ? 'selected' : '') . ">" . htmlspecialchars($b['name']) . " (" . number_format($b['mony'], 2) . " ر.ي)</option>";
                                            }
                                        }
                                        ?>
                                    </select>
                                <?php else: ?>
                                    <?php $user_box_id = get_user_box_id($conn, $active_user_id); ?>
                                    <input type="hidden" name="box_id" value="<?php echo $user_box_id; ?>">
                                    <input type="text" class="form-control text-center font-weight-bold bg-light rounded-0" readonly value="<?php echo htmlspecialchars(get_box_name($conn, $user_box_id)) . ' (' . number_format(floatval($conn->query("SELECT mony FROM treasury WHERE box_id = $user_box_id")->fetch_assoc()['mony']), 2) . ' ر.ي)'; ?>">
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="col-md-4 mb-3">
                            <div class="form-group">
                                <label class="font-weight-bold text-secondary">المورد المستلم <span class="text-danger">*</span></label>
                                <select name="select" class="form-control rounded-0" required>
                                    <option value="">-- اختر مورد --</option>
                                    <?php
                                    $sql_supp = "SELECT supp_name FROM Suppliers WHERE d_s='0' ORDER BY supp_id DESC";
                                    $res_supp = $conn->query($sql_supp);
                                    if ($res_supp) {
                                        while ($r_service = $res_supp->fetch_assoc()) {
                                            echo "<option value='".htmlspecialchars($r_service['supp_name'])."'>".htmlspecialchars($r_service['supp_name'])."</option>";
                                        }
                                    }
                                    ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <div class="form-group">
                                <label class="font-weight-bold text-secondary">مبلغ التسديد <span class="text-danger">*</span></label>
                                <input type="number" step="0.01" class="form-control rounded-0" name="pr" placeholder="أدخل المبلغ بالريال اليمني" required>
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <div class="form-group">
                                <label class="font-weight-bold text-secondary">البيان / الملاحظات <span class="text-danger">*</span></label>
                                <input type="text" class="form-control rounded-0" name="r" placeholder="تفاصيل الحركة أو رقم المستند اليدوي" required>
                            </div>
                        </div>
                    </div>
                    
                    <hr class="my-4">
                    
                    <div class="form-group mb-0 text-left">
                        <button type="submit" class="btn-flat btn-flat-success mr-2" name="btn">
                            <i class="fa fa-save ml-1"></i>حفظ السند وصرف المبلغ
                        </button>
                        <a href="index.php" class="btn-flat btn-flat-secondary text-decoration-none">إلغاء</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php
require_once($dir_prefix . 'includes/footer.php');
?>
