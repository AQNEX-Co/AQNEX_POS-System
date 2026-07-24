<?php
$dir_prefix = '../';
$module = 'accounts';
require_once($dir_prefix . 'includes/header.php');

check_permission(['admin']);

// مزامنة دليل الحسابات تلقائياً ليكون فعالاً وديناميكياً ومطابقاً للحركات
\AQNEX\Services\AccountingService::syncAccounts($conn);

$msg = isset($_GET['msg']) ? $_GET['msg'] : '';

// ==========================================
// معالجة طلبات الإضافة والتعديل والحذف (POST)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? $_POST['action'] : '';

    if ($action === 'save') {
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        $code = trim($_POST['code']);
        $name = trim($_POST['name']);
        $parent_id = isset($_POST['parent_id']) && $_POST['parent_id'] !== '' ? intval($_POST['parent_id']) : null;
        $account_type = trim($_POST['account_type']);
        $is_parent = isset($_POST['is_parent']) ? intval($_POST['is_parent']) : 0;
        $notes = trim($_POST['notes']);

        if (empty($code) || empty($name) || empty($account_type)) {
            header('Location: accounts.php?msg=empty');
            exit;
        }

        $code = $conn->real_escape_string($code);
        $name = $conn->real_escape_string($name);
        $account_type = $conn->real_escape_string($account_type);
        $notes = $conn->real_escape_string($notes);
        $parent_id_val = ($parent_id === null) ? 'NULL' : $parent_id;

        if ($id === 0) {
            $chk = $conn->query("SELECT id FROM accounting_accounts WHERE code = '$code'");
            if ($chk && $chk->num_rows > 0) {
                header('Location: accounts.php?msg=code_exists');
                exit;
            }

            $level = 1;
            if ($parent_id !== null) {
                $p_res = $conn->query("SELECT level, account_type FROM accounting_accounts WHERE id = $parent_id");
                if ($p_res && $p_row = $p_res->fetch_assoc()) {
                    $level = intval($p_row['level']) + 1;
                    $account_type = $conn->real_escape_string($p_row['account_type']);
                }
            }

            $sql = "INSERT INTO accounting_accounts (code, name, parent_id, account_type, is_parent, level, notes) 
                    VALUES ('$code', '$name', $parent_id_val, '$account_type', $is_parent, $level, '$notes')";
            
            if ($conn->query($sql)) {
                header('Location: accounts.php?msg=added');
            } else {
                header('Location: accounts.php?msg=error');
            }
            exit;
        } else {
            $chk = $conn->query("SELECT id FROM accounting_accounts WHERE code = '$code' AND id != $id");
            if ($chk && $chk->num_rows > 0) {
                header('Location: accounts.php?msg=code_exists');
                exit;
            }

            $sql = "UPDATE accounting_accounts 
                    SET code = '$code', name = '$name', is_parent = $is_parent, notes = '$notes' 
                    WHERE id = $id";
            
            if ($conn->query($sql)) {
                header('Location: accounts.php?msg=updated');
            } else {
                header('Location: accounts.php?msg=error');
            }
            exit;
        }
    }

    if ($action === 'delete') {
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;

        if ($id > 0) {
            $chk_child = $conn->query("SELECT id FROM accounting_accounts WHERE parent_id = $id");
            if ($chk_child && $chk_child->num_rows > 0) {
                header('Location: accounts.php?msg=has_children');
                exit;
            }

            $acc_res = $conn->query("SELECT name FROM accounting_accounts WHERE id = $id");
            if ($acc_res && $acc_row = $acc_res->fetch_assoc()) {
                $name_escaped = $conn->real_escape_string($acc_row['name']);
                
                $chk_journal = $conn->query("SELECT id FROM accounting_journal 
                                             WHERE account_debit = '$name_escaped' 
                                                OR account_debit LIKE '%$name_escaped%'
                                                OR account_credit = '$name_escaped' 
                                                OR account_credit LIKE '%$name_escaped%' 
                                             LIMIT 1");
                
                if ($chk_journal && $chk_journal->num_rows > 0) {
                    header('Location: accounts.php?msg=has_entries');
                    exit;
                }
            }

            if ($conn->query("DELETE FROM accounting_accounts WHERE id = $id")) {
                header('Location: accounts.php?msg=deleted');
            } else {
                header('Location: accounts.php?msg=error');
            }
            exit;
        }
    }
}

// ==========================================
// جلب الحسابات وبناء الشجرة وحساب الأرصدة
// ==========================================
$sql_accounts = "SELECT a.*,
    (SELECT COALESCE(SUM(aj.amount), 0) FROM accounting_journal aj WHERE aj.account_debit = a.name OR aj.account_debit LIKE CONCAT('%', a.name) OR aj.account_debit LIKE CONCAT(a.name, ' - %')) AS total_debit,
    (SELECT COALESCE(SUM(aj.amount), 0) FROM accounting_journal aj WHERE aj.account_credit = a.name OR aj.account_credit LIKE CONCAT('%', a.name) OR aj.account_credit LIKE CONCAT(a.name, ' - %')) AS total_credit
FROM accounting_accounts a
ORDER BY a.code ASC";

$res_accounts = $conn->query($sql_accounts);
$accounts_by_parent = [];
$accounts_all = [];

if ($res_accounts) {
    while ($row = $res_accounts->fetch_assoc()) {
        $parent = $row['parent_id'] ?? 0;
        
        $debit = doubleval($row['total_debit']);
        $credit = doubleval($row['total_credit']);
        
        if (in_array($row['account_type'], ['asset', 'expense'])) {
            $row['balance'] = $debit - $credit;
        } else {
            $row['balance'] = $credit - $debit;
        }
        
        $row['total_debit_val'] = $debit;
        $row['total_credit_val'] = $credit;
        
        $accounts_by_parent[$parent][] = $row;
        $accounts_all[$row['id']] = $row;
    }
}

function calculate_cumulative_balances(&$accounts_by_parent, &$accounts_all, $parent_id = 0) {
    $sum = 0;
    if (!isset($accounts_by_parent[$parent_id])) return 0;
    
    foreach ($accounts_by_parent[$parent_id] as $key => $acc) {
        if ($acc['is_parent']) {
            $child_sum = calculate_cumulative_balances($accounts_by_parent, $accounts_all, $acc['id']);
            $accounts_by_parent[$parent_id][$key]['balance'] = $child_sum;
            $accounts_all[$acc['id']]['balance'] = $child_sum;
            $sum += $child_sum;
        } else {
            $sum += $acc['balance'];
        }
    }
    return $sum;
}
calculate_cumulative_balances($accounts_by_parent, $accounts_all, 0);

function render_account_tree($accounts_by_parent, $parent_id = 0, $depth = 0) {
    if (!isset($accounts_by_parent[$parent_id])) return '';
    
    $html = '<ul class="account-tree-branch" data-depth="' . $depth . '">';
    foreach ($accounts_by_parent[$parent_id] as $acc) {
        $has_children = isset($accounts_by_parent[$acc['id']]);
        $class_type = $acc['is_parent'] ? 'parent-node' : 'leaf-node';
        
        // تحديد اللون حسب نوع الحساب
        $type_class = 'type-' . $acc['account_type'];
        
        $html .= '<li class="account-node ' . $class_type . ' ' . $type_class . '" 
                      data-id="' . $acc['id'] . '" 
                      data-code="' . $acc['code'] . '" 
                      data-name="' . htmlspecialchars($acc['name']) . '" 
                      data-type="' . $acc['account_type'] . '" 
                      data-is-parent="' . $acc['is_parent'] . '" 
                      data-notes="' . htmlspecialchars($acc['notes'] ?? '') . '">';
        
        $html .= '<div class="node-content">';
        
        // مؤشر التوسيع
        if ($acc['is_parent']) {
            $html .= '<span class="toggle-icon"><i class="bi bi-chevron-down"></i></span>';
        } else {
            $html .= '<span class="toggle-icon-placeholder"></span>';
        }
        
        // شارة نوع الحساب
        $type_labels = [
            'asset' => 'أصول', 
            'liability' => 'خصوم', 
            'equity' => 'حقوق ملكية', 
            'revenue' => 'إيرادات', 
            'expense' => 'مصروفات'
        ];
        $type_label = $type_labels[$acc['account_type']] ?? $acc['account_type'];
        $html .= '<span class="type-badge ' . $type_class . '">' . $type_label . '</span>';
        
        // كود الحساب
        $html .= '<span class="node-code">' . $acc['code'] . '</span>';
        
        // اسم الحساب
        $html .= '<span class="node-name">' . htmlspecialchars($acc['name']) . '</span>';
        
        // عرض الأرصدة (مدين / دائن)
        $debit = $acc['total_debit_val'] ?? 0;
        $credit = $acc['total_credit_val'] ?? 0;
        
        if (!$acc['is_parent'] || $depth > 0) {
            $html .= '<div class="node-balances">';
            $html .= '<span class="balance-item debit" title="إجمالي المدين">';
            $html .= '<small>مدين</small>';
            $html .= '<strong>' . number_format($debit, 2) . '</strong>';
            $html .= '</span>';
            $html .= '<span class="balance-item credit" title="إجمالي الدائن">';
            $html .= '<small>دائن</small>';
            $html .= '<strong>' . number_format($credit, 2) . '</strong>';
            $html .= '</span>';
            $html .= '</div>';
        }
        
        // الرصيد الصافي
        $balance = $acc['balance'];
        $balance_class = $balance > 0 ? 'positive' : ($balance < 0 ? 'negative' : 'zero');
        $balance_sign = $balance >= 0 ? '' : '-';
        $html .= '<div class="node-balance ' . $balance_class . '">';
        $html .= '<small>الرصيد</small>';
        $html .= '<strong>' . $balance_sign . number_format(abs($balance), 2) . '</strong>';
        $html .= '</div>';
        
        // أزرار العمليات
        $html .= '<div class="node-actions">';
        if ($acc['is_parent']) {
            $html .= '<button type="button" class="action-btn add-child-btn" title="إضافة حساب فرعي" data-id="' . $acc['id'] . '"><i class="bi bi-plus-lg"></i></button>';
        }
        $html .= '<button type="button" class="action-btn edit-btn" title="تعديل الحساب" data-id="' . $acc['id'] . '"><i class="bi bi-pencil"></i></button>';
        
        if (!$has_children) {
            $html .= '<button type="button" class="action-btn delete-btn" title="حذف" data-id="' . $acc['id'] . '"><i class="bi bi-trash"></i></button>';
        }
        $html .= '</div>';
        
        $html .= '</div>';
        
        if ($has_children) {
            $html .= render_account_tree($accounts_by_parent, $acc['id'], $depth + 1);
        }
        
        $html .= '</li>';
    }
    $html .= '</ul>';
    return $html;
}

// حساب الإجماليات
$totals = ['asset' => 0, 'liability' => 0, 'equity' => 0, 'revenue' => 0, 'expense' => 0];
$counts = ['asset' => 0, 'liability' => 0, 'equity' => 0, 'revenue' => 0, 'expense' => 0];

function count_accounts_recursive($accounts_by_parent, $parent_id = 0, &$counts) {
    if (!isset($accounts_by_parent[$parent_id])) return;
    foreach ($accounts_by_parent[$parent_id] as $acc) {
        if (!$acc['is_parent']) {
            if (isset($counts[$acc['account_type']])) {
                $counts[$acc['account_type']]++;
            }
        }
        count_accounts_recursive($accounts_by_parent, $acc['id'], $counts);
    }
}

if (isset($accounts_by_parent[0])) {
    foreach ($accounts_by_parent[0] as $root_acc) {
        $type = $root_acc['account_type'];
        if (isset($totals[$type])) {
            $totals[$type] += $root_acc['balance'];
        }
    }
}
count_accounts_recursive($accounts_by_parent, 0, $counts);

$total_accounts_count = array_sum($counts);
$net_balance = $totals['asset'] - ($totals['liability'] + $totals['equity']);
?>
<title>دليل الحسابات المحاسبي</title>

<style>
/* ===== بطاقات الإحصائيات ===== */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    gap: 12px;
    margin-bottom: 20px;
}

@media (max-width: 992px) {
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

.stat-card {
    background: #fff;
    padding: 15px;
    border: 1px solid #e5e7eb;
    position: relative;
    overflow: hidden;
    transition: all 0.25s ease;
}

.stat-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 20px rgba(0,0,0,0.08);
}

.stat-card::before {
    content: '';
    position: absolute;
    top: 0;
    right: 0;
    width: 4px;
    height: 100%;
}

.stat-card.type-asset::before { background: linear-gradient(180deg, #10b981, #059669); }
.stat-card.type-liability::before { background: linear-gradient(180deg, #ef4444, #dc2626); }
.stat-card.type-equity::before { background: linear-gradient(180deg, #f59e0b, #d97706); }
.stat-card.type-revenue::before { background: linear-gradient(180deg, #3b82f6, #2563eb); }
.stat-card.type-expense::before { background: linear-gradient(180deg, #8b5cf6, #7c3aed); }

.stat-card .stat-icon {
    width: 36px;
    height: 36px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-size: 1rem;
    margin-bottom: 10px;
}

.stat-card.type-asset .stat-icon { color: #10b981; }
.stat-card.type-liability .stat-icon { color: #ef4444; }
.stat-card.type-equity .stat-icon { color: #f59e0b; }
.stat-card.type-revenue .stat-icon { color: #3b82f6; }
.stat-card.type-expense .stat-icon { color: #8b5cf6; }

.stat-card .stat-label {
    font-size: 0.75rem;
    color: #6b7280;
    font-weight: 600;
    margin-bottom: 4px;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.stat-card .stat-value {
    font-size: 0.9rem;
    font-weight: 700;
    color: #111827;
    font-family: 'Tajawal', sans-serif;
    margin-bottom: 2px;
}

.stat-card .stat-count {
    font-size: 0.5rem;
    color: #9ca3af;
}

/* ===== شجرة الحسابات ===== */
.account-tree-container {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    padding: 15px;
    min-height: 500px;
    max-height: 700px;
    overflow-y: auto;
    overflow-x: auto;
}

.account-tree-branch {
    list-style: none;
    padding-right: 20px;
    margin: 0;
    position: relative;
}

.account-tree-branch::before {
    content: '';
    position: absolute;
    top: 0;
    bottom: 15px;
    right: 8px;
    border-right: 1px dashed #d1d5db;
}

.account-tree-branch[data-depth="0"] {
    padding-right: 0;
}

.account-tree-branch[data-depth="0"]::before {
    display: none;
}

.account-node {
    margin: 4px 0;
    position: relative;
}

.account-node::before {
    content: '';
    position: absolute;
    top: 20px;
    right: -12px;
    width: 12px;
    border-top: 1px dashed #d1d5db;
}

.account-tree-branch[data-depth="0"] > .account-node::before {
    display: none;
}

.node-content {
    display: flex;
    align-items: center;
    padding: 8px 12px;
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 6px;
    transition: all 0.2s ease;
    gap: 10px;
    position: relative;
}

.node-content:hover {
    background: #f9fafb;
    border-color: #9ca3af;
    box-shadow: 0 2px 6px rgba(0,0,0,0.05);
}

/* تلوين حسب نوع الحساب */
.account-node.type-asset > .node-content { border-right: 3px solid #10b981; }
.account-node.type-liability > .node-content { border-right: 3px solid #ef4444; }
.account-node.type-equity > .node-content { border-right: 3px solid #f59e0b; }
.account-node.type-revenue > .node-content { border-right: 3px solid #3b82f6; }
.account-node.type-expense > .node-content { border-right: 3px solid #8b5cf6; }

.account-node.parent-node > .node-content {
    background: #f9fafb;
    font-weight: 600;
}

.toggle-icon, .toggle-icon-placeholder {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 18px;
    height: 18px;
    flex-shrink: 0;
    color: #6b7280;
    transition: transform 0.2s ease;
}

.toggle-icon {
    cursor: pointer;
}

.toggle-icon.collapsed {
    transform: rotate(-90deg);
}

.toggle-icon:hover {
    color: #111827;
}

.toggle-icon-placeholder {
    visibility: hidden;
}

.type-badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 4px;
    font-size: 0.65rem;
    font-weight: 700;
    flex-shrink: 0;
    letter-spacing: 0.3px;
}

.type-badge.type-asset { color: #10b981; }
.type-badge.type-liability { color: #ef4444; }
.type-badge.type-equity { color: #f59e0b; }
.type-badge.type-revenue { color: #3b82f6; }
.type-badge.type-expense { color: #8b5cf6; }

.node-code {
    background: #f3f4f6;
    color: #374151;
    padding: 2px 8px;
    border-radius: 4px;
    font-size: 0.72rem;
    font-family: 'Tajawal', sans-serif;
    font-weight: 700;
    flex-shrink: 0;
    border: 1px solid #e5e7eb;
}

.node-name {
    color: #111827;
    font-size: 0.85rem;
    font-weight: 600;
    flex: 1;
    min-width: 0;
}

.parent-node > .node-content .node-name {
    font-size: 0.9rem;
}

.node-balances {
    display: flex;
    gap: 8px;
    flex-shrink: 0;
}

.balance-item {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 3px 8px;
    border-radius: 4px;
    min-width: 70px;
}

.balance-item small {
    font-size: 0.6rem;
    font-weight: 600;
    text-transform: uppercase;
    opacity: 0.7;
}

.balance-item strong {
    font-size: 0.72rem;
    font-family: 'Tajawal', sans-serif;
    font-weight: 700;
}

.balance-item.debit {
    background: #fef2f2;
    color: #991b1b;
    border: 1px solid #fecaca;
}

.balance-item.credit {
    background: #f0fdf4;
    color: #166534;
    border: 1px solid #bbf7d0;
}

.node-balance {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 3px 10px;
    border-radius: 4px;
    min-width: 80px;
    flex-shrink: 0;
}

.node-balance small {
    font-size: 0.6rem;
    font-weight: 600;
    text-transform: uppercase;
    opacity: 0.7;
}

.node-balance strong {
    font-size: 0.72rem;
    font-family: 'Tajawal', sans-serif;
    font-weight: 700;
}

.node-balance.positive {
    background: #dcfce7;
    color: #166534;
    border: 1px solid #86efac;
}

.node-balance.negative {
    background: #fee2e2;
    color: #991b1b;
    border: 1px solid #fca5a5;
}

.node-balance.zero {
    background: #f3f4f6;
    color: #6b7280;
    border: 1px solid #d1d5db;
}

.node-actions {
    display: flex;
    gap: 4px;
    flex-shrink: 0;
    opacity: 0;
    transition: opacity 0.2s ease;
}

.node-content:hover .node-actions {
    opacity: 1;
}

.action-btn {
    width: 26px;
    height: 26px;
    border-radius: 4px;
    border: 1px solid #e5e7eb;
    background: #fff;
    color: #6b7280;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.15s ease;
    font-size: 0.75rem;
    padding: 0;
}

.action-btn:hover {
    transform: translateY(-1px);
}

.action-btn.add-child-btn:hover {
    background: #10b981;
    color: #fff;
    border-color: #10b981;
}

.action-btn.edit-btn:hover {
    background: #3b82f6;
    color: #fff;
    border-color: #3b82f6;
}

.action-btn.delete-btn:hover {
    background: #ef4444;
    color: #fff;
    border-color: #ef4444;
}

.account-tree-branch.collapsed {
    display: none;
}

/* ===== لوحة التحكم ===== */
.control-panel {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    overflow: hidden;
    position: sticky;
    top: 20px;
}

.control-panel .panel-header {
    background: linear-gradient(135deg, #1e3a8a 0%, #0f172a 100%);
    color: #fff;
    padding: 12px 15px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.control-panel .panel-header h6 {
    margin: 0;
    font-size: 0.9rem;
    font-weight: 700;
}

.control-panel .panel-body {
    padding: 15px;
}

.control-panel .form-label {
    font-size: 0.75rem;
    font-weight: 700;
    color: #374151;
    margin-bottom: 4px;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.control-panel .form-control {
    font-size: 0.85rem;
    padding: 7px 10px;
    border: 1px solid #d1d5db;
    border-radius: 4px;
}

.control-panel .form-control:focus {
    border-color: #3b82f6;
    box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.15);
}

.control-panel .form-group {
    margin-bottom: 12px;
}

.control-panel .radio-group {
    display: grid;
    grid-template-columns: 1fr;
    gap: 6px;
}

.control-panel .radio-option {
    display: flex;
    align-items: center;
    padding: 8px 10px;
    border: 1px solid #e5e7eb;
    border-radius: 4px;
    cursor: pointer;
    transition: all 0.15s ease;
}

.control-panel .radio-option:hover {
    background: #f9fafb;
    border-color: #9ca3af;
}

.control-panel .radio-option input[type="radio"] {
    margin-left: 8px;
}

.control-panel .radio-option label {
    margin: 0;
    cursor: pointer;
    font-size: 0.8rem;
    font-weight: 600;
    flex: 1;
}

.control-panel .btn-submit {
    width: 100%;
    padding: 9px;
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    color: #fff;
    border: none;
    border-radius: 4px;
    font-weight: 700;
    font-size: 0.85rem;
    cursor: pointer;
    transition: all 0.2s ease;
}

.control-panel .btn-submit:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
}

/* ===== شريط الأدوات ===== */
.toolbar {
    display: flex;
    gap: 8px;
    margin-bottom: 15px;
    flex-wrap: wrap;
}

.toolbar .search-box {
    flex: 1;
    min-width: 200px;
    position: relative;
}

.toolbar .search-box input {
    width: 100%;
    padding: 8px 12px 8px 35px;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    font-size: 0.85rem;
}

.toolbar .search-box i {
    position: absolute;
    right: 12px;
    top: 50%;
    transform: translateY(-50%);
    color: #9ca3af;
}

.toolbar .btn {
    padding: 8px 14px;
    border-radius: 6px;
    font-size: 0.8rem;
    font-weight: 600;
    border: 1px solid #d1d5db;
    background: #fff;
    color: #374151;
    cursor: pointer;
    transition: all 0.15s ease;
    display: inline-flex;
    align-items: center;
    gap: 5px;
}

.toolbar .btn:hover {
    background: #f9fafb;
    border-color: #9ca3af;
}

.toolbar .btn.btn-primary {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    color: #fff;
    border-color: #10b981;
}

.toolbar .btn.btn-primary:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
}

/* ===== ملخص الحسابات ===== */
.accounts-summary {
    background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
    color: #fff;
    border-radius: 8px;
    padding: 15px;
    margin-top: 15px;
}

.accounts-summary h6 {
    font-size: 0.85rem;
    font-weight: 700;
    margin-bottom: 12px;
    color: #f1f5f9;
    border-bottom: 1px solid rgba(255,255,255,0.1);
    padding-bottom: 8px;
}

.summary-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 6px 0;
    font-size: 0.8rem;
    border-bottom: 1px dashed rgba(255,255,255,0.08);
}

.summary-row:last-child {
    border-bottom: none;
    padding-top: 10px;
    margin-top: 5px;
    border-top: 1px solid rgba(255,255,255,0.2);
    font-weight: 700;
    font-size: 0.9rem;
}

.summary-row .label {
    color: #cbd5e1;
}

.summary-row .value {
    font-family: 'Tajawal', sans-serif;
    font-weight: 700;
}

/* ===== رأس الصفحة ===== */
.page-title-bar {
    display: flex; align-items: center; justify-content: space-between;
    padding: 10px 0 14px; border-bottom: 2px solid #e2e8f0;
    margin-bottom: 18px; flex-wrap: wrap; gap: 10px;
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

/* ===== رسائل التنبيه ===== */
.alert-custom {
    border-radius: 6px;
    padding: 10px 15px;
    font-size: 0.85rem;
    border: none;
    border-right: 4px solid;
    margin-bottom: 15px;
}

.alert-custom.alert-success {
    background: #f0fdf4;
    color: #166534;
    border-right-color: #10b981;
}

.alert-custom.alert-danger {
    background: #fef2f2;
    color: #991b1b;
    border-right-color: #ef4444;
}

.alert-custom.alert-warning {
    background: #fffbeb;
    color: #92400e;
    border-right-color: #f59e0b;
}

/* ===== Scrollbar مخصص ===== */
.account-tree-container::-webkit-scrollbar {
    width: 6px;
    height: 6px;
}

.account-tree-container::-webkit-scrollbar-track {
    background: #f1f5f9;
    border-radius: 3px;
}

.account-tree-container::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 3px;
}

.account-tree-container::-webkit-scrollbar-thumb:hover {
    background: #94a3b8;
}

/* ===== الطباعة ===== */
@media print {
    .no-print, .control-panel, .toolbar, .page-header .actions { display: none !important; }
    .account-tree-container { max-height: none !important; overflow: visible !important; }
    .node-actions { display: none !important; }
}
</style>

<div class="page-inner">
<div class="page-title-bar no-print">
    <div class="ptb-left">
        <div class="icon-wrap"><i class="bi bi-diagram-3"></i></div>
        <div>
            <h4>دليل الحسابات المحاسبي (Chart of Accounts)</h4>
            <small>الهيكل الكامل للحسابات المحاسبية — الأصول، الخصوم، حقوق الملكية، الإيرادات، المصروفات</small>
        </div>
    </div>
    <div class="ptb-actions">
        <button type="button" id="expandAll" class="btn btn-sm btn-light" style="font-size:0.8rem; border:1px solid #cbd5e1;" title="توسيع شجرة الحسابات كاملاً">
            <i class="bi bi-arrows-angle-expand ml-1"></i> توسيع الكل
        </button>
        <button type="button" id="collapseAll" class="btn btn-sm btn-light" style="font-size:0.8rem; border:1px solid #cbd5e1;" title="طي شجرة الحسابات">
            <i class="bi bi-arrows-angle-contract ml-1"></i> طي الكل
        </button>
        <button onclick="window.print()" class="btn btn-sm btn-light" style="font-size:0.8rem; border:1px solid #cbd5e1;" title="طباعة الدليل المحاسبي">
            <i class="bi bi-printer ml-1"></i> طباعة
        </button>
        <a href="../home.php" class="btn btn-sm btn-light text-decoration-none" style="font-size:0.8rem; border:1px solid #cbd5e1;" title="العودة للرئيسية">
            <i class="bi bi-arrow-left ml-1"></i> عودة
        </a>
    </div>
</div>

<!-- ===== بطاقات الإحصائيات ===== -->
<div class="stats-grid no-print">
    <div class="stat-card type-asset">
        <div class="stat-icon"><i class="bi bi-cash-stack"></i></div>
        <div class="stat-label">إجمالي الأصول</div>
        <div class="stat-value"><?php echo number_format($totals['asset'], 2); ?></div>
        <div class="stat-count"><?php echo $counts['asset']; ?> حساب</div>
    </div>
    
    <div class="stat-card type-liability">
        <div class="stat-icon"><i class="bi bi-credit-card"></i></div>
        <div class="stat-label">إجمالي الخصوم</div>
        <div class="stat-value"><?php echo number_format($totals['liability'], 2); ?></div>
        <div class="stat-count"><?php echo $counts['liability']; ?> حساب</div>
    </div>
    
    <div class="stat-card type-equity">
        <div class="stat-icon"><i class="bi bi-bank"></i></div>
        <div class="stat-label">حقوق الملكية</div>
        <div class="stat-value"><?php echo number_format($totals['equity'], 2); ?></div>
        <div class="stat-count"><?php echo $counts['equity']; ?> حساب</div>
    </div>
    
    <div class="stat-card type-revenue">
        <div class="stat-icon"><i class="bi bi-graph-up-arrow"></i></div>
        <div class="stat-label">إجمالي الإيرادات</div>
        <div class="stat-value"><?php echo number_format($totals['revenue'], 2); ?></div>
        <div class="stat-count"><?php echo $counts['revenue']; ?> حساب</div>
    </div>
    
    <div class="stat-card type-expense">
        <div class="stat-icon"><i class="bi bi-graph-down-arrow"></i></div>
        <div class="stat-label">إجمالي المصروفات</div>
        <div class="stat-value"><?php echo number_format($totals['expense'], 2); ?></div>
        <div class="stat-count"><?php echo $counts['expense']; ?> حساب</div>
    </div>
</div>

<!-- ===== رسائل التنبيه ===== -->
<?php if ($msg === 'added'): ?>
    <div class="alert-custom alert-success no-print"><i class="bi bi-check-circle-fill ml-2"></i> تم إضافة الحساب بنجاح إلى شجرة الحسابات.</div>
<?php elseif ($msg === 'updated'): ?>
    <div class="alert-custom alert-success no-print"><i class="bi bi-check-circle-fill ml-2"></i> تم تعديل الحساب وتحديث البيانات بنجاح.</div>
<?php elseif ($msg === 'deleted'): ?>
    <div class="alert-custom alert-success no-print"><i class="bi bi-trash-fill ml-2"></i> تم حذف الحساب المحاسبي من الدليل بنجاح.</div>
<?php elseif ($msg === 'code_exists'): ?>
    <div class="alert-custom alert-warning no-print"><i class="bi bi-exclamation-triangle-fill ml-2"></i> تنبيه: كود الحساب مدخل مسبقاً، يرجى كتابة كود فريد.</div>
<?php elseif ($msg === 'has_children'): ?>
    <div class="alert-custom alert-danger no-print"><i class="bi bi-x-circle-fill ml-2"></i> فشل الحذف: يحتوي هذا الحساب على حسابات فرعية تحته. يرجى حذف الفروع أولاً.</div>
<?php elseif ($msg === 'has_entries'): ?>
    <div class="alert-custom alert-danger no-print"><i class="bi bi-x-circle-fill ml-2"></i> فشل الحذف: الحساب مرتبط بقيود يومية مسجلة سابقاً.</div>
<?php elseif ($msg === 'empty'): ?>
    <div class="alert-custom alert-danger no-print"><i class="bi bi-x-circle-fill ml-2"></i> يرجى ملء الحقول المطلوبة (الكود، الاسم، النوع).</div>
<?php elseif ($msg === 'error'): ?>
    <div class="alert-custom alert-danger no-print"><i class="bi bi-x-circle-fill ml-2"></i> حدث خطأ في النظام أثناء معالجة الطلب.</div>
<?php endif; ?>

<div class="row">
    <!-- ===== شجرة الحسابات (شجرة كاملة العرض) ===== -->
    <div class="col-lg-12 mb-4">
        <div class="toolbar no-print">
            <div class="search-box">
                <i class="bi bi-search"></i>
                <input type="text" id="searchTree" placeholder="ابحث عن حساب بالكود أو الاسم...">
            </div>
            <button type="button" id="addRootBtn" class="btn btn-primary">
                <i class="bi bi-plus-lg"></i> حساب رئيسي
            </button>
        </div>
        
        <div class="account-tree-container">
            <?php
            if (!empty($accounts_by_parent)) {
                echo render_account_tree($accounts_by_parent, 0);
            } else {
                echo '<div class="text-center text-muted py-5"><i class="bi bi-exclamation-circle font-lg d-block mb-2"></i> دليل الحسابات فارغ. يرجى إضافة حساب رئيسي للبدء.</div>';
            }
            ?>
        </div>
        
        <!-- ملخص الحسابات -->
        <div class="accounts-summary no-print">
            <h6><i class="bi bi-info-circle ml-1"></i> ملخص الدليل المحاسبي</h6>
            <div class="row text-right">
                <div class="col-md-3 mb-2">
                    <span class="label">إجمالي عدد الحسابات:</span>
                    <strong class="value text-white mr-1"><?php echo number_format($total_accounts_count); ?></strong>
                </div>
                <div class="col-md-3 mb-2">
                    <span class="label">إجمالي الأصول:</span>
                    <strong class="value text-success mr-1"><?php echo number_format($totals['asset'], 2); ?> ر.ي</strong>
                </div>
                <div class="col-md-3 mb-2">
                    <span class="label">الخصوم + الملكية:</span>
                    <strong class="value text-danger mr-1"><?php echo number_format($totals['liability'] + $totals['equity'], 2); ?> ر.ي</strong>
                </div>
                <div class="col-md-3 mb-2">
                    <span class="label">صافي الميزانية:</span>
                    <strong class="value text-warning mr-1"><?php echo number_format($net_balance, 2); ?> ر.ي</strong>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ===== مودال إضافة وتعديل الحساب ===== -->
<div class="modal fade no-print text-right" id="accountModal" tabindex="-1" role="dialog" aria-labelledby="accountModalLabel" aria-hidden="true" dir="rtl">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content rounded-0">
            <div class="modal-header bg-primary text-white py-3">
                <h6 class="modal-title font-weight-bold" id="formTitle">
                    <i class="bi bi-plus-circle ml-1"></i> إضافة حساب جديد
                </h6>
                <button type="button" class="close text-white rounded p-1" data-dismiss="modal" aria-label="Close" style="background: transparent; border: none; font-size: 1.5rem; line-height: 0.8;">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <form method="POST" id="accountForm">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="id" id="acc_id" value="0">
                    
                    <div class="form-group mb-3">
                        <label class="form-label font-weight-bold text-secondary mb-1">الحساب الأب</label>
                        <input type="hidden" name="parent_id" id="acc_parent_id" value="">
                        <input type="text" id="acc_parent_name" class="form-control bg-light rounded-0" readonly value="حساب رئيسي (جذر)">
                    </div>
                    
                    <div class="form-group mb-3">
                        <label class="form-label font-weight-bold text-secondary mb-1">كود الحساب *</label>
                        <input type="text" name="code" id="acc_code" class="form-control font-weight-bold rounded-0" required placeholder="مثال: 1101002" style="font-family: 'Tajawal', monospace;">
                        <small class="text-muted" id="codeSuggestLabel">كود فريد للحساب</small>
                    </div>
                    
                    <div class="form-group mb-3">
                        <label class="form-label font-weight-bold text-secondary mb-1">اسم الحساب *</label>
                        <input type="text" name="name" id="acc_name" class="form-control font-weight-bold rounded-0" required placeholder="مثال: الصندوق الرئيسي">
                    </div>
                    
                    <div class="form-group mb-3" id="typeGroup">
                        <label class="form-label font-weight-bold text-secondary mb-1">نوع الحساب *</label>
                        <select name="account_type" id="acc_type" class="form-control font-weight-bold rounded-0" required>
                            <option value="asset">أصول (Assets)</option>
                            <option value="liability">خصوم (Liabilities)</option>
                            <option value="equity">حقوق ملكية (Equity)</option>
                            <option value="revenue">إيرادات (Revenues)</option>
                            <option value="expense">مصروفات (Expenses)</option>
                        </select>
                    </div>
                    
                    <div class="form-group mb-3">
                        <label class="form-label font-weight-bold text-secondary mb-1">طبيعة الحساب</label>
                        <div class="radio-group">
                            <div class="radio-option p-2 border mb-1">
                                <input type="radio" id="is_parent_yes" name="is_parent" value="1">
                                <label for="is_parent_yes" class="mr-2 mb-0" style="cursor:pointer;">رئيسي / مجلد (يحتوي حسابات فرعية)</label>
                            </div>
                            <div class="radio-option p-2 border">
                                <input type="radio" id="is_parent_no" name="is_parent" value="0" checked>
                                <label for="is_parent_no" class="mr-2 mb-0" style="cursor:pointer;">فرعي / تحليلي (تُرحل عليه القيود)</label>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-group mb-3">
                        <label class="form-label font-weight-bold text-secondary mb-1">ملاحظات / وصف</label>
                        <textarea name="notes" id="acc_notes" class="form-control rounded-0" rows="2" placeholder="وصف مختصر للحساب..."></textarea>
                    </div>
                    
                    <div class="text-left mt-3">
                        <button type="button" class="btn btn-secondary btn-sm rounded-0 ml-2" data-dismiss="modal">إلغاء</button>
                        <button type="submit" class="btn btn-success btn-sm rounded-0" id="submitFormBtn">
                            <i class="bi bi-check-lg ml-1"></i> حفظ الحساب
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- مودال تأكيد الحذف -->
<div class="modal fade" id="deleteConfirmModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content rounded-0">
            <div class="modal-header bg-danger text-white py-3">
                <h5 class="modal-title font-weight-bold"><i class="bi bi-exclamation-triangle-fill ml-2"></i> تأكيد حذف الحساب</h5>
                <button type="button" class="close text-white" data-dismiss="modal">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body py-4">
                <p class="font-weight-bold text-secondary">هل أنت متأكد من حذف الحساب التالي؟</p>
                <div class="p-3 bg-light border mb-2">
                    <div><strong>الكود:</strong> <span id="del_code_display" style="font-family: monospace;" class="text-primary"></span></div>
                    <div><strong>الاسم:</strong> <span id="del_name_display" class="text-danger"></span></div>
                </div>
                <small class="text-muted"><i class="bi bi-info-circle ml-1"></i> لا يمكن حذف الحساب إذا كان يحتوي على حسابات فرعية أو قيود يومية مرتبطة.</small>
            </div>
            <div class="modal-footer">
                <form method="POST" id="deleteForm" class="w-100 d-flex">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" id="delete_acc_id" value="">
                    <button type="button" class="btn btn-secondary w-50 ml-2" data-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-danger w-50 font-weight-bold">تأكيد الحذف</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once($dir_prefix . 'includes/footer.php'); ?>

<script type="text/javascript">
$(document).ready(function() {
    // ===== توسيع وطي الفروع =====
    $('.toggle-icon').click(function(e) {
        e.stopPropagation();
        var node = $(this).closest('.account-node');
        var branch = node.find('> .account-tree-branch');
        
        if (branch.hasClass('collapsed')) {
            branch.removeClass('collapsed');
            $(this).removeClass('collapsed');
        } else {
            branch.addClass('collapsed');
            $(this).addClass('collapsed');
        }
    });

    $('#expandAll').click(function() {
        $('.account-tree-branch').removeClass('collapsed');
        $('.toggle-icon').removeClass('collapsed');
    });

    $('#collapseAll').click(function() {
        $('.account-tree-branch').addClass('collapsed');
        $('.toggle-icon').addClass('collapsed');
        $('.account-tree-container > .account-tree-branch').removeClass('collapsed');
    });

    // ===== البحث =====
    $('#searchTree').on('input', function() {
        var query = $(this).val().trim().toLowerCase();
        if (query === '') {
            $('.account-node').show();
            return;
        }

        $('.account-node').hide();
        
        $('.account-node').each(function() {
            var name = $(this).data('name').toString().toLowerCase();
            var code = $(this).data('code').toString().toLowerCase();
            
            if (name.indexOf(query) !== -1 || code.indexOf(query) !== -1) {
                $(this).show();
                $(this).parents('.account-node').show();
                $(this).parents('.account-tree-branch').removeClass('collapsed');
                $(this).parents('.account-node').find('> .node-content .toggle-icon').removeClass('collapsed');
            }
        });
    });

    // ===== اقتراح الكود التالي =====
    function suggestNextCode(parentCode, parentId) {
        var childCodes = [];
        $('.account-node[data-id="' + parentId + '"] > .account-tree-branch > .account-node').each(function() {
            childCodes.push($(this).data('code').toString());
        });
        
        if (childCodes.length > 0) {
            childCodes.sort();
            var maxCode = childCodes[childCodes.length - 1];
            var numPart = maxCode.substring(parentCode.length);
            var nextNum = parseInt(numPart) + 1;
            var paddedNum = nextNum.toString().padStart(numPart.length, '0');
            return parentCode + paddedNum;
        } else {
            if (parentCode.length <= 2) {
                return parentCode + '01';
            } else {
                return parentCode + '001';
            }
        }
    }

    // ===== إعادة تعيين النموذج =====
    function resetForm() {
        $('#formTitle').html('<i class="bi bi-plus-circle ml-1"></i> إضافة حساب جديد');
        $('#acc_id').val('0');
        $('#acc_parent_id').val('');
        $('#acc_parent_name').val('حساب رئيسي (جذر)');
        $('#acc_code').val('');
        $('#acc_name').val('');
        $('#acc_type').val('asset').prop('disabled', false);
        $('#is_parent_no').prop('checked', true);
        $('#acc_notes').val('');
        $('#resetFormBtn').hide();
        $('#typeGroup').show();
        $('#codeSuggestLabel').text('كود فريد للحساب');
        $('#submitFormBtn').html('<i class="bi bi-check-lg ml-1"></i> حفظ الحساب');
    }

    $('#resetFormBtn').click(function() {
        resetForm();
    });

    // ===== إضافة حساب رئيسي =====
    $('#addRootBtn').click(function() {
        resetForm();
        var rootCodes = [];
        $('.account-tree-container > .account-tree-branch > .account-node').each(function() {
            rootCodes.push(parseInt($(this).data('code')));
        });
        if (rootCodes.length > 0) {
            var nextRoot = Math.max.apply(Math, rootCodes) + 1;
            $('#acc_code').val(nextRoot);
        } else {
            $('#acc_code').val('1');
        }
        $('#formTitle').html('<i class="bi bi-plus-circle ml-1"></i> إضافة حساب رئيسي');
        $('#resetFormBtn').show();
        $('#accountModal').modal('show');
    });

    // ===== إضافة حساب فرعي =====
    $(document).on('click', '.add-child-btn', function(e) {
        e.stopPropagation();
        resetForm();
        
        var parentNode = $(this).closest('.account-node');
        var parentId = parentNode.data('id');
        var parentCode = parentNode.data('code').toString();
        var parentName = parentNode.data('name');
        var parentType = parentNode.data('type');
        
        $('#acc_parent_id').val(parentId);
        $('#acc_parent_name').val(parentCode + ' - ' + parentName);
        $('#acc_type').val(parentType);
        
        var suggestedCode = suggestNextCode(parentCode, parentId);
        $('#acc_code').val(suggestedCode);
        
        $('#formTitle').html('<i class="bi bi-plus-circle ml-1"></i> إضافة حساب فرعي تحت: ' + parentName);
        $('#codeSuggestLabel').html('<span class="text-success font-weight-bold">الكود المقترح: ' + suggestedCode + '</span>');
        $('#resetFormBtn').show();
        $('#accountModal').modal('show');
    });

    // ===== تعديل الحساب =====
    $(document).on('click', '.edit-btn', function(e) {
        e.stopPropagation();
        resetForm();
        
        var node = $(this).closest('.account-node');
        var id = node.data('id');
        var code = node.data('code');
        var name = node.data('name');
        var type = node.data('type');
        var isParent = node.data('is-parent');
        var notes = node.data('notes');
        
        $('#acc_id').val(id);
        $('#acc_code').val(code);
        $('#acc_name').val(name);
        $('#acc_type').val(type);
        $('#acc_notes').val(notes);
        
        if (isParent == 1) {
            $('#is_parent_yes').prop('checked', true);
        } else {
            $('#is_parent_no').prop('checked', true);
        }
        
        var parentNode = node.parents('.account-node').first();
        if (parentNode.length > 0) {
            $('#acc_parent_id').val(parentNode.data('id'));
            $('#acc_parent_name').val(parentNode.data('code') + ' - ' + parentNode.data('name'));
        }
        
        $('#formTitle').html('<i class="bi bi-pencil ml-1"></i> تعديل: ' + name);
        $('#submitFormBtn').html('<i class="bi bi-save ml-1"></i> حفظ التعديلات');
        $('#resetFormBtn').show();
        $('#accountModal').modal('show');
    });

    // ===== حذف الحساب =====
    $(document).on('click', '.delete-btn', function(e) {
        e.stopPropagation();
        var node = $(this).closest('.account-node');
        var id = node.data('id');
        var code = node.data('code');
        var name = node.data('name');
        
        $('#delete_acc_id').val(id);
        $('#del_code_display').text(code);
        $('#del_name_display').text(name);
        
        $('#deleteConfirmModal').modal('show');
    });

    $('#accountForm').submit(function() {
        $('#acc_type').prop('disabled', false);
    });
});
</script>
</div><!-- end .page-inner -->
<?php require_once($dir_prefix . 'includes/footer.php'); ?>