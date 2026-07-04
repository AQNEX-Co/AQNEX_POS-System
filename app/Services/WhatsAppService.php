<?php
namespace AQNEX\Services;

class WhatsAppService
{
    /**
     * إرسال رسالة واتساب للعملاء أو الإدارة تلقائياً باستخدام إعدادات النظام
     * 
     * @param array $settings إعدادات النظام العامة التي تحتوي على توكن واتساب ورقم المثيل
     * @param string $to رقم الهاتف المستلم مع رمز الدولة
     * @param string $message نص الرسالة المراد إرسالها
     * @return bool
     */
    public static function sendNotification(array $settings, string $to, string $message): bool
    {
        // التحقق من تفعيل ميزة الواتساب في لوحة التحكم
        if (!isset($settings['whatsapp_enabled']) || intval($settings['whatsapp_enabled']) !== 1) {
            return false;
        }

        $token = $settings['whatsapp_token'] ?? '';
        $instanceId = $settings['whatsapp_instance'] ?? '';

        // التحقق من توفر بيانات الاتصال بالبوابة
        if (empty($token) || empty($instanceId)) {
            return false;
        }

        // تنظيف رقم الهاتف من الفراغات أو الرموز
        $to = preg_replace('/[^0-9]/', '', $to);
        if (empty($to)) {
            return false;
        }

        $params = array(
            'token' => $token,
            'to' => $to,
            'body' => $message
        );

        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => "https://api.ultramsg.com/{$instanceId}/messages/chat",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => "POST",
            CURLOPT_POSTFIELDS => http_build_query($params),
            CURLOPT_HTTPHEADER => array(
                "content-type: application/x-www-form-urlencoded"
            ),
            CURLOPT_TIMEOUT => 8
        ));

        $response = curl_exec($curl);
        $err = curl_error($curl);
        curl_close($curl);

        if ($err) {
            return false;
        }

        $resDecoded = json_decode($response, true);
        return isset($resDecoded['sent']) && $resDecoded['sent'] == 'true';
    }
}
