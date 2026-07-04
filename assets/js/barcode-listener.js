/**
 * مستمع قارئ الباركود التلقائي (Barcode Listener)
 * يقوم بالتقاط مدخلات قارئ الباركود الذي يحاكي لوحة المفاتيح بسرعة فائقة
 * ويقوم بإطلاق حدث مخصص (barcodeScanned) يمكن الاستماع إليه في أي شاشة.
 */
(function () {
    let buffer = "";
    let lastKeyTime = 0;
    const FAST_THRESHOLD_MS = 50; // الحد الأقصى بالمللي ثانية لتعتبر الضغطات متتالية من القارئ
    const MIN_BARCODE_LENGTH = 4;

    document.addEventListener("keydown", function (e) {
        // نتحقق من أن المستخدم لا يكتب داخل مربعات النصوص الكبيرة (textarea) لتجنب مقاطعة كتابته
        if (e.target.tagName === 'TEXTAREA') {
            return;
        }

        const now = Date.now();
        const elapsed = now - lastKeyTime;
        lastKeyTime = now;

        // إذا كانت المدة بين الضغطة الحالية والسابقة طويلة، فإن الإدخال يعتبر بداية إدخال جديد
        if (elapsed > 200) {
            buffer = "";
        }

        if (e.key === "Enter") {
            if (buffer.length >= MIN_BARCODE_LENGTH) {
                // نطلق الحدث فقط إذا كانت سرعة الضغطات متوافقة مع الأجهزة (قارئ الباركود) وليس إدخالاً يدوياً بطيئاً
                if (elapsed <= FAST_THRESHOLD_MS) {
                    window.dispatchEvent(new CustomEvent("barcodeScanned", { detail: { code: buffer.trim() } }));
                    e.preventDefault(); // منع إرسال الفورمات عن الخطأ
                }
            }
            buffer = "";
            return;
        }

        // إضافة الأحرف المطبوعة فقط وتجاهل أزرار التحكم (مثل Shift, Control)
        if (e.key && e.key.length === 1) {
            buffer += e.key;
        }
    });
})();
