<?php
namespace AQNEX\Services;

class AuthService
{
    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    public static function ensureLogin(): void
    {
        self::startSession();
        if (empty($_SESSION['SESS_MEMBER_ID']) || trim((string)$_SESSION['SESS_MEMBER_ID']) === '') {
            self::redirect('auth/login.php');
        }
    }

    public static function currentUserId(): int
    {
        self::startSession();
        return isset($_SESSION['SESS_MEMBER_ID']) ? intval($_SESSION['SESS_MEMBER_ID']) : 0;
    }

    public static function currentUserRole(): string
    {
        self::startSession();
        return isset($_SESSION['SESS_LAST_NAME']) ? trim((string)$_SESSION['SESS_LAST_NAME']) : '';
    }

    public static function isAdmin(): bool
    {
        return self::currentUserRole() === 'admin';
    }

    public static function hasPermission(string $module, \mysqli $conn): bool
    {
        if (self::isAdmin()) {
            return true;
        }

        $userId = self::currentUserId();
        if ($userId <= 0) {
            return false;
        }

        $module = trim($module);
        if ($module === '') {
            return true;
        }

        $result = $conn->query("SELECT custom_permissions FROM users WHERE userid = $userId LIMIT 1");
        if ($result && $row = $result->fetch_assoc()) {
            $custom = trim($row['custom_permissions'] ?? '');
            if ($custom !== '') {
                $customList = array_map('trim', explode(',', $custom));
                return in_array($module, $customList, true);
            }
        }

        $settingsResult = $conn->query("SELECT cashier_permissions, inventory_permissions FROM settings WHERE id = 1 LIMIT 1");
        if ($settingsResult && $settings = $settingsResult->fetch_assoc()) {
            if (self::currentUserRole() === 'cashier') {
                $allowed = array_map('trim', explode(',', $settings['cashier_permissions'] ?? ''));
                return in_array($module, $allowed, true);
            }
            if (self::currentUserRole() === 'inventory') {
                $allowed = array_map('trim', explode(',', $settings['inventory_permissions'] ?? ''));
                return in_array($module, $allowed, true);
            }
        }

        return false;
    }

    public static function checkPermission(string $module, \mysqli $conn): void
    {
        if (!self::hasPermission($module, $conn)) {
            self::denyAccess();
        }
    }

    public static function denyAccess(): void
    {
        $prefix = '';
        if (defined('APP_BASE_PATH')) {
            $prefix = '';
        }
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

    public static function redirect(string $url): void
    {
        header('Location: ' . $url);
        exit();
    }
}
