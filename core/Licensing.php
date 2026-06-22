<?php
namespace AQNEX\Core;

class Licensing {
    private $licensePath;
    
    // المفتاح العام للشركة مدمج مباشرة في الكود لضمان الأمان وعدم ظهوره كملف خارجي
    private $publicKey = "-----BEGIN PUBLIC KEY-----\n" .
        "MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAwqaEP+b3Z4tfPiMHsqHV\n" .
        "WbhrCOC5Tea4R2CbB6hf35oZrd4GaM7mP9+m0pmqgVfCU1lYhhOYAniCF7Okzumv\n" .
        "KW+qK2kpVGdtlHYHZsDV1BuZK2mXWmmm6246YIoYxUaXCuqeqdAcFNMUCc2XG0oS\n" .
        "NFHHfDK5OeCtoJcVHhv7RtWnLyhv7FjgUr4JUgxDby5VyBsdRack26MW1mFNZCj8\n" .
        "o6sUzmQsMd4G7+INI5NBHcHf0aSOJRt1TRPLWwHiq4cW3Lk858vigQSxBwMVqw34\n" .
        "8JRbs/EPUs5pC8YgcX5hXRRl5ancPU9Si7WFRPa3xqV5pHff9bo949XEQZ51pDoO\n" .
        "+QIDAQAB\n" .
        "-----END PUBLIC KEY-----";

    public function __construct() {
        $this->licensePath = __DIR__ . '/../license/license.AQNEX';
    }

    /**
     * توليد Machine ID الفريد للجهاز بالاعتماد على الهاردوير (CPU, Disk, Motherboard)
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
            // محاولة جلب اسم الكمبيوتر وبعض متغيرات البيئة كبديل أخير
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
            return ['status' => false, 'message' => 'ملف الترخيص (license.AQNEX) غير موجود في مجلد الترخيص.'];
        }

        $licenseContent = file_get_contents($this->licensePath);
        $licenseData = json_decode($licenseContent, true);

        if (!$licenseData || !isset($licenseData['payload']) || !isset($licenseData['signature'])) {
            return ['status' => false, 'message' => 'ملف الترخيص تالف أو غير صالح البنية.'];
        }

        $pubKey = (!empty($licenseData['public_key'])) ? $licenseData['public_key'] : $this->publicKey;
        
        // التحقق من التوقيع الرقمي (Signature) باستخدام المفتاح العام لـ AQNEX
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
     * التحقق من تفعيل موديول معين للمستخدم
     */
    public function isModuleEnabled($moduleName) {
        $check = $this->verifyLicense();
        if (!$check['status']) {
            return false;
        }

        $enabledModules = array_map('trim', explode(',', $check['data']['modules_enabled'] ?? ''));
        return in_array($moduleName, $enabledModules);
    }

    /**
     * تشغيل الأوامر لاستخراج معلومات النظام
     */
    private static function getSystemHWInfo($command) {
        $output = [];
        @exec($command, $output);
        if (is_array($output)) {
            // تنظيف الأسطر الفارغة وإرجاع السطر الثاني الفعلي
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
