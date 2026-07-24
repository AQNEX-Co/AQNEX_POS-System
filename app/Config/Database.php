<?php
namespace AQNEX\Config;

class Database
{
    private static array $config = [];
    private static ?\PDO $pdoInstance = null;

    public static function loadConfig(array $config): void
    {
        self::$config = $config['db'] ?? [];
    }

    /**
     * يُنشئ قاعدة البيانات تلقائياً إذا لم تكن موجودة، ثم يستورد مخطط SQL.
     */
    private static function ensureDatabaseExists(string $host, int $port, string $user, string $pass, string $name, string $charset): void
    {
        // اتصال بدون تحديد قاعدة بيانات للتحقق من وجودها
        $tmpConn = @new \mysqli($host, $user, $pass, '', $port);
        if ($tmpConn->connect_error) {
            return; // لا يمكن الاتصال بالخادم
        }

        // التحقق من وجود قاعدة البيانات
        $dbCheck = $tmpConn->query("SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '" . $tmpConn->real_escape_string($name) . "'");
        $dbExists = ($dbCheck && $dbCheck->num_rows > 0);

        // =====================================================================
        // الخطوة 1: إنشاء قاعدة البيانات إن لم تكن موجودة + استيراد الهيكل الأساسي
        // =====================================================================
        $needsFullImport = !$dbExists;
        if ($dbExists) {
            // إذا كانت قاعدة البيانات موجودة لكن جدول system_licensing مفقود → استيراد كامل
            $tableCheck = $tmpConn->query("SELECT 1 FROM information_schema.TABLES WHERE table_schema = '" . $tmpConn->real_escape_string($name) . "' AND table_name = 'system_licensing'");
            if (!$tableCheck || $tableCheck->num_rows === 0) {
                $needsFullImport = true;
            }
        }

        if ($needsFullImport) {
            if (!$dbExists) {
                // إنشاء قاعدة البيانات
                $tmpConn->query("CREATE DATABASE IF NOT EXISTS `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
            }

            // استيراد ملف SQL الاحتياطي (الهيكل الكامل)
            $sqlFile = defined('APP_ROOT') ? APP_ROOT . '/DB/backup/aqnex_pos.sql' : dirname(__DIR__, 3) . '/DB/backup/aqnex_pos.sql';
            if (file_exists($sqlFile)) {
                $tmpConn->select_db($name);
                $tmpConn->set_charset($charset);
                $sqlContent = file_get_contents($sqlFile);
                if ($sqlContent !== false) {
                    $tmpConn->query("SET FOREIGN_KEY_CHECKS = 0");
                    $currentQuery = '';
                    foreach (explode("\n", $sqlContent) as $line) {
                        $trimmed = trim($line);
                        if (empty($trimmed) || strpos($trimmed, '--') === 0 || strpos($trimmed, '/*') === 0 || strpos($trimmed, '#') === 0) {
                            continue;
                        }
                        $currentQuery .= $line . "\n";
                        if (substr($trimmed, -1) === ';') {
                            $tmpConn->query(trim($currentQuery));
                            $currentQuery = '';
                        }
                    }
                    $tmpConn->query("SET FOREIGN_KEY_CHECKS = 1");
                }
            }
        }

        // =====================================================================
        // الخطوة 2: تشغيل الـ Migrations دائماً (سواء قاعدة بيانات جديدة أو قديمة)
        // ملفات الـ Migration تستخدم CREATE TABLE IF NOT EXISTS و ALTER TABLE
        // لذا آمنة التشغيل المتكرر — هذا يضمن إنشاء الجداول الجديدة على الأنظمة القديمة
        // =====================================================================
        $migrationsDir = defined('APP_ROOT') ? APP_ROOT . '/DB/migrations' : dirname(__DIR__, 3) . '/DB/migrations';
        if (is_dir($migrationsDir)) {
            $tmpConn->select_db($name);
            $tmpConn->set_charset($charset);

            $files = scandir($migrationsDir);
            $sqlFiles = [];
            foreach ($files as $file) {
                if (pathinfo($file, PATHINFO_EXTENSION) === 'sql') {
                    $sqlFiles[] = $file;
                }
            }
            sort($sqlFiles);

            foreach ($sqlFiles as $sqlFile) {
                $fullPath = $migrationsDir . '/' . $sqlFile;
                $migrationContent = @file_get_contents($fullPath);
                if ($migrationContent !== false) {
                    $tmpConn->query("SET FOREIGN_KEY_CHECKS = 0");
                    $currentQuery = '';
                    foreach (explode("\n", $migrationContent) as $line) {
                        $trimmed = trim($line);
                        if (empty($trimmed) || strpos($trimmed, '--') === 0 || strpos($trimmed, '/*') === 0 || strpos($trimmed, '#') === 0) {
                            continue;
                        }
                        $currentQuery .= $line . "\n";
                        if (substr($trimmed, -1) === ';') {
                            @$tmpConn->query(trim($currentQuery));
                            $currentQuery = '';
                        }
                    }
                    $tmpConn->query("SET FOREIGN_KEY_CHECKS = 1");
                }
            }
        }

        $tmpConn->close();
    }

    public static function createMysqli(): ?\mysqli
    {
        $host = self::$config['host'] ?? 'localhost';
        $port = self::$config['port'] ?? 3306;
        $name = self::$config['name'] ?? '';
        $user = self::$config['user'] ?? '';
        $pass = self::$config['pass'] ?? '';
        $charset = self::$config['charset'] ?? 'utf8mb4';

        mysqli_report(MYSQLI_REPORT_OFF);

        // إنشاء قاعدة البيانات تلقائياً عند أول تشغيل
        self::ensureDatabaseExists($host, $port, $user, $pass, $name, $charset);

        $connection = new \mysqli($host, $user, $pass, $name, $port);
        if ($connection->connect_error) {
            return null;
        }

        $connection->set_charset($charset);
        return $connection;
    }

    public static function createPdo(): ?\PDO
    {
        if (self::$pdoInstance !== null) {
            return self::$pdoInstance;
        }

        $host = self::$config['host'] ?? 'localhost';
        $port = (int)(self::$config['port'] ?? 3306);
        $name = self::$config['name'] ?? '';
        $user = self::$config['user'] ?? '';
        $pass = self::$config['pass'] ?? '';
        $charset = self::$config['charset'] ?? 'utf8mb4';

        self::ensureDatabaseExists($host, $port, $user, $pass, $name, $charset);

        try {
            $dsn = "mysql:host=$host;port=$port;dbname=$name;charset=$charset";
            $options = [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => false,
            ];
            self::$pdoInstance = new \PDO($dsn, $user, $pass, $options);
            return self::$pdoInstance;
        } catch (\PDOException $e) {
            return null;
        }
    }
}
