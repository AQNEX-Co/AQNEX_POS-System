<?php
namespace AQNEX\Services\Accounting;

/**
 * AccountTreeService
 * Handles hierarchical Chart of Accounts rendering and balance aggregation.
 * Balances flow from leaf accounts → parent nodes → root.
 */
class AccountTreeService
{
    /**
     * Fetch all accounts and build a nested tree structure.
     * Each node contains: id, code, name, account_type, is_parent, level,
     * is_reconcilable, is_active, notes, children[], debit, credit, balance.
     */
    public static function buildTree(\mysqli $conn, bool $activeOnly = false): array
    {
        $where = $activeOnly ? "WHERE a.is_active = 1" : "";
        $sql = "
            SELECT
                a.id, a.code, a.name, a.account_type, a.is_parent,
                a.level, a.is_reconcilable, a.is_active, a.notes, a.parent_id,
                COALESCE(SUM(ji.debit),  0) AS total_debit,
                COALESCE(SUM(ji.credit), 0) AS total_credit
            FROM accounting_accounts a
            LEFT JOIN accounting_journal_items ji ON ji.account_id = a.id
            LEFT JOIN accounting_journal_entries je ON je.id = ji.entry_id AND je.status = 'posted'
            $where
            GROUP BY a.id
            ORDER BY a.code ASC
        ";

        $res = $conn->query($sql);
        if (!$res) return [];

        $flat    = [];
        $byParent = [];

        while ($row = $res->fetch_assoc()) {
            $row['total_debit']  = (float)$row['total_debit'];
            $row['total_credit'] = (float)$row['total_credit'];
            $row['balance']      = self::computeBalance($row);
            $row['children']     = [];

            $flat[$row['id']]                          = $row;
            $byParent[(int)($row['parent_id'] ?? 0)][] = $row['id'];
        }

        // Bubble balances up through parent nodes
        self::aggregateBalances($flat, $byParent, 0);

        // Build tree
        return self::buildNodes($flat, $byParent, 0);
    }

    /**
     * Get a flat list of ALL accounts (for dropdowns).
     * Only returns ledger (non-parent) accounts that are active.
     */
    public static function getLedgerAccounts(\mysqli $conn): array
    {
        // Try with is_active filter first, fallback without it if column is missing
        $sql = "SELECT id, code, name, account_type,
                       COALESCE(is_reconcilable, 1) AS is_reconcilable
                FROM accounting_accounts
                WHERE is_parent = 0
                ORDER BY code ASC";
        $res = $conn->query($sql);
        // Fallback: if is_reconcilable column missing too
        if (!$res) {
            $sql2 = "SELECT id, code, name, account_type, 1 AS is_reconcilable FROM accounting_accounts WHERE is_parent = 0 ORDER BY code ASC";
            $res = $conn->query($sql2);
        }
        $list = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $list[] = $row;
            }
        }
        return $list;
    }

    /**
     * Get all accounts (parent + leaf) for a full dropdown tree.
     */
    public static function getAllAccounts(\mysqli $conn): array
    {
        $sql = "SELECT id, code, name, account_type, is_parent, level
                FROM accounting_accounts
                WHERE is_active = 1
                ORDER BY code ASC";
        $res = $conn->query($sql);
        $list = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $list[] = $row;
            }
        }
        return $list;
    }

    /**
     * Compute Trial Balance: flat list of all leaf accounts with totals.
     * Returns: [ account_row + debit_total + credit_total + balance ]
     */
    public static function getTrialBalance(\mysqli $conn, ?string $fromDate = null, ?string $toDate = null): array
    {
        $dateFilter = '';
        if ($fromDate) $dateFilter .= " AND je.entry_date >= '" . $conn->real_escape_string($fromDate) . "'";
        if ($toDate)   $dateFilter .= " AND je.entry_date <= '" . $conn->real_escape_string($toDate) . "'";

        $sql = "
            SELECT
                a.id, a.code, a.name, a.account_type, a.level,
                COALESCE(SUM(ji.debit),  0) AS total_debit,
                COALESCE(SUM(ji.credit), 0) AS total_credit
            FROM accounting_accounts a
            LEFT JOIN accounting_journal_items ji ON ji.account_id = a.id
            LEFT JOIN accounting_journal_entries je ON je.id = ji.entry_id AND je.status = 'posted' $dateFilter
            WHERE a.is_parent = 0 AND a.is_active = 1
            GROUP BY a.id
            HAVING total_debit > 0 OR total_credit > 0
            ORDER BY a.code ASC
        ";

        $res = $conn->query($sql);
        $rows = [];
        if (!$res) {
            // Tables may not exist yet — return empty safely
            return [];
        }
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $row['total_debit']  = (float)$row['total_debit'];
                $row['total_credit'] = (float)$row['total_credit'];
                $row['balance']      = self::computeBalance($row);
                $rows[] = $row;
            }
        }
        return $rows;
    }

    /**
     * Get movements for a specific account (General Ledger).
     */
    public static function getAccountMovements(
        \mysqli $conn,
        int $accountId,
        ?string $fromDate = null,
        ?string $toDate = null
    ): array {
        $dateFilter = '';
        if ($fromDate) $dateFilter .= " AND je.entry_date >= '" . $conn->real_escape_string($fromDate) . "'";
        if ($toDate)   $dateFilter .= " AND je.entry_date <= '" . $conn->real_escape_string($toDate) . "'";

        $sql = "
            SELECT
                je.id AS entry_id,
                je.entry_date,
                je.reference_no,
                je.description AS entry_desc,
                je.source_type,
                ji.debit,
                ji.credit,
                ji.memo,
                je.created_by
            FROM accounting_journal_items ji
            JOIN accounting_journal_entries je ON je.id = ji.entry_id
            WHERE ji.account_id = $accountId
              AND je.status = 'posted'
              $dateFilter
            ORDER BY je.entry_date ASC, je.id ASC
        ";

        $res = $conn->query($sql);
        $rows = [];
        $running = 0.0;

        if ($res) {
            // Get account type to know normal balance direction
            $accRes = $conn->query("SELECT account_type FROM accounting_accounts WHERE id = $accountId LIMIT 1");
            $accRow = $accRes ? $accRes->fetch_assoc() : null;
            $isDebitNormal = in_array($accRow['account_type'] ?? 'asset', ['asset', 'expense']);

            while ($row = $res->fetch_assoc()) {
                $row['debit']  = (float)$row['debit'];
                $row['credit'] = (float)$row['credit'];
                if ($isDebitNormal) {
                    $running += $row['debit'] - $row['credit'];
                } else {
                    $running += $row['credit'] - $row['debit'];
                }
                $row['running_balance'] = $running;
                $rows[] = $row;
            }
        }
        return $rows;
    }

    // ------------------------------------------------------------------
    // Private helpers
    // ------------------------------------------------------------------

    private static function computeBalance(array $row): float
    {
        $debit  = (float)$row['total_debit'];
        $credit = (float)$row['total_credit'];
        if (in_array($row['account_type'], ['asset', 'expense'])) {
            return $debit - $credit;
        }
        return $credit - $debit;
    }

    private static function aggregateBalances(array &$flat, array $byParent, int $parentId): float
    {
        $sum = 0.0;
        foreach (($byParent[$parentId] ?? []) as $childId) {
            if (!isset($flat[$childId])) continue;
            if ($flat[$childId]['is_parent']) {
                $childBalance = self::aggregateBalances($flat, $byParent, $childId);
                $flat[$childId]['balance'] = $childBalance;
                $sum += $childBalance;
            } else {
                $sum += $flat[$childId]['balance'];
            }
        }
        return $sum;
    }

    private static function buildNodes(array &$flat, array $byParent, int $parentId): array
    {
        $nodes = [];
        foreach (($byParent[$parentId] ?? []) as $childId) {
            if (!isset($flat[$childId])) continue;
            $node = $flat[$childId];
            if (!empty($byParent[$childId])) {
                $node['children'] = self::buildNodes($flat, $byParent, $childId);
            }
            $nodes[] = $node;
        }
        return $nodes;
    }
}
