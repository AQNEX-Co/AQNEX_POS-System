<?php
$dir_prefix = '../';
$module = 'settings';
require_once($dir_prefix . 'includes/header.php');
check_permission(['admin']);

$error = '';
$success = '';

// إضافة طابعة جديدة
if (isset($_POST['btn_add_printer'])) {
    $printer_name = $conn->real_escape_string(trim($_POST['printer_name']));
    $printer_type = $conn->real_escape_string($_POST['printer_type']);
    $connection_type = $conn->real_escape_string($_POST['connection_type']);
    $ip_address = $conn->real_escape_string(trim($_POST['ip_address']));
    $port = intval($_POST['port']);
    $is_default = isset($_POST['is_default']) ? 1 : 0;

    if (empty($printer_name)) {
        $error = 'اسم الطابعة حقل إجباري.';
    } else {
        if ($is_default === 1) {
            $conn->query("UPDATE `printer_settings` SET `is_default` = 0");
        }
        
        $stmt = $conn->prepare("INSERT INTO `printer_settings` (`printer_name`, `printer_type`, `connection_type`, `ip_address`, `port`, `is_default`, `d_s`) VALUES (?, ?, ?, ?, ?, ?, '0')");
        $stmt->bind_param("ssssii", $printer_name, $printer_type, $connection_type, $ip_address, $port, $is_default);
        if ($stmt->execute()) {
            $success = 'تمت إضافة الطابعة بنجاح.';
        } else {
            $error = 'فشل إضافة الطابعة: ' . $conn->error;
        }
        $stmt->close();
    }
}

// تعديل طابعة
if (isset($_POST['btn_edit_printer'])) {
    $printer_id = intval($_POST['printer_id']);
    $printer_name = $conn->real_escape_string(trim($_POST['printer_name']));
    $printer_type = $conn->real_escape_string($_POST['printer_type']);
    $connection_type = $conn->real_escape_string($_POST['connection_type']);
    $ip_address = $conn->real_escape_string(trim($_POST['ip_address']));
    $port = intval($_POST['port']);
    $is_default = isset($_POST['is_default']) ? 1 : 0;

    if (empty($printer_name)) {
        $error = 'اسم الطابعة حقل إجباري.';
    } else {
        if ($is_default === 1) {
            $conn->query("UPDATE `printer_settings` SET `is_default` = 0");
        }
        
        $stmt = $conn->prepare("UPDATE `printer_settings` SET `printer_name` = ?, `printer_type` = ?, `connection_type` = ?, `ip_address` = ?, `port` = ?, `is_default` = ? WHERE `id` = ?");
        $stmt->bind_param("ssssiii", $printer_name, $printer_type, $connection_type, $ip_address, $port, $is_default, $printer_id);
        if ($stmt->execute()) {
            $success = 'تم تحديث بيانات الطابعة بنجاح.';
        } else {
            $error = 'فشل تحديث بيانات الطابعة: ' . $conn->error;
        }
        $stmt->close();
    }
}

// حذف طابعة (Soft Delete)
if (isset($_GET['del_printer'])) {
    $printer_id = intval($_GET['del_printer']);
    $conn->query("UPDATE `printer_settings` SET `d_s` = '1' WHERE `id` = $printer_id");
    $success = 'تم حذف الطابعة بنجاح.';
}

// تعيين كافتراضية
if (isset($_GET['set_default'])) {
    $printer_id = intval($_GET['set_default']);
    $conn->query("UPDATE `printer_settings` SET `is_default` = 0");
    $conn->query("UPDATE `printer_settings` SET `is_default` = 1 WHERE `id` = $printer_id");
    $success = 'تم تعيين الطابعة كافتراضية للنظام.';
}

// جلب الطابعات
$printers_list = [];
$res_p = $conn->query("SELECT * FROM `printer_settings` WHERE `d_s` = '0' ORDER BY `id` DESC");
if ($res_p) {
    while ($row = $res_p->fetch_assoc()) {
        $printers_list[] = $row;
    }
}
?>

<title>إدارة وإعدادات الطباعة - AQNEX POS</title>
<link rel="stylesheet" href="<?php echo $prefix; ?>assets/css/settings.css">

<div class="settings-shell">
    <div class="row mb-3 no-print align-items-center">
        <div class="col-md-7">
            <span class="eyebrow">إعدادات النظام وإدارة الموارد</span>
            <h3 class="mb-1">
                <span class="icon-chip"><i class="bi bi-printer"></i></span>
                إعدادات وإدارة طابعات الفواتير
            </h3>
            <p class="text-muted small mb-0">ربط وتكوين طابعات الفواتير الحرارية، وطابعات الباركود، وتعيين الطابعات الافتراضية للنظام.</p>
        </div>
        <div class="col-md-5 text-left">
            <button type="button" class="btn-formal-success" data-toggle="modal" data-target="#addPrinterModal">
                <i class="bi bi-plus-circle ml-1"></i> إضافة طابعة جديدة
            </button>
            <a href="../home.php" class="btn-formal-secondary text-decoration-none mr-1">
                <i class="bi bi-arrow-right-short ml-1"></i> الرئيسية
            </a>
        </div>
    </div>

    <div class="row justify-content-center no-print">
        <div class="col-lg-12">
            
            <?php if (!empty($success)): ?>
                <div class="alert-formal is-success mb-4"><i class="bi bi-check-circle ml-1"></i> <?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>
            <?php if (!empty($error)): ?>
                <div class="alert-formal is-error mb-4"><i class="bi bi-exclamation-triangle ml-1"></i> <?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <!-- Shared Sub-Navigation Menu -->
            <?php 
            $active_tab = 'printers'; 
            require_once 'settings_nav.php'; 
            ?>

            <div class="tab-content tab-content-custom mb-5">
                <div class="tab-pane-inner">
                    <h5 class="section-heading">قائمة الطابعات المربوطة والمفعلة بالنظام</h5>

                    <div class="formal-card">
                        <div class="formal-card-head d-flex justify-content-between align-items-center">
                            <span><i class="bi bi-printer-fill ml-1"></i> الطابعات المسجلة</span>
                            <button type="button" class="btn btn-sm btn-light py-0 font-weight-bold" style="font-size:0.75rem;" data-toggle="modal" data-target="#addPrinterModal">
                                + طابعة جديدة
                            </button>
                        </div>
                        <div class="formal-card-body p-0">
                            <div class="table-responsive">
                                <table class="table-formal">
                                    <thead>
                                        <tr>
                                            <th>اسم الطابعة</th>
                                            <th>نوع الطابعة</th>
                                            <th>طريقة الاتصال</th>
                                            <th>العنوان / المسار</th>
                                            <th>المنفذ</th>
                                            <th>الحالة والافتراضية</th>
                                            <th class="no-print" style="width:140px;">إجراءات</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($printers_list)): ?>
                                            <tr>
                                                <td colspan="7" class="text-center text-muted p-4">لا توجد طابعات مضافة حالياً. أضف طابعة للبدء في استخدام محرك الطباعة الصامتة.</td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($printers_list as $p): ?>
                                                <tr>
                                                    <td class="font-weight-bold text-dark"><?php echo htmlspecialchars($p['printer_name']); ?></td>
                                                    <td>
                                                        <?php 
                                                        switch ($p['printer_type']) {
                                                            case 'thermal_80': echo 'حرارية 80 مم'; break;
                                                            case 'thermal_58': echo 'حرارية 58 مم'; break;
                                                            case 'a4': echo 'A4 عادية / PDF'; break;
                                                            case 'label_zpl': echo 'ملصقات باركود ZPL'; break;
                                                            default: echo htmlspecialchars($p['printer_type']);
                                                        }
                                                        ?>
                                                    </td>
                                                    <td>
                                                        <?php 
                                                        switch ($p['connection_type']) {
                                                            case 'network': echo 'شبكة (IP)'; break;
                                                            case 'usb': echo 'USB / مسار مشترك'; break;
                                                            case 'bluetooth': echo 'بلوتوث'; break;
                                                            default: echo htmlspecialchars($p['connection_type']);
                                                        }
                                                        ?>
                                                    </td>
                                                    <td class="dir-ltr text-right">
                                                        <code><?php echo htmlspecialchars($p['ip_address']); ?></code>
                                                    </td>
                                                    <td><?php echo $p['port']; ?></td>
                                                    <td>
                                                        <?php if ($p['is_default'] == 1): ?>
                                                            <span class="badge-formal is-success">افتراضية للنظام</span>
                                                        <?php else: ?>
                                                            <a href="?set_default=<?php echo $p['id']; ?>" class="btn btn-sm btn-outline-secondary py-0 font-weight-bold" style="font-size:0.75rem;">تعيين كافتراضية</a>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="no-print">
                                                        <button type="button" class="btn btn-sm btn-outline-primary py-1 px-2 font-weight-bold edit-printer-btn" 
                                                                data-id="<?php echo $p['id']; ?>"
                                                                data-name="<?php echo htmlspecialchars($p['printer_name']); ?>"
                                                                data-type="<?php echo htmlspecialchars($p['printer_type']); ?>"
                                                                data-conn="<?php echo htmlspecialchars($p['connection_type']); ?>"
                                                                data-ip="<?php echo htmlspecialchars($p['ip_address']); ?>"
                                                                data-port="<?php echo $p['port']; ?>"
                                                                data-default="<?php echo $p['is_default']; ?>"
                                                                data-toggle="modal" data-target="#editPrinterModal"
                                                                style="font-size:0.75rem;">
                                                            تعديل
                                                        </button>
                                                        <a href="?del_printer=<?php echo $p['id']; ?>" class="btn btn-sm btn-outline-danger py-1 px-2 font-weight-bold mr-1" onclick="return confirm('هل أنت متأكد من حذف هذه الطابعة؟')" style="font-size:0.75rem;">حذف</a>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal إضافة طابعة -->
<div class="modal fade" id="addPrinterModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title" style="font-size:0.95rem; font-weight:700;"><i class="bi bi-printer ml-1"></i> إضافة طابعة جديدة</h5>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form method="POST">
                <div class="modal-body text-right">
                    <div class="form-group mb-3">
                        <label class="field-label">اسم الطابعة التعريفي *</label>
                        <input type="text" name="printer_name" class="form-control rounded-0" placeholder="مثال: طابعة الفواتير الكاشير" required>
                    </div>
                    <div class="form-group mb-3">
                        <label class="field-label">نوع الطابعة وحجم الورق *</label>
                        <select name="printer_type" class="form-control rounded-0" required>
                            <option value="thermal_80">حرارية (80mm)</option>
                            <option value="thermal_58">حرارية (58mm)</option>
                            <option value="a4">طابعة عادية A4 / ليزر</option>
                            <option value="label_zpl">طابعة ملصقات باركود (ZPL/EPL)</option>
                        </select>
                    </div>
                    <div class="form-group mb-3">
                        <label class="field-label">نوع الاتصال بالطابعة *</label>
                        <select name="connection_type" class="form-control rounded-0 connection-type-select" required>
                            <option value="usb">USB محلي / مشاركة شبكة ويندوز</option>
                            <option value="network">شبكة (Ethernet/Wifi/IP)</option>
                        </select>
                    </div>
                    <div class="form-group mb-3 ip-group">
                        <label class="field-label dest-label">مسار مشاركة الطابعة المشترك بنظام ويندوز *</label>
                        <input type="text" name="ip_address" class="form-control rounded-0" placeholder="//localhost/ThermalPrinter" required>
                        <span class="field-hint help-note">للاتصال بالـ USB، يرجى تفعيل المشاركة للطابعة بنظام ويندوز وإدخال المسار المشترك هنا.</span>
                    </div>
                    <div class="form-group mb-3 port-group d-none">
                        <label class="field-label">المنفذ (Port) *</label>
                        <input type="number" name="port" class="form-control rounded-0" value="9100" required>
                    </div>
                    <div class="form-check text-right mb-2 font-weight-bold" style="padding-right: 20px;">
                        <input class="form-check-input" type="checkbox" name="is_default" id="addDefaultCheck">
                        <label class="form-check-label mr-4" for="addDefaultCheck">
                            تعيين هذه الطابعة كافتراضية لجميع العمليات
                        </label>
                    </div>
                </div>
                <div class="modal-footer d-flex justify-content-between">
                    <button type="button" class="btn-formal-secondary" data-dismiss="modal">إلغاء</button>
                    <button type="submit" name="btn_add_printer" class="btn-formal-success">إضافة الطابعة</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal تعديل طابعة -->
<div class="modal fade" id="editPrinterModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title" style="font-size:0.95rem; font-weight:700;"><i class="bi bi-pencil-square ml-1"></i> تعديل بيانات الطابعة</h5>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form method="POST">
                <input type="hidden" name="printer_id" id="edit_printer_id">
                <div class="modal-body text-right">
                    <div class="form-group mb-3">
                        <label class="field-label">اسم الطابعة التعريفي *</label>
                        <input type="text" name="printer_name" id="edit_printer_name" class="form-control rounded-0" required>
                    </div>
                    <div class="form-group mb-3">
                        <label class="field-label">نوع الطابعة وحجم الورق *</label>
                        <select name="printer_type" id="edit_printer_type" class="form-control rounded-0" required>
                            <option value="thermal_80">حرارية (80mm)</option>
                            <option value="thermal_58">حرارية (58mm)</option>
                            <option value="a4">طابعة عادية A4 / ليزر</option>
                            <option value="label_zpl">طابعة ملصقات باركود (ZPL/EPL)</option>
                        </select>
                    </div>
                    <div class="form-group mb-3">
                        <label class="field-label">نوع الاتصال بالطابعة *</label>
                        <select name="connection_type" id="edit_connection_type" class="form-control rounded-0 connection-type-select" required>
                            <option value="usb">USB محلي / مشاركة شبكة ويندوز</option>
                            <option value="network">شبكة (Ethernet/Wifi/IP)</option>
                        </select>
                    </div>
                    <div class="form-group mb-3 ip-group">
                        <label class="field-label dest-label">عنوان IP الطابعة / مسار المشاركة ويندوز *</label>
                        <input type="text" name="ip_address" id="edit_ip_address" class="form-control rounded-0" required>
                        <span class="field-hint help-note"></span>
                    </div>
                    <div class="form-group mb-3 port-group">
                        <label class="field-label">المنفذ (Port) *</label>
                        <input type="number" name="port" id="edit_port" class="form-control rounded-0" required>
                    </div>
                    <div class="form-check text-right mb-2 font-weight-bold" style="padding-right: 20px;">
                        <input class="form-check-input" type="checkbox" name="is_default" id="editDefaultCheck">
                        <label class="form-check-label mr-4" for="editDefaultCheck">
                            تعيين هذه الطابعة كافتراضية لجميع العمليات
                        </label>
                    </div>
                </div>
                <div class="modal-footer d-flex justify-content-between">
                    <button type="button" class="btn-formal-secondary" data-dismiss="modal">إلغاء</button>
                    <button type="submit" name="btn_edit_printer" class="btn-formal-primary">حفظ التغييرات</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function() {
    function handleConnectionFields(modal) {
        const connType = modal.querySelector(".connection-type-select").value;
        const ipGroup = modal.querySelector(".ip-group");
        const portGroup = modal.querySelector(".port-group");
        const destLabel = modal.querySelector(".dest-label");
        const helpNote = modal.querySelector(".help-note");
        const ipInput = modal.querySelector('input[name="ip_address"]');
        
        if (connType === "usb") {
            destLabel.textContent = "مسار مشاركة الطابعة بنظام ويندوز *";
            ipInput.placeholder = "//localhost/ThermalPrinter";
            helpNote.textContent = "للاتصال بالـ USB، يرجى تفعيل المشاركة للطابعة بنظام ويندوز وإدخال المسار المشترك هنا.";
            portGroup.classList.add("d-none");
        } else {
            destLabel.textContent = "عنوان IP الخاص بالطابعة على الشبكة *";
            ipInput.placeholder = "192.168.1.100";
            helpNote.textContent = "أدخل الـ IP الخاص بالطابعة، وتأكد من أن الطابعة والكمبيوتر متصلين على نفس الراوتر.";
            portGroup.classList.remove("d-none");
        }
    }

    const addModal = document.getElementById("addPrinterModal");
    if (addModal) {
        addModal.querySelector(".connection-type-select").addEventListener("change", function() {
            handleConnectionFields(addModal);
        });
        handleConnectionFields(addModal);
    }

    const editModal = document.getElementById("editPrinterModal");
    if (editModal) {
        editModal.querySelector(".connection-type-select").addEventListener("change", function() {
            handleConnectionFields(editModal);
        });

        document.querySelectorAll(".edit-printer-btn").forEach(btn => {
            btn.addEventListener("click", function() {
                const id = this.getAttribute("data-id");
                const name = this.getAttribute("data-name");
                const type = this.getAttribute("data-type");
                const conn = this.getAttribute("data-conn");
                const ip = this.getAttribute("data-ip");
                const port = this.getAttribute("data-port");
                const def = this.getAttribute("data-default");

                document.getElementById("edit_printer_id").value = id;
                document.getElementById("edit_printer_name").value = name;
                document.getElementById("edit_printer_type").value = type;
                document.getElementById("edit_connection_type").value = conn;
                document.getElementById("edit_ip_address").value = ip;
                document.getElementById("edit_port").value = port;
                document.getElementById("editDefaultCheck").checked = (def == 1);
                
                handleConnectionFields(editModal);
            });
        });
    }
});
</script>

<?php require_once($dir_prefix . 'includes/footer.php'); ?>
