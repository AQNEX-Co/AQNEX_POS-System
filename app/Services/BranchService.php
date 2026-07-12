<?php
declare(strict_types=1);

namespace AQNEX\Services;

use AQNEX\Config\Database;

class BranchService
{
    private static function startSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    /**
     * Gets the active branch ID from session, falls back to user default branch or first database branch.
     */
    public static function getCurrentBranchId(): int
    {
        self::startSession();
        if (isset($_SESSION['active_branch_id']) && (int)$_SESSION['active_branch_id'] > 0) {
            return (int)$_SESSION['active_branch_id'];
        }

        $userId = isset($_SESSION['SESS_MEMBER_ID']) ? (int)$_SESSION['SESS_MEMBER_ID'] : 0;
        $pdo = Database::createPdo();

        if ($userId > 0 && $pdo) {
            try {
                $stmt = $pdo->prepare("SELECT branch_id FROM users WHERE userid = :userid LIMIT 1");
                $stmt->execute([':userid' => $userId]);
                $branchId = (int)$stmt->fetchColumn();
                if ($branchId > 0) {
                    $_SESSION['active_branch_id'] = $branchId;
                    return $branchId;
                }
            } catch (\PDOException $e) {
                // Fallback
            }
        }

        // Fallback to the first branch in the database
        if ($pdo) {
            try {
                $branchId = (int)$pdo->query("SELECT id FROM branches ORDER BY id ASC LIMIT 1")->fetchColumn();
                if ($branchId > 0) {
                    $_SESSION['active_branch_id'] = $branchId;
                    return $branchId;
                }
            } catch (\PDOException $e) {
                // Fallback
            }
        }

        return 1;
    }

    /**
     * Gets the active warehouse ID from session, falls back to user default warehouse or primary branch warehouse.
     */
    public static function getCurrentWarehouseId(): int
    {
        self::startSession();
        if (isset($_SESSION['active_warehouse_id']) && (int)$_SESSION['active_warehouse_id'] > 0) {
            return (int)$_SESSION['active_warehouse_id'];
        }

        $userId = isset($_SESSION['SESS_MEMBER_ID']) ? (int)$_SESSION['SESS_MEMBER_ID'] : 0;
        $pdo = Database::createPdo();

        if ($userId > 0 && $pdo) {
            try {
                $stmt = $pdo->prepare("SELECT warehouse_id FROM users WHERE userid = :userid LIMIT 1");
                $stmt->execute([':userid' => $userId]);
                $warehouseId = (int)$stmt->fetchColumn();
                if ($warehouseId > 0) {
                    $_SESSION['active_warehouse_id'] = $warehouseId;
                    return $warehouseId;
                }
            } catch (\PDOException $e) {
                // Fallback
            }
        }

        // Fallback to the first warehouse of the current branch
        $branchId = self::getCurrentBranchId();
        if ($pdo) {
            try {
                $stmt = $pdo->prepare("SELECT id FROM warehouses WHERE branch_id = :branch_id ORDER BY id ASC LIMIT 1");
                $stmt->execute([':branch_id' => $branchId]);
                $warehouseId = (int)$stmt->fetchColumn();
                if ($warehouseId > 0) {
                    $_SESSION['active_warehouse_id'] = $warehouseId;
                    return $warehouseId;
                }
            } catch (\PDOException $e) {
                // Fallback
            }
        }

        return 1;
    }

    /**
     * Set the current active branch ID, and switch the warehouse to the branch's primary warehouse.
     */
    public static function setCurrentBranchId(int $branchId): void
    {
        self::startSession();
        $_SESSION['active_branch_id'] = $branchId;

        // Auto-switch active warehouse to the primary warehouse of the new branch
        $pdo = Database::createPdo();
        if ($pdo) {
            try {
                $stmt = $pdo->prepare("SELECT id FROM warehouses WHERE branch_id = :branch_id ORDER BY id ASC LIMIT 1");
                $stmt->execute([':branch_id' => $branchId]);
                $warehouseId = (int)$stmt->fetchColumn();
                if ($warehouseId > 0) {
                    $_SESSION['active_warehouse_id'] = $warehouseId;
                } else {
                    unset($_SESSION['active_warehouse_id']);
                }
            } catch (\PDOException $e) {
                unset($_SESSION['active_warehouse_id']);
            }
        }
    }

    /**
     * Set the current active warehouse ID.
     */
    public static function setCurrentWarehouseId(int $warehouseId): void
    {
        self::startSession();
        $_SESSION['active_warehouse_id'] = $warehouseId;
    }

    /**
     * Lists all branches.
     */
    public static function getAvailableBranches(): array
    {
        $pdo = Database::createPdo();
        if (!$pdo) {
            return [];
        }
        try {
            return $pdo->query("SELECT * FROM branches ORDER BY id ASC")->fetchAll();
        } catch (\PDOException $e) {
            return [];
        }
    }

    /**
     * Lists warehouses for a branch.
     */
    public static function getWarehousesForBranch(int $branchId): array
    {
        $pdo = Database::createPdo();
        if (!$pdo) {
            return [];
        }
        try {
            $stmt = $pdo->prepare("SELECT * FROM warehouses WHERE branch_id = :branch_id ORDER BY id ASC");
            $stmt->execute([':branch_id' => $branchId]);
            return $stmt->fetchAll();
        } catch (\PDOException $e) {
            return [];
        }
    }

    /**
     * Gets the active branch name.
     */
    public static function getCurrentBranchName(): string
    {
        $branchId = self::getCurrentBranchId();
        $pdo = Database::createPdo();
        if ($pdo) {
            try {
                $stmt = $pdo->prepare("SELECT name FROM branches WHERE id = :id LIMIT 1");
                $stmt->execute([':id' => $branchId]);
                $name = $stmt->fetchColumn();
                if ($name) {
                    return (string)$name;
                }
            } catch (\PDOException $e) {
                // Fallback
            }
        }
        return 'غير محدد';
    }

    /**
     * Gets the active warehouse name.
     */
    public static function getCurrentWarehouseName(): string
    {
        $warehouseId = self::getCurrentWarehouseId();
        $pdo = Database::createPdo();
        if ($pdo) {
            try {
                $stmt = $pdo->prepare("SELECT name FROM warehouses WHERE id = :id LIMIT 1");
                $stmt->execute([':id' => $warehouseId]);
                $name = $stmt->fetchColumn();
                if ($name) {
                    return (string)$name;
                }
            } catch (\PDOException $e) {
                // Fallback
            }
        }
        return 'غير محدد';
    }
}
