<?php
declare(strict_types=1);

namespace AQNEX\Services;

use AQNEX\Config\Database;

class ConfigService
{
    private static ?array $settings = null;
    private static ?array $businessRules = null;

    /**
     * Loads settings from the settings table (ID = 1).
     */
    private static function loadSettings(\PDO $pdo): void
    {
        if (self::$settings !== null) {
            return;
        }
        $stmt = $pdo->query("SELECT * FROM settings WHERE id = 1 LIMIT 1");
        $row = $stmt->fetch();
        self::$settings = $row ?: [];
    }

    /**
     * Loads business rules from the business_rules table (ID = 1).
     */
    private static function loadBusinessRules(\PDO $pdo): void
    {
        if (self::$businessRules !== null) {
            return;
        }
        $stmt = $pdo->query("SELECT * FROM business_rules WHERE id = 1 LIMIT 1");
        $row = $stmt->fetch();
        self::$businessRules = $row ?: [];
    }

    /**
     * Returns a setting value by key.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $pdo = Database::createPdo();
        if (!$pdo) {
            return $default;
        }
        self::loadSettings($pdo);
        return self::$settings[$key] ?? $default;
    }

    /**
     * Returns a business rule value by key.
     */
    public static function getBusinessRule(string $key, mixed $default = null): mixed
    {
        $pdo = Database::createPdo();
        if (!$pdo) {
            return $default;
        }
        self::loadBusinessRules($pdo);
        return self::$businessRules[$key] ?? $default;
    }

    /**
     * Refreshes the cached configuration settings.
     */
    public static function refresh(): void
    {
        self::$settings = null;
        self::$businessRules = null;
    }

    /**
     * Updates settings dynamically.
     */
    public static function updateSettings(array $data): bool
    {
        $pdo = Database::createPdo();
        if (!$pdo) {
            return false;
        }

        $sets = [];
        $params = [];
        foreach ($data as $key => $val) {
            $sets[] = "`$key` = :$key";
            $params[":$key"] = $val;
        }

        if (empty($sets)) {
            return true;
        }

        $sql = "UPDATE settings SET " . implode(", ", $sets) . " WHERE id = 1";
        try {
            $stmt = $pdo->prepare($sql);
            $res = $stmt->execute($params);
            self::refresh();
            return $res;
        } catch (\PDOException $e) {
            return false;
        }
    }

    /**
     * Updates business rules dynamically.
     */
    public static function updateBusinessRules(array $data): bool
    {
        $pdo = Database::createPdo();
        if (!$pdo) {
            return false;
        }

        $sets = [];
        $params = [];
        foreach ($data as $key => $val) {
            $sets[] = "`$key` = :$key";
            $params[":$key"] = $val;
        }

        if (empty($sets)) {
            return true;
        }

        $sql = "UPDATE business_rules SET " . implode(", ", $sets) . " WHERE id = 1";
        try {
            $stmt = $pdo->prepare($sql);
            $res = $stmt->execute($params);
            self::refresh();
            return $res;
        } catch (\PDOException $e) {
            return false;
        }
    }

    /**
     * Resets system transaction tables.
     */
    public static function resetSystemData(): bool
    {
        $pdo = Database::createPdo();
        if (!$pdo) {
            return false;
        }

        $tables = [
            'sales_items_history',
            'sales_items',
            'sales_cancellations',
            'sales_returns_history',
            'sales_returns',
            'sales_history',
            'sales',
            'purchase_items_history',
            'purchase_items',
            'purchase_returns_history',
            'purchase_returns',
            'purchases_history',
            'purchases',
            'repair_parts_used',
            'repair_tickets',
            'installment_schedule',
            'installment_plans',
            'inventory_log',
            'journal_entries_history',
            'journal_entries',
            'accounting_journal_history',
            'accounting_journal',
            'expenses_history',
            'expenses',
            'receipts_history',
            'receipts',
            'supplier_payments',
            'treasury_transactions',
            'treasury_expenses_history',
            'treasury_expenses',
            'treasury_closings',
            'notifications',
        ];

        try {
            $pdo->beginTransaction();
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
            foreach ($tables as $table) {
                // Check if table exists
                $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
                if ($stmt && $stmt->rowCount() > 0) {
                    $pdo->exec("TRUNCATE TABLE `$table`");
                }
            }
            $pdo->exec("UPDATE fiscal_years SET is_closed = 0");
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
            $pdo->commit();
            return true;
        } catch (\Exception $e) {
            $pdo->rollBack();
            return false;
        }
    }
}
