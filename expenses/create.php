<?php
$dir_prefix = '../';
$module = 'expenses';
require_once($dir_prefix . 'includes/header.php');

check_permission(['admin', 'cashier']);

// معالجة بيانات المساعد الذكي AI Prefill للمصروفات
$prefill_service = '';
$prefill_amount = '';
$prefill_remark = '';
if (isset($_GET['ai_prefill'])) {
    $prefill_data = json_decode(base64_decode($_GET['ai_prefill']), true);
    if ($prefill_data) {
        $prefill_service = $prefill_data['expense_type'] ?? $prefill_data['service'] ?? '';
        $prefill_amount = $prefill_data['amount'] ?? '';
        $prefill_remark = $prefill_data['remark'] ?? $prefill_data['notes'] ?? '';
    }
}

$editing_id = isset($_GET['id']) ? intval($_GET['id']) : (isset($_GET['sid']) ? intval($_GET['sid']) : 0);
$editing_expense = null;

if ($editing_id > 0) {
    $res_edit = $conn->query("SELECT * FROM treasury_expenses WHERE sid = $editing_id LIMIT 1");
    if ($res_edit && $res_edit->num_rows > 0) {
        $editing_expense = $res_edit->fetch_assoc();
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
    $services = $_POST['select_services'];
    $prices = $_POST['unit_price'];
    $remarks = $_POST['t'];
    $selected_box_id = isset($_POST['box_id']) ? intval($_POST['box_id']) : get_user_box_id($conn, $active_user_id);
    $box_name = get_box_name($conn, $selected_box_id);

    if ($editing_id > 0) {
        $old_res = $conn->query("SELECT * FROM treasury_expenses WHERE sid = $editing_id LIMIT 1");
        if ($old_res && $old_row = $old_res->fetch_assoc()) {
            $old_price = doubleval($old_row['sprice']);
            $old_box_id = intval($old_row['box_id']);

            if ($old_price > 0 && $old_box_id > 0) {
                update_box_balance($conn, $old_box_id, $old_price, 'addition', "إلغاء خصم سند صرف رقم #$editing_id للتعديل", date('Y-m-d'));
            }
            $conn->query("DELETE FROM journal_entries WHERE ref_type = 'expense' AND ref_id = $editing_id");
            $conn->query("DELETE FROM accounting_journal WHERE ref_type = 'expense' AND ref_id = $editing_id");
            $conn->query("DELETE FROM expenses WHERE m_date='{$old_row['sdate']}' AND sname='{$old_row['st']}' AND m_price='{$old_price}' LIMIT 1");
        }

        $expense_type = $conn->real_escape_string($services[0]);
        $price = doubleval($prices[0]);
        $row_remark = $conn->real_escape_string($remarks[0]);

        if (!empty($expense_type) && $price > 0) {
            $sql_update = "UPDATE `treasury_expenses` SET `sdate` = '$build_date', `st` = '$expense_type', `sprice` = '$price', `sremark` = '$row_remark', `tot` = '$price', `box_id` = $selected_box_id WHERE `sid` = $editing_id";
            if (!$conn->query($sql_update)) {
                throw new Exception("فشل تحديث سند الصرف رقم #" . $editing_id);
            }

            $sqls = "INSERT INTO `expenses`(`m_date`, `sname`, `m_price`, `remark`, `s`) VALUES ('$build_date', '$expense_type', '$price', '$row_remark', 0)";
            $conn->query($sqls);

            update_box_balance($conn, $selected_box_id, $price, 'discount', "سند صرف رقم #$editing_id (معدل) - بند: $expense_type ($row_remark)", $build_date);
            post_journal_entry($conn, 'expense', $editing_id, 'مصروفات - ' . $expense_type, 'الصندوق - ' . $box_name, $price, "تعديل صرف مبلغ بسند صرف رقم #$editing_id - $row_remark", $_SESSION['SESS_FIRST_NAME'], $selected_box_id);
        }
    } else {
        $count = count($services);
        for ($i = 0; $i < $count; $i++) {
            $expense_type = $conn->real_escape_string($services[$i]);
            $price = doubleval($prices[$i]);
            $row_remark = $conn->real_escape_string($remarks[$i]);
            
            if (!empty($expense_type) && $price > 0) {
                $sql_service = "INSERT INTO `treasury_expenses`(`sdate`, `st`, `sprice`, `sremark`, `tot`, `s`, `box_id`) 
                                VALUES ('$build_date', '$expense_type', '$price', '$row_remark', '$price', 0, $selected_box_id)";
                if ($conn->query($sql_service)) {
                    $sid = $conn->insert_id;
                    
                    $sqls = "INSERT INTO `expenses`(`m_date`, `sname`, `m_price`, `remark`, `s`) 
                             VALUES ('$build_date', '$expense_type', '$price', '$row_remark', 0)";
                    $conn->query($sqls);
                    
                    update_box_balance($conn, $selected_box_id, $price, 'discount', "سند صرف رقم #$sid - بند: $expense_type ($row_remark)", $build_date);
                    
                    post_journal_entry($conn, 'expense', $sid, 'مصروفات - ' . $expense_type, 'الصندوق - ' . $box_name, $price, "صرف مبلغ بسند صرف رقم #$sid - $row_remark", $_SESSION['SESS_FIRST_NAME'], $selected_box_id);
                }
            }
        }
    }

    echo "<script>window.location='index.php';</script>";
    exit;
}

// جلب قوائم العملاء والموردين
$customers_list = [];
$res_c = $conn->query("SELECT cust_name, cust_madeen FROM customers WHERE d_s = 0 ORDER BY cust_id DESC");
if ($res_c) while ($r = $res_c->fetch_assoc()) $customers_list[] = $r;

$suppliers_list = [];
$res_s = $conn->query("SELECT supp_name, supp_daain FROM suppliers WHERE d_s = 0 ORDER BY supp_id DESC");
if ($res_s) while ($r = $res_s->fetch_assoc()) $suppliers_list[] = $r;
?>
<title>تسجيل مصروفات / سند صرف جديد - <?php echo htmlspecialchars($global_settings['store_name'] ?? 'AQNEX'); ?></title>

<div class="card-flat">
    <!-- AQNEX System Window Header Bar -->
    <div class="aqnex-window-header no-print">
        <div>
            <i class="bi bi-journal-minus text-danger ml-1"></i>
            <span>أنظمة الحسابات - المقبوضات والمدفوعات - سند صرف جديد</span>
        </div>
        <div>
            <span class="ml-3">المستخدم: <strong><?php echo htmlspecialchars($_SESSION['SESS_FIRST_NAME'] ?? 'مدير النظام'); ?></strong></span>
            <span>التاريخ: <strong><?php echo date('Y/m/d'); ?></strong></span>
        </div>
    </div>

    <!-- AQNEX Action Toolbar -->
    <!-- Onyx Pro Action Toolbar (Large Icon Buttons with Hover Tooltips) -->
    <div class="aqnex-toolbar no-print">
        <div style="display: flex; align-items: center; gap: 5px;">
            <!-- ➕ جديد (F2) -->
            <button type="button" class="tool-btn btn-new" title="جديد (F2) - فتح سند صرف جديد" onclick="window.open('create.php', '_blank');">
                <i class="bi bi-file-earmark-plus-fill"></i>
            </button>

            <!-- 💾 حفظ السند (F10) -->
            <button type="submit" form="expenseForm" name="btn_save" class="tool-btn btn-save btn-save-action" title="حفظ وإثبات الصرف (F10)">
                <i class="bi bi-floppy-fill"></i>
            </button>

            <!-- ✏️ تعديل السند -->
            <button type="button" class="tool-btn" title="تعديل سند صرف مالي (F6)" onclick="openSearchExpenseModal();">
                <i class="bi bi-pencil-square" style="color: #d97706;"></i>
            </button>

            <!-- 🔍 البحث في المصروفات السابقة (F3) -->
            <button type="button" class="tool-btn btn-search" title="البحث عن سندات صرف سابقة (F3)" onclick="openSearchExpenseModal();">
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
                <strong>تأكيد التعديل:</strong> أنت تشاهد وتعدل الآن سند الصرف رقم <strong>#<?php echo $editing_id; ?></strong> (بتاريخ: <?php echo htmlspecialchars($editing_expense['sdate'] ?? ''); ?> - البند: <?php echo htmlspecialchars($editing_expense['st'] ?? ''); ?>). يمكنك تعديل البيانات ثم الضغط على <strong>حفظ (F10)</strong> لتحديث السند.
                <a href="create.php" class="btn btn-xs btn-outline-danger font-weight-bold mr-3">إلغاء التعديل والدخول لإنشاء سند جديد</a>
            </div>
        <?php endif; ?>

        <form method="POST" id="expenseForm">
            <input type="hidden" name="editing_id" value="<?php echo $editing_id; ?>">
            <!-- الترويسة الرئيسية للسند -->
            <div class="aqnex-form-grid">
                <div class="row">
                    <!-- تاريخ الصرف -->
                    <div class="col-md-2">
                        <div class="aqnex-form-group">
                            <label class="aqnex-label">تاريخ الصرف:</label>
                            <input type="date" name="build_date" class="aqnex-input" value="<?php echo $editing_expense ? htmlspecialchars($editing_expense['sdate']) : date('Y-m-d'); ?>" required>
                        </div>
                    </div>

                    <!-- نوع الطرف -->
                    <div class="col-md-2">
                        <div class="aqnex-form-group">
                            <label class="aqnex-label">نوع الطرف:</label>
                            <select id="mainPartyType" class="aqnex-select" onchange="onExpensePartyTypeChange(this.value)">
                                <option value="other" selected>مصروفات عامة / أخرى</option>
                                <option value="supplier">مورد (سداد مستحقات)</option>
                                <option value="customer">عميل (إرجاع مالي)</option>
                            </select>
                        </div>
                    </div>

                    <!-- اختيار الجهة / الحساب -->
                    <div class="col-md-4">
                        <div class="aqnex-form-group">
                            <label class="aqnex-label">اختيار الجهة / الحساب:</label>

                            <!-- مصروفات عامة (افتراضي) -->
                            <select id="mainPartySelect_other" class="aqnex-select" onchange="syncExpensePartyToRows(this.value)">
                                <option value="">-- اختر بند الصرف --</option>
                                <option value="وجبات غذائية">وجبات غذائية</option>
                                <option value="مصروفات يومية">مصروفات يومية</option>
                                <option value="رواتب">رواتب</option>
                                <option value="اجور">اجور</option>
                                <option value="كهرباء">كهرباء</option>
                                <option value="ماء">ماء</option>
                                <option value="خاصة">خاصة</option>
                                <option value="اخرى">اخرى</option>
                            </select>

                            <!-- قائمة الموردين (مخفية) -->
                            <select id="mainPartySelect_supplier" class="aqnex-select d-none" onchange="syncExpensePartyToRows(this.value)">
                                <option value="">-- اختر مورد --</option>
                                <?php foreach ($suppliers_list as $s): ?>
                                    <option value="<?php echo htmlspecialchars($s['supp_name']); ?>">
                                        <?php echo htmlspecialchars($s['supp_name']); ?> (الذمة: <?php echo number_format(floatval($s['supp_daain']), 2); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>

                            <!-- قائمة العملاء (مخفية) -->
                            <select id="mainPartySelect_customer" class="aqnex-select d-none" onchange="syncExpensePartyToRows(this.value)">
                                <option value="">-- اختر عميل --</option>
                                <?php foreach ($customers_list as $c): ?>
                                    <option value="<?php echo htmlspecialchars($c['cust_name']); ?>">
                                        <?php echo htmlspecialchars($c['cust_name']); ?> (المديونية: <?php echo number_format(floatval($c['cust_madeen']), 2); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <!-- الصندوق المستهدف -->
                    <div class="col-md-4">
                        <div class="aqnex-form-group">
                            <label class="aqnex-label">الصندوق المستهدف:</label>
                            <?php if ($is_admin): ?>
                                <select name="box_id" class="aqnex-select" required>
                                    <?php
                                    $res_b = $conn->query("SELECT box_id, name, mony FROM treasury WHERE is_active = 1 ORDER BY box_id ASC");
                                    if ($res_b) {
                                        while($b = $res_b->fetch_assoc()) {
                                            $sel_bx = $editing_expense ? ($b['box_id'] == $editing_expense['box_id']) : ($b['box_id'] == 1);
                                            echo "<option value='{$b['box_id']}' " . ($sel_bx ? 'selected' : '') . ">" . htmlspecialchars($b['name']) . " (" . number_format($b['mony'], 2) . " ر.ي)</option>";
                                        }
                                    }
                                    ?>
                                </select>
                            <?php else: ?>
                                <?php $user_box_id = get_user_box_id($conn, $active_user_id); ?>
                                <input type="hidden" name="box_id" value="<?php echo $user_box_id; ?>">
                                <input type="text" class="aqnex-input bg-light" readonly value="<?php echo htmlspecialchars(get_box_name($conn, $user_box_id)); ?>">
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- جدول بنود الصرف -->
            <div class="table-responsive mt-2" style="overflow: visible;">
                <table class="aqnex-grid-table" id="expenseTable">
                    <thead>
                        <tr>
                            <th style="width: 35%;">حاجة الصرف / البند المستهدف *</th>
                            <th style="width: 20%;">المبلغ</th>
                            <th style="width: 40%;">ملاحظات / بيان الصرف</th>
                            <th class="no-print" style="width: 5%;">إجراء</th>
                        </tr>
                    </thead>
                    <tbody id="itemsContainer">
                        <!-- صف البداية الافتراضي -->
                        <tr class="item-row">
                            <td>
                                <input type="text" name="select_services[]" class="aqnex-input row-service-input" placeholder="-- اختر بند الصرف --" value="<?php echo $editing_expense ? htmlspecialchars($editing_expense['st']) : ''; ?>" required>
                            </td>
                            <td>
                                <input type="number" step="any" name="unit_price[]" class="aqnex-input price-input text-center" value="<?php echo $editing_expense ? floatval($editing_expense['sprice']) : '0'; ?>" min="1" required>
                            </td>
                            <td>
                                <input type="text" name="t[]" class="aqnex-input" placeholder="اكتب ملاحظة للبيان..." value="<?php echo $editing_expense ? htmlspecialchars($editing_expense['sremark']) : ''; ?>" required>
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
                    <i class="bi bi-plus-circle ml-1"></i> إضافة بند آخر
                </button>
            </div>

            <hr class="my-3">

            <!-- ملخص المصروفات -->
            <div class="row justify-content-end">
                <div class="col-md-4">
                    <table class="aqnex-grid-table">
                        <tr style="background: #fef2f2;">
                            <th class="text-right py-2 px-3" style="color: #dc2626;">إجمالي المصاريف الكلية</th>
                            <td class="text-left px-3">
                                <input type="text" id="grandTotalDisplay" name="tot" class="aqnex-input text-center font-weight-bold bg-transparent border-0 text-danger" readonly value="0" style="font-size: 1.1rem;">
                            </td>
                        </tr>
                    </table>
                </div>
            </div>

            <div class="mt-3 no-print text-left">
                <button type="submit" name="btn_save" class="btn-flat btn-flat-primary btn-lg px-5 btn-save-action" title="حفظ وإثبات الصرف (F10)">
                    <i class="bi bi-check-circle-fill ml-1"></i> حفظ وإثبات الصرف (F10)
                </button>
            </div>
        </form>
    </div>
</div>

<script type="text/javascript">
function onExpensePartyTypeChange(type) {
    document.getElementById('mainPartySelect_other').classList.add('d-none');
    document.getElementById('mainPartySelect_supplier').classList.add('d-none');
    document.getElementById('mainPartySelect_customer').classList.add('d-none');

    if (type === 'supplier') {
        document.getElementById('mainPartySelect_supplier').classList.remove('d-none');
    } else if (type === 'customer') {
        document.getElementById('mainPartySelect_customer').classList.remove('d-none');
    } else {
        document.getElementById('mainPartySelect_other').classList.remove('d-none');
    }
    document.querySelectorAll('.row-service-input').forEach(inp => { inp.value = ''; });
}

function syncExpensePartyToRows(value) {
    if (!value) return;
    document.querySelectorAll('.row-service-input').forEach(inp => {
        inp.value = value;
    });
}

document.addEventListener("DOMContentLoaded", function() {
    const itemsContainer = document.getElementById("itemsContainer");
    const addItemBtn = document.getElementById("addItemBtn");
    const rowTemplate = document.querySelector(".item-row").cloneNode(true);

    function updateGrandTotals() {
        let totalVal = 0;
        document.querySelectorAll(".price-input").forEach(function(input) {
            totalVal += parseFloat(input.value) || 0;
        });
        document.getElementById("grandTotalDisplay").value = totalVal.toFixed(2);
    }

    addItemBtn.addEventListener("click", function() {
        const newRow = rowTemplate.cloneNode(true);
        const partyType = document.getElementById('mainPartyType').value;
        let currentParty = '';
        if (partyType === 'supplier') currentParty = document.getElementById('mainPartySelect_supplier').value;
        else if (partyType === 'customer') currentParty = document.getElementById('mainPartySelect_customer').value;
        else currentParty = document.getElementById('mainPartySelect_other').value;

        newRow.querySelector(".row-service-input").value = currentParty;
        newRow.querySelector(".price-input").value = "0";
        newRow.querySelector("input[name='t[]']").value = "";
        itemsContainer.appendChild(newRow);
    });

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

    itemsContainer.addEventListener("input", function(e) {
        if (e.target.classList.contains("price-input")) {
            updateGrandTotals();
        }
    });

    const targetService = "<?php echo htmlspecialchars($prefill_service); ?>";
    const targetAmount = "<?php echo floatval($prefill_amount); ?>";
    const targetRemark = "<?php echo htmlspecialchars($prefill_remark); ?>";

    if (targetService || targetAmount > 0 || targetRemark) {
        if (targetService) {
            document.querySelector(".row-service-input").value = targetService;
        }
        if (targetAmount > 0) {
            document.querySelector(".price-input").value = targetAmount;
        }
        if (targetRemark) {
            document.querySelector("input[name='t[]']").value = targetRemark;
        }
        updateGrandTotals();
    }
    updateGrandTotals();
});

function openSearchExpenseModal() {
    $('#searchExpenseModal').modal('show');
    setTimeout(() => {
        const inp = document.getElementById('searchExpenseQuery');
        if (inp) { inp.focus(); inp.select(); }
    }, 250);
}

function selectPastExpense(id) {
    window.location.href = 'create.php?id=' + id;
}

function filterPastExpensesList() {
    const q = (document.getElementById('searchExpenseQuery').value || '').toLowerCase();
    const d = document.getElementById('searchExpenseDate').value;
    selectedExpenseModalIndex = 0;
    const visibleRows = [];
    document.querySelectorAll('.past-exp-row').forEach(row => {
        const id = row.getAttribute('data-id').toLowerCase();
        const st = row.getAttribute('data-st').toLowerCase();
        const date = row.getAttribute('data-date');
        let match = (id.includes(q) || st.includes(q));
        if (d && date !== d) match = false;
        row.style.display = match ? '' : 'none';
        if (match) visibleRows.push(row);
    });
    highlightPastExpenseRow(visibleRows, 0);
}

let selectedExpenseModalIndex = 0;

function highlightPastExpenseRow(rows, index) {
    if (!rows) {
        rows = Array.from(document.querySelectorAll('.past-exp-row')).filter(r => r.style.display !== 'none');
    }
    rows.forEach(r => r.classList.remove('table-primary', 'font-weight-bold'));
    if (rows.length > 0) {
        if (index < 0) index = 0;
        if (index >= rows.length) index = rows.length - 1;
        selectedExpenseModalIndex = index;
        rows[selectedExpenseModalIndex].classList.add('table-primary', 'font-weight-bold');
        rows[selectedExpenseModalIndex].scrollIntoView({ block: 'nearest' });
    }
}

function deletePastExpense(id, e) {
    if (e) e.stopPropagation();
    if (confirm(`تأكيد الحذف النهائي:\n\nهل أنت متأكد من رغبتك في حذف سند الصرف رقم #${id}؟\nسيتم إلغاء القيود والتأثير المالي والصناديق نهائياً، ولن يمكن التراجع عن هذا الإجراء.`)) {
        window.location.href = 'delete.php?id=' + id;
    }
}

document.addEventListener('keydown', function(e) {
    const modalOpen = $('#searchExpenseModal').is(':visible');
    if (modalOpen) {
        const visibleRows = Array.from(document.querySelectorAll('.past-exp-row')).filter(r => r.style.display !== 'none');
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            highlightPastExpenseRow(visibleRows, selectedExpenseModalIndex + 1);
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            highlightPastExpenseRow(visibleRows, selectedExpenseModalIndex - 1);
        } else if (e.key === 'Enter') {
            e.preventDefault();
            if (visibleRows[selectedExpenseModalIndex]) {
                const id = visibleRows[selectedExpenseModalIndex].getAttribute('data-id');
                if (id) selectPastExpense(id);
            }
        } else if (e.key === 'Delete') {
            e.preventDefault();
            if (visibleRows[selectedExpenseModalIndex]) {
                const id = visibleRows[selectedExpenseModalIndex].getAttribute('data-id');
                if (id) deletePastExpense(id);
            }
        }
    } else if (e.key === 'F3' || e.key === 'F6') {
        e.preventDefault();
        openSearchExpenseModal();
    }
});
</script>

<!-- مودل البحث عن سندات الصرف والمصروفات السابقة -->
<div class="modal fade" id="searchExpenseModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
        <div class="modal-content rounded-0">
            <div class="modal-header bg-danger text-white py-2">
                <h6 class="modal-title font-weight-bold"><i class="bi bi-search ml-1"></i> البحث عن سند صرف سابق للتعديل / الإنزال</h6>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body p-3">
                <div class="row mb-3">
                    <div class="col-md-7">
                        <input type="text" id="searchExpenseQuery" class="form-control rounded-0" placeholder="ابحث برقم السند أو بند المصروف... (استخدم الأسهم و Enter للإنزال السريع)" oninput="filterPastExpensesList()">
                    </div>
                    <div class="col-md-5">
                        <input type="date" id="searchExpenseDate" class="form-control rounded-0" onchange="filterPastExpensesList()">
                    </div>
                </div>
                <div class="table-responsive" style="max-height: 350px; overflow-y: auto;">
                    <table class="table table-sm table-bordered table-hover text-center mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th># رقم السند</th>
                                <th>التاريخ</th>
                                <th>بند / حاجة الصرف</th>
                                <th>المبلغ</th>
                                <th>الملاحظات</th>
                                <th>إجراء</th>
                            </tr>
                        </thead>
                        <tbody id="pastExpensesTableBody">
                            <?php
                            $res_past_e = $conn->query("SELECT sid, sdate, st, sprice, sremark FROM treasury_expenses ORDER BY sid DESC LIMIT 50");
                            if ($res_past_e && $res_past_e->num_rows > 0) {
                                $first = true;
                                while($e_row = $res_past_e->fetch_assoc()) {
                                    $cls = $first ? 'past-exp-row table-primary font-weight-bold' : 'past-exp-row';
                                    $first = false;
                                    echo "<tr class='$cls' data-id='{$e_row['sid']}' data-st='" . htmlspecialchars($e_row['st']) . "' data-date='{$e_row['sdate']}' style='cursor:pointer;' onclick='selectPastExpense({$e_row['sid']})'>
                                        <td>#{$e_row['sid']}</td>
                                        <td>{$e_row['sdate']}</td>
                                        <td>" . htmlspecialchars($e_row['st']) . "</td>
                                        <td class='font-weight-bold text-danger'>" . number_format($e_row['sprice'], 2) . "</td>
                                        <td>" . htmlspecialchars($e_row['sremark']) . "</td>
                                        <td>
                                            <button type='button' class='btn btn-xs btn-primary px-2' onclick='selectPastExpense({$e_row['sid']})'>تعديل / إنزال <i class='bi bi-arrow-down-square-fill mr-1'></i></button>
                                            <button type='button' class='btn btn-xs btn-outline-danger px-2 ml-1' onclick='deletePastExpense({$e_row['sid']}, event)' title='حذف السند نهائياً'><i class='bi bi-trash-fill'></i> حذف</button>
                                        </td>
                                    </tr>";
                                }
                            } else {
                                echo "<tr><td colspan='6' class='text-muted py-3'>لا توجد سندات صرف مسجلة سابقة.</td></tr>";
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

<?php require_once($dir_prefix . 'includes/footer.php'); ?>
