<?php
namespace AQNEX\Printing;

class PrinterManager {
    /**
     * إرسال أمر الطباعة إلى الطابعة المحددة
     * @param array $settings إعدادات الطابعة من قاعدة البيانات
     * @param string $payload البيانات المراد طباعتها (بايتات ESC/POS أو ZPL)
     * @return array [success => bool, message => string]
     */
    public static function sendJob(array $settings, string $payload): array {
        $connType = $settings['connection_type'] ?? 'usb';
        $ip = $settings['ip_address'] ?? '';
        $port = intval($settings['port'] ?? 9100);

        if ($connType === 'network') {
            if (empty($ip)) {
                return ['success' => false, 'message' => 'عنوان IP الخاص بالطابعة فارغ.'];
            }
            
            // فتح اتصال سوكيت مباشر بالطابعة على منفذ 9100
            $fp = @fsockopen($ip, $port, $errno, $errstr, 3); // المهلة 3 ثوانٍ
            if (!$fp) {
                return ['success' => false, 'message' => "فشل الاتصال بالطابعة الشبكية ($ip:$port): $errstr (كود: $errno)"];
            }
            
            @fwrite($fp, $payload);
            @fclose($fp);
            return ['success' => true, 'message' => 'تم إرسال أمر الطباعة بنجاح للطابعة الشبكية.'];
        } 
        else if ($connType === 'usb') {
            if (empty($ip)) {
                return ['success' => false, 'message' => 'مسار الطابعة المشتركة بنظام ويندوز فارغ.'];
            }
            
            // فتح اتصال للمسار المشترك لويندوز (مثال: //localhost/ThermalPrinter)
            // نستخدم وضع الكتابة الثنائية wb
            $fp = @fopen($ip, 'wb');
            if (!$fp) {
                return ['success' => false, 'message' => "فشل الكتابة إلى منفذ الطابعة المشترك ($ip). تأكد من مشاركة الطابعة بشكل صحيح ومن أن التسمية مطابقة."];
            }
            
            @fwrite($fp, $payload);
            @fclose($fp);
            return ['success' => true, 'message' => 'تم إرسال أمر الطباعة بنجاح للطابعة المحلية المشتركة.'];
        }

        return ['success' => false, 'message' => 'طريقة اتصال غير مدعومة حالياً.'];
    }
}
