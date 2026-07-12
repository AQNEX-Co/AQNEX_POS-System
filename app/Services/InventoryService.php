<?php
namespace AQNEX\Services;

use AQNEX\Services\Accounting\JournalService;

/**
 * InventoryService
 * Handles all stock logic, FIFO costing, internal transfers, adjustments, and accounting integrations.
 * Precision: DECIMAL(15,4) for all stock quantities and cost values.
 */
class InventoryService
{
    /**
     * Log inventory movement to audit log and legacy log.
     */
    public static function logInventoryAction(\mysqli $conn, array $logData): bool
    {
        $productId    = intval($logData['product_id']);
        $actionType   = $conn->real_escape_string($logData['action_type']); // 'in'|'out'|'transfer_out'|'transfer_in'|'adjustment'
        $quantity     = round((float)$logData['quantity'], 4);
        $costPrice    = round((float)($logData['cost_price'] ?? 0.0), 4);
        $warehouseId  = intval($logData['warehouse_id']);
        $refTable     = isset($logData['reference_table']) ? $conn->real_escape_string($logData['reference_table']) : 'NULL';
        $refId        = !empty($logData['reference_id']) ? intval($logData['reference_id']) : 'NULL';
        $userId       = $conn->real_escape_string($logData['user_id'] ?? 'system');

        $refTableVal = ($refTable === 'NULL') ? "NULL" : "'$refTable'";

        // 1. Write to modern inventory_audit_log
        $sql = "INSERT INTO `inventory_audit_log` 
                (product_id, action_type, quantity, cost_price, warehouse_id, reference_table, reference_id, user_id) 
                VALUES 
                ($productId, '$actionType', $quantity, $costPrice, $warehouseId, $refTableVal, $refId, '$userId')";
        $res = $conn->query($sql);

        // 2. Write to legacy inventory_log for backward compatibility
        $p_res = $conn->query("SELECT name, quantity FROM products WHERE id = $productId LIMIT 1");
        if ($p_res && $p_row = $p_res->fetch_assoc()) {
            $p_name = $conn->real_escape_string($p_row['name']);
            $new_qty = floatval($p_row['quantity']);
            
            $reason = "حركة مخزنية: $actionType | مستودع: $warehouseId";
            if ($refTable !== 'NULL') {
                $reason .= " | مرجع: $refTable #$refId";
            }
            $reason_esc = $conn->real_escape_string($reason);

            $legacy_type = ($actionType === 'in' || $actionType === 'transfer_in') ? 'purchase' : (($actionType === 'out' || $actionType === 'transfer_out') ? 'sale' : 'adjustment');
            $qty_change = ($actionType === 'in' || $actionType === 'transfer_in') ? $quantity : -$quantity;

            $conn->query("INSERT INTO `inventory_log` (product_id, product_name, type, qty_change, new_qty, reason, user) 
                          VALUES ($productId, '$p_name', '$legacy_type', $qty_change, $new_qty, '$reason_esc', '$userId')");
        }

        return $res;
    }

    /**
     * Stock Inward & Procurement
     * Records new stock arrival, updates quantities, and creates Journal Entry.
     */
    public static function stockIn(\mysqli $conn, array $data): array
    {
        $conn->begin_transaction();
        try {
            $productId   = intval($data['product_id']);
            $qty         = round((float)$data['quantity'], 4);
            $costPrice   = round((float)$data['cost_price'], 4);
            $warehouseId = intval($data['warehouse_id'] ?? 1);
            $userId      = $data['created_by'] ?? 'system';
            
            $batchNo     = trim($data['batch_number'] ?? '');
            $expiryDate  = !empty($data['expiry_date']) ? $conn->real_escape_string($data['expiry_date']) : null;
            $serials     = $data['serials'] ?? []; // Array of IMEI/Serials

            if ($productId <= 0 || $qty <= 0 || $costPrice < 0) {
                throw new \Exception("بيانات التوريد غير صالحة");
            }

            // 1. Verify Product exists
            $res_p = $conn->query("SELECT name, track_expiry FROM products WHERE id = $productId LIMIT 1");
            if (!$res_p || $res_p->num_rows == 0) {
                throw new \Exception("المنتج غير موجود");
            }
            $product = $res_p->fetch_assoc();
            $trackExpiry = (bool)$product['track_expiry'];

            // 2. Add / Update warehouses_stock
            $conn->query("INSERT INTO `warehouses_stock` (warehouse_id, product_id, quantity) 
                          VALUES ($warehouseId, $productId, $qty) 
                          ON DUPLICATE KEY UPDATE quantity = quantity + $qty");

            // 3. Update global product quantity and buy_price
            $conn->query("UPDATE `products` SET quantity = quantity + $qty, buy_price = $costPrice, total = quantity * buy_price WHERE id = $productId");

            // 4. Handle Batches or Serials
            $batchId = null;
            if ($trackExpiry || !empty($batchNo)) {
                // If batch no is empty, generate one
                if (empty($batchNo)) {
                    $batchNo = 'BATCH-' . date('Ymd') . '-' . rand(100, 999);
                }
                $batchNo_esc = $conn->real_escape_string($batchNo);
                $exp_val = ($expiryDate === null) ? "NULL" : "'$expiryDate'";
                
                $conn->query("INSERT INTO `product_batches` (product_id, batch_number, quantity, cost_price, expiry_date) 
                              VALUES ($productId, '$batchNo_esc', $qty, $costPrice, $exp_val)");
                $batchId = $conn->insert_id;
            } else {
                // Telecom mode IMEI/Serials
                if (!empty($serials)) {
                    foreach ($serials as $serial) {
                        $s_no = $conn->real_escape_string($serial['serial_number'] ?? '');
                        $imei1 = $conn->real_escape_string($serial['imei_1'] ?? '');
                        $imei2 = $conn->real_escape_string($serial['imei_2'] ?? '');
                        
                        if (empty($s_no) && empty($imei1)) continue;

                        $conn->query("INSERT INTO `product_serials` (product_id, serial_number, imei_1, imei_2, cost_price, status) 
                                      VALUES ($productId, '$s_no', '$imei1', '$imei2', $costPrice, 'in_stock')");
                    }
                }
            }

            // 5. Accounting Integration: Create Journal Entry
            // Debit: Inventory Asset (1103)
            // Credit: Accounts Payable (2101xxxx) or Treasury Cash (1101xxxx)
            $creditAccId = 0;
            $paymentMethod = $data['payment_method'] ?? 'cash';
            
            if ($paymentMethod === 'cash') {
                $boxId = intval($data['box_id'] ?? 1);
                $code = '1101' . sprintf('%04d', $boxId);
                $res_acc = $conn->query("SELECT id FROM accounting_accounts WHERE code = '$code' LIMIT 1");
                if ($res_acc && $row_acc = $res_acc->fetch_assoc()) {
                    $creditAccId = intval($row_acc['id']);
                }
            } else {
                // Supplier / Credit
                $supplierId = intval($data['supplier_id'] ?? 0);
                if ($supplierId > 0) {
                    $code = '2101' . sprintf('%04d', $supplierId);
                    $res_acc = $conn->query("SELECT id FROM accounting_accounts WHERE code = '$code' LIMIT 1");
                    if ($res_acc && $row_acc = $res_acc->fetch_assoc()) {
                        $creditAccId = intval($row_acc['id']);
                    }
                }
            }

            if ($creditAccId === 0) {
                // Fallback to general accounts payable
                $res_acc = $conn->query("SELECT id FROM accounting_accounts WHERE code = '2101' LIMIT 1");
                if ($res_acc && $row_acc = $res_acc->fetch_assoc()) {
                    $creditAccId = intval($row_acc['id']);
                }
            }

            // Get Inventory Asset Account ID (1103)
            $debitAccId = 0;
            $res_inv_acc = $conn->query("SELECT id FROM accounting_accounts WHERE code = '1103' LIMIT 1");
            if ($res_inv_acc && $row_inv = $res_inv_acc->fetch_assoc()) {
                $debitAccId = intval($row_inv['id']);
            }

            $totalAmount = $qty * $costPrice;

            if ($debitAccId > 0 && $creditAccId > 0 && $totalAmount > 0) {
                $entryDesc = "توريد بضاعة: " . $product['name'] . " | كمية: $qty بسعر تكلفة: $costPrice";
                $postResult = JournalService::postEntry($conn, [
                    'entry_date'   => $data['inward_date'] ?? date('Y-m-d'),
                    'reference_no' => '',
                    'description'  => $entryDesc,
                    'source_type'  => 'purchase',
                    'source_id'    => null,
                    'created_by'   => $userId,
                    'items'        => [
                        [
                            'account_id'    => $debitAccId,
                            'debit'         => $totalAmount,
                            'credit'        => 0,
                            'exchange_rate' => 1.0,
                            'memo'          => $entryDesc
                        ],
                        [
                            'account_id'    => $creditAccId,
                            'debit'         => 0,
                            'credit'        => $totalAmount,
                            'exchange_rate' => 1.0,
                            'memo'          => $entryDesc
                        ]
                    ]
                ]);

                if (!$postResult['success']) {
                    throw new \Exception("فشل ترحيل القيد المحاسبي للتوريد: " . $postResult['error']);
                }
            }

            // 6. Log movement
            self::logInventoryAction($conn, [
                'product_id'      => $productId,
                'action_type'     => 'in',
                'quantity'        => $qty,
                'cost_price'      => $costPrice,
                'warehouse_id'    => $warehouseId,
                'reference_table' => 'products',
                'reference_id'    => $productId,
                'user_id'         => $userId
            ]);

            $conn->commit();
            return ['success' => true, 'message' => 'تم التوريد بنجاح وتسجيل القيد المحاسبي.'];

        } catch (\Exception $e) {
            $conn->rollback();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * FIFO Inventory Costing & Deduction
     * Deducts quantity from oldest available batches and returns total COGS.
     */
    public static function deductStockAndGetCOGS(\mysqli $conn, int $productId, float $qty): float
    {
        // 1. Determine sort order (expiry first if track_expiry is enabled)
        $trackExpiry = false;
        $res_p = $conn->query("SELECT track_expiry FROM products WHERE id = $productId LIMIT 1");
        if ($res_p && $row_p = $res_p->fetch_assoc()) {
            $trackExpiry = (bool)$row_p['track_expiry'];
        }

        $orderBy = $trackExpiry ? "expiry_date ASC, id ASC" : "created_at ASC, id ASC";

        // Get active batches
        $res = $conn->query("SELECT id, quantity, cost_price FROM product_batches WHERE product_id = $productId AND quantity > 0 AND d_s = '0' ORDER BY $orderBy");
        
        $remainingQty = $qty;
        $totalCOGS = 0.0;

        if ($res && $res->num_rows > 0) {
            while ($batch = $res->fetch_assoc()) {
                if ($remainingQty <= 0) break;

                $batchId   = intval($batch['id']);
                $batchQty  = (float)$batch['quantity'];
                $batchCost = (float)$batch['cost_price'];

                if ($batchQty >= $remainingQty) {
                    // Fully satisfy from this batch
                    $conn->query("UPDATE product_batches SET quantity = quantity - $remainingQty WHERE id = $batchId");
                    $totalCOGS += $remainingQty * $batchCost;
                    $remainingQty = 0;
                } else {
                    // Deduct what is available and move to next
                    $conn->query("UPDATE product_batches SET quantity = 0 WHERE id = $batchId");
                    $totalCOGS += $batchQty * $batchCost;
                    $remainingQty -= $batchQty;
                }
            }
        }

        // Fallback to base product cost if quantity still remains (negative stock or no batch layers)
        if ($remainingQty > 0) {
            $res_p_base = $conn->query("SELECT buy_price FROM products WHERE id = $productId LIMIT 1");
            if ($res_p_base && $row_p_base = $res_p_base->fetch_assoc()) {
                $baseCost = (float)$row_p_base['buy_price'];
                $totalCOGS += $remainingQty * $baseCost;
            }
        }

        return $totalCOGS;
    }

    /**
     * Warehouse Transfer Creation
     */
    public static function transferStock(\mysqli $conn, array $data): array
    {
        $conn->begin_transaction();
        try {
            $fromWH      = intval($data['from_warehouse_id']);
            $toWH        = intval($data['to_warehouse_id']);
            $transferDate= $conn->real_escape_string($data['transfer_date'] ?? date('Y-m-d'));
            $notes       = $conn->real_escape_string($data['notes'] ?? '');
            $createdBy   = $conn->real_escape_string($data['created_by'] ?? 'system');
            
            $items       = $data['items'] ?? []; // [{product_id, quantity, batch_id, serial_id}]

            if ($fromWH <= 0 || $toWH <= 0 || $fromWH === $toWH || empty($items)) {
                throw new \Exception("بيانات التحويل غير صالحة");
            }

            // Insert Transfer
            $sql = "INSERT INTO `stock_transfers` (from_warehouse_id, to_warehouse_id, transfer_date, status, created_by, notes) 
                    VALUES ($fromWH, $toWH, '$transferDate', 'pending', '$createdBy', '$notes')";
            if (!$conn->query($sql)) {
                throw new \Exception("فشل تسجيل التحويل المخزني: " . $conn->error);
            }
            $transferId = $conn->insert_id;

            // Insert Items
            foreach ($items as $item) {
                $p_id = intval($item['product_id']);
                $qty  = round((float)$item['quantity'], 4);
                $b_id = !empty($item['batch_id']) ? intval($item['batch_id']) : 'NULL';
                $s_id = !empty($item['serial_id']) ? intval($item['serial_id']) : 'NULL';

                if ($p_id <= 0 || $qty <= 0) continue;

                // Check source stock availability
                $res_avail = $conn->query("SELECT quantity FROM `warehouses_stock` WHERE warehouse_id = $fromWH AND product_id = $p_id LIMIT 1");
                $avail = $res_avail ? floatval($res_avail->fetch_row()[0] ?? 0.0) : 0.0;
                if ($avail < $qty) {
                    throw new \Exception("الكمية المطلوبة للتحويل غير متوفرة في المستودع المصدر");
                }

                $conn->query("INSERT INTO `stock_transfer_items` (transfer_id, product_id, quantity, batch_id, serial_id) 
                              VALUES ($transferId, $p_id, $qty, $b_id, $s_id)");
            }

            $conn->commit();
            return ['success' => true, 'transfer_id' => $transferId];

        } catch (\Exception $e) {
            $conn->rollback();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Approve Warehouse Transfer
     */
    public static function approveTransfer(\mysqli $conn, int $transferId, string $approvedBy): array
    {
        $conn->begin_transaction();
        try {
            $res_t = $conn->query("SELECT * FROM `stock_transfers` WHERE id = $transferId LIMIT 1");
            if (!$res_t || $res_t->num_rows == 0) {
                throw new \Exception("طلب التحويل غير موجود");
            }
            $transfer = $res_t->fetch_assoc();
            if ($transfer['status'] !== 'pending') {
                throw new \Exception("طلب التحويل معتمد أو ملغي مسبقاً");
            }

            $fromWH = intval($transfer['from_warehouse_id']);
            $toWH   = intval($transfer['to_warehouse_id']);

            // Fetch items
            $res_items = $conn->query("SELECT * FROM `stock_transfer_items` WHERE transfer_id = $transferId");
            if ($res_items) {
                while ($item = $res_items->fetch_assoc()) {
                    $p_id = intval($item['product_id']);
                    $qty  = floatval($item['quantity']);
                    $b_id = !empty($item['batch_id']) ? intval($item['batch_id']) : null;
                    $s_id = !empty($item['serial_id']) ? intval($item['serial_id']) : null;

                    // Deduct from source warehouse
                    $conn->query("UPDATE `warehouses_stock` SET quantity = quantity - $qty WHERE warehouse_id = $fromWH AND product_id = $p_id");
                    
                    // Add to target warehouse
                    $conn->query("INSERT INTO `warehouses_stock` (warehouse_id, product_id, quantity) 
                                  VALUES ($toWH, $p_id, $qty) 
                                  ON DUPLICATE KEY UPDATE quantity = quantity + $qty");

                    // Move batch if applicable
                    if ($b_id) {
                        // In simple models, batches are global or tracked by warehouse, if tracked globally we don't need changes.
                        // But we log it.
                    }
                    if ($s_id) {
                        // Serials are global but could be flagged.
                    }

                    // Audit logs
                    $app_by_esc = $conn->real_escape_string($approvedBy);
                    self::logInventoryAction($conn, [
                        'product_id'      => $p_id,
                        'action_type'     => 'transfer_out',
                        'quantity'        => $qty,
                        'warehouse_id'    => $fromWH,
                        'reference_table' => 'stock_transfers',
                        'reference_id'    => $transferId,
                        'user_id'         => $app_by_esc
                    ]);

                    self::logInventoryAction($conn, [
                        'product_id'      => $p_id,
                        'action_type'     => 'transfer_in',
                        'quantity'        => $qty,
                        'warehouse_id'    => $toWH,
                        'reference_table' => 'stock_transfers',
                        'reference_id'    => $transferId,
                        'user_id'         => $app_by_esc
                    ]);
                }
            }

            // Update status
            $app_by_esc = $conn->real_escape_string($approvedBy);
            $conn->query("UPDATE `stock_transfers` SET status = 'approved', approved_by = '$app_by_esc' WHERE id = $transferId");

            $conn->commit();
            return ['success' => true];

        } catch (\Exception $e) {
            $conn->rollback();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Stock Settlement & Damage Adjustments
     */
    public static function adjustStock(\mysqli $conn, array $data): array
    {
        $conn->begin_transaction();
        try {
            $warehouseId    = intval($data['warehouse_id']);
            $adjustmentDate = $conn->real_escape_string($data['adjustment_date'] ?? date('Y-m-d'));
            $notes          = $conn->real_escape_string($data['notes'] ?? '');
            $createdBy      = $conn->real_escape_string($data['created_by'] ?? 'system');

            $items          = $data['items'] ?? []; // [{product_id, quantity, type('damaged'|'discrepancy'), cost_price}]

            if ($warehouseId <= 0 || empty($items)) {
                throw new \Exception("بيانات التسوية غير صالحة");
            }

            // Insert Adjustment Header
            $sql = "INSERT INTO `stock_adjustments` (warehouse_id, adjustment_date, notes, created_by) 
                    VALUES ($warehouseId, '$adjustmentDate', '$notes', '$createdBy')";
            if (!$conn->query($sql)) {
                throw new \Exception("فشل إنشاء التسوية: " . $conn->error);
            }
            $adjustmentId = $conn->insert_id;

            foreach ($items as $item) {
                $p_id = intval($item['product_id']);
                $qty  = round((float)$item['quantity'], 4); // can be negative (e.g. -2 for damage)
                $type = $conn->real_escape_string($item['type']);
                
                $costPrice = round((float)($item['cost_price'] ?? 0.0), 4);
                if ($costPrice <= 0) {
                    $res_c = $conn->query("SELECT buy_price FROM products WHERE id = $p_id LIMIT 1");
                    $costPrice = $res_c ? floatval($res_c->fetch_row()[0] ?? 0.0) : 0.0;
                }

                $absQty = abs($qty);
                if ($p_id <= 0 || $qty == 0) continue;

                // Insert Item
                $conn->query("INSERT INTO `stock_adjustment_items` (adjustment_id, product_id, quantity, type, cost_price) 
                              VALUES ($adjustmentId, $p_id, $qty, '$type', $costPrice)");

                if ($qty < 0) {
                    // Decrement Stock (Loss)
                    $conn->query("UPDATE `warehouses_stock` SET quantity = quantity - $absQty WHERE warehouse_id = $warehouseId AND product_id = $p_id");
                    $conn->query("UPDATE `products` SET quantity = quantity - $absQty, total = quantity * buy_price WHERE id = $p_id");
                    
                    // Deduct from batches
                    self::deductStockAndGetCOGS($conn, $p_id, $absQty);

                    // Accounting Integration if Damaged: Credit Inventory (1103), Debit Inventory Loss Expense (5105)
                    if ($type === 'damaged') {
                        $lossAmount = $absQty * $costPrice;
                        
                        $debitAccId = 0;
                        $res_deb = $conn->query("SELECT id FROM accounting_accounts WHERE code = '5105' LIMIT 1");
                        if ($res_deb && $row_deb = $res_deb->fetch_assoc()) {
                            $debitAccId = intval($row_deb['id']);
                        }

                        $creditAccId = 0;
                        $res_crd = $conn->query("SELECT id FROM accounting_accounts WHERE code = '1103' LIMIT 1");
                        if ($res_crd && $row_crd = $res_crd->fetch_assoc()) {
                            $creditAccId = intval($row_crd['id']);
                        }

                        if ($debitAccId > 0 && $creditAccId > 0 && $lossAmount > 0) {
                            $entryDesc = "تسوية بضاعة تالفة رقم #$adjustmentId: صنف $p_id | كمية: $absQty";
                            $postResult = JournalService::postEntry($conn, [
                                'entry_date'   => $adjustmentDate,
                                'reference_no' => '',
                                'description'  => $entryDesc,
                                'source_type'  => 'expense',
                                'source_id'    => $adjustmentId,
                                'created_by'   => $createdBy,
                                'items'        => [
                                    [
                                        'account_id'    => $debitAccId,
                                        'debit'         => $lossAmount,
                                        'credit'        => 0,
                                        'exchange_rate' => 1.0,
                                        'memo'          => $entryDesc
                                    ],
                                    [
                                        'account_id'    => $creditAccId,
                                        'debit'         => 0,
                                        'credit'        => $lossAmount,
                                        'exchange_rate' => 1.0,
                                        'memo'          => $entryDesc
                                    ]
                                ]
                            ]);

                            if (!$postResult['success']) {
                                throw new \Exception("فشل تسجيل قيد خسائر التالف: " . $postResult['error']);
                            }
                        }
                    }
                } else {
                    // Increment Stock (Surplus)
                    $conn->query("UPDATE `warehouses_stock` SET quantity = quantity + $qty WHERE warehouse_id = $warehouseId AND product_id = $p_id");
                    $conn->query("UPDATE `products` SET quantity = quantity + $qty, total = quantity * buy_price WHERE id = $p_id");
                    
                    // Add batch
                    $batchNo = 'ADJ-SURPLUS-' . $adjustmentId;
                    $conn->query("INSERT INTO `product_batches` (product_id, batch_number, quantity, cost_price) 
                                  VALUES ($p_id, '$batchNo', $qty, $costPrice)");
                }

                // Log audit action
                self::logInventoryAction($conn, [
                    'product_id'      => $p_id,
                    'action_type'     => 'adjustment',
                    'quantity'        => $absQty,
                    'cost_price'      => $costPrice,
                    'warehouse_id'    => $warehouseId,
                    'reference_table' => 'stock_adjustments',
                    'reference_id'    => $adjustmentId,
                    'user_id'         => $createdBy
                ]);
            }

            $conn->commit();
            return ['success' => true, 'adjustment_id' => $adjustmentId];

        } catch (\Exception $e) {
            $conn->rollback();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Stock Valuation Report (FIFO layers)
     */
    public static function getValuationReport(\mysqli $conn): array
    {
        $sql = "
            SELECT 
                p.id as product_id,
                p.name as product_name,
                p.barcode,
                COALESCE(SUM(pb.quantity), 0) as total_qty,
                COALESCE(SUM(pb.quantity * pb.cost_price), 0) as total_valuation,
                p.buy_price as last_buy_price
            FROM products p
            LEFT JOIN product_batches pb ON p.id = pb.product_id AND pb.quantity > 0 AND pb.d_s = '0'
            WHERE p.delete_status = 0
            GROUP BY p.id
            ORDER BY p.name ASC
        ";
        
        $result = $conn->query($sql);
        $rows = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                // If there are no active batches, fallback to global product quantity and buy_price
                if (floatval($row['total_qty']) == 0) {
                    $p_id = intval($row['product_id']);
                    $p_res = $conn->query("SELECT quantity, buy_price FROM products WHERE id = $p_id LIMIT 1");
                    if ($p_res && $p_data = $p_res->fetch_assoc()) {
                        $row['total_qty'] = floatval($p_data['quantity']);
                        $row['total_valuation'] = floatval($p_data['quantity']) * floatval($p_data['buy_price']);
                    }
                }
                $rows[] = $row;
            }
        }
        return $rows;
    }

    /**
     * Near-Expiry Batches List
     */
    public static function getNearExpiryItems(\mysqli $conn, int $days = 30): array
    {
        $days = intval($days);
        $sql = "
            SELECT 
                pb.id as batch_id,
                pb.batch_number,
                pb.expiry_date,
                pb.quantity,
                pb.cost_price,
                p.id as product_id,
                p.name as product_name,
                DATEDIFF(pb.expiry_date, CURDATE()) as days_left
            FROM product_batches pb
            JOIN products p ON pb.product_id = p.id
            WHERE pb.quantity > 0 
              AND pb.expiry_date IS NOT NULL 
              AND pb.expiry_date <= DATE_ADD(CURDATE(), INTERVAL $days DAY)
              AND pb.d_s = '0'
              AND p.delete_status = 0
            ORDER BY pb.expiry_date ASC
        ";
        $res = $conn->query($sql);
        $rows = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $rows[] = $row;
            }
        }
        return $rows;
    }
}
?>
