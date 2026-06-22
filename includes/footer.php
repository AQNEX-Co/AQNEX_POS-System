        </div> <!-- End #content -->
    </div> <!-- End .wrapper -->
    
    <!-- استدعاء ملفات الجافا سكربت المشتركة -->
    <script type="text/javascript" src="<?php echo isset($prefix) ? $prefix : ''; ?>files/bower_components/jquery/js/jquery.min.js"></script>
    <script type="text/javascript" src="<?php echo isset($prefix) ? $prefix : ''; ?>files/bower_components/popper.js/js/popper.min.js"></script>
    <script type="text/javascript" src="<?php echo isset($prefix) ? $prefix : ''; ?>files/bower_components/bootstrap/js/bootstrap.min.js"></script>

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

        // 5. تأكيد تسجيل الخروج والترحيل للصندوق
        try {
            var logoutLinks = document.querySelectorAll('a[href$="auth/logout.php"], a[href$="/auth/logout.php"]');
            logoutLinks.forEach(function(a) {
                a.addEventListener('click', function(e) {
                    e.preventDefault();
                    var proceed = confirm('هل تريد ترحيل رصيد الوردية/اليوم إلى الصندوق الآن قبل تسجيل الخروج؟\nاضغط موافق للترحيل ثم تسجيل الخروج، إلغاء للمتابعة بالخروج دون ترحيل.');
                    if (proceed) {
                        window.location.href = (this.getAttribute('href').startsWith('../') ? '../box/close.php' : 'box/close.php');
                    } else {
                        window.location.href = this.getAttribute('href');
                    }
                });
            });
        } catch (e) {
            console.error("Error setting logout confirm handler:", e);
        }

    });

    // تحديث الساعة لحظياً كل ثانية
    var liveTimeEl = document.getElementById('live-time');
    if (liveTimeEl) {
        function updateLiveClock() {
            var now = new Date();
            var h = now.getHours();
            var m = now.getMinutes();
            var s = now.getSeconds();
            var ampm = h >= 12 ? 'م' : 'ص';
            h = h % 12 || 12;
            liveTimeEl.textContent = 
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
    </script>
    </body>
    </html>
