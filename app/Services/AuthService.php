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

    public static function hasPermission(string $module, ?\mysqli $conn = null): bool
    {
        self::startSession();
        $userId = self::currentUserId();
        if ($userId <= 0) {
            return false;
        }

        if (self::isAdmin()) {
            return true;
        }

        $module = trim($module);
        if ($module === '') {
            return true;
        }

        // Try the new RBAC verification via PDO first
        $pdo = \AQNEX\Config\Database::createPdo();
        if ($pdo) {
            try {
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) 
                    FROM users u
                    JOIN roles r ON u.role_id = r.id
                    JOIN role_permissions rp ON r.id = rp.role_id
                    JOIN permissions p ON rp.permission_id = p.id
                    WHERE u.userid = :user_id AND p.permission_key = :permission_key
                ");
                $stmt->execute([
                    ':user_id' => $userId,
                    ':permission_key' => $module
                ]);
                $count = (int) $stmt->fetchColumn();
                if ($count > 0) {
                    return true;
                }
            } catch (\PDOException $e) {
                // If tables do not exist yet or connection fails, fallback to legacy
            }
        }

        // Fallback for Legacy / Custom permissions
        if ($conn === null) {
            $conn = \AQNEX\Config\Database::createMysqli();
        }
        if (!$conn) {
            return false;
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

    public static function checkPermission(string $module, ?\mysqli $conn = null): void
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

    public static function can(string $permission): bool
    {
        return self::hasPermission($permission);
    }

    public static function redirect(string $url): void
    {
        header('Location: ' . $url);
        exit();
    }
}
