<?php
$dir_prefix = '../';
$module = 'vouchers';
require_once($dir_prefix . 'includes/header.php');
check_permission(['admin']);

use AQNEX\Services\Accounting\AccountTreeService;
use AQNEX\Services\Accounting\VoucherService;

$allAccounts = AccountTreeService::getLedgerAccounts($conn);

// Separate cash/bank accounts (asset type) and contra accounts
$cashAccounts     = array_filter($allAccounts, fn($a) => $a['account_type'] === 'asset');
$allAccountsFlat  = $allAccounts;

// Fetch recent vouchers
$recentVouchers = VoucherService::listVouchers($conn, [], 60);

// Customers & Suppliers for party selection
$customers = [];
$res_c = $conn->query("SELECT cust_id, cust_name FROM customers WHERE d_s = 0 AND cust_name != 'عميل نقدي' ORDER BY cust_name ASC");
if ($res_c) while ($r = $res_c->fetch_assoc()) $customers[] = $r;

$suppliers = [];
$res_s = $conn->query("SELECT supp_id, supp_name FROM suppliers WHERE d_s = 0 ORDER BY supp_name ASC");
if ($res_s) while ($r = $res_s->fetch_assoc()) $suppliers[] = $r;
?>
<title>سندات القبض والصرف - AQNEX</title>
<style>
:root{
    --vou-primary:#065f46; --vou-receipt:#059669; --vou-payment:#dc2626;
    --vou-accent:#10b981; --vou-bg:#f0fdf4; --vou-card:#fff;
    --vou-border:#d1fae5; --vou-text:#1e293b; --vou-muted:#64748b;
}
.vou-page { padding:20px; }
.vou-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:24px; flex-wrap:wrap; gap:12px; }
.vou-title  { font-size:1.5rem; font-weight:800; color:var(--vou-text); display:flex; align-items:center; gap:10px; }
.vou-subtitle { font-size:.78rem; color:var(--vou-muted); }

/* Tab Switcher */
.vou-tabs { display:flex; gap:0; background:#f1f5f9; border-radius:10px; padding:4px; margin-bottom:24px; width:fit-content; }
.vou-tab { padding:8px 24px; border-radius:7px; border:none; background:transparent; font-size:.85rem; font-weight:700; cursor:pointer; transition:all .2s; color:var(--vou-muted); }
.vou-tab.active-receipt { background:linear-gradient(135deg,#059669,#10b981); color:#fff; box-shadow:0 2px 8px rgba(5,150,105,.3); }
.vou-tab.active-payment { background:linear-gradient(135deg,#dc2626,#ef4444); color:#fff; box-shadow:0 2px 8px rgba(220,38,38,.3); }

.vou-grid { display:grid; grid-template-columns:400px 1fr; gap:20px; }
@media(max-width:900px){ .vou-grid { grid-template-columns:1fr; } }

/* Form card */
.vou-card { background:var(--vou-card); border-radius:12px; box-shadow:0 2px 12px rgba(0,0,0,.06); overflow:hidden; }
.vou-card-header { padding:16px 20px; color:#fff; display:flex; align-items:center; gap:8px; }
.vou-card-header.receipt { background:linear-gradient(135deg,#065f46,#059669); }
.vou-card-header.payment { background:linear-gradient(135deg,#991b1b,#dc2626); }
.vou-card-header h3 { margin:0; font-size:1rem; font-weight:700; }
.vou-card-body { padding:22px; }

/* Form fields */
.vf-group { margin-bottom:14px; }
.vf-group label { font-size:.75rem; font-weight:700; color:var(--vou-text); display:block; margin-bottom:5px; }
.vf-group input, .vf-group select, .vf-group textarea {
    width:100%; padding:9px 13px; border:1.5px solid #e2e8f0;
    border-radius:7px; font-size:.85rem; color:var(--vou-text);
    background:#fff; transition:border-color .2s; font-family:inherit;
}
.vf-group input:focus, .vf-group select:focus { outline:none; border-color:var(--vou-accent); }
.vf-group.amount-field input { font-size:1.2rem; font-weight:800; text-align:center; color:#065f46; border-color:#a7f3d0; background:#f0fdf4; }
.vf-row-2 { display:grid; grid-template-columns:1fr 1fr; gap:12px; }

/* Submit button */
.vou-btn { display:flex; align-items:center; justify-content:center; gap:8px; width:100%; padding:12px; border:none; border-radius:8px; font-size:.95rem; font-weight:800; cursor:pointer; transition:all .2s; }
.vou-btn.receipt-btn { background:linear-gradient(135deg,#059669,#10b981); color:#fff; }
.vou-btn.payment-btn { background:linear-gradient(135deg,#dc2626,#ef4444); color:#fff; }
.vou-btn:hover { transform:translateY(-1px); box-shadow:0 6px 20px rgba(0,0,0,.2); }
.vou-btn:disabled { opacity:.6; transform:none; box-shadow:none; cursor:not-allowed; }

/* Voucher preview */
.vou-preview { border:2px dashed #a7f3d0; border-radius:8px; padding:14px; margin-top:14px; background:#f0fdf4; font-size:.82rem; display:none; }
.vou-preview.payment-preview { border-color:#fca5a5; background:#fff5f5; }

/* Vouchers list */
.vouchers-table { width:100%; border-collapse:collapse; font-size:.82rem; }
.vouchers-table thead th { background:#f8fafc; padding:10px 12px; font-weight:700; color:var(--vou-muted); border-bottom:2px solid #e2e8f0; }
.vouchers-table tbody tr { border-bottom:1px solid #f1f5f9; transition:background .15s; }
.vouchers-table tbody tr:hover { background:#f8fafc; }
.vouchers-table tbody td { padding:10px 12px; vertical-align:middle; }
.type-badge-receipt { background:#d1fae5; color:#065f46; padding:3px 10px; border-radius:20px; font-size:.72rem; font-weight:700; }
.type-badge-payment { background:#fee2e2; color:#991b1b; padding:3px 10px; border-radius:20px; font-size:.72rem; font-weight:700; }
.status-voided { background:#fee2e2; color:#991b1b; padding:2px 8px; border-radius:4px; font-size:.7rem; }
.status-posted  { background:#d1fae5; color:#065f46; padding:2px 8px; border-radius:4px; font-size:.7rem; }
.filter-bar { padding:14px 16px; background:#f8fafc; border-bottom:1px solid #e2e8f0; display:flex; gap:10px; flex-wrap:wrap; }
.filter-bar select, .filter-bar input { padding:6px 10px; border:1px solid #e2e8f0; border-radius:6px; font-size:.8rem; background:#fff; }
.action-btn { padding:4px 10px; border-radius:5px; border:1px solid #e2e8f0; background:#fff; cursor:pointer; font-size:.75rem; transition:all .15s; }
.action-btn:hover { background:#f1f5f9; }

/* Alert */
.vou-alert { padding:12px 16px; border-radius:8px; margin-bottom:14px; font-size:.83rem; }
.vou-alert-success { background:#d1fae5; color:#065f46; border:1px solid #6ee7b7; }
.vou-alert-danger  { background:#fee2e2; color:#991b1b; border:1px solid #fca5a5; }
</style>

<div class="vou-page">
    <div class="vou-header">
        <div>
            <div class="vou-title"><i class="bi bi-receipt-cutoff"></i> سندات القبض والصرف</div>
            <div class="vou-subtitle">إدارة التدفقات النقدية الواردة والصادرة مع الترحيل المحاسبي التلقائي</div>
        </div>
    </div>

    <!-- Tab switcher -->
    <div class="vou-tabs">
        <button class="vou-tab active-receipt" id="tab-receipt" onclick="switchTab('receipt')">
            <i class="bi bi-arrow-down-circle-fill"></i> سند قبض (واردة)
        </button>
        <button class="vou-tab" id="tab-payment" onclick="switchTab('payment')">
            <i class="bi bi-arrow-up-circle-fill"></i> سند صرف (صادرة)
        </button>
    </div>

    <div class="vou-grid">
        <!-- === FORM PANEL === -->
        <div class="vou-card" id="form-card">
            <div class="vou-card-header receipt" id="form-card-header">
                <i class="bi bi-arrow-down-circle-fill" style="font-size:1.3rem;"></i>
                <h3 id="form-card-title">سند قبض جديد (سند قبض)</h3>
            </div>
            <div class="vou-card-body">
                <div id="vou-alert" style="display:none;"></div>
                <form id="vou-form">
                    <input type="hidden" id="voucher_type" name="voucher_type" value="receipt">

                    <div class="vf-row-2">
                        <div class="vf-group">
                            <label>التاريخ</label>
                            <input type="date" id="voucher_date" name="voucher_date" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="vf-group">
                            <label>الطرف (الجهة)</label>
                            <select id="party_type" name="party_type" onchange="updatePartyList()">
                                <option value="other">أخرى (يدوي)</option>
                                <option value="customer">عميل</option>
                                <option value="supplier">مورد</option>
                            </select>
                        </div>
                    </div>

                    <div class="vf-group" id="party_select_wrap" style="display:none;">
                        <label id="party_select_label">اختر العميل</label>
                        <select id="party_id" name="party_id" onchange="fillPartyName()">
                            <option value="">-- اختر --</option>
                        </select>
                    </div>
                    <div class="vf-group">
                        <label>اسم الطرف</label>
                        <input type="text" id="party_name" name="party_name" placeholder="مثال: شركة المقاولات..." required>
                    </div>

                    <div class="vf-group amount-field">
                        <label>المبلغ</label>
                        <input type="number" id="amount" name="amount" step="0.0001" min="0.01" placeholder="0.0000" required oninput="updatePreview()">
                    </div>

                    <div class="vf-group">
                        <label id="cash-account-label">حساب الصندوق / البنك (المستلم)</label>
                        <select id="cash_account_id" name="cash_account_id" required onchange="updatePreview()">
                            <option value="">-- اختر حساب الصندوق --</option>
                            <?php foreach ($cashAccounts as $a): ?>
                            <option value="<?= $a['id'] ?>">[<?= $a['code'] ?>] <?= htmlspecialchars($a['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="vf-group">
                        <label id="contra-account-label">الحساب المقابل (المصدر)</label>
                        <select id="contra_account_id" name="contra_account_id" required onchange="updatePreview()">
                            <option value="">-- اختر الحساب المقابل --</option>
                            <?php foreach ($allAccountsFlat as $a): ?>
                            <option value="<?= $a['id'] ?>">[<?= $a['code'] ?>] <?= htmlspecialchars($a['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="vf-group">
                        <label>البيان / الوصف</label>
                        <textarea id="description" name="description" rows="2" placeholder="وصف السند..." style="resize:none;"></textarea>
                    </div>

                    <!-- Preview -->
                    <div class="vou-preview" id="vou-preview">
                        <strong>معاينة القيد المحاسبي:</strong><br>
                        <div id="preview-content" style="margin-top:8px; font-family:monospace; font-size:.8rem;"></div>
                    </div>

                    <div style="margin-top:16px;">
                        <button type="submit" class="vou-btn receipt-btn" id="vou-submit-btn">
                            <i class="bi bi-check-circle-fill"></i> حفظ وترحيل السند
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- === VOUCHERS LIST === -->
        <div class="vou-card" style="overflow:hidden;">
            <div class="vou-card-header" style="background:linear-gradient(135deg,#1e3a5f,#1e40af);">
                <i class="bi bi-list-ul"></i>
                <h3>سجل السندات</h3>
            </div>
            <div class="filter-bar">
                <select id="filter-type" onchange="filterVouchers()">
                    <option value="">الكل</option>
                    <option value="receipt">قبض</option>
                    <option value="payment">صرف</option>
                </select>
                <input type="date" id="filter-from" onchange="filterVouchers()">
                <input type="date" id="filter-to"   onchange="filterVouchers()">
                <button class="action-btn" onclick="filterVouchers()" style="background:var(--vou-accent);color:#fff;border-color:var(--vou-accent);">
                    <i class="bi bi-funnel"></i> تصفية
                </button>
            </div>
            <div style="overflow-y:auto; max-height:580px;">
                <table class="vouchers-table">
                    <thead>
                        <tr>
                            <th>النوع</th>
                            <th>رقم السند</th>
                            <th>التاريخ</th>
                            <th>الجهة</th>
                            <th>المبلغ</th>
                            <th>الحالة</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="vouchers-tbody">
                        <?php if (empty($recentVouchers)): ?>
                        <tr><td colspan="7" style="text-align:center;padding:40px;color:var(--vou-muted);">
                            <i class="bi bi-inbox" style="font-size:2rem;"></i><br>لا توجد سندات
                        </td></tr>
                        <?php else: foreach ($recentVouchers as $v): ?>
                        <tr data-type="<?= $v['voucher_type'] ?>">
                            <td>
                                <?php if ($v['voucher_type'] === 'receipt'): ?>
                                <span class="type-badge-receipt"><i class="bi bi-arrow-down-circle"></i> قبض</span>
                                <?php else: ?>
                                <span class="type-badge-payment"><i class="bi bi-arrow-up-circle"></i> صرف</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-family:monospace;font-weight:700;"><?= htmlspecialchars($v['voucher_no']) ?></td>
                            <td><?= $v['voucher_date'] ?></td>
                            <td style="max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= htmlspecialchars($v['party_name']) ?></td>
                            <td style="font-weight:700;"><?= number_format((float)$v['amount'], 2) ?></td>
                            <td><span class="status-<?= $v['status'] ?>"><?= $v['status'] === 'posted' ? 'مرحّل' : 'ملغى' ?></span></td>
                            <td>
                                <button class="action-btn" onclick="viewVoucher(<?= $v['id'] ?>)"><i class="bi bi-eye"></i></button>
                            </td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- View Voucher Modal -->
<div class="modal fade" id="viewVoucherModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header" id="voucher-modal-header" style="background:linear-gradient(135deg,#065f46,#059669);color:#fff;">
                <h5 class="modal-title" id="voucher-modal-title"><i class="bi bi-receipt-cutoff"></i> تفاصيل السند</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="voucher-detail-body">
                <div class="text-center py-4"><div class="spinner-border text-primary"></div></div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" data-bs-dismiss="modal">إغلاق</button>
                <button class="btn btn-danger" id="void-vou-btn" onclick="voidVoucher()">
                    <i class="bi bi-x-circle"></i> إلغاء السند
                </button>
            </div>
        </div>
    </div>
</div>

<script>
const CUSTOMERS = <?= json_encode($customers, JSON_UNESCAPED_UNICODE) ?>;
const SUPPLIERS = <?= json_encode($suppliers, JSON_UNESCAPED_UNICODE) ?>;
let currentTab = 'receipt';
let currentVoucherId = null;

function switchTab(type) {
    currentTab = type;
    document.getElementById('voucher_type').value = type;

    const isReceipt = type === 'receipt';
    document.getElementById('tab-receipt').className = 'vou-tab' + (isReceipt ? ' active-receipt' : '');
    document.getElementById('tab-payment').className = 'vou-tab' + (!isReceipt ? ' active-payment' : '');

    const header     = document.getElementById('form-card-header');
    const submitBtn  = document.getElementById('vou-submit-btn');
    const formTitle  = document.getElementById('form-card-title');
    const cashlabel  = document.getElementById('cash-account-label');
    const contralabel= document.getElementById('contra-account-label');

    if (isReceipt) {
        header.className     = 'vou-card-header receipt';
        submitBtn.className  = 'vou-btn receipt-btn';
        formTitle.textContent= 'سند قبض جديد (سند قبض)';
        cashlabel.textContent = 'حساب الصندوق / البنك (المستلم)';
        contralabel.textContent = 'الحساب المقابل (المصدر)';
        submitBtn.innerHTML  = '<i class="bi bi-check-circle-fill"></i> حفظ وترحيل سند القبض';
        document.querySelector('.vou-preview').className = 'vou-preview';
    } else {
        header.className     = 'vou-card-header payment';
        submitBtn.className  = 'vou-btn payment-btn';
        formTitle.textContent= 'سند صرف جديد (سند صرف)';
        cashlabel.textContent = 'حساب الصندوق / البنك (المدفوع منه)';
        contralabel.textContent = 'الحساب المقابل (المستلم / المصروف)';
        submitBtn.innerHTML  = '<i class="bi bi-check-circle-fill"></i> حفظ وترحيل سند الصرف';
        document.querySelector('.vou-preview').className = 'vou-preview payment-preview';
    }
    updatePreview();
}

function updatePartyList() {
    const ptype = document.getElementById('party_type').value;
    const wrap  = document.getElementById('party_select_wrap');
    const sel   = document.getElementById('party_id');
    const label = document.getElementById('party_select_label');

    if (ptype === 'customer' || ptype === 'supplier') {
        wrap.style.display = 'block';
        sel.innerHTML = '<option value="">-- اختر --</option>';
        const list = ptype === 'customer' ? CUSTOMERS : SUPPLIERS;
        const nameKey = ptype === 'customer' ? 'cust_name' : 'supp_name';
        const idKey   = ptype === 'customer' ? 'cust_id'   : 'supp_id';
        label.textContent = ptype === 'customer' ? 'اختر العميل' : 'اختر المورد';
        list.forEach(p => sel.innerHTML += `<option value="${p[idKey]}" data-name="${p[nameKey]}">${p[nameKey]}</option>`);
    } else {
        wrap.style.display = 'none';
        document.getElementById('party_name').value = '';
    }
}

function fillPartyName() {
    const sel = document.getElementById('party_id');
    const opt = sel.options[sel.selectedIndex];
    if (opt && opt.dataset.name) {
        document.getElementById('party_name').value = opt.dataset.name;
    }
}

function updatePreview() {
    const amount    = parseFloat(document.getElementById('amount').value) || 0;
    const cashSel   = document.getElementById('cash_account_id');
    const contraSel = document.getElementById('contra_account_id');
    const cashName  = cashSel.options[cashSel.selectedIndex]?.text || '---';
    const contraName= contraSel.options[contraSel.selectedIndex]?.text || '---';
    const preview   = document.getElementById('vou-preview');
    const content   = document.getElementById('preview-content');

    if (amount > 0 && cashSel.value && contraSel.value) {
        preview.style.display = 'block';
        if (currentTab === 'receipt') {
            content.innerHTML = `
                <span style="color:#059669;">من حـ/ ${contraName}</span><br>
                &nbsp;&nbsp;&nbsp;&nbsp;<span style="color:#1e40af;">إلى حـ/ ${cashName}</span><br>
                <strong>المبلغ: ${amount.toFixed(2)}</strong>`;
        } else {
            content.innerHTML = `
                <span style="color:#dc2626;">من حـ/ ${cashName}</span><br>
                &nbsp;&nbsp;&nbsp;&nbsp;<span style="color:#1e40af;">إلى حـ/ ${contraName}</span><br>
                <strong>المبلغ: ${amount.toFixed(2)}</strong>`;
        }
    } else {
        preview.style.display = 'none';
    }
}

// Save voucher
document.getElementById('vou-form').addEventListener('submit', async function(e) {
    e.preventDefault();
    const btn = document.getElementById('vou-submit-btn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> جاري الحفظ...';

    const fd = new FormData(this);
    fd.append('action', currentTab === 'receipt' ? 'create_receipt' : 'create_payment');

    try {
        const res  = await fetch('../ajax/accounting_voucher.php', { method:'POST', body: fd });
        const data = await res.json();
        const alertEl = document.getElementById('vou-alert');

        if (data.success) {
            alertEl.className = 'vou-alert vou-alert-success';
            alertEl.innerHTML = `<i class="bi bi-check-circle-fill"></i> تم ترحيل السند بنجاح — رقم: <strong>${data.voucher_no}</strong>`;
            alertEl.style.display = 'block';
            this.reset();
            document.getElementById('vou-preview').style.display = 'none';
            setTimeout(() => location.reload(), 2000);
        } else {
            alertEl.className = 'vou-alert vou-alert-danger';
            alertEl.innerHTML = `<i class="bi bi-exclamation-triangle-fill"></i> ${data.error}`;
            alertEl.style.display = 'block';
        }
    } catch(err) { console.error(err); }
    finally {
        btn.disabled = false;
        switchTab(currentTab);
    }
});

function filterVouchers() {
    const type = document.getElementById('filter-type').value;
    const from = document.getElementById('filter-from').value;
    const to   = document.getElementById('filter-to').value;
    document.querySelectorAll('#vouchers-tbody tr[data-type]').forEach(tr => {
        const matchType = !type || tr.dataset.type === type;
        tr.style.display = matchType ? '' : 'none';
    });
}

async function viewVoucher(id) {
    currentVoucherId = id;
    const modal = new bootstrap.Modal(document.getElementById('viewVoucherModal'));
    modal.show();
    document.getElementById('voucher-detail-body').innerHTML = '<div class="text-center py-4"><div class="spinner-border text-success"></div></div>';

    const res  = await fetch(`../ajax/accounting_voucher.php?action=get&id=${id}`);
    const data = await res.json();
    if (!data.success) { document.getElementById('voucher-detail-body').innerHTML = `<div class="alert alert-danger">${data.error}</div>`; return; }

    const v = data.voucher;
    const isVoided = v.status === 'voided';
    document.getElementById('void-vou-btn').style.display = isVoided ? 'none' : '';

    const typeLabel = v.voucher_type === 'receipt' ? '🟢 سند قبض' : '🔴 سند صرف';
    const hdr = document.getElementById('voucher-modal-header');
    hdr.style.background = v.voucher_type === 'receipt' ? 'linear-gradient(135deg,#065f46,#059669)' : 'linear-gradient(135deg,#991b1b,#dc2626)';

    document.getElementById('voucher-detail-body').innerHTML = `
        <div class="row mb-3">
            <div class="col-md-4"><strong>النوع:</strong> ${typeLabel}</div>
            <div class="col-md-4"><strong>رقم السند:</strong> <span class="badge bg-success">${v.voucher_no}</span></div>
            <div class="col-md-4"><strong>التاريخ:</strong> ${v.voucher_date}</div>
        </div>
        <div class="row mb-3">
            <div class="col-md-6"><strong>الجهة:</strong> ${v.party_name || '—'}</div>
            <div class="col-md-6"><strong>الحالة:</strong>
                <span class="badge ${isVoided ? 'bg-danger' : 'bg-success'}">${isVoided ? 'ملغى' : 'مرحّل'}</span>
            </div>
        </div>
        <table class="table table-bordered table-sm">
            <thead class="table-dark">
                <tr><th>الحساب</th><th>مدين</th><th>دائن</th></tr>
            </thead>
            <tbody>
                <tr>
                    <td>${v.voucher_type === 'receipt' ? v.cash_account_name : v.contra_account_name}</td>
                    <td style="color:#059669;font-weight:700;">${parseFloat(v.amount).toFixed(2)}</td>
                    <td>—</td>
                </tr>
                <tr>
                    <td>${v.voucher_type === 'receipt' ? v.contra_account_name : v.cash_account_name}</td>
                    <td>—</td>
                    <td style="color:#dc2626;font-weight:700;">${parseFloat(v.amount).toFixed(2)}</td>
                </tr>
            </tbody>
        </table>
        <p><strong>البيان:</strong> ${v.description || '—'}</p>
        <small class="text-muted">بواسطة: ${v.created_by} | ${v.created_at}</small>`;
}

async function voidVoucher() {
    const reason = prompt('أدخل سبب إلغاء السند:');
    if (!reason) return;
    const fd = new FormData();
    fd.append('action', 'void');
    fd.append('id', currentVoucherId);
    fd.append('reason', reason);
    const res  = await fetch('../ajax/accounting_voucher.php', { method:'POST', body: fd });
    const data = await res.json();
    if (data.success) { alert('تم الإلغاء'); location.reload(); }
    else alert('خطأ: ' + data.error);
}
</script>
<?php require_once($dir_prefix . 'includes/footer.php'); ?>
