<?php
$dir_prefix = '../';
$module = 'trial_balance';
require_once($dir_prefix . 'includes/header.php');
check_permission(['admin']);

use AQNEX\Services\Accounting\AccountTreeService;

$filterFrom = $_GET['from_date'] ?? null;
$filterTo   = $_GET['to_date']   ?? null;

$rows = AccountTreeService::getTrialBalance($conn, $filterFrom, $filterTo);

$grandDebit  = 0.0;
$grandCredit = 0.0;
foreach ($rows as $r) { $grandDebit += $r['total_debit']; $grandCredit += $r['total_credit']; }
$isBalanced    = abs($grandDebit - $grandCredit) < 0.001;
$totalDebitBal = 0.0; $totalCreditBal = 0.0;
foreach ($rows as $r) {
    $b = $r['balance'];
    if ($b >= 0) $totalDebitBal  += $b;
    else         $totalCreditBal += abs($b);
}

// Group by account type
$groups = ['asset' => [], 'liability' => [], 'equity' => [], 'revenue' => [], 'expense' => []];
foreach ($rows as $r) {
    if (isset($groups[$r['account_type']])) $groups[$r['account_type']][] = $r;
}
$typeLabels = ['asset'=>'الأصول','liability'=>'الخصوم','equity'=>'حقوق الملكية','revenue'=>'الإيرادات','expense'=>'المصروفات'];
$typeColors = ['asset'=>'#059669','liability'=>'#dc2626','equity'=>'#b45309','revenue'=>'#2563eb','expense'=>'#7c3aed'];
$typeIcons  = ['asset'=>'bi-bank2','liability'=>'bi-credit-card','equity'=>'bi-person-badge','revenue'=>'bi-graph-up','expense'=>'bi-graph-down'];
?>
<title>ميزان المراجعة - AQNEX</title>
<style>
/* ─── متوافق مع custom.css الخاص بالنظام ─── */
.page-inner { padding: 16px 20px; }

.page-title-bar {
    display: flex; align-items: center; justify-content: space-between;
    padding: 10px 0 14px; border-bottom: 2px solid #1e40af; margin-bottom: 16px;
    flex-wrap: wrap; gap: 10px;
}
.page-title-bar .ptb-left { display: flex; align-items: center; gap: 10px; }
.page-title-bar .icon-wrap {
    width: 34px; height: 34px;
    background: #1e40af; color: #fff;
    display: flex; align-items: center; justify-content: center; font-size: 1rem;
}
.page-title-bar h4 { margin: 0; font-size: 0.95rem; font-weight: 700; color: var(--text-color); }
.page-title-bar small { font-size: 0.72rem; color: #64748b; display: block; }

/* Filter Panel */
.filter-panel {
    background: #fff; border: 1px solid var(--border-color);
    padding: 12px 16px; margin-bottom: 14px;
    display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-end;
}
.filter-panel label { font-size: 0.7rem; font-weight: 700; color: #475569; display: block; margin-bottom: 3px; text-transform: uppercase; }
.filter-panel input[type=date] {
    padding: 6px 10px; border: 1px solid var(--border-color);
    font-size: 0.8rem; background: #fff; color: var(--text-color);
    font-family: 'Tajawal', sans-serif;
}
.filter-panel input[type=date]:focus { outline: none; border-color: #1e40af; }
.btn-filter {
    padding: 7px 18px; background: #1e40af; color: #fff;
    border: none; font-size: 0.8rem; font-weight: 700;
    font-family: 'Tajawal', sans-serif; cursor: pointer;
    display: flex; align-items: center; gap: 6px;
}
.btn-filter:hover { background: #1e3a8a; }
.btn-reset-filter {
    padding: 7px 14px; background: #f1f5f9; border: 1px solid var(--border-color);
    color: #475569; font-size: 0.78rem; font-weight: 600; cursor: pointer;
    font-family: 'Tajawal', sans-serif; text-decoration: none;
    display: flex; align-items: center; gap: 5px;
}
.btn-reset-filter:hover { background: #e2e8f0; color: #475569; }

/* Balance Banner */
.balance-banner {
    padding: 10px 16px; margin-bottom: 14px;
    display: flex; align-items: center; gap: 10px;
    font-size: 0.82rem; font-weight: 700; border: 1px solid;
}
.balance-banner.balanced   { background: #f0fdf4; border-color: #6ee7b7; color: #065f46; }
.balance-banner.unbalanced { background: #fff1f2; border-color: #fca5a5; color: #9f1239; }

/* Summary Cards */
.summary-grid { display: grid; grid-template-columns: repeat(5,1fr); gap: 10px; margin-bottom: 14px; }
@media(max-width:900px){ .summary-grid{ grid-template-columns: repeat(2,1fr); } }
@media(max-width:560px){ .summary-grid{ grid-template-columns: 1fr; } }
.summary-card {
    background: #fff; border: 1px solid var(--border-color);
    padding: 12px; text-align: center;
}
.summary-card .s-icon  { font-size: 1.2rem; margin-bottom: 4px; }
.summary-card .s-label { font-size: 0.66rem; color: #64748b; font-weight: 700; margin-bottom: 3px; text-transform: uppercase; }
.summary-card .s-val   { font-size: 0.88rem; font-weight: 800; }
.summary-card small    { font-size: 0.66rem; color: #94a3b8; }

/* Type Card */
.type-card { background: #fff; border: 1px solid var(--border-color); margin-bottom: 12px; }
.type-card-header {
    padding: 8px 14px; color: #fff;
    display: flex; align-items: center; justify-content: space-between;
    font-size: 0.82rem; font-weight: 700;
}
.type-card-header small { font-size: 0.7rem; opacity: 0.85; font-weight: 400; }

/* Table */
.sys-table { width: 100%; border-collapse: collapse; font-size: 0.79rem; }
.sys-table thead th {
    background: #1e293b; color: #e2e8f0;
    padding: 8px 12px; text-align: right; font-weight: 600;
    white-space: nowrap;
}
.sys-table thead th.th-debit  { background: #059669; text-align: center; }
.sys-table thead th.th-credit { background: #dc2626; text-align: center; }
.sys-table thead th.th-bal    { background: #6d28d9; text-align: center; }
.sys-table tbody tr { border-bottom: 1px solid #f1f5f9; }
.sys-table tbody tr:hover { background: #f8fafc; }
.sys-table tbody td { padding: 8px 12px; }
.sys-table tfoot td {
    padding: 9px 12px; font-weight: 800;
    border-top: 2px solid var(--border-color); background: #f8fafc;
}
.code-chip { font-family: monospace; font-size: 0.72rem; background: #eff6ff; color: #1e40af; padding: 1px 5px; }
.debit-val  { color: #059669; font-weight: 700; text-align: center; }
.credit-val { color: #dc2626; font-weight: 700; text-align: center; }
.bal-dr { color: #1e40af; font-weight: 800; text-align: center; }
.bal-cr { color: #dc2626; font-weight: 800; text-align: center; }
.zero-val { color: #94a3b8; text-align: center; }

/* Grand total */
.grand-row { background: #1e293b !important; }
.grand-row td { color: #fff !important; padding: 10px 12px !important; font-weight: 800 !important; font-size: 0.85rem !important; border-top: none !important; }

/* Action buttons */
.ptb-actions { display: flex; gap: 8px; }
.btn-print {
    padding: 6px 14px; background: #f1f5f9; border: 1px solid var(--border-color);
    color: #475569; font-size: 0.78rem; font-weight: 700; cursor: pointer;
    font-family: 'Tajawal', sans-serif; display: flex; align-items: center; gap: 5px;
}
.btn-print:hover { background: #e2e8f0; }

@media print {
    .filter-panel, .page-title-bar .ptb-actions, nav, #sidebar { display: none !important; }
    .balance-banner, .summary-grid { break-inside: avoid; }
}
</style>

<div class="page-inner">

    <!-- HEADER -->
    <div class="page-title-bar">
        <div class="ptb-left">
            <div class="icon-wrap"><i class="bi bi-bar-chart-steps"></i></div>
            <div>
                <h4>ميزان المراجعة</h4>
                <small>كشف إجمالي المدين والدائن — يجب أن يتساوى الإجمالان</small>
            </div>
        </div>
        <div class="ptb-actions">
            <button class="btn-print" onclick="window.print()"><i class="bi bi-printer"></i> طباعة</button>
        </div>
    </div>

    <!-- FILTER -->
    <div class="filter-panel">
        <form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;width:100%;">
            <div>
                <label>من تاريخ</label>
                <input type="date" name="from_date" value="<?= $filterFrom ?? '' ?>">
            </div>
            <div>
                <label>إلى تاريخ</label>
                <input type="date" name="to_date" value="<?= $filterTo ?? '' ?>">
            </div>
            <button type="submit" class="btn-filter"><i class="bi bi-search"></i> عرض الميزان</button>
            <a href="trial_balance.php" class="btn-reset-filter"><i class="bi bi-arrow-counterclockwise"></i> عرض الكل</a>
        </form>
    </div>

    <?php if (!empty($rows)): ?>

    <!-- Balance Status -->
    <div class="balance-banner <?= $isBalanced ? 'balanced' : 'unbalanced' ?>">
        <i class="bi <?= $isBalanced ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill' ?>" style="font-size:1.2rem;"></i>
        <div>
            <div><?= $isBalanced ? '✓ الميزان متوازن — إجمالي المدين يساوي إجمالي الدائن' : '⚠ الميزان غير متوازن — يوجد فرق يجب مراجعته' ?></div>
            <?php if (!$isBalanced): ?>
            <div style="font-size:0.72rem;font-weight:400;margin-top:2px;">الفرق: <?= number_format(abs($grandDebit - $grandCredit), 4) ?></div>
            <?php endif; ?>
        </div>
        <div style="margin-right:auto;display:flex;gap:20px;">
            <span>المدين: <strong><?= number_format($grandDebit, 2) ?></strong></span>
            <span>الدائن: <strong><?= number_format($grandCredit, 2) ?></strong></span>
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="summary-grid">
        <?php foreach ($groups as $type => $grpRows): ?>
        <div class="summary-card">
            <div class="s-icon" style="color:<?= $typeColors[$type] ?>;"><i class="bi <?= $typeIcons[$type] ?>"></i></div>
            <div class="s-label"><?= $typeLabels[$type] ?></div>
            <?php $sum = array_sum(array_column($grpRows, 'balance')); ?>
            <div class="s-val" style="color:<?= $typeColors[$type] ?>;"><?= number_format(abs($sum), 2) ?></div>
            <small><?= count($grpRows) ?> حساب</small>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Grouped Tables -->
    <?php foreach ($groups as $type => $grpRows): if (empty($grpRows)) continue; ?>
    <div class="type-card">
        <div class="type-card-header" style="background:<?= $typeColors[$type] ?>;">
            <span><i class="bi <?= $typeIcons[$type] ?>"></i> <?= $typeLabels[$type] ?></span>
            <small><?= count($grpRows) ?> حساب</small>
        </div>
        <div style="overflow-x:auto;">
            <table class="sys-table">
                <thead>
                    <tr>
                        <th>كود</th>
                        <th>اسم الحساب</th>
                        <th class="th-debit">إجمالي المدين</th>
                        <th class="th-credit">إجمالي الدائن</th>
                        <th class="th-bal">الرصيد</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($grpRows as $r): $b = $r['balance']; ?>
                <tr>
                    <td><span class="code-chip"><?= $r['code'] ?></span></td>
                    <td><?= htmlspecialchars($r['name']) ?></td>
                    <td class="debit-val"><?= $r['total_debit'] > 0 ? number_format($r['total_debit'], 2) : '<span class="zero-val">—</span>' ?></td>
                    <td class="credit-val"><?= $r['total_credit'] > 0 ? number_format($r['total_credit'], 2) : '<span class="zero-val">—</span>' ?></td>
                    <td class="<?= $b >= 0 ? 'bal-dr' : 'bal-cr' ?>">
                        <?= number_format(abs($b), 2) ?>
                        <small style="font-size:0.65rem;"><?= $b >= 0 ? 'مد' : 'دا' ?></small>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <?php
                    $gd = array_sum(array_column($grpRows, 'total_debit'));
                    $gc = array_sum(array_column($grpRows, 'total_credit'));
                    $gb = array_sum(array_column($grpRows, 'balance'));
                    ?>
                    <tr>
                        <td colspan="2">مجموع <?= $typeLabels[$type] ?></td>
                        <td class="debit-val"><?= number_format($gd, 2) ?></td>
                        <td class="credit-val"><?= number_format($gc, 2) ?></td>
                        <td class="<?= $gb >= 0 ? 'bal-dr' : 'bal-cr' ?>"><?= number_format(abs($gb), 2) ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
    <?php endforeach; ?>

    <!-- Grand Total -->
    <div class="type-card">
        <table class="sys-table">
            <tfoot>
                <tr class="grand-row">
                    <td colspan="2">الإجمالي الكلي</td>
                    <td style="text-align:center;"><?= number_format($grandDebit, 2) ?></td>
                    <td style="text-align:center;"><?= number_format($grandCredit, 2) ?></td>
                    <td style="text-align:center;">
                        <?php $diff = $grandDebit - $grandCredit; ?>
                        <?= number_format(abs($diff), 2) ?>
                        <?= $isBalanced ? ' ✓' : (' ⚠ ' . ($diff > 0 ? 'مدين' : 'دائن')) ?>
                    </td>
                </tr>
            </tfoot>
        </table>
    </div>

    <?php else: ?>
    <div class="type-card">
        <div style="text-align:center;padding:60px;color:#94a3b8;">
            <i class="bi bi-bar-chart-steps" style="font-size:2.5rem;opacity:.3;display:block;margin-bottom:12px;"></i>
            <h4 style="margin:0 0 8px;font-size:1rem;color:#64748b;">لا توجد بيانات لعرضها</h4>
            <p style="font-size:0.82rem;margin:0;">لم يتم ترحيل أي قيود يومية بعد. ابدأ بإضافة قيود من صفحة قيود اليومية.</p>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php require_once($dir_prefix . 'includes/footer.php'); ?>
