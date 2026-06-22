<?php
/**
 * AQNEX License Cryptography Helper for Hosting Provider
 * 
 * هذا الملف يحتوي على كلاس التشفير وتوقيع التراخيص للاستضافة.
 * يقوم المساعد بقراءة طلب التفعيل وتوليد ملف الترخيص مدمجاً به المفتاح العام ديناميكياً.
 */
class AQNEXLicenseCrypto {
    
    /**
     * قراءة وفك تشفير ملف طلب التفعيل المستلم من العميل
     */
    public static function parseActivationRequest($content) {
        $decoded = @base64_decode(trim($content));
        if (!$decoded) return null;
        return @json_decode($decoded, true);
    }

    /**
     * توليد وتوقيع ملف الترخيص بالكامل وتضمين المفتاح العام ديناميكياً
     */
    public static function generateLicenseFile($payload) {
        $payloadBase64 = base64_encode(json_encode($payload));
        
        // تحديد مسار المفتاح الخاص على الاستضافة
        // يبحث أولاً في نفس المجلد، ثم في المجلد الأب، ثم في مجلد مفاتيح خاص
        $possiblePaths = [
            __DIR__ . '/private.key',
            __DIR__ . '/../keys/private.key',
            __DIR__ . '/../private.key',
            __DIR__ . '/../../keys/private.key',
            __DIR__ . '/../license_manager/private.key'
        ];
        
        $privateKeyContent = '';
        foreach ($possiblePaths as $path) {
            if (file_exists($path) && filesize($path) > 10) {
                $privateKeyContent = file_get_contents($path);
                break;
            }
        }
        
        if (empty($privateKeyContent)) {
            throw new Exception("خطأ: ملف المفتاح الخاص (private.key) غير موجود على الاستضافة. يرجى التأكد من رفعه بجانب السكربت.");
        }
        
        $resKey = openssl_get_privatekey($privateKeyContent);
        if (!$resKey) {
            throw new Exception("خطأ: ملف المفتاح الخاص تالف أو غير صالح.");
        }
        
        // التوقيع الرقمي للمحتوى
        $signature = '';
        if (!openssl_sign($payloadBase64, $signature, $resKey, OPENSSL_ALGO_SHA256)) {
            throw new Exception("خطأ: فشل التوقيع الرقمي للترخيص.");
        }
        $signatureBase64 = base64_encode($signature);
        
        // استخراج المفتاح العام المقابل للمفتاح الخاص ديناميكياً لتضمينه في الكود
        $pubKeyDetails = openssl_pkey_get_details($resKey);
        $publicKeyPEM = isset($pubKeyDetails["key"]) ? $pubKeyDetails["key"] : '';
        
        // تجهيز الهيكل النهائي للملف مضافاً إليه المفتاح العام الديناميكي
        $licenseData = [
            'payload' => $payloadBase64,
            'signature' => $signatureBase64,
            'public_key' => $publicKeyPEM
        ];
        
        return json_encode($licenseData, JSON_PRETTY_PRINT);
    }
}
?>
