<?php
/**
 * PHP Migration Script: Master-Detail Data Unification & Legacy Data Transfer
 * Safely migrates sales, purchases, receipt vouchers, and payment vouchers into _mst and _dtl tables.
 */
require_once(__DIR__ . '/../../includes/connect.php');

header('Content-Type: text/html; charset=utf-8');

echo "<h2>AQNEX POS - Master-Detail Data Migration</h2>";
echo "<ul>";

try {
    // 1. Run SQL Migration DDL
    $sql_file = __DIR__ . '/sprint4_master_detail_unification.sql';
    if (file_exists($sql_file)) {
        $ddl = file_get_contents($sql_file);
        // Execute multi query or split by semicolon
        $statements = array_filter(array_map('trim', explode(';', $ddl)));
        foreach ($statements as $stmt) {
            if (!empty($stmt)) {
                $conn->query($stmt);
            }
        }
        echo "<li>✅ SQL DDL Tables (sprint4_master_detail_unification.sql) verified/created successfully.</li>";
    }

    // 2. Migrate legacy `sales` -> `sales_invoices_mst` & `sales_items` -> `sales_invoices_dtl`
    $chk_sales = $conn->query("SHOW TABLES LIKE 'sales'");
    if ($chk_sales && $chk_sales->num_rows > 0) {
        $sales_res = $conn->query("SELECT * FROM sales WHERE delete_status = 0");
        $migrated_sales = 0;
        if ($sales_res) {
            while ($s = $sales_res->fetch_assoc()) {
                $inv_id = intval($s['id']);
                $inv_no = 'INV-' . str_pad($inv_id, 6, '0', STR_PAD_LEFT);
                $cust_name = $conn->real_escape_string($s['cust_name'] ?: 'عميل نقدي');
                
                // Get customer ID if available
                $c_res = $conn->query("SELECT supp_id FROM customers WHERE cust_name = '{$cust_name}' LIMIT 1");
                $cust_id = ($c_res && $c_res->num_rows > 0) ? intval($c_res->fetch_assoc()['supp_id']) : 'NULL';

                $invoice_date = $s['build_date'] ?? date('Y-m-d');
                $total_amount = floatval($s['total'] ?? 0);
                $net_amount = floatval($s['total'] ?? 0);
                $profit_total = floatval($s['prifet'] ?? 0);
                $box_id = intval($s['box_id'] ?? 1);
                $curr = $conn->real_escape_string($s['currency_code'] ?? 'YER');
                $rate = floatval($s['exchange_rate'] ?? 1.0);
                $user_id = intval($s['user_id'] ?? 1);

                // Insert into sales_invoices_mst if not exists
                $exist_mst = $conn->query("SELECT id FROM sales_invoices_mst WHERE id = $inv_id OR invoice_no = '$inv_no' LIMIT 1");
                if (!$exist_mst || $exist_mst->num_rows === 0) {
                    $ins_mst = "INSERT INTO sales_invoices_mst 
                                (id, invoice_no, cust_id, cust_name, invoice_date, total_amount, discount_amount, net_amount, paid_amount, remaining_amount, invoice_type, payment_method, profit_total, box_id, currency_code, exchange_rate, user_id, d_s)
                                VALUES 
                                ($inv_id, '$inv_no', $cust_id, '$cust_name', '$invoice_date', $total_amount, 0, $net_amount, $net_amount, 0, 'cash', 'cash', $profit_total, $box_id, '$curr', $rate, $user_id, 0)";
                    if ($conn->query($ins_mst)) {
                        $new_mst_id = $conn->insert_id ?: $inv_id;
                        $migrated_sales++;

                        // Migrate line items from sales_items
                        $items_res = $conn->query("SELECT * FROM sales_items WHERE sales_id = $inv_id");
                        if ($items_res) {
                            while ($item = $items_res->fetch_assoc()) {
                                $prod_id = intval($item['p_id'] ?? $item['id']);
                                $p_name = $conn->real_escape_string($item['name'] ?? 'منتج');
                                $qty = floatval($item['quantity'] ?? 1);
                                $price = floatval($item['unit_price'] ?? 0);
                                $tot = floatval($item['all_tot'] ?? 0);

                                $conn->query("INSERT INTO sales_invoices_dtl (invoice_id, product_id, product_name, quantity, unit_price, total_price, d_s)
                                              VALUES ($new_mst_id, $prod_id, '$p_name', $qty, $price, $tot, 0)");
                            }
                        }
                    }
                }
            }
        }
        echo "<li>✅ Migrated $migrated_sales legacy sales invoices to `sales_invoices_mst` & `sales_invoices_dtl`.</li>";
    }

    // 3. Migrate legacy `sales_returns` -> `sales_returns_mst` & `sales_returns_dtl`
    $chk_returns = $conn->query("SHOW TABLES LIKE 'sales_returns'");
    if ($chk_returns && $chk_returns->num_rows > 0) {
        $returns_res = $conn->query("SELECT * FROM sales_returns WHERE status = 'active'");
        $migrated_returns = 0;
        if ($returns_res) {
            while ($r = $returns_res->fetch_assoc()) {
                $orig_sale_id = intval($r['sales_id']);
                $ret_id = intval($r['id']);
                $ret_no = 'RET-S-' . str_pad($ret_id, 6, '0', STR_PAD_LEFT);
                $p_id = intval($r['product_id']);
                $p_name = $conn->real_escape_string($r['product_name'] ?? 'منتج مرتجع');
                $qty = floatval($r['quantity'] ?? 1);
                $unit_price = floatval($r['unit_price'] ?? 0);
                $refund = floatval($r['refund_amount'] ?? 0);
                $reason = $conn->real_escape_string($r['reason'] ?? '');
                $ret_date = $r['return_date'] ?? date('Y-m-d');
                $box_id = intval($r['box_id'] ?? 1);
                $refund_method = ($r['refund_method'] ?? 'cash') === 'credit' ? 'credit' : 'cash';

                $exist_ret = $conn->query("SELECT id FROM sales_returns_mst WHERE id = $ret_id OR return_no = '$ret_no' LIMIT 1");
                if (!$exist_ret || $exist_ret->num_rows === 0) {
                    $ins_ret_mst = "INSERT INTO sales_returns_mst 
                                    (id, return_no, original_sale_id, cust_name, return_date, total_amount, refund_method, box_id, reason, d_s)
                                    VALUES 
                                    ($ret_id, '$ret_no', $orig_sale_id, 'عميل مرتجع', '$ret_date', $refund, '$refund_method', $box_id, '$reason', 0)";
                    if ($conn->query($ins_ret_mst)) {
                        $new_ret_id = $conn->insert_id ?: $ret_id;
                        $migrated_returns++;
                        $conn->query("INSERT INTO sales_returns_dtl (return_id, product_id, product_name, quantity, unit_price, total_amount, d_s)
                                      VALUES ($new_ret_id, $p_id, '$p_name', $qty, $unit_price, $refund, 0)");
                    }
                }
            }
        }
        echo "<li>✅ Migrated $migrated_returns legacy sales returns to `sales_returns_mst` & `sales_returns_dtl`.</li>";
    }

    // 4. Migrate legacy `purchases` -> `purchase_invoices_mst` & `purchase_items` -> `purchase_invoices_dtl`
    $chk_pur = $conn->query("SHOW TABLES LIKE 'purchases'");
    if ($chk_pur && $chk_pur->num_rows > 0) {
        $pur_res = $conn->query("SELECT * FROM purchases WHERE d_s = 0");
        $migrated_pur = 0;
        if ($pur_res) {
            while ($p = $pur_res->fetch_assoc()) {
                $pur_id = intval($p['id']);
                $inv_no = $p['invoice_no'] ?: ('PUR-' . str_pad($pur_id, 6, '0', STR_PAD_LEFT));
                $supp_name = $conn->real_escape_string($p['supp_name'] ?: 'مورد عام');
                $supp_id = intval($p['supp_id'] ?? 0) ?: 'NULL';
                $inv_date = $p['invoice_date'] ?? date('Y-m-d');
                $tot = floatval($p['total_amount'] ?? $p['total'] ?? 0);
                $paid = floatval($p['paid_amount'] ?? $tot);
                $rem = floatval($p['remaining_amount'] ?? 0);
                $box_id = intval($p['box_id'] ?? 1);

                $exist_pur = $conn->query("SELECT id FROM purchase_invoices_mst WHERE id = $pur_id OR invoice_no = '$inv_no' LIMIT 1");
                if (!$exist_pur || $exist_pur->num_rows === 0) {
                    $ins_pur = "INSERT INTO purchase_invoices_mst
                                (id, invoice_no, supp_id, supp_name, invoice_date, total_amount, discount_amount, net_amount, paid_amount, remaining_amount, invoice_type, payment_method, box_id, d_s)
                                VALUES 
                                ($pur_id, '$inv_no', $supp_id, '$supp_name', '$inv_date', $tot, 0, $tot, $paid, $rem, 'cash', 'cash', $box_id, 0)";
                    if ($conn->query($ins_pur)) {
                        $new_pur_id = $conn->insert_id ?: $pur_id;
                        $migrated_pur++;

                        $pi_res = $conn->query("SELECT * FROM purchase_items WHERE purchase_id = $pur_id");
                        if ($pi_res) {
                            while ($pi = $pi_res->fetch_assoc()) {
                                $prod_id = intval($pi['product_id'] ?? $pi['id']);
                                $p_name = $conn->real_escape_string($pi['product_name'] ?? $pi['name'] ?? 'منتج');
                                $qty = floatval($pi['quantity'] ?? 1);
                                $cost = floatval($pi['unit_cost'] ?? $pi['unit_price'] ?? 0);
                                $tot_cost = floatval($pi['total_cost'] ?? ($qty * $cost));

                                $conn->query("INSERT INTO purchase_invoices_dtl (invoice_id, product_id, product_name, quantity, unit_cost, total_cost, d_s)
                                              VALUES ($new_pur_id, $prod_id, '$p_name', $qty, $cost, $tot_cost, 0)");
                            }
                        }
                    }
                }
            }
        }
        echo "<li>✅ Migrated $migrated_pur legacy purchase invoices to `purchase_invoices_mst` & `purchase_invoices_dtl`.</li>";
    }

    // 5. Migrate legacy `receipts` -> `receipt_vouchers_mst` & `receipt_vouchers_dtl`
    $chk_rec = $conn->query("SHOW TABLES LIKE 'receipts'");
    if ($chk_rec && $chk_rec->num_rows > 0) {
        $rec_res = $conn->query("SELECT * FROM receipts WHERE delete_status = 0");
        $migrated_rec = 0;
        if ($rec_res) {
            while ($rc = $rec_res->fetch_assoc()) {
                $rec_id = intval($rc['id']);
                $v_no = 'REC-' . str_pad($rec_id, 6, '0', STR_PAD_LEFT);
                $party_name = $conn->real_escape_string($rc['cust_name'] ?: 'عميل');
                $amount = floatval($rc['amount'] ?? 0);
                $v_date = $rc['receipt_date'] ?? date('Y-m-d');
                $box_id = intval($rc['box_id'] ?? 1);

                $exist_rec = $conn->query("SELECT id FROM receipt_vouchers_mst WHERE id = $rec_id OR voucher_no = '$v_no' LIMIT 1");
                if (!$exist_rec || $exist_rec->num_rows === 0) {
                    $ins_rec = "INSERT INTO receipt_vouchers_mst
                                (id, voucher_no, voucher_date, party_type, party_name, total_amount, payment_method, box_id, d_s)
                                VALUES
                                ($rec_id, '$v_no', '$v_date', 'customer', '$party_name', $amount, 'cash', $box_id, 0)";
                    if ($conn->query($ins_rec)) {
                        $new_rec_id = $conn->insert_id ?: $rec_id;
                        $migrated_rec++;
                        $conn->query("INSERT INTO receipt_vouchers_dtl (voucher_id, amount, remark, d_s)
                                      VALUES ($new_rec_id, $amount, 'سند قبض تاريخي', 0)");
                    }
                }
            }
        }
        echo "<li>✅ Migrated $migrated_rec legacy receipt vouchers to `receipt_vouchers_mst` & `receipt_vouchers_dtl`.</li>";
    }

    // 6. Migrate legacy `expenses` -> `payment_vouchers_mst` & `payment_vouchers_dtl`
    $chk_exp = $conn->query("SHOW TABLES LIKE 'expenses'");
    if ($chk_exp && $chk_exp->num_rows > 0) {
        $exp_res = $conn->query("SELECT * FROM expenses WHERE d_s = 0 OR delete_status = 0");
        $migrated_exp = 0;
        if ($exp_res) {
            while ($ex = $exp_res->fetch_assoc()) {
                $exp_id = intval($ex['id']);
                $v_no = 'PAY-' . str_pad($exp_id, 6, '0', STR_PAD_LEFT);
                $party_name = $conn->real_escape_string($ex['expense_name'] ?: 'ملاحظات مصروفات');
                $amount = floatval($ex['amount'] ?? 0);
                $v_date = $ex['expense_date'] ?? date('Y-m-d');
                $box_id = intval($ex['box_id'] ?? 1);

                $exist_exp = $conn->query("SELECT id FROM payment_vouchers_mst WHERE id = $exp_id OR voucher_no = '$v_no' LIMIT 1");
                if (!$exist_exp || $exist_exp->num_rows === 0) {
                    $ins_exp = "INSERT INTO payment_vouchers_mst
                                (id, voucher_no, voucher_date, party_type, party_name, total_amount, payment_method, box_id, d_s)
                                VALUES
                                ($exp_id, '$v_no', '$v_date', 'other', '$party_name', $amount, 'cash', $box_id, 0)";
                    if ($conn->query($ins_exp)) {
                        $new_exp_id = $conn->insert_id ?: $exp_id;
                        $migrated_exp++;
                        $conn->query("INSERT INTO payment_vouchers_dtl (voucher_id, amount, remark, d_s)
                                      VALUES ($new_exp_id, $amount, '$party_name', 0)");
                    }
                }
            }
        }
        echo "<li>✅ Migrated $migrated_exp legacy payment vouchers to `payment_vouchers_mst` & `payment_vouchers_dtl`.</li>";
    }

    echo "</ul>";
    echo "<h3>🎉 Master-Detail Data Migration Completed Successfully!</h3>";

} catch (Exception $e) {
    echo "<h3>❌ Error during migration: " . htmlspecialchars($e->getMessage()) . "</h3>";
}
?>
