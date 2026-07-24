</div> <!-- End #content -->
    </div> <!-- End .wrapper -->
    
    <!-- استدعاء ملفات الجافا سكربت المشتركة -->
    <script type="text/javascript" src="<?php echo isset($prefix) ? $prefix : ''; ?>files/bower_components/jquery/js/jquery.min.js"></script>
    <script type="text/javascript" src="<?php echo isset($prefix) ? $prefix : ''; ?>files/bower_components/popper.js/js/popper.min.js"></script>
    <script type="text/javascript" src="<?php echo isset($prefix) ? $prefix : ''; ?>files/bower_components/bootstrap/js/bootstrap.min.js"></script>
    <script type="text/javascript" src="<?php echo isset($prefix) ? $prefix : ''; ?>assets/js/barcode-listener.js"></script>

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

        // 5. تأكيد تسجيل الخروج مباشرة دون المطالبة بالترحيل
        try {
            var logoutLinks = document.querySelectorAll('a[href$="auth/logout.php"], a[href$="/auth/logout.php"]');
            logoutLinks.forEach(function(a) {
                a.addEventListener('click', function(e) {
                    e.preventDefault();
                    if (confirm('هل أنت متأكد من تسجيل الخروج؟')) {
                        window.location.href = this.getAttribute('href');
                    }
                });
            });
        } catch (e) {
            console.error("Error setting logout confirm handler:", e);
        }

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

// ============================================================
// تحسين تجربة القوائم المنسدلة والإكمال التلقائي (Select2 & Autocomplete UX)
// تنظيف نص البحث السابق فور فتح القائمة لإظهار جميع الخيارات
// ============================================================
if (typeof $ !== 'undefined') {
    $(document).on('select2:open', function(e) {
        // عند فتح أي قائمة Select2 بالنظام، نقوم بتفريغ نص البحث فوراً لكي تظهر جميع الخيارات للمستخدم
        setTimeout(function() {
            var searchField = document.querySelector('.select2-container--open .select2-search__field');
            if (searchField) {
                searchField.value = '';
                searchField.focus();
                searchField.dispatchEvent(new Event('input', { bubbles: true }));
            }
        }, 10);
    });

    // عند اختيار أي عنصر من Select2 إغلاق القائمة فوراً
    $(document).on('select2:select', function(e) {
        $(e.target).select2('close');
    });
}

// اختصار الحفظ الموحد بالنظام زر (F10) وإغلاق القوائم عند الانتقال بزر الانتر
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
    } else if (e.key === 'Enter' || e.key === 'Tab') {
        // إغلاق كافة القوائم المنسدلة غير المجهولة فور الانتقال للحقل التالي
        document.querySelectorAll('.autocomplete-dropdown').forEach(function(d) {
            d.classList.add('d-none');
        });
        if (typeof $ !== 'undefined' && $.fn.select2) {
            $('.select2-hidden-accessible').select2('close');
        }
    }
});

// دالة إظهار التنبيهات الفورية المنبثقة (Enterprise Popup Alert)
window.showSystemAlert = function(title, message, type) {
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
    <div class="modal fade" id="systemAlertModal" tabindex="-1" role="dialog" aria-hidden="true" style="z-index: 99999;">
        <div class="modal-dialog modal-dialog-centered" role="document" style="max-width: 440px;">
            <div class="modal-content" style="border-radius: 6px !important; overflow: hidden; border: none; box-shadow: 0 20px 40px rgba(0,0,0,0.3);">
                <div class="modal-header" style="background: ${headerBg} !important; color: #fff; padding: 12px 18px;">
                    <h5 class="modal-title" style="font-size: 1rem; font-weight: 700; color: #fff; margin: 0; display: flex; align-items: center; gap: 8px;">
                        <i class="bi ${iconClass}" style="font-size: 1.2rem; color: #fff !important;"></i>
                        <span>${title}</span>
                    </h5>
                    <button type="button" class="close" data-dismiss="modal" data-bs-dismiss="modal" aria-label="إغلاق" style="color: #fff; opacity: 0.9; background: none; border: none; font-size: 1.4rem;">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body text-right" style="padding: 20px; font-size: 0.92rem; color: #1e293b; line-height: 1.6; background: #fff !important;">
                    ${message}
                </div>
                <div class="modal-footer" style="padding: 10px 18px; background: #f8fafc; border-top: 1px solid #e2e8f0;">
                    <button type="button" class="btn btn-dark btn-sm px-4" data-dismiss="modal" data-bs-dismiss="modal" style="font-weight: 700; border-radius: 4px !important;">حسناً (Enter)</button>
                </div>
            </div>
        </div>
    </div>`;

    var existingModal = document.getElementById('systemAlertModal');
    if (existingModal) existingModal.remove();

    document.body.insertAdjacentHTML('beforeend', alertModalHtml);

    var $alertModal = $('#systemAlertModal');
    if ($alertModal && typeof $alertModal.modal === 'function') {
        $alertModal.modal('show');
        $alertModal.on('shown.bs.modal', function() {
            $(this).find('button').focus();
        });
    } else {
        alert(title + "\n" + message);
    }
};

// التنقل التلقائي بين حقول الإدخال عند ضغط مفتاح Enter بدون الحاجة للماوس
document.addEventListener('keydown', function(e) {
    if (e.key === 'Enter') {
        var target = e.target;
        var isInputField = target.tagName === 'INPUT' || target.tagName === 'SELECT';
        var isTextArea = target.tagName === 'TEXTAREA';
        var isSubmitBtn = target.type === 'submit' || target.classList.contains('btn-save-action');

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
    <!-- <button id="ai-assistant-toggle" class="no-print" style="position: fixed; bottom: 25px; left: 25px; width: 56px; height: 56px; border-radius: 50%; background: linear-gradient(135deg, #0369a1 0%, #0284c7 100%); color: #fff; border: none; box-shadow: 0 8px 30px rgba(2, 132, 199, 0.4); cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 1.6rem; z-index: 10001; transition: transform 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275); outline: none;">
        <i class="bi bi-robot" id="toggle-icon"></i>
    </button> -->

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
            
            fetch("<?php echo $pfx; ?>api/ai_assistant.php", {
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

    // ────────────────────────────────────────────────────────────────────────
    // 🚀 AQNEX POS - إضافة ميزات التنقل بزر Enter والـ Autocomplete الشامل
    // ────────────────────────────────────────────────────────────────────────
    
    // 1. حقن تنسيق الاستايل الفاخر للـ Autocomplete
    (function() {
        var style = document.createElement('style');
        style.innerHTML = `
            .aqnex-select-container {
                position: relative;
                display: inline-block;
                width: 100%;
            }
            .aqnex-select-input {
                width: 100%;
                padding: 8px 12px;
                font-family: 'Tajawal', sans-serif;
                font-size: 0.95rem;
                font-weight: 600;
                border: 1px solid #ced4da;
                background-color: #fff;
                color: #495057;
                outline: none;
                transition: border-color 0.15s ease-in-out, box-shadow 0.15s ease-in-out;
                cursor: pointer;
            }
            .aqnex-select-input:focus {
                border-color: #1d6bff;
                box-shadow: 0 0 0 0.2rem rgba(29, 107, 255, 0.25);
            }
            .aqnex-select-dropdown {
                position: absolute;
                top: 100%;
                left: 0;
                right: 0;
                z-index: 1050;
                display: none;
                max-height: 250px;
                overflow-y: auto;
                margin-top: 2px;
                padding: 5px 0;
                background-color: #fff;
                border: 1px solid rgba(0, 0, 0, 0.15);
                box-shadow: 0 6px 12px rgba(0, 0, 0, 0.175);
                direction: rtl;
                text-align: right;
            }
            .aqnex-select-item {
                padding: 8px 15px;
                font-size: 0.9rem;
                color: #212529;
                cursor: pointer;
                transition: background-color 0.15s;
            }
            .aqnex-select-item:hover, .aqnex-select-item.active {
                background-color: #f1f5f9;
                color: #1d6bff;
                font-weight: bold;
            }
            .aqnex-select-item.selected-val {
                background-color: #e2e8f0;
                color: #0f172a;
                font-weight: bold;
            }
            .aqnex-select-item.no-results {
                color: #7d8590;
                text-align: center;
                cursor: default;
                font-style: italic;
            }
        `;
        document.head.appendChild(style);
    })();

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
        var selects = document.querySelectorAll('select:not(.no-autocomplete):not([multiple])');
        selects.forEach(function(select) {
            if (select.dataset.aqnexInit === 'true' || select.style.display === 'none') {
                return; // تم تهيئته مسبقاً أو حقل مخفي مخصص
            }
            select.dataset.aqnexInit = 'true';
            
            // إنشاء الحاوية والعنصر البديل
            var container = document.createElement('div');
            container.className = 'aqnex-select-container';
            if (select.className) {
                container.classList.add('original-' + select.className);
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
    </body>
    </html>