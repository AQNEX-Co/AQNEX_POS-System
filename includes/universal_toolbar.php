<?php
// universal_toolbar.php - Standardized ERP Action Toolbar
?>
<style>
.universal-toolbar {
    background: #ffffff;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    padding: 10px 15px;
    margin-bottom: 20px;
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.03);
    justify-content: center;
}
.utoolbar-btn {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 5px;
    background: transparent;
    border: none;
    color: #475569;
    font-size: 0.75rem;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.2s ease;
    min-width: 65px;
    padding: 5px;
    border-radius: 6px;
    outline: none !important;
}
.utoolbar-btn:hover:not(:disabled) {
    background: #f1f5f9;
    color: #1d4ed8;
}
.utoolbar-btn:disabled {
    opacity: 0.4;
    cursor: not-allowed;
}
.utoolbar-icon {
    width: 38px;
    height: 38px;
    border-radius: 50%;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.15rem;
    color: inherit;
    transition: all 0.2s ease;
}
.utoolbar-btn:hover:not(:disabled) .utoolbar-icon {
    background: #1d4ed8;
    border-color: #1d4ed8;
    color: #ffffff;
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(29, 78, 216, 0.2);
}
/* Specific Colors */
.utoolbar-btn.ut-save:hover:not(:disabled) .utoolbar-icon { background: #15803d; border-color: #15803d; box-shadow: 0 4px 8px rgba(21, 128, 61, 0.2); }
.utoolbar-btn.ut-save:hover:not(:disabled) { color: #15803d; }

.utoolbar-btn.ut-delete:hover:not(:disabled) .utoolbar-icon { background: #be123c; border-color: #be123c; box-shadow: 0 4px 8px rgba(190, 18, 60, 0.2); }
.utoolbar-btn.ut-delete:hover:not(:disabled) { color: #be123c; }

.utoolbar-btn.ut-exit:hover:not(:disabled) .utoolbar-icon { background: #0f172a; border-color: #0f172a; }
.utoolbar-btn.ut-exit:hover:not(:disabled) { color: #0f172a; }

.utoolbar-btn.ut-print:hover:not(:disabled) .utoolbar-icon { background: #475569; border-color: #475569; }
.utoolbar-btn.ut-print:hover:not(:disabled) { color: #475569; }
</style>

<div class="universal-toolbar no-print">
    <button type="button" class="utoolbar-btn ut-new" onclick="window.location.href='create.php';">
        <div class="utoolbar-icon"><i class="bi bi-file-earmark-plus"></i></div>
        <span>جديد</span>
    </button>

    <button type="button" class="utoolbar-btn ut-save" onclick="utoolbarSaveAction();" id="ut-btn-save">
        <div class="utoolbar-icon"><i class="bi bi-check2-circle"></i></div>
        <span>حفظ</span>
    </button>

    <button type="button" class="utoolbar-btn ut-edit" <?php echo empty($_GET['edit_id']) && empty($_GET['id']) ? 'disabled' : ''; ?>>
        <div class="utoolbar-icon"><i class="bi bi-pencil-square"></i></div>
        <span>تعديل</span>
    </button>

    <button type="button" class="utoolbar-btn ut-search" onclick="window.location.href='index.php';">
        <div class="utoolbar-icon"><i class="bi bi-search"></i></div>
        <span>بحث للسابق</span>
    </button>

    <button type="button" class="utoolbar-btn ut-delete" <?php echo empty($_GET['edit_id']) && empty($_GET['id']) ? 'disabled' : ''; ?>>
        <div class="utoolbar-icon"><i class="bi bi-trash"></i></div>
        <span>حذف</span>
    </button>

    <button type="button" class="utoolbar-btn ut-filter" onclick="window.location.href='index.php';">
        <div class="utoolbar-icon"><i class="bi bi-funnel"></i></div>
        <span>تصفية</span>
    </button>

    <button type="button" class="utoolbar-btn ut-print" onclick="window.print();">
        <div class="utoolbar-icon"><i class="bi bi-printer"></i></div>
        <span>طباعة</span>
    </button>

    <button type="button" class="utoolbar-btn ut-undo" onclick="window.location.reload();">
        <div class="utoolbar-icon"><i class="bi bi-arrow-counterclockwise"></i></div>
        <span>تراجع</span>
    </button>

    <button type="button" class="utoolbar-btn ut-exit" onclick="window.location.href='../home.php';">
        <div class="utoolbar-icon"><i class="bi bi-box-arrow-right"></i></div>
        <span>خروج</span>
    </button>
</div>

<script>
function utoolbarSaveAction() {
    var form = document.getElementById('main-form') || document.querySelector('form');
    if (form) {
        // إذا كان هناك حقل مخفي باسم btn_save غير موجود، نقوم بإضافته
        if (!form.querySelector('input[name="btn_save"]') && !form.querySelector('input[name="btn_save_ticket"]')) {
            var hiddenInput = document.createElement('input');
            hiddenInput.type = 'hidden';
            // التحقق من اسم النموذج، بعض النماذج تستخدم btn_save_ticket أو غيرها
            var submitName = form.id === 'repair-form' ? 'btn_save_ticket' : 'btn_save';
            hiddenInput.name = submitName;
            hiddenInput.value = '1';
            form.appendChild(hiddenInput);
        }
        
        // إذا كان الفورم يحتوي على دالة للتحقق قبل الإرسال (مثل checkInvoice)
        if (typeof window.triggerFormSave === 'function') {
            window.triggerFormSave(form);
        } else {
            // محاكاة الضغط على زر الحفظ الأصلي إن وجد لتشغيل HTML5 validation
            var originalSubmit = form.querySelector('button[type="submit"]');
            if (originalSubmit) {
                originalSubmit.click();
            } else {
                form.submit();
            }
        }
    }
}
</script>
