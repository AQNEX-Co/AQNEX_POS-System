<?php
$dir_prefix = '../';
$module = 'ledger';
require_once($dir_prefix . 'includes/header.php');
check_permission(['admin']);

use AQNEX\Services\Accounting\AccountTreeService;

$allAccounts = AccountTreeService::getAllAccounts($conn);

// Filters from GET
$filterAccountId = isset($_GET['account_id']) ? intval($_GET['account_id']) : 0;
$filterFrom      = $_GET['from_date'] ?? date('Y-m-01');
$filterTo        = $_GET['to_date']   ?? date('Y-m-d');

$movements = [];
$selectedAccount = null;
$openingBalance  = 0.0;

if ($filterAccountId > 0) {
    // Get account info
    foreach ($allAccounts as $a) {
        if ($a['id'] == $filterAccountId) { $selectedAccount = $a; break; }
    }
    // Opening balance = all movements BEFORE from_date
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(ji.debit),0) AS total_debit, COALESCE(SUM(ji.credit),0) AS total_credit
        FROM accounting_journal_items ji
        JOIN accounting_journal_entries je ON je.id = ji.entry_id
        WHERE ji.account_id = ? AND je.status = 'posted' AND je.entry_date < ?
    ");
    if ($stmt) {
        $stmt->bind_param('is', $filterAccountId, $filterFrom);
        $stmt->execute();
        $ob = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $isDebitNormal = in_array($selectedAccount['account_type'] ?? 'asset', ['asset', 'expense']);
        $openingBalance = $isDebitNormal
            ? (float)$ob['total_debit'] - (float)$ob['total_credit']
            : (float)$ob['total_credit'] - (float)$ob['total_debit'];
    }
    $movements = AccountTreeService::getAccountMovements($conn, $filterAccountId, $filterFrom, $filterTo);
}

// Totals for the period
$periodDebit  = array_sum(array_column($movements, 'debit'));
$periodCredit = array_sum(array_column($movements, 'credit'));
$closingBalance = $openingBalance + ($selectedAccount && in_array($selectedAccount['account_type'], ['asset','expense'])
    ? $periodDebit - $periodCredit
    : $periodCredit - $periodDebit);
?>
<title>دفتر الأستاذ العام - AQNEX</title>
<style>
:root{
    --lg-primary:#1e40af; --lg-accent:#3b82f6; --lg-debit:#059669;
    --lg-credit:#dc2626; --lg-bg:#f1f5f9; --lg-card:#fff;
    --lg-border:#e2e8f0; --lg-text:#1e293b; --lg-m.page-inner { padding: 16px 20px; }

/* ===== ترويسة الصفحة الموحدة ===== */
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
.page-title-bar .ptb-actions { display: flex; gap: 8px; }

/* Filter bar */
.filter-panel { background:var(--lg-card); border:1px solid var(--lg-border); border-radius:12px; padding:18px 22px; margin-bottom:20px; box-shadow:0 1px 6px rgba(0,0,0,.04); }
.filter-form  { display:flex; gap:14px; flex-wrap:wrap; align-items:flex-end; }
.filter-group { display:flex; flex-direction:column; gap:4px; }
.filter-group label { font-size:.73rem; font-weight:700; color:var(--lg-muted); }
.filter-group select, .filter-group input {
    padding:8px 12px; border:1.5px solid var(--lg-border);
    border-radius:7px; font-size:.83rem; background:#fff; color:var(--lg-text); min-width:180px;
}
.filter-group select:focus, .filter-group input:focus { outline:none; border-color:var(--lg-accent); }
.filter-btn { padding:9px 22px; background:linear-gradient(135deg,#1e40af,#3b82f6); color:#fff; border:none; border-radius:7px; font-size:.85rem; font-weight:700; cursor:pointer; transition:all .2s; display:flex; align-items:center; gap:6px; }
.filter-btn:hover { transform:translateY(-1px); box-shadow:0 4px 12px rgba(59,130,246,.4); }

/* Summary cards */
.summary-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:14px; margin-bottom:20px; }
@media(max-width:768px){ .summary-grid{ grid-template-columns:repeat(2,1fr); } }
.summary-card { background:var(--lg-card); border-radius:10px; padding:16px; border:1px solid var(--lg-border); text-align:center; }
.summary-card .s-label { font-size:.72rem; color:var(--lg-muted); font-weight:700; margin-bottom:6px; }
.summary-card .s-amount { font-size:1.1rem; font-weight:800; }
.summary-card.opening  { border-top:3px solid #f59e0b; }
.summary-card.debit    { border-top:3px solid var(--lg-debit); }
.summary-card.credit   { border-top:3px solid var(--lg-credit); }
.summary-card.closing  { border-top:3px solid var(--lg-primary); }
.opening .s-amount  { color:#92400e; }
.debit   .s-amount  { color:var(--lg-debit); }
.credit  .s-amount  { color:var(--lg-credit); }
.closing .s-amount  { color:var(--lg-primary); }

/* Ledger card */
.lg-card { background:var(--lg-card); border:1px solid var(--lg-border); border-radius:12px; box-shadow:0 1px 8px rgba(0,0,0,.05); overflow:hidden; }
.lg-card-header { padding:14px 20px; background:linear-gradient(135deg,#1e3a5f,#1e40af); color:#fff; display:flex; align-items:center; justify-content:space-between; }
.lg-card-header h3 { margin:0; font-size:.95rem; font-weight:700; }

/* Ledger table */
.ledger-table { width:100%; border-collapse:collapse; font-size:.83rem; }
.ledger-table thead th { background:#1e40af; color:#fff; padding:11px 14px; text-align:right; font-weight:600; white-space:nowrap; }
.ledger-table thead th.debit-col  { background:#059669; }
.ledger-table thead th.credit-col { background:#dc2626; }
.ledger-table thead th.balance-col{ background:#7c3aed; }
.ledger-table tbody tr { border-bottom:1px solid #f1f5f9; transition:background .15s; }
.ledger-table tbody tr:hover { background:#eff6ff; }
.ledger-table tbody td { padding:10px 14px; vertical-align:middle; }
.ledger-table tfoot td { padding:11px 14px; font-weight:800; background:#f8fafc; border-top:2px solid var(--lg-border); }
.debit-amount  { color:var(--lg-debit); font-weight:700; }
.credit-amount { color:var(--lg-credit); font-weight:700; }
.balance-positive { color:#1e40af; font-weight:800; }
.balance-negative { color:#dc2626; font-weight:800; }
.source-tag { background:#eff6ff; color:#1e40af; padding:2px 8px; border-radius:4px; font-size:.7rem; font-family:monospace; }
.no-data { text-align:center; padding:60px; color:var(--lg-muted); }

/* Print */
@media print {
    .filter-panel, .page-title-bar .ptb-actions, nav, #sidebar { display:none !important; }
    .page-inner { padding:0; }
}
</style>

<div class="page-inner">
    <div class="page-title-bar no-print">
        <div class="ptb-left">
            <div class="icon-wrap"><i class="bi bi-folder2-open"></i></div>
            <div>
                <h4>دفتر الأستاذ العام</h4>
                <small>عرض كشف الحساب التفصيلي مع الأرصدة الجارية التراكمية</small>
            </div>
        </div>
        <div class="ptb-actions">
            <button class="btn btn-sm btn-light" onclick="window.print()" style="font-size: 0.8rem; border: 1px solid #cbd5e1;">
                <i class="bi bi-printer"></i> طباعة
            </button>
            <a href="../home.php" class="btn btn-sm btn-light text-decoration-none" style="font-size: 0.8rem; border: 1px solid #cbd5e1;">
                <i class="bi bi-arrow-left"></i> عودة
            </a>
        </div>
    </div>

    <!-- Filter Panel -->
    <div class="filter-panel">
        <form method="GET" class="filter-form">
            <div class="filter-group">
                <label>الحساب</label>
                <select name="account_id" id="account_filter" required>
                    <option value="">-- اختر الحساب --</option>
                    <?php foreach ($allAccounts as $a):
                        $indent = str_repeat('— ', max(0, $a['level'] - 1));
                        $disabled = $a['is_parent'] ? 'disabled style="color:#aaa;"' : '';
                        $sel = $a['id'] == $filterAccountId ? 'selected' : '';
                    ?>
                    <option value="<?= $a['id'] ?>" <?= $disabled ?> <?= $sel ?>>
                        <?= $indent ?>[<?= $a['code'] ?>] <?= htmlspecialchars($a['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group">
                <label>من تاريخ</label>
                <input type="date" name="from_date" value="<?= $filterFrom ?>">
            </div>
            <div class="filter-group">
                <label>إلى تاريخ</label>
                <input type="date" name="to_date" value="<?= $filterTo ?>">
            </div>
            <button type="submit" class="filter-btn">
                <i class="bi bi-search"></i> عرض
            </button>
        </form>
    </div>

    <?php if ($filterAccountId > 0 && $selectedAccount): ?>

    <!-- Summary Cards -->
    <div class="summary-grid">
        <div class="summary-card opening">
            <div class="s-label">الرصيد الافتتاحي</div>
            <div class="s-amount"><?= number_format(abs($openingBalance), 2) ?></div>
            <small style="color:var(--lg-muted);"><?= $openingBalance >= 0 ? 'مدين' : 'دائن' ?></small>
        </div>
        <div class="summary-card debit">
            <div class="s-label">إجمالي المدين (الفترة)</div>
            <div class="s-amount"><?= number_format($periodDebit, 2) ?></div>
        </div>
        <div class="summary-card credit">
            <div class="s-label">إجمالي الدائن (الفترة)</div>
            <div class="s-amount"><?= number_format($periodCredit, 2) ?></div>
        </div>
        <div class="summary-card closing">
            <div class="s-label">الرصيد الختامي</div>
            <div class="s-amount"><?= number_format(abs($closingBalance), 2) ?></div>
            <small style="color:var(--lg-muted);"><?= $closingBalance >= 0 ? 'مدين' : 'دائن' ?></small>
        </div>
    </div>

    <!-- Ledger Table -->
    <div class="lg-card">
        <div class="lg-card-header">
            <h3>
                <i class="bi bi-book"></i>
                دفتر الأستاذ — [<?= $selectedAccount['code'] ?>] <?= htmlspecialchars($selectedAccount['name']) ?>
            </h3>
            <small style="opacity:.8;"><?= $filterFrom ?> → <?= $filterTo ?></small>
        </div>
        <div style="overflow-x:auto;">
            <table class="ledger-table">
                <thead>
                    <tr>
                        <th>التاريخ</th>
                        <th>المرجع</th>
                        <th>البيان</th>
                        <th>المصدر</th>
                        <th class="debit-col" style="text-align:center;">مدين</th>
                        <th class="credit-col" style="text-align:center;">دائن</th>
                        <th class="balance-col" style="text-align:center;">الرصيد الجاري</th>
                    </tr>
                </thead>
                <tbody>
                    <!-- Opening balance row -->
                    <tr style="background:#fffbeb;">
                        <td><em><?= $filterFrom ?></em></td>
                        <td colspan="3" style="color:#92400e;font-style:italic;font-weight:600;">رصيد افتتاحي</td>
                        <td style="text-align:center;">—</td>
                        <td style="text-align:center;">—</td>
                        <td style="text-align:center;">
                            <span class="<?= $openingBalance >= 0 ? 'balance-positive' : 'balance-negative' ?>">
                                <?= number_format(abs($openingBalance), 2) ?>
                                <small><?= $openingBalance >= 0 ? 'مد' : 'دا' ?></small>
                            </span>
                        </td>
                    </tr>
                    <?php if (empty($movements)): ?>
                    <tr><td colspan="7" class="no-data">لا توجد حركات في هذه الفترة</td></tr>
                    <?php else: foreach ($movements as $mv): ?>
                    <tr>
                        <td><?= $mv['entry_date'] ?></td>
                        <td><span class="source-tag"><?= htmlspecialchars($mv['reference_no']) ?></span></td>
                        <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= htmlspecialchars($mv['entry_desc'] . ' ' . $mv['memo']) ?>">
                            <?= htmlspecialchars($mv['entry_desc']) ?>
                            <?php if ($mv['memo']): ?><small style="color:var(--lg-muted);"> — <?= htmlspecialchars($mv['memo']) ?></small><?php endif; ?>
                        </td>
                        <td><small class="source-tag"><?= $mv['source_type'] ?></small></td>
                        <td style="text-align:center;">
                            <?php if ($mv['debit'] > 0): ?>
                            <span class="debit-amount"><?= number_format($mv['debit'], 2) ?></span>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td style="text-align:center;">
                            <?php if ($mv['credit'] > 0): ?>
                            <span class="credit-amount"><?= number_format($mv['credit'], 2) ?></span>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td style="text-align:center;">
                            <?php $rb = $openingBalance + $mv['running_balance']; ?>
                            <span class="<?= $rb >= 0 ? 'balance-positive' : 'balance-negative' ?>">
                                <?= number_format(abs($rb), 2) ?>
                                <small><?= $rb >= 0 ? 'مد' : 'دا' ?></small>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="4" style="font-weight:800;">الإجمالي</td>
                        <td style="text-align:center;color:var(--lg-debit);font-weight:800;"><?= number_format($periodDebit, 2) ?></td>
                        <td style="text-align:center;color:var(--lg-credit);font-weight:800;"><?= number_format($periodCredit, 2) ?></td>
                        <td style="text-align:center;">
                            <span class="<?= $closingBalance >= 0 ? 'balance-positive' : 'balance-negative' ?>">
                                <?= number_format(abs($closingBalance), 2) ?>
                                <small><?= $closingBalance >= 0 ? 'مد' : 'دا' ?></small>
                            </span>
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <?php else: ?>
    <div class="lg-card">
        <div style="text-align:center;padding:80px;color:var(--lg-muted);">
            <i class="bi bi-folder2-open" style="font-size:3rem;opacity:.3;"></i>
            <h4 style="margin:16px 0 8px;font-size:1rem;">اختر حساباً لعرض حركاته</h4>
            <p style="font-size:.83rem;">اختر الحساب والفترة الزمنية من الفلتر أعلاه، ثم اضغط "عرض"</p>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php require_once($dir_prefix . 'includes/footer.php'); ?>
