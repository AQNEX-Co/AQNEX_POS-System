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

if ($editing_id > 0) {
    $res_edit = $conn->query("SELECT * FROM receipts WHERE qid = $editing_id LIMIT 1");
    if ($res_edit && $res_edit->num_rows > 0) {
        $editing_receipt = $res_edit->fetch_assoc();
    } else {
        $editing_id = 0;
    }
}

$active_user_id = intval($_SESSION['SESS_MEMBER_ID']);
$active_user_role = trim($_SESSION['SESS_LAST_NAME']);
$is_admin = ($active_user_role === 'admin' || empty($active_user_role));

if (isset($_POST['btn_save'])) {
    $editing_id = isset($_POST['editing_id']) ? intval($_POST['editing_id']) : 0;
    $build_date = date('Y-m-d', strtotime($_POST['build_date']));
    $customers = $_POST['select2'];
    $prices = $_POST['unit_price'];
    $remarks = $_POST['t'];
    $selected_box_id = isset($_POST['box_id']) ? intval($_POST['box_id']) : get_user_box_id($conn, $active_user_id);
    $box_name = get_box_name($conn, $selected_box_id);

    $conn->begin_transaction();
    try {
        if ($editing_id > 0) {
            $old_res = $conn->query("SELECT * FROM receipts WHERE qid = $editing_id LIMIT 1");
            if ($old_res && $old_row = $old_res->fetch_assoc()) {
                $old_cust = $conn->real_escape_string($old_row['cust_name']);
                $old_price = doubleval($old_row['q_price']);
                $old_box_id = intval($old_row['box_id']);

                $conn->query("UPDATE customers SET cust_madeen = cust_madeen + $old_price WHERE cust_name = '$old_cust'");
                if ($old_price > 0 && $old_box_id > 0) {
                    update_box_balance($conn, $old_box_id, $old_price, 'discount', "إلغاء إيداع سند قبض رقم #$editing_id للتعديل", date('Y-m-d'));
                }
                $conn->query("DELETE FROM journal_entries WHERE ref_type = 'receipt' AND ref_id = $editing_id");
                $conn->query("DELETE FROM accounting_journal WHERE ref_type = 'receipt' AND ref_id = $editing_id");
            }

            $cust_name = $conn->real_escape_string($customers[0]);
            $price = doubleval($prices[0]);
            $row_remark = $conn->real_escape_string($remarks[0]);

            if (!empty($cust_name) && $price > 0) {
                $sql_update = "UPDATE `receipts` SET `q_date` = '$build_date', `cust_name` = '$cust_name', `q_price` = '$price', `remark` = '$row_remark', `total` = '$price', `box_id` = $selected_box_id WHERE `qid` = $editing_id";
                if (!$conn->query($sql_update)) {
                    throw new Exception("فشل تحديث سند القبض رقم #" . $editing_id);
                }

                $sql_update_cust = "UPDATE `customers` SET `cust_madeen` = `cust_madeen` - $price WHERE `cust_name` = '$cust_name'";
                if (!$conn->query($sql_update_cust)) {
                    throw new Exception("فشل تحديث مديونية العميل: " . $cust_name);
                }
                update_box_balance($conn, $selected_box_id, $price, 'addition', "سند قبض رقم #$editing_id (معدل) - عميل: $cust_name", $build_date);
                if (!post_journal_entry($conn, 'receipt', $editing_id, 'الصندوق - ' . $box_name, 'الذمم المدينة - ' . $cust_name, $price, "تعديل تحصيل دفعة بسند قبض رقم #$editing_id - $row_remark", $_SESSION['SESS_FIRST_NAME'], $selected_box_id)) {
                    throw new Exception("فشل قيد اليومية للسند رقم: " . $editing_id);
                }
            }
        } else {
            $count = count($customers);
            for ($i = 0; $i < $count; $i++) {
                $cust_name = $conn->real_escape_string($customers[$i]);
                $price = doubleval($prices[$i]);
                $row_remark = $conn->real_escape_string($remarks[$i]);
                
                if (!empty($cust_name) && $price > 0) {
                    $sql_service = "INSERT INTO `receipts`(`q_date`, `cust_name`, `q_price`, `remark`, `total`, `s`, `box_id`) 
                                    VALUES ('$build_date', '$cust_name', '$price', '$row_remark', '$price', 0, $selected_box_id)";
                    if (!$conn->query($sql_service)) {
                        throw new Exception("فشل إدراج سند القبض للعميل: " . $cust_name);
                    }
                    $qid = $conn->insert_id;
                    
                    $sql_update_cust = "UPDATE `customers` SET `cust_madeen` = `cust_madeen` - $price WHERE `cust_name` = '$cust_name'";
                    if (!$conn->query($sql_update_cust)) {
                        throw new Exception("فشل تحديث مديونية العميل: " . $cust_name);
                    }
                    
                    update_box_balance($conn, $selected_box_id, $price, 'addition', "سند قبض رقم #$qid - عميل: $cust_name", $build_date);
                    
                    if (!post_journal_entry($conn, 'receipt', $qid, 'الصندوق - ' . $box_name, 'الذمم المدينة - ' . $cust_name, $price, "تحصيل دفعة بسند قبض رقم #$qid - $row_remark", $_SESSION['SESS_FIRST_NAME'], $selected_box_id)) {
                        throw new Exception("فشل قيد اليومية للسند رقم: " . $qid);
                    }

                    $res_cust_phone = $conn->query("SELECT phone FROM customers WHERE cust_name = '$cust_name' LIMIT 1");
                    if ($res_cust_phone && $res_cust_phone->num_rows > 0) {
                        $cust_phone = $res_cust_phone->fetch_assoc()['phone'];
                        if (!empty($cust_phone)) {
                            require_once($dir_prefix . 'app/Services/WhatsAppService.php');
                            $msg = "شريكنا العزيز، تم استلام دفعة مالية منكم بمبلغ: " . number_format($price, 2) . " ر.ي. وتم تسجيل سند قبض رقم #{$qid}. شكراً لكم.";
                            \AQNEX\Services\WhatsAppService::sendNotification($global_settings, $cust_phone, $msg);
                        }
                    }
                }
            }
        }
        $conn->commit();
    } catch (Exception $e) {
        $conn->rollback();
        echo "<script>alert('خطأ أثناء الحفظ: " . addslashes($e->getMessage()) . "');</script>";
    }

    echo "<script>window.location='index.php';</script>";
    exit;
}
?>
<title>تسجيل مقبوضات جديدة - تكنولوجيا فون</title>

<div class="card-flat">
    <!-- AQNEX System Window Header Bar -->
    <div class="aqnex-window-header no-print">
        <div>
            <i class="bi bi-journal-check text-success ml-1"></i>
            <span>أنظمة الحسابات - المقبوضات والمدفوعات - سند قبض جديد</span>
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
            <button type="button" class="tool-btn btn-delete" title="حذف وتصفية السند الحالي" onclick="if(confirm('هل أنت متأكد من رغبتك في تصفية السند؟')) window.location.href='create.php';">
                <i class="bi bi-trash-fill"></i>
            </button>

            <!-- 📖 القيود المحاسبية للسند (F8) -->
            <button type="button" class="tool-btn" title="عرض القيود المحاسبية الآلية للسند (F8)" onclick="alert('تتم إضافة القيود المحاسبية المزدوجة تلقائياً في الدفتر فور حفظ السند.');" style="color: #7c3aed; border-color: #ddd6fe;">
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

                    <div class="col-md-3">
                        <div class="aqnex-form-group">
                            <label class="aqnex-label">نوع الطرف:</label>
                            <select id="mainPartyType" class="aqnex-select">
                                <option value="customer" selected>عميل (ذمم مدينة)</option>
                                <option value="supplier">مورد (ذمم دائنة)</option>
                            </select>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="aqnex-form-group">
                            <label class="aqnex-label" style="width: 130px;">اختيار العميل / المورد:</label>
                            <select id="mainPartySelect" class="aqnex-select">
                                <option value="">--اختر الحساب--</option>
                                <?php
                                $sql_cust = "SELECT cust_name, cust_madeen FROM customers WHERE d_s = 0 ORDER BY cust_id DESC";
                                $res_cust = $conn->query($sql_cust);
                                if ($res_cust) {
                                    while($row = $res_cust->fetch_assoc()) {
                                        $sel_cp = ($editing_receipt && $editing_receipt['cust_name'] === $row['cust_name']) ? 'selected' : '';
                                        echo "<option value='".htmlspecialchars($row['cust_name'])."' $sel_cp>".htmlspecialchars($row['cust_name'])." (المديونية: ".number_format(floatval($row['cust_madeen']), 2).")</option>";
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
    
    // التغيير التلقائي للعميل من الترويسة ينعكس فوراً في الجدول دون الحاجة للاختيار ثانية
    const mainPartySelect = document.getElementById("mainPartySelect");
    if (mainPartySelect) {
        mainPartySelect.addEventListener("change", function() {
            const selectedParty = this.value;
            if (!selectedParty) return;

            document.querySelectorAll("#itemsContainer .select-customer").forEach(selectEl => {
                selectEl.value = selectedParty;
                if (selectEl.value !== selectedParty) {
                    const opt = document.createElement("option");
                    opt.value = selectedParty;
                    opt.text = selectedParty;
                    opt.selected = true;
                    selectEl.add(opt);
                }
            });
        });
    }
    
    // إضافة صف جديد
    addItemBtn.addEventListener("click", function() {
        const newRow = rowTemplate.cloneNode(true);
        const sel = newRow.querySelector("select");
        const selectedParty = mainPartySelect ? mainPartySelect.value : "";
        sel.value = selectedParty;
        if (selectedParty && sel.value !== selectedParty) {
            const opt = document.createElement("option");
            opt.value = selectedParty;
            opt.text = selectedParty;
            opt.selected = true;
            sel.add(opt);
        }
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

<?php
require_once($dir_prefix . 'includes/footer.php');
?>
