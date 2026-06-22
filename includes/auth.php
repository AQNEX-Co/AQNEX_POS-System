<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ======= التحقق من تسجيل الدخول =======
if (!isset($_SESSION['SESS_MEMBER_ID']) || empty(trim($_SESSION['SESS_MEMBER_ID']))) {
    $prefix = isset($dir_prefix) ? $dir_prefix : '';
    header("Location: " . $prefix . "auth/login.php");
    exit();
}

// ======= دالة التحقق من الصلاحيات =======
if (!function_exists('check_permission')) {
    function check_permission($allowed_positions = null) {
        global $module, $conn;

        $user_role = isset($_SESSION['SESS_LAST_NAME']) ? trim($_SESSION['SESS_LAST_NAME']) : '';
        $user_id   = isset($_SESSION['SESS_MEMBER_ID']) ? intval($_SESSION['SESS_MEMBER_ID']) : 0;

        // 1. المدير = صلاحية كاملة دائماً
        if ($user_role === 'admin') {
            return;
        }

        // 2. الصفحات المتاحة للجميع
        $public_modules = ['dashboard', 'changeuser', ''];
        if (in_array($module ?? '', $public_modules)) {
            return;
        }

        // 3. فحص الصلاحيات الفردية للمستخدم أولاً (تتجاوز الدور)
        if ($user_id > 0 && $conn) {
            $res_user = $conn->query("SELECT custom_permissions FROM users WHERE userid = $user_id LIMIT 1");
            if ($res_user) {
                $user_row = $res_user->fetch_assoc();
                $custom = trim($user_row['custom_permissions'] ?? '');

                if (!empty($custom)) {
                    // لديه صلاحيات فردية — تحقق منها فقط
                    $custom_list = array_map('trim', explode(',', $custom));
                    if (in_array($module ?? '', $custom_list)) {
                        return; // مسموح بالصلاحيات الفردية
                    }
                    // مرفوض بالصلاحيات الفردية
                    _deny_access();
                    return;
                }
            }
        }

        // 4. فحص صلاحيات الدور من الإعدادات العامة
        if ($conn) {
            $settings_res = $conn->query("SELECT cashier_permissions, inventory_permissions FROM settings WHERE id = 1");
            $settings = $settings_res ? $settings_res->fetch_assoc() : null;

            if ($settings) {
                $role_perms = [];
                if ($user_role === 'cashier') {
                    $role_perms = array_map('trim', explode(',', $settings['cashier_permissions'] ?? ''));
                } elseif ($user_role === 'inventory') {
                    $role_perms = array_map('trim', explode(',', $settings['inventory_permissions'] ?? ''));
                }

                if (in_array($module ?? '', $role_perms)) {
                    return; // مسموح بصلاحيات الدور
                }
            }
        }

        // 5. رفض الوصول
        _deny_access();
    }

    function _deny_access() {
        $prefix = isset($GLOBALS['dir_prefix']) ? $GLOBALS['dir_prefix'] : '../';
        echo "<!DOCTYPE html><html dir='rtl'><head><meta charset='UTF-8'></head><body>";
        echo "<script>
            if (confirm('غير مصرح لك بالوصول إلى هذا القسم. هل تريد العودة للرئيسية؟')) {
                window.location.href = '" . $prefix . "home.php';
            } else {
                history.back();
            }
        </script>";
        echo "</body></html>";
        exit();
    }
}
?>
