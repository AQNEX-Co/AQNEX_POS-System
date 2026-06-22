<?php
$dir_prefix = '../';
$module = 'expenses';
require_once($dir_prefix . 'includes/header.php');

check_permission(['admin']);
if (!isset($_GET['sid']) || empty($_GET['sid'])) {
    echo "<div class='alert alert-danger rounded-0'>خطأ: لم يتم تحديد بند المصروف لتعديله.</div>";
    require_once($dir_prefix . 'includes/footer.php');
    exit;
}

$sid = intval($_GET['sid']);

// جلب التفاصيل الحالية أولاً
$sql_details = "SELECT * FROM treasury_expenses WHERE sid = $sid";
$res_details = $conn->query($sql_details);
$details = ($res_details) ? $res_details->fetch_assoc() : null;

if (isset($_POST['btn_save'])) {
    $expense_type = $conn->real_escape_string($_POST['select_services']);
    $price = doubleval($_POST['sprice']);
    $remark = $conn->real_escape_string($_POST['sremark']);
    
    if ($details) {
        $old_price = doubleval($details['sprice']);
        $old_box_id = intval($details['box_id']);
        $new_box_id = isset($_POST['box_id']) ? intval($_POST['box_id']) : $old_box_id;
        $new_box_name = get_box_name($conn, $new_box_id);
        
        // 1. تسوية الصناديق المالية
        if ($old_box_id === $new_box_id) {
            $diff = $price - $old_price;
            if ($diff != 0) {
                // زيادة المصروف تعني خصماً من الصندوق، ونقصانه يعني إضافة إليه
                $type = ($diff > 0) ? 'discount' : 'addition';
                $abs_diff = abs($diff);
                update_box_balance($conn, $old_box_id, $abs_diff, $type, "تعديل سند صرف رقم #$sid (فرق القيمة)", date('Y-m-d'));
            }
        } else {
            // إرجاع القيمة القديمة للصندوق القديم
            update_box_balance($conn, $old_box_id, $old_price, 'addition', "تعديل سند صرف رقم #$sid (نقل الصندوق - إرجاع القيمة القديمة)", date('Y-m-d'));
            // خصم القيمة الجديدة من الصندوق الجديد
            update_box_balance($conn, $new_box_id, $price, 'discount', "تعديل سند صرف رقم #$sid (نقل الصندوق - خصم القيمة الجديدة)", date('Y-m-d'));
        }
        
        // 2. تحديث سند الصرف
        $sql_update = "UPDATE treasury_expenses SET st='$expense_type', sprice='$price', sremark='$remark', box_id=$new_box_id WHERE sid='$sid'";
        if ($conn->query($sql_update)) {
            // تحديث الجدول الموازي expenses
            $sql_update_expenses = "UPDATE expenses SET sname='$expense_type', m_price='$price', remark='$remark' WHERE m_date='{$details['sdate']}' AND sname='{$details['st']}' AND m_price='$old_price' LIMIT 1";
            $conn->query($sql_update_expenses);
            
            // 3. تحديث القيد اليومي المحاسبي
            $conn->query("DELETE FROM accounting_journal WHERE ref_type = 'expense' AND ref_id = $sid");
            post_journal_entry($conn, 'expense', $sid, 'مصروفات - ' . $expense_type, 'الصندوق - ' . $new_box_name, $price, "تعديل صرف مبلغ بسند صرف رقم #$sid - $remark", $_SESSION['SESS_FIRST_NAME'], $new_box_id);
            
            echo "<script>window.location='index.php';</script>";
            exit;
        }
    }
}
?>
<title>تعديل بند المصروفات - تكنولوجيا فون</title>

<div class="card-flat">
    <div class="card-header">
        <h5><i class="fa fa-edit ml-1"></i> تعديل بيانات بند المصروفات</h5>
        <a href="index.php" class="btn-flat btn-flat-secondary btn-sm text-decoration-none">
            <i class="fa fa-arrow-left ml-1"></i> عودة
        </a>
    </div>
    <div class="card-body">
        <?php if ($details): ?>
        <form method="POST">
            <div class="row">
                <div class="col-md-3 mb-3">
                    <label class="form-label font-weight-bold">حاجة الصرف / البند</label>
                    <select name="select_services" class="form-control" required>
                        <option value="وجبة فطور" <?php echo ($details['st'] == 'وجبة فطور') ? 'selected' : ''; ?>>وجبة فطور</option>
                        <option value="وجبة غداء" <?php echo ($details['st'] == 'وجبة غداء') ? 'selected' : ''; ?>>وجبة غداء</option>
                        <option value="وجبة عشاء" <?php echo ($details['st'] == 'وجبة عشاء') ? 'selected' : ''; ?>>وجبة عشاء</option>
                        <option value="رواتب" <?php echo ($details['st'] == 'رواتب') ? 'selected' : ''; ?>>رواتب</option>
                        <option value="اجور" <?php echo ($details['st'] == 'اجور') ? 'selected' : ''; ?>>اجور</option>
                        <option value="كهرباء" <?php echo ($details['st'] == 'كهرباء') ? 'selected' : ''; ?>>كهرباء</option>
                        <option value="ماء" <?php echo ($details['st'] == 'ماء') ? 'selected' : ''; ?>>ماء</option>
                        <option value="خاصة" <?php echo ($details['st'] == 'خاصة') ? 'selected' : ''; ?>>خاصة</option>
                        <option value="اخرى" <?php echo ($details['st'] == 'اخرى') ? 'selected' : ''; ?>>اخرى</option>
                    </select>
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label font-weight-bold">الصندوق المستهدف</label>
                    <select name="box_id" class="form-control" required>
                        <?php
                        $res_b = $conn->query("SELECT box_id, name, mony FROM treasury WHERE is_active = 1 OR box_id = " . intval($details['box_id']) . " ORDER BY box_id ASC");
                        if ($res_b) {
                            while($b = $res_b->fetch_assoc()) {
                                $sel_box = ($b['box_id'] == $details['box_id']) ? 'selected' : '';
                                echo "<option value='{$b['box_id']}' $sel_box>" . htmlspecialchars($b['name']) . " (" . number_format($b['mony'], 2) . " ر.ي)</option>";
                            }
                        }
                        ?>
                    </select>
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label font-weight-bold">مبلغ الصرف</label>
                    <input type="number" step="any" name="sprice" class="form-control text-center" value="<?php echo $details['sprice']; ?>" required>
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label font-weight-bold">البيان والملاحظات</label>
                    <input type="text" name="sremark" class="form-control" value="<?php echo htmlspecialchars($details['sremark']); ?>" required>
                </div>
            </div>

            <div class="mt-4 text-left">
                <button type="submit" name="btn_save" class="btn-flat btn-flat-primary px-4">
                    <i class="fa fa-save ml-1"></i> حفظ التعديل
                </button>
            </div>
        </form>
        <?php else: ?>
        <div class="alert alert-danger text-center rounded-0">بند المصروف غير موجود!</div>
        <?php endif; ?>
    </div>
</div>

<?php
require_once($dir_prefix . 'includes/footer.php');
?>
