<?php
$dir_prefix = '../';
$module = 'receipts';
require_once($dir_prefix . 'includes/header.php');

check_permission(['admin', 'cashier']);

$active_user_id = intval($_SESSION['SESS_MEMBER_ID']);
$active_user_role = trim($_SESSION['SESS_LAST_NAME']);
$is_admin = ($active_user_role === 'admin' || empty($active_user_role));

if (isset($_POST['btn_save'])) {
    $build_date = date('Y-m-d', strtotime($_POST['build_date']));
    $customers = $_POST['select2'];
    $prices = $_POST['unit_price'];
    $remarks = $_POST['t'];
    $selected_box_id = isset($_POST['box_id']) ? intval($_POST['box_id']) : get_user_box_id($conn, $active_user_id);
    $box_name = get_box_name($conn, $selected_box_id);

    $count = count($customers);
    for ($i = 0; $i < $count; $i++) {
        $cust_name = $conn->real_escape_string($customers[$i]);
        $price = doubleval($prices[$i]);
        $row_remark = $conn->real_escape_string($remarks[$i]);
        
        if (!empty($cust_name) && $price > 0) {
            // إدراج سند القبض مع ربطه بالصندوق المحدد
            $sql_service = "INSERT INTO `receipts`(`q_date`, `cust_name`, `q_price`, `remark`, `total`, `s`, `box_id`) 
                            VALUES ('$build_date', '$cust_name', '$price', '$row_remark', '$price', 0, $selected_box_id)";
            if ($conn->query($sql_service)) {
                $qid = $conn->insert_id;
                
                // خصم المبلغ المقبوض من مديونية العميل (تصحيح الحساب)
                $sql_update_cust = "UPDATE `customers` SET `cust_madeen` = `cust_madeen` - $price WHERE `cust_name` = '$cust_name'";
                $conn->query($sql_update_cust);
                
                // إضافة المبلغ المقبوض إلى الصندوق المحدد
                update_box_balance($conn, $selected_box_id, $price, 'addition', "سند قبض رقم #$qid - عميل: $cust_name", $build_date);
                
                // قيد يومية محاسبي مزدوج
                post_journal_entry($conn, 'receipt', $qid, 'الصندوق - ' . $box_name, 'الذمم المدينة - ' . $cust_name, $price, "تحصيل دفعة بسند قبض رقم #$qid - $row_remark", $_SESSION['SESS_FIRST_NAME'], $selected_box_id);
            }
        }
    }

    echo "<script>window.location='index.php';</script>";
    exit;
}
?>
<title>تسجيل مقبوضات جديدة - تكنولوجيا فون</title>

<div class="card-flat">
    <div class="card-header">
        <h5><i class="fa fa-plus-circle ml-1"></i> تسجيل مقبوضات جديدة (سندات قبض عملاء)</h5>
        <a href="index.php" class="btn-flat btn-flat-secondary btn-sm text-decoration-none">
            <i class="fa fa-arrow-left ml-1"></i> عودة
        </a>
    </div>
    <div class="card-body">
        <form method="POST" id="receiptForm">
            <!-- تاريخ القبض ورصيد الصندوق -->
            <div class="row mb-4">
                <div class="col-md-3 col-sm-6 mb-3">
                    <label class="form-label font-weight-bold">تاريخ القبض</label>
                    <input type="date" name="build_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                </div>
                <div class="col-md-4 col-sm-6 mb-3">
                    <label class="form-label font-weight-bold">الصندوق المستهدف</label>
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
                        <input type="text" class="form-control text-center font-weight-bold bg-light" readonly value="<?php echo htmlspecialchars(get_box_name($conn, $user_box_id)) . ' (' . number_format(floatval($conn->query("SELECT mony FROM treasury WHERE box_id = $user_box_id")->fetch_assoc()['mony']), 2) . ' ر.ي)'; ?>">
                    <?php endif; ?>
                </div>
            </div>

            <!-- جدول بنود المقبوضات -->
            <div class="table-responsive">
                <table class="table-flat" id="receiptsTable">
                    <thead>
                        <tr>
                            <th style="width: 35%;">العميل</th>
                            <th style="width: 20%;">المبلغ المقبوض</th>
                            <th style="width: 40%;">بيان القبض / الملاحظات</th>
                            <th class="no-print" style="width: 5%;">اجراء</th>
                        </tr>
                    </thead>
                    <tbody id="itemsContainer">
                        <!-- صف البداية الافتراضي -->
                        <tr class="item-row">
                            <td>
                                <select name="select2[]" class="form-control select-customer" required>
                                    <option value="">-- اختر عميل --</option>
                                    <?php
                                    $sql_cust = "SELECT cust_name, cust_madeen FROM customers WHERE d_s = 0 ORDER BY cust_id DESC";
                                    $res_cust = $conn->query($sql_cust);
                                    if ($res_cust) {
                                        while($row = $res_cust->fetch_assoc()) {
                                            echo "<option value='".htmlspecialchars($row['cust_name'])."' data-debt='".floatval($row['cust_madeen'])."'>".htmlspecialchars($row['cust_name'])." (المديونية: ".number_format(floatval($row['cust_madeen']), 2).")</option>";
                                        }
                                    }
                                    ?>
                                </select>
                            </td>
                            <td>
                                <input type="number" step="any" name="unit_price[]" class="form-control price-input text-center" value="0" min="1" required>
                            </td>
                            <td>
                                <input type="text" name="t[]" class="form-control" placeholder="دفعة من الحساب..." required>
                            </td>
                            <td class="no-print">
                                <button type="button" class="btn-flat btn-flat-danger btn-sm py-1 px-2 remove-item-btn"><i class="fa fa-trash"></i></button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- أزرار الإجراء للجدول -->
            <div class="mt-3 no-print">
                <button type="button" id="addItemBtn" class="btn-flat btn-flat-success btn-sm">
                    <i class="fa fa-plus ml-1"></i> إضافة بند آخر
                </button>
            </div>

            <hr class="my-4">

            <!-- ملخص المقبوضات الكلية -->
            <div class="row justify-content-end">
                <div class="col-md-5">
                    <table class="table-flat bg-light">
                        <tr>
                            <th class="text-right py-2">إجمالي المبالغ المقبوضة</th>
                            <td class="text-left font-weight-bold text-success" style="font-size: 1.2rem;">
                                <input type="text" id="grandTotalDisplay" name="tot" class="form-control text-left font-weight-bold bg-transparent border-0 text-success" readonly value="0">
                            </td>
                        </tr>
                    </table>
                </div>
            </div>

            <div class="mt-4 no-print text-left">
                <button type="submit" name="btn_save" class="btn-flat btn-flat-primary btn-lg px-5">
                    <i class="fa fa-save ml-1"></i> حفظ وإثبات القبض
                </button>
            </div>
        </form>
    </div>
</div>

<script type="text/javascript">
document.addEventListener("DOMContentLoaded", function() {
    const itemsContainer = document.getElementById("itemsContainer");
    const addItemBtn = document.getElementById("addItemBtn");
    
    // حفظ نسخة من أول صف كنموذج
    const rowTemplate = document.querySelector(".item-row").cloneNode(true);
    
    // دالة تحديث المجاميع الكلية
    function updateGrandTotals() {
        let totalVal = 0;
        document.querySelectorAll(".price-input").forEach(function(input) {
            totalVal += parseFloat(input.value) || 0;
        });
        document.getElementById("grandTotalDisplay").value = totalVal.toFixed(2);
    }
    
    // إضافة صف جديد
    addItemBtn.addEventListener("click", function() {
        const newRow = rowTemplate.cloneNode(true);
        newRow.querySelector("select").value = "";
        newRow.querySelector(".price-input").value = "0";
        newRow.querySelector("input[type='text']").value = "";
        itemsContainer.appendChild(newRow);
    });
    
    // حذف صف
    itemsContainer.addEventListener("click", function(e) {
        if (e.target.classList.contains("remove-item-btn") || e.target.closest(".remove-item-btn")) {
            const row = e.target.closest(".item-row");
            if (document.querySelectorAll(".item-row").length > 1) {
                row.remove();
                updateGrandTotals();
            } else {
                alert("يجب إدخال بند واحد على الأقل!");
            }
        }
    });
    
    // تحديث الإجمالي تلقائياً
    itemsContainer.addEventListener("input", function(e) {
        if (e.target.classList.contains("price-input")) {
            updateGrandTotals();
        }
    });
});
</script>

<?php
require_once($dir_prefix . 'includes/footer.php');
?>
