<?php
namespace AQNEX\Repositories;

class SettingsRepository
{
    private static function defaultSettings(): array
    {
        return [
            'id' => 1,
            'store_name' => 'اسم المنشأة',
            'phone' => '777777777',
            'address' => 'اليمن - عدن',
            'currency' => 'ريال يمني',
            'barcode_scanner' => 1,
            'printer_type' => 'receipt_80mm',
            'tax_percent' => 0,
            'low_stock_threshold' => 5,
            'receipt_footer' => 'شكرًا لزيارتكم!',
            'logo' => null,
            'is_configured' => 0,
            'cashier_permissions' => 'sales,customers,receipts',
            'inventory_permissions' => 'products,categories,purchases,suppliers',
        ];
    }

    public static function ensureDefaults(\mysqli $conn): void
    {
        $result = $conn->query("SHOW TABLES LIKE 'settings'");
        if (!$result || $result->num_rows === 0) {
            $conn->query("CREATE TABLE `settings` (
              `id` int(11) NOT NULL PRIMARY KEY,
              `store_name` varchar(100) NOT NULL,
              `phone` varchar(50) DEFAULT NULL,
              `address` text DEFAULT NULL,
              `commercial_register` varchar(100) DEFAULT NULL,
              `tax_number` varchar(100) DEFAULT NULL,
              `currency` varchar(20) DEFAULT 'ريال يمني',
              `barcode_scanner` tinyint(1) DEFAULT 1,
              `printer_type` varchar(50) DEFAULT 'receipt_80mm',
              `tax_percent` double DEFAULT 0,
              `low_stock_threshold` int(11) DEFAULT 5,
              `receipt_footer` text DEFAULT NULL,
              `logo` varchar(255) DEFAULT NULL,
              `cashier_permissions` text DEFAULT NULL,
              `inventory_permissions` text DEFAULT NULL,
              `is_configured` tinyint(1) NOT NULL DEFAULT 0
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } else {
            $columns = ['commercial_register', 'tax_number', 'logo', 'cashier_permissions', 'inventory_permissions', 'is_configured'];
            foreach ($columns as $column) {
                $check = $conn->query("SHOW COLUMNS FROM `settings` LIKE '$column'");
                if ($check && $check->num_rows === 0) {
                    if ($column === 'commercial_register') {
                        $conn->query("ALTER TABLE `settings` ADD COLUMN `commercial_register` varchar(100) DEFAULT NULL AFTER `address`");
                    } elseif ($column === 'tax_number') {
                        $conn->query("ALTER TABLE `settings` ADD COLUMN `tax_number` varchar(100) DEFAULT NULL AFTER `commercial_register`");
                    } elseif ($column === 'logo') {
                        $conn->query("ALTER TABLE `settings` ADD COLUMN `logo` varchar(255) DEFAULT NULL AFTER `receipt_footer`");
                    } elseif ($column === 'cashier_permissions') {
                        $conn->query("ALTER TABLE `settings` ADD COLUMN `cashier_permissions` text DEFAULT NULL AFTER `logo`");
                    } elseif ($column === 'inventory_permissions') {
                        $conn->query("ALTER TABLE `settings` ADD COLUMN `inventory_permissions` text DEFAULT NULL AFTER `cashier_permissions`");
                    } elseif ($column === 'is_configured') {
                        $conn->query("ALTER TABLE `settings` ADD COLUMN `is_configured` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'تحديد إذا ما تم تشغيل معالج الإعداد الأول'");
                    }
                }
            }
        }

        $row = $conn->query("SELECT id FROM settings WHERE id = 1 LIMIT 1");
        if (!$row || $row->num_rows === 0) {
            $default = self::defaultSettings();
            $stmt = $conn->prepare("INSERT INTO settings (`id`, `store_name`, `phone`, `address`, `currency`, `barcode_scanner`, `printer_type`, `tax_percent`, `low_stock_threshold`, `receipt_footer`, `logo`, `cashier_permissions`, `inventory_permissions`, `is_configured`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            if ($stmt) {
                $stmt->bind_param(
                    'isssisdidsissi',
                    $default['id'],
                    $default['store_name'],
                    $default['phone'],
                    $default['address'],
                    $default['currency'],
                    $default['barcode_scanner'],
                    $default['printer_type'],
                    $default['tax_percent'],
                    $default['low_stock_threshold'],
                    $default['receipt_footer'],
                    $default['logo'],
                    $default['cashier_permissions'],
                    $default['inventory_permissions'],
                    $default['is_configured']
                );
                $stmt->execute();
                $stmt->close();
            }
        }
    }

    public static function getSettings(\mysqli $conn): array
    {
        self::ensureDefaults($conn);
        $result = $conn->query("SELECT * FROM settings WHERE id = 1 LIMIT 1");
        return ($result && $result->num_rows > 0) ? $result->fetch_assoc() : [];
    }
}
