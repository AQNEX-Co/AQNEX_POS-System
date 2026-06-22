<?php
$dir_prefix = '../';
$module = 'box';
require_once($dir_prefix . 'includes/header.php');

// يسمح للمدير والكاشير بالدخول، ولكن بصلاحيات مختلفة
check_permission(['admin', 'cashier']);

$active_user_id = intval($_SESSION['SESS_MEMBER_ID']);
$active_user_role = trim($_SESSION['SESS_LAST_NAME']);
$is_admin = ($active_user_role === 'admin' || empty($active_user_role));

$success = '';
$error = '';
$today = date("Y-m-d H:i:s");
$today_date = date("Y-m-d");

// ==========================================
// 1. إضافة صندوق جديد (المدير فقط)
// ==========================================
if ($is_admin && isset($_POST['btn_add_box'])) {
    $box_name = $conn->real_escape_string(trim($_POST['box_name']));
    $user_id_val = !empty($_POST['user_id']) ? intval($_POST['user_id']) : 'NULL';
    $initial_balance = doubleval($_POST['initial_balance']);
    $remark = $conn->real_escape_string(trim($_POST['remark']));

    if (empty($box_name)) {
        $error = 'الرجاء إدخال اسم الصندوق.';
    } else {
        // التحقق من عدم تكرار المستخدم المرتبط بصندوق نشط
        $chk_user = false;
        if ($user_id_val !== 'NULL') {
            $res_chk = $conn->query("SELECT box_id FROM treasury WHERE user_id = $user_id_val AND is_active = 1 LIMIT 1");
            if ($res_chk && $res_chk->num_rows > 0) {
                $chk_user = true;
            }
        }

        if ($chk_user) {
            $error = 'هذا الموظف مرتبط بالفعل بصندوق نشط آخر.';
        } else {
            $sql_ins = "INSERT INTO treasury (name, mony, remark, user_id, is_active) VALUES ('$box_name', $initial_balance, '$remark', $user_id_val, 1)";
            if ($conn->query($sql_ins)) {
                $new_box_id = $conn->insert_id;
                if ($initial_balance > 0) {
                    // تسجيل رصيد افتتاحي
                    $conn->query("INSERT INTO treasury_transactions (mony, statue, remark, datte, box_id) VALUES ($initial_balance, 'addition', 'رصيد افتتاحي عند الإنشاء', '$today_date', $new_box_id)");
                    // قيد يومية
                    post_journal_entry($conn, 'adjustment', $new_box_id, 'الصندوق - ' . $box_name, 'رأس المال / رصيد افتتاحي', $initial_balance, "رصيد افتتاحي لصندوق $box_name", $_SESSION['SESS_FIRST_NAME'], $new_box_id);
                }
                $success = "✓ تم إنشاء صندوق \"$box_name\" بنجاح!";
            } else {
                $error = 'فشل إنشاء الصندوق: ' . $conn->error;
            }
        }
    }
}

// ==========================================
// 2. تعديل صندوق موجود (المدير فقط)
// ==========================================
if ($is_admin && isset($_POST['btn_edit_box'])) {
    $edit_box_id = intval($_POST['edit_box_id']);
    $box_name = $conn->real_escape_string(trim($_POST['box_name']));
    $user_id_val = !empty($_POST['user_id']) ? intval($_POST['user_id']) : 'NULL';
    $remark = $conn->real_escape_string(trim($_POST['remark']));
    $is_active = isset($_POST['is_active']) ? 1 : 0;

    if ($edit_box_id <= 0 || empty($box_name)) {
        $error = 'بيانات التعديل غير مكتملة.';
    } else {
        // منع إلغاء تفعيل الصندوق الرئيسي 1
        if ($edit_box_id === 1) {
            $is_active = 1; 
        }

        // التحقق من عدم تكرار المستخدم
        $chk_user = false;
        if ($user_id_val !== 'NULL') {
            $res_chk = $conn->query("SELECT box_id FROM treasury WHERE user_id = $user_id_val AND is_active = 1 AND box_id != $edit_box_id LIMIT 1");
            if ($res_chk && $res_chk->num_rows > 0) {
                $chk_user = true;
            }
        }

        if ($chk_user) {
            $error = 'هذا الموظف مرتبط بالفعل بصندوق نشط آخر.';
        } else {
            $sql_up = "UPDATE treasury SET name = '$box_name', user_id = $user_id_val, remark = '$remark', is_active = $is_active WHERE box_id = $edit_box_id";
            if ($conn->query($sql_up)) {
                $success = "✓ تم تحديث بيانات الصندوق بنجاح!";
            } else {
                $error = 'فشل تحديث الصندوق: ' . $conn->error;
            }
        }
    }
}

// ==========================================
// 3. حركة يدوية (سحب أو إيداع)
// ==========================================
if (isset($_POST['btn_save'])) {
    $amount = doubleval($_POST['n']);
    $type = $_POST['type']; // addition / discount
    $remark = $conn->real_escape_string(trim($_POST['r']));
    $target_box_id = intval($_POST['target_box_id']);

    // التحقق من الصلاحيات
    if (!$is_admin && $target_box_id !== get_user_box_id($conn, $active_user_id)) {
        $error = 'غير مصرح لك بإجراء عمليات على صناديق أخرى.';
    } elseif ($amount <= 0 || empty($type) || $target_box_id <= 0) {
        $error = 'يرجى ملء جميع الحقول بشكل صحيح.';
    } else {
        $box_name = get_box_name($conn, $target_box_id);
        
        if ($type === 'addition') {
            update_box_balance($conn, $target_box_id, $amount, 'addition', $remark, $today_date);
            post_journal_entry($conn, 'adjustment', $target_box_id, 'الصندوق - ' . $box_name, 'إيرادات أخرى / تسويات الصندوق', $amount, $remark, $_SESSION['SESS_FIRST_NAME'], $target_box_id);
            $success = "✓ تم إيداع المبلغ بنجاح في صندوق $box_name.";
        } else if ($type === 'discount') {
            // التحقق من كفاية الرصيد
            $res_bal = $conn->query("SELECT mony FROM treasury WHERE box_id = $target_box_id");
            $cur_bal = ($res_bal && $r = $res_bal->fetch_assoc()) ? doubleval($r['mony']) : 0;
            
            if ($amount > $cur_bal) {
                $error = 'رصيد الصندوق غير كافٍ لإجراء عملية السحب.';
            } else {
                update_box_balance($conn, $target_box_id, $amount, 'discount', $remark, $today_date);
                post_journal_entry($conn, 'adjustment', $target_box_id, 'مصاريف أخرى / تسويات الصندوق', 'الصندوق - ' . $box_name, $amount, $remark, $_SESSION['SESS_FIRST_NAME'], $target_box_id);
                $success = "✓ تم سحب المبلغ بنجاح من صندوق $box_name.";
            }
        }
    }
}

// ==========================================
// 4. تحويل أموال بين الصناديق (المدير فقط)
// ==========================================
if ($is_admin && isset($_POST['btn_transfer'])) {
    $from_box = intval($_POST['from_box']);
    $to_box = intval($_POST['to_box']);
    $transfer_amount = doubleval($_POST['transfer_amount']);
    $transfer_remark = $conn->real_escape_string(trim($_POST['transfer_remark']));

    if ($from_box <= 0 || $to_box <= 0 || $from_box === $to_box || $transfer_amount <= 0) {
        $error = 'يرجى تحديد صناديق مختلفة ومبلغ تحويل صحيح.';
    } else {
        // التحقق من رصيد الصندوق المصدر
        $res_bal = $conn->query("SELECT mony FROM treasury WHERE box_id = $from_box");
        $from_bal = ($res_bal && $r = $res_bal->fetch_assoc()) ? doubleval($r['mony']) : 0;

        if ($transfer_amount > $from_bal) {
            $error = 'رصيد الصندوق المصدر غير كافٍ لإتمام التحويل.';
        } else {
            $from_name = get_box_name($conn, $from_box);
            $to_name = get_box_name($conn, $to_box);

            // 1. خصم من الصندوق المصدر
            update_box_balance($conn, $from_box, $transfer_amount, 'discount', "تحويل صادر إلى $to_name - $transfer_remark", $today_date);
            // 2. إضافة للصندوق الهدف
            update_box_balance($conn, $to_box, $transfer_amount, 'addition', "تحويل وارد من $from_name - $transfer_remark", $today_date);
            // 3. قيد يومية
            post_journal_entry($conn, 'adjustment', $from_box, 'الصندوق - ' . $to_name, 'الصندوق - ' . $from_name, $transfer_amount, "تحويل مالي بين الصناديق: من $from_name إلى $to_name", $_SESSION['SESS_FIRST_NAME']);

            $success = "✓ تم تحويل مبلغ " . number_format($transfer_amount, 2) . " ر.ي بنجاح من صندوق ($from_name) إلى صندوق ($to_name).";
        }
    }
}

// ==========================================
// جلب قائمة الصناديق المعروضة
// ==========================================
if ($is_admin) {
    // المدير يرى كل الصناديق
    $sql_boxes = "SELECT t.*, u.username FROM treasury t LEFT JOIN users u ON t.user_id = u.userid ORDER BY t.box_id ASC";
} else {
    // الكاشير يرى صندوقه الخاص فقط
    $user_box_id = get_user_box_id($conn, $active_user_id);
    $sql_boxes = "SELECT t.*, u.username FROM treasury t LEFT JOIN users u ON t.user_id = u.userid WHERE t.box_id = $user_box_id";
}
$res_boxes = $conn->query($sql_boxes);
$boxes = [];
if ($res_boxes) {
    while($row = $res_boxes->fetch_assoc()) {
        $boxes[] = $row;
    }
}

// جلب المستخدمين لإقرانهم بالصناديق (المدير فقط)
$users = [];
if ($is_admin) {
    $res_u = $conn->query("SELECT userid, username, position FROM users WHERE position IN ('cashier', 'inventory') ORDER BY userid ASC");
    if ($res_u) {
        while($r = $res_u->fetch_assoc()) $users[] = $r;
    }
}
?>
<title>إدارة الصناديق والحسابات النقدية - AQNEX POS</title>

<div class="row no-print mb-4">
    <div class="col-md-6">
        <h3 class="text-secondary font-weight-bold">
            <i class="fa fa-university ml-2 text-primary"></i> إدارة الصناديق المالية وتسوية النقدية
        </h3>
        <p class="text-muted small mb-0">متابعة أرصدة صناديق المستخدمين، إجراء الإيداع والسحب اليدوي والتحويلات المالية.</p>
    </div>
    <div class="col-md-6 text-left">
        <?php if ($is_admin): ?>
            <button class="btn btn-success btn-sm rounded-0 ml-2" data-toggle="modal" data-target="#addBoxModal">
                <i class="fa fa-plus ml-1"></i> إضافة صندوق جديد
            </button>
            <button class="btn btn-info btn-sm rounded-0 ml-2" data-toggle="modal" data-target="#transferModal">
                <i class="bi bi-arrow-left-right ml-1"></i> تحويل بين الصناديق
            </button>
        <?php endif; ?>
        <a href="../home.php" class="btn btn-secondary btn-sm rounded-0 text-decoration-none">
            <i class="fa fa-arrow-left ml-1"></i> عودة للرئيسية
        </a>
    </div>
</div>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger rounded-0 mb-4"><?php echo $error; ?></div>
<?php endif; ?>
<?php if (!empty($success)): ?>
    <div class="alert alert-success rounded-0 mb-4"><?php echo $success; ?></div>
<?php endif; ?>

<div class="row">
    <!-- قائمة الأرصدة والصناديق -->
    <div class="col-lg-8 mb-4">
        <div class="card-flat">
            <div class="card-header bg-light">
                <h5 class="mb-0"><i class="fa fa-list ml-2"></i> أرصدة وحسابات الصناديق</h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table-flat mb-0">
                        <thead>
                            <tr>
                                <th style="width: 10%;">رقم الصندوق</th>
                                <th>اسم الصندوق</th>
                                <th>الموظف المرتبط</th>
                                <th>الرصيد الحالي (ر.ي)</th>
                                <th style="width: 12%;">الحالة</th>
                                <th style="width: 25%;" class="no-print">إجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($boxes as $box): ?>
                                <tr>
                                    <td class="font-weight-bold text-secondary">#<?php echo $box['box_id']; ?></td>
                                    <td class="font-weight-bold text-dark text-right pr-4"><?php echo htmlspecialchars($box['name']); ?></td>
                                    <td class="text-secondary"><?php echo $box['username'] ? htmlspecialchars($box['username']) : '<span class="text-muted">عام (غير مخصص)</span>'; ?></td>
                                    <td class="font-weight-bold text-primary" style="font-size: 1.1rem;"><?php echo number_format($box['mony'], 2); ?></td>
                                    <td>
                                        <?php if ($box['is_active'] == 1): ?>
                                            <span class="badge badge-success px-2 py-1 font-weight-normal">نشط</span>
                                        <?php else: ?>
                                            <span class="badge badge-secondary px-2 py-1 font-weight-normal">معطل</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="no-print">
                                        <a href="close.php?box_id=<?php echo $box['box_id']; ?>" class="btn btn-success btn-sm rounded-0 py-1 px-2 text-decoration-none ml-1">
                                            <i class="bi bi-safe2 ml-1"></i> إقفال الوردية والترحيل
                                        </a>
                                        <?php if ($is_admin): ?>
                                            <button class="btn btn-primary btn-sm rounded-0 py-1 px-2" onclick='openEditModal(<?php echo json_encode($box); ?>)'>
                                        <?php echo get_icon('edit', 'ml-1'); ?> تعديل
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- لوحة التسوية المالية (سحب وإيداع) -->
    <div class="col-lg-4 mb-4">
        <div class="card-flat">
            <div class="card-header bg-light">
                <h5 class="mb-0"><i class="fa fa-calculator ml-2"></i> التسويات المالية اليدوية</h5>
            </div>
            <div class="card-body">
                <form method="POST" onsubmit="return confirm('تأكيد تنفيذ هذه الحركة المالية اليدوية؟')">
                    <!-- الصندوق المستهدف -->
                    <div class="form-group mb-3">
                        <label class="font-weight-bold text-secondary mb-1">الصندوق المالي:</label>
                        <select name="target_box_id" class="form-control rounded-0" required>
                            <?php if ($is_admin): ?>
                                <?php foreach ($boxes as $box): ?>
                                    <?php if ($box['is_active'] == 1): ?>
                                        <option value="<?php echo $box['box_id']; ?>"><?php echo htmlspecialchars($box['name']); ?> (رصيد: <?php echo number_format($box['mony'], 2); ?> ر.ي)</option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <?php $user_box_id = get_user_box_id($conn, $active_user_id); ?>
                                <option value="<?php echo $user_box_id; ?>"><?php echo get_box_name($conn, $user_box_id); ?></option>
                            <?php endif; ?>
                        </select>
                    </div>

                    <!-- نوع الحركة -->
                    <div class="form-group mb-3 text-center">
                        <label class="font-weight-bold d-block text-right mb-2">نوع الحركة:</label>
                        <div class="custom-control custom-radio custom-control-inline mx-2">
                            <input type="radio" id="type_add" name="type" class="custom-control-input" value="addition" required>
                            <label class="custom-control-label text-success font-weight-bold" for="type_add">إيداع / إضافة</label>
                        </div>
                        <div class="custom-control custom-radio custom-control-inline mx-2">
                            <input type="radio" id="type_sub" name="type" class="custom-control-input" value="discount" required>
                            <label class="custom-control-label text-danger font-weight-bold" for="type_sub">سحب / خصم</label>
                        </div>
                    </div>

                    <!-- المبلغ -->
                    <div class="form-group mb-3">
                        <label class="font-weight-bold text-secondary mb-1">المبلغ (ر.ي):</label>
                        <input type="number" step="any" name="n" class="form-control text-center font-weight-bold" style="font-size: 1.2rem;" placeholder="0.00" required>
                    </div>

                    <!-- البيان والملاحظات -->
                    <div class="form-group mb-4">
                        <label class="font-weight-bold text-secondary mb-1">البيان والملاحظات:</label>
                        <input type="text" name="r" class="form-control rounded-0" placeholder="اكتب سبباً للتسوية المالية..." required>
                    </div>

                    <button type="submit" name="btn_save" class="btn btn-primary btn-block rounded-0 py-2 font-weight-bold">
                        <i class="fa fa-check ml-1"></i> حفظ الحركة وإثباتها
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- ======================================================
Modals (المدير فقط)
====================================================== -->
<?php if ($is_admin): ?>
<!-- إضافة صندوق جديد -->
<div class="modal fade" id="addBoxModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content rounded-0">
            <div class="modal-header">
                <h5 class="modal-title font-weight-bold">إضافة صندوق مالي جديد</h5>
                <button type="button" class="close ml-0" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form method="POST">
                <div class="modal-body text-right">
                    <div class="form-group mb-3">
                        <label class="font-weight-bold text-secondary mb-1">اسم الصندوق *</label>
                        <input type="text" name="box_name" class="form-control rounded-0" placeholder="مثال: صندوق الكاشير أحمد" required>
                    </div>
                    <div class="form-group mb-3">
                        <label class="font-weight-bold text-secondary mb-1">ربط بالموظف (كاشير/أمين مستودع)</label>
                        <select name="user_id" class="form-control rounded-0">
                            <option value="">-- عام (غير مخصص لموظف واحد) --</option>
                            <?php foreach ($users as $u): ?>
                                <option value="<?php echo $u['userid']; ?>"><?php echo htmlspecialchars($u['username']); ?> (<?php echo $u['position'] === 'cashier' ? 'كاشير' : 'أمين مستودع'; ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group mb-3">
                        <label class="font-weight-bold text-secondary mb-1">الرصيد الافتتاحي (ر.ي)</label>
                        <input type="number" step="any" name="initial_balance" class="form-control rounded-0" value="0">
                    </div>
                    <div class="form-group mb-3">
                        <label class="font-weight-bold text-secondary mb-1">ملاحظات / وصف الصندوق</label>
                        <input type="text" name="remark" class="form-control rounded-0" placeholder="صندوق مبيعات الفرع الرئيسي مثلاً...">
                    </div>
                </div>
                <div class="modal-footer justify-content-start">
                    <button type="submit" name="btn_add_box" class="btn btn-success rounded-0">حفظ وإنشاء الصندوق</button>
                    <button type="button" class="btn btn-secondary rounded-0" data-dismiss="modal">إلغاء</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- تعديل صندوق -->
<div class="modal fade" id="editBoxModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content rounded-0">
            <div class="modal-header">
                <h5 class="modal-title font-weight-bold">تعديل بيانات الصندوق المالي</h5>
                <button type="button" class="close ml-0" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form method="POST">
                <input type="hidden" name="edit_box_id" id="edit_box_id">
                <div class="modal-body text-right">
                    <div class="form-group mb-3">
                        <label class="font-weight-bold text-secondary mb-1">اسم الصندوق *</label>
                        <input type="text" name="box_name" id="edit_box_name" class="form-control rounded-0" required>
                    </div>
                    <div class="form-group mb-3">
                        <label class="font-weight-bold text-secondary mb-1">ربط بالموظف</label>
                        <select name="user_id" id="edit_user_id" class="form-control rounded-0">
                            <option value="">-- عام (غير مخصص لموظف واحد) --</option>
                            <?php foreach ($users as $u): ?>
                                <option value="<?php echo $u['userid']; ?>"><?php echo htmlspecialchars($u['username']); ?> (<?php echo $u['position'] === 'cashier' ? 'كاشير' : 'أمين مستودع'; ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group mb-3">
                        <label class="font-weight-bold text-secondary mb-1">ملاحظات</label>
                        <input type="text" name="remark" id="edit_remark" class="form-control rounded-0">
                    </div>
                    <div class="form-group mb-3" id="activeToggleContainer">
                        <div class="custom-control custom-checkbox">
                            <input type="checkbox" class="custom-control-input" id="edit_is_active" name="is_active" checked>
                            <label class="custom-control-label font-weight-bold" for="edit_is_active">الصندوق نشط ومفعّل</label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer justify-content-start">
                    <button type="submit" name="btn_edit_box" class="btn btn-primary rounded-0">حفظ التغييرات</button>
                    <button type="button" class="btn btn-secondary rounded-0" data-dismiss="modal">إلغاء</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- تحويل بين الصناديق -->
<div class="modal fade" id="transferModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content rounded-0">
            <div class="modal-header">
                <h5 class="modal-title font-weight-bold">تحويل أموال بين الصناديق</h5>
                <button type="button" class="close ml-0" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form method="POST" onsubmit="return confirm('تأكيد إتمام عملية التحويل المالي بين الصناديق؟')">
                <div class="modal-body text-right">
                    <div class="form-group mb-3">
                        <label class="font-weight-bold text-secondary mb-1">الصندوق المصدر (الخصم من) *</label>
                        <select name="from_box" class="form-control rounded-0" required>
                            <option value="">-- اختر الصندوق المصدر --</option>
                            <?php foreach ($boxes as $box): ?>
                                <?php if ($box['is_active'] == 1): ?>
                                    <option value="<?php echo $box['box_id']; ?>"><?php echo htmlspecialchars($box['name']); ?> (رصيد: <?php echo number_format($box['mony'], 2); ?> ر.ي)</option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group mb-3">
                        <label class="font-weight-bold text-secondary mb-1">الصندوق الهدف (الإيداع في) *</label>
                        <select name="to_box" class="form-control rounded-0" required>
                            <option value="">-- اختر الصندوق الهدف --</option>
                            <?php foreach ($boxes as $box): ?>
                                <?php if ($box['is_active'] == 1): ?>
                                    <option value="<?php echo $box['box_id']; ?>"><?php echo htmlspecialchars($box['name']); ?> (رصيد: <?php echo number_format($box['mony'], 2); ?> ر.ي)</option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group mb-3">
                        <label class="font-weight-bold text-secondary mb-1">مبلغ التحويل (ر.ي) *</label>
                        <input type="number" step="any" name="transfer_amount" class="form-control rounded-0 text-center font-weight-bold" style="font-size: 1.1rem;" placeholder="0.00" required>
                    </div>
                    <div class="form-group mb-3">
                        <label class="font-weight-bold text-secondary mb-1">بيان التحويل / ملاحظات *</label>
                        <input type="text" name="transfer_remark" class="form-control rounded-0" placeholder="مثال: توريد الإيراد اليومي للصندوق الرئيسي" required>
                    </div>
                </div>
                <div class="modal-footer justify-content-start">
                    <button type="submit" name="btn_transfer" class="btn btn-success rounded-0">إجراء التحويل المالي</button>
                    <button type="button" class="btn btn-secondary rounded-0" data-dismiss="modal">إلغاء</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openEditModal(box) {
    document.getElementById('edit_box_id').value = box.box_id;
    document.getElementById('edit_box_name').value = box.name;
    document.getElementById('edit_user_id').value = box.user_id ? box.user_id : '';
    document.getElementById('edit_remark').value = box.remark;
    document.getElementById('edit_is_active').checked = box.is_active == 1;

    // منع تعطيل الصندوق الرئيسي الأول
    if (parseInt(box.box_id) === 1) {
        document.getElementById('activeToggleContainer').style.display = 'none';
    } else {
        document.getElementById('activeToggleContainer').style.display = 'block';
    }

    $('#editBoxModal').modal('show');
}
</script>
<?php endif; ?>

<?php
require_once($dir_prefix . 'includes/footer.php');
?>
