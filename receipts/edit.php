<?php
$dir_prefix = '../';
$module = 'receipts';
require_once($dir_prefix . 'includes/header.php');

check_permission(['admin']);
if (!isset($_GET['id']) || empty($_GET['id'])) {
    echo "<div class='alert alert-danger rounded-0'>خطأ: لم يتم تحديد سند القبض.</div>";
    require_once($dir_prefix . 'includes/footer.php');
    exit;
}

$qid = intval($_GET['id']);

// جلب التفاصيل الحالية للسند
$sql_details = "SELECT * FROM receipts WHERE qid = $qid";
$res_details = $conn->query($sql_details);
$details = ($res_details) ? $res_details->fetch_assoc() : null;

if (isset($_POST['btn_save'])) {
    $new_cust = $conn->real_escape_string($_POST['select2']);
    $new_price = doubleval($_POST['q_price']);
    $new_remark = $conn->real_escape_string($_POST['remark']);
    
    if ($details) {
        $old_cust = $conn->real_escape_string($details['cust_name']);
        $old_price = doubleval($details['q_price']);
        $old_box_id = intval($details['box_id']);
        $new_box_id = isset($_POST['box_id']) ? intval($_POST['box_id']) : $old_box_id;
        $new_box_name = get_box_name($conn, $new_box_id);
        
        // 1. استعادة المديونية القديمة للعميل القديم
        $conn->query("UPDATE customers SET cust_madeen = cust_madeen + $old_price WHERE cust_name = '$old_cust'");
        
        // 2. تطبيق المديونية الجديدة للعميل الجديد
        $conn->query("UPDATE customers SET cust_madeen = cust_madeen - $new_price WHERE cust_name = '$new_cust'");
        
        // 3. تسوية الصناديق المالية
        if ($old_box_id === $new_box_id) {
            $diff = $new_price - $old_price;
            if ($diff != 0) {
                $type = ($diff > 0) ? 'addition' : 'discount';
                $abs_diff = abs($diff);
                update_box_balance($conn, $old_box_id, $abs_diff, $type, "تعديل سند قبض رقم #$qid للعميل $new_cust (فرق القيمة)", date('Y-m-d'));
            }
        } else {
            // خصم القيمة القديمة من الصندوق القديم
            update_box_balance($conn, $old_box_id, $old_price, 'discount', "تعديل سند قبض رقم #$qid (نقل الصندوق - خصم القيمة القديمة)", date('Y-m-d'));
            // إضافة القيمة الجديدة إلى الصندوق الجديد
            update_box_balance($conn, $new_box_id, $new_price, 'addition', "تعديل سند قبض رقم #$qid (نقل الصندوق - إضافة القيمة الجديدة)", date('Y-m-d'));
        }
        
        // 4. تحديث السند
        $sql_update = "UPDATE receipts SET cust_name='$new_cust', q_price='$new_price', remark='$new_remark', box_id=$new_box_id WHERE qid='$qid'";
        if ($conn->query($sql_update)) {
            // 5. تحديث القيد اليومي المحاسبي (حذف القديم وإدراج جديد)
            $conn->query("DELETE FROM accounting_journal WHERE ref_type = 'receipt' AND ref_id = $qid");
            post_journal_entry($conn, 'receipt', $qid, 'الصندوق - ' . $new_box_name, 'الذمم المدينة - ' . $new_cust, $new_price, "تعديل تحصيل دفعة بسند قبض رقم #$qid - $new_remark", $_SESSION['SESS_FIRST_NAME'], $new_box_id);
            
            echo "<script>window.location='index.php';</script>";
            exit;
        }
    }
}
?>
<title>تعديل سند القبض - تكنولوجيا فون</title>

<div class="card-flat">
    <div class="card-header">
        <h5><i class="fa fa-edit ml-1"></i> تعديل بيانات سند القبض</h5>
        <a href="index.php" class="btn-flat btn-flat-secondary btn-sm text-decoration-none">
            <i class="fa fa-arrow-left ml-1"></i> عودة
        </a>
    </div>
    <div class="card-body">
        <?php if ($details): ?>
        <form method="POST">
            <div class="row">
                <div class="col-md-3 mb-3">
                    <label class="form-label font-weight-bold">العميل</label>
                    <select name="select2" class="form-control" required>
                        <?php
                        $sql_cust = "SELECT cust_name FROM customers WHERE d_s = 0 ORDER BY cust_id DESC";
                        $res_cust = $conn->query($sql_cust);
                        if ($res_cust) {
                            while($row = $res_cust->fetch_assoc()) {
                                $selected = ($row['cust_name'] === $details['cust_name']) ? 'selected' : '';
                                echo "<option value='".htmlspecialchars($row['cust_name'])."' $selected>".htmlspecialchars($row['cust_name'])."</option>";
                            }
                        }
                        ?>
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
                    <label class="form-label font-weight-bold">المبلغ المقبوض</label>
                    <input type="number" step="any" name="q_price" class="form-control text-center" value="<?php echo $details['q_price']; ?>" required>
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label font-weight-bold">بيان القبض / الملاحظات</label>
                    <input type="text" name="remark" class="form-control" value="<?php echo htmlspecialchars($details['remark']); ?>" required>
                </div>
            </div>

            <div class="mt-4 text-left">
                <button type="submit" name="btn_save" class="btn-flat btn-flat-primary px-4">
                    <i class="fa fa-save ml-1"></i> حفظ التعديل
                </button>
            </div>
        </form>
        <?php else: ?>
        <div class="alert alert-danger text-center rounded-0">سند القبض غير موجود!</div>
        <?php endif; ?>
    </div>
</div>

<?php
require_once($dir_prefix . 'includes/footer.php');
?>
