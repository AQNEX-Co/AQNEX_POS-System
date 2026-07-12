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

<title>إدارة الفروع والمستودعات - AQNEX POS</title>

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
.table-formal tbody tr:hover { background: var(--surface-soft); cursor: pointer; }
.table-formal tbody tr.selected-row { background: var(--accent-soft); }

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
                <span class="icon-chip"><i class="bi bi-geo-alt"></i></span>
                إدارة الفروع والمخازن
            </h3>
            <p class="text-muted small mb-0">إضافة وتعديل الفروع والمخازن وربطها بالمستودعات التابعة لتنظيم حركة البضاعة والمبيعات قطاعياً.</p>
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
            $active_tab = 'locations'; 
            require_once 'settings_nav.php'; 
            ?>

            <div class="tab-content tab-content-custom mb-5">
                <div class="tab-pane-inner">
                    <h5 class="section-heading">إدارة الهيكل التنظيمي والفروع والمخازن</h5>
                    
                    <div class="row">
                        <!-- Branch list (Right Panel) -->
                        <div class="col-md-6 mb-4">
                            <div class="formal-card">
                                <div class="formal-card-head d-flex justify-content-between align-items-center">
                                    <span><i class="bi bi-diagram-3 ml-1"></i> قائمة الفروع الحالية</span>
                                    <button class="btn btn-sm btn-light py-0 font-weight-bold" style="font-size:0.75rem;" onclick="showBranchModal(0)">+ فرع جديد</button>
                                </div>
                                <div class="formal-card-body p-0">
                                    <div class="table-responsive">
                                        <table class="table-formal" id="table-branches">
                                            <thead>
                                                <tr>
                                                    <th>اسم الفرع</th>
                                                    <th>الموقع الجغرافي</th>
                                                    <th style="width:120px;">إجراءات</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <!-- Loaded via AJAX -->
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Warehouse list (Left Panel) -->
                        <div class="col-md-6 mb-4">
                            <div class="formal-card">
                                <div class="formal-card-head d-flex justify-content-between align-items-center">
                                    <span id="label-warehouses-title"><i class="bi bi-box ml-1"></i> المستودعات التابعة للفرع المحدد</span>
                                    <button class="btn btn-sm btn-light py-0 font-weight-bold" style="font-size:0.75rem; display:none;" id="btn-add-warehouse" onclick="showWarehouseModal(0)">+ مستودع جديد</button>
                                </div>
                                <div class="formal-card-body p-0">
                                    <div id="warehouse-empty-alert" class="p-3 text-center text-muted small">
                                        يرجى اختيار فرع من القائمة الجانبية لعرض وتعديل مستودعاته المرتبطة.
                                    </div>
                                    <div class="table-responsive" id="table-warehouses-wrapper" style="display:none;">
                                        <table class="table-formal" id="table-warehouses">
                                            <thead>
                                                <tr>
                                                    <th>اسم المستودع</th>
                                                    <th>الموقع</th>
                                                    <th style="width:120px;">إجراءات</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <!-- Loaded via AJAX -->
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

<!-- Branch Modal -->
<div class="modal fade" id="modal-branch" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
            <form id="form-branch">
                <input type="hidden" name="action" value="save_branch">
                <input type="hidden" name="id" id="branch-id" value="0">
                <div class="modal-header">
                    <h5 class="modal-title" id="modal-branch-title"><i class="bi bi-building"></i> فرع تجاري</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body text-right">
                    <div class="form-group mb-3">
                        <label class="field-label">اسم الفرع التجاري *</label>
                        <input type="text" name="name" id="branch-name" class="form-control rounded-0" required>
                    </div>
                    <div class="form-group mb-3">
                        <label class="field-label">الموقع الجغرافي / العنوان</label>
                        <input type="text" name="location" id="branch-location" class="form-control rounded-0">
                    </div>
                    <div class="form-group mb-3">
                        <label class="field-label">بيانات الاتصال والهواتف</label>
                        <input type="text" name="contacts" id="branch-contacts" class="form-control rounded-0">
                    </div>
                </div>
                <div class="modal-footer d-flex justify-content-between">
                    <button type="button" class="btn-formal-secondary" data-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn-formal-success">حفظ الفرع</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Warehouse Modal -->
<div class="modal fade" id="modal-warehouse" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
            <form id="form-warehouse">
                <input type="hidden" name="action" value="save_warehouse">
                <input type="hidden" name="id" id="warehouse-id" value="0">
                <input type="hidden" name="branch_id" id="warehouse-branch-id" value="0">
                <div class="modal-header">
                    <h5 class="modal-title" id="modal-warehouse-title"><i class="bi bi-box"></i> مستودع جديد</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body text-right">
                    <div class="form-group mb-3">
                        <label class="field-label">اسم المستودع / المخزن *</label>
                        <input type="text" name="name" id="warehouse-name" class="form-control rounded-0" required>
                    </div>
                    <div class="form-group mb-3">
                        <label class="field-label">الموقع / الملاحظات</label>
                        <input type="text" name="location" id="warehouse-location" class="form-control rounded-0">
                    </div>
                </div>
                <div class="modal-footer d-flex justify-content-between">
                    <button type="button" class="btn-formal-secondary" data-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn-formal-success">حفظ المخزن</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
var selectedBranchId = 0;
var cacheBranches = [];
var cacheWarehouses = [];

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

// Branches Listing
function loadBranches() {
    var formData = new FormData();
    formData.append('action', 'list_branches');
    makeAjaxCall(formData, function(err, response) {
        if (err) {
            showSettingsAlert('error', err);
            return;
        }
        cacheBranches = response.data || [];
        var tbody = document.querySelector('#table-branches tbody');
        tbody.innerHTML = '';
        if (cacheBranches.length === 0) {
            tbody.innerHTML = '<tr><td colspan="3" class="text-center text-muted">لا يوجد فروع مضافة حالياً.</td></tr>';
            return;
        }
        cacheBranches.forEach(function(b) {
            var tr = document.createElement('tr');
            tr.setAttribute('data-id', b.id);
            if (selectedBranchId === parseInt(b.id)) {
                tr.className = 'selected-row';
            }
            
            // Text values
            var tdName = document.createElement('td');
            tdName.className = 'font-weight-bold';
            tdName.textContent = b.name;
            
            var tdLoc = document.createElement('td');
            tdLoc.textContent = b.location || '-';
            
            // Actions
            var tdAct = document.createElement('td');
            tdAct.innerHTML = '<button class="btn btn-sm btn-outline-primary py-0 px-2 ml-1" onclick="event.stopPropagation(); editBranch(' + b.id + ')">تعديل</button>' +
                              '<button class="btn btn-sm btn-outline-danger py-0 px-2" onclick="event.stopPropagation(); deleteBranch(' + b.id + ')">حذف</button>';
            
            tr.appendChild(tdName);
            tr.appendChild(tdLoc);
            tr.appendChild(tdAct);
            
            // Row click triggers selecting the branch
            tr.addEventListener('click', function() {
                selectBranch(parseInt(b.id));
            });
            tbody.appendChild(tr);
        });
    });
}

function selectBranch(id) {
    selectedBranchId = id;
    
    // Highlight active row
    var rows = document.querySelectorAll('#table-branches tbody tr');
    rows.forEach(function(row) {
        row.classList.remove('selected-row');
        if (parseInt(row.getAttribute('data-id')) === id) {
            row.classList.add('selected-row');
        }
    });

    var branch = cacheBranches.find(function(b) { return parseInt(b.id) === id; });
    var branchName = branch ? branch.name : '';
    
    document.getElementById('label-warehouses-title').innerHTML = '<i class="bi bi-box ml-1"></i> المستودعات التابعة لفرع: <strong class="text-primary">' + branchName + '</strong>';
    document.getElementById('btn-add-warehouse').style.display = 'inline-block';
    document.getElementById('warehouse-empty-alert').style.display = 'none';
    document.getElementById('table-warehouses-wrapper').style.display = 'block';
    
    loadWarehouses(id);
}

// Warehouses Listing
function loadWarehouses(branchId) {
    var formData = new FormData();
    formData.append('action', 'list_warehouses');
    formData.append('branch_id', branchId.toString());
    
    makeAjaxCall(formData, function(err, response) {
        if (err) {
            showSettingsAlert('error', err);
            return;
        }
        cacheWarehouses = response.data || [];
        var tbody = document.querySelector('#table-warehouses tbody');
        tbody.innerHTML = '';
        if (cacheWarehouses.length === 0) {
            tbody.innerHTML = '<tr><td colspan="3" class="text-center text-muted">لا يوجد مستودعات في هذا الفرع.</td></tr>';
            return;
        }
        cacheWarehouses.forEach(function(w) {
            var tr = document.createElement('tr');
            var tdName = document.createElement('td');
            tdName.className = 'font-weight-bold';
            tdName.textContent = w.name;
            
            var tdLoc = document.createElement('td');
            tdLoc.textContent = w.location || '-';
            
            var tdAct = document.createElement('td');
            tdAct.innerHTML = '<button class="btn btn-sm btn-outline-primary py-0 px-2 ml-1" onclick="editWarehouse(' + w.id + ')">تعديل</button>' +
                              '<button class="btn btn-sm btn-outline-danger py-0 px-2" onclick="deleteWarehouse(' + w.id + ')">حذف</button>';
            
            tr.appendChild(tdName);
            tr.appendChild(tdLoc);
            tr.appendChild(tdAct);
            tbody.appendChild(tr);
        });
    });
}

// Branch Modal Controls
function showBranchModal(id) {
    document.getElementById('branch-id').value = id;
    if (id > 0) {
        document.getElementById('modal-branch-title').innerHTML = '<i class="bi bi-building"></i> تعديل بيانات الفرع';
        var b = cacheBranches.find(function(item) { return parseInt(item.id) === id; });
        if (b) {
            document.getElementById('branch-name').value = b.name;
            document.getElementById('branch-location').value = b.location || '';
            document.getElementById('branch-contacts').value = b.contacts || '';
        }
    } else {
        document.getElementById('modal-branch-title').innerHTML = '<i class="bi bi-building"></i> إضافة فرع تجاري جديد';
        document.getElementById('branch-name').value = '';
        document.getElementById('branch-location').value = '';
        document.getElementById('branch-contacts').value = '';
    }
    $('#modal-branch').modal('show');
}

function editBranch(id) {
    showBranchModal(id);
}

function deleteBranch(id) {
    if (id <= 1) {
        alert('لا يمكن حذف الفرع الرئيسي الافتراضي للنظام.');
        return;
    }
    if (!confirm('هل أنت متأكد من حذف هذا الفرع نهائياً؟ تنبيه: سيتم تعذر الوصول للمستودعات والفواتير التابعة له!')) {
        return;
    }
    var formData = new FormData();
    formData.append('action', 'delete_branch');
    formData.append('id', id.toString());
    
    makeAjaxCall(formData, function(err, response) {
        if (err) {
            alert(err);
        } else {
            showSettingsAlert('success', response.message);
            if (selectedBranchId === id) {
                selectedBranchId = 0;
                document.getElementById('btn-add-warehouse').style.display = 'none';
                document.getElementById('warehouse-empty-alert').style.display = 'block';
                document.getElementById('table-warehouses-wrapper').style.display = 'none';
            }
            loadBranches();
        }
    });
}

// Warehouse Modal Controls
function showWarehouseModal(id) {
    document.getElementById('warehouse-branch-id').value = selectedBranchId;
    document.getElementById('warehouse-id').value = id;
    if (id > 0) {
        document.getElementById('modal-warehouse-title').innerHTML = '<i class="bi bi-box"></i> تعديل مستودع';
        var w = cacheWarehouses.find(function(item) { return parseInt(item.id) === id; });
        if (w) {
            document.getElementById('warehouse-name').value = w.name;
            document.getElementById('warehouse-location').value = w.location || '';
        }
    } else {
        document.getElementById('modal-warehouse-title').innerHTML = '<i class="bi bi-box"></i> إضافة مستودع جديد للفرع';
        document.getElementById('warehouse-name').value = '';
        document.getElementById('warehouse-location').value = '';
    }
    $('#modal-warehouse').modal('show');
}

function editWarehouse(id) {
    showWarehouseModal(id);
}

function deleteWarehouse(id) {
    if (id <= 1) {
        alert('لا يمكن حذف المستودع الرئيسي الافتراضي.');
        return;
    }
    if (!confirm('هل أنت متأكد من حذف هذا المستودع؟ ستفقد الكميات المسجلة به!')) {
        return;
    }
    var formData = new FormData();
    formData.append('action', 'delete_warehouse');
    formData.append('id', id.toString());
    
    makeAjaxCall(formData, function(err, response) {
        if (err) {
            alert(err);
        } else {
            showSettingsAlert('success', response.message);
            loadWarehouses(selectedBranchId);
        }
    });
}

document.addEventListener('DOMContentLoaded', function() {
    // Form submissions
    var formBranch = document.getElementById('form-branch');
    if (formBranch) {
        formBranch.addEventListener('submit', function(e) {
            e.preventDefault();
            var formData = new FormData(this);
            makeAjaxCall(formData, function(err, response) {
                if (err) {
                    alert(err);
                } else {
                    $('#modal-branch').modal('hide');
                    showSettingsAlert('success', response.message);
                    loadBranches();
                }
            });
        });
    }

    var formWarehouse = document.getElementById('form-warehouse');
    if (formWarehouse) {
        formWarehouse.addEventListener('submit', function(e) {
            e.preventDefault();
            var formData = new FormData(this);
            makeAjaxCall(formData, function(err, response) {
                if (err) {
                    alert(err);
                } else {
                    $('#modal-warehouse').modal('hide');
                    showSettingsAlert('success', response.message);
                    loadWarehouses(selectedBranchId);
                }
            });
        });
    }

    // Initial Listing
    loadBranches();
});
</script>

<?php
require_once($dir_prefix . 'includes/footer.php');
?>
