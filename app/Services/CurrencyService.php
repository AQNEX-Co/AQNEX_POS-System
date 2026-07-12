<?php
declare(strict_types=1);

namespace AQNEX\Services;

use AQNEX\Config\Database;

class CurrencyService
{
    private static ?array $currencies = null;

    /**
     * Loads currencies from the database currencies table.
     */
    private static function loadCurrencies(\PDO $pdo): void
    {
        if (self::$currencies !== null) {
            return;
        }
        $stmt = $pdo->query("SELECT code, exchange_rate, is_base FROM currencies");
        $rows = $stmt->fetchAll();
        self::$currencies = [];
        foreach ($rows as $row) {
            self::$currencies[$row['code']] = [
                'rate' => (float) $row['exchange_rate'],
                'is_base' => (bool) $row['is_base']
            ];
        }
    }

    /**
     * Converts an amount from one currency to another on-the-fly.
     * All monetary fields are DECIMAL(15,4).
     */
    public static function convert(float $amount, string $fromCurrency, string $toCurrency): float
    {
        $pdo = Database::createPdo();
        if (!$pdo) {
            throw new \RuntimeException("Failed to obtain database connection.");
        }

        self::loadCurrencies($pdo);

        if (!isset(self::$currencies[$fromCurrency])) {
            throw new \InvalidArgumentException("Source currency '{$fromCurrency}' is not defined in the system.");
        }
        if (!isset(self::$currencies[$toCurrency])) {
            throw new \InvalidArgumentException("Destination currency '{$toCurrency}' is not defined in the system.");
        }

        $fromRate = self::$currencies[$fromCurrency]['rate'];
        $toRate = self::$currencies[$toCurrency]['rate'];

        if ($fromRate <= 0.0 || $toRate <= 0.0) {
            throw new \RuntimeException("Invalid exchange rate configured for currency conversion.");
        }

        // Formula: (amount * fromRate) / toRate
        $amountInBase = $amount * $fromRate;
        $convertedAmount = $amountInBase / $toRate;

        return round($convertedAmount, 4);
    }

    /**
     * Clear loaded currencies cache.
     */
    public static function refresh(): void
    {
        self::$currencies = null;
    }
}
