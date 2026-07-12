<?php
namespace AQNEX\Services;

class AccountingService
{
    public static function beginTransaction(\mysqli $conn): bool
    {
        return $conn->begin_transaction();
    }

    public static function commit(\mysqli $conn): bool
    {
        return $conn->commit();
    }

    public static function rollback(\mysqli $conn): bool
    {
        return $conn->rollback();
    }

    /**
     * Posts a double-entry transaction into the journal_entries table.
     * Also mirrors to the legacy accounting_journal table for complete backwards compatibility.
     */
    public static function post(
        \mysqli $conn,
        string $ref_type,
        int $ref_id,
        string $debit_acc,
        string $credit_acc,
        float $amount,
        string $desc,
        string $user,
        ?int $box_id = null,
        string $debit_entity_type = 'general',
        ?int $debit_entity_id = null,
        string $credit_entity_type = 'general',
        ?int $credit_entity_id = null,
        string $currency = 'YER',
        float $exchange_rate = 1.0,
        ?int $sector_id = null
    ): bool {
        if ($amount == 0) {
            return true;
        }

        // Automatic Entity Resolution (Defensive bookkeeping)
        if ($debit_entity_type === 'general') {
            if (strpos($debit_acc, 'الذمم المدينة - ') === 0) {
                $name = str_replace('الذمم المدينة - ', '', $debit_acc);
                $id = self::lookupCustomerId($conn, $name);
                if ($id > 0) {
                    $debit_entity_type = 'customer';
                    $debit_entity_id = $id;
                }
            } elseif (strpos($debit_acc, 'الذمم الدائنة - ') === 0) {
                $name = str_replace('الذمم الدائنة - ', '', $debit_acc);
                $id = self::lookupSupplierId($conn, $name);
                if ($id > 0) {
                    $debit_entity_type = 'supplier';
                    $debit_entity_id = $id;
                }
            } elseif (strpos($debit_acc, 'الصندوق - ') === 0) {
                $name = str_replace('الصندوق - ', '', $debit_acc);
                $id = self::lookupBoxId($conn, $name);
                if ($id > 0) {
                    $debit_entity_type = 'fund';
                    $debit_entity_id = $id;
                }
            } elseif (strpos($debit_acc, 'نقدية مبيعات معلقة - ') === 0) {
                $name = str_replace('نقدية مبيعات معلقة - ', '', $debit_acc);
                $id = self::lookupBoxId($conn, $name);
                if ($id > 0) {
                    $debit_entity_type = 'fund';
                    $debit_entity_id = $id;
                }
            }
        }

        if ($credit_entity_type === 'general') {
            if (strpos($credit_acc, 'الذمم المدينة - ') === 0) {
                $name = str_replace('الذمم المدينة - ', '', $credit_acc);
                $id = self::lookupCustomerId($conn, $name);
                if ($id > 0) {
                    $credit_entity_type = 'customer';
                    $credit_entity_id = $id;
                }
            } elseif (strpos($credit_acc, 'الذمم الدائنة - ') === 0) {
                $name = str_replace('الذمم الدائنة - ', '', $credit_acc);
                $id = self::lookupSupplierId($conn, $name);
                if ($id > 0) {
                    $credit_entity_type = 'supplier';
                    $credit_entity_id = $id;
                }
            } elseif (strpos($credit_acc, 'الصندوق - ') === 0) {
                $name = str_replace('الصندوق - ', '', $credit_acc);
                $id = self::lookupBoxId($conn, $name);
                if ($id > 0) {
                    $credit_entity_type = 'fund';
                    $credit_entity_id = $id;
                }
            } elseif (strpos($credit_acc, 'نقدية مبيعات معلقة - ') === 0) {
                $name = str_replace('نقدية مبيعات معلقة - ', '', $credit_acc);
                $id = self::lookupBoxId($conn, $name);
                if ($id > 0) {
                    $credit_entity_type = 'fund';
                    $credit_entity_id = $id;
                }
            }
        }

        // Fallback for fund IDs from parameter
        if ($debit_entity_type === 'fund' && $debit_entity_id === null && $box_id > 0) {
            $debit_entity_id = $box_id;
        }
        if ($credit_entity_type === 'fund' && $credit_entity_id === null && $box_id > 0) {
            $credit_entity_id = $box_id;
        }

        $ref_type_esc = $conn->real_escape_string($ref_type);
        $debit_acc_esc = $conn->real_escape_string($debit_acc);
        $credit_acc_esc = $conn->real_escape_string($credit_acc);
        $desc_esc = $conn->real_escape_string($desc);
        $user_esc = $conn->real_escape_string($user);
        $currency_esc = $conn->real_escape_string($currency);
        
        $box_val = ($box_id === null) ? "NULL" : intval($box_id);
        $sector_val = ($sector_id === null) ? "NULL" : intval($sector_id);
        
        $debit_id_sql = ($debit_entity_id === null) ? "NULL" : intval($debit_entity_id);
        $credit_id_sql = ($credit_entity_id === null) ? "NULL" : intval($credit_entity_id);

        $amount_foreign = $amount / ($exchange_rate > 0 ? $exchange_rate : 1.0);

        // 1. Insert into journal_entries
        $sql1 = "INSERT INTO journal_entries 
                (ref_type, ref_id, account_debit, account_credit, debit_entity_type, debit_entity_id, credit_entity_type, credit_entity_id, amount, description, currency_code, exchange_rate, amount_foreign, user, box_id, sector_id) 
                VALUES 
                ('$ref_type_esc', $ref_id, '$debit_acc_esc', '$credit_acc_esc', '$debit_entity_type', $debit_id_sql, '$credit_entity_type', $credit_id_sql, $amount, '$desc_esc', '$currency_esc', $exchange_rate, $amount_foreign, '$user_esc', $box_val, $sector_val)";
        $res1 = $conn->query($sql1);

        // 2. Mirror into legacy accounting_journal
        $sql2 = "INSERT INTO accounting_journal 
                (ref_type, ref_id, account_debit, account_credit, amount, description, currency_code, exchange_rate, amount_foreign, user, box_id, sector_id) 
                VALUES 
                ('$ref_type_esc', $ref_id, '$debit_acc_esc', '$credit_acc_esc', $amount, '$desc_esc', '$currency_esc', $exchange_rate, $amount_foreign, '$user_esc', $box_val, $sector_val)";
        $res2 = $conn->query($sql2);

        // 3. Mirror into modern accounting_journal_entries & accounting_journal_items
        try {
            $debit_acc_id = 0;
            $credit_acc_id = 0;

            $res_deb = $conn->query("SELECT id FROM accounting_accounts WHERE name = '$debit_acc_esc' LIMIT 1");
            if ($res_deb && $row_deb = $res_deb->fetch_assoc()) {
                $debit_acc_id = intval($row_deb['id']);
            }
            $res_crd = $conn->query("SELECT id FROM accounting_accounts WHERE name = '$credit_acc_esc' LIMIT 1");
            if ($res_crd && $row_crd = $res_crd->fetch_assoc()) {
                $credit_acc_id = intval($row_crd['id']);
            }

            if ($debit_acc_id === 0 || $credit_acc_id === 0) {
                self::syncAccounts($conn);
                
                $res_deb = $conn->query("SELECT id FROM accounting_accounts WHERE name = '$debit_acc_esc' LIMIT 1");
                if ($res_deb && $row_deb = $res_deb->fetch_assoc()) {
                    $debit_acc_id = intval($row_deb['id']);
                }
                $res_crd = $conn->query("SELECT id FROM accounting_accounts WHERE name = '$credit_acc_esc' LIMIT 1");
                if ($res_crd && $row_crd = $res_crd->fetch_assoc()) {
                    $credit_acc_id = intval($row_crd['id']);
                }
            }

            if ($debit_acc_id > 0 && $credit_acc_id > 0 && $debit_acc_id !== $credit_acc_id) {
                $ref_no = 'JE-' . date('Y') . '-' . $ref_type . '-' . $ref_id;
                $ref_no_esc = $conn->real_escape_string($ref_no);
                
                $entry_date = date('Y-m-d');
                $sql_h = "INSERT INTO accounting_journal_entries 
                          (entry_date, reference_no, description, source_type, source_id, status, created_by) 
                          VALUES 
                          ('$entry_date', '$ref_no_esc', '$desc_esc', '$ref_type_esc', $ref_id, 'posted', '$user_esc')";
                if ($conn->query($sql_h)) {
                    $entry_id = $conn->insert_id;
                    
                    $sql_d = "INSERT INTO accounting_journal_items 
                              (entry_id, account_id, debit, credit, exchange_rate, memo) 
                              VALUES 
                              ($entry_id, $debit_acc_id, $amount, 0.0, $exchange_rate, '$desc_esc')";
                    $conn->query($sql_d);
                    
                    $sql_c = "INSERT INTO accounting_journal_items 
                              (entry_id, account_id, debit, credit, exchange_rate, memo) 
                              VALUES 
                              ($entry_id, $credit_acc_id, 0.0, $amount, $exchange_rate, '$desc_esc')";
                    $conn->query($sql_c);
                }
            }
        } catch (\Exception $e) {
            // Silently ignore or write log to prevent breaking transaction flow
        }

        return ($res1 && $res2);
    }

    public static function getCustomerBalance(\mysqli $conn, int $customerId): float
    {
        $stmt = $conn->prepare("
            SELECT 
              (SELECT COALESCE(SUM(amount), 0) FROM journal_entries WHERE debit_entity_type = 'customer' AND debit_entity_id = ?) -
              (SELECT COALESCE(SUM(amount), 0) FROM journal_entries WHERE credit_entity_type = 'customer' AND credit_entity_id = ?) AS balance
        ");
        if ($stmt) {
            $stmt->bind_param("ii", $customerId, $customerId);
            $stmt->execute();
            $res = $stmt->get_result();
            $row = $res->fetch_assoc();
            $stmt->close();
            return $row ? floatval($row['balance']) : 0.0;
        }
        return 0.0;
    }

    public static function getSupplierBalance(\mysqli $conn, int $supplierId): float
    {
        $stmt = $conn->prepare("
            SELECT 
              (SELECT COALESCE(SUM(amount), 0) FROM journal_entries WHERE credit_entity_type = 'supplier' AND credit_entity_id = ?) -
              (SELECT COALESCE(SUM(amount), 0) FROM journal_entries WHERE debit_entity_type = 'supplier' AND debit_entity_id = ?) AS balance
        ");
        if ($stmt) {
            $stmt->bind_param("ii", $supplierId, $supplierId);
            $stmt->execute();
            $res = $stmt->get_result();
            $row = $res->fetch_assoc();
            $stmt->close();
            return $row ? floatval($row['balance']) : 0.0;
        }
        return 0.0;
    }

    public static function getFundBalance(\mysqli $conn, int $boxId): float
    {
        $stmt = $conn->prepare("
            SELECT 
              (SELECT COALESCE(SUM(amount), 0) FROM journal_entries WHERE debit_entity_type = 'fund' AND debit_entity_id = ?) -
              (SELECT COALESCE(SUM(amount), 0) FROM journal_entries WHERE credit_entity_type = 'fund' AND credit_entity_id = ?) AS balance
        ");
        if ($stmt) {
            $stmt->bind_param("ii", $boxId, $boxId);
            $stmt->execute();
            $res = $stmt->get_result();
            $row = $res->fetch_assoc();
            $stmt->close();
            return $row ? floatval($row['balance']) : 0.0;
        }
        return 0.0;
    }

    public static function syncAccounts(\mysqli $conn): void
    {
        // 1. Ensure basic static accounts and names are updated/corrected
        $baseAccounts = [
            '1' => ['الأصول', null, 'asset', 1, 1],
            '11' => ['الأصول المتداولة', '1', 'asset', 1, 2],
            '1101' => ['الصناديق والسيولة', '11', 'asset', 1, 3], // Changed to parent
            '1102' => ['الذمم المدينة', '11', 'asset', 1, 3], // Changed to parent & renamed
            '1103' => ['المخزون / البضاعة', '11', 'asset', 0, 3],
            '1104' => ['نقدية مبيعات معلقة', '11', 'asset', 1, 3], // Added new parent for pending sales
            '12' => ['الأصول الثابتة', '1', 'asset', 1, 2],
            '2' => ['الخصوم والالتزامات', null, 'liability', 1, 1],
            '21' => ['الالتزامات المتداولة', '2', 'liability', 1, 2],
            '2101' => ['الذمم الدائنة', '21', 'liability', 1, 3], // Changed to parent & renamed
            '3' => ['حقوق الملكية', null, 'equity', 1, 1],
            '31' => ['رأس المال والاحتياطيات', '3', 'equity', 1, 2],
            '3101' => ['رأس المال المفتوح', '31', 'equity', 0, 3],
            '3102' => ['رأس المال / رصيد افتتاحي', '31', 'equity', 0, 3],
            '3103' => ['رأس المال / دفع خارجي', '31', 'equity', 0, 3],
            '4' => ['الإيرادات', null, 'revenue', 1, 1],
            '4101' => ['المبيعات', '4', 'revenue', 0, 2],
            '4102' => ['مردودات المبيعات', '4', 'revenue', 0, 2], // Renamed to match returns.php
            '4103' => ['إيرادات الصيانة والخدمات', '4', 'revenue', 0, 2], // Added
            '4104' => ['زيادات وفروقات الصناديق (إيراد)', '4', 'revenue', 0, 2], // Added
            '5' => ['المصروفات', null, 'expense', 1, 1],
            '5101' => ['تكلفة البضاعة المباعة (مصروف)', '5', 'expense', 0, 2], // Renamed to match returns.php
            '5102' => ['المصروفات العامة والتشغيلية', '5', 'expense', 1, 2], // Changed to parent
            '5103' => ['الخصم المسموح به (مصروف)', '5', 'expense', 0, 2], // Added
            '5104' => ['عجز وفروقات الصناديق (مصروف)', '5', 'expense', 0, 2], // Added
            '5105' => ['خسائر وتلفيات المخزون (مصروف)', '5', 'expense', 0, 2], // Added
        ];

        // We will insert/update these base accounts
        // First resolve parent IDs dynamically if we are inserting new ones
        foreach ($baseAccounts as $code => $info) {
            $name = $info[0];
            $parentCode = $info[1];
            $type = $info[2];
            $isParent = $info[3];
            $level = $info[4];

            $parentId = null;
            if ($parentCode !== null) {
                // Find parent database ID by code
                $p_res = $conn->query("SELECT id FROM accounting_accounts WHERE code = '" . $conn->real_escape_string($parentCode) . "' LIMIT 1");
                if ($p_res && $p_row = $p_res->fetch_assoc()) {
                    $parentId = intval($p_row['id']);
                }
            }

            // Check if code exists
            $chk = $conn->query("SELECT id FROM accounting_accounts WHERE code = '" . $conn->real_escape_string($code) . "' LIMIT 1");
            if ($chk && $chk->num_rows > 0) {
                $existing = $chk->fetch_assoc();
                $id = $existing['id'];
                // Update properties
                $parent_val = ($parentId === null) ? 'NULL' : $parentId;
                $conn->query("UPDATE accounting_accounts 
                              SET name = '" . $conn->real_escape_string($name) . "', 
                                  parent_id = $parent_val, 
                                  account_type = '$type', 
                                  is_parent = $isParent, 
                                  level = $level 
                              WHERE id = $id");
            } else {
                // Insert new base account
                $parent_val = ($parentId === null) ? 'NULL' : $parentId;
                $conn->query("INSERT INTO accounting_accounts (code, name, parent_id, account_type, is_parent, level) 
                              VALUES ('" . $conn->real_escape_string($code) . "', '" . $conn->real_escape_string($name) . "', $parent_val, '$type', $isParent, $level)");
            }
        }

        // Get updated database IDs for the parents we need to link children to
        $parents = ['1101' => null, '1102' => null, '2101' => null, '5102' => null, '1104' => null];
        $codes_str = implode("','", array_keys($parents));
        $res = $conn->query("SELECT id, code FROM accounting_accounts WHERE code IN ('$codes_str')");
        while ($row = $res->fetch_assoc()) {
            $parents[$row['code']] = intval($row['id']);
        }

        // 2. Sync treasury boxes (funds) under 1101
        if ($parents['1101'] !== null) {
            $res_box = $conn->query("SELECT box_id, name FROM treasury");
            if ($res_box) {
                while ($box = $res_box->fetch_assoc()) {
                    $box_id = intval($box['box_id']);
                    $box_name = $box['name'];
                    $code = '1101' . sprintf('%04d', $box_id);
                    $acc_name = 'الصندوق - ' . $box_name;

                    self::upsertChildAccount($conn, $code, $acc_name, $parents['1101'], 'asset', 4);
                }
            }
        }

        // 3. Sync pending sales (pending cash) under 1104
        if ($parents['1104'] !== null) {
            $res_box = $conn->query("SELECT box_id, name FROM treasury");
            if ($res_box) {
                while ($box = $res_box->fetch_assoc()) {
                    $box_id = intval($box['box_id']);
                    $box_name = $box['name'];
                    $code = '1104' . sprintf('%04d', $box_id);
                    $acc_name = 'نقدية مبيعات معلقة - ' . $box_name;

                    self::upsertChildAccount($conn, $code, $acc_name, $parents['1104'], 'asset', 4);
                }
            }
        }

        // 4. Sync customers under 1102
        if ($parents['1102'] !== null) {
            $res_cust = $conn->query("SELECT cust_id, cust_name FROM customers WHERE d_s = 0");
            if ($res_cust) {
                while ($cust = $res_cust->fetch_assoc()) {
                    $cust_id = intval($cust['cust_id']);
                    $cust_name = $cust['cust_name'];
                    if (empty($cust_name) || $cust_name === 'عميل نقدي') continue;
                    $code = '1102' . sprintf('%04d', $cust_id);
                    $acc_name = 'الذمم المدينة - ' . $cust_name;

                    self::upsertChildAccount($conn, $code, $acc_name, $parents['1102'], 'asset', 4);
                }
            }
        }

        // 5. Sync suppliers under 2101
        if ($parents['2101'] !== null) {
            $res_supp = $conn->query("SELECT supp_id, supp_name FROM suppliers WHERE d_s = 0");
            if ($res_supp) {
                while ($supp = $res_supp->fetch_assoc()) {
                    $supp_id = intval($supp['supp_id']);
                    $supp_name = $supp['supp_name'];
                    $code = '2101' . sprintf('%04d', $supp_id);
                    $acc_name = 'الذمم الدائنة - ' . $supp_name;

                    self::upsertChildAccount($conn, $code, $acc_name, $parents['2101'], 'liability', 4);
                }
            }
        }

        // 6. Sync expense types under 5102
        if ($parents['5102'] !== null) {
            $res_exp = $conn->query("SELECT DISTINCT sname FROM expenses WHERE sname != '' AND sname IS NOT NULL ORDER BY sname ASC");
            if ($res_exp) {
                $idx = 1;
                while ($exp = $res_exp->fetch_assoc()) {
                    $exp_type = $exp['sname'];
                    $code = '5102' . sprintf('%04d', $idx++);
                    $acc_name = 'مصروفات - ' . $exp_type;

                    self::upsertChildAccount($conn, $code, $acc_name, $parents['5102'], 'expense', 3);
                }
            }
        }
    }

    private static function upsertChildAccount(\mysqli $conn, string $code, string $name, int $parentId, string $type, int $level): void
    {
        $code_esc = $conn->real_escape_string($code);
        $name_esc = $conn->real_escape_string($name);
        $type_esc = $conn->real_escape_string($type);

        $chk = $conn->query("SELECT id FROM accounting_accounts WHERE code = '$code_esc' LIMIT 1");
        if ($chk && $chk->num_rows > 0) {
            $row = $chk->fetch_assoc();
            $id = intval($row['id']);
            $conn->query("UPDATE accounting_accounts 
                          SET name = '$name_esc', parent_id = $parentId, account_type = '$type_esc', is_parent = 0, level = $level 
                          WHERE id = $id");
        } else {
            // Check if name is already registered to prevent duplicate names on other codes
            $chk_name = $conn->query("SELECT id FROM accounting_accounts WHERE name = '$name_esc' LIMIT 1");
            if ($chk_name && $chk_name->num_rows > 0) {
                $row_n = $chk_name->fetch_assoc();
                $id_n = intval($row_n['id']);
                // Update code and other fields to match correct code
                $conn->query("UPDATE accounting_accounts 
                              SET code = '$code_esc', parent_id = $parentId, account_type = '$type_esc', is_parent = 0, level = $level 
                              WHERE id = $id_n");
            } else {
                $conn->query("INSERT INTO accounting_accounts (code, name, parent_id, account_type, is_parent, level) 
                              VALUES ('$code_esc', '$name_esc', $parentId, '$type_esc', 0, $level)");
            }
        }
    }

    // Helper lookups
    private static function lookupCustomerId(\mysqli $conn, string $name): int
    {
        $name_esc = $conn->real_escape_string($name);
        $res = $conn->query("SELECT cust_id FROM customers WHERE cust_name = '$name_esc' LIMIT 1");
        return ($res && $row = $res->fetch_assoc()) ? intval($row['cust_id']) : 0;
    }

    private static function lookupSupplierId(\mysqli $conn, string $name): int
    {
        $name_esc = $conn->real_escape_string($name);
        $res = $conn->query("SELECT supp_id FROM suppliers WHERE supp_name = '$name_esc' LIMIT 1");
        return ($res && $row = $res->fetch_assoc()) ? intval($row['supp_id']) : 0;
    }

    private static function lookupBoxId(\mysqli $conn, string $name): int
    {
        $name_esc = $conn->real_escape_string($name);
        $res = $conn->query("SELECT box_id FROM treasury WHERE name = '$name_esc' LIMIT 1");
        return ($res && $row = $res->fetch_assoc()) ? intval($row['box_id']) : 0;
    }
}
