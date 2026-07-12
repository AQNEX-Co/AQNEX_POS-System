<?php
$dir_prefix = '../';
$module = 'payment_voucher';
require_once($dir_prefix . 'includes/header.php');
check_permission(['admin']);

use AQNEX\Services\Accounting\AccountTreeService;
use AQNEX\Services\Accounting\VoucherService;

$allAccounts  = AccountTreeService::getLedgerAccounts($conn);
$cashAccounts = array_values(array_filter($allAccounts, fn($a) => $a['account_type'] === 'asset'));

$customers = [];
$res_c = $conn->query("SELECT cust_id AS id, cust_name AS name FROM customers WHERE d_s = 0 AND cust_name != 'عميل نقدي' ORDER BY cust_name ASC");
if ($res_c) while ($r = $res_c->fetch_assoc()) $customers[] = $r;

$suppliers = [];
$res_s = $conn->query("SELECT supp_id AS id, supp_name AS name FROM suppliers WHERE d_s = 0 ORDER BY supp_name ASC");
if ($res_s) while ($r = $res_s->fetch_assoc()) $suppliers[] = $r;

$recentVouchers = VoucherService::listVouchers($conn, ['type' => 'payment'], 100);

$totalPosted    = array_filter($recentVouchers, fn($v) => $v['status'] === 'posted');
$totalAmount    = array_sum(array_column(array_values($totalPosted), 'amount'));
$totalPostedAmt = 0; $totalVoidedAmt = 0;
?>
<title>سند الصرف</title>
<style>
/* ─── متوافق مع custom.css الخاص بالنظام ─── */
.page-inner { padding: 16px 20px; }

.page-title-bar {
    display: flex; align-items: center; justify-content: space-between;
    padding: 10px 0 14px; border-bottom: 2px solid #be123c; margin-bottom: 16px;
    flex-wrap: wrap; gap: 10px;
}
.ptb-left { display: flex; align-items: center; gap: 10px; }
.icon-wrap {
    width: 34px; height: 34px;
    background: #be123c; color: #fff;
    display: flex; align-items: center; justify-content: center; font-size: 1rem;
}
.page-title-bar h4 { margin: 0; font-size: .95rem; font-weight: 700; color: var(--text-color); }
.page-title-bar small { font-size: .72rem; color: #64748b; display: block; }
.ptb-stats { display: flex; gap: 10px; flex-wrap: wrap; }
.stat-box { background: #fff; border: 1px solid var(--border-color); padding: 6px 14px; text-align: center; }
.stat-box .sv { font-size: .9rem; font-weight: 700; color: #be123c; }
.stat-box .sl { font-size: .65rem; color: #94a3b8; }

.sys-alert { padding: 9px 14px; margin-bottom: 12px; font-size: .8rem; border: 1px solid; display: flex; align-items: center; gap: 8px; }
.sys-alert.success { background: #f0fdf4; border-color: #6ee7b7; color: #065f46; }
.sys-alert.danger  { background: #fff1f2; border-color: #fca5a5; color: #9f1239; }

.form-panel { background: #fff; border: 1px solid var(--border-color); margin-bottom: 16px; }
.form-panel-header {
    background: #0f172a; color: #e2e8f0;
    padding: 8px 14px; font-size: .8rem; font-weight: 700;
    display: flex; align-items: center; gap: 8px;
    border-bottom: 2px solid #be123c;
}
.form-panel-header i { color: #f43f5e; }
.form-panel-body { padding: 16px 18px; }

.fg-grid { display: grid; gap: 12px 14px; margin-bottom: 14px; }
.fg-grid.c2 { grid-template-columns: repeat(2,1fr); }
.fg-grid.c3 { grid-template-columns: repeat(3,1fr); }
.fg-grid.c4 { grid-template-columns: repeat(4,1fr); }
@media(max-width:900px){ .fg-grid.c4,.fg-grid.c3 { grid-template-columns: repeat(2,1fr); } }
@media(max-width:560px){ .fg-grid { grid-template-columns: 1fr !important; } }

.fg-field { display: flex; flex-direction: column; gap: 4px; }
.fg-field label { font-size: .72rem; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: .3px; }
.fg-field label .req { color: #be123c; }
.fg-field input, .fg-field select {
    padding: 7px 10px; font-size: .82rem;
    border: 1px solid var(--border-color); background: #fff;
    color: var(--text-color); width: 100%;
    font-family: 'Tajawal', sans-serif; transition: border-color .15s;
}
.fg-field input:focus, .fg-field select:focus { outline: none; border-color: #be123c; }
.fg-field input.amount-f {
    font-size: 1.2rem; font-weight: 800; text-align: center;
    color: #9f1239; border-color: #be123c; background: #fff1f2;
}
.fg-field input.readonly-f { background: #f8fafc; color: #be123c; font-weight: 700; cursor: default; }

.ac-wrap { position: relative; }
.ac-drop {
    position: absolute; top: calc(100% + 2px); right: 0; left: 0;
    background: #fff; border: 1px solid #be123c;
    max-height: 220px; overflow-y: auto; z-index: 9999; display: none;
}
.ac-drop.open { display: block; }
.ac-drop::-webkit-scrollbar { width: 4px; }
.ac-drop::-webkit-scrollbar-thumb { background: #be123c; }
.ac-drop-item {
    padding: 8px 12px; cursor: pointer; border-bottom: 1px solid #f1f5f9;
    font-size: .8rem; display: flex; align-items: center; gap: 8px; transition: background .1s;
}
.ac-drop-item:hover, .ac-drop-item.active { background: #fff1f2; color: #be123c; font-weight: 600; }
.ac-code-tag { font-family: monospace; font-size: .68rem; background: #eff6ff; color: #1d4ed8; padding: 1px 6px; white-space: nowrap; flex-shrink: 0; }
.ac-type-tag { font-size: .65rem; padding: 1px 5px; flex-shrink: 0; font-weight: 700; }
.t-asset     { background: #d1fae5; color: #065f46; }
.t-liability { background: #fee2e2; color: #991b1b; }
.t-equity    { background: #fef3c7; color: #92400e; }
.t-revenue   { background: #dbeafe; color: #1e40af; }
.t-expense   { background: #ede9fe; color: #5b21b6; }
.ac-empty { padding: 12px; text-align: center; color: #94a3b8; font-size: .78rem; }

.sec-divider {
    display: flex; align-items: center; gap: 10px;
    margin: 4px 0 14px; font-size: .7rem; color: #94a3b8; font-weight: 700; text-transform: uppercase;
}
.sec-divider::before, .sec-divider::after { content:''; flex:1; height:1px; background:var(--border-color); }

.entry-preview {
    background: #fff1f2; border: 1px dashed #be123c;
    padding: 10px 14px; margin-bottom: 14px; font-size: .8rem; display: none;
}
.entry-preview.show { display: block; }
.ep-title { font-size: .68rem; font-weight: 700; color: #be123c; text-transform: uppercase; margin-bottom: 8px; }
.ep-dr { color: #9f1239; font-weight: 700; }
.ep-cr { color: #1e40af; font-weight: 700; padding-right: 20px; }

.submit-row { display: flex; align-items: center; gap: 10px; margin-top: 4px; }
.btn-submit-payment {
    padding: 9px 24px; background: #be123c; color: #fff;
    border: none; font-size: .83rem; font-weight: 700;
    font-family: 'Tajawal', sans-serif; cursor: pointer;
    display: flex; align-items: center; gap: 7px; transition: background .2s;
}
.btn-submit-payment:hover { background: #9f1239; }
.btn-submit-payment:disabled { background: #94a3b8; cursor: not-allowed; }
.btn-reset {
    padding: 9px 18px; background: #f1f5f9; border: 1px solid var(--border-color);
    color: #475569; font-size: .8rem; font-weight: 600; cursor: pointer;
    font-family: 'Tajawal', sans-serif; transition: background .15s;
}
.btn-reset:hover { background: #e2e8f0; }

.records-panel { background: #fff; border: 1px solid var(--border-color); }
.records-panel-header {
    background: #1e293b; color: #e2e8f0;
    padding: 8px 14px; font-size: .8rem; font-weight: 700;
    display: flex; align-items: center; justify-content: space-between;
}
.filter-strip {
    padding: 8px 14px; background: #f8fafc;
    border-bottom: 1px solid var(--border-color);
    display: flex; gap: 8px; flex-wrap: wrap; align-items: center;
}
.filter-strip input, .filter-strip select {
    padding: 5px 9px; border: 1px solid var(--border-color);
    font-size: .78rem; font-family: 'Tajawal', sans-serif; background: #fff;
}
.filter-strip input:focus, .filter-strip select:focus { outline:none; border-color:#be123c; }
.btn-filter {
    padding: 5px 14px; background: #be123c; color: #fff;
    border: none; font-size: .78rem; font-weight: 700;
    cursor: pointer; font-family: 'Tajawal', sans-serif;
}
.sys-table { width: 100%; border-collapse: collapse; font-size: .79rem; }
.sys-table thead th {
    background: #f1f5f9; padding: 9px 12px; font-weight: 700;
    color: #475569; text-align: right;
    border-bottom: 2px solid var(--border-color); white-space: nowrap;
}
.sys-table tbody tr { border-bottom: 1px solid #f1f5f9; }
.sys-table tbody tr:hover { background: #fff1f2; }
.sys-table tbody td { padding: 9px 12px; vertical-align: middle; }
.no-data-row { text-align: center; padding: 40px !important; color: #94a3b8; }

.badge-posted { background: #d1fae5; color: #065f46; padding: 2px 8px; font-size: .7rem; font-weight: 700; }
.badge-voided { background: #fee2e2; color: #991b1b; padding: 2px 8px; font-size: .7rem; font-weight: 700; }
.vno { font-family: monospace; background: #fff1f2; color: #9f1239; padding: 1px 7px; font-size: .75rem; font-weight: 700; }
.party-name { color: #334155; font-size: .78rem; }
.amount-col { font-weight: 700; color: #9f1239; }
.icon-btn {
    width: 27px; height: 27px; border: 1px solid var(--border-color);
    background: #fff; cursor: pointer; display: inline-flex; align-items: center; justify-content: center;
    font-size: .75rem; color: #64748b; transition: all .15s;
}
.icon-btn:hover { background: #fff1f2; border-color: #be123c; color: #9f1239; }
.icon-btn.del:hover { background: #fee2e2; border-color: #f87171; color: #7f1d1d; }

.totals-footer {
    padding: 10px 14px; background: #f8fafc;
    border-top: 1px solid var(--border-color);
    display: flex; gap: 12px; flex-wrap: wrap;
}
.total-chip { display:flex; align-items:center; gap:6px; background:#be123c; color:#fff; padding:5px 14px; font-size:.78rem; font-weight:700; }
.total-chip.blue { background: #0369a1; }
.total-chip.gray { background: #475569; }

@media print {
    .page-title-bar, .form-panel, .filter-strip, #sidebar, nav, .records-panel-header button { display:none !important; }
}
</style>

<div class="page-inner">

    <!-- HEADER -->
    <div class="page-title-bar">
        <div class="ptb-left">
            <div class="icon-wrap"><i class="bi bi-arrow-up-circle-fill"></i></div>
            <div>
                <h4>سند الصرف (Payment Voucher)</h4>
                <small>تسجيل المبالغ الصادرة مع الترحيل المحاسبي التلقائي</small>
            </div>
        </div>
        <div class="ptb-stats">
            <div class="stat-box">
                <div class="sv"><?= count($recentVouchers) ?></div>
                <div class="sl">إجمالي السندات</div>
            </div>
            <div class="stat-box">
                <div class="sv"><?= number_format($totalAmount, 2) ?></div>
                <div class="sl">إجمالي المصروف</div>
            </div>
        </div>
    </div>

    <!-- ALERT -->
    <div id="sys-alert" style="display:none;"></div>

    <!-- FORM PANEL -->
    <div class="form-panel">
        <div class="form-panel-header">
            <i class="bi bi-plus-circle"></i> إنشاء سند صرف جديد
            <span style="margin-right:auto;font-size:.68rem;color:#94a3b8;font-weight:400;">يُرحَّل تلقائياً في الحسابات</span>
        </div>
        <div class="form-panel-body">
            <form id="pv-form" autocomplete="off">
                <!-- Row 1 -->
                <div class="fg-grid c4">
                    <div class="fg-field">
                        <label>التاريخ <span class="req">*</span></label>
                        <input type="date" id="voucher_date" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="fg-field">
                        <label>رقم السند (تلقائي)</label>
                        <input type="text" id="voucher_ref" class="readonly-f" placeholder="سيتم توليده تلقائياً" readonly>
                    </div>
                    <div class="fg-field">
                        <label>نوع الطرف</label>
                        <select id="party_type" onchange="switchPartyType()">
                            <option value="other">أخرى (يدوي)</option>
                            <option value="supplier">مورد</option>
                            <option value="customer">عميل</option>
                        </select>
                    </div>
                    <div class="fg-field" id="party-ac-wrap">
                        <label id="party-ac-label">بحث الطرف</label>
                        <div class="ac-wrap">
                            <input type="text" id="party_search" placeholder="اكتب للبحث..."
                                   oninput="searchParty(this.value)" onfocus="searchParty(this.value)">
                            <input type="hidden" id="party_id">
                            <div class="ac-drop" id="party-drop"></div>
                        </div>
                    </div>
                </div>

                <!-- Row 2 -->
                <div class="fg-grid c2">
                    <div class="fg-field">
                        <label>اسم الجهة / المستفيد <span class="req">*</span></label>
                        <input type="text" id="party_name" placeholder="مثال: مورد الكهرباء، إيجار مكتب..." required>
                    </div>
                    <div class="fg-field">
                        <label>البيان / الوصف</label>
                        <input type="text" id="description" placeholder="مثال: دفع فاتورة إيجار شهر يوليو...">
                    </div>
                </div>

                <div class="sec-divider"><i class="bi bi-bank2"></i> الحسابات المحاسبية</div>

                <!-- Row 3 -->
                <div class="fg-grid c2">
                    <div class="fg-field">
                        <label>حساب الصندوق / البنك (المدفوع منه) <span class="req">*</span></label>
                        <div class="ac-wrap">
                            <input type="text" id="cash_ac_search" placeholder="اكتب للبحث عن صندوق أو بنك..."
                                   oninput="searchAccount('cash', this.value, ['asset'])"
                                   onfocus="searchAccount('cash', this.value, ['asset'])" required>
                            <input type="hidden" id="cash_account_id">
                            <div class="ac-drop" id="cash-drop"></div>
                        </div>
                    </div>
                </div>

                <div class="sec-divider"><i class="bi bi-table"></i> بنود السند التفصيلية (الحسابات المقابلة)</div>

                <!-- مصاريف يومية سريعة -->
                <div class="quick-expenses-panel" style="margin-bottom: 12px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 10px 14px;">
                    <div style="font-size: 0.78rem; font-weight: 700; color: #475569; margin-bottom: 8px;">
                        <i class="bi bi-lightning-charge-fill" style="color:#eab308;"></i> إضافة سريعة لمصروفات يومية متكررة (صرفة يومية):
                    </div>
                    <div class="d-flex flex-wrap gap-2">
                        <button type="button" class="btn btn-xs btn-outline-secondary" onclick="quickAddExpense('51020002', 'التغذية والضيافة اليومية')" style="font-size:0.78rem; border-radius: 20px; padding: 4px 10px; background:#fff;"><i class="bi bi-egg-fried"></i> تغذية وضيافة</button>
                        <button type="button" class="btn btn-xs btn-outline-secondary" onclick="quickAddExpense('51020001', 'إيجار المقر والفروع')" style="font-size:0.78rem; border-radius: 20px; padding: 4px 10px; background:#fff;"><i class="bi bi-building"></i> إيجار مقر/فروع</button>
                        <button type="button" class="btn btn-xs btn-outline-secondary" onclick="quickAddExpense('51020003', 'الكهرباء والمياه')" style="font-size:0.78rem; border-radius: 20px; padding: 4px 10px; background:#fff;"><i class="bi bi-lightning"></i> كهرباء ومياه</button>
                        <button type="button" class="btn btn-xs btn-outline-secondary" onclick="quickAddExpense('51020004', 'الاتصالات والانترنت')" style="font-size:0.78rem; border-radius: 20px; padding: 4px 10px; background:#fff;"><i class="bi bi-wifi"></i> اتصالات وانترنت</button>
                        <button type="button" class="btn btn-xs btn-outline-secondary" onclick="quickAddExpense('51020006', 'القرطاسية والأدوات المكتبية')" style="font-size:0.78rem; border-radius: 20px; padding: 4px 10px; background:#fff;"><i class="bi bi-journal-text"></i> قرطاسية ومكتبية</button>
                        <button type="button" class="btn btn-xs btn-outline-secondary" onclick="quickAddExpense('51020005', 'مصاريف عمومية ونثرية')" style="font-size:0.78rem; border-radius: 20px; padding: 4px 10px; background:#fff;"><i class="bi bi-cash-stack"></i> نثرية وعمومايات</button>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="sys-table" id="voucher-items-table" style="background:#fff;">
                        <thead>
                            <tr>
                                <th style="width: 5%;">#</th>
                                <th style="width: 35%;">حساب المصروف / المستفيد <span class="req">*</span></th>
                                <th style="width: 25%;">اسم الحساب</th>
                                <th style="width: 20%;">البيان الفرعي</th>
                                <th style="width: 10%; text-align: center;">المبلغ <span class="req">*</span></th>
                                <th style="width: 5%; text-align: center;">إجراء</th>
                            </tr>
                        </thead>
                        <tbody id="voucher-items-tbody">
                            <!-- rows added dynamically -->
                        </tbody>
                    </table>
                </div>

                <div class="mt-2 mb-4 d-flex justify-content-between align-items-center">
                    <button type="button" class="btn btn-sm btn-outline-success" onclick="addNewVoucherRow()">
                        <i class="bi bi-plus-circle"></i> إضافة بند آخر
                    </button>
                    
                    <div style="min-width: 250px;">
                        <label class="form-label font-weight-bold text-danger text-uppercase" style="font-size:0.75rem;">الإجمالي الكلي للسند</label>
                        <input type="text" id="voucher_total_display" class="amount-f form-control text-center" readonly value="0.00" style="font-size: 1.3rem; font-weight: 800; color: #9f1239; border-color: #be123c; background: #fff1f2;">
                    </div>
                </div>

                <!-- Preview -->
                <div class="entry-preview" id="entry-preview" style="display:none; margin-bottom:14px; background:#fff1f2; border:1px dashed #be123c; padding:10px 14px; font-size:.8rem;">
                    <div class="ep-title" style="font-size:.68rem; font-weight:700; color:#be123c; text-transform:uppercase; margin-bottom:8px;"><i class="bi bi-eye"></i> معاينة القيد المحاسبي</div>
                    <div id="preview-body"></div>
                </div>

                <div class="submit-row" style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px;">
                    <div style="display:flex; align-items:center; gap:10px;">
                        <button type="submit" class="btn-submit-payment" id="submit-btn">
                            <i class="bi bi-check-circle-fill"></i> حفظ وترحيل
                        </button>
                        <button type="button" class="btn-reset" onclick="resetForm()">
                            <i class="bi bi-arrow-counterclockwise"></i> مسح
                        </button>
                    </div>
                    <label style="display: inline-flex; align-items: center; gap: 6px; font-size: .8rem; font-weight: bold; color: #475569; cursor: pointer;">
                        <input type="checkbox" id="autoprint_after_save" checked style="width: 16px; height: 16px; accent-color: #be123c;">
                        طباعة السند تلقائياً بعد الحفظ
                    </label>
                </div>
            </form>
        </div>
    </div>

    <!-- RECORDS TABLE -->
    <div class="records-panel">
        <div class="records-panel-header">
            <span><i class="bi bi-table"></i> سجل سندات الصرف</span>
            <button onclick="window.print()" style="background:transparent;border:1px solid #475569;color:#94a3b8;padding:3px 12px;font-size:.72rem;cursor:pointer;font-family:'Tajawal',sans-serif;">
                <i class="bi bi-printer"></i> طباعة
            </button>
        </div>
        <div class="filter-strip">
            <input type="date" id="f-from">
            <input type="date" id="f-to">
            <input type="text" id="f-search" placeholder="🔍 بحث..." oninput="filterTable()" style="min-width:180px;">
            <select id="f-status" onchange="filterTable()">
                <option value="">الكل</option>
                <option value="posted">مرحّل</option>
                <option value="voided">ملغى</option>
            </select>
            <button class="btn-filter" onclick="filterTable()"><i class="bi bi-funnel"></i> تصفية</button>
        </div>
        <div style="overflow-x:auto;">
            <table class="sys-table">
                <thead>
                    <tr>
                        <th>#</th><th>رقم السند</th><th>التاريخ</th><th>الجهة</th>
                        <th>حساب الدفع</th><th>حساب المصروف</th>
                        <th>المبلغ</th><th>الحالة</th><th>بواسطة</th><th></th>
                    </tr>
                </thead>
                <tbody id="records-tbody">
                <?php if (empty($recentVouchers)): ?>
                <tr><td colspan="10" class="no-data-row">
                    <i class="bi bi-inbox" style="font-size:1.5rem;display:block;"></i>لا توجد سندات صرف بعد
                </td></tr>
                <?php else: foreach ($recentVouchers as $i => $v): ?>
                <tr data-status="<?= $v['status'] ?>"
                    data-search="<?= strtolower($v['voucher_no'] . ' ' . $v['party_name']) ?>">
                    <td style="color:#94a3b8;"><?= $i+1 ?></td>
                    <td><span class="vno"><?= htmlspecialchars($v['voucher_no']) ?></span></td>
                    <td><?= $v['voucher_date'] ?></td>
                    <td class="party-name"><?= htmlspecialchars($v['party_name'] ?: '—') ?></td>
                    <td style="font-size:.75rem;"><?= htmlspecialchars($v['cash_account_name']) ?></td>
                    <td style="font-size:.75rem;"><?= htmlspecialchars($v['contra_account_name']) ?></td>
                    <td class="amount-col"><?= number_format((float)$v['amount'], 2) ?></td>
                    <td><span class="badge-<?= $v['status'] ?>"><?= $v['status']==='posted'?'مرحّل':'ملغى' ?></span></td>
                    <td style="font-size:.72rem;color:#94a3b8;"><?= htmlspecialchars($v['created_by']) ?></td>
                    <td style="white-space:nowrap;">
                        <button class="icon-btn" onclick="viewVoucher(<?= $v['id'] ?>)" title="عرض التفاصيل"><i class="bi bi-eye"></i></button>
                        <button class="icon-btn" onclick="printVoucherById(<?= $v['id'] ?>)" title="طباعة السند"><i class="bi bi-printer"></i></button>
                        <?php if ($v['status']==='posted'): ?>
                        <button class="icon-btn del" onclick="voidVoucher(<?= $v['id'] ?>)" title="إلغاء السند"><i class="bi bi-x-circle"></i></button>
                        <?php endif; ?>
                        <button class="icon-btn del" onclick="deleteVoucher(<?= $v['id'] ?>)" title="حذف السند نهائياً"><i class="bi bi-trash"></i></button>
                    </td>
                </tr>
                <?php
                if ($v['status']==='posted') $totalPostedAmt += (float)$v['amount'];
                else $totalVoidedAmt += (float)$v['amount'];
                endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php if (!empty($recentVouchers)): ?>
        <div class="totals-footer">
            <div class="total-chip blue"><i class="bi bi-receipt"></i> <?= count($recentVouchers) ?> سند</div>
            <div class="total-chip"><i class="bi bi-arrow-up-circle"></i> مرحّل: <?= number_format($totalPostedAmt,2) ?></div>
            <?php if ($totalVoidedAmt > 0): ?>
            <div class="total-chip gray"><i class="bi bi-x-circle"></i> ملغى: <?= number_format($totalVoidedAmt,2) ?></div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- View Modal -->
<div class="modal fade" id="viewModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header" style="background:#0f172a;color:#e2e8f0;border:none;padding:10px 16px;">
                <h5 class="modal-title" style="font-size:.88rem;font-weight:700;">
                    <i class="bi bi-receipt-cutoff"></i> تفاصيل سند الصرف
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="view-body" style="font-size:.83rem;"></div>
            <div class="modal-footer" id="view-footer" style="padding:8px 16px;border-top:1px solid var(--border-color); display:flex; justify-content:space-between; width:100%;">
                <button class="btn btn-sm btn-secondary" data-bs-dismiss="modal">إغلاق</button>
            </div>
        </div>
    </div>
</div>

<script>
const ALL_ACCOUNTS = <?= json_encode(array_values($allAccounts), JSON_UNESCAPED_UNICODE) ?>;
const CUSTOMERS    = <?= json_encode($customers, JSON_UNESCAPED_UNICODE) ?>;
const SUPPLIERS    = <?= json_encode($suppliers, JSON_UNESCAPED_UNICODE) ?>;
const TYPE_AR      = { asset:'أصول', liability:'خصوم', equity:'حقوق', revenue:'إيرادات', expense:'مصروفات' };

function renderDrop(ddId, items, onSelect) {
    const dd = document.getElementById(ddId);
    if (!items.length) {
        dd.innerHTML = '<div class="ac-empty">لا توجد نتائج</div>';
        dd.classList.add('open'); return;
    }
    dd.innerHTML = items.slice(0,50).map((item,idx) =>
        `<div class="ac-drop-item ${idx === 0 ? 'active' : ''}" onmousedown="event.preventDefault()" onclick="pickItem('${ddId}',${idx})">
            ${item.code!==undefined?`<span class="ac-code-tag">${item.code}</span>`:''}
            <span>${item.name}</span>
            ${item.account_type?`<span class="ac-type-tag t-${item.account_type}">${TYPE_AR[item.account_type]||''}</span>`:''}
        </div>`).join('');
    dd._items=items; dd._onSelect=onSelect; dd.classList.add('open');
}
function pickItem(ddId,idx) {
    const dd=document.getElementById(ddId);
    if(dd?._items?.[idx]){
        dd._onSelect(dd._items[idx]);
        dd.classList.remove('open');
    }
}
document.addEventListener('click',e=>{
    if(!e.target.closest('.ac-wrap'))
        document.querySelectorAll('.ac-drop').forEach(d=>d.classList.remove('open'));
});

function searchAccount(type,q,filterTypes=[]) {
    const src=filterTypes.length?ALL_ACCOUNTS.filter(a=>filterTypes.includes(a.account_type)):ALL_ACCOUNTS;
    const ql=q.trim().toLowerCase();
    const res=ql?src.filter(a=>a.name.toLowerCase().includes(ql)||a.code.toLowerCase().includes(ql)):src;
    renderDrop(type==='cash'?'cash-drop':'contra-drop',res,item=>{
        const label=`[${item.code}] ${item.name}`;
        document.getElementById(type==='cash'?'cash_ac_search':'contra_ac_search').value=label;
        document.getElementById(type==='cash'?'cash_account_id':'contra_account_id').value=item.id;
        updatePreview();
    });
}

let currentPartyType='other';
function switchPartyType(){
    currentPartyType=document.getElementById('party_type').value;
    const lbl=document.getElementById('party-ac-label');
    const inp=document.getElementById('party_search');
    const wrap=document.getElementById('party-ac-wrap');
    const nameInp=document.getElementById('party_name');
    
    inp.value=''; document.getElementById('party_id').value='';
    nameInp.value='';
    
    if(currentPartyType==='other'){
        wrap.style.display='none';
        nameInp.readOnly=false;
        nameInp.placeholder='مثال: مورد الكهرباء، إيجار مكتب...';
    }else{
        wrap.style.display='block';
        nameInp.readOnly=true;
        if(currentPartyType==='supplier'){
            lbl.textContent='بحث الموردين';
            inp.placeholder='اكتب اسم المورد للبحث واختياره...';
            nameInp.placeholder='سيتم ملء اسم المورد تلقائياً عند الاختيار...';
        }else{
            lbl.textContent='بحث العملاء';
            inp.placeholder='اكتب اسم العميل للبحث واختياره...';
            nameInp.placeholder='سيتم ملء اسم العميل تلقائياً عند الاختيار...';
        }
    }
}
function searchParty(q){
    if(currentPartyType==='other')return;
    const src=currentPartyType==='supplier'?SUPPLIERS:CUSTOMERS;
    const ql=q.trim().toLowerCase();
    const res=ql?src.filter(p=>p.name.toLowerCase().includes(ql)):src;
    renderDrop('party-drop',res.map(p=>({...p,code:p.id})),item=>{
        document.getElementById('party_search').value=item.name;
        document.getElementById('party_id').value=item.id;
        document.getElementById('party_name').value=item.name;
    });
}

function quickAddExpense(code, name) {
    const acc = ALL_ACCOUNTS.find(a => a.code === code);
    if (!acc) {
        alert("الحساب المحاسبي غير موجود في شجرة الحسابات: " + code);
        return;
    }
    const tbody = document.getElementById('voucher-items-tbody');
    const firstRow = tbody.querySelector('tr:first-child');
    if (firstRow) {
        const idInp = firstRow.querySelector('.row-account-id');
        const searchInp = firstRow.querySelector('.row-account-search');
        const nameSpan = firstRow.querySelector('.row-account-name');
        if (idInp && !idInp.value && searchInp && !searchInp.value) {
            idInp.value = acc.id;
            searchInp.value = `[${acc.code}] ${acc.name}`;
            nameSpan.textContent = acc.name;
            const amtInp = firstRow.querySelector('.row-amount');
            if (amtInp) {
                amtInp.value = '';
                amtInp.focus();
            }
            updatePreview();
            return;
        }
    }
    addNewVoucherRow(acc.id, acc.code, acc.name, '', '');
    setTimeout(() => {
        const rows = tbody.querySelectorAll('tr');
        if (rows.length > 0) {
            const lastRow = rows[rows.length - 1];
            const amtInp = lastRow.querySelector('.row-amount');
            if (amtInp) {
                amtInp.value = '';
                amtInp.focus();
            }
        }
    }, 50);
}

// Dynamic row addition & deletion functions
let voucherRowCount = 0;

function addNewVoucherRow(accountId = '', accountCode = '', accountName = '', amount = '', memo = '') {
    voucherRowCount++;
    const tbody = document.getElementById('voucher-items-tbody');
    const tr = document.createElement('tr');
    tr.className = 'voucher-row';
    tr.id = `row-${voucherRowCount}`;
    tr.innerHTML = `
        <td class="row-index text-muted" style="vertical-align:middle;">${tbody.children.length + 1}</td>
        <td style="position:relative;">
            <div class="ac-wrap">
                <input type="text" class="row-account-search form-control form-control-sm" placeholder="ابحث بكود أو اسم الحساب..." value="${accountCode ? `[${accountCode}] ${accountName}` : ''}" oninput="searchAccountRow(this, this.value)" onfocus="searchAccountRow(this, this.value)" required style="padding: 5px 9px; font-size: .8rem; border-radius: 4px; border: 1px solid var(--border-color);">
                <input type="hidden" class="row-account-id" value="${accountId}">
                <div class="ac-drop row-ac-drop"></div>
            </div>
        </td>
        <td style="vertical-align:middle;"><span class="row-account-name text-muted">${accountName || '—'}</span></td>
        <td>
            <input type="text" class="row-memo form-control form-control-sm" placeholder="بيان هذا البند..." value="${memo}" style="padding: 5px 9px; font-size: .8rem; border-radius: 4px; border: 1px solid var(--border-color);">
        </td>
        <td>
            <input type="number" step="0.0001" min="0.01" class="row-amount amount-input form-control form-control-sm text-center font-weight-bold text-danger" value="${amount || '0.00'}" oninput="updateVoucherTotal()" required style="padding: 5px 9px; font-size: .88rem; border-radius: 4px; border: 1px solid var(--border-color);">
        </td>
        <td style="text-align:center; vertical-align:middle;">
            <button type="button" class="icon-btn del" onclick="deleteRow(this)"><i class="bi bi-trash"></i></button>
        </td>
    `;
    tbody.appendChild(tr);
    updateVoucherTotal();
}

function deleteRow(btn) {
    const tbody = document.getElementById('voucher-items-tbody');
    if (tbody.children.length > 1) {
        btn.closest('tr').remove();
        // Re-index rows
        Array.from(tbody.children).forEach((tr, idx) => {
            tr.querySelector('.row-index').textContent = idx + 1;
        });
        updateVoucherTotal();
    } else {
        alert("يجب إدخال بند واحد على الأقل في السند");
    }
}

function searchAccountRow(inputEl, q) {
    const row = inputEl.closest('tr');
    const dd = row.querySelector('.row-ac-drop');
    const ql = q.trim().toLowerCase();
    
    const res = ql ? ALL_ACCOUNTS.filter(a => a.name.toLowerCase().includes(ql) || a.code.toLowerCase().includes(ql)) : ALL_ACCOUNTS;
    
    if (!res.length) {
        dd.innerHTML = '<div class="ac-empty">لا توجد نتائج</div>';
        dd.classList.add('open'); return;
    }
    
    dd.innerHTML = res.slice(0,50).map((item, idx) =>
        `<div class="ac-drop-item ${idx === 0 ? 'active' : ''}" onmousedown="event.preventDefault()" onclick="pickAccountRow(this, ${item.id}, '${item.code}', '${item.name}')">
            <span class="ac-code-tag">${item.code}</span>
            <span>${item.name}</span>
        </div>`).join('');
    dd.classList.add('open');
}

function pickAccountRow(itemEl, id, code, name) {
    const row = itemEl.closest('tr');
    row.querySelector('.row-account-id').value = id;
    row.querySelector('.row-account-search').value = `[${code}] ${name}`;
    row.querySelector('.row-account-name').textContent = name;
    row.querySelector('.row-ac-drop').classList.remove('open');
    updateVoucherTotal();
}

function updateVoucherTotal() {
    let total = 0.0;
    document.querySelectorAll('.row-amount').forEach(inp => {
        total += parseFloat(inp.value) || 0.0;
    });
    document.getElementById('voucher_total_display').value = total.toFixed(2);
    updatePreview();
}

// Keyboard Navigation & Suggestions Arrow Navigation
function setupEnterKeyNavigation(formId) {
    const form = document.getElementById(formId);
    if (!form) return;

    form.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            const active = document.activeElement;
            if (!active) return;

            if (active.tagName === 'BUTTON' || active.type === 'submit' || active.classList.contains('btn-reset')) {
                return;
            }

            e.preventDefault();

            // Autocomplete enter picker
            if (active.classList.contains('row-account-search') || active.id === 'cash_ac_search' || active.id === 'party_search') {
                const wrap = active.closest('.ac-wrap');
                const dd = wrap ? wrap.querySelector('.ac-drop') : null;
                if (dd && dd.classList.contains('open')) {
                    const activeItem = dd.querySelector('.ac-drop-item.active');
                    if (activeItem) {
                        activeItem.click();
                        setTimeout(() => focusNextField(active), 30);
                        return;
                    }
                }
            }

            // Amount Enter key -> add row if last row and valid
            if (active.classList.contains('row-amount')) {
                const rows = document.querySelectorAll('#voucher-items-tbody tr');
                const currentRow = active.closest('tr');
                if (currentRow === rows[rows.length - 1]) {
                    const accId = currentRow.querySelector('.row-account-id').value;
                    const amt = parseFloat(active.value) || 0;
                    if (accId && amt > 0) {
                        addNewVoucherRow();
                        setTimeout(() => {
                            const newRows = document.querySelectorAll('#voucher-items-tbody tr');
                            const lastRow = newRows[newRows.length - 1];
                            const searchInp = lastRow.querySelector('.row-account-search');
                            if (searchInp) {
                                searchInp.focus();
                                searchInp.select();
                            }
                        }, 50);
                        return;
                    }
                }
            }

            focusNextField(active);
        }
    });

    // Arrow keys suggestions navigation
    form.addEventListener('keydown', function(e) {
        const active = document.activeElement;
        if (!active) return;

        if (active.classList.contains('row-account-search') || active.id === 'cash_ac_search' || active.id === 'party_search') {
            const wrap = active.closest('.ac-wrap');
            const dd = wrap ? wrap.querySelector('.ac-drop') : null;
            if (!dd || !dd.classList.contains('open')) return;

            const items = Array.from(dd.querySelectorAll('.ac-drop-item'));
            if (items.length === 0) return;

            let activeIdx = items.findIndex(item => item.classList.contains('active'));

            if (e.key === 'ArrowDown') {
                e.preventDefault();
                if (activeIdx > -1) items[activeIdx].classList.remove('active');
                activeIdx = (activeIdx + 1) % items.length;
                items[activeIdx].classList.add('active');
                items[activeIdx].scrollIntoView({ block: 'nearest' });
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                if (activeIdx > -1) items[activeIdx].classList.remove('active');
                activeIdx = (activeIdx - 1 + items.length) % items.length;
                items[activeIdx].classList.add('active');
                items[activeIdx].scrollIntoView({ block: 'nearest' });
            } else if (e.key === 'Escape') {
                dd.classList.remove('open');
            }
        }
    });
}

function focusNextField(activeEl) {
    const form = activeEl.closest('form');
    if (!form) return;
    const focusables = Array.from(form.querySelectorAll('input:not([readonly]):not([type=hidden]):not([disabled]), select:not([disabled]), textarea:not([disabled]), button[type=submit]'));
    const index = focusables.indexOf(activeEl);
    if (index > -1 && index < focusables.length - 1) {
        focusables[index + 1].focus();
        if (focusables[index + 1].select) {
            focusables[index + 1].select();
        }
    }
}

// Add default row and setup keyboard listeners on load
document.addEventListener('DOMContentLoaded', () => {
    addNewVoucherRow();
    setupEnterKeyNavigation('pv-form');
    switchPartyType();
});

function updatePreview() {
    const cash = document.getElementById('cash_ac_search').value || 'حساب الصندوق';
    const total = parseFloat(document.getElementById('voucher_total_display').value) || 0;
    const prev = document.getElementById('entry-preview');
    if (!prev) return;
    
    const rows = [];
    document.querySelectorAll('#voucher-items-tbody tr').forEach(tr => {
        const accName = tr.querySelector('.row-account-name').textContent;
        const amt = parseFloat(tr.querySelector('.row-amount').value) || 0;
        if (accName && accName !== '—' && amt > 0) {
            rows.push({ name: accName, amount: amt });
        }
    });

    if (total > 0 && rows.length > 0) {
        prev.style.display = 'block';
        let bodyHtml = '';
        rows.forEach(r => {
            bodyHtml += `<div><span class="ep-dr">من حـ/</span> ${r.name} <strong>${r.amount.toFixed(2)}</strong></div>`;
        });
        bodyHtml += `<div><span class="ep-cr">إلى حـ/</span> ${cash} <strong>${total.toFixed(2)}</strong></div>`;
        document.getElementById('preview-body').innerHTML = bodyHtml;
    } else {
        prev.style.display = 'none';
    }
}

document.getElementById('pv-form').addEventListener('submit', async function(e){
    e.preventDefault();
    const cashId = document.getElementById('cash_account_id').value;
    if (!cashId) {
        const al = document.getElementById('sys-alert');
        al.className = 'sys-alert danger';
        al.innerHTML = '<i class="bi bi-exclamation-triangle-fill"></i> يرجى اختيار حساب الصندوق / البنك';
        al.style.display = 'flex'; return;
    }

    const items = [];
    let valid = true;
    document.querySelectorAll('#voucher-items-tbody tr').forEach(tr => {
        const accId = tr.querySelector('.row-account-id').value;
        const amt = parseFloat(tr.querySelector('.row-amount').value) || 0;
        const memo = tr.querySelector('.row-memo').value;
        if (!accId) {
            valid = false;
        }
        items.push({ account_id: accId, amount: amt, memo: memo });
    });

    if (!valid || items.length === 0) {
        const al = document.getElementById('sys-alert');
        al.className = 'sys-alert danger';
        al.innerHTML = '<i class="bi bi-exclamation-triangle-fill"></i> يرجى التأكد من اختيار الحسابات لجميع البنود';
        al.style.display = 'flex'; return;
    }

    const btn = document.getElementById('submit-btn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> جاري الترحيل...';
    
    const fd = new FormData();
    fd.append('action', 'create_payment');
    fd.append('voucher_date', document.getElementById('voucher_date').value);
    fd.append('party_type', document.getElementById('party_type').value);
    fd.append('party_id', document.getElementById('party_id').value || '');
    fd.append('party_name', document.getElementById('party_name').value);
    fd.append('cash_account_id', cashId);
    fd.append('description', document.getElementById('description').value);
    fd.append('items', JSON.stringify(items));
    fd.append('amount', document.getElementById('voucher_total_display').value);

    try {
        const res = await fetch('../ajax/accounting_voucher.php', { method: 'POST', body: fd });
        const data = await res.json();
        const al = document.getElementById('sys-alert');
        if (data.success) {
            al.className = 'sys-alert success';
            al.innerHTML = `<i class="bi bi-check-circle-fill"></i> تم ترحيل سند الصرف — رقم: <strong>${data.voucher_no}</strong>`;
            al.style.display = 'flex';
            document.getElementById('voucher_ref').value = data.voucher_no;
            window.scrollTo({ top: 0, behavior: 'smooth' });
            
            // Trigger automatic printing if selected
            if (document.getElementById('autoprint_after_save').checked) {
                await printVoucherById(data.voucher_id);
            }
            
            setTimeout(() => location.reload(), 2200);
        } else {
            al.className = 'sys-alert danger';
            al.innerHTML = `<i class="bi bi-exclamation-triangle-fill"></i> ${data.error}`;
            al.style.display = 'flex';
        }
    } catch(err) { console.error(err); }
    finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-check-circle-fill"></i> حفظ وترحيل';
    }
});

function resetForm() {
    document.getElementById('pv-form').reset();
    document.getElementById('cash_ac_search').value = '';
    document.getElementById('cash_account_id').value = '';
    document.getElementById('party_search').value = '';
    document.getElementById('party_id').value = '';
    document.getElementById('voucher-items-tbody').innerHTML = '';
    addNewVoucherRow();
    document.getElementById('entry-preview').style.display = 'none';
    document.getElementById('sys-alert').style.display = 'none';
    document.getElementById('voucher_date').value = new Date().toISOString().slice(0, 10);
}

function filterTable() {
    const q = document.getElementById('f-search').value.toLowerCase();
    const st = document.getElementById('f-status').value;
    document.querySelectorAll('#records-tbody tr[data-status]').forEach(tr => {
        tr.style.display = ((!q || tr.dataset.search.includes(q)) && (!st || tr.dataset.status === st)) ? '' : 'none';
    });
}

async function viewVoucher(id) {
    $('#viewModal').modal('show');
    document.getElementById('view-body').innerHTML = '<div class="text-center py-3"><div class="spinner-border spinner-border-sm text-danger"></div></div>';
    const res = await fetch(`../ajax/accounting_voucher.php?action=get&id=${id}`);
    const data = await res.json();
    if (!data.success) { document.getElementById('view-body').innerHTML = `<p class="text-danger">${data.error}</p>`; return; }
    const v = data.voucher;
    
    let tableRows = '';
    v.items.forEach(item => {
        tableRows += `<tr>
            <td>${item.account_name} <span class="text-muted" style="font-size:0.7rem;">(${item.account_code})</span></td>
            <td style="color:#9f1239; font-weight:700;">${parseFloat(item.amount).toFixed(2)}</td>
            <td>—</td>
        </tr>`;
    });
    // Cash account line (credit)
    tableRows += `<tr>
        <td>${v.cash_account_name} <span class="text-muted" style="font-size:0.7rem;">(${v.cash_account_code})</span></td>
        <td>—</td>
        <td style="color:#065f46; font-weight:700;">${parseFloat(v.amount).toFixed(2)}</td>
    </tr>`;

    document.getElementById('view-body').innerHTML = `
        <div class="row g-2 mb-2">
            <div class="col-4"><strong>رقم السند:</strong><br><span class="vno">${v.voucher_no}</span></div>
            <div class="col-4"><strong>التاريخ:</strong><br>${v.voucher_date}</div>
            <div class="col-4"><strong>الحالة:</strong><br><span class="badge-${v.status}">${v.status === 'posted' ? 'مرحّل' : 'ملغى'}</span></div>
        </div>
        <div class="row g-2 mb-2">
            <div class="col-6"><strong>الجهة:</strong><br>${v.party_name || '—'}</div>
            <div class="col-6"><strong>المبلغ الإجمالي:</strong><br><span class="amount-col" style="font-size:1.1rem;">${parseFloat(v.amount).toFixed(2)}</span></div>
        </div>
        <table class="sys-table mt-2">
            <thead><tr><th>الحساب</th><th>مدين</th><th>دائن</th></tr></thead>
            <tbody>
                ${tableRows}
            </tbody>
        </table>
        <p style="margin-top:10px;"><strong>البيان:</strong> ${v.description || '—'}</p>
        <small style="color:#94a3b8;">بواسطة: ${v.created_by} | ${v.created_at}</small>`;

    // Add Print, Void, and Delete actions to footer dynamically
    let footerHtml = `
        <div>
            <button class="btn btn-sm btn-success" onclick="printVoucherById(${v.id})"><i class="bi bi-printer"></i> طباعة السند</button>
            ${v.status === 'posted' ? `<button class="btn btn-sm btn-outline-danger" onclick="voidVoucher(${v.id})"><i class="bi bi-x-circle"></i> إلغاء السند</button>` : ''}
            <button class="btn btn-sm btn-danger" onclick="deleteVoucher(${v.id})"><i class="bi bi-trash"></i> حذف نهائي</button>
        </div>
        <button class="btn btn-sm btn-secondary" data-bs-dismiss="modal">إغلاق</button>
    `;
    document.getElementById('view-footer').innerHTML = footerHtml;
}

async function voidVoucher(id) {
    if (!confirm('هل أنت متأكد من إلغاء هذا السند؟ سيتم عكس الحركات وإلغاء القيد المحاسبي بالكامل.')) return;
    const reason = prompt('أدخل سبب الإلغاء:');
    if (!reason) return;
    const fd = new FormData();
    fd.append('action', 'void'); fd.append('id', id); fd.append('reason', reason);
    const res = await fetch('../ajax/accounting_voucher.php', { method: 'POST', body: fd });
    const data = await res.json();
    data.success ? location.reload() : alert('خطأ: ' + data.error);
}

async function deleteVoucher(id) {
    if (!confirm('تحذير: هل أنت متأكد من حذف هذا السند نهائياً؟ سيتم مسح السند والقيود وتعديل رصيد الصندوق بالكامل، ولا يمكن التراجع عن هذه العملية!')) return;
    const fd = new FormData();
    fd.append('action', 'delete'); fd.append('id', id);
    const res = await fetch('../ajax/accounting_voucher.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (data.success) {
        alert('تم حذف السند نهائياً بنجاح');
        location.reload();
    } else {
        alert('خطأ أثناء الحذف: ' + data.error);
    }
}

// print helper loading details and generating pop-up print window
async function printVoucherById(id) {
    const res = await fetch(`../ajax/accounting_voucher.php?action=get&id=${id}`);
    const data = await res.json();
    if (data.success && data.voucher) {
        printVoucherData(data.voucher);
    } else {
        alert("فشل تحميل بيانات السند للطباعة");
    }
}

function printVoucherData(v) {
    const printWindow = window.open('', '_blank', 'width=850,height=650');
    if (!printWindow) {
        alert("يرجى السماح بالنوافذ المنبثقة لطباعة السند");
        return;
    }
    
    let isReceipt = v.voucher_type === 'receipt';
    let rowsHtml = '';
    
    // Cash account line
    rowsHtml += `
        <tr>
            <td style="padding:10px; border:1px solid #cbd5e1; font-weight:500;">${v.cash_account_name} <span style="font-size:0.75rem; color:#64748b;">(${v.cash_account_code})</span></td>
            <td style="padding:10px; border:1px solid #cbd5e1; text-align:center; font-weight:bold; font-family:monospace; font-size:1rem; color:${isReceipt ? '#0d9488' : '#334155'};">
                ${isReceipt ? parseFloat(v.amount).toFixed(2) : '—'}
            </td>
            <td style="padding:10px; border:1px solid #cbd5e1; text-align:center; font-weight:bold; font-family:monospace; font-size:1rem; color:${isReceipt ? '#334155' : '#e11d48'};">
                ${isReceipt ? '—' : parseFloat(v.amount).toFixed(2)}
            </td>
        </tr>
    `;
    
    // Items
    v.items.forEach(item => {
        rowsHtml += `
            <tr>
                <td style="padding:10px; border:1px solid #cbd5e1; font-weight:500;">
                    ${item.account_name} <span style="font-size:0.75rem; color:#64748b;">(${item.account_code})</span>
                    ${item.memo ? `<br><small style="color:#64748b; font-size:0.75rem;">البيان: ${item.memo}</small>` : ''}
                </td>
                <td style="padding:10px; border:1px solid #cbd5e1; text-align:center; font-weight:bold; font-family:monospace; font-size:1rem; color:${isReceipt ? '#334155' : '#e11d48'};">
                    ${isReceipt ? '—' : parseFloat(item.amount).toFixed(2)}
                </td>
                <td style="padding:10px; border:1px solid #cbd5e1; text-align:center; font-weight:bold; font-family:monospace; font-size:1rem; color:${isReceipt ? '#0d9488' : '#334155'};">
                    ${isReceipt ? parseFloat(item.amount).toFixed(2) : '—'}
                </td>
            </tr>
        `;
    });

    const titleColor = isReceipt ? '#0f766e' : '#be123c';
    const subTitle = isReceipt ? 'سند قبض مالي' : 'سند صرف مالي';
    
    printWindow.document.write(`
        <!DOCTYPE html>
        <html dir="rtl" lang="ar">
        <head>
            <meta charset="UTF-8">
            <title>طباعة سند ${isReceipt ? 'قبض' : 'صرف'} - ${v.voucher_no}</title>
            <style>
                @import url('https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800&display=swap');
                body { font-family: 'Tajawal', sans-serif; padding: 25px; color: #1e293b; background: #fff; margin: 0; }
                .voucher-card { border: 2px solid #e2e8f0; border-radius: 8px; padding: 25px; box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.05); }
                .top-bar { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid ${titleColor}; padding-bottom: 12px; margin-bottom: 20px; }
                .title-main { font-size: 1.6rem; font-weight: 800; color: ${titleColor}; margin: 0; }
                .title-sub { font-size: 0.8rem; color: #64748b; margin-top: 2px; }
                .v-no { font-family: monospace; font-size: 1.2rem; font-weight: 700; background: #f1f5f9; padding: 4px 10px; border-radius: 4px; border: 1px solid #e2e8f0; }
                
                .grid-info { display: grid; grid-template-columns: repeat(2, 1fr); gap: 14px; margin-bottom: 25px; }
                .info-item { font-size: 0.88rem; line-height: 1.5; }
                .info-item strong { color: #475569; display: inline-block; width: 110px; }
                
                .amount-container { background: ${isReceipt ? '#f0fdf4' : '#fff1f2'}; border: 1px solid ${isReceipt ? '#bbf7d0' : '#fecdd3'}; border-radius: 6px; padding: 12px 18px; margin-bottom: 25px; display: inline-flex; align-items: center; gap: 10px; }
                .amount-container span { font-size: 0.95rem; font-weight: 700; color: #475569; }
                .amount-container strong { font-size: 1.4rem; font-weight: 800; color: ${isReceipt ? '#15803d' : '#be123c'}; font-family: monospace; }
                
                .table-ledger { width: 100%; border-collapse: collapse; margin-top: 15px; }
                .table-ledger th { background: #f8fafc; padding: 12px 10px; border: 1px solid #cbd5e1; font-weight: 700; text-align: right; color: #475569; font-size: 0.85rem; }
                .table-ledger td { border: 1px solid #cbd5e1; font-size: 0.88rem; }
                
                .signatures { margin-top: 50px; display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; text-align: center; }
                .sig-box { border-top: 1px dashed #94a3b8; padding-top: 8px; font-size: 0.8rem; font-weight: bold; color: #64748b; margin-top: 35px; }
                
                @media print {
                    body { padding: 0; }
                    .voucher-card { border: none; box-shadow: none; padding: 0; }
                }
            </style>
        </head>
        <body>
            <div class="voucher-card">
                <div class="top-bar">
                    <div>
                        <h1 class="title-main">${subTitle}</h1>
                        <div class="title-sub">نظام إكس بوز لإدارة الحسابات والمبيعات</div>
                    </div>
                    <div class="v-no">${v.voucher_no}</div>
                </div>

                <div class="grid-info">
                    <div class="info-item"><strong>تاريخ السند:</strong> <span>${v.voucher_date}</span></div>
                    <div class="info-item"><strong>نوع الطرف:</strong> <span>${v.party_type === 'customer' ? 'عميل' : (v.party_type === 'supplier' ? 'مورد' : 'أخرى')}</span></div>
                    <div class="info-item"><strong>المستفيد/الجهة:</strong> <span>${v.party_name || '—'}</span></div>
                    <div class="info-item"><strong>البيان العام:</strong> <span>${v.description || '—'}</span></div>
                </div>

                <div class="amount-container">
                    <span>المبلغ الكلي للسند:</span>
                    <strong>${parseFloat(v.amount).toFixed(2)} YER</strong>
                </div>

                <h3 style="font-size:1rem; font-weight:700; color:#334155; margin-bottom:8px; border-bottom:1px solid #e2e8f0; padding-bottom:5px;">التوجيه المحاسبي والقيود:</h3>
                <table class="table-ledger">
                    <thead>
                        <tr>
                            <th style="width: 60%;">الحساب المحاسبي</th>
                            <th style="width: 20%; text-align:center;">مدين (Dr)</th>
                            <th style="width: 20%; text-align:center;">دائن (Cr)</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${rowsHtml}
                    </tbody>
                </table>

                <div class="signatures">
                    <div>
                        <div>المستلم / المستفيد</div>
                        <div class="sig-box">التوقيع والختم</div>
                    </div>
                    <div>
                        <div>المحاسب المسؤول</div>
                        <div class="sig-box">توقيع المحاسب</div>
                    </div>
                    <div>
                        <div>المدير المالي / المعتمد</div>
                        <div class="sig-box">توقيع الاعتماد</div>
                    </div>
                </div>
            </div>

            ` + '<' + 'script' + '>' + `
                window.onload = function() {
                    window.print();
                    setTimeout(function() { window.close(); }, 500);
                }
            ` + '<' + '/script' + '>' + `
        </body>
        </html>
    `);
    printWindow.document.close();
}
</script>
<?php require_once($dir_prefix . 'includes/footer.php'); ?>
