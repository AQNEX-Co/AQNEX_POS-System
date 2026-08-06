</div> <!-- End #content -->
    </div> <!-- End .wrapper -->
    
    <!-- استدعاء ملفات الجافا سكربت المشتركة -->
    <?php $rel_prefix = isset($dir_prefix) ? $dir_prefix : (isset($prefix) ? $prefix : ''); ?>
    <script type="text/javascript" src="<?php echo $rel_prefix; ?>files/bower_components/jquery/js/jquery.min.js"></script>
    <script type="text/javascript" src="<?php echo $rel_prefix; ?>files/bower_components/popper.js/js/popper.min.js"></script>
    <script type="text/javascript" src="<?php echo $rel_prefix; ?>files/bower_components/bootstrap/js/bootstrap.min.js"></script>
    <script type="text/javascript" src="<?php echo $rel_prefix; ?>assets/js/select2.min.js?v=<?php echo time(); ?>"></script>
    <script type="text/javascript" src="<?php echo $rel_prefix; ?>assets/js/barcode-listener.js?v=<?php echo time(); ?>"></script>
    <script type="text/javascript" src="<?php echo $rel_prefix; ?>assets/js/aqnex-core.js?v=<?php echo time(); ?>"></script>




    <!-- محرك تفعيل Bootstrap Icons وتحويل الأزرار ديناميكياً مع تفعيل Tooltips -->
    <script type="text/javascript">
    document.addEventListener("DOMContentLoaded", function() {
        // 1. خريطة تحويل أيقونات FontAwesome القديمة إلى Bootstrap Icons محلياً
        const faToBi = {
            'home': 'house',
            'shopping-cart': 'cart3',
            'cart-plus': 'cart-plus',
            'truck': 'truck',
            'minus-circle': 'dash-circle',
            'plus-circle': 'plus-circle',
            'plus': 'plus',
            'archive': 'archive',
            'tags': 'tags',
            'cubes': 'box-seam',
            'users': 'people',
            'users-plus': 'person-plus',
            'user-plus': 'person-plus',
            'briefcase': 'briefcase',
            'exchange': 'arrow-left-right',
            'line-chart': 'bar-chart-line',
            'bar-chart-2': 'bar-chart-line',
            'bar-chart': 'bar-chart-line',
            'file-text-o': 'file-earmark-text',
            'file-text': 'file-earmark-text',
            'file-excel-o': 'file-earmark-excel',
            'file-excel': 'file-earmark-excel',
            'cog': 'gear',
            'sign-out': 'box-arrow-right',
            'money': 'cash-coin',
            'trash': 'trash',
            'edit': 'pencil-square',
            'eye': 'eye',
            'print': 'printer',
            'whatsapp': 'whatsapp',
            'calculator': 'calculator',
            'search': 'search',
            'bank': 'bank',
            'university': 'bank',
            'balance-scale': 'scales',
            'calendar': 'calendar',
            'calendar-o': 'calendar',
            'arrow-left': 'arrow-left',
            'arrow-right': 'arrow-right',
            'filter': 'filter',
            'bolt': 'lightning-charge',
            'info-circle': 'info-circle',
            'check': 'check-circle',
            'import': 'upload',
            'save': 'check-circle',
            'pencil': 'pencil-square',
            'excel': 'file-earmark-excel',
            'download': 'download',
            'upload': 'upload',
            'list': 'list',
            'times': 'x-circle',
            'close': 'x-circle',
            'lock': 'lock',
            'unlock': 'unlock'
        };

        // تحويل عناصر i التي تحتوي على كلاسات fa إلى bi
        document.querySelectorAll("i.fa, i.fa-brands, i.fa-regular, i.fa-solid").forEach(function(el) {
            let iconName = '';
            el.classList.forEach(function(cls) {
                if (cls.startsWith("fa-")) {
                    iconName = cls.substring(3);
                }
            });
            let biName = faToBi[iconName] || iconName;
            
            // تغيير الكلاس من fa إلى bi
            el.className = el.className.replace(/\bfa\b/g, 'bi').replace(/\bfa-[a-z0-9-]+\b/g, 'bi-' + biName);
        });

        // 2. تحويل جميع الأزرار والروابط التي تحتوي على أيقونة ونصوص إلى أيقونات فقط مع Tooltip (باستثناء أزرار الإضافة والحفظ والصفحة الرئيسية)
        document.querySelectorAll('.btn-flat, .btn-flat-primary, .btn-flat-secondary, .btn-flat-success, .btn-flat-danger, .btn, .btn-sm, .btn-danger, .btn-success, .btn-primary, .btn-secondary').forEach(function(el) {
            try {
                // استثناء الطباعة
                if (el.closest('.print-header') || el.closest('.d-none.d-print-block') || el.closest('.print-only')) return;

                // استثناء أزرار المودال (إضافة صندوق، تحويل، إلخ)
                if (el.getAttribute('data-toggle') === 'modal' || el.getAttribute('data-bs-toggle') === 'modal') return;

                // استثناء أزرار النماذج الرئيسية (submit داخل modal)
                if (el.closest('.modal')) return;

                // استثناء أزرار الصفحات المستقلة (تسجيل الدخول، استعادة الحساب)
                if (el.closest('.login-card') || el.closest('.recovery-card')) return;

                // البحث عن أيقونة (سواء bi أو i أو svg)
                var icon = el.querySelector('i, svg, .bi');
                
                var text = '';
                Array.from(el.childNodes).forEach(function(node) {
                    if (node !== icon && (!icon || !icon.contains(node))) {
                        if (node.nodeType === Node.TEXT_NODE) {
                            var t = node.textContent.trim();
                            if (t) text += (text ? ' ' : '') + t;
                        } else if (node.nodeType === Node.ELEMENT_NODE) {
                            var nt = node.textContent.trim();
                            if (nt) text += (text ? ' ' : '') + nt;
                        }
                    }
                });

                text = text.trim();

                // تحديد ما إذا كان الزر في الصفحة الرئيسية
                var isHomePage = window.location.pathname.indexOf('home.php') !== -1 || el.closest('.home-dashboard') || el.closest('.home-quick-links');
                
                // تحديد نوع الزر بناءً على النص
                var isAddBtn = /إضافة|اضافة|جديد/.test(text);
                var isSaveBtn = /حفظ|تثبيت|تسجيل/.test(text);
                var isReturnBtn = /رجوع|عودة|العودة/.test(text);

                // توحيد الأيقونات ديناميكياً
                if (isAddBtn || isSaveBtn || isReturnBtn) {
                    var targetIconClass = '';
                    if (isAddBtn) targetIconClass = 'bi bi-plus-circle';
                    else if (isSaveBtn) targetIconClass = 'bi bi-check-circle';
                    else if (isReturnBtn) targetIconClass = 'bi bi-arrow-right';

                    if (icon) {
                        // تحديث كلاس الأيقونة الحالية
                        icon.className = targetIconClass;
                    } else {
                        // إنشاء أيقونة جديدة
                        var newIcon = document.createElement('i');
                        newIcon.className = targetIconClass + ' ml-1';
                        el.prepend(newIcon);
                        icon = newIcon;
                    }
                }

                // إذا كان في الصفحة الرئيسية أو زر إضافة/حفظ، يظهر كزر كبير بارز بنص وأيقونة
                if (isHomePage || isAddBtn || isSaveBtn) {
                    el.classList.add('btn-prominent-action');
                    el.classList.remove('btn-icon-only');
                    
                    if (isAddBtn) {
                        el.classList.add('btn-prominent-add');
                    } else if (isSaveBtn) {
                        el.classList.add('btn-prominent-save');
                    }
                    
                    // تفعيل tooltip اختياري دون إزالة النص
                    if (text) {
                        el.setAttribute('title', text);
                        el.setAttribute('data-toggle', 'tooltip');
                        el.setAttribute('data-placement', 'top');
                    }
                    return; // إنهاء معالجة هذا الزر وإبقاء النص
                }

                // لبقية الأزرار: تحويلها لأيقونات دائرية صغيرة مع Tooltip
                if (text && icon) {
                    el.setAttribute('title', text);
                    el.setAttribute('aria-label', text);
                    el.setAttribute('data-toggle', 'tooltip');
                    el.setAttribute('data-placement', 'top');
                    
                    // إزالة النصوص
                    Array.from(el.childNodes).forEach(function(node) {
                        if (node !== icon) {
                            node.remove();
                        }
                    });
                    el.classList.add('btn-icon-only');
                }
            } catch (e) { console.error("Error formatting button:", e); }
        });

        // 3. تفعيل Bootstrap Tooltips إذا كانت المكتبة محملة
        try {
            if (typeof $ !== 'undefined' && $.fn.tooltip) {
                $('[data-toggle="tooltip"]').tooltip({
                    trigger: 'hover',
                    boundary: 'window'
                });
            }
        } catch (e) {
            console.warn("Bootstrap tooltips initialization failed or was blocked by browser policies:", e);
        }

        // 4. ضبط ديناميكي لعنوان التقرير في ترويسة الطباعة الموحدة
        try {
            var printDocTitle = document.getElementById('print-doc-title');
            if (printDocTitle && !printDocTitle.textContent.trim()) {
                var pageTitle = '';
                var titleTags = document.getElementsByTagName('title');
                
                // البحث في التايتل الثاني أولاً لأنه يكون خاصاً بالصفحة وليس بالمتجر العام
                if (titleTags.length > 1) {
                    pageTitle = titleTags[1].textContent.replace(/ - تكنولوجيا فون\s*$/, '').trim();
                } else if (titleTags.length > 0) {
                    pageTitle = titleTags[0].textContent.replace(/ - تكنولوجيا فون\s*$/, '').trim();
                }
                
                // استثناء اسم المتجر كعنوان للتقرير والاستعانة بالـ headings
                if (!pageTitle || pageTitle === 'تكنولوجيا فون' || pageTitle === 'تكنولوجي فون' || pageTitle === 'نظام إدارة المبيعات والمخازن') {
                    // البحث عن أول عنوان غير خاص بالترويسة الموحدة أو الأجزاء المخفية
                    var titleEl = Array.from(document.querySelectorAll('h3, h4, h5')).find(function(el) {
                        return !el.closest('.print-header') && !el.closest('.no-print') && el.textContent.trim().length > 0;
                    });
                    if (titleEl) {
                        pageTitle = titleEl.textContent.trim();
                    }
                }
                
                // إضافة التواريخ والمدى الجغرافي من الرابط
                var urlParams = new URLSearchParams(window.location.search);
                if (window.location.search.includes('start_date=')) {
                    var start = urlParams.get('start_date');
                    var end = urlParams.get('end_date');
                    if (start && end) {
                        pageTitle += ' (من ' + start + ' إلى ' + end + ')';
                    }
                } else if (window.location.search.includes('date=')) {
                    var selDate = urlParams.get('date');
                    if (selDate) {
                        pageTitle += ' بتاريخ: ' + selDate;
                    }
                }
                
                // تنظيف العنوان النهائي من أي نصوص أيقونات
                printDocTitle.textContent = pageTitle.replace(/^\s*[\u2000-\u206F\u2E00-\u2E7F\\'!"#$%&()*+,\-.\/:;<=>?@\[\]^_`{|}~]/, '').trim();
            }
        } catch (e) {
            console.error("Error setting dynamic print title:", e);
        }

        // 5. تأكيد تسجيل الخروج التفاعلي بتنسيق AqnexConfirm وتعميم التنبيهات
        try {
            var logoutLinks = document.querySelectorAll('a[href*="logout.php"], .logout-link');
            logoutLinks.forEach(function(a) {
                a.addEventListener('click', function(e) {
                    e.preventDefault();
                    var href = this.getAttribute('href');
                    if (typeof AqnexConfirm !== 'undefined') {
                        AqnexConfirm.show('هل أنت متأكد من رغبتك في تسجيل الخروج من النظام؟', function(confirmed) {
                            if (confirmed) {
                                window.location.href = href;
                            }
                        });
                    } else {
                        if (confirm('هل أنت متأكد من رغبتك في تسجيل الخروج من النظام؟')) {
                            window.location.href = href;
                        }
                    }
                });
            });
        } catch (e) {
            console.error("Error setting logout confirm handler:", e);
        }

        // 6. تعميم التنسيق الجديد الفوري التفاعلي بدلاً من alert العادية
        window.alert = function(msg) {
            if (typeof AqnexAlert !== 'undefined' && AqnexAlert.show) {
                AqnexAlert.show(500, msg);
            } else {
                console.log("Alert message:", msg);
            }
        };
    });

    // تحديث الساعة لحظياً كل ثانية - يستهدف عنصر الـ Topbar الجديد
    var liveClockEl = document.getElementById('live-clock');
    if (liveClockEl) {
        function updateLiveClock() {
            var now = new Date();
            var h = now.getHours();
            var m = now.getMinutes();
            var s = now.getSeconds();
            var ampm = h >= 12 ? 'PM' : 'AM';
            h = h % 12 || 12;
            liveClockEl.textContent =
                (h < 10 ? '0' : '') + h + ':' +
                (m < 10 ? '0' : '') + m + ':' +
                (s < 10 ? '0' : '') + s + ' ' + ampm;
        }
        updateLiveClock();
        setInterval(updateLiveClock, 1000);
    }

    try {
        if (typeof $ !== 'undefined' && $.fn.tooltip) {
            $('[data-bs-toggle="tooltip"]').tooltip({
                trigger: 'hover',
                boundary: 'window'
            });
        }
    } catch (e) {
        console.warn("Bootstrap tooltips initialization failed:", e);
    }
    // ========================================
    // تم إلغاء شاشات الانتظار لتسريع التنقل وجعله مباشراً وسلساً
    // ========================================
    </script>

<script>
// تعطيل شاشة التحميل كلياً للانتقال المباشر والسريع بين الصفحات
document.addEventListener("DOMContentLoaded", function() {
    var loader = document.getElementById('page-loader');
    if (loader) {
        loader.style.display = 'none';
        loader.classList.remove('active');
    }
});


function setupHeaderSelectAutocomplete(selectEl) {
    if (!selectEl) return;
    // حماية تامة لمنع التكرار نهائياً في أي شاشة
    if (selectEl.getAttribute('data-autocomplete-attached') === 'true') return;
    if (selectEl.closest('.header-autocomplete-wrapper')) return;
    if (selectEl.parentNode && selectEl.parentNode.classList.contains('header-autocomplete-wrapper')) return;
    if (selectEl.parentNode && selectEl.parentNode.querySelector('.header-autocomplete-wrapper')) return;

    selectEl.setAttribute('data-autocomplete-attached', 'true');
    selectEl.dataset.autocompleteAttached = "true";

    // تدمير وإزالة أي بقايا كائنات من مكتبة Select2 القديمة لمنع ظهور الحقول المزدوجة
    if (typeof $ !== 'undefined' && $.fn && $.fn.select2 && $(selectEl).data('select2')) {
        try { $(selectEl).select2('destroy'); } catch(e) {}
    }
    if (selectEl.parentNode) {
        selectEl.parentNode.querySelectorAll('.select2-container, .select2').forEach(el => el.remove());
    }

    const wrapper = document.createElement('div');
    wrapper.className = 'header-autocomplete-wrapper position-relative';
    wrapper.style.cssText = 'position: relative !important; width: 100% !important; flex: 1 1 auto !important; display: inline-block !important; vertical-align: middle !important; margin: 0 !important; padding: 0 !important;';

    const input = document.createElement('input');
    input.type = 'text';
    input.className = 'aqnex-input header-autocomplete-input font-weight-bold';
    input.autocomplete = 'off';
    input.placeholder = '-- اختر أو ابحث --';
    input.style.cssText = 'width: 100% !important; height: 28px !important; font-size: 0.82rem !important; text-align: right !important; cursor: pointer !important; background-color: #ffffff !important; border: 1px solid #94a3b8 !important; border-radius: 3px !important; padding: 0 6px !important;';

    const dropdown = document.createElement('div');
    dropdown.className = 'autocomplete-dropdown d-none shadow-sm';
    dropdown.style.cssText = 'position: absolute !important; top: 100% !important; right: 0 !important; left: 0 !important; width: 100% !important; max-height: 220px !important; overflow-y: auto !important; background: #ffffff !important; border: 1px solid #cbd5e1 !important; border-top: none !important; z-index: 999999 !important; box-shadow: 0 8px 16px rgba(0,0,0,0.2) !important; border-bottom-left-radius: 4px !important; border-bottom-right-radius: 4px !important;';

    const isRequired = selectEl.hasAttribute('required') || selectEl.required;
    if (isRequired) {
        selectEl.removeAttribute('required');
        selectEl.required = false;
        input.required = true;
    }

    selectEl.parentNode.insertBefore(wrapper, selectEl);
    wrapper.appendChild(input);
    wrapper.appendChild(dropdown);
    wrapper.appendChild(selectEl);
    selectEl.style.cssText = 'display: none !important; opacity: 0 !important; visibility: hidden !important; width: 0 !important; height: 0 !important; position: absolute !important; pointer-events: none !important;';

    function syncTextFromSelect() {
        if (selectEl.selectedIndex >= 0 && selectEl.options[selectEl.selectedIndex]) {
            input.value = selectEl.options[selectEl.selectedIndex].text.trim();
        } else {
            input.value = '';
        }
        if (isRequired) {
            if (selectEl.value && selectEl.value !== '') {
                input.setCustomValidity('');
            }
        }
    }

    syncTextFromSelect();
    selectEl.addEventListener('change', syncTextFromSelect);

    function renderOptions(filterText) {
        dropdown.innerHTML = '';
        const query = (filterText || '').trim().toLowerCase();
        let count = 0;

        Array.from(selectEl.options).forEach((opt) => {
            const text = opt.text.trim();
            const val = opt.value;
            if (!val && !text) return;

            if (!query || text.toLowerCase().includes(query) || val.toLowerCase().includes(query)) {
                const item = document.createElement('div');
                item.className = 'autocomplete-item text-right' + (selectEl.value === val ? ' active' : '');
                item.style.cssText = 'padding: 6px 10px; cursor: pointer; border-bottom: 1px solid #f1f5f9; font-size: 0.82rem; font-weight: 600; color: #1e293b; transition: background 0.15s ease;';
                item.textContent = text;

                item.addEventListener('mousedown', function(e) {
                    e.preventDefault();
                    if (selectEl.value !== val) {
                        opt.selected = true;
                        selectEl.value = val;
                        syncTextFromSelect();
                        dropdown.classList.add('d-none');
                        selectEl.dispatchEvent(new Event('change', { bubbles: true }));
                    } else {
                        syncTextFromSelect();
                        dropdown.classList.add('d-none');
                    }
                });

                dropdown.appendChild(item);
                count++;
            }
        });

        if (count === 0) {
            dropdown.innerHTML = '<div style="padding: 8px; color: #94a3b8; font-size: 0.78rem; text-align: center;">لا توجد نتائج</div>';
        }
        dropdown.classList.remove('d-none');
    }

    let isSelectingOption = false;

    input.addEventListener('focus', function() {
        renderOptions('');
    });

    input.addEventListener('click', function(e) {
        e.stopPropagation();
        renderOptions('');
    });

    input.addEventListener('input', function() {
        renderOptions(input.value);
    });

    document.addEventListener('click', function(e) {
        if (!wrapper.contains(e.target)) {
            dropdown.classList.add('d-none');
            syncTextFromSelect();
        }
    });

    input.addEventListener('keydown', function(e) {
        const items = dropdown.querySelectorAll('.autocomplete-item');
        if (items.length === 0) return;
        let currentIdx = Array.from(items).findIndex(it => it.classList.contains('active'));

        if (e.key === 'ArrowDown') {
            e.preventDefault();
            if (currentIdx >= 0) items[currentIdx].classList.remove('active');
            currentIdx = (currentIdx + 1) % items.length;
            items[currentIdx].classList.add('active');
            items[currentIdx].scrollIntoView({ block: 'nearest' });
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            if (currentIdx >= 0) items[currentIdx].classList.remove('active');
            currentIdx = (currentIdx - 1 + items.length) % items.length;
            items[currentIdx].classList.add('active');
            items[currentIdx].scrollIntoView({ block: 'nearest' });
        } else if (e.key === 'Enter') {
            e.preventDefault();
            if (currentIdx >= 0 && items[currentIdx]) {
                items[currentIdx].dispatchEvent(new Event('mousedown'));
            } else if (items[0]) {
                items[0].dispatchEvent(new Event('mousedown'));
            }
        } else if (e.key === 'Escape') {
            dropdown.classList.add('d-none');
            syncTextFromSelect();
        }
    });
}

function runHeaderAutocompleteEngine() {
    if (window.disableHeaderAutocomplete) return;
    const selectors = [
        'select.form-control',
        'select.form-select',
        'select.aqnex-select',
        'select.onyx-select',
        'select[name="refund_method"]',
        'select[name="refund_source"]',
        'select[name="reason"]',
        'select[name="type"]',
        'select[name="debt_status"]',
        'select[name="balance_status"]',
        'select[name="account_type"]',
        'select[name="sector_id"]',
        'select#boxSelect',
        'select[name="box_id"]',
        'select#select2',
        'select[name="customer_name"]',
        'select[name="select2"]',
        'select[name="select2[]"]',
        'select.select-customer',
        'select#supplierSelect2',
        'select[name="supplier_name"]',
        'select#invoiceTypeSelect',
        'select[name="invoice_type"]',
        'select#currencySelect',
        'select[name="currency_code"]',
        'select#salesPaymentMethodSelect',
        'select#paymentMethodSelect',
        'select#salesWalletTypeSelect',
        'select#walletTypeSelect',
        'select[name="expense_type"]',
        'select[name="payment_method"]',
        'select[name="payment_type"]'
    ];

    const elements = document.querySelectorAll(selectors.join(','));
    const uniqueElements = Array.from(new Set(elements));

    uniqueElements.forEach(function(sel) {
        if (!sel.closest('table') && !sel.closest('#itemsTable') && !sel.closest('#itemsContainer') && !sel.classList.contains('select-product') && !sel.classList.contains('unit-id') && !sel.classList.contains('serial-select')) {
            setupHeaderSelectAutocomplete(sel);
        }
    });

    // إزالة أية كائنات متكررة متبقية لـ Select2 في الصفحة بأكملها
    document.querySelectorAll('.select2-container').forEach(s2 => {
        if (s2.parentNode && s2.parentNode.querySelector('.header-autocomplete-wrapper')) {
            s2.remove();
        }
    });
}

document.addEventListener('DOMContentLoaded', function() {
    runHeaderAutocompleteEngine();
});

// اختصار الحفظ الموحد بالنظام زر (F10)
document.addEventListener('keydown', function(e) {
    if (e.key === 'F10') {
        e.preventDefault();
        var saveBtn = document.querySelector('.btn-save-action, button[name="btn_save"], button[name="btn_save_return"], button[name="btn_save_purchase"], input[type="submit"][name="btn_save"]');
        if (saveBtn) {
            saveBtn.click();
        } else {
            var activeForm = document.querySelector('form');
            if (activeForm) {
                if (typeof activeForm.requestSubmit === 'function') {
                    activeForm.requestSubmit();
                } else {
                    activeForm.submit();
                }
            }
        }
    }
});

// دالة إظهار التنبيهات الفورية المنبثقة (Enterprise Popup Alert)
window.showSystemAlert = function(title, message, type, targetElementToFocus) {
    type = type || 'warning';
    var iconClass = 'bi-exclamation-triangle-fill text-warning';
    var headerBg = 'linear-gradient(135deg, #7c2d12 0%, #b45309 100%)';
    if (type === 'danger' || type === 'error') {
        iconClass = 'bi-x-circle-fill text-danger';
        headerBg = 'linear-gradient(135deg, #881337 0%, #be123c 100%)';
    } else if (type === 'success') {
        iconClass = 'bi-check-circle-fill text-success';
        headerBg = 'linear-gradient(135deg, #064e3b 0%, #047857 100%)';
    } else if (type === 'info') {
        iconClass = 'bi-info-circle-fill text-info';
        headerBg = 'linear-gradient(135deg, #0c4a6e 0%, #0284c7 100%)';
    }

    var alertModalHtml = `
    <div class="modal fade" id="systemAlertModal" tabindex="-1" role="dialog" aria-hidden="true" style="z-index: 999999; display: flex !important; align-items: center !important; justify-content: center !important;">
        <div class="modal-dialog modal-dialog-centered" role="document" style="max-width: 460px; width: 92%; margin: auto !important; top: 0 !important;">
            <div class="modal-content" style="border-radius: 6px !important; overflow: hidden; border: none; box-shadow: 0 20px 40px rgba(0,0,0,0.3);">
                <div class="modal-header" style="background: ${headerBg} !important; color: #fff; padding: 12px 18px;">
                    <h5 class="modal-title" style="font-size: 1rem; font-weight: 700; color: #fff; margin: 0; display: flex; align-items: center; gap: 8px;">
                        <i class="bi ${iconClass}" style="font-size: 1.2rem; color: #fff !important;"></i>
                        <span>${title}</span>
                    </h5>
                    <button type="button" class="close text-white" data-dismiss="modal" data-bs-dismiss="modal" aria-label="إغلاق" style="color: #fff; opacity: 0.9; background: none; border: none; font-size: 1.4rem;">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body text-right" style="padding: 20px; font-size: 0.92rem; color: #1e293b; line-height: 1.6; background: #fff !important;">
                    ${message}
                </div>
                <div class="modal-footer" style="padding: 10px 18px; background: #f8fafc; border-top: 1px solid #e2e8f0;">
                    <button type="button" id="btnDismissSystemAlert" class="btn btn-dark btn-sm px-4" data-dismiss="modal" data-bs-dismiss="modal" style="font-weight: 700; border-radius: 4px !important;">موافق (Enter)</button>
                </div>
            </div>
        </div>
    </div>`;

    var existingModal = document.getElementById('systemAlertModal');
    if (existingModal) existingModal.remove();

    document.body.insertAdjacentHTML('beforeend', alertModalHtml);

    const modalEl = document.getElementById('systemAlertModal');
    const dismissBtn = document.getElementById('btnDismissSystemAlert');

    function focusTarget() {
        if (!targetElementToFocus) return;
        let el = targetElementToFocus;
        if (typeof el === 'string') {
            el = document.querySelector(el) || document.getElementById(el);
        }
        if (el) {
            if (el.parentNode && el.parentNode.querySelector('input.header-autocomplete-input')) {
                const wrapperInput = el.parentNode.querySelector('input.header-autocomplete-input');
                wrapperInput.focus();
                wrapperInput.click();
            } else {
                el.focus();
                if (typeof el.select === 'function') el.select();
            }
        }
    }

    if (typeof $ !== 'undefined' && $.fn && $.fn.modal) {
        const $m = $('#systemAlertModal');
        $m.modal('show');
        $m.on('shown.bs.modal', function() {
            if (dismissBtn) dismissBtn.focus();
        });
        $m.on('hidden.bs.modal', function() {
            $m.remove();
            focusTarget();
        });
    } else {
        modalEl.classList.add('show');
        modalEl.style.display = 'block';
        if (dismissBtn) dismissBtn.focus();
    }

    function handleAlertKeydown(e) {
        if (e.key === 'Enter' || e.key === ' ' || e.key === 'Escape') {
            e.preventDefault();
            e.stopPropagation();
            document.removeEventListener('keydown', handleAlertKeydown, true);
            if (typeof $ !== 'undefined' && $.fn && $.fn.modal) {
                $('#systemAlertModal').modal('hide');
            } else {
                modalEl.remove();
                focusTarget();
            }
        }
    }
    document.addEventListener('keydown', handleAlertKeydown, true);

    if (dismissBtn) {
        dismissBtn.addEventListener('click', function() {
            document.removeEventListener('keydown', handleAlertKeydown, true);
            setTimeout(focusTarget, 150);
        });
    }
};

// التنقل التلقائي بين حقول الإدخال عند ضغط مفتاح Enter بدون الحاجة للماوس
document.addEventListener('keydown', function(e) {
    if (e.key === 'Enter') {
        var target = e.target;
        var isInputField = target.tagName === 'INPUT' || target.tagName === 'SELECT';
        var isTextArea = target.tagName === 'TEXTAREA';
        var isSubmitBtn = target.type === 'submit' || target.classList.contains('btn-save-action');

        if (target.closest('#itemsContainer') || target.closest('.item-row')) {
            return;
        }

        if (isInputField && !isSubmitBtn && !isTextArea && !target.closest('.aqnex-select-container')) {
            var form = target.form;
            if (form) {
                var focusables = Array.from(form.querySelectorAll('input:not([type="hidden"]):not([disabled]):not([readonly]), select:not([disabled]), button:not([disabled])'));
                var index = focusables.indexOf(target);
                if (index > -1 && index < focusables.length - 1) {
                    e.preventDefault();
                    focusables[index + 1].focus();
                    if (focusables[index + 1].select) {
                        focusables[index + 1].select();
                    }
                }
            }
        }
    }
});
</script>
    
    <!-- تذييل الطباعة الموحد (يظهر فقط عند طباعة التقارير والفواتير) -->
    <div class="print-footer" style="width: 100%; border-top: 1.5px solid #1e293b; padding-top: 8px; margin-top: 20px; font-family: 'Tajawal', sans-serif; font-size: 10px; direction: rtl;">
        <table style="width: 100%; border-collapse: collapse; border: none; margin: 0; padding: 0;">
            <tr style="border: none;">
                <!-- اليمين: تاريخ الطباعة -->
                <td style="width: 35%; text-align: right; border: none; padding: 0; color: #475569;">
                    <strong>تاريخ الطباعة:</strong> <?php echo date('Y-m-d H:i'); ?>
                </td>
                <!-- الوسط: رقم الصفحة -->
                <td style="width: 30%; text-align: center; border: none; padding: 0; color: #475569;" class="print-page-num-center">
                    صفحة <span class="page-count"></span>
                </td>
                <!-- اليسار: Printed by -->
                <td style="width: 35%; text-align: left; direction: ltr; border: none; padding: 0; color: #475569;">
                    <strong>Printed by:</strong> <?php echo isset($_SESSION['SESS_FIRST_NAME']) ? htmlspecialchars($_SESSION['SESS_FIRST_NAME']) : 'System Admin'; ?>
                </td>
            </tr>
        </table>
    </div>
    
    <style>
    @media print {
        .print-footer {
            display: block !important;
            position: fixed;
            bottom: 0;
            left: 0;
            width: 100%;
            background: #fff;
        }
        .print-page-num-center::after {
            content: " " counter(page);
        }
        body {
            margin-bottom: 50px !important;
        }
    }
    </style>
    
    <?php if (isset($_SESSION['SESS_MEMBER_ID'])): 
        $pfx = isset($dir_prefix) ? $dir_prefix : (isset($prefix) ? $prefix : '');
        
        // جلب مقترحات ذكية سياقية بناء على الصفحة الحالية
        $page_suggestions = [];
        $current_filename = basename($_SERVER['PHP_SELF']);
        $current_dir = basename(dirname($_SERVER['PHP_SELF']));
        $active_module = isset($module) ? $module : '';

        if ($current_dir === 'sales' || $active_module === 'sales') {
            $page_suggestions = [
                "فاتورة للعميل أحمد تحتوي على 3 شواحن",
                "كم مبيعات اليوم؟",
                "ابحث عن فواتير العميل محمد"
            ];
        } elseif ($current_dir === 'purchases' || $active_module === 'purchases') {
            $page_suggestions = [
                "تسجيل فاتورة شراء جديدة",
                "أكثر الموردين تعاملاً",
                "سجل مشتريات الشهر الحالي"
            ];
        } elseif ($current_dir === 'products' || $active_module === 'products' || $active_module === 'inventory') {
            $page_suggestions = [
                "عرض السلع منخفضة المخزون",
                "كم كمية آيفون 13 المتوفرة؟",
                "أعلى الأصناف مبيعاً"
            ];
        } elseif ($current_dir === 'box' || $active_module === 'box') {
            $page_suggestions = [
                "ترحيل مبيعات اليوم إلى الصندوق",
                "عرض الرصيد الحالي للصناديق",
                "كشف حركة الصندوق اليوم"
            ];
        } elseif ($current_dir === 'reports' || $active_module === 'reports') {
            $page_suggestions = [
                "تقرير الأرباح لهذا الشهر",
                "تحليل المبيعات والمصاريف لليوم",
                "ملخص المخزون والسيولة"
            ];
        } else {
            $page_suggestions = [
                "خلاصة أرباح اليوم",
                "فحص مخزون صنف",
                "الاستعلام عن أرصدة الصناديق والسيولة"
            ];
        }
    ?>
    <!-- زر تشغيل المساعد الذكي العائم -->
    <button id="ai-assistant-toggle" class="no-print" title="المساعد الذكي (AQNEX AI)" style="position: fixed; bottom: 25px; left: 25px; width: 56px; height: 56px; border-radius: 50%; background: linear-gradient(135deg, #0369a1 0%, #0284c7 100%); color: #fff; border: none; box-shadow: 0 8px 30px rgba(2, 132, 199, 0.4); cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 1.6rem; z-index: 10001; transition: transform 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275); outline: none;">
        <i class="bi bi-robot" id="toggle-icon"></i>
    </button>

    <!-- لوحة المساعد الذكي الجانبية (Slide-in Sidebar Drawer) -->
    <div id="ai-assistant-panel" class="no-print" style="position: fixed; top: 0; left: -380px; width: 380px; height: 100vh; background: rgba(15, 23, 42, 0.98); border-right: 1px solid rgba(255, 255, 255, 0.1); box-shadow: 10px 0 40px rgba(0, 0, 0, 0.5); z-index: 10000; display: flex; flex-direction: column; transition: left 0.3s ease; font-family: 'Tajawal', sans-serif;">
        <!-- ترويسة المساعد -->
        <div style="padding: 14px 18px; border-bottom: 1px solid rgba(255, 255, 255, 0.1); display: flex; align-items: center; justify-content: space-between; background: rgba(30, 41, 59, 0.5);">
            <div style="display: flex; align-items: center; gap: 10px;">
                <div style="position: relative;">
                    <div style="width: 34px; height: 34px; border-radius: 50%; background: #0369a1; display: flex; align-items: center; justify-content: center; color: #fff;">
                        <i class="bi bi-robot"></i>
                    </div>
                    <span style="position: absolute; bottom: 0; right: 0; width: 10px; height: 10px; border-radius: 50%; background: #22c55e; border: 2px solid #0f172a; animation: pulse-green-footer 2s infinite;"></span>
                </div>
                <div>
                    <h6 style="color: #fff; margin: 0; font-size: 0.9rem; font-weight: 700;">مساعد AQNEX الذكي</h6>
                    <span style="font-size: 0.7rem; color: #94a3b8;">متصل ونشط حالياً</span>
                </div>
            </div>
            <button id="ai-assistant-close" style="background: none; border: none; color: #94a3b8; font-size: 1.2rem; cursor: pointer; padding: 0; line-height: 1;"><i class="bi bi-x-lg"></i></button>
        </div>
        
        <!-- منطقة الرسائل -->
        <div id="ai-chat-messages" style="flex-grow: 1; overflow-y: auto; padding: 18px; display: flex; flex-direction: column; gap: 14px; background: rgba(15, 23, 42, 0.95);">
            <!-- رسالة الترحيب -->
            <div class="ai-msg bot" style="max-width: 85%; align-self: flex-start; display: flex; flex-direction: column; gap: 4px;">
                <div style="background: rgba(30, 41, 59, 0.8); border: 1px solid rgba(255, 255, 255, 0.05); color: #e2e8f0; padding: 10px 14px; font-size: 0.82rem; line-height: 1.5; border-radius: 12px 12px 12px 0;">
                    أهلاً بك! أنا مساعدك الذكي في AQNEX. يمكنك سؤالي عن:
                    <br>• حالة مخزون أي صنف (مثال: "كم متوفر من آيفون؟")
                    <br>• التقارير المالية لليوم أو الشهر (مثال: "خلاصة أرباح اليوم")
                    <br>• طلب تجهيز فاتورة مبيعات جديدة لعميل (مثال: "فاتورة للعميل أحمد تحتوي على 3 شواحن")
                </div>
            </div>
        </div>

        <!-- اقتراحات ذكية سياقية -->
        <div class="ai-chat-suggestions" style="padding: 10px 14px; display: flex; flex-wrap: wrap; gap: 6px; border-top: 1px solid rgba(255, 255, 255, 0.05); background: rgba(30, 41, 59, 0.3); justify-content: flex-start;">
            <?php foreach ($page_suggestions as $suggestion): ?>
                <button type="button" class="ai-suggestion-chip" style="background: rgba(2, 132, 199, 0.15); border: 1px solid rgba(2, 132, 199, 0.3); border-radius: 16px !important; color: #38bdf8; padding: 5px 12px; font-size: 0.72rem; cursor: pointer; transition: all 0.2s; outline: none;" data-text="<?php echo htmlspecialchars($suggestion); ?>">
                    <?php echo htmlspecialchars($suggestion); ?>
                </button>
            <?php endforeach; ?>
        </div>
        
        <!-- حقل الإدخال وإرسال الرسالة -->
        <div style="padding: 12px; border-top: 1px solid rgba(255, 255, 255, 0.1); background: rgba(30, 41, 59, 0.5); display: flex; gap: 8px;">
            <input type="text" id="ai-chat-input" placeholder="اكتب سؤالك هنا..." style="flex-grow: 1; background: rgba(15, 23, 42, 0.8); border: 1px solid rgba(255, 255, 255, 0.15); color: #fff; padding: 10px 14px; font-size: 0.82rem; outline: none; transition: border-color 0.2s;" />
            <button id="ai-chat-send" style="background: #0284c7; color: #fff; border: none; width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: background 0.2s; outline: none;">
                <i class="bi bi-send-fill" style="transform: scaleX(-1);"></i>
            </button>
        </div>
    </div>

    <style>
    #ai-assistant-toggle,
    #ai-assistant-toggle * {
        border-radius: 50% !important;
    }
    .ai-suggestion-chip {
        border-radius: 16px !important;
    }
    .ai-msg div {
        border-radius: 12px !important;
    }
    #ai-assistant-panel div[style*="border-radius: 50%"],
    #ai-assistant-panel span[style*="border-radius: 50%"] {
        border-radius: 50% !important;
    }
    @keyframes pulse-green-footer {
        0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(34, 197, 94, 0.7); }
        70% { transform: scale(1); box-shadow: 0 0 0 6px rgba(34, 197, 94, 0); }
        100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(34, 197, 94, 0); }
    }
    #ai-chat-input:focus {
        border-color: #0284c7 !important;
    }
    .ai-msg.user {
        align-self: flex-end !important;
    }
    .ai-msg.user div {
        background: #0284c7 !important;
        color: #fff !important;
        border-radius: 12px 12px 0 12px !important;
    }
    .ai-msg.bot a {
        color: #38bdf8 !important;
        text-decoration: underline !important;
        font-weight: 700;
    }
    
    /* تنسيقات توسيع وانكماش الصفحة عند فتح المساعد الذكي */
    body.ai-assistant-open #content {
        margin-left: 380px !important;
        width: calc(100% - 380px) !important;
        transition: margin 0.3s ease, width 0.3s ease !important;
    }
    body.ai-assistant-open #sidebar + #content {
        margin-left: 380px !important;
        width: calc(100% - 260px - 380px) !important;
        transition: margin 0.3s ease, width 0.3s ease !important;
    }
    body.ai-assistant-open #ai-assistant-panel {
        left: 0 !important;
    }
    body.ai-assistant-open #ai-assistant-toggle {
        left: 405px !important;
        transform: rotate(180deg) scale(0.9) !important;
    }
    
    @media (max-width: 768px) {
        body.ai-assistant-open #content {
            margin-left: 0 !important;
            width: 100% !important;
        }
        body.ai-assistant-open #ai-assistant-toggle {
            left: 25px !important;
        }
        #ai-assistant-panel {
            width: 100% !important;
            left: -100% !important;
        }
    }
    </style>

    <script>
    document.addEventListener("DOMContentLoaded", function() {
        const toggleBtn = document.getElementById("ai-assistant-toggle");
        const panel = document.getElementById("ai-assistant-panel");
        const closeBtn = document.getElementById("ai-assistant-close");
        const chatInput = document.getElementById("ai-chat-input");
        const sendBtn = document.getElementById("ai-chat-send");
        const messagesContainer = document.getElementById("ai-chat-messages");
        
        if (!toggleBtn || !panel) return;
        
        let isOpen = false;
        
        toggleBtn.addEventListener("click", function() {
            isOpen = !isOpen;
            if (isOpen) {
                document.body.classList.add("ai-assistant-open");
                chatInput.focus();
            } else {
                closePanel();
            }
        });
        
        closeBtn.addEventListener("click", closePanel);
        
        function closePanel() {
            isOpen = false;
            document.body.classList.remove("ai-assistant-open");
        }
        
        // معالجة رقاقات الاقتراحات
        document.querySelectorAll(".ai-suggestion-chip").forEach(chip => {
            chip.addEventListener("click", function() {
                chatInput.value = this.getAttribute("data-text");
                sendMessage();
            });
        });
        
        function appendMessage(sender, text) {
            const msgDiv = document.createElement("div");
            msgDiv.className = `ai-msg ${sender}`;
            msgDiv.style.maxWidth = "85%";
            msgDiv.style.alignSelf = sender === "user" ? "flex-end" : "flex-start";
            msgDiv.style.display = "flex";
            msgDiv.style.flexDirection = "column";
            msgDiv.style.gap = "4px";
            
            let formattedText = text
                .replace(/\n/g, "<br>")
                .replace(/\[([^\]]+)\]\(([^)]+)\)/g, '<a href="$2" target="_blank">$1</a>')
                .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
                .replace(/\*([^*]+)\*/g, '<em>$1</em>');
                
            msgDiv.innerHTML = `
                <div style="background: ${sender === 'user' ? '#0284c7' : 'rgba(30, 41, 59, 0.8)'}; 
                            border: 1px solid rgba(255, 255, 255, 0.05); 
                            color: #e2e8f0; 
                            padding: 10px 14px; 
                            font-size: 0.82rem; 
                            line-height: 1.5; 
                            border-radius: ${sender === 'user' ? '12px 12px 0 12px' : '12px 12px 12px 0'};">
                    ${formattedText}
                </div>
            `;
            messagesContainer.appendChild(msgDiv);
            messagesContainer.scrollTop = messagesContainer.scrollHeight;
        }
        
        function sendMessage() {
            const text = chatInput.value.trim();
            if (text === "") return;
            
            appendMessage("user", text);
            chatInput.value = "";
            
            const loadingDiv = document.createElement("div");
            loadingDiv.className = "ai-msg bot loading-dots";
            loadingDiv.style.alignSelf = "flex-start";
            loadingDiv.style.color = "#94a3b8";
            loadingDiv.style.fontSize = "0.8rem";
            loadingDiv.style.padding = "4px 14px";
            loadingDiv.innerHTML = "جاري التفكير والتنفيذ...";
            messagesContainer.appendChild(loadingDiv);
            messagesContainer.scrollTop = messagesContainer.scrollHeight;
            
            fetch("<?php echo $prefix; ?>api/ai_assistant.php", {
                method: "POST",
                headers: {
                    "Content-Type": "application/json"
                },
                body: JSON.stringify({ message: text })
            })
            .then(res => res.json())
            .then(data => {
                loadingDiv.remove();
                if (data.status === "success") {
                    appendMessage("bot", data.message);
                } else {
                    appendMessage("bot", `خطأ: ${data.message}`);
                }
            })
            .catch(err => {
                loadingDiv.remove();
                appendMessage("bot", "حدث عطل أثناء الاتصال بالمساعد الذكي.");
            });
        }
        
        sendBtn.addEventListener("click", sendMessage);
        chatInput.addEventListener("keypress", function(e) {
            if (e.key === "Enter") {
                sendMessage();
            }
        });
    });
    </script>
    <?php endif; ?>
    
    <!-- تحديث عنوان الصفحة والترويسات تلقائياً باسم الشركة المدخل في الإعدادات -->
    <script>
    document.addEventListener("DOMContentLoaded", function() {
        var storeName = <?php echo json_encode(!empty($global_settings['store_name']) ? $global_settings['store_name'] : 'AQNEX POS'); ?>;
        var titleTags = document.getElementsByTagName('title');
        for (var i = 0; i < titleTags.length; i++) {
            titleTags[i].textContent = titleTags[i].textContent.replace(/تكنولوجيا فون/g, storeName);
        }
    });
    </script>
    <script>
    // Sidebar live search filtering
    document.addEventListener('DOMContentLoaded', function() {
        var input = document.getElementById('sidebar-search');
        if (!input) return;

        var debounceTimer = null;

        function normalizeText(s) {
            return (s || '').replace(/\s+/g, ' ').trim().toLowerCase();
        }

        function filterSidebar() {
            var q = normalizeText(input.value);
            var topItems = document.querySelectorAll('#sidebar .components > li');

            topItems.forEach(function(li) {
                var toggle = li.querySelector('a.dropdown-toggle');
                if (toggle) {
                    var menuText = normalizeText(toggle.textContent);
                    var submenu = li.querySelector('ul');
                    var childMatch = false;
                    if (submenu) {
                        var links = submenu.querySelectorAll('a');
                        links.forEach(function(a){
                            if (normalizeText(a.textContent).indexOf(q) !== -1) childMatch = true;
                        });
                    }
                    var match = (q === '') || menuText.indexOf(q) !== -1 || childMatch;
                    li.style.display = match ? '' : 'none';

                    // expand submenu when there is a child match and a query
                    if (submenu) {
                        if (childMatch && q !== '') {
                            submenu.classList.add('show');
                            toggle.setAttribute('aria-expanded','true');
                        } else if (q === '') {
                            // reset to default collapse state (do nothing)
                        } else {
                            submenu.classList.remove('show');
                            toggle.setAttribute('aria-expanded','false');
                        }
                    }
                } else {
                    var a = li.querySelector('a');
                    var text = normalizeText(a ? a.textContent : '');
                    var match = (q === '') || text.indexOf(q) !== -1;
                    li.style.display = match ? '' : 'none';
                }
            });
        }

        input.addEventListener('input', function() {
            if (debounceTimer) clearTimeout(debounceTimer);
            debounceTimer = setTimeout(filterSidebar, 120);
        });
    });

    // 2. التنقل بين الحقول عبر زر Enter ومنع الحفظ التلقائي بالفورم
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            var activeEl = document.activeElement;
            if (!activeEl) return;

            // استثناء الـ Textarea وأزرار الحفظ والإرسال الفعلية
            var tagName = activeEl.tagName.toLowerCase();
            var typeAttr = (activeEl.getAttribute('type') || '').toLowerCase();
            
            if (tagName === 'textarea' || typeAttr === 'submit' || activeEl.classList.contains('btn-submit') || activeEl.classList.contains('save-btn')) {
                return; // دع زر الانتر يقوم بعمله الطبيعي هنا
            }

            e.preventDefault(); // منع إرسال الفورم

            // البحث عن الفورم الحالي للعنصر النشط
            var form = activeEl.form || activeEl.closest('form');
            if (!form) return;

            // جلب كافة العناصر القابلة للتفاعل ومرئية في الفورم
            var focusableElements = Array.prototype.slice.call(
                form.querySelectorAll('input, select, textarea, button, [tabindex="0"]')
            ).filter(function(el) {
                var style = window.getComputedStyle(el);
                return el.tabIndex >= 0 && 
                       !el.disabled && 
                       style.display !== 'none' && 
                       style.visibility !== 'hidden' &&
                       el.offsetHeight > 0 &&
                       !el.classList.contains('no-focus-enter');
            });

            var index = focusableElements.indexOf(activeEl);
            if (index > -1 && index < focusableElements.length - 1) {
                var nextEl = focusableElements[index + 1];
                nextEl.focus();
                if (nextEl.tagName.toLowerCase() === 'input' && typeof nextEl.select === 'function') {
                    nextEl.select(); // تحديد النص لسهولة الكتابة فوقه
                }
            }
        }
    });

    // 3. تحويل كافة حقول الـ select تلقائياً إلى autocomplete ذكي
    document.addEventListener('DOMContentLoaded', function() {
        initAqnexAutocomplete();
    });

    // تفعيل التحديث التلقائي للحقول الجديدة بعد تحميلها بـ AJAX
    var ajaxObserver = new MutationObserver(function(mutations) {
        var needsInit = false;
        mutations.forEach(function(mutation) {
            if (mutation.addedNodes.length) {
                mutation.addedNodes.forEach(function(node) {
                    if (node.nodeType === 1 && (node.tagName.toLowerCase() === 'select' || node.querySelector('select'))) {
                        needsInit = true;
                    }
                });
            }
        });
        if (needsInit) {
            initAqnexAutocomplete();
        }
    });
    ajaxObserver.observe(document.body, { childList: true, subtree: true });

    function initAqnexAutocomplete() {
        var selects = document.querySelectorAll('select:not(.no-autocomplete):not([multiple]):not(.select-category)');
        selects.forEach(function(select) {
            if (select.dataset.aqnexInit === 'true' || select.style.display === 'none' || select.classList.contains('select2-hidden-accessible') || select.classList.contains('select-category')) {
                return; // تم تهيئته مسبقاً أو حقل مخفي مخصص
            }
            select.dataset.aqnexInit = 'true';
            
            // إنشاء الحاوية والعنصر البديل
            var container = document.createElement('div');
            container.className = 'aqnex-select-container';
            if (select.className) {
                select.className.split(/\s+/).forEach(function(cls) {
                    if (cls && cls !== 'select2-hidden-accessible') {
                        container.classList.add('original-' + cls);
                    }
                });
            }
            
            var input = document.createElement('input');
            input.type = 'text';
            input.className = 'aqnex-select-input';
            if (select.classList.contains('form-control')) {
                input.classList.add('form-control');
            }
            // نسخ الكلاسات المخصصة والتنسيق للانسجام مع واجهة الصفحة
            input.style.borderRadius = '0px'; // الحفاظ على الحواف الكلاسيكية للنظام
            input.placeholder = select.options[0] ? select.options[0].text : 'اختر...';
            
            // قائمة الخيارات العائمة
            var dropdown = document.createElement('div');
            dropdown.className = 'aqnex-select-dropdown';
            
            // إخفاء الـ select الأصلي ووضع الحاوية بدلاً منه
            select.style.display = 'none';
            select.parentNode.insertBefore(container, select);
            container.appendChild(input);
            container.appendChild(dropdown);
            
            // تحديث قيمة حقل الإدخال بالخيار النشط حالياً
            function updateInputFromSelect() {
                var selectedOpt = select.options[select.selectedIndex];
                input.value = selectedOpt ? selectedOpt.text : '';
            }
            updateInputFromSelect();
            
            // بناء وتعبئة الخيارات في القائمة المنسدلة المخصصة
            function buildDropdownItems() {
                dropdown.innerHTML = '';
                var searchTerm = input.value.toLowerCase().trim();
                var hasVisible = false;
                
                for (var i = 0; i < select.options.length; i++) {
                    var option = select.options[i];
                    // تجاوز الخيارات الفارغة أو الغير نشطة
                    var text = option.text;
                    var val = option.value;
                    
                    if (text.toLowerCase().indexOf(searchTerm) !== -1 || searchTerm === '') {
                        hasVisible = true;
                        var item = document.createElement('div');
                        item.className = 'aqnex-select-item';
                        item.textContent = text;
                        item.dataset.value = val;
                        item.dataset.index = i;
                        
                        if (i === select.selectedIndex) {
                            item.classList.add('selected-val');
                        }
                        
                        item.addEventListener('click', function(e) {
                            select.selectedIndex = parseInt(this.dataset.index);
                            updateInputFromSelect();
                            dropdown.style.display = 'none';
                            // إطلاق حدث التغيير لتعمل العمليات التفاعلية (كاحتساب الإجماليات وتحديث أسعار الصرف)
                            var changeEvent = new Event('change', { bubbles: true });
                            select.dispatchEvent(changeEvent);
                        });
                        dropdown.appendChild(item);
                    }
                }
                
                if (!hasVisible) {
                    var noRes = document.createElement('div');
                    noRes.className = 'aqnex-select-item no-results';
                    noRes.textContent = 'لا توجد نتائج مطابقة';
                    dropdown.appendChild(noRes);
                }
            }
            
            // إدارة الأحداث والظهور
            input.addEventListener('focus', function() {
                // إظهار وتحديث القائمة
                buildDropdownItems();
                dropdown.style.display = 'block';
                input.select(); // تحديد النص لسهولة البحث مباشرة
            });
            
            input.addEventListener('input', function() {
                dropdown.style.display = 'block';
                buildDropdownItems();
            });
            
            // إغلاق القائمة المنسدلة عند النقر في الخارج
            document.addEventListener('click', function(e) {
                if (!container.contains(e.target)) {
                    dropdown.style.display = 'none';
                    updateInputFromSelect(); // إعادة النص للخيار المختار فعلياً في حال إلغاء البحث
                }
            });
            
            // دعم أزرار الكيبورد (أعلى/أسفل للتنقل، Enter للتأكيد)
            input.addEventListener('keydown', function(e) {
                var items = dropdown.querySelectorAll('.aqnex-select-item:not(.no-results)');
                if (dropdown.style.display === 'none' || !items.length) {
                    return;
                }
                
                var activeItem = dropdown.querySelector('.aqnex-select-item.active');
                var activeIdx = -1;
                if (activeItem) {
                    activeIdx = Array.prototype.indexOf.call(items, activeItem);
                }
                
                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    if (activeItem) activeItem.classList.remove('active');
                    var nextIdx = (activeIdx + 1) % items.length;
                    items[nextIdx].classList.add('active');
                    items[nextIdx].scrollIntoView({ block: 'nearest' });
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    if (activeItem) activeItem.classList.remove('active');
                    var prevIdx = (activeIdx - 1 + items.length) % items.length;
                    items[prevIdx].classList.add('active');
                    items[prevIdx].scrollIntoView({ block: 'nearest' });
                } else if (e.key === 'Enter') {
                    if (activeItem) {
                        e.preventDefault();
                        activeItem.click();
                    }
                }
            });
        });
    }
    </script>
    <!-- مودال البحث السريع الموحد للأصناف (F4) -->
    <div class="modal fade" id="quickProductSearchModal" tabindex="-1" role="dialog" aria-hidden="true" style="z-index: 99999;">
        <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
            <div class="modal-content text-right border-0 shadow">
                <div class="modal-header bg-primary text-white py-2">
                    <h5 class="modal-title font-weight-bold" style="font-size: 1.05rem;"><i class="bi bi-search ml-2"></i>البحث عن صنف (F4)</h5>
                    <button type="button" class="close text-white" data-dismiss="modal" data-bs-dismiss="modal" aria-label="إغلاق">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body p-3">
                    <div class="input-group mb-3">
                        <div class="input-group-prepend">
                            <span class="input-group-text bg-white"><i class="bi bi-search text-muted"></i></span>
                        </div>
                        <input type="text" id="quickProductSearchInput" class="form-control form-control-lg font-weight-bold" placeholder="ابحث باسم المنتج، الباركود..." autocomplete="off">
                    </div>
                    <div class="table-responsive" style="max-height: 360px; overflow-y: auto;">
                        <table class="table table-hover table-bordered table-sm text-center mb-0">
                            <thead class="thead-light">
                                <tr>
                                    <th>الباركود</th>
                                    <th>اسم المنتج</th>
                                    <th>سعر البيع</th>
                                    <th>سعر الشراء</th>
                                    <th>المخزون</th>
                                    <th>إجراء</th>
                                </tr>
                            </thead>
                            <tbody id="quickProductSearchResults">
                                <tr><td colspan="6" class="text-muted py-3">اكتب كلمة البحث للبدء...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer bg-light py-2 justify-content-between">
                    <small class="text-muted"><i class="bi bi-keyboard ml-1"></i> اضغط ⬇ ⬆ للتنقل و Enter للاختيار السريع</small>
                    <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal" data-bs-dismiss="modal">إغلاق</button>
                </div>
    <!-- 📄 مودال عرض وطباعة الوثائق المنفصلة (Universal Document Viewer Modal) -->
    <div class="modal fade" id="universalDocViewModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title font-weight-bold" id="universalDocTitle">
                        <i class="bi bi-file-earmark-text-fill ml-1"></i> معاينة الوثيقة المالية
                    </h6>
                    <button type="button" class="close text-white" data-dismiss="modal" data-bs-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body p-3" id="universalDocBody">
                    <div class="text-center p-4 text-muted">جاري تحميل بيانات الوثيقة...</div>
                </div>
                <div class="modal-footer bg-light justify-content-between">
                    <small class="text-muted"><i class="bi bi-info-circle ml-1"></i> يمكن طباعة الوثيقة بشكل مستقل دون التأثير على التقرير</small>
                    <div>
                        <button type="button" class="btn btn-primary btn-sm px-3" onclick="printUniversalDoc();">
                            <i class="bi bi-printer-fill ml-1"></i> طباعة الوثيقة
                        </button>
                        <button type="button" class="btn btn-secondary btn-sm px-3" data-dismiss="modal" data-bs-dismiss="modal">إغلاق</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
    window.openDocumentViewerModal = function(type, docId, title) {
        var modalEl = document.getElementById('universalDocViewModal');
        if (!modalEl) return;
        if (modalEl.parentNode !== document.body) {
            document.body.appendChild(modalEl);
        }
        
        document.getElementById('universalDocTitle').innerHTML = '<i class="bi bi-file-earmark-text-fill ml-1"></i> ' + (title || 'معاينة الوثيقة المستقلة') + ' ' + (docId ? '#' + docId : '');
        var docBody = document.getElementById('universalDocBody');
        docBody.innerHTML = '<div class="text-center p-4 text-muted"><i class="spinner-border text-primary spinner-border-sm ml-2"></i> جاري تحميل تفاصيل الأصناف والبيانات الرسمية للوثيقة...</div>';
        
        if (typeof $ !== 'undefined' && $.fn && $.fn.modal) {
            $('#universalDocViewModal').modal('show');
        } else {
            modalEl.classList.add('show');
            modalEl.style.display = 'block';
        }

        var apiUrl = '../api/fetch_document_details.php?type=' + encodeURIComponent(type) + '&id=' + encodeURIComponent(docId);
        fetch(apiUrl)
            .then(function(res) { return res.text(); })
            .then(function(html) {
                docBody.innerHTML = html;
            })
            .catch(function(err) {
                docBody.innerHTML = '<div class="alert alert-danger text-center p-3 font-weight-bold">حدث خطأ أثناء تحميل تفاصيل الوثيقة: ' + err.message + '</div>';
            });
    };

    window.printUniversalDoc = function() {
        var content = document.getElementById('universalDocBody').innerHTML;
        var printWin = window.open('', '_blank', 'width=800,height=600');
        printWin.document.write(`
            <!DOCTYPE html>
            <html dir="rtl" lang="ar">
            <head>
                <title>طباعة وثيقة مستقلة</title>
                <link rel="stylesheet" href="../assets/css/bootstrap-rtl.min.css">
                <style>
                    body { font-family: Arial, sans-serif; padding: 20px; direction: rtl; text-align: right; }
                    table { width: 100%; border-collapse: collapse; margin-top: 15px; }
                    th, td { border: 1px solid #000; padding: 6px; text-align: center; }
                    th { background: #f1f5f9; }
                </style>
            </head>
            <body>
                ${content}
                <script>window.onload = function() { window.print(); setTimeout(function(){ window.close(); }, 500); }<\/script>
            </body>
            </html>
        `);
        printWin.document.close();
    };
    // ────────────────────────────────────────────────────────────────────────
    // 🎯 التركيز المباشر والتنقل بالأسهم الشامل في جميع المودالات (Global Modal Handling)
    // ────────────────────────────────────────────────────────────────────────
    (function() {
        var style = document.createElement('style');
        style.innerHTML = `
            .modal-backdrop { z-index: 10400 !important; }
            .modal { z-index: 10500 !important; }
        `;
        document.head.appendChild(style);
    })();

    document.addEventListener('DOMContentLoaded', function() {
        // 1. نقل المودال لـ document.body تلقائياً لفك قيود التراكب (Z-Index Stacking) وتركيز التصفح
        if (typeof $ !== 'undefined' && $.fn && $.fn.on) {
            $(document).on('show.bs.modal', '.modal', function() {
                if (this.parentNode !== document.body) {
                    document.body.appendChild(this);
                }
            });
            $(document).on('shown.bs.modal', '.modal', function() {
                var searchInp = this.querySelector('input[type="text"]:not([readonly]), input[type="search"], #quickProductSearchInput, #searchPurchaseQuery, #modalInvoiceSearchInput, #newSupplierNameInput');
                if (searchInp) {
                    searchInp.focus();
                    if (typeof searchInp.select === 'function') searchInp.select();
                }
            });
        }

        // 2. معالجة التصفح بالأسهم (ArrowUp, ArrowDown, Enter) في كافة المودالات
        document.addEventListener('keydown', function(e) {
            var openModal = document.querySelector('.modal.show, .modal[style*="display: block"]');
            if (!openModal) return;

            if (e.key === 'ArrowDown' || e.key === 'ArrowUp' || e.key === 'Enter') {
                var activeEl = document.activeElement;
                // إذا كان المودال مفتوحاً ولم تكن في حقل ادخال يتطلب الانتر العادي
                var rows = Array.from(openModal.querySelectorAll('tbody tr')).filter(function(r) {
                    return r.style.display !== 'none' && !r.querySelector('td[colspan]');
                });

                if (rows.length === 0) return;

                var currentIndex = rows.findIndex(function(r) {
                    return r.classList.contains('active-modal-row') || r.classList.contains('table-primary') || r.classList.contains('table-success');
                });

                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    if (currentIndex >= 0) {
                        rows[currentIndex].classList.remove('active-modal-row', 'table-primary', 'table-success');
                    }
                    var nextIndex = (currentIndex + 1) % rows.length;
                    rows[nextIndex].classList.add('table-primary', 'active-modal-row');
                    rows[nextIndex].scrollIntoView({ block: 'nearest' });
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    if (currentIndex >= 0) {
                        rows[currentIndex].classList.remove('active-modal-row', 'table-primary', 'table-success');
                    }
                    var prevIndex = (currentIndex - 1 + rows.length) % rows.length;
                    rows[prevIndex].classList.add('table-primary', 'active-modal-row');
                    rows[prevIndex].scrollIntoView({ block: 'nearest' });
                } else if (e.key === 'Enter') {
                    if (currentIndex >= 0 && rows[currentIndex]) {
                        var targetRow = rows[currentIndex];
                        var actionBtn = targetRow.querySelector('.btn-primary, .btn-success, button, a');
                        if (actionBtn && activeEl !== actionBtn) {
                            e.preventDefault();
                            actionBtn.click();
                        } else if (targetRow.onclick || targetRow.getAttribute('onclick')) {
                            e.preventDefault();
                            targetRow.click();
                        }
                    }
                }
            }
        });
    });

    window.openQuickProductModal = function() {
        let modalEl = document.getElementById('quickProductSearchModal');
        if (!modalEl) return;
        let searchInp = document.getElementById('quickProductSearchInput');
        if (typeof $ !== 'undefined' && $.fn && $.fn.modal) {
            $('#quickProductSearchModal').modal('show');
        } else {
            modalEl.classList.add('show');
            modalEl.style.display = 'block';
        }
        setTimeout(() => {
            if (searchInp) { searchInp.value = ''; searchInp.focus(); }
            window.performQuickProductSearch('');
        }, 200);
    };

    document.addEventListener('keydown', function(e) {
        if (e.key === 'F4') {
            e.preventDefault();
            window.openQuickProductModal();
        }
    });

    document.addEventListener('click', function(e) {
        if (e.target.id === 'quickProductSearchBtn' || e.target.closest('#quickProductSearchBtn') || e.target.classList.contains('quick-product-btn')) {
            e.preventDefault();
            window.openQuickProductModal();
        }
    });

    window.performQuickProductSearch = function(q) {
        let tbody = document.getElementById('quickProductSearchResults');
        if (!tbody) return;
        tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-3">جاري البحث...</td></tr>';
        fetch(`../api/search_products.php?q=${encodeURIComponent(q)}`)
            .then(res => res.json())
            .then(products => {
                if (!products || products.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-3">لا توجد نتائج مطابقة</td></tr>';
                    return;
                }
                tbody.innerHTML = products.map((p, idx) => {
                    let pJson = JSON.stringify(p).replace(/'/g, "&#39;");
                    let escapeHtml = str => (str || '').toString().replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;");
                    return `
                        <tr class="search-result-item ${idx === 0 ? 'table-primary active-modal-row' : ''}" style="cursor:pointer;" onclick="selectGlobalProductResult('${pJson}')">
                            <td>${escapeHtml(p.barcode || '-')}</td>
                            <td class="font-weight-bold text-right">${escapeHtml(p.name)}</td>
                            <td class="text-success font-weight-bold">${parseFloat(p.price || 0).toFixed(2)}</td>
                            <td class="text-primary font-weight-bold">${parseFloat(p.buy_price || 0).toFixed(2)}</td>
                            <td><span class="badge ${p.quantity > 0 ? 'badge-success' : 'badge-danger'}">${parseFloat(p.quantity || 0)}</span></td>
                            <td><button type="button" class="btn btn-xs btn-primary font-weight-bold" title="اختيار الصنف"><i class="bi bi-arrow-down-square-fill"></i></button></td>
                        </tr>
                    `;
                }).join('');
            })
            .catch(err => {
                console.error('Quick product search err:', err);
                tbody.innerHTML = '<tr><td colspan="6" class="text-center text-danger py-3">حدث خطأ أثناء تحميل الأصناف</td></tr>';
            });
    };

    window.selectGlobalProductResult = function(productJson) {
        let p = typeof productJson === 'string' ? JSON.parse(productJson) : productJson;
        if (typeof $ !== 'undefined' && $.fn && $.fn.modal) {
            $('#quickProductSearchModal').modal('hide');
        } else {
            let m = document.getElementById('quickProductSearchModal');
            if (m) m.style.display = 'none';
        }

        if (typeof window.selectProductForRow === 'function') {
            let rows = document.querySelectorAll('.item-row');
            let targetRow = rows.length > 0 ? rows[rows.length - 1] : null;
            if (!targetRow || (targetRow.querySelector('.select-product') && targetRow.querySelector('.select-product').value !== '')) {
                let addBtn = document.getElementById('addItemBtn');
                if (addBtn) addBtn.click();
                let newRows = document.querySelectorAll('.item-row');
                targetRow = newRows[newRows.length - 1];
            }
            window.selectProductForRow(targetRow, p);
        } else if (typeof window.addScannedPurchaseProduct === 'function') {
            window.addScannedPurchaseProduct(p);
        } else if (typeof window.addScannedSalesProduct === 'function') {
            window.addScannedSalesProduct(p);
        }
    };

    document.addEventListener('input', function(e) {
        if (e.target.id === 'quickProductSearchInput') {
            window.performQuickProductSearch(e.target.value.trim());
        }
    });
    </script>
    </body>
    </html>