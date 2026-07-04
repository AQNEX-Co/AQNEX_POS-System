<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once(__DIR__ . '/../app/Services/AuthService.php');

// ======= التحقق من تسجيل الدخول =======
\AQNEX\Services\AuthService::ensureLogin();

// ======= دالة التحقق من الصلاحيات =======
if (!function_exists('check_permission')) {
    function check_permission($allowed_positions = null) {
        global $module, $conn;
        $moduleKey = $module ?? '';
        if (empty($moduleKey)) {
            return;
        }

        \AQNEX\Services\AuthService::checkPermission($moduleKey, $conn);
    }

    function _deny_access() {
        \AQNEX\Services\AuthService::denyAccess();
    }
}
?>
