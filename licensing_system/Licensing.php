<?php
namespace AQNEX\Licensing;

/**
 * كلاس إدارة والتحقق من تراخيص الأنظمة البرمجية - نسخة مستقلة وقابلة لإعادة الاستخدام
 */
class Licensing {
    private $licensePath;
    
    // ====================================================================
    // الصق المفتاح العام (Public Key) الخاص بنظامك هنا:
    // (يتم توليده تلقائياً من لوحة التحكم المرفقة في ملف license_generator.php)
    // ====================================================================
    private $publicKey = "-----BEGIN PUBLIC KEY-----\n" .
        "MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAwqaEP+b3Z4tfPiMHsqHV\n" .
        "WbhrCOC5Tea4R2CbB6hf35oZrd4GaM7mP9+m0pmqgVfCU1lYhhOYAniCF7Okzumv\n" .
        "KW+qK2kpVGdtlHYHZsDV1BuZK2mXWmmm6246YIoYxUaXCuqeqdAcFNMUCc2XG0oS\n" .
        "NFHHfDK5OeCtoJcVHhv7RtWnLyhv7FjgUr4JUgxDby5VyBsdRack26MW1mFNZCj8\n" .
        "o6sUzmQsMd4G7+INI5NBHcHf0aSOJRt1TRPLWwHiq4cW3Lk858vigQSxBwMVqw34\n" .
        "8JRbs/EPUs5pC8YgcX5hXRRl5ancPU9Si7WFRPa3xqV5pHff9bo949XEQZ51pDoO\n" .
        "+QIDAQAB\n" .
        "-----END PUBLIC KEY-----";

    /**
     * @param string|null $licensePath مسار ملف الترخيص النهائي للعميل
     */
    public function __construct($licensePath = null) {
        // إذا لم يتم تحديد مسار، سيتم الحفظ تلقائياً في نفس مجلد ملف الترخيص الافتراضي
        $this->licensePath = $licensePath ?? __DIR__ . '/license.AQNEX';
    }

    /**
     * توليد معرف فريد للجهاز بالاعتماد على الهاردوير (لويندوز)
     */
    public static function generateMachineID() {
        // محاولة جلب معلومات المكونات الثلاثة الأساسية
        $cpu = self::getSystemHWInfo('wmic cpu get processorid');
        $disk = self::getSystemHWInfo('wmic diskdrive where "index=0" get serialnumber');
        $board = self::getSystemHWInfo('wmic baseboard get serialnumber');

        // تنظيف القيم لضمان استقرار المعرف
        $cpu = trim($cpu);
        $disk = trim($disk);
        $board = trim($board);

        // إذا فشلت الأوامر أو كانت فارغة، استخدم قيم احتياطية لضمان عدم توقف النظام
        if (empty($cpu) && empty($disk) && empty($board)) {
            $computerName = getenv('COMPUTERNAME');
            $processorId = getenv('PROCESSOR_IDENTIFIER');
            $uuid = self::getSystemHWInfo('wmic csproduct get uuid');
            return hash('sha256', 'FALLBACK_' . $computerName . '_' . $processorId . '_' . trim($uuid));
        }

        // دمج المعرفات وعمل Hash SHA-256
        return hash('sha256', 'AQNEX_' . $cpu . '_' . $disk . '_' . $board);
    }

    /**
     * التحقق من الترخيص وقراءة بيانات الرخصة الموقعة رقمياً
     */
    public function verifyLicense() {
        if (!file_exists($this->licensePath)) {
            return ['status' => false, 'message' => 'ملف الترخيص غير موجود في المسار المحدد.'];
        }

        $licenseContent = file_get_contents($this->licensePath);
        $licenseData = json_decode($licenseContent, true);

        if (!$licenseData || !isset($licenseData['payload']) || !isset($licenseData['signature'])) {
            return ['status' => false, 'message' => 'ملف الترخيص تالف أو غير صالح البنية.'];
        }

        $pubKey = (!empty($licenseData['public_key'])) ? $licenseData['public_key'] : $this->publicKey;
        if (empty($pubKey) || strpos($pubKey, '-----BEGIN PUBLIC KEY-----') === false) {
            return ['status' => false, 'message' => 'تنبيه: المفتاح العام للشركة غير مهيأ داخل كود Licensing.php.'];
        }
        
        // التحقق من التوقيع الرقمي (Signature) باستخدام المفتاح العام
        $signature = base64_decode($licenseData['signature']);
        $payloadRaw = $licenseData['payload'];

        $verified = openssl_verify(
            $payloadRaw,
            $signature,
            $pubKey,
            OPENSSL_ALGO_SHA256
        );

        if ($verified !== 1) {
            return ['status' => false, 'message' => 'تم تعديل ملف الترخيص أو أن التوقيع الرقمي للترخيص غير مطابق.'];
        }

        // فك تشفير محتوى الحمولة
        $payload = json_decode(base64_decode($payloadRaw), true);
        if (!$payload) {
            return ['status' => false, 'message' => 'فشل قراءة بيانات الترخيص الداخلية.'];
        }

        // 1. مطابقة معرف الجهاز (Machine ID)
        $currentMachineID = self::generateMachineID();
        if ($payload['machine_id'] !== $currentMachineID) {
            return ['status' => false, 'message' => 'عذراً، هذا الترخيص مخصص لجهاز كمبيوتر آخر وغير صالح لهذا الجهاز.'];
        }

        // 2. التحقق من انتهاء الصلاحية
        if ($payload['license_type'] !== 'lifetime') {
            $today = strtotime(date('Y-m-d'));
            $expiry = strtotime($payload['expiry_date']);
            if ($today > $expiry) {
                return ['status' => false, 'message' => 'لقد انتهت صلاحية الترخيص الممنوح لك بتاريخ: ' . $payload['expiry_date']];
            }
        }

        return ['status' => true, 'data' => $payload];
    }

    /**
     * تشغيل الأوامر لاستخراج معلومات النظام
     */
    private static function getSystemHWInfo($command) {
        $output = [];
        @exec($command, $output);
        if (is_array($output)) {
            $clean = array_filter(array_map('trim', $output));
            $clean = array_values($clean);
            if (count($clean) >= 2) {
                return $clean[1];
            }
        }
        return '';
    }
}
?>
