/**
 * ═════════════════════════════════════════════════════════════════
 * AQNEX POS - Unified Core JS Engine
 * Real-time Validation, Custom Retro Alert Modal & Autocomplete Dropdowns
 * ═════════════════════════════════════════════════════════════════
 */

(function (window, document, $) {
    'use strict';

    // 1. Message Registry with Standardized Accounting Error Codes
    const AQNEX_MESSAGES = {
        87: 'فضلاً اختر القطاع / مركز التكلفة',
        886: 'فضلاً حدد نوع طريقة الدفع',
        101: 'فضلاً اختر اسم المورد / العمـيل',
        102: 'فضلاً أضف أصنافاً للجدول قبل الحفظ',
        103: 'فضلاً حدد الصندوق / الحساب النقدي',
        104: 'المبلغ المدخل غير صحيح أو لا يمكن تجاوزه',
        105: 'الكمية المطلوبة غير متوفرة بالمخزن',
        106: 'فضلاً اختر المستودع / المخزن لتخزين الأصناف فيه',
        107: 'فضلاً حدد نوع الفاتورة أولاً (نقدي / آجل)',
        108: 'سعر الشراء المدخل يجب أن يكون أكبر من الصفر',
        109: 'سعر البيع المدخل يجب أن يكون أكبر من أو يساوي سعر الشراء',
        110: 'خطأ: رصيد الصندوق غير كافٍ لإتمام هذه المعاملة',
        111: 'فضلاً حدد تصنيفاً / مجموعة مناسبة لكل صنف جديد مضاف',
        112: 'اسم الصنف المدخل غير صالح أو مكرر',
        113: 'اسم المجموعة / التصنيف الجديد لا يمكن أن يكون فارغاً',
        114: 'فضلاً امسح أو اكتب باركوداً صالحاً للمنتج الجديد',
        115: 'قيمة الخصم لا يمكن أن تتجاوز قيمة الإجمالي',
        116: 'المبلغ المدفوع لا يمكن أن يتجاوز صافي الفاتورة',
        117: 'تم استيراد ملف الأصناف بنجاح!',
        118: 'خطأ في تنسيق ملف الاستيراد، يرجى التأكد من الحقول وسلاسل البيانات',
        119: 'تم حفظ الفاتورة بنجاح!',
        120: 'يرجى إكمال بيانات كافة الأصناف المدخلة أولاً',
        121: 'فضلاً حدد نوع المحفظة / البنك المالي المستخدم',
        122: 'خطأ: نوع الفاتورة (آجل) يتطلب تحديد مورد آجل مسجل وليس مورد نقدي',
        123: 'اسم المورد المدخل غير مسجل لدينا، هل ترغب في إضافته كمورد جديد؟',
        124: 'يرجى كتابة رقم جوال المورد لإضافته بنجاح',
        500: 'حدث خطأ أثناء معالجة العملية، يرجى المحاولة لاحقاً'
    };

    // Global Modal Reference
    let modalOverlayEl = null;
    let focusTargetEl = null;
    let isModalOpen = false;

    // 2. Custom Alert Modal Engine
    const AqnexAlert = {
        init: function () {
            if (document.getElementById('aqnex-alert-overlay')) {
                modalOverlayEl = document.getElementById('aqnex-alert-overlay');
                return;
            }

            const modalHtml = `
                <div class="aqnex-modal-overlay" id="aqnex-alert-overlay" role="dialog" aria-modal="true">
                    <div class="aqnex-dialog-window">
                        <div class="aqnex-dialog-header">
                            <span class="aqnex-dialog-title" id="aqnex-alert-title">Message No - 0</span>
                            <button type="button" class="aqnex-dialog-close" id="aqnex-alert-close" title="إغلاق">&times;</button>
                        </div>
                        <div class="aqnex-dialog-body">
                            <div class="aqnex-dialog-content" id="aqnex-alert-text"></div>
                            <div class="aqnex-dialog-icon-wrapper">
                                <div class="aqnex-info-icon-badge">i</div>
                            </div>
                        </div>
                        <div class="aqnex-dialog-footer">
                            <button type="button" class="aqnex-dialog-btn-ok" id="aqnex-alert-ok">موافق</button>
                        </div>
                    </div>
                </div>
            `;

            document.body.insertAdjacentHTML('beforeend', modalHtml);
            modalOverlayEl = document.getElementById('aqnex-alert-overlay');

            // Event Listeners
            document.getElementById('aqnex-alert-ok').addEventListener('click', AqnexAlert.close);
            document.getElementById('aqnex-alert-close').addEventListener('click', AqnexAlert.close);

            document.addEventListener('keydown', function (e) {
                if (isModalOpen && (e.key === 'Enter' || e.key === 'Escape')) {
                    e.preventDefault();
                    e.stopPropagation();
                    AqnexAlert.close();
                }
            }, true);
        },

        show: function (msgNo, customText, targetElement) {
            AqnexAlert.init();

            if (isModalOpen) return;

            const text = customText || AQNEX_MESSAGES[msgNo] || 'تنبيه في النظام';
            document.getElementById('aqnex-alert-title').textContent = `Message No - ${msgNo}`;
            document.getElementById('aqnex-alert-text').textContent = text;

            focusTargetEl = targetElement || null;
            isModalOpen = true;

            modalOverlayEl.classList.add('active');

            // Focus the OK button inside modal
            setTimeout(function () {
                const okBtn = document.getElementById('aqnex-alert-ok');
                if (okBtn) okBtn.focus();
            }, 50);
        },

        close: function () {
            if (!isModalOpen) return;

            if (modalOverlayEl) {
                modalOverlayEl.classList.remove('active');
            }
            isModalOpen = false;

            // Restore focus to invalid input element
            if (focusTargetEl) {
                setTimeout(function () {
                    if (focusTargetEl.tagName === 'SELECT' && window.jQuery && $(focusTargetEl).data('select2')) {
                        $(focusTargetEl).select2('open');
                    } else if (typeof focusTargetEl.focus === 'function') {
                        focusTargetEl.focus();
                    }
                    focusTargetEl = null;
                }, 100);
            }
        }
    };

    // 2.5 Custom Confirm Dialog Engine
    let confirmOverlayEl = null;
    let confirmCallback = null;
    let isConfirmOpen = false;

    const AqnexConfirm = {
        init: function () {
            if (document.getElementById('aqnex-confirm-overlay')) {
                confirmOverlayEl = document.getElementById('aqnex-confirm-overlay');
                return;
            }

            const modalHtml = `
                <div class="aqnex-modal-overlay" id="aqnex-confirm-overlay" role="dialog" aria-modal="true">
                    <div class="aqnex-dialog-window">
                        <div class="aqnex-dialog-header">
                            <span class="aqnex-dialog-title" id="aqnex-confirm-title">تأكيد الإجراء</span>
                            <button type="button" class="aqnex-dialog-close" id="aqnex-confirm-close" title="إغلاق">&times;</button>
                        </div>
                        <div class="aqnex-dialog-body">
                            <div class="aqnex-dialog-content" id="aqnex-confirm-text">هل أنت متأكد؟</div>
                            <div class="aqnex-dialog-icon-wrapper">
                                <div class="aqnex-info-icon-badge" style="background: var(--danger, #dc3545);">?</div>
                            </div>
                        </div>
                        <div class="aqnex-dialog-footer">
                            <button type="button" class="aqnex-dialog-btn-ok" id="aqnex-confirm-yes" style="margin-left: 10px; background: linear-gradient(180deg, #ef4444 0%, #dc2626 100%); color: #fff; border-color: #b91c1c;">نعم</button>
                            <button type="button" class="aqnex-dialog-btn-ok" id="aqnex-confirm-no" style="background: linear-gradient(180deg, #f1f5f9 0%, #cbd5e1 100%); color: #334155; border-color: #94a3b8;">إلغاء</button>
                        </div>
                    </div>
                </div>
            `;

            document.body.insertAdjacentHTML('beforeend', modalHtml);
            confirmOverlayEl = document.getElementById('aqnex-confirm-overlay');

            // Event Listeners
            document.getElementById('aqnex-confirm-yes').addEventListener('click', function() {
                AqnexConfirm.close(true);
            });
            document.getElementById('aqnex-confirm-no').addEventListener('click', function() {
                AqnexConfirm.close(false);
            });
            document.getElementById('aqnex-confirm-close').addEventListener('click', function() {
                AqnexConfirm.close(false);
            });

            document.addEventListener('keydown', function (e) {
                if (isConfirmOpen) {
                    if (e.key === 'Escape') {
                        e.preventDefault();
                        e.stopPropagation();
                        AqnexConfirm.close(false);
                    }
                }
            }, true);
        },

        show: function (titleOrText, textOrCallback, callbackFn) {
            AqnexConfirm.init();

            if (isConfirmOpen) return;

            let title = 'تأكيد الإجراء';
            let text = '';
            let cb = null;

            if (typeof textOrCallback === 'function') {
                text = titleOrText;
                cb = textOrCallback;
            } else if (typeof callbackFn === 'function') {
                title = titleOrText;
                text = textOrCallback;
                cb = callbackFn;
            } else {
                text = titleOrText;
                cb = textOrCallback;
            }

            const titleEl = document.getElementById('aqnex-confirm-title');
            if (titleEl) titleEl.textContent = title;

            const textEl = document.getElementById('aqnex-confirm-text');
            if (textEl) textEl.textContent = text;

            confirmCallback = cb;
            isConfirmOpen = true;

            confirmOverlayEl.classList.add('active');

            setTimeout(function () {
                const noBtn = document.getElementById('aqnex-confirm-no');
                if (noBtn) noBtn.focus();
            }, 50);
        },

        close: function (result) {
            if (!isConfirmOpen) return;

            if (confirmOverlayEl) {
                confirmOverlayEl.classList.remove('active');
            }
            isConfirmOpen = false;

            const cb = confirmCallback;
            confirmCallback = null;

            if (typeof cb === 'function') {
                cb(result);
            }
        }
    };
    window.AqnexConfirm = AqnexConfirm;

    // 3. Autocomplete Engine
    const AqnexAutocomplete = {
        init: function (selector) {
            const querySelector = selector || 'select.aqnex-select, select.select2-autocomplete, select.select2';
            if (window.jQuery && $.fn.select2) {
                $(querySelector).each(function () {
                    const $select = $(this);
                    if ($select.hasClass('select2-hidden-accessible')) return;
                    if ($select.attr('data-autocomplete-attached') === 'true' || $select.closest('.header-autocomplete-wrapper').length > 0) {
                        return;
                    }

                    // Remove native required to avoid browser validation crashes on hidden fields
                    if ($select.prop('required')) {
                        $select.removeAttr('required');
                        $select.attr('data-was-required', 'true');
                    }

                    $select.select2({
                        dir: 'rtl',
                        language: 'ar',
                        width: '100%',
                        placeholder: $select.find('option[value=""]').text() || 'اختر من القائمة...',
                        allowClear: !$select.attr('data-was-required')
                    });
                });
            }
        }
    };

    // 4. Immediate Real-time Validation Engine
    const AqnexValidation = {
        initImmediateValidation: function () {
            // Immediate check on blur / change for Sector / Cost Center
            $(document).on('blur change', 'select[name="sector_id"], select[name="cost_center_id"], .aqnex-sector-required', function () {
                const val = $(this).val();
                if (!val || val.trim() === '') {
                    AqnexAlert.show(87, AQNEX_MESSAGES[87], this);
                }
            });

            // Immediate check on blur / change for Payment Method / Invoice Type
            $(document).on('change', 'select[name="payment_method"]', function () {
                const val = $(this).val();
                if (!val || val.trim() === '') {
                    AqnexAlert.show(886, AQNEX_MESSAGES[886], this);
                }
            });

            // Immediate check on blur / change for Customer / Supplier
            $(document).on('change', 'select[name="supplier_name"], select[name="customer_name"], select[name="cust_id"], select[name="supp_id"], input[name="cust_name"], input[name="supp_name"]', function () {
                const val = $(this).val();
                if (!val || val.toString().trim() === '') {
                    AqnexAlert.show(101, AQNEX_MESSAGES[101], this);
                }
            });
        },

        validateForm: function (formEl) {
            let isValid = true;
            const $form = $(formEl);

            // Check dynamic was-required fields
            $form.find('[data-was-required="true"]').each(function() {
                if ($(this).prop('disabled') || $(this).is(':hidden') || $(this).closest('.d-none').length > 0) {
                    return; // Skip hidden/disabled fields
                }
                const val = $(this).val();
                if (!val || val.toString().trim() === '') {
                    isValid = false;
                    const name = $(this).attr('name');
                    if (name === 'supplier_name' || name === 'supp_id' || name === 'customer_name' || name === 'cust_id') {
                        AqnexAlert.show(101, AQNEX_MESSAGES[101], this);
                    } else if (name === 'payment_method') {
                        AqnexAlert.show(886, AQNEX_MESSAGES[886], this);
                    } else if (name === 'box_id') {
                        AqnexAlert.show(103, AQNEX_MESSAGES[103], this);
                    } else if (name === 'sector_id' || name === 'cost_center_id') {
                        AqnexAlert.show(87, AQNEX_MESSAGES[87], this);
                    } else {
                        AqnexAlert.show(500, 'هذا الحقل مطلوب لإتمام عملية الحفظ', this);
                    }
                    return false; // Break loop
                }
            });

            if (!isValid) return false;

            // Check Sector / Cost Center
            const $sector = $form.find('select[name="sector_id"], select[name="cost_center_id"]');
            if ($sector.length > 0 && !$sector.prop('disabled') && !$sector.is(':hidden') && (!$sector.val() || $sector.val().trim() === '')) {
                AqnexAlert.show(87, AQNEX_MESSAGES[87], $sector[0]);
                return false;
            }

            // Check Supplier / Customer
            const $supp = $form.find('select[name="supplier_name"], select[name="supp_id"], select[name="customer_name"], select[name="cust_id"]');
            if ($supp.length > 0 && !$supp.prop('disabled') && !$supp.is(':hidden') && (!$supp.val() || $supp.val().toString().trim() === '')) {
                AqnexAlert.show(101, AQNEX_MESSAGES[101], $supp[0]);
                return false;
            }

            // Check Payment Method
            const $pMethod = $form.find('select[name="payment_method"]');
            if ($pMethod.length > 0 && !$pMethod.prop('disabled') && !$pMethod.is(':hidden') && (!$pMethod.val() || $pMethod.val().trim() === '')) {
                AqnexAlert.show(886, AQNEX_MESSAGES[886], $pMethod[0]);
                return false;
            }

            // Check Box / Treasury
            const $box = $form.find('select[name="box_id"], input[name="box_id"]');
            if ($box.length > 0 && !$box.prop('disabled') && !$box.is(':hidden') && (!$box.val() || $box.val().toString().trim() === '')) {
                AqnexAlert.show(103, AQNEX_MESSAGES[103], $box[0]);
                return false;
            }

            // Check invoice items
            const isReceiptOrExpense = $form.attr('id') === 'receiptForm' || $form.attr('id') === 'expenseForm';
            if (!isReceiptOrExpense) {
                const $items = $form.find('.item-row');
                let hasItems = false;
                $items.each(function() {
                    const prodId = $(this).find('.select-product').val();
                    const prodName = $(this).find('.product-search-input').val();
                    if ((prodId && prodId.trim() !== '') || (prodName && prodName.trim() !== '')) {
                        hasItems = true;
                    }
                });
                // if ($items.length === 0 || !hasItems) {
                //     AqnexAlert.show(102, AQNEX_MESSAGES[102] || 'فضلاً أضف أصنافاً للجدول قبل الحفظ');
                //     return false;
                // }
            } else {
                // Receipts/Expenses validation:
                const $rows = $form.find('.item-row');
                let hasItems = false;
                let isRowValid = true;
                
                $rows.each(function() {
                    // const $select = $(this).find('select');
                    const $select = $(this).find('select, .row-service-input');
                    const selectVal = $select.val();
                    const amountInput = $(this).find('.price-input, input[name="unit_price[]"], input[name="price[]"]');
                    const amountVal = parseFloat(amountInput.val()) || 0;
                    
                    if (selectVal && selectVal.trim() !== "") {
                        hasItems = true;
                        if (amountVal <= 0) {
                            AqnexAlert.show(110, 'خطأ: يجب إدخال مبلغ صحيح أكبر من صفر للحساب المحدد!', amountInput[0]);
                            isRowValid = false;
                            return false; // break loop
                        }
                    } else if (amountVal > 0) {
                        AqnexAlert.show(101, 'خطأ: يرجى تحديد الحساب للمبلغ المدخل!', $select[0]);
                        isRowValid = false;
                        return false; // break loop
                    }
                });
                
                if (!isRowValid) return false;
                if (!hasItems) {
                    AqnexAlert.show(102, 'تحذير: يرجى إضافة دفعة واحدة على الأقل بالجدول تحتوي على عميل ومبلغ مالي صحيح!');
                    return false;
                }
            }

            return isValid;
        }
    };

    // Aqnex Form State Control (Freeze / Unfreeze / Saved State)
    const AqnexFormState = {
        freezeForm: function (formSelector) {
            const $form = $(formSelector);
            if ($form.length === 0) return;
            
            $form.find('input, textarea, select').each(function() {
                if ($(this).prop('disabled')) {
                    $(this).attr('data-originally-disabled', 'true');
                }
                $(this).prop('readonly', true);
                if ($(this).is('select')) {
                    $(this).prop('disabled', true);
                    if ($(this).data('select2')) {
                        $(this).trigger('change.select2');
                    }
                }
            });
            
            $form.find('.btn-add-row, .btn-remove-row, button.btn-danger, button.btn-success, .item-row button, .add-row-btn, .delete-row-btn, #add-row-btn, #importExcelBtn').each(function() {
                $(this).prop('disabled', true).css('opacity', '0.5');
            });
            
            $('#barcodeScanInput, #invoiceSearchInput, #excelFileInput').prop('disabled', true).css('opacity', '0.5');
            
            $('.btn-save-action, button[name="btn_save_return"], button[name="btn_save"]').prop('disabled', true).css({
                'opacity': '0.5',
                'pointer-events': 'none'
            });
            
            $form.addClass('aqnex-form-frozen');
        },
        
        unfreezeForm: function (formSelector) {
            const $form = $(formSelector);
            if ($form.length === 0) return;
            
            $form.find('input, textarea, select').each(function() {
                if ($(this).attr('data-originally-disabled') === 'true') {
                    return; 
                }
                $(this).prop('readonly', false);
                if ($(this).is('select')) {
                    $(this).prop('disabled', false);
                    if ($(this).data('select2')) {
                        $(this).trigger('change.select2');
                    }
                }
            });
            
            $form.find('.btn-add-row, .btn-remove-row, button.btn-danger, button.btn-success, .item-row button, .add-row-btn, .delete-row-btn, #add-row-btn, #importExcelBtn').each(function() {
                $(this).prop('disabled', false).css('opacity', '1');
            });
            
            $('#barcodeScanInput, #invoiceSearchInput, #excelFileInput').prop('disabled', false).css('opacity', '1');
            
            $('.btn-save-action, button[name="btn_save_return"], button[name="btn_save"]').prop('disabled', false).css({
                'opacity': '1',
                'pointer-events': 'auto'
            });
            
            $form.removeClass('aqnex-form-frozen');
        },
        
        init: function () {
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.get('saved') === '1') {
                const formSelector = 'form#salesForm, form#purchaseForm, form#receiptForm, form#expenseForm, form#returnForm';
                AqnexFormState.freezeForm(formSelector);
                
                $(document).on('click', '.aqnex-toolbar .bi-pencil-square, .aqnex-toolbar [title*="تعديل"], .aqnex-toolbar .btn-edit', function(e) {
                    if ($(formSelector).hasClass('aqnex-form-frozen')) {
                        e.preventDefault();
                        e.stopPropagation();
                        AqnexFormState.unfreezeForm(formSelector);
                        AqnexAlert.show(200, 'تم إلغاء تجميد المستند وتفعيل التعديل. يمكنك تعديل البيانات ثم الضغط على حفظ.');
                    }
                });
                
                // Show print/journal buttons
                $('.btn-print, .btn-journal').css('opacity', '1');
                
                // تعريض القيود الفعلية على window
                if (typeof actualJournalEntries !== 'undefined') {
                    window.actualJournalEntries = actualJournalEntries;
                }
            }
        }
    };

    // Auto Init on DOM Ready
    $(document).ready(function () {
        AqnexAlert.init();
        AqnexAutocomplete.init();
        AqnexValidation.initImmediateValidation();

        // اعادة حساب الإجماليات إذا كانت الصفحة في وضع saved=1
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('saved') === '1') {
            // تعريض القيود الفعلية على window أولاً
            if (typeof actualJournalEntries !== 'undefined') {
                window.actualJournalEntries = actualJournalEntries;
            }
            // إعادة حساب الإجماليات قبل التجميد
            setTimeout(function() {
                if (typeof updateGrandTotals === 'function') updateGrandTotals();
                if (typeof window.updateGrandTotals === 'function') window.updateGrandTotals();
                AqnexFormState.init();
            }, 150);
        } else {
            AqnexFormState.init();
        }

        // Prevent browser validation popups by removing required attributes dynamically
        $('select[required]').each(function() {
            $(this).removeAttr('required').attr('data-was-required', 'true');
        });

        // Global Submit Handler for AQNEX Forms
        $(document).on('submit', 'form#purchaseForm, form#salesForm, form#receiptForm, form#expenseForm, form#returnForm, .aqnex-form', function (e) {
            const isValid = AqnexValidation.validateForm(this);
            if (!isValid) {
                e.preventDefault();
                e.stopPropagation();
                return false;
            }
        });
    });

    // Expose Global Object
    window.AqnexAlert = AqnexAlert;
    window.AqnexAutocomplete = AqnexAutocomplete;
    window.AqnexValidation = AqnexValidation;
    window.AQNEX_MESSAGES = AQNEX_MESSAGES;

})(window, document, window.jQuery || null);
