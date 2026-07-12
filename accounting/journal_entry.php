<?php
$dir_prefix = '../';
$module = 'journal_entry';
require_once($dir_prefix . 'includes/header.php');
check_permission(['admin']);

use AQNEX\Services\Accounting\AccountTreeService;

$accounts = AccountTreeService::getLedgerAccounts($conn);
$entries  = \AQNEX\Services\Accounting\JournalService::listEntries($conn, [], 50);
?>
<title>قيود اليومية المزدوجة - AQNEX</title>
<style>
:root {
    --je-primary: #1e40af;
    --je-accent:  #3b82f6;
    --je-success: #10b981;
    --je-danger:  #ef4444;
    --je-warn:    #f59e0b;
    --je-bg:      #f1f5f9;
    --je-card:    #ffffff;
    --je-border:  #e2e8f0;
    --je-text:    #1e293b;
    --je-muted:   #64748b;
}
.page-inner { padding: 16px 20px; }

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

/* Grid cards */
.je-grid-2 { display:grid; grid-template-columns:1fr 1fr; gap:20px; }
@media(max-width:900px){ .je-grid-2 { grid-template-columns:1fr; } }

/* Card */
.je-card { background:var(--je-card); border:1px solid var(--je-border); border-radius:12px; box-shadow:0 1px 8px rgba(0,0,0,.05); overflow:hidden; }
.je-card-header { padding:14px 20px; background:linear-gradient(135deg,#1e3a5f,#1e40af); color:#fff; display:flex; align-items:center; gap:8px; }
.je-card-header i { font-size:1.1rem; }
.je-card-header h3 { margin:0; font-size:.95rem; font-weight:700; }
.je-card-body { padding:20px; }

/* Form fields */
.je-form-row { display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:12px; }
.je-form-row.cols3 { grid-template-columns:1fr 1fr 1fr; }
@media(max-width:600px){ .je-form-row,.je-form-row.cols3{grid-template-columns:1fr;} }
.je-form-group label { font-size:.75rem; font-weight:700; color:var(--je-text); display:block; margin-bottom:4px; }
.je-form-group input, .je-form-group select, .je-form-group textarea {
    width:100%; padding:8px 12px; border:1.5px solid var(--je-border);
    border-radius:6px; font-size:.85rem; color:var(--je-text); background:#fff;
    transition:border-color .2s; font-family:inherit;
}
.je-form-group input:focus, .je-form-group select:focus { outline:none; border-color:var(--je-accent); }

/* Entry grid */
.entry-grid-wrap { overflow-x:auto; }
.entry-grid { width:100%; border-collapse:collapse; font-size:.82rem; min-width:600px; }
.entry-grid thead th {
    background:linear-gradient(135deg,#1e3a5f,#1e40af);
    color:#fff; padding:10px 8px; text-align:center;
    font-weight:600; white-space:nowrap;
}
.entry-grid tbody tr { border-bottom:1px solid var(--je-border); transition:background .15s; }
.entry-grid tbody tr:hover { background:#f8fafc; }
.entry-grid td { padding:6px 4px; vertical-align:middle; }
.entry-grid td select, .entry-grid td input {
    width:100%; padding:6px 8px; border:1px solid #cbd5e1;
    border-radius:5px; font-size:.8rem; background:#fff; color:var(--je-text);
}
.entry-grid td select:focus, .entry-grid td input:focus {
    outline:none; border-color:var(--je-accent); background:#eff6ff;
}
.entry-grid td.debit-cell input { border-left:3px solid #10b981; }
.entry-grid td.credit-cell input { border-left:3px solid #ef4444; }

/* Totals row */
.entry-totals { display:flex; justify-content:flex-end; gap:20px; padding:12px 0 4px; }
.entry-total-item { text-align:center; padding:10px 20px; border-radius:8px; }
.entry-total-item.debit-total  { background:#dcfce7; border:1px solid #86efac; }
.entry-total-item.credit-total { background:#fee2e2; border:1px solid #fca5a5; }
.entry-total-item .amount { font-size:1.1rem; font-weight:800; }
.entry-total-item .label  { font-size:.7rem; color:var(--je-muted); }
.balance-status { padding:8px 16px; border-radius:6px; font-size:.82rem; font-weight:700; text-align:center; }
.balance-status.balanced   { background:#dcfce7; color:#166534; }
.balance-status.unbalanced { background:#fee2e2; color:#991b1b; }

/* Buttons */
.je-btn { display:inline-flex; align-items:center; gap:6px; padding:9px 18px; border:none; border-radius:7px; font-size:.83rem; font-weight:700; cursor:pointer; transition:all .2s; text-decoration:none; }
.je-btn-primary { background:linear-gradient(135deg,#1e40af,#3b82f6); color:#fff; }
.je-btn-primary:hover { transform:translateY(-1px); box-shadow:0 4px 14px rgba(59,130,246,.4); }
.je-btn-success { background:linear-gradient(135deg,#059669,#10b981); color:#fff; }
.je-btn-danger  { background:#fee2e2; color:#991b1b; border:1px solid #fca5a5; }
.je-btn-outline { background:transparent; border:1.5px solid var(--je-border); color:var(--je-text); }
.je-btn-sm { padding:5px 12px; font-size:.75rem; }
.btn-row { display:flex; gap:10px; flex-wrap:wrap; margin-top:14px; }

/* Entries list */
.entries-table { width:100%; border-collapse:collapse; font-size:.82rem; }
.entries-table thead th { background:#f8fafc; padding:10px 12px; text-align:right; font-weight:700; color:var(--je-muted); border-bottom:2px solid var(--je-border); }
.entries-table tbody tr { border-bottom:1px solid var(--je-border); transition:background .15s; }
.entries-table tbody tr:hover { background:#f8fafc; }
.entries-table tbody td { padding:10px 12px; vertical-align:middle; }
.status-badge { display:inline-flex; align-items:center; gap:4px; padding:3px 10px; border-radius:20px; font-size:.72rem; font-weight:700; }
.badge-posted { background:#dcfce7; color:#166534; }
.badge-voided { background:#fee2e2; color:#991b1b; }
.badge-draft  { background:#fef9c3; color:#92400e; }
.source-badge { background:#eff6ff; color:#1e40af; padding:2px 8px; border-radius:4px; font-size:.7rem; }

/* Alert */
.je-alert { padding:12px 16px; border-radius:8px; margin-bottom:16px; font-size:.83rem; display:flex; align-items:flex-start; gap:8px; }
.je-alert-danger  { background:#fee2e2; border:1px solid #fca5a5; color:#991b1b; }
.je-alert-success { background:#dcfce7; border:1px solid #86efac; color:#166534; }
</style>

<div class="page-inner">
    <!-- Page Header -->
    <div class="page-title-bar no-print">
        <div class="ptb-left">
            <div class="icon-wrap"><i class="bi bi-pencil-square"></i></div>
            <div>
                <h4>قيود اليومية المزدوجة</h4>
                <small>إضافة وضبط قيود اليومية بنظام القيد المزدوج المتوازن</small>
            </div>
        </div>
        <div class="ptb-actions">
            <a href="journal.php" class="btn btn-sm btn-light text-decoration-none" style="font-size: 0.8rem; border: 1px solid #cbd5e1;">
                <i class="bi bi-book"></i> دفتر القيود
            </a>
            <a href="../home.php" class="btn btn-sm btn-light text-decoration-none" style="font-size: 0.8rem; border: 1px solid #cbd5e1;">
                <i class="bi bi-arrow-left"></i> عودة
            </a>
        </div>
    </div>

    <!-- Alert container -->
    <div id="je-alert" style="display:none"></div>

    <div class="je-grid-2">
        <!-- === LEFT: New Entry Form === -->
        <div class="je-card">
            <div class="je-card-header">
                <i class="bi bi-plus-circle-fill"></i>
                <h3>قيد يومية جديد</h3>
            </div>
            <div class="je-card-body">
                <form id="je-form">
                    <div class="je-form-row cols3">
                        <div class="je-form-group">
                            <label>تاريخ القيد</label>
                            <input type="date" id="entry_date" name="entry_date" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="je-form-group">
                            <label>رقم المرجع</label>
                            <input type="text" id="reference_no" name="reference_no" placeholder="تلقائي إذا تُرك فارغاً">
                        </div>
                    </div>
                    <div class="je-form-group" style="margin-bottom:14px;">
                        <label>الوصف / البيان</label>
                        <textarea id="description" name="description" rows="2" placeholder="وصف القيد..." style="resize:none;"></textarea>
                    </div>

                    <!-- Entry Grid -->
                    <div class="entry-grid-wrap">
                        <table class="entry-grid" id="entry-table">
                            <thead>
                                <tr>
                                    <th style="width:38%">الحساب</th>
                                    <th style="width:22%">مدين</th>
                                    <th style="width:22%">دائن</th>
                                    <th style="width:12%">بيان</th>
                                    <th style="width:6%"></th>
                                </tr>
                            </thead>
                            <tbody id="entry-rows">
                                <!-- rows injected by JS -->
                            </tbody>
                        </table>
                    </div>

                    <div class="entry-totals">
                        <div class="entry-total-item debit-total">
                            <div class="label">إجمالي المدين</div>
                            <div class="amount" id="total-debit">0.00</div>
                        </div>
                        <div class="entry-total-item credit-total">
                            <div class="label">إجمالي الدائن</div>
                            <div class="amount" id="total-credit">0.00</div>
                        </div>
                    </div>
                    <div class="balance-status unbalanced" id="balance-status" style="margin:10px 0;">
                        <i class="bi bi-exclamation-triangle-fill"></i> القيد غير متوازن
                    </div>

                    <div class="btn-row">
                        <button type="button" class="je-btn je-btn-outline je-btn-sm" onclick="addRow()">
                            <i class="bi bi-plus-lg"></i> إضافة سطر
                        </button>
                        <button type="submit" class="je-btn je-btn-success" id="save-btn" disabled>
                            <i class="bi bi-check-circle-fill"></i> ترحيل القيد
                        </button>
                        <button type="button" class="je-btn je-btn-outline" onclick="resetForm()">
                            <i class="bi bi-arrow-counterclockwise"></i> إعادة تعيين
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- === RIGHT: Recent Entries === -->
        <div class="je-card">
            <div class="je-card-header">
                <i class="bi bi-journal-text"></i>
                <h3>آخر القيود المرحّلة</h3>
            </div>
            <div class="je-card-body" style="padding:0;">
                <div style="overflow-y:auto; max-height:600px;">
                    <table class="entries-table">
                        <thead>
                            <tr>
                                <th>التاريخ</th>
                                <th>المرجع</th>
                                <th>البيان</th>
                                <th>المبلغ</th>
                                <th>الحالة</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($entries)): ?>
                            <tr><td colspan="6" style="text-align:center;padding:30px;color:var(--je-muted);">
                                <i class="bi bi-inbox" style="font-size:2rem;"></i><br>لا توجد قيود مرحّلة بعد
                            </td></tr>
                        <?php else: foreach ($entries as $e): ?>
                            <tr>
                                <td><?= htmlspecialchars($e['entry_date']) ?></td>
                                <td><span class="source-badge"><?= htmlspecialchars($e['reference_no']) ?></span></td>
                                <td style="max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= htmlspecialchars($e['description']) ?>">
                                    <?= htmlspecialchars($e['description']) ?>
                                </td>
                                <td style="font-weight:700;"><?= number_format((float)$e['total_amount'], 2) ?></td>
                                <td>
                                    <span class="status-badge badge-<?= $e['status'] ?>">
                                        <?= $e['status'] === 'posted' ? 'مرحّل' : ($e['status'] === 'voided' ? 'ملغى' : 'مسودة') ?>
                                    </span>
                                </td>
                                <td>
                                    <button class="je-btn je-btn-outline je-btn-sm" onclick="viewEntry(<?= $e['id'] ?>)">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- View Entry Modal -->
<div class="modal fade" id="viewEntryModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header" style="background:linear-gradient(135deg,#1e3a5f,#1e40af);color:#fff;">
                <h5 class="modal-title"><i class="bi bi-journal-text"></i> تفاصيل القيد</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="entry-detail-body">
                <div class="text-center py-4"><div class="spinner-border text-primary"></div></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إغلاق</button>
                <button type="button" class="btn btn-danger" id="void-entry-btn" onclick="voidEntry()">
                    <i class="bi bi-x-circle"></i> إلغاء القيد
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// =====================================================
// Accounts data from PHP
// =====================================================
const ACCOUNTS = <?= json_encode($accounts, JSON_UNESCAPED_UNICODE) ?>;

function buildAccountSelect(selectedId = 0) {
    let opts = '<option value="">-- اختر الحساب --</option>';
    for (const a of ACCOUNTS) {
        const sel = (a.id == selectedId) ? 'selected' : '';
        opts += `<option value="${a.id}" data-type="${a.account_type}" ${sel}>[${a.code}] ${a.name}</option>`;
    }
    return opts;
}

let rowIndex = 0;

function addRow(debitVal = '', creditVal = '', accountId = 0, memo = '') {
    rowIndex++;
    const tbody = document.getElementById('entry-rows');
    const tr = document.createElement('tr');
    tr.dataset.row = rowIndex;
    tr.innerHTML = `
        <td>
            <select name="items[${rowIndex}][account_id]" onchange="recalc()" class="account-sel">
                ${buildAccountSelect(accountId)}
            </select>
        </td>
        <td class="debit-cell">
            <input type="number" name="items[${rowIndex}][debit]" min="0" step="0.0001"
                   value="${debitVal}" placeholder="0.0000" onchange="recalc()" oninput="recalc()">
        </td>
        <td class="credit-cell">
            <input type="number" name="items[${rowIndex}][credit]" min="0" step="0.0001"
                   value="${creditVal}" placeholder="0.0000" onchange="recalc()" oninput="recalc()">
        </td>
        <td>
            <input type="text" name="items[${rowIndex}][memo]" value="${memo}" placeholder="بيان...">
        </td>
        <td style="text-align:center;">
            <button type="button" class="je-btn je-btn-danger je-btn-sm" onclick="removeRow(this)">
                <i class="bi bi-trash"></i>
            </button>
        </td>`;
    tbody.appendChild(tr);
    recalc();
}

function removeRow(btn) {
    btn.closest('tr').remove();
    recalc();
}

function recalc() {
    let totalDebit = 0, totalCredit = 0;
    document.querySelectorAll('#entry-rows tr').forEach(tr => {
        const d = parseFloat(tr.querySelector('input[name*="[debit]"]').value) || 0;
        const c = parseFloat(tr.querySelector('input[name*="[credit]"]').value) || 0;
        totalDebit  += d;
        totalCredit += c;
    });

    document.getElementById('total-debit').textContent  = totalDebit.toFixed(2);
    document.getElementById('total-credit').textContent = totalCredit.toFixed(2);

    const balanced = Math.abs(totalDebit - totalCredit) < 0.001 && totalDebit > 0;
    const statusEl = document.getElementById('balance-status');
    const saveBtn  = document.getElementById('save-btn');

    if (balanced) {
        statusEl.className = 'balance-status balanced';
        statusEl.innerHTML = '<i class="bi bi-check-circle-fill"></i> القيد متوازن ✓';
        saveBtn.disabled = false;
    } else {
        statusEl.className = 'balance-status unbalanced';
        statusEl.innerHTML = `<i class="bi bi-exclamation-triangle-fill"></i> غير متوازن | الفرق: ${Math.abs(totalDebit - totalCredit).toFixed(4)}`;
        saveBtn.disabled = true;
    }
}

function resetForm() {
    document.getElementById('je-form').reset();
    document.getElementById('entry-rows').innerHTML = '';
    rowIndex = 0;
    addRow(); addRow();
    recalc();
}

// =====================================================
// Save entry via AJAX
// =====================================================
document.getElementById('je-form').addEventListener('submit', async function(e) {
    e.preventDefault();
    const saveBtn = document.getElementById('save-btn');
    saveBtn.disabled = true;
    saveBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> جاري الترحيل...';

    const formData = new FormData(this);
    formData.append('action', 'save');
    formData.append('entry_date',   document.getElementById('entry_date').value);
    formData.append('reference_no', document.getElementById('reference_no').value);
    formData.append('description',  document.getElementById('description').value);

    try {
        const res  = await fetch('../ajax/accounting_journal_entry.php', { method:'POST', body: formData });
        const data = await res.json();

        const alertEl = document.getElementById('je-alert');
        if (data.success) {
            alertEl.className = 'je-alert je-alert-success';
            alertEl.innerHTML = `<i class="bi bi-check-circle-fill"></i> تم ترحيل القيد بنجاح — رقم المرجع: <strong>${data.reference_no}</strong>`;
            alertEl.style.display = 'flex';
            resetForm();
            setTimeout(() => location.reload(), 2000);
        } else {
            alertEl.className = 'je-alert je-alert-danger';
            alertEl.innerHTML = `<i class="bi bi-exclamation-triangle-fill"></i> ${data.error}`;
            alertEl.style.display = 'flex';
        }
    } catch (err) {
        console.error(err);
    } finally {
        saveBtn.disabled = false;
        saveBtn.innerHTML = '<i class="bi bi-check-circle-fill"></i> ترحيل القيد';
        recalc();
    }
});

// =====================================================
// View entry
// =====================================================
let currentEntryId = null;

async function viewEntry(id) {
    currentEntryId = id;
    document.getElementById('entry-detail-body').innerHTML = '<div class="text-center py-4"><div class="spinner-border text-primary"></div></div>';
    const modal = new bootstrap.Modal(document.getElementById('viewEntryModal'));
    modal.show();

    const res  = await fetch(`../ajax/accounting_journal_entry.php?action=get&id=${id}`);
    const data = await res.json();
    if (!data.success) { document.getElementById('entry-detail-body').innerHTML = `<div class="alert alert-danger">${data.error}</div>`; return; }

    const e = data.entry;
    const isVoided = e.status === 'voided';
    document.getElementById('void-entry-btn').style.display = isVoided ? 'none' : '';

    let itemsHtml = '';
    let totalD = 0, totalC = 0;
    for (const item of e.items) {
        totalD += parseFloat(item.debit);
        totalC += parseFloat(item.credit);
        itemsHtml += `<tr>
            <td>[${item.account_code}] ${item.account_name}</td>
            <td style="color:#059669;font-weight:700;">${parseFloat(item.debit).toFixed(4)}</td>
            <td style="color:#dc2626;font-weight:700;">${parseFloat(item.credit).toFixed(4)}</td>
            <td>${item.memo || ''}</td>
        </tr>`;
    }

    document.getElementById('entry-detail-body').innerHTML = `
        <div class="row mb-3">
            <div class="col-md-4"><strong>التاريخ:</strong> ${e.entry_date}</div>
            <div class="col-md-4"><strong>المرجع:</strong> <span class="badge bg-primary">${e.reference_no}</span></div>
            <div class="col-md-4"><strong>الحالة:</strong>
                <span class="badge ${e.status==='posted'?'bg-success':e.status==='voided'?'bg-danger':'bg-warning'}">
                    ${e.status==='posted'?'مرحّل':e.status==='voided'?'ملغى':'مسودة'}
                </span>
            </div>
        </div>
        <p class="mb-3"><strong>البيان:</strong> ${e.description}</p>
        <div class="table-responsive">
        <table class="table table-bordered table-sm">
            <thead class="table-dark"><tr><th>الحساب</th><th>مدين</th><th>دائن</th><th>بيان</th></tr></thead>
            <tbody>${itemsHtml}</tbody>
            <tfoot class="table-secondary">
                <tr>
                    <td><strong>الإجمالي</strong></td>
                    <td style="color:#059669;font-weight:800;">${totalD.toFixed(4)}</td>
                    <td style="color:#dc2626;font-weight:800;">${totalC.toFixed(4)}</td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
        </div>
        <small class="text-muted">بواسطة: ${e.created_by} | ${e.created_at}</small>`;
}

async function voidEntry() {
    const reason = prompt('أدخل سبب إلغاء القيد (مطلوب):');
    if (!reason || reason.trim() === '') return;

    const fd = new FormData();
    fd.append('action', 'void');
    fd.append('id', currentEntryId);
    fd.append('reason', reason);

    const res  = await fetch('../ajax/accounting_journal_entry.php', { method:'POST', body: fd });
    const data = await res.json();
    if (data.success) {
        alert('تم إلغاء القيد بنجاح');
        location.reload();
    } else {
        alert('خطأ: ' + data.error);
    }
}

// Init: add 2 default rows
addRow(); addRow();
</script>
</div> <!-- End .page-inner -->
<?php require_once($dir_prefix . 'includes/footer.php'); ?>
