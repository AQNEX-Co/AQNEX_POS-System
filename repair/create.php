<?php
$dir_prefix = '../';
$module = 'repair';

// معالجة إضافة العميل السريع عبر AJAX قبل تضمين الهيدر لمنع تلوث الـ JSON بالـ HTML
if (isset($_POST['ajax_add_customer'])) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    require_once($dir_prefix . 'includes/connect.php');
    header('Content-Type: application/json; charset=utf-8');
    
    if (!isset($_SESSION['SESS_MEMBER_ID'])) {
        echo json_encode(['status' => 'error', 'message' => 'غير مصرح بالعملية.']);
        exit;
    }
    
    date_default_timezone_set("Asia/Aden");
    $today = date("Y-m-d H:i:s");

    $cust_name = $conn->real_escape_string(trim($_POST['cust_name']));
    $phone = $conn->real_escape_string(trim($_POST['phone']));
    $email = $conn->real_escape_string(trim($_POST['email']));
    $address = $conn->real_escape_string(trim($_POST['address']));
    $credit_limit = doubleval($_POST['credit_limit']);
    $notes = $conn->real_escape_string(trim($_POST['notes']));

    if (empty($cust_name) || empty($phone)) {
        echo json_encode(['status' => 'error', 'message' => 'اسم العميل ورقم الجوال مطلوبان.']);
        exit;
    }

    $chk = $conn->query("SELECT cust_id FROM customers WHERE cust_name = '$cust_name' AND d_s = 0");
    if ($chk && $chk->num_rows > 0) {
        echo json_encode(['status' => 'error', 'message' => 'اسم العميل مسجل بالفعل.']);
        exit;
    }

    $sql = "INSERT INTO customers (cust_name, phone, email, address, credit_limit, notes, sale_date) "
         . "VALUES ('$cust_name', '$phone', '$email', '$address', $credit_limit, '$notes', '$today')";
    if ($conn->query($sql)) {
        $new_id = $conn->insert_id;
        echo json_encode(['status' => 'success', 'id' => $new_id, 'name' => $cust_name]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'خطأ أثناء الإضافة: ' . $conn->error]);
    }
    exit;
}

require_once($dir_prefix . 'includes/header.php');
@include_once($dir_prefix . 'includes/modules.php');

// التحقق من صلاحية الصيانة أو الأدمن
check_permission(['admin', 'cashier']);

// التحقق من تفعيل الموديول
if (!is_module_enabled('repair_service')) {
    echo '
    <div class="card-flat">
        <div class="card-body text-center py-5">
            <h4 class="text-danger mb-3">' . get_icon('briefcase', 'ml-2') . 'موديول الصيانة غير مفعل</h4>
            <p class="text-muted">يرجى تفعيل موديول إدارة الصيانة والأجهزة من إعدادات النظام لتتمكن من استخدام هذه الشاشة.</p>
            ' . (($_SESSION['SESS_LAST_NAME'] === 'admin') ? '<a href="../settings/modules.php" class="btn btn-primary rounded-0 px-4">لوحة إدارة الموديولات</a>' : '') . '
        </div>
    </div>';
    require_once($dir_prefix . 'includes/footer.php');
    exit;
}

$error = '';
$success = '';

$selected_customer_id = '';
$selected_technician_id = '';
$selected_issue_type = '';
$custom_issue_type_value = '';
$device_name_value = '';
$imei_value = '';
$expected_delivery_date_value = date('Y-m-d', strtotime('+3 days'));
$estimated_cost_value = '0.00';
$problem_description_value = '';

// معالجة حفظ التذكرة
if (isset($_POST['btn_save_ticket'])) {
    $selected_customer_id = $_POST['customer_id'] ?? '';
    $selected_technician_id = $_POST['technician_id'] ?? '';
    $selected_issue_type = $_POST['issue_type'] ?? '';
    $custom_issue_type_value = $_POST['custom_issue_type'] ?? '';
    $device_name_value = trim($_POST['device_name'] ?? '');
    $imei_value = trim($_POST['imei'] ?? '');
    $expected_delivery_date_value = !empty($_POST['expected_delivery_date']) ? date('Y-m-d', strtotime($_POST['expected_delivery_date'])) : date('Y-m-d', strtotime('+3 days'));
    $estimated_cost_value = $_POST['estimated_cost'] ?? '0.00';
    $problem_description_value = trim($_POST['problem_description'] ?? '');

    $customer_id = intval($selected_customer_id);
    $device_name = $conn->real_escape_string($device_name_value);
    $imei = $conn->real_escape_string($imei_value);
    $issue_type = $conn->real_escape_string(trim($selected_issue_type));
    $custom_issue_type = $conn->real_escape_string(trim($custom_issue_type_value));
    $expected_delivery_date = !empty($expected_delivery_date_value) ? date('Y-m-d', strtotime($expected_delivery_date_value)) : null;
    $problem_description = $conn->real_escape_string($problem_description_value);
    $estimated_cost = doubleval($estimated_cost_value);
    $technician_id = intval($selected_technician_id);

    if ($issue_type === 'other') {
        if (!empty($custom_issue_type)) {
            $issue_type = $custom_issue_type;
        } else {
            $issue_type = '';
        }
    }

    if ($customer_id <= 0) {
        $error = 'العميل مطلوب.';
    } elseif ($technician_id <= 0) {
        $error = 'الفني المسؤول مطلوب.';
    } elseif (empty($device_name)) {
        $error = 'نوع الجهاز مطلوب.';
    } elseif (empty($issue_type)) {
        $error = 'نوع العطل مطلوب.';
    } elseif (empty($expected_delivery_date)) {
        $error = 'تاريخ التسليم المتوقع مطلوب.';
    } else {
        // توليد رقم التذكرة التلقائي الفريد REP-YYYYMMDD-XXXX
        $today = date('Y-m-d');
        $prefix = "REP-" . date('Ymd') . "-";
        
        $res_count = $conn->query("SELECT COUNT(*) as cnt FROM repair_tickets WHERE DATE(received_date) = '$today'");
        $count_row = $res_count->fetch_assoc();
        $next_num = str_pad($count_row['cnt'] + 1, 4, '0', STR_PAD_LEFT);
        $ticket_number = $prefix . $next_num;

        if (!empty($issue_type)) {
            $issue_type_esc = $conn->real_escape_string($issue_type);
            $chk_type = $conn->query("SELECT id FROM repair_issue_types WHERE type_name = '$issue_type_esc' AND d_s = 0 LIMIT 1");
            if ($chk_type && $chk_type->num_rows == 0) {
                $conn->query("INSERT INTO repair_issue_types (type_name, d_s) VALUES ('$issue_type_esc', '0')");
            }
        }

        $stmt = $conn->prepare("
            INSERT INTO `repair_tickets` 
            (`ticket_number`, `customer_id`, `device_name`, `device_type`, `device_brand`, `imei`, `issue_type`, `expected_delivery_date`, `problem_description`, `status`, `estimated_cost`, `technician_id`, `received_date`) 
            VALUES (?, ?, ?, '', '', ?, ?, ?, ?, 'received', ?, ?, NOW())
        ");
        
        if ($stmt) {
            $stmt->bind_param("sisssssdi", $ticket_number, $customer_id, $device_name, $imei, $issue_type, $expected_delivery_date, $problem_description, $estimated_cost, $technician_id);
            if ($stmt->execute()) {
                $new_id = $conn->insert_id;
                echo "<script>window.location='view.php?id=$new_id';</script>";
                exit;
            } else {
                $error = 'فشل فتح تذكرة الصيانة: ' . $conn->error;
            }
            $stmt->close();
        } else {
            $error = 'خطأ في معالجة الاستعلام: ' . $conn->error;
        }
    }
}

// جلب العملاء
$customers = [];
$res_c = $conn->query("SELECT cust_id, cust_name FROM customers WHERE d_s = 0 ORDER BY cust_id DESC");
if ($res_c) {
    while($row = $res_c->fetch_assoc()) {
        $customers[] = $row;
    }
}

// جلب الموظفين/الفنيين
$technicians = [];
$res_t = $conn->query("SELECT userid, full_name, username, position FROM users ORDER BY userid ASC");
if ($res_t) {
    while($row = $res_t->fetch_assoc()) {
        $technicians[] = $row;
    }
}

// جلب قائمة أنواع الأعطال
$issue_types = [];
$res_issue = $conn->query("SELECT type_name FROM repair_issue_types WHERE d_s = 0 ORDER BY id ASC");
if ($res_issue) {
    while($row = $res_issue->fetch_assoc()) {
        $issue_types[] = $row['type_name'];
    }
}
?>

<title>فتح تذكرة صيانة جديدة - تكنولوجيا فون</title>

<div class="card-flat">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5><?php echo get_icon('briefcase', 'ml-2 text-primary'); ?> فتح تذكرة صيانة واستلام جهاز جديدة</h5>
        <a href="index.php" class="btn-flat btn-flat-secondary btn-sm text-decoration-none">
            <?php echo get_icon('logout', 'ml-1'); ?> عودة للقائمة
        </a>
    </div>

    <div class="card-body">
        <?php if (!empty($error)): ?>
            <div class="alert alert-danger rounded-0 mb-4"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="row">
                <!-- العميل والضمان -->
                <div class="col-md-6 form-group mb-3">
                    <label class="font-weight-bold text-secondary">العميل *</label>
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span>اختر العميل صاحب الجهاز</span>
                        <a href="javascript:void(0)" class="small font-weight-bold text-primary text-decoration-none" data-toggle="modal" data-target="#quickAddCustomerModal">
                            <i class="fa fa-plus-circle ml-1"></i> عميل جديد
                        </a>
                    </div>
                    <select name="customer_id" class="form-control rounded-0" required>
                        <option value="">-- اختر العميل صاحب الجهاز --</option>
                        <?php foreach($customers as $c): ?>
                            <option value="<?php echo $c['cust_id']; ?>" <?php echo ($selected_customer_id == $c['cust_id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['cust_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-muted">يمكنك إضافة عميل جديد مباشرة من هنا دون مغادرة الشاشة.</small>
                </div>

                <!-- الفني المسؤول -->
                <div class="col-md-6 form-group mb-3">
                    <label class="font-weight-bold text-secondary">الفني المختص بفحص الجهاز *</label>
                    <select name="technician_id" class="form-control rounded-0" required>
                        <option value="">-- اختر الفني المسؤول --</option>
                        <?php foreach($technicians as $t): ?>
                            <option value="<?php echo $t['userid']; ?>" <?php echo ($selected_technician_id == $t['userid']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars(!empty($t['full_name']) ? $t['full_name'] : $t['username']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- تفاصيل الجهاز -->
                <div class="col-md-6 form-group mb-3">
                    <label class="font-weight-bold text-secondary">نوع الجهاز *</label>
                    <input type="text" name="device_name" class="form-control rounded-0" placeholder="مثال: iPhone 13 Pro Max أو سامسونج جالاكسي A14" value="<?php echo htmlspecialchars($device_name_value); ?>" required>
                </div>
                <div class="col-md-3 form-group mb-3">
                    <label class="font-weight-bold text-secondary">الرقم التسلسلي / IMEI (إن وجد)</label>
                    <input type="text" name="imei" class="form-control rounded-0" placeholder="رقم IMEI المكون من 15 خانة أو Serial Number" value="<?php echo htmlspecialchars($imei_value); ?>">
                </div>
                <div class="col-md-3 form-group mb-3">
                    <label class="font-weight-bold text-secondary">تاريخ التسليم المتوقع *</label>
                    <input type="date" name="expected_delivery_date" class="form-control rounded-0" value="<?php echo htmlspecialchars($expected_delivery_date_value); ?>" required>
                </div>

                <div class="col-md-6 form-group mb-3">
                    <label class="font-weight-bold text-secondary">نوع العطل *</label>
                    <select name="issue_type" id="issueTypeSelect" class="form-control rounded-0" required>
                        <option value="">-- اختر نوع العطل --</option>
                        <?php foreach($issue_types as $type): ?>
                            <option value="<?php echo htmlspecialchars($type); ?>" <?php echo ($selected_issue_type === $type) ? 'selected' : ''; ?>><?php echo htmlspecialchars($type); ?></option>
                        <?php endforeach; ?>
                        <option value="other" <?php echo ($selected_issue_type === 'other') ? 'selected' : ''; ?>>أخرى</option>
                    </select>
                </div>
                <div class="col-md-6 form-group mb-3 <?php echo ($selected_issue_type === 'other') ? '' : 'd-none'; ?>" id="customIssueTypeWrapper">
                    <label class="font-weight-bold text-secondary">حدد نوع العطل أو اكتب وصفاً موجزاً</label>
                    <input type="text" name="custom_issue_type" id="customIssueType" class="form-control rounded-0" placeholder="مثال: مشكلة في الشحن، صوت غير طبيعي، إعادة تشغيل تلقائي" value="<?php echo htmlspecialchars($custom_issue_type_value); ?>">
                </div>

                <!-- التكاليف والمشكلة -->
                <div class="col-md-4 form-group mb-3">
                    <label class="font-weight-bold text-secondary">التكلفة التقديرية للصيانة *</label>
                    <div class="input-group">
                        <input type="number" step="any" name="estimated_cost" class="form-control rounded-0" value="<?php echo htmlspecialchars($estimated_cost_value); ?>" min="0" required>
                        <div class="input-group-append">
                            <span class="input-group-text rounded-0">ر.ي</span>
                        </div>
                    </div>
                </div>

                <div class="col-md-12 form-group mb-3">
                    <label class="font-weight-bold text-secondary">توصيف المشكلة والأعطال الظاهرة *</label>
                    <textarea name="problem_description" class="form-control rounded-0" rows="4" placeholder="اكتب شكوى العميل بالتفصيل (مثال: الشاشة مكسورة، الجهاز لا يشحن، عطل في الكاميرا الخلفية...)" required><?php echo htmlspecialchars($problem_description_value); ?></textarea>
                </div>
            </div>

            <div class="text-left mt-4 border-top pt-3">
                <button type="submit" name="btn_save_ticket" class="btn-flat btn-flat-success btn-lg px-5">
                    <?php echo get_icon('check', 'ml-1'); ?> فتح التذكرة وطباعة إيصال الاستلام
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal إضافة عميل جديد سريعاً -->
<div class="modal fade" id="quickAddCustomerModal" tabindex="-1" role="dialog" aria-labelledby="quickAddCustomerModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content rounded-0">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title font-weight-bold" id="quickAddCustomerModalLabel">
                    <i class="fa fa-user-plus ml-2"></i> إضافة عميل جديد سريع
                </h5>
                <button type="button" class="close ml-0 text-white" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body text-right" dir="rtl">
                <div class="alert alert-danger d-none" id="quickAddCustomerError"></div>
                <form id="quickAddCustomerForm" autocomplete="off">
                    <div class="form-group mb-3">
                        <label class="font-weight-bold text-secondary">اسم العميل <span class="text-danger">*</span></label>
                        <input type="text" class="form-control rounded-0" name="cust_name" placeholder="أدخل اسم العميل بالكامل" required>
                    </div>
                    <div class="form-group mb-3">
                        <label class="font-weight-bold text-secondary">رقم الجوال <span class="text-danger">*</span></label>
                        <input type="text" class="form-control rounded-0" name="phone" placeholder="أدخل رقم الجوال" required>
                    </div>
                    <div class="form-group mb-3">
                        <label class="font-weight-bold text-secondary">البريد الإلكتروني</label>
                        <input type="email" class="form-control rounded-0" name="email" placeholder="email@example.com">
                    </div>
                    <div class="form-group mb-3">
                        <label class="font-weight-bold text-secondary">حد الائتمان الأقصى للآجل (ر.ي)</label>
                        <input type="number" step="any" class="form-control rounded-0" name="credit_limit" value="0.00">
                    </div>
                    <div class="form-group mb-3">
                        <label class="font-weight-bold text-secondary">العنوان السكني / العمل</label>
                        <input type="text" class="form-control rounded-0" name="address" placeholder="المحافظة - المدينة - الشارع">
                    </div>
                    <div class="form-group mb-3">
                        <label class="font-weight-bold text-secondary">ملاحظات إضافية</label>
                        <textarea class="form-control rounded-0" name="notes" rows="2" placeholder="أدخل أي ملاحظات..."></textarea>
                    </div>
                    <input type="hidden" name="ajax_add_customer" value="1">
                    <hr class="my-3">
                    <div class="text-left">
                        <button type="submit" class="btn btn-success rounded-0 font-weight-bold px-4">
                            <i class="fa fa-plus ml-1"></i> حفظ العميل
                        </button>
                        <button type="button" class="btn btn-secondary rounded-0 mr-2" data-dismiss="modal">إلغاء</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function() {
    const issueTypeSelect = document.getElementById('issueTypeSelect');
    const customIssueWrapper = document.getElementById('customIssueTypeWrapper');
    const customIssueInput = document.getElementById('customIssueType');

    function updateCustomIssueTypeVisibility(selectedValue) {
        if (selectedValue === 'other') {
            customIssueWrapper.classList.remove('d-none');
            customIssueInput.setAttribute('required', 'required');
        } else {
            customIssueWrapper.classList.add('d-none');
            customIssueInput.removeAttribute('required');
        }
    }

    if (issueTypeSelect) {
        updateCustomIssueTypeVisibility(issueTypeSelect.value);
        issueTypeSelect.addEventListener('change', function() {
            updateCustomIssueTypeVisibility(this.value);
            if (this.value !== 'other') {
                customIssueInput.value = '';
            }
        });
    }

    const form = document.getElementById("quickAddCustomerForm");
    const errorDiv = document.getElementById("quickAddCustomerError");
    if (form) {
        form.addEventListener("submit", function(e) {
            e.preventDefault();
            errorDiv.classList.add("d-none");
            errorDiv.textContent = '';

            const formData = new FormData(form);
            fetch("create.php", {
                method: "POST",
                body: formData,
                credentials: 'same-origin'
            })
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                    alert("تم حفظ العميل بنجاح واختياره تلقائياً!");
                    const customerSelect = document.querySelector('select[name="customer_id"]');
                    if (customerSelect) {
                        if (typeof $ !== 'undefined' && $(customerSelect).data('select2')) {
                            var newOption = new Option(data.name, data.id, true, true);
                            $(customerSelect).append(newOption).trigger('change');
                        } else {
                            const opt = document.createElement('option');
                            opt.value = data.id;
                            opt.textContent = data.name;
                            opt.selected = true;
                            customerSelect.appendChild(opt);
                            customerSelect.value = data.id;
                        }
                    }
                    // إغلاق المودال بطريقة آمنة لتجنب التعليق
                    const modalEl = document.getElementById('quickAddCustomerModal');
                    if (modalEl) {
                        if (typeof $ !== 'undefined' && typeof $.fn.modal === 'function') {
                            $(modalEl).modal('hide');
                        }
                        modalEl.classList.remove('show');
                        modalEl.setAttribute('aria-hidden', 'true');
                        modalEl.style.display = 'none';
                        const backdrop = document.querySelector('.modal-backdrop');
                        if (backdrop) backdrop.remove();
                        document.body.classList.remove('modal-open');
                        document.body.style.paddingRight = '';
                    }
                    form.reset();
                } else {
                    errorDiv.textContent = data.message || 'حدث خطأ أثناء الإضافة.';
                    errorDiv.classList.remove('d-none');
                }
            })
            .catch(err => {
                errorDiv.textContent = 'تعذر الاتصال بالخادم. حاول مرة أخرى.';
                errorDiv.classList.remove('d-none');
            });
        });
    }
});
</script>

<?php require_once($dir_prefix . 'includes/footer.php'); ?>
