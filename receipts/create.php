<?php
$dir_prefix = '../';
$module = 'receipts';
require_once($dir_prefix . 'includes/header.php');

check_permission(['admin', 'cashier']);

// معالجة بيانات المساعد الذكي AI Prefill للمقبوضات
$prefill_customer = '';
$prefill_amount = '';
$prefill_remark = '';
if (isset($_GET['ai_prefill'])) {
    $prefill_data = json_decode(base64_decode($_GET['ai_prefill']), true);
    if ($prefill_data) {
        $prefill_customer = $prefill_data['customer_name'] ?? '';
        $prefill_amount = $prefill_data['amount'] ?? '';
        $prefill_remark = $prefill_data['remark'] ?? $prefill_data['notes'] ?? '';
    }
}


$editing_id = isset($_GET['id']) ? intval($_GET['id']) : (isset($_GET['qid']) ? intval($_GET['qid']) : 0);
$editing_receipt = null;
$actual_entries = [];

if ($editing_id > 0) {
    $res_edit = $conn->query("SELECT * FROM receipts WHERE qid = $editing_id LIMIT 1");
    if ($res_edit && $res_edit->num_rows > 0) {
        $editing_receipt = $res_edit->fetch_assoc();
        // جلب القيود المحاسبية الفعلية لهذا السند
        $res_j = $conn->query("SELECT * FROM journal_entries WHERE ref_id = $editing_id AND ref_type = 'receipt' ORDER BY id ASC");
        if ($res_j) {
            while ($j_row = $res_j->fetch_assoc()) {
                $amt = doubleval($j_row['amount']);
                $actual_entries[] = ['account_name' => $j_row['account_debit'], 'debit' => $amt, 'credit' => 0, 'narration' => $j_row['description'] ?? ''];
                $actual_entries[] = ['account_name' => $j_row['account_credit'], 'debit' => 0, 'credit' => $amt, 'narration' => $j_row['description'] ?? ''];
            }
        }
    } else {
        $editing_id = 0;
    }
}

$active_user_id = intval($_SESSION['SESS_MEMBER_ID']);
$active_user_role = trim($_SESSION['SESS_LAST_NAME']);
$is_admin = ($active_user_role === 'admin' || empty($active_user_role));
if (isset($_POST['btn_save'])) {
    $conn->begin_transaction(); // بدء الترانزكشن لضمان سلامة البيانات
    try {
        $editing_id = isset($_POST['editing_id']) ? intval($_POST['editing_id']) : 0;
        $build_date = date('Y-m-d', strtotime($_POST['build_date']));
        $party_type = $conn->real_escape_string($_POST['party_type'] ?? 'customer'); // نوع الطرف: مورد أو عميل
        
        $customers  = $_POST['select2'] ?? $_POST['select_services'] ?? []; 
        $prices     = $_POST['unit_price'] ?? [];
        $remarks    = $_POST['t'] ?? [];
        
        $selected_box_id = isset($_POST['box_id']) ? intval($_POST['box_id']) : get_user_box_id($conn, $active_user_id);
        $box_name        = get_box_name($conn, $selected_box_id);
        
        // --- تصحيح مشكلة TypeError: إرسال القيمة null برمجياً ---
        $sector_id_val   = (isset($_POST['sector_id']) && $_POST['sector_id'] !== '') ? intval($_POST['sector_id']) : null;
        $sql_sector      = is_null($sector_id_val) ? "NULL" : $sector_id_val;

        // --- أولاً: معالجة حالة التعديل (عكس الأثر القديم) ---
        if ($editing_id > 0) {
            $old_res = $conn->query("SELECT * FROM receipts WHERE qid = $editing_id LIMIT 1");
            if ($old_res && $old_row = $old_res->fetch_assoc()) {
                $old_name = $conn->real_escape_string($old_row['cust_name']);
                $old_price = doubleval($old_row['q_price']);
                $old_box_id = intval($old_row['box_id']);

                // 1. عكس رصيد العميل أو المورد (إعادة الدين كما كان)
                $check_mst = $conn->query("SELECT party_type FROM receipt_vouchers_mst WHERE id = $editing_id");
                $old_type = ($check_mst && $r = $check_mst->fetch_assoc()) ? $r['party_type'] : 'customer';

                if ($old_type === 'customer') {
                    $conn->query("UPDATE customers SET cust_madeen = cust_madeen + $old_price WHERE cust_name = '$old_name'");
                } else {
                    $conn->query("UPDATE suppliers SET supp_daain = supp_daain - $old_price WHERE supp_name = '$old_name'");
                }

                // 2. عكس رصيد الصندوق (خصم المبلغ الذي أودع سابقاً)
                if ($old_price > 0 && $old_box_id > 0) {
                    update_box_balance($conn, $old_box_id, $old_price, 'discount', "إلغاء إيداع سند قبض رقم #$editing_id للتعديل", date('Y-m-d'));
                }

                // 3. مسح القيود والتفاصيل القديمة
                $conn->query("DELETE FROM journal_entries WHERE ref_type = 'receipt' AND ref_id = $editing_id");
                $conn->query("DELETE FROM accounting_journal WHERE ref_type = 'receipt' AND ref_id = $editing_id");
                $conn->query("DELETE FROM receipt_vouchers_dtl WHERE voucher_id = $editing_id");
            }
        }

        // --- ثانياً: حفظ البيانات الجديدة بنظام Master-Detail ---
        $count = count($customers);
        $total_voucher_amount = 0;
        foreach($prices as $p) $total_voucher_amount += doubleval($p);

        for ($i = 0; $i < $count; $i++) {
            $party_name = $conn->real_escape_string($customers[$i]);
            $price      = doubleval($prices[$i]);
            $row_remark = $conn->real_escape_string($remarks[$i]);
            
            if (!empty($party_name) && $price > 0) {
                // حفظ الماستر والديل (البنية الاحترافية)
                if ($i === 0) {
                    if ($editing_id > 0) {
                        $sql_up_rec = "UPDATE `receipts` SET `q_date` = '$build_date', `cust_name` = '$party_name', `q_price` = '$price', `remark` = '$row_remark', `total` = '$total_voucher_amount', `box_id` = $selected_box_id WHERE `qid` = $editing_id";
                        $conn->query($sql_up_rec);
                        $qid = $editing_id;
                    } else {
                        $sql_in_rec = "INSERT INTO `receipts`(`q_date`, `cust_name`, `q_price`, `remark`, `total`, `s`, `box_id`) 
                                       VALUES ('$build_date', '$party_name', '$price', '$row_remark', '$total_voucher_amount', 0, $selected_box_id)";
                        $conn->query($sql_in_rec);
                        $qid = $conn->insert_id;
                    }

                    $v_no = 'REC-' . str_pad($qid, 6, '0', STR_PAD_LEFT);
                    
                    // تحديث/إدراج في جدول الماستر الجديد
                    $conn->query("INSERT INTO receipt_vouchers_mst (id, voucher_no, voucher_date, party_type, party_name, total_amount, box_id, sector_id, remark, d_s)
                                  VALUES ($qid, '$v_no', '$build_date', '$party_type', '$party_name', $total_voucher_amount, $selected_box_id, $sql_sector, '$row_remark', 0)
                                  ON DUPLICATE KEY UPDATE party_type = '$party_type', party_name = '$party_name', total_amount = $total_voucher_amount, sector_id = $sql_sector, remark = '$row_remark'");
                }

                // إدراج التفاصيل
                $conn->query("INSERT INTO receipt_vouchers_dtl (voucher_id, amount, remark, d_s) VALUES ($qid, $price, '$row_remark', 0)");

                // 1. تحديث أرصدة الموردين أو العملاء
                if ($party_type === 'customer') {
                    // القبض من عميل يقلل مديونيته
                    $conn->query("UPDATE `customers` SET `cust_madeen` = `cust_madeen` - $price WHERE `cust_name` = '$party_name'");
                } else {
                    // القبض من مورد يزيد من دائنية المورد (أو سداد مسبق)
                    $conn->query("UPDATE `suppliers` SET `supp_daain` = `supp_daain` + $price WHERE `supp_name` = '$party_name'");
                }

                // 2. تحديث رصيد الصندوق (إيداع)
                update_box_balance($conn, $selected_box_id, $price, 'addition', "سند قبض #$qid للطرف: $party_name", $build_date);
                
                // 3. تسجيل القيد المحاسبي (تمرير $sector_id_val كـ null أو int)
                $debit_acc_name = "الصندوق - $box_name";
                $credit_acc_name = ($party_type === 'customer') ? "الذمم المدينة - $party_name" : "الذمم الدائنة - $party_name";
                $j_descr = ($editing_id > 0 ? "تعديل " : "") . "تحصيل دفعة بسند قبض رقم #$qid - $row_remark";
                if (!post_journal_entry($conn, 'receipt', $qid, $debit_acc_name, $credit_acc_name, $price, $j_descr, $_SESSION['SESS_FIRST_NAME'], $selected_box_id, 'YER', 1, $sector_id_val)) {
                    throw new Exception("فشل تسجيل القيد المحاسبي للسند #$qid");
                }

                // 4. إشعار WhatsApp
                $res_phone = $conn->query("SELECT phone FROM customers WHERE cust_name = '$party_name' LIMIT 1");
                if ($res_phone && $res_phone->num_rows > 0) {
                    $phone = $res_phone->fetch_assoc()['phone'];
                    if (!empty($phone)) {
                        require_once($dir_prefix . 'app/Services/WhatsAppService.php');
                        $wa_msg = "شريكنا العزيز، تم استلام دفعة مالية منكم بمبلغ: " . number_format($price, 2) . " ر.ي بسند قبض رقم #{$qid}. شكراً لكم.";
                        \AQNEX\Services\WhatsAppService::sendNotification($global_settings, $phone, $wa_msg);
                    }
                }
            }
        }
        $conn->commit();
        $last_qid = $qid ?? $editing_id;
        echo "<script>window.location='create.php?qid=$last_qid&saved=1';</script>";
        exit;
    } catch (Exception $e) {
        $conn->rollback();
        echo "<script>
        document.addEventListener('DOMContentLoaded', function() {
            if (typeof showSystemAlert === 'function') {
                showSystemAlert('خطأ في العملية', " . json_encode($e->getMessage()) . ", 'danger');
            } else {
                alert('خطأ أثناء الحفظ: " . addslashes($e->getMessage()) . "');
            }
        });
        </script>";
    }
}
?>
<title> سند قبض </title>

<div class="card-flat">
    <!-- AQNEX System Window Header Bar -->
    <div class="aqnex-window-header no-print">
        <div>
            <i class="bi bi-journal-check text-success ml-1"></i>
            <span>أنظمة الحسابات - سند قبض </span>
        </div>
        <div>
            <span class="ml-3">المستخدم: <strong><?php echo htmlspecialchars($_SESSION['SESS_FIRST_NAME'] ?? 'مدير النظام'); ?></strong></span>
            <span>التاريخ: <strong><?php echo date('Y/m/d'); ?></strong></span>
        </div>
    </div>

    <!-- Onyx Pro Action Toolbar (Large Icon Buttons with Hover Tooltips) -->
    <div class="aqnex-toolbar no-print">
        <div style="display: flex; align-items: center; gap: 5px;">
            <!-- ➕ جديد (F2) -->
            <button type="button" class="tool-btn btn-new" title="جديد (F2) - فتح سند قبض جديد" onclick="window.open('create.php', '_blank');">
                <i class="bi bi-file-earmark-plus-fill"></i>
            </button>

            <!-- 💾 حفظ السند (F10) -->
            <button type="submit" form="receiptForm" name="btn_save" class="tool-btn btn-save btn-save-action" title="حفظ وإثبات القبض (F10)">
                <i class="bi bi-floppy-fill"></i>
            </button>

            <!-- ✏️ تعديل السند -->
            <button type="button" class="tool-btn" title="تعديل سند قبض مالي (F6)" onclick="openSearchReceiptModal();">
                <i class="bi bi-pencil-square" style="color: #d97706;"></i>
            </button>

            <!-- 🔍 البحث في المقبوضات السابقة (F3) -->
            <button type="button" class="tool-btn btn-search" title="البحث عن سندات قبض سابقة (F3)" onclick="openSearchReceiptModal();">
                <i class="bi bi-search"></i>
            </button>

            <!-- 🗑 حذف / تصفية السند -->
            <button type="button" class="tool-btn btn-delete" title="حذف وتصفية السند الحالي" onclick="confirmDeleteReceipt();">
                <i class="bi bi-trash-fill"></i>
            </button>

            <!-- 📖 القيود المحاسبية للسند (F8) -->
            <button type="button" class="tool-btn btn-journal" title="عرض القيود المحاسبية للسند (F8)" onclick="openReceiptJournalModal();" style="color: #7c3aed; border-color: #ddd6fe;">
                <i class="bi bi-journal-bookmark-fill"></i>
            </button>

            <!-- 🔄 تراجع وتصفية السند -->
            <button type="button" class="tool-btn" title="تراجع وتصفية بيانات السند" onclick="window.location.href='create.php';">
                <i class="bi bi-arrow-counterclockwise" style="color: #0284c7;"></i>
            </button>
        </div>

        <!-- أزرار الجانب الأيسر -->
        <div style="margin-right: auto; display: flex; align-items: center; gap: 5px;">
            <!-- 🖨 طباعة (F9) -->
            <button type="button" class="tool-btn btn-print" title="طباعة السند (F9)" onclick="window.print();">
                <i class="bi bi-printer-fill"></i>
            </button>
        </div>
    </div>

    <div class="card-body p-2">
        <?php if ($editing_id > 0): ?>
            <div class="alert alert-warning rounded-0 mb-3 text-right no-print" style="border: 1px solid #fbbf24; border-right: 4px solid #d97706 !important; background-color: #fffbeb; color: #92400e; padding: 10px 14px;">
                <i class="bi bi-pencil-square ml-1 font-weight-bold"></i>
                <strong>تأكيد التعديل:</strong> أنت تشاهد وتعدل الآن سند القبض رقم <strong>#<?php echo $editing_id; ?></strong> (بتاريخ: <?php echo htmlspecialchars($editing_receipt['q_date'] ?? ''); ?> - العميل: <?php echo htmlspecialchars($editing_receipt['cust_name'] ?? ''); ?>). يمكنك تعديل البيانات ثم الضغط على <strong>حفظ (F10)</strong> لتحديث السند.
                <a href="create.php" class="btn btn-xs btn-outline-danger font-weight-bold mr-3">إلغاء التعديل والدخول لإنشاء سند جديد</a>
            </div>
        <?php endif; ?>

        <form method="POST" id="receiptForm">
            <input type="hidden" name="editing_id" value="<?php echo $editing_id; ?>">
            <!-- الترويسة الرئيسية للسند -->
            <div class="aqnex-form-grid">
                <div class="row">
                    <div class="col-md-3">
                        <div class="aqnex-form-group">
                            <label class="aqnex-label">تاريخ القبض:</label>
                            <input type="date" name="build_date" class="aqnex-input" value="<?php echo $editing_receipt ? htmlspecialchars($editing_receipt['q_date']) : date('Y-m-d'); ?>" required>
                        </div>
                    </div>

<!-- نوع الطرف -->
<div class="col-md-3">
    <div class="aqnex-form-group">
        <label class="aqnex-label">نوع الطرف:</label>
        <!-- أضفنا name="party_type" ليرسله الفورم -->
        <select id="mainPartyType" name="party_type" class="aqnex-select" onchange="onReceiptPartyTypeChange(this.value)">
            <option value="customer" selected>عميل (ذمم مدينة)</option>
            <option value="supplier">مورد (ذمم دائنة)</option>
        </select>
    </div>
</div>

<!-- اختيار العميل / المورد -->
<div class="col-md-6">
    <div class="aqnex-form-group">
        <label class="aqnex-label" id="mainPartySelectLabel">اختيار الحساب:</label>

        <!-- حاوية العملاء -->
        <div id="div_customer">
            <select id="mainPartySelect_customer" class="aqnex-select" onchange="syncReceiptPartyToRows(this.value)">
                <option value="">-- اختر عميل --</option>
                <?php
                $sql_cust = "SELECT cust_name, cust_madeen FROM customers WHERE d_s = 0 ORDER BY cust_id DESC";
                $res_cust = $conn->query($sql_cust);
                if ($res_cust) {
                    while($row = $res_cust->fetch_assoc()) {
                        echo "<option value='".htmlspecialchars($row['cust_name'])."'>".htmlspecialchars($row['cust_name'])." (المديونية: ".number_format(floatval($row['cust_madeen']), 2).")</option>";
                    }
                }
                ?>
            </select>
        </div>

        <!-- حاوية الموردين -->
        <div id="div_supplier" class="d-none">
            <select id="mainPartySelect_supplier" class="aqnex-select" onchange="syncReceiptPartyToRows(this.value)">
                <option value="">-- اختر مورد --</option>
                <?php
                $res_supp_r = $conn->query("SELECT supp_name, supp_daain FROM suppliers WHERE d_s = 0 ORDER BY supp_id DESC");
                if ($res_supp_r) {
                    while($sr = $res_supp_r->fetch_assoc()) {
                        echo "<option value='".htmlspecialchars($sr['supp_name'])."'>".htmlspecialchars($sr['supp_name'])." (الذمة: ".number_format(floatval($sr['supp_daain']), 2).")</option>";
                    }
                }
                ?>
            </select>
        </div>
    </div>
</div>

                    <div class="col-md-6 mt-1">
                        <div class="aqnex-form-group">
                            <label class="aqnex-label">القطاع / المركز:</label>
                            <select name="sector_id" class="aqnex-select">
                                <option value="">عام</option>
                                <?php
                                $res_sec = $conn->query("SELECT id, name FROM sectors ORDER BY name ASC");
                                if ($res_sec) {
                                    while($sec = $res_sec->fetch_assoc()) {
                                        echo "<option value='{$sec['id']}'>" . htmlspecialchars($sec['name']) . "</option>";
                                    }
                                }
                                ?>
                            </select>
                        </div>
                    </div>

                    <div class="col-md-6 mt-1">
                        <div class="aqnex-form-group">
                            <label class="aqnex-label">الصندوق المستهدف:</label>
                            <?php if ($is_admin): ?>
                                <select name="box_id" class="aqnex-select" required>
                                    <?php
                                    $res_b = $conn->query("SELECT box_id, name, mony FROM treasury WHERE is_active = 1 ORDER BY box_id ASC");
                                    if ($res_b) {
                                        while($b = $res_b->fetch_assoc()) {
                                            $sel_bx = $editing_receipt ? ($b['box_id'] == $editing_receipt['box_id']) : ($b['name'] === 'الصندوق الرئيسي');
                                            echo "<option value='{$b['box_id']}' " . ($sel_bx ? 'selected' : '') . ">" . htmlspecialchars($b['name']) . " (" . number_format($b['mony'], 2) . " ر.ي)</option>";
                                        }
                                    }
                                    ?>
                                </select>
                            <?php else: ?>
                                <?php $user_box_id = get_user_box_id($conn, $active_user_id); ?>
                                <input type="hidden" name="box_id" value="<?php echo $user_box_id; ?>">
                                <input type="text" class="aqnex-input text-center font-weight-bold bg-light" readonly value="<?php echo htmlspecialchars(get_box_name($conn, $user_box_id)) . ' (' . number_format(floatval($conn->query("SELECT mony FROM treasury WHERE box_id = $user_box_id")->fetch_assoc()['mony']), 2) . ' ر.ي)'; ?>">
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>


            <!-- جدول بنود المقبوضات -->
            <div class="table-responsive mt-2">
                <table class="aqnex-grid-table" id="receiptsTable">
                    <thead>
                        <tr>
                            <th style="width: 35%;">العميل / الحساب المستهدف</th>
                            <th style="width: 20%;">المبلغ المقبوض</th>
                            <th style="width: 40%;">بيان القبض / الملاحظات</th>
                            <th class="no-print" style="width: 5%;">إجراء</th>
                        </tr>
                    </thead>
                    <tbody id="itemsContainer">
                        <!-- صف البداية الافتراضي -->
                        <tr class="item-row">
                            <td>
                                <select name="select2[]" class="aqnex-select select-customer" required>
                                    <option value="">-- اختر عميل --</option>
                                    <?php
                                    $sql_cust = "SELECT cust_name, cust_madeen FROM customers WHERE d_s = 0 ORDER BY cust_id DESC";
                                    $res_cust = $conn->query($sql_cust);
                                    if ($res_cust) {
                                        while($row = $res_cust->fetch_assoc()) {
                                            $sel_rc = ($editing_receipt && $editing_receipt['cust_name'] === $row['cust_name']) ? 'selected' : '';
                                            echo "<option value='".htmlspecialchars($row['cust_name'])."' data-debt='".floatval($row['cust_madeen'])."' $sel_rc>".htmlspecialchars($row['cust_name'])." (المديونية: ".number_format(floatval($row['cust_madeen']), 2).")</option>";
                                        }
                                    }
                                    ?>
                                </select>
                            </td>
                            <td>
                                <input type="number" step="any" name="unit_price[]" class="aqnex-input price-input text-center" value="<?php echo $editing_receipt ? floatval($editing_receipt['q_price']) : '0'; ?>" min="1" required>
                            </td>
                            <td>
                                <input type="text" name="t[]" class="aqnex-input" placeholder="دفعة من الحساب..." value="<?php echo $editing_receipt ? htmlspecialchars($editing_receipt['remark']) : ''; ?>" required>
                            </td>
                            <td class="no-print text-center">
                                <button type="button" class="btn btn-sm btn-outline-danger p-1 remove-item-btn" style="height:24px; line-height:1;"><i class="bi bi-trash"></i></button>
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
                <button type="submit" name="btn_save" class="btn-flat btn-flat-primary btn-lg px-5 btn-save-action" title="حفظ وإثبات القبض (F10)">
                    <i class="fa fa-save ml-1"></i> حفظ وإثبات القبض (F10)
                </button>
            </div>
        </form>
    </div>
</div>

<script type="text/javascript">

// ============================================================
// دالة تبديل نوع الطرف في سند القبض (عميل / مورد)
// ============================================================
function onReceiptPartyTypeChange(type) {
    // 1. إخفاء كل الحاويات
    document.getElementById('div_customer').classList.add('d-none');
    document.getElementById('div_supplier').classList.add('d-none');
    const label = document.getElementById('mainPartySelectLabel');

    // 2. إظهار الحاوية المطلوبة وتغيير العنوان
    if (type === 'supplier') {
        document.getElementById('div_supplier').classList.remove('d-none');
        if (label) label.textContent = 'اختيار المورد:';
        // تركيز تلقائي على حقل البحث الذكي للمورد
        setTimeout(() => {
            const inp = document.querySelector('#div_supplier .header-autocomplete-input');
            if (inp) inp.focus();
        }, 100);
    } else {
        document.getElementById('div_customer').classList.remove('d-none');
        if (label) label.textContent = 'اختيار العميل:';
        setTimeout(() => {
            const inp = document.querySelector('#div_customer .header-autocomplete-input');
            if (inp) inp.focus();
        }, 100);
    }

    // 3. مسح بيانات الجدول لضمان التناسق
    document.querySelectorAll('#itemsContainer .select-customer').forEach(s => { s.value = ''; });
}

// ============================================================
// دالة ربط الطرف المختار بالجدول + انتقال للمبلغ
// ============================================================
function syncReceiptPartyToRows(value) {
    if (!value || value.trim() === '') return;

    // عبّئ أول صف وانتقل للمبلغ
    const firstRow = document.querySelector('.item-row');
    if (firstRow) {
        const sel = firstRow.querySelector('.select-customer');
        if (sel) {
            sel.value = value;
            if (sel.value !== value) {
                // أضف الخيار إذا لم يكن موجوداً
                const opt = document.createElement('option');
                opt.value = value;
                opt.text = value;
                opt.selected = true;
                sel.add(opt);
            }
        }
        // انتقل لحقل المبلغ
        const priceInput = firstRow.querySelector('.price-input');
        if (priceInput) {
            priceInput.select();
            priceInput.focus();
        }
    }

    // باقي الصفوف (إذا كانت فارغة)
    let isFirst = true;
    document.querySelectorAll('#itemsContainer .select-customer').forEach(selectEl => {
        if (isFirst) { isFirst = false; return; }
        if (!selectEl.value) {
            selectEl.value = value;
            if (selectEl.value !== value) {
                const opt = document.createElement('option');
                opt.value = value;
                opt.text = value;
                opt.selected = true;
                selectEl.add(opt);
            }
        }
    });
}

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
        const sel = newRow.querySelector("select");
        const partyType = document.getElementById('mainPartyType').value;
        const activeSel = partyType === 'supplier'
            ? document.getElementById('mainPartySelect_supplier')
            : document.getElementById('mainPartySelect_customer');
        const selectedParty = activeSel ? activeSel.value : "";
        if (sel) {
            sel.value = selectedParty;
            if (selectedParty && sel.value !== selectedParty) {
                const opt = document.createElement("option");
                opt.value = selectedParty;
                opt.text = selectedParty;
                opt.selected = true;
                sel.add(opt);
            }
        }
        newRow.querySelector(".price-input").value = "0";
        const remarkInput = newRow.querySelector("input[type='text']");
        if (remarkInput) remarkInput.value = "";
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

    // تعبئة البيانات الممررة من المساعد الذكي تلقائياً
    const targetCust = "<?php echo htmlspecialchars($prefill_customer); ?>";
    const targetAmount = "<?php echo floatval($prefill_amount); ?>";
    const targetRemark = "<?php echo htmlspecialchars($prefill_remark); ?>";
    
    if (targetCust || targetAmount > 0 || targetRemark) {
        const custSelect = document.querySelector(".select-customer");
        if (custSelect && targetCust) {
            let found = false;
            for (let i = 0; i < custSelect.options.length; i++) {
                if (custSelect.options[i].value === targetCust || custSelect.options[i].text.includes(targetCust)) {
                    custSelect.selectedIndex = i;
                    found = true;
                    break;
                }
            }
            if (!found) {
                const opt = document.createElement("option");
                opt.value = targetCust;
                opt.text = targetCust;
                opt.selected = true;
                custSelect.add(opt);
            }
        }
        
        if (targetAmount > 0) {
            const priceInput = document.querySelector(".price-input");
            if (priceInput) {
                priceInput.value = targetAmount;
            }
        }
        
        if (targetRemark) {
            const remarkInput = document.querySelector("input[name='t[]']");
            if (remarkInput) {
                remarkInput.value = targetRemark;
            }
        }
        
        updateGrandTotals();
    }

    // التحقق النهائي قبل إرسال النموذج (منع فقدان البيانات)
    document.getElementById("receiptForm").addEventListener("submit", function(e) {
        let isValid = true;
        let hasItems = false;
        
        document.querySelectorAll(".item-row").forEach(row => {
            const customer = row.querySelector("select").value;
            const amount = parseFloat(row.querySelector(".price-input").value) || 0;
            
            if (customer && customer !== "") {
                hasItems = true;
                if (amount <= 0) {
                    alert(`خطأ: يجب إدخال مبلغ صحيح أكبر من صفر للعميل: ${customer}`);
                    isValid = false;
                }
            } else if (amount > 0) {
                alert("خطأ: يرجى تحديد اسم العميل للمبلغ المدخل!");
                isValid = false;
            }
        });
        
        if (!hasItems) {
            alert("تحذير: يرجى إضافة دفعة واحدة على الأقل قبل الحفظ!");
            isValid = false;
        }
        
        if (!isValid) {
            e.preventDefault();
        }
    });
});

function openSearchReceiptModal() {
    $('#searchReceiptModal').modal('show');
    setTimeout(() => {
        const inp = document.getElementById('searchReceiptQuery');
        if (inp) { inp.focus(); inp.select(); }
    }, 250);
}

function selectPastReceipt(id) {
    window.location.href = 'create.php?id=' + id;
}

function filterPastReceiptsList() {
    const q = (document.getElementById('searchReceiptQuery').value || '').toLowerCase();
    const d = document.getElementById('searchReceiptDate').value;
    selectedReceiptModalIndex = 0;
    const visibleRows = [];
    document.querySelectorAll('.past-rec-row').forEach(row => {
        const id = row.getAttribute('data-id').toLowerCase();
        const cust = row.getAttribute('data-cust').toLowerCase();
        const date = row.getAttribute('data-date');
        let match = (id.includes(q) || cust.includes(q));
        if (d && date !== d) match = false;
        row.style.display = match ? '' : 'none';
        if (match) visibleRows.push(row);
    });
    highlightPastReceiptRow(visibleRows, 0);
}

let selectedReceiptModalIndex = 0;

function highlightPastReceiptRow(rows, index) {
    if (!rows) {
        rows = Array.from(document.querySelectorAll('.past-rec-row')).filter(r => r.style.display !== 'none');
    }
    rows.forEach(r => r.classList.remove('table-success', 'font-weight-bold'));
    if (rows.length > 0) {
        if (index < 0) index = 0;
        if (index >= rows.length) index = rows.length - 1;
        selectedReceiptModalIndex = index;
        rows[selectedReceiptModalIndex].classList.add('table-success', 'font-weight-bold');
        rows[selectedReceiptModalIndex].scrollIntoView({ block: 'nearest' });
    }
}

function deletePastReceipt(id, e) {
    if (e) e.stopPropagation();
    if (confirm(`تأكيد الحذف النهائي:\n\nهل أنت متأكد من رغبتك في حذف سند القبض رقم #${id}؟\nسيتم إرجاع مديونية العميل وإلغاء القيد والتأثير المالي نهائياً، ولن يمكن التراجع عن هذا الإجراء.`)) {
        window.location.href = 'delete.php?id=' + id;
    }
}

document.addEventListener('keydown', function(e) {
    const modalOpen = $('#searchReceiptModal').is(':visible');
    if (modalOpen) {
        const visibleRows = Array.from(document.querySelectorAll('.past-rec-row')).filter(r => r.style.display !== 'none');
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            highlightPastReceiptRow(visibleRows, selectedReceiptModalIndex + 1);
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            highlightPastReceiptRow(visibleRows, selectedReceiptModalIndex - 1);
        } else if (e.key === 'Enter') {
            e.preventDefault();
            if (visibleRows[selectedReceiptModalIndex]) {
                const id = visibleRows[selectedReceiptModalIndex].getAttribute('data-id');
                if (id) selectPastReceipt(id);
            }
        } else if (e.key === 'Delete') {
            e.preventDefault();
            if (visibleRows[selectedReceiptModalIndex]) {
                const id = visibleRows[selectedReceiptModalIndex].getAttribute('data-id');
                if (id) deletePastReceipt(id);
            }
        }
    } else if (e.key === 'F3' || e.key === 'F6') {
        e.preventDefault();
        openSearchReceiptModal();
    }
});
</script>

<!-- مودل البحث عن سندات القبض السابقة -->
<div class="modal fade" id="searchReceiptModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
        <div class="modal-content rounded-0">
            <div class="modal-header bg-success text-white py-2">
                <h6 class="modal-title font-weight-bold"><i class="bi bi-search ml-1"></i> البحث عن سند قبض سابق للتعديل / الإنزال</h6>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body p-3">
                <div class="row mb-3">
                    <div class="col-md-7">
                        <input type="text" id="searchReceiptQuery" class="form-control rounded-0" placeholder="ابحث برقم السند أو اسم العميل... (استخدم الأسهم و Enter للإنزال السريع)" oninput="filterPastReceiptsList()">
                    </div>
                    <div class="col-md-5">
                        <input type="date" id="searchReceiptDate" class="form-control rounded-0" onchange="filterPastReceiptsList()">
                    </div>
                </div>
                <div class="table-responsive" style="max-height: 350px; overflow-y: auto;">
                    <table class="table table-sm table-bordered table-hover text-center mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th># رقم السند</th>
                                <th>التاريخ</th>
                                <th>اسم العميل</th>
                                <th>المبلغ المقبوض</th>
                                <th>الملاحظات</th>
                                <th>إجراء</th>
                            </tr>
                        </thead>
                        <tbody id="pastReceiptsTableBody">
                            <?php
                            $res_past_r = $conn->query("SELECT qid, q_date, cust_name, q_price, remark FROM receipts ORDER BY qid DESC LIMIT 50");
                            if ($res_past_r && $res_past_r->num_rows > 0) {
                                $first = true;
                                while($r_row = $res_past_r->fetch_assoc()) {
                                    $cls = $first ? 'past-rec-row table-success font-weight-bold' : 'past-rec-row';
                                    $first = false;
                                    echo "<tr class='$cls' data-id='{$r_row['qid']}' data-cust='" . htmlspecialchars($r_row['cust_name']) . "' data-date='{$r_row['q_date']}' style='cursor:pointer;' onclick='selectPastReceipt({$r_row['qid']})'>
                                        <td>#{$r_row['qid']}</td>
                                        <td>{$r_row['q_date']}</td>
                                        <td>" . htmlspecialchars($r_row['cust_name']) . "</td>
                                        <td class='font-weight-bold text-success'>" . number_format($r_row['q_price'], 2) . "</td>
                                        <td>" . htmlspecialchars($r_row['remark']) . "</td>
                                        <td>
                                            <button type='button' class='btn btn-xs btn-primary px-2' onclick='selectPastReceipt({$r_row['qid']})'>تعديل / إنزال <i class='bi bi-arrow-down-square-fill mr-1'></i></button>
                                            <button type='button' class='btn btn-xs btn-outline-danger px-2 ml-1' onclick='deletePastReceipt({$r_row['qid']}, event)' title='حذف السند نهائياً'><i class='bi bi-trash-fill'></i> حذف</button>
                                        </td>
                                    </tr>";
                                }
                            } else {
                                echo "<tr><td colspan='6' class='text-muted py-3'>لا توجد سندات قبض مسجلة سابقة.</td></tr>";
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer py-2 justify-content-between">
                <small class="text-muted"><i class="bi bi-keyboard ml-1"></i> نصيحة: التنقل بالأسهم ⬆ ⬇ والضغط على Enter للإنزال السريع أو Delete للحذف</small>
                <button type="button" class="btn btn-secondary btn-sm rounded-0" data-dismiss="modal">إغلاق</button>
            </div>
        </div>
    </div>
</div>

<!-- مودال القيود المحاسبية لسند القبض -->
<div class="modal fade" id="receiptJournalModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
        <div class="modal-content rounded-0">
            <div class="modal-header bg-primary text-white py-2">
                <h6 class="modal-title font-weight-bold"><i class="bi bi-journal-bookmark-fill ml-1"></i> القيود المحاسبية لسند القبض</h6>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
            </div>
            <div class="modal-body p-3">
                <div class="table-responsive">
                    <table class="table table-sm table-bordered text-center">
                        <thead class="bg-light">
                            <tr>
                                <th>الحساب</th>
                                <th>مدين</th>
                                <th>دائن</th>
                                <th>البيان</th>
                            </tr>
                        </thead>
                        <tbody id="receiptJournalBody"></tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-secondary btn-sm rounded-0" data-dismiss="modal">إغلاق</button>
            </div>
        </div>
    </div>
</div>

<script>
const actualReceiptJournalEntries = <?php echo json_encode($actual_entries); ?>;

function openReceiptJournalModal() {
    const body = document.getElementById('receiptJournalBody');
    if (!body) return;
    
    const entries = actualReceiptJournalEntries;
    if (!entries || entries.length === 0) {
        body.innerHTML = '<tr><td colspan="4" class="text-muted py-3">لا توجد قيود محاسبية مسجلة لهذا السند بعد. احفظ السند أولاً.</td></tr>';
    } else {
        let totalDebit = 0, totalCredit = 0;
        let html = '';
        entries.forEach(e => {
            const debit = parseFloat(e.debit) || 0;
            const credit = parseFloat(e.credit) || 0;
            totalDebit += debit;
            totalCredit += credit;
            html += `<tr>
                <td class="text-right">${e.account_name || ''}</td>
                <td class="${debit > 0 ? 'text-danger font-weight-bold' : 'text-muted'}">${debit > 0 ? debit.toFixed(2) : '-'}</td>
                <td class="${credit > 0 ? 'text-success font-weight-bold' : 'text-muted'}">${credit > 0 ? credit.toFixed(2) : '-'}</td>
                <td class="text-right text-muted" style="font-size:0.85em;">${e.narration || ''}</td>
            </tr>`;
        });
        html += `<tr class="table-info font-weight-bold">
            <td>الإجمالي</td>
            <td>${totalDebit.toFixed(2)}</td>
            <td>${totalCredit.toFixed(2)}</td>
            <td>${Math.abs(totalDebit - totalCredit) < 0.01 ? '✓ ميزان محقق' : '⚠ ميزان غير محقق'}</td>
        </tr>`;
        body.innerHTML = html;
    }
    
    if (typeof $ !== 'undefined') {
        $('#receiptJournalModal').modal('show');
    }
}

function confirmDeleteReceipt() {
    const receiptId = <?php echo $editing_id ?: 0; ?>;
    if (receiptId > 0) {
        const msg = 'هل أنت متأكد من حذف سند القبض رقم #' + receiptId + '؟\nسيتم إلغاء القيود المحاسبية واسترجاع المديونية للحساب. لا يمكن التراجع.';
        if (typeof AqnexConfirm !== 'undefined') {
            AqnexConfirm.show('تأكيد الحذف النهائي', msg, function() {
                window.location.href = 'delete.php?id=' + receiptId;
            });
        } else {
            if (confirm(msg)) window.location.href = 'delete.php?id=' + receiptId;
        }
    } else {
        if (typeof AqnexConfirm !== 'undefined') {
            AqnexConfirm.show('تأكيد التصفية', 'هل تريد مسح البيانات وبدء سند جديد؟', function() {
                window.location.href = 'create.php';
            });
        } else {
            if (confirm('تصفية السند؟')) window.location.href = 'create.php';
        }
    }
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'F8') {
        e.preventDefault();
        openReceiptJournalModal();
    }
});
</script>

<?php
require_once($dir_prefix . 'includes/footer.php');
?>
