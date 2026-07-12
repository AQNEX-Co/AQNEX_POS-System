<?php
declare(strict_types=1);

$dir_prefix = '../';
$module = 'settings';

require_once($dir_prefix . 'includes/header.php');
\AQNEX\Services\AuthService::ensureLogin();

// Check basic permissions
if (!\AQNEX\Services\AuthService::isAdmin() && \AQNEX\Services\AuthService::currentUserRole() !== 'Support') {
    \AQNEX\Services\AuthService::denyAccess();
}

$pdo = \AQNEX\Config\Database::createPdo();
if (!$pdo) {
    die('فشل الاتصال بقاعدة البيانات.');
}
?>

<title>النظام المالي والضرائب - AQNEX POS</title>

<style>
/* Same shared POS settings styling */
:root {
    --ink-900: #0f1b2d;
    --ink-700: #1e3148;
    --ink-500: #46607e;
    --ink-300: #8fa3ba;
    --line: #e1e7ee;
    --surface: #ffffff;
    --surface-soft: #f6f8fb;
    --accent: #1d4ed8;
    --accent-dark: #1e3a8a;
    --accent-soft: #eaf0ff;
    --gold: #b9892f;
    --good: #15803d;
    --good-soft: #ecfdf3;
    --bad: #b91c1c;
    --bad-soft: #fef2f2;
    --radius: 4px;
}

.settings-shell { font-family: inherit; margin-top: 15px; }

.settings-head {
    border-bottom: 2px solid var(--ink-900);
    padding-bottom: 16px;
    margin-bottom: 24px;
}
.settings-head .eyebrow {
    text-transform: uppercase;
    letter-spacing: .08em;
    font-size: 11px;
    font-weight: 700;
    color: var(--accent);
    margin-bottom: 4px;
    display: block;
}
.settings-head h3 {
    color: var(--ink-900);
    font-weight: 800;
    margin-bottom: 2px;
}
.settings-head .icon-chip {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 38px;
    height: 38px;
    border-radius: 8px;
    background: var(--accent-soft);
    color: var(--accent-dark);
    margin-left: 10px;
}

.alert-formal {
    border-radius: var(--radius);
    border: 1px solid;
    padding: 13px 18px;
    font-weight: 600;
    font-size: 14px;
}
.alert-formal.is-error { background: var(--bad-soft); border-color: #fecaca; color: var(--bad); }
.alert-formal.is-success { background: var(--good-soft); border-color: #bbf7d0; color: var(--good); }

.nav-tabs-custom {
    margin-bottom: 0;
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
    border: none;
    padding-left: 0;
    list-style: none;
    background: var(--ink-900);
    border-radius: 8px 8px 0 0;
    padding: 6px;
}
.nav-tabs-custom .nav-item { margin-bottom: 0; }
.nav-tabs-custom .nav-link {
    border-radius: 5px !important;
    border: none !important;
    font-weight: 700;
    font-size: 13.5px;
    color: #c3cedb !important;
    padding: 11px 18px;
    background: transparent;
    transition: all 0.15s ease-in-out;
    display: inline-flex;
    align-items: center;
}
.nav-tabs-custom .nav-link:hover {
    color: #fff !important;
    background-color: rgba(255,255,255,0.08);
}
.nav-tabs-custom .nav-link.active {
    background-color: var(--accent) !important;
    color: #ffffff !important;
}

.tab-content-custom {
    background: var(--surface);
    padding: 0;
    border: 1px solid var(--line);
    border-top: none;
    border-radius: 0 0 8px 8px;
    box-shadow: 0 1px 2px rgba(15,27,45,0.04);
}
.tab-pane-inner { padding: 32px; }

.section-heading {
    color: var(--ink-900);
    font-weight: 800;
    font-size: 15px;
    text-transform: uppercase;
    letter-spacing: .03em;
    border-bottom: 1px solid var(--line);
    padding-bottom: 12px;
    margin-bottom: 24px;
    display: flex;
    align-items: center;
    gap: 8px;
}
.section-heading::before {
    content: "";
    width: 4px;
    height: 16px;
    background: var(--accent);
    display: inline-block;
    border-radius: 2px;
}

.field-label {
    font-weight: 700;
    font-size: 12.5px;
    color: var(--ink-700);
    margin-bottom: 7px;
    display: block;
}
.form-control.rounded-0 {
    border: 1px solid var(--line);
    border-radius: var(--radius) !important;
    font-size: 13.5px;
    color: var(--ink-900);
    padding: 9px 12px;
    height: auto;
    transition: border-color .15s ease, box-shadow .15s ease;
}
.form-control.rounded-0:focus {
    border-color: var(--accent);
    box-shadow: 0 0 0 3px var(--accent-soft) !important;
}

.formal-card {
    border: 1px solid var(--line);
    border-radius: 6px !important;
    background: var(--surface);
    overflow: hidden;
    margin-bottom: 20px;
}
.formal-card-head {
    background: var(--ink-900);
    color: #fff;
    padding: 13px 18px;
    font-weight: 700;
    font-size: 13.5px;
    display: flex;
    align-items: center;
    gap: 8px;
}
.formal-card-body { padding: 20px; }

.badge-formal {
    display: inline-block;
    padding: 3px 11px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 700;
}
.badge-formal.success { background: var(--good-soft); color: var(--good); }
.badge-formal.danger { background: var(--bad-soft); color: var(--bad); }

.table-formal {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}
.table-formal thead th {
    background: var(--surface-soft);
    color: var(--ink-700);
    font-weight: 700;
    font-size: 11.5px;
    padding: 11px 14px;
    border-bottom: 2px solid var(--line);
    text-align: right;
}
.table-formal tbody td {
    padding: 11px 14px;
    border-bottom: 1px solid var(--line);
    color: var(--ink-900);
    vertical-align: middle;
}
.table-formal tbody tr:hover { background: var(--surface-soft); }

.btn-formal-primary {
    background: var(--accent);
    color: #fff;
    border: 1px solid var(--accent);
    border-radius: var(--radius) !important;
    font-weight: 700;
    font-size: 13px;
    padding: 10px 22px;
    transition: background .15s ease;
    cursor: pointer;
}
.btn-formal-primary:hover { background: var(--accent-dark); color: #fff; }

.btn-formal-secondary {
    background: var(--surface);
    color: var(--ink-700);
    border: 1px solid var(--line);
    border-radius: var(--radius) !important;
    font-weight: 700;
    font-size: 13px;
    padding: 10px 22px;
    cursor: pointer;
}
.btn-formal-secondary:hover { background: var(--surface-soft); color: var(--ink-900); }

.btn-formal-success {
    background: var(--good);
    color: #fff;
    border: 1px solid var(--good);
    border-radius: var(--radius) !important;
    font-weight: 700;
    font-size: 13px;
    padding: 10px 22px;
    cursor: pointer;
}
.btn-formal-success:hover { background: #126b34; color: #fff; }
</style>

<div class="settings-shell">
    <div class="row mb-3 no-print align-items-center">
        <div class="col-md-7">
            <span class="eyebrow">إعدادات النظام وإدارة الموارد</span>
            <h3 class="mb-1">
                <span class="icon-chip"><i class="bi bi-calendar-event"></i></span>
                النظام المالي والضرائب
            </h3>
            <p class="text-muted small mb-0">تهيئة السنين والفترات المالية لترحيل الحسابات، وإدارة المجموعات الضريبية ونسب الرسوم المفروضة.</p>
        </div>
        <div class="col-md-5 text-left">
            <a href="../home.php" class="btn btn-outline-secondary font-weight-bold" style="padding: 8px 16px; border-radius: var(--radius); font-size: 13px;">
                <i class="bi bi-arrow-right-short ml-1"></i> العودة للرئيسية
            </a>
        </div>
    </div>

    <div class="row justify-content-center no-print">
        <div class="col-lg-12">
            
            <!-- Dynamic Notifications Box -->
            <div id="settings-alert-box" class="alert-formal mb-4" style="display:none;"></div>

            <!-- Shared Sub-Navigation Menu -->
            <?php 
            $active_tab = 'financial'; 
            require_once 'settings_nav.php'; 
            ?>

            <div class="tab-content tab-content-custom mb-5">
                <div class="tab-pane-inner">
                    <h5 class="section-heading">إعدادات النظام المالي، الفترات، والضرائب المطبقة</h5>
                    
                    <div class="row">
                        <!-- Fiscal Years CRUD -->
                        <div class="col-md-6 mb-4">
                            <div class="formal-card">
                                <div class="formal-card-head d-flex justify-content-between align-items-center">
                                    <span><i class="bi bi-calendar-event ml-1"></i> إدارة السنين والفترات المالية</span>
                                    <button class="btn btn-sm btn-light py-0 font-weight-bold" style="font-size:0.75rem;" onclick="showFiscalYearModal(0)">+ فترة جديدة</button>
                                </div>
                                <div class="formal-card-body p-0">
                                    <div class="table-responsive">
                                        <table class="table-formal" id="table-fiscal-years">
                                            <thead>
                                                <tr>
                                                    <th>اسم الفترة</th>
                                                    <th>تاريخ البدء والانتهاء</th>
                                                    <th>حالة الإغلاق</th>
                                                    <th style="width:120px;">إجراء</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <!-- loaded via AJAX -->
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Tax Groups CRUD -->
                        <div class="col-md-6 mb-4">
                            <div class="formal-card">
                                <div class="formal-card-head d-flex justify-content-between align-items-center">
                                    <span><i class="bi bi-percent ml-1"></i> مجموعات الضرائب والرسوم</span>
                                    <button class="btn btn-sm btn-light py-0 font-weight-bold" style="font-size:0.75rem;" onclick="showTaxGroupModal(0)">+ مجموعة ضريبية</button>
                                </div>
                                <div class="formal-card-body p-0">
                                    <div class="table-responsive">
                                        <table class="table-formal" id="table-tax-groups">
                                            <thead>
                                                <tr>
                                                    <th>اسم الضريبة</th>
                                                    <th>النسبة المئوية (%)</th>
                                                    <th>الحالة</th>
                                                    <th style="width:120px;">إجراء</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <!-- loaded via AJAX -->
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
    </div>
</div>

<!-- =======================================================================
     MODALS FOR CRUD OPERATIONS
     ======================================================================= -->

<!-- Fiscal Year Modal -->
<div class="modal fade" id="modal-fiscal-year" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
            <form id="form-fiscal-year">
                <input type="hidden" name="action" value="save_fiscal_year">
                <input type="hidden" name="id" id="fiscal-id" value="0">
                <div class="modal-header">
                    <h5 class="modal-title" id="modal-fiscal-title"><i class="bi bi-calendar-event"></i> فترة مالية</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body text-right">
                    <div class="form-group mb-3">
                        <label class="field-label">اسم الفترة المالية *</label>
                        <input type="text" name="name" id="fiscal-name" class="form-control rounded-0" placeholder="مثال: السنة المالية 2026" required>
                    </div>
                    <div class="row">
                        <div class="col-md-6 form-group mb-3">
                            <label class="field-label">تاريخ البدء *</label>
                            <input type="date" name="start_date" id="fiscal-start" class="form-control rounded-0" required>
                        </div>
                        <div class="col-md-6 form-group mb-3">
                            <label class="field-label">تاريخ الانتهاء *</label>
                            <input type="date" name="end_date" id="fiscal-end" class="form-control rounded-0" required>
                        </div>
                    </div>
                    <div class="form-group mb-3">
                        <div class="form-check">
                            <input type="checkbox" name="is_closed" id="fiscal-closed" class="form-check-input" value="1">
                            <label class="form-check-label font-weight-bold text-secondary mr-1" for="fiscal-closed">إغلاق الفترة المالية (تجميد العمليات المباشرة)</label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer d-flex justify-content-between">
                    <button type="button" class="btn-formal-secondary" data-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn-formal-success">حفظ الفترة المالية</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Tax Group Modal -->
<div class="modal fade" id="modal-tax-group" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
            <form id="form-tax-group">
                <input type="hidden" name="action" value="save_tax_group">
                <input type="hidden" name="id" id="tax-id" value="0">
                <div class="modal-header">
                    <h5 class="modal-title" id="modal-tax-title"><i class="bi bi-percent"></i> مجموعة ضريبية</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body text-right">
                    <div class="form-group mb-3">
                        <label class="field-label">اسم المجموعة الضريبية *</label>
                        <input type="text" name="name" id="tax-name" class="form-control rounded-0" placeholder="مثال: ضريبة القيمة المضافة 15%" required>
                    </div>
                    <div class="form-group mb-3">
                        <label class="field-label">النسبة المئوية الضريبية (%) *</label>
                        <input type="number" step="any" min="0" max="100" name="rate" id="tax-rate" class="form-control rounded-0 text-center font-weight-bold" placeholder="15.00" required>
                    </div>
                    <div class="form-group mb-3">
                        <div class="form-check">
                            <input type="checkbox" name="is_active" id="tax-active" class="form-check-input" value="1" checked>
                            <label class="form-check-label font-weight-bold text-secondary mr-1" for="tax-active">تنشيط المجموعة الضريبية فورا</label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer d-flex justify-content-between">
                    <button type="button" class="btn-formal-secondary" data-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn-formal-success">حفظ المجموعة</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
var cacheFiscalYears = [];
var cacheTaxGroups = [];

function showSettingsAlert(status, message) {
    var box = document.getElementById('settings-alert-box');
    if (!box) return;
    box.className = 'alert-formal ' + (status === 'success' ? 'is-success' : 'is-error');
    box.textContent = message;
    box.style.display = 'block';
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function makeAjaxCall(formData, callback) {
    fetch('ajax_handler.php', {
        method: 'POST',
        body: formData
    })
    .then(function(res) { return res.json(); })
    .then(function(data) {
        if (data.status === 'success') {
            callback(null, data);
        } else {
            callback(data.message || 'حدث خطأ غير معروف');
        }
    })
    .catch(function(err) {
        callback('فشل الاتصال بالخادم: ' + err);
    });
}

// Fiscal Years functions
function loadFiscalYears() {
    var formData = new FormData();
    formData.append('action', 'list_fiscal_years');
    makeAjaxCall(formData, function(err, response) {
        if (err) {
            showSettingsAlert('error', err);
            return;
        }
        cacheFiscalYears = response.data || [];
        var tbody = document.querySelector('#table-fiscal-years tbody');
        tbody.innerHTML = '';
        if (cacheFiscalYears.length === 0) {
            tbody.innerHTML = '<tr><td colspan="4" class="text-center text-muted">لا يوجد فترات مالية مضافة.</td></tr>';
            return;
        }
        cacheFiscalYears.forEach(function(f) {
            var tr = document.createElement('tr');
            var tdName = document.createElement('td');
            tdName.className = 'font-weight-bold';
            tdName.textContent = f.name;
            
            var tdDates = document.createElement('td');
            tdDates.textContent = f.start_date + ' ~ ' + f.end_date;
            
            var tdStatus = document.createElement('td');
            tdStatus.innerHTML = (parseInt(f.is_closed) === 1) 
                ? '<span class="badge-formal danger">مغلقة</span>' 
                : '<span class="badge-formal success">نشطة ومفتوحة</span>';
                
            var tdAct = document.createElement('td');
            tdAct.innerHTML = '<button class="btn btn-sm btn-outline-primary py-0 px-2 ml-1" onclick="editFiscalYear(' + f.id + ')">تعديل</button>' +
                              '<button class="btn btn-sm btn-outline-danger py-0 px-2" onclick="deleteFiscalYear(' + f.id + ')">حذف</button>';
            
            tr.appendChild(tdName);
            tr.appendChild(tdDates);
            tr.appendChild(tdStatus);
            tr.appendChild(tdAct);
            tbody.appendChild(tr);
        });
    });
}

function showFiscalYearModal(id) {
    document.getElementById('fiscal-id').value = id;
    if (id > 0) {
        document.getElementById('modal-fiscal-title').innerHTML = '<i class="bi bi-calendar-event"></i> تعديل فترة مالية';
        var f = cacheFiscalYears.find(function(item) { return parseInt(item.id) === id; });
        if (f) {
            document.getElementById('fiscal-name').value = f.name;
            document.getElementById('fiscal-start').value = f.start_date;
            document.getElementById('fiscal-end').value = f.end_date;
            document.getElementById('fiscal-closed').checked = (parseInt(f.is_closed) === 1);
        }
    } else {
        document.getElementById('modal-fiscal-title').innerHTML = '<i class="bi bi-calendar-event"></i> إضافة فترة مالية جديدة';
        document.getElementById('fiscal-name').value = '';
        document.getElementById('fiscal-start').value = '';
        document.getElementById('fiscal-end').value = '';
        document.getElementById('fiscal-closed').checked = false;
    }
    $('#modal-fiscal-year').modal('show');
}

function editFiscalYear(id) {
    showFiscalYearModal(id);
}

function deleteFiscalYear(id) {
    if (!confirm('هل أنت متأكد من حذف هذه الفترة المالية؟')) {
        return;
    }
    var formData = new FormData();
    formData.append('action', 'delete_fiscal_year');
    formData.append('id', id.toString());
    
    makeAjaxCall(formData, function(err, response) {
        if (err) {
            alert(err);
        } else {
            showSettingsAlert('success', response.message);
            loadFiscalYears();
        }
    });
}

// Tax Groups functions
function loadTaxGroups() {
    var formData = new FormData();
    formData.append('action', 'list_tax_groups');
    makeAjaxCall(formData, function(err, response) {
        if (err) {
            showSettingsAlert('error', err);
            return;
        }
        cacheTaxGroups = response.data || [];
        var tbody = document.querySelector('#table-tax-groups tbody');
        tbody.innerHTML = '';
        if (cacheTaxGroups.length === 0) {
            tbody.innerHTML = '<tr><td colspan="4" class="text-center text-muted">لا يوجد مجموعات ضريبية مضافة.</td></tr>';
            return;
        }
        cacheTaxGroups.forEach(function(t) {
            var tr = document.createElement('tr');
            var tdName = document.createElement('td');
            tdName.className = 'font-weight-bold';
            tdName.textContent = t.name;
            
            var tdRate = document.createElement('td');
            tdRate.className = 'text-center font-weight-bold';
            tdRate.textContent = parseFloat(t.rate).toFixed(2) + ' %';
            
            var tdStatus = document.createElement('td');
            tdStatus.innerHTML = (parseInt(t.is_active) === 1) 
                ? '<span class="badge-formal success">مفعلة</span>' 
                : '<span class="badge-formal danger">معطلة</span>';
                
            var tdAct = document.createElement('td');
            tdAct.innerHTML = '<button class="btn btn-sm btn-outline-primary py-0 px-2 ml-1" onclick="editTaxGroup(' + t.id + ')">تعديل</button>' +
                              '<button class="btn btn-sm btn-outline-danger py-0 px-2" onclick="deleteTaxGroup(' + t.id + ')">حذف</button>';
            
            tr.appendChild(tdName);
            tr.appendChild(tdRate);
            tr.appendChild(tdStatus);
            tr.appendChild(tdAct);
            tbody.appendChild(tr);
        });
    });
}

function showTaxGroupModal(id) {
    document.getElementById('tax-id').value = id;
    if (id > 0) {
        document.getElementById('modal-tax-title').innerHTML = '<i class="bi bi-percent"></i> تعديل مجموعة ضريبية';
        var t = cacheTaxGroups.find(function(item) { return parseInt(item.id) === id; });
        if (t) {
            document.getElementById('tax-name').value = t.name;
            document.getElementById('tax-rate').value = t.rate;
            document.getElementById('tax-active').checked = (parseInt(t.is_active) === 1);
        }
    } else {
        document.getElementById('modal-tax-title').innerHTML = '<i class="bi bi-percent"></i> إضافة مجموعة ضريبية جديدة';
        document.getElementById('tax-name').value = '';
        document.getElementById('tax-rate').value = '';
        document.getElementById('tax-active').checked = true;
    }
    $('#modal-tax-group').modal('show');
}

function editTaxGroup(id) {
    showTaxGroupModal(id);
}

function deleteTaxGroup(id) {
    if (!confirm('هل أنت متأكد من حذف هذه المجموعة الضريبية؟')) {
        return;
    }
    var formData = new FormData();
    formData.append('action', 'delete_tax_group');
    formData.append('id', id.toString());
    
    makeAjaxCall(formData, function(err, response) {
        if (err) {
            alert(err);
        } else {
            showSettingsAlert('success', response.message);
            loadTaxGroups();
        }
    });
}

document.addEventListener('DOMContentLoaded', function() {
    // Form submissions
    var formFiscal = document.getElementById('form-fiscal-year');
    if (formFiscal) {
        formFiscal.addEventListener('submit', function(e) {
            e.preventDefault();
            var formData = new FormData(this);
            makeAjaxCall(formData, function(err, response) {
                if (err) {
                    alert(err);
                } else {
                    $('#modal-fiscal-year').modal('hide');
                    showSettingsAlert('success', response.message);
                    loadFiscalYears();
                }
            });
        });
    }

    var formTax = document.getElementById('form-tax-group');
    if (formTax) {
        formTax.addEventListener('submit', function(e) {
            e.preventDefault();
            var formData = new FormData(this);
            makeAjaxCall(formData, function(err, response) {
                if (err) {
                    alert(err);
                } else {
                    $('#modal-tax-group').modal('hide');
                    showSettingsAlert('success', response.message);
                    loadTaxGroups();
                }
            });
        });
    }

    // Initial Listing
    loadFiscalYears();
    loadTaxGroups();
});
</script>

<?php
require_once($dir_prefix . 'includes/footer.php');
?>
