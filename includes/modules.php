<?php
/**
 * نظام إدارة الموديولات البرمجية (Module Manager)
 * يتحكم في تشغيل وتعطيل الميزات قطاعياً لتخصيص النظام حسب نوع المتجر.
 */

if (!function_exists('is_module_enabled')) {
    function is_module_enabled(string $key): bool {
        global $conn;
        static $cache = [];

        if (isset($cache[$key])) {
            return $cache[$key];
        }

        // إذا لم تكن قاعدة البيانات مهيأة بعد أو الاتصال مفقود
        if (!isset($conn) || !$conn || $conn->connect_error) {
            return false;
        }

        // استخدام Prepared Statements لتأمين الحقول
        $stmt = $conn->prepare("SELECT is_enabled FROM system_modules WHERE module_key = ? LIMIT 1");
        if (!$stmt) {
            return false;
        }

        $stmt->bind_param("s", $key);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $cache[$key] = $res ? (intval($res['is_enabled']) === 1) : false;
        return $cache[$key];
    }
}
?>
