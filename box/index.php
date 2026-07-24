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
                    $conn->query("INSERT INTO treasury_transactions (mony, statue, remark, datte, box_id) VALUES ($initial_balance, 'addition', 'رصيد افتتاحي عند الإنشاء', '$today_date', $new_box_id)");
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
        if ($edit_box_id === 1) { $is_active = 1; }
        $chk_user = false;
        if ($user_id_val !== 'NULL') {
            $res_chk = $conn->query("SELECT box_id FROM treasury WHERE user_id = $user_id_val AND is_active = 1 AND box_id != $edit_box_id LIMIT 1");
            if ($res_chk && $res_chk->num_rows > 0) { $chk_user = true; }
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

// ترحيل مبيعات الصندوق
if (isset($_POST['btn_quick_transfer']) && isset($_POST['box_id_to_transfer'])) {
    $box_id_val = intval($_POST['box_id_to_transfer']);
    if (!$is_admin && $box_id_val !== get_user_box_id($conn, $active_user_id)) {
        $error = 'غير مصرح لك بترحيل مبيعات صناديق أخرى.';
    } else {
        $user_display = $_SESSION['SESS_FIRST_NAME'];
        $transferred = transfer_sales_to_box($conn, $box_id_val, $user_display);
        if ($transferred !== false && $transferred > 0) {
            $success = "✓ تم ترحيل مبيعات معلقة بمبلغ " . number_format($transferred, 2) . " ر.ي بنجاح إلى الصندوق!";
        } elseif ($transferred === 0.0) {
            $error = "لا توجد مبيعات معلقة للترحيل.";
        } else {
            $error = "حدث خطأ أثناء محاولة ترحيل المبيعات.";
        }
    }
}

// ==========================================
// 3. حركة يدوية (سحب أو إيداع)
// ==========================================
if (isset($_POST['btn_save'])) {
    $amount = doubleval($_POST['n']);
    $type = $_POST['type'];
    $remark = $conn->real_escape_string(trim($_POST['r']));
    $target_box_id = intval($_POST['target_box_id']);

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
        $res_bal = $conn->query("SELECT mony FROM treasury WHERE box_id = $from_box");
        $from_bal = ($res_bal && $r = $res_bal->fetch_assoc()) ? doubleval($r['mony']) : 0;
        if ($transfer_amount > $from_bal) {
            $error = 'رصيد الصندوق المصدر غير كافٍ لإتمام التحويل.';
        } else {
            $from_name = get_box_name($conn, $from_box);
            $to_name = get_box_name($conn, $to_box);
            update_box_balance($conn, $from_box, $transfer_amount, 'discount', "تحويل صادر إلى $to_name - $transfer_remark", $today_date);
            update_box_balance($conn, $to_box, $transfer_amount, 'addition', "تحويل وارد من $from_name - $transfer_remark", $today_date);
            post_journal_entry($conn, 'adjustment', $from_box, 'الصندوق - ' . $to_name, 'الصندوق - ' . $from_name, $transfer_amount, "تحويل مالي بين الصناديق: من $from_name إلى $to_name", $_SESSION['SESS_FIRST_NAME']);
            $success = "✓ تم تحويل مبلغ " . number_format($transfer_amount, 2) . " ر.ي بنجاح من صندوق ($from_name) إلى صندوق ($to_name).";
        }
    }
}

// ==========================================
// جلب قائمة الصناديق
// ==========================================
if ($is_admin) {
    $sql_boxes = "SELECT t.*, u.username FROM treasury t LEFT JOIN users u ON t.user_id = u.userid ORDER BY t.box_id ASC";
} else {
    $user_box_id = get_user_box_id($conn, $active_user_id);
    $sql_boxes = "SELECT t.*, u.username FROM treasury t LEFT JOIN users u ON t.user_id = u.userid WHERE t.box_id = $user_box_id";
}
$res_boxes = $conn->query($sql_boxes);
$boxes = [];
if ($res_boxes) {
    while($row = $res_boxes->fetch_assoc()) $boxes[] = $row;
}

$users = [];
if ($is_admin) {
    $res_u = $conn->query("SELECT userid, username, position FROM users WHERE position IN ('cashier', 'inventory') ORDER BY userid ASC");
    if ($res_u) { while($r = $res_u->fetch_assoc()) $users[] = $r; }
}

$total_liquidity = 0;
$active_boxes_cnt = 0;
foreach ($boxes as $b) {
    $total_liquidity += floatval($b['mony']);
    if ($b['is_active'] == 1) $active_boxes_cnt++;
}
$res_total_pending = $conn->query("SELECT COALESCE(SUM(total), 0) as tot FROM sales WHERE is_transferred_to_box = 0 AND delete_status = 0");
$total_pending_amount = ($res_total_pending) ? floatval($res_total_pending->fetch_assoc()['tot']) : 0;
?>
<title>إدارة الصناديق المالية — AQNEX POS</title>

<style>
/* ===== Page Title Bar ===== */
.page-title-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 10px 0 14px;
    border-bottom: 2px solid #e2e8f0;
    margin-bottom: 18px;
    flex-wrap: wrap;
    gap: 10px;
}
.page-title-bar .ptb-left { display: flex; align-items: center; gap: 10px; }
.page-title-bar .icon-wrap {
    width: 36px; height: 36px;
    background: linear-gradient(135deg, #1e3a8a 0%, #1d4ed8 100%);
    display: flex; align-items: center; justify-content: center;
    color: #fff; font-size: 1rem;
}
.page-title-bar h4 { margin: 0; font-size: 0.95rem; font-weight: 700; color: var(--text-color); }
.page-title-bar small { font-size: 0.72rem; color: #64748b; display: block; }
.page-title-bar .ptb-actions { display: flex; gap: 8px; flex-wrap: wrap; }

/* ===== KPI Cards ===== */
.kpi-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 12px;
    margin-bottom: 18px;
}
@media (max-width: 768px) { .kpi-grid { grid-template-columns: 1fr; } }
.kpi-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    padding: 14px 16px;
    position: relative;
    overflow: hidden;
    transition: transform 0.2s ease;
}
.kpi-card:hover { transform: translateY(-2px); }
.kpi-card::before {
    content: '';
    position: absolute;
    top: 0; right: 0;
    width: 4px; height: 100%;
}
.kpi-card.kpi-green::before { background: linear-gradient(180deg, #10b981, #059669); }
.kpi-card.kpi-blue::before  { background: linear-gradient(180deg, #3b82f6, #1d4ed8); }
.kpi-card.kpi-amber::before { background: linear-gradient(180deg, #f59e0b, #d97706); }

.kpi-label {
    font-size: 0.72rem; font-weight: 700; color: #64748b;
    text-transform: uppercase; letter-spacing: 0.4px; margin-bottom: 6px;
    display: flex; align-items: center; justify-content: space-between;
}
.kpi-value {
    font-size: 1.2rem; font-weight: 800; color: #0f172a; line-height: 1;
    margin-bottom: 3px;
}
.kpi-sub { font-size: 0.65rem; color: #94a3b8; }

/* ===== Box Cards (Grid) ===== */
.box-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 14px;
    margin-bottom: 20px;
}
.box-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    padding: 16px;
    position: relative;
    transition: all 0.2s ease;
}
.box-card:hover { border-color: #94a3b8; box-shadow: 0 4px 12px rgba(0,0,0,0.07) !important; }
.box-card.inactive { opacity: 0.6; background: #f8fafc; }

.box-card-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 12px;
}
.box-id-badge {
    font-size: 0.65rem; font-weight: 700;
    color: #64748b; background: #f1f5f9;
    border: 1px solid #e2e8f0;
    padding: 2px 8px;
}
.box-status-badge {
    font-size: 0.65rem; font-weight: 700; padding: 2px 8px;
}
.status-active { background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; }
.status-inactive { background: #f1f5f9; color: #64748b; border: 1px solid #e2e8f0; }

.box-name {
    font-size: 0.95rem; font-weight: 800; color: #0f172a; margin-bottom: 4px;
}
.box-employee {
    font-size: 0.75rem; color: #64748b; margin-bottom: 12px;
    display: flex; align-items: center; gap: 5px;
}
.box-balance-row {
    background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
    border: 1px solid #bae6fd;
    padding: 10px 14px;
    margin-bottom: 12px;
    display: flex; align-items: center; justify-content: space-between;
}
.box-balance-label { font-size: 0.7rem; color: #0369a1; font-weight: 600; }
.box-balance-amount { font-size: 1.25rem; font-weight: 800; color: #0c4a6e; font-family: 'Courier New', monospace; }
.box-balance-currency { font-size: 0.65rem; color: #0369a1; }

.pending-tag {
    font-size: 0.65rem; font-weight: 700;
    background: #fef3c7; color: #b45309;
    border: 1px solid #fde68a;
    padding: 2px 8px; margin-bottom: 10px; display: inline-block;
}
.box-actions { display: flex; gap: 6px; flex-wrap: wrap; }

/* ===== تسوية يدوية ===== */
.settlement-panel {
    background: #fff;
    border: 1px solid #e2e8f0;
    padding: 18px;
    margin-bottom: 20px;
}
.settlement-panel .panel-header {
    display: flex; align-items: center; gap: 10px;
    padding-bottom: 12px;
    border-bottom: 1px solid #e2e8f0;
    margin-bottom: 16px;
}
.settlement-panel .panel-header .icon-wrap {
    width: 32px; height: 32px;
    background: linear-gradient(135deg, #10b981, #059669);
    display: flex; align-items: center; justify-content: center;
    color: #fff; font-size: 0.9rem;
}
.settlement-panel .panel-header h5 {
    margin: 0; font-size: 0.88rem; font-weight: 700; color: #0f172a;
}

/* ===== Type Toggle Buttons ===== */
.type-toggle { display: flex; gap: 0; }
.type-toggle label {
    flex: 1; text-align: center;
    padding: 7px;
    font-size: 0.78rem; font-weight: 700;
    cursor: pointer;
    border: 1px solid #e2e8f0;
    transition: all 0.18s ease;
    background: #f8fafc;
    color: #64748b;
}
.type-toggle input[type=radio] { display: none; }
.type-toggle input[type=radio]:checked + label.addition-label {
    background: #dcfce7; color: #15803d; border-color: #86efac;
}
.type-toggle input[type=radio]:checked + label.discount-label {
    background: #fee2e2; color: #b91c1c; border-color: #fca5a5;
}

@media print {
    #sidebar, .navbar-top, .no-print, .ptb-actions { display: none !important; }
    #content { margin: 0 !important; padding: 0 !important; }
    body { background: #fff !important; }
}
</style>

<div class="page-inner">

<?php if (!empty($error)): ?>
<div class="alert alert-danger rounded-0 mb-3 no-print" style="font-size:0.82rem; border-right: 4px solid #b91c1c;">
    <i class="bi bi-exclamation-triangle-fill ml-2"></i><?php echo $error; ?>
</div>
<?php endif; ?>
<?php if (!empty($success)): ?>
<div class="alert alert-success rounded-0 mb-3 no-print" style="font-size:0.82rem; border-right: 4px solid #15803d;">
    <i class="bi bi-check-circle-fill ml-2"></i><?php echo $success; ?>
</div>
<?php endif; ?>

<!-- ===== رأس الصفحة ===== -->
<div class="page-title-bar no-print">
    <div class="ptb-left">
        <div class="icon-wrap"><i class="bi bi-safe2"></i></div>
        <div>
            <h4>إدارة الصناديق المالية</h4>
            <small>متابعة أرصدة الصناديق، الإيداع والسحب، والتحويلات النقدية</small>
        </div>
    </div>
    <div class="ptb-actions">
        <?php if ($is_admin): ?>
        <button class="btn btn-sm btn-success" data-toggle="modal" data-target="#addBoxModal" style="font-size:0.8rem;" title="إضافة صندوق جديد">
            <i class="bi bi-plus-lg ml-1"></i> إضافة صندوق
        </button>
        <button class="btn btn-sm btn-primary" data-toggle="modal" data-target="#transferModal" style="font-size:0.8rem;" title="تحويل بين الصناديق">
            <i class="bi bi-arrow-left-right ml-1"></i> تحويل مالي
        </button>
        <?php endif; ?>
        <a href="../home.php" class="btn btn-sm btn-light text-decoration-none" style="font-size:0.8rem; border:1px solid #cbd5e1;" title="عودة للرئيسية">
            <i class="bi bi-arrow-left ml-1"></i> عودة
        </a>
    </div>
</div>

<!-- ===== KPI Cards ===== -->
<div class="kpi-grid no-print">
    <div class="kpi-card kpi-green">
        <div class="kpi-label">
            <span>إجمالي سيولة الصناديق</span>
            <i class="bi bi-wallet2 text-success"></i>
        </div>
        <div class="kpi-value"><?php echo number_format($total_liquidity, 2); ?> <span style="font-size:0.7rem;font-weight:400;">ر.ي</span></div>
        <div class="kpi-sub">الرصيد النقدي المتوفر في جميع الصناديق</div>
    </div>
    <div class="kpi-card kpi-blue">
        <div class="kpi-label">
            <span>الصناديق النشطة</span>
            <i class="bi bi-safe text-primary"></i>
        </div>
        <div class="kpi-value"><?php echo number_format($active_boxes_cnt); ?> <span style="font-size:0.7rem;font-weight:400;">صندوق</span></div>
        <div class="kpi-sub">إجمالي الصناديق العاملة حالياً</div>
    </div>
    <div class="kpi-card kpi-amber">
        <div class="kpi-label">
            <span>مبيعات معلقة للترحيل</span>
            <i class="bi bi-clock-history text-warning"></i>
        </div>
        <div class="kpi-value <?php echo $total_pending_amount > 0 ? 'text-warning' : ''; ?>">
            <?php echo number_format($total_pending_amount, 2); ?> <span style="font-size:0.7rem;font-weight:400;">ر.ي</span>
        </div>
        <div class="kpi-sub">مبيعات لم يتم ترحيلها للصناديق بعد</div>
    </div>
</div>

<div class="row">
    <!-- ===== قائمة الصناديق كبطاقات ===== -->
    <div class="col-lg-8 mb-4">
        <div class="card-flat">
            <div class="card-header">
                <h5><i class="bi bi-safe2 ml-2"></i> أرصدة وإدارة الصناديق النقدية</h5>
            </div>
            <div class="card-body">
                <div class="box-grid">
                    <?php foreach ($boxes as $box):
                        $bx_id = intval($box['box_id']);
                        $sql_p_box = "SELECT COALESCE(SUM(total), 0) as pending_sales FROM sales WHERE box_id = $bx_id AND is_transferred_to_box = 0 AND delete_status = 0";
                        $res_p_box = $conn->query($sql_p_box);
                        $pending_sales = ($res_p_box) ? floatval($res_p_box->fetch_assoc()['pending_sales']) : 0;
                        $is_active_box = $box['is_active'] == 1;
                    ?>
                    <div class="box-card <?php echo $is_active_box ? '' : 'inactive'; ?>">
                        <div class="box-card-header">
                            <span class="box-id-badge">#<?php echo $box['box_id']; ?></span>
                            <span class="box-status-badge <?php echo $is_active_box ? 'status-active' : 'status-inactive'; ?>">
                                <?php echo $is_active_box ? 'نشط' : 'معطل'; ?>
                            </span>
                        </div>
                        <div class="box-name"><?php echo htmlspecialchars($box['name']); ?></div>
                        <div class="box-employee">
                            <i class="bi bi-person-badge"></i>
                            <?php echo $box['username'] ? htmlspecialchars($box['username']) : 'غير مخصص لموظف'; ?>
                        </div>

                        <?php if ($pending_sales > 0): ?>
                        <div class="pending-tag no-print">
                            <i class="bi bi-clock ml-1"></i> مبيعات معلقة: <?php echo number_format($pending_sales, 2); ?> ر.ي
                        </div>
                        <?php endif; ?>

                        <div class="box-balance-row">
                            <div>
                                <div class="box-balance-label">الرصيد الحالي</div>
                                <div class="kpi-sub"><?php echo !empty($box['remark']) ? htmlspecialchars($box['remark']) : 'لا توجد ملاحظات'; ?></div>
                            </div>
                            <div class="text-left">
                                <div class="box-balance-amount"><?php echo number_format($box['mony'], 2); ?></div>
                                <div class="box-balance-currency text-left">ريال يمني</div>
                            </div>
                        </div>

                        <div class="box-actions no-print">
                            <?php if ($pending_sales > 0): ?>
                            <form method="POST" style="margin:0;">
                                <input type="hidden" name="box_id_to_transfer" value="<?php echo $box['box_id']; ?>">
                                <button type="submit" name="btn_quick_transfer" class="btn btn-warning btn-sm" title="ترحيل المبيعات المعلقة">
                                    <i class="bi bi-reply-all ml-1"></i> ترحيل المبيعات
                                </button>
                            </form>
                            <?php endif; ?>
                            <a href="close.php?box_id=<?php echo $box['box_id']; ?>" class="btn btn-success btn-sm text-decoration-none" title="إقفال الوردية والترحيل">
                                <i class="bi bi-safe2 ml-1"></i> إقفال
                            </a>
                            <?php if ($is_admin): ?>
                            <button class="btn btn-primary btn-sm" onclick='openEditModal(<?php echo json_encode($box); ?>)' title="تعديل الصندوق">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- ===== لوحة التسوية المالية ===== -->
    <div class="col-lg-4 mb-4">
        <div class="settlement-panel">
            <div class="panel-header">
                <div class="icon-wrap"><i class="bi bi-calculator"></i></div>
                <div>
                    <h5>التسويات المالية اليدوية</h5>
                    <small style="font-size:0.7rem; color:#64748b;">إيداع أو سحب مباشر من الصندوق</small>
                </div>
            </div>
            <form method="POST" onsubmit="return confirm('تأكيد تنفيذ هذه الحركة المالية اليدوية؟ لا يمكن التراجع عنها.')">
                <div class="form-group mb-3">
                    <label class="form-label" style="font-size:0.8rem; font-weight:700; color:#334155;">الصندوق المالي</label>
                    <select name="target_box_id" class="form-control" required>
                        <?php if ($is_admin): ?>
                            <?php foreach ($boxes as $box): ?>
                                <?php if ($box['is_active'] == 1): ?>
                                    <option value="<?php echo $box['box_id']; ?>"><?php echo htmlspecialchars($box['name']); ?> (<?php echo number_format($box['mony'], 2); ?> ر.ي)</option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <?php $user_box_id = get_user_box_id($conn, $active_user_id); ?>
                            <option value="<?php echo $user_box_id; ?>"><?php echo get_box_name($conn, $user_box_id); ?></option>
                        <?php endif; ?>
                    </select>
                </div>

                <div class="form-group mb-3">
                    <label class="form-label" style="font-size:0.8rem; font-weight:700; color:#334155;">نوع الحركة</label>
                    <div class="type-toggle">
                        <input type="radio" name="type" id="type_add" value="addition" required>
                        <label for="type_add" class="addition-label"><i class="bi bi-plus-circle ml-1"></i>إيداع</label>
                        <input type="radio" name="type" id="type_sub" value="discount">
                        <label for="type_sub" class="discount-label"><i class="bi bi-dash-circle ml-1"></i>سحب</label>
                    </div>
                </div>

                <div class="form-group mb-3">
                    <label class="form-label" style="font-size:0.8rem; font-weight:700; color:#334155;">المبلغ (ر.ي)</label>
                    <input type="number" step="any" name="n" class="form-control text-center" style="font-size:1.1rem; font-weight:700;" placeholder="0.00" required>
                </div>

                <div class="form-group mb-4">
                    <label class="form-label" style="font-size:0.8rem; font-weight:700; color:#334155;">البيان / ملاحظات</label>
                    <input type="text" name="r" class="form-control" placeholder="سبب الحركة المالية..." required>
                </div>

                <button type="submit" name="btn_save" class="btn btn-dark btn-block" style="font-size:0.85rem; font-weight:700; padding:9px;">
                    <i class="bi bi-check2-circle ml-1"></i> تأكيد وحفظ الحركة
                </button>
            </form>
        </div>
    </div>
</div>

<!-- ======= Modals (المدير فقط) ======= -->
<?php if ($is_admin): ?>

<!-- إضافة صندوق جديد -->
<div class="modal fade" id="addBoxModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content rounded-0">
            <div class="modal-header" style="background:#f8fafc; border-bottom:1px solid #e2e8f0;">
                <h5 class="modal-title font-weight-bold" style="font-size:0.9rem;">
                    <i class="bi bi-safe-fill ml-2 text-success"></i>إضافة صندوق مالي جديد
                </h5>
                <button type="button" class="close ml-0" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <div class="form-group mb-3">
                        <label class="form-label" style="font-size:0.8rem; font-weight:700;">اسم الصندوق *</label>
                        <input type="text" name="box_name" class="form-control" placeholder="مثال: صندوق الكاشير الرئيسي" required>
                    </div>
                    <div class="form-group mb-3">
                        <label class="form-label" style="font-size:0.8rem; font-weight:700;">ربط بموظف (كاشير/أمين مستودع)</label>
                        <select name="user_id" class="form-control">
                            <option value="">-- عام (غير مخصص لموظف) --</option>
                            <?php foreach ($users as $u): ?>
                            <option value="<?php echo $u['userid']; ?>"><?php echo htmlspecialchars($u['username']); ?> (<?php echo $u['position'] === 'cashier' ? 'كاشير' : 'أمين مستودع'; ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group mb-3">
                        <label class="form-label" style="font-size:0.8rem; font-weight:700;">الرصيد الافتتاحي (ر.ي)</label>
                        <input type="number" step="any" name="initial_balance" class="form-control" value="0">
                    </div>
                    <div class="form-group mb-0">
                        <label class="form-label" style="font-size:0.8rem; font-weight:700;">ملاحظات / وصف الصندوق</label>
                        <input type="text" name="remark" class="form-control" placeholder="صندوق مبيعات الفرع الرئيسي...">
                    </div>
                </div>
                <div class="modal-footer" style="background:#f8fafc; border-top:1px solid #e2e8f0; justify-content:flex-start; padding:10px 16px;">
                    <button type="submit" name="btn_add_box" class="btn btn-success" style="font-size:0.82rem;">
                        <i class="bi bi-check2-circle ml-1"></i> حفظ وإنشاء الصندوق
                    </button>
                    <button type="button" class="btn btn-light" data-dismiss="modal" style="font-size:0.82rem;">إلغاء</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- تعديل صندوق -->
<div class="modal fade" id="editBoxModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content rounded-0">
            <div class="modal-header" style="background:#f8fafc; border-bottom:1px solid #e2e8f0;">
                <h5 class="modal-title font-weight-bold" style="font-size:0.9rem;">
                    <i class="bi bi-pencil-square ml-2 text-primary"></i>تعديل بيانات الصندوق
                </h5>
                <button type="button" class="close ml-0" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <form method="POST">
                <input type="hidden" name="edit_box_id" id="edit_box_id">
                <div class="modal-body">
                    <div class="form-group mb-3">
                        <label class="form-label" style="font-size:0.8rem; font-weight:700;">اسم الصندوق *</label>
                        <input type="text" name="box_name" id="edit_box_name" class="form-control" required>
                    </div>
                    <div class="form-group mb-3">
                        <label class="form-label" style="font-size:0.8rem; font-weight:700;">ربط بموظف</label>
                        <select name="user_id" id="edit_user_id" class="form-control">
                            <option value="">-- عام (غير مخصص لموظف) --</option>
                            <?php foreach ($users as $u): ?>
                            <option value="<?php echo $u['userid']; ?>"><?php echo htmlspecialchars($u['username']); ?> (<?php echo $u['position'] === 'cashier' ? 'كاشير' : 'أمين مستودع'; ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group mb-3">
                        <label class="form-label" style="font-size:0.8rem; font-weight:700;">ملاحظات</label>
                        <input type="text" name="remark" id="edit_remark" class="form-control">
                    </div>
                    <div class="form-group mb-0" id="activeToggleContainer">
                        <div class="custom-control custom-checkbox">
                            <input type="checkbox" class="custom-control-input" id="edit_is_active" name="is_active" checked>
                            <label class="custom-control-label font-weight-bold" for="edit_is_active" style="font-size:0.82rem;">الصندوق نشط ومفعّل</label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer" style="background:#f8fafc; border-top:1px solid #e2e8f0; justify-content:flex-start; padding:10px 16px;">
                    <button type="submit" name="btn_edit_box" class="btn btn-primary" style="font-size:0.82rem;">
                        <i class="bi bi-check2 ml-1"></i> حفظ التغييرات
                    </button>
                    <button type="button" class="btn btn-light" data-dismiss="modal" style="font-size:0.82rem;">إلغاء</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- تحويل بين الصناديق -->
<div class="modal fade" id="transferModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content rounded-0">
            <div class="modal-header" style="background:#f8fafc; border-bottom:1px solid #e2e8f0;">
                <h5 class="modal-title font-weight-bold" style="font-size:0.9rem;">
                    <i class="bi bi-arrow-left-right ml-2 text-info"></i>تحويل أموال بين الصناديق
                </h5>
                <button type="button" class="close ml-0" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <form method="POST" onsubmit="return confirm('تأكيد إتمام عملية التحويل المالي بين الصناديق؟ لا يمكن التراجع.')">
                <div class="modal-body">
                    <div class="form-group mb-3">
                        <label class="form-label" style="font-size:0.8rem; font-weight:700;">الصندوق المصدر (الخصم منه) *</label>
                        <select name="from_box" class="form-control" required>
                            <option value="">-- اختر الصندوق المصدر --</option>
                            <?php foreach ($boxes as $box): ?>
                                <?php if ($box['is_active'] == 1): ?>
                                <option value="<?php echo $box['box_id']; ?>"><?php echo htmlspecialchars($box['name']); ?> (<?php echo number_format($box['mony'], 2); ?> ر.ي)</option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group mb-3">
                        <label class="form-label" style="font-size:0.8rem; font-weight:700;">الصندوق الهدف (الإيداع فيه) *</label>
                        <select name="to_box" class="form-control" required>
                            <option value="">-- اختر الصندوق الهدف --</option>
                            <?php foreach ($boxes as $box): ?>
                                <?php if ($box['is_active'] == 1): ?>
                                <option value="<?php echo $box['box_id']; ?>"><?php echo htmlspecialchars($box['name']); ?> (<?php echo number_format($box['mony'], 2); ?> ر.ي)</option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group mb-3">
                        <label class="form-label" style="font-size:0.8rem; font-weight:700;">مبلغ التحويل (ر.ي) *</label>
                        <input type="number" step="any" name="transfer_amount" class="form-control text-center" style="font-size:1.05rem; font-weight:700;" placeholder="0.00" required>
                    </div>
                    <div class="form-group mb-0">
                        <label class="form-label" style="font-size:0.8rem; font-weight:700;">بيان التحويل / ملاحظات *</label>
                        <input type="text" name="transfer_remark" class="form-control" placeholder="سبب التحويل المالي..." required>
                    </div>
                </div>
                <div class="modal-footer" style="background:#f8fafc; border-top:1px solid #e2e8f0; justify-content:flex-start; padding:10px 16px;">
                    <button type="submit" name="btn_transfer" class="btn btn-info text-white" style="font-size:0.82rem;">
                        <i class="bi bi-arrow-left-right ml-1"></i> إجراء التحويل
                    </button>
                    <button type="button" class="btn btn-light" data-dismiss="modal" style="font-size:0.82rem;">إلغاء</button>
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
    document.getElementById('edit_remark').value = box.remark || '';
    document.getElementById('edit_is_active').checked = box.is_active == 1;
    if (parseInt(box.box_id) === 1) {
        document.getElementById('activeToggleContainer').style.display = 'none';
    } else {
        document.getElementById('activeToggleContainer').style.display = 'block';
    }
    $('#editBoxModal').modal('show');
}
</script>
<?php endif; ?>

</div><!-- end .page-inner -->
<?php require_once($dir_prefix . 'includes/footer.php'); ?>
