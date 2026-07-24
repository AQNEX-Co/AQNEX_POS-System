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

<title>إدارة العملات وأسعار الصرف - AQNEX POS</title>

<link rel="stylesheet" href="<?php echo $prefix; ?>assets/css/settings.css">

<div class="settings-shell">
    <div class="row mb-3 no-print align-items-center">
        <div class="col-md-7">
            <span class="eyebrow">إعدادات النظام وإدارة الموارد</span>
            <h3 class="mb-1">
                <span class="icon-chip"><i class="bi bi-cash-coin"></i></span>
                تهيئة وإدارة العملات
            </h3>
            <p class="text-muted small mb-0">تحديد العملة الأساسية للمتجر، وإدخال عملات موازية إضافية مع نسب الصرف للمبيعات والمشتريات المتعددة.</p>
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
            $active_tab = 'currencies'; 
            require_once 'settings_nav.php'; 
            ?>

            <div class="tab-content tab-content-custom mb-5">
                <div class="tab-pane-inner">
                    <h5 class="section-heading">قائمة العملات وأسعار الصرف الحالية</h5>
                    
                    <div class="row">
                        <!-- Currency table (Right Panel) -->
                        <div class="col-md-8 mb-4">
                            <div class="formal-card">
                                <div class="formal-card-head d-flex justify-content-between align-items-center">
                                    <span><i class="bi bi-table ml-1"></i> العملات المضافة</span>
                                    <button class="btn btn-sm btn-light py-0 font-weight-bold" style="font-size:0.75rem;" onclick="showCurrencyModal(0)">+ عملة جديدة</button>
                                </div>
                                <div class="formal-card-body p-0">
                                    <div class="table-responsive">
                                        <table class="table-formal" id="table-currencies">
                                            <thead>
                                                <tr>
                                                    <th>اسم العملة</th>
                                                    <th>الرمز الدولي</th>
                                                    <th>العلامة</th>
                                                    <th class="text-center">سعر الصرف (نسبة للعملة الأساسية)</th>
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

                        <!-- Info Card (Left Panel) -->
                        <div class="col-md-4 mb-4">
                            <div class="formal-card">
                                <div class="formal-card-head is-accent">
                                    <i class="bi bi-info-circle ml-1"></i> ملاحظة حول العملات
                                </div>
                                <div class="formal-card-body small text-muted text-right">
                                    <p>• <strong>العملة الأساسية:</strong> هي العملة المرجعية للنظام ويبلغ سعر صرفها دائماً 1.0.</p>
                                    <p>• <strong>العملات الإضافية:</strong> يتم إدخال سعر صرفها مقارنة بالعملة الأساسية (مثلاً إذا كانت الأساسية ريال يمني، فسعر صرف الدولار يكون 530.0).</p>
                                    <p>• لا يمكن حذف العملة المحددة كعملة أساسية للنظام، ويجب تعيين عملة أساسية بديلة أولاً لتتمكن من حذفها.</p>
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

<!-- Currency Modal -->
<div class="modal fade" id="modal-currency" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
            <form id="form-currency">
                <input type="hidden" name="action" value="save_currency">
                <input type="hidden" name="id" id="currency-id" value="0">
                <div class="modal-header">
                    <h5 class="modal-title" id="modal-currency-title"><i class="bi bi-cash-coin"></i> عملة صرف</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body text-right">
                    <div class="form-group mb-3">
                        <label class="field-label">اسم العملة *</label>
                        <input type="text" name="name" id="currency-name" class="form-control rounded-0" placeholder="مثال: دولار أمريكي" required>
                    </div>
                    <div class="row">
                        <div class="col-md-6 form-group mb-3">
                            <label class="field-label">رمز العملة الدولي (Code) *</label>
                            <input type="text" name="code" id="currency-code" class="form-control rounded-0 text-center" placeholder="مثال: USD" required>
                        </div>
                        <div class="col-md-6 form-group mb-3">
                            <label class="field-label">العلامة / الرمز (Symbol) *</label>
                            <input type="text" name="symbol" id="currency-symbol" class="form-control rounded-0 text-center" placeholder="مثال: $" required>
                        </div>
                    </div>
                    <div class="form-group mb-3">
                        <label class="field-label">سعر الصرف مقابل العملة الأساسية *</label>
                        <input type="number" step="any" min="0.000001" name="exchange_rate" id="currency-rate" class="form-control rounded-0 text-center font-weight-bold" placeholder="1.0" required>
                        <span class="field-hint">للموازية الإضافية أدخل قيمتها بالعملة الأساسية. للعملة الأساسية اتركها 1.0.</span>
                    </div>
                    <div class="form-group mb-3">
                        <div class="form-check">
                            <input type="checkbox" name="is_base" id="currency-base" class="form-check-input" value="1">
                            <label class="form-check-label font-weight-bold text-secondary mr-1" for="currency-base">تعيين كعملة مرجعية أساسية للنظام (Base Currency)</label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer d-flex justify-content-between">
                    <button type="button" class="btn-formal-secondary" data-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn-formal-success">حفظ العملة</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
var cacheCurrencies = [];

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

function loadCurrencies() {
    var formData = new FormData();
    formData.append('action', 'list_currencies');
    makeAjaxCall(formData, function(err, response) {
        if (err) {
            showSettingsAlert('error', err);
            return;
        }
        cacheCurrencies = response.data || [];
        var tbody = document.querySelector('#table-currencies tbody');
        tbody.innerHTML = '';
        if (cacheCurrencies.length === 0) {
            tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted">لا يوجد عملات مضافة.</td></tr>';
            return;
        }
        cacheCurrencies.forEach(function(c) {
            var tr = document.createElement('tr');
            
            var tdName = document.createElement('td');
            tdName.className = 'font-weight-bold';
            tdName.textContent = c.name;
            
            var tdCode = document.createElement('td');
            tdCode.className = 'font-weight-bold text-secondary';
            tdCode.textContent = c.code;
            
            var tdSym = document.createElement('td');
            tdSym.className = 'text-center font-weight-bold';
            tdSym.textContent = c.symbol;
            
            var tdRate = document.createElement('td');
            tdRate.className = 'text-center font-weight-bold text-primary';
            tdRate.textContent = parseFloat(c.exchange_rate).toFixed(4);
            
            var tdStatus = document.createElement('td');
            tdStatus.innerHTML = (parseInt(c.is_base) === 1)
                ? '<span class="badge-formal success">أساسية</span>'
                : '<span class="badge-formal secondary">موازية</span>';
                
            var tdAct = document.createElement('td');
            tdAct.innerHTML = '<button class="btn btn-sm btn-outline-primary py-0 px-2 ml-1" onclick="editCurrency(' + c.id + ')">تعديل</button>' +
                              '<button class="btn btn-sm btn-outline-danger py-0 px-2" onclick="deleteCurrency(' + c.id + ')">حذف</button>';
            
            tr.appendChild(tdName);
            tr.appendChild(tdCode);
            tr.appendChild(tdSym);
            tr.appendChild(tdRate);
            tr.appendChild(tdStatus);
            tr.appendChild(tdAct);
            tbody.appendChild(tr);
        });
    });
}

function showCurrencyModal(id) {
    document.getElementById('currency-id').value = id;
    var baseCheck = document.getElementById('currency-base');
    var rateInput = document.getElementById('currency-rate');
    
    if (id > 0) {
        document.getElementById('modal-currency-title').innerHTML = '<i class="bi bi-cash-coin"></i> تعديل بيانات العملة';
        var c = cacheCurrencies.find(function(item) { return parseInt(item.id) === id; });
        if (c) {
            document.getElementById('currency-name').value = c.name;
            document.getElementById('currency-code').value = c.code;
            document.getElementById('currency-symbol').value = c.symbol;
            rateInput.value = c.exchange_rate;
            
            if (parseInt(c.is_base) === 1) {
                baseCheck.checked = true;
                baseCheck.disabled = true; // Cannot uncheck base directly from here (must select another base)
                rateInput.readOnly = true;
            } else {
                baseCheck.checked = false;
                baseCheck.disabled = false;
                rateInput.readOnly = false;
            }
        }
    } else {
        document.getElementById('modal-currency-title').innerHTML = '<i class="bi bi-cash-coin"></i> إضافة عملة صرف جديدة';
        document.getElementById('currency-name').value = '';
        document.getElementById('currency-code').value = '';
        document.getElementById('currency-symbol').value = '';
        rateInput.value = '';
        rateInput.readOnly = false;
        baseCheck.checked = false;
        baseCheck.disabled = false;
    }
    $('#modal-currency').modal('show');
}

// Watch base checkbox to toggle rate input readonly
document.getElementById('currency-base').addEventListener('change', function() {
    var rateInput = document.getElementById('currency-rate');
    if (this.checked) {
        rateInput.value = '1.0';
        rateInput.readOnly = true;
    } else {
        rateInput.readOnly = false;
    }
});

function editCurrency(id) {
    showCurrencyModal(id);
}

function deleteCurrency(id) {
    if (id === 1) {
        alert('لا يمكن حذف العملة الأساسية للنظام.');
        return;
    }
    var c = cacheCurrencies.find(function(item) { return parseInt(item.id) === id; });
    if (c && parseInt(c.is_base) === 1) {
        alert('هذه العملة معلمة كعملة أساسية. يرجى تعيين عملة أخرى كعملة أساسية أولاً لتتمكن من حذفها.');
        return;
    }
    if (!confirm('هل أنت متأكد من حذف هذه العملة نهائياً؟')) {
        return;
    }
    
    var formData = new FormData();
    formData.append('action', 'delete_currency');
    formData.append('id', id.toString());
    
    makeAjaxCall(formData, function(err, response) {
        if (err) {
            alert(err);
        } else {
            showSettingsAlert('success', response.message);
            loadCurrencies();
        }
    });
}

document.addEventListener('DOMContentLoaded', function() {
    var formCurrency = document.getElementById('form-currency');
    if (formCurrency) {
        formCurrency.addEventListener('submit', function(e) {
            e.preventDefault();
            var formData = new FormData(this);
            // If checkbox disabled, FormData won't send it, so manually append if checked
            var baseCheck = document.getElementById('currency-base');
            if (baseCheck.disabled && baseCheck.checked) {
                formData.append('is_base', '1');
            }
            makeAjaxCall(formData, function(err, response) {
                if (err) {
                    alert(err);
                } else {
                    $('#modal-currency').modal('hide');
                    showSettingsAlert('success', response.message);
                    loadCurrencies();
                }
            });
        });
    }

    loadCurrencies();
});
</script>

<?php
require_once($dir_prefix . 'includes/footer.php');
?>
