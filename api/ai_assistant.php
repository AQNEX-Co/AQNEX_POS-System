<?php
header('Content-Type: application/json; charset=utf-8');

$dir_prefix = '../';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once($dir_prefix . "includes/connect.php");
require_once($dir_prefix . "includes/accounting_helper.php");

$global_settings = [];
$res_settings = $conn->query("SELECT * FROM settings WHERE id = 1 LIMIT 1");
if ($res_settings && $res_settings->num_rows > 0) {
    $global_settings = $res_settings->fetch_assoc();
}
// جلب مفتاح Gemini من الإعدادات العامة؛ إن لم يتوفر يتم العمل بالوضع المحلي (أوفلاين) تلقائياً
$api_key = $global_settings['gemini_api_key'] ?? '';

function assistant_db_val($conn, $sql, $field, $default = 0) {
    $res = $conn->query($sql);
    if (!$res || is_bool($res)) return $default;
    $row = $res->fetch_assoc();
    return $row[$field] ?? $default;
}

// =====================================================================
// قاعدة معرفة عامة بصفحات النظام
// =====================================================================
$SITE_HELP_KB = [
    ['keywords' => ['عميل جديد', 'إضافة عميل', 'اضافة عميل', 'تسجيل عميل'],
     'answer' => "لإضافة عميل جديد: من القائمة الجانبية اذهب إلى (العملاء) ثم اضغط (إضافة عميل جديد).",
     'url' => '../customers/create.php'],
    ['keywords' => ['مورد جديد', 'إضافة مورد', 'اضافة مورد', 'تسجيل مورد'],
     'answer' => "لإضافة مورد جديد: اذهب إلى (الموردون) من القائمة الجانبية ثم (إضافة مورد جديد).",
     'url' => '../suppliers/create.php'],
    ['keywords' => ['منتج جديد', 'إضافة منتج', 'اضافة منتج', 'صنف جديد', 'إضافة صنف'],
     'answer' => "لإضافة منتج جديد: من قائمة (المخزون/المنتجات) اضغط (إضافة منتج).",
     'url' => '../products/create.php'],
    ['keywords' => ['تقرير مبيعات', 'تقارير المبيعات', 'تصدير تقرير'],
     'answer' => "يمكنك عرض وتصدير تقارير المبيعات من قائمة (التقارير) > (تقرير المبيعات).",
     'url' => '../reports/sales.php'],
    ['keywords' => ['مرتجع', 'مرتجعات', 'استرجاع فاتورة', 'إلغاء فاتورة'],
     'answer' => "لتسجيل مرتجع مبيعات: افتح الفاتورة الأصلية من سجل المبيعات واضغط (إنشاء مرتجع).",
     'url' => '../sales/returns.php'],
    ['keywords' => ['صلاحيات', 'مستخدم جديد', 'إضافة موظف', 'حساب موظف'],
     'answer' => "لإضافة مستخدم/موظف جديد، اذهب إلى (الإعدادات) > (المستخدمون) > (إضافة مستخدم).",
     'url' => '../settings/users.php'],
];

function match_help_kb($msg_lower, $kb) {
    foreach ($kb as $entry) {
        foreach ($entry['keywords'] as $kw) {
            if (mb_strpos($msg_lower, $kw) !== false) return $entry;
        }
    }
    return null;
}

function build_kb_text($kb) {
    $lines = [];
    foreach ($kb as $entry) {
        $lines[] = "- " . implode('/', $entry['keywords']) . " ⇐ " . $entry['url'] . " | " . $entry['answer'];
    }
    return implode("\n", $lines);
}

// =====================================================================
// دوال مساعدة لجلب معلومات الصندوق النشط للمستخدم الحالي
// =====================================================================
function ai_get_box_context($conn) {
    $user_id = $_SESSION['SESS_MEMBER_ID'] ?? 0;
    $box_id = (function_exists('get_user_box_id')) ? intval(get_user_box_id($conn, $user_id)) : 1;
    if ($box_id <= 0) $box_id = 1;
    $box_name = (function_exists('get_box_name')) ? get_box_name($conn, $box_id) : 'الصندوق الرئيسي';
    return [$box_id, $box_name];
}

function ai_resolve_product($conn, $name) {
    $esc = $conn->real_escape_string(trim($name));
    if ($esc === '') return null;
    $res = $conn->query("SELECT id, name, quantity, sale_price, buy_price FROM products WHERE name LIKE '%$esc%' AND delete_status='0' LIMIT 1");
    if ($res && $res->num_rows > 0) return $res->fetch_assoc();
    return null;
}

// =====================================================================
// فاتورة مبيعات: معاينة + تنفيذ حقيقي
// =====================================================================
function ai_build_sale_preview($conn, $customer_name, $items_input, $paid_amount = null) {
    $resolved = [];
    $not_found = [];
    $grand_total = 0;

    foreach ($items_input as $it) {
        $pname = $it['product_name'] ?? '';
        $qty = max(1, intval($it['qty'] ?? 1));
        $prod = ai_resolve_product($conn, $pname);
        if (!$prod) { $not_found[] = $pname; continue; }
        $price = doubleval($prod['sale_price']);
        $line_total = $price * $qty;
        $grand_total += $line_total;
        $resolved[] = [
            'id' => intval($prod['id']), 'name' => $prod['name'], 'qty' => $qty,
            'price' => $price, 'buy_price' => doubleval($prod['buy_price']),
            'stock' => intval($prod['quantity']), 'line_total' => $line_total
        ];
    }

    if (empty($resolved)) {
        return ['ok' => false, 'error' => "لم أتمكن من العثور على أي من الأصناف المطلوبة: " . implode(', ', $not_found)];
    }

    $paid = ($paid_amount !== null) ? doubleval($paid_amount) : $grand_total;
    if ($paid > $grand_total) $paid = $grand_total;
    if ($paid < 0) $paid = 0;
    $remaining = $grand_total - $paid;

    $lines = [];
    $stock_warning = false;
    foreach ($resolved as $r) {
        $warn = '';
        if ($r['qty'] > $r['stock']) { $warn = " ⚠️ (المتوفر بالمخزن: {$r['stock']} فقط)"; $stock_warning = true; }
        $lines[] = "- {$r['name']} × {$r['qty']} = " . number_format($r['line_total'], 2) . " ر.ي{$warn}";
    }

    $preview = "📋 *معاينة فاتورة مبيعات* (لم تُحفظ بعد):\n" .
               "العميل: {$customer_name}\n" .
               implode("\n", $lines) . "\n" .
               "------------------------\n" .
               "الإجمالي: " . number_format($grand_total, 2) . " ر.ي\n" .
               "المدفوع: " . number_format($paid, 2) . " ر.ي\n" .
               "المتبقي (آجل): " . number_format($remaining, 2) . " ر.ي";

    if (!empty($not_found)) {
        $preview .= "\n\n⚠️ لم يتم العثور على: " . implode(', ', $not_found) . " (لن تُضاف هذه الأصناف للفاتورة)";
    }
    if ($stock_warning) {
        $preview .= "\n⚠️ تنبيه: بعض الكميات المطلوبة تتجاوز المخزون المتاح حالياً.";
    }
    $preview .= "\n\n✅ للتأكيد والحفظ الفعلي اكتب: تأكيد\n❌ للإلغاء اكتب: إلغاء";

    return ['ok' => true, 'preview' => $preview, 'payload' => [
        'customer_name' => $customer_name, 'items' => $resolved,
        'grand_total' => $grand_total, 'paid' => $paid, 'remaining' => $remaining
    ]];
}

function ai_execute_sale($conn, $payload) {
    date_default_timezone_set("Asia/Aden");
    $build_date = date('Y-m-d');
    $customer_name = $conn->real_escape_string($payload['customer_name']);
    list($box_id, $box_name) = ai_get_box_context($conn);
    $user_display = $_SESSION['SESS_FIRST_NAME'] ?? 'مساعد AI';

    $items = $payload['items'];
    $grand_total = $payload['grand_total'];
    $total_paid = $payload['paid'];
    $total_remaining = $payload['remaining'];

    $total_cost = 0;
    foreach ($items as $it) { $total_cost += $it['buy_price'] * $it['qty']; }
    $grand_profit = $grand_total - $total_cost;

    $sql_insert = "INSERT INTO `sales`(`build_date`,`cust_name`,`total`,`prifet`,`remark`,`delete_status`,`currency_code`,`exchange_rate`,`remaining_total`,`box_id`)
                   VALUES ('$build_date','$customer_name','$grand_total','$grand_profit','تم الإنشاء عبر المساعد الذكي',0,'YER',1.0,'$total_remaining',$box_id)";
    if (!$conn->query($sql_insert)) {
        return ['ok' => false, 'error' => 'فشل حفظ الفاتورة: ' . $conn->error];
    }
    $billing_id = $conn->insert_id;

    $paid_ratio = ($grand_total > 0) ? ($total_paid / $grand_total) : 0;
    $n = count($items);
    $allocated_paid = 0;

    foreach ($items as $idx => $it) {
        $p_id = intval($it['id']);
        $qty = intval($it['qty']);
        $price = doubleval($it['price']);
        $line_total = $it['line_total'];

        $line_paid = ($idx === $n - 1) ? max(0, $total_paid - $allocated_paid) : round($line_total * $paid_ratio, 2);
        if ($idx !== $n - 1) $allocated_paid += $line_paid;
        $line_remaining = max(0, $line_total - $line_paid);

        $name_esc = $conn->real_escape_string($it['name']);
        $product_field_val = "$p_id $name_esc";

        $sql_item = "INSERT INTO `sales_items`(`sales_id`,`id`,`cust_name`,`name`,`quantity`,`unit_price`,`bush`,`d`,`dis`,`total`,`all_tot`,`build_date`)
                     VALUES ('$billing_id','$p_id','$customer_name','$product_field_val','$qty','$price','$line_paid',0,'$line_remaining','$line_total','$line_total','$build_date')";
        $conn->query($sql_item);

        $conn->query("UPDATE `products` SET `quantity` = `quantity` - $qty WHERE `id` = $p_id");
        $user_esc = $conn->real_escape_string($user_display);
        $conn->query("INSERT INTO `inventory_log` (`product_id`,`product_name`,`type`,`qty_change`,`new_qty`,`reason`,`user`)
                      SELECT $p_id, name, 'sale', -$qty, quantity, 'عملية بيع عبر المساعد الذكي بفاتورة رقم #$billing_id', '$user_esc' FROM products WHERE id=$p_id");

        if (!empty($customer_name) && $customer_name !== 'عميل نقدي' && $line_remaining > 0) {
            $conn->query("UPDATE `customers` SET `cust_madeen` = `cust_madeen` + $line_remaining WHERE `cust_name` = '$customer_name'");
        }
    }

    if (function_exists('post_journal_entry')) {
        if ($total_paid > 0) {
            post_journal_entry($conn, 'sale', $billing_id, 'الصندوق - ' . $box_name, 'المبيعات', $total_paid, "مبيعات نقدية عبر المساعد الذكي فاتورة #$billing_id - $customer_name", $user_display, $box_id, 'YER', 1.0, null);
        }
        if ($total_remaining > 0) {
            post_journal_entry($conn, 'sale', $billing_id, 'الذمم المدينة - ' . $customer_name, 'المبيعات', $total_remaining, "مبيعات آجل عبر المساعد الذكي فاتورة #$billing_id - $customer_name", $user_display, $box_id, 'YER', 1.0, null);
        }
        if ($total_cost > 0) {
            post_journal_entry($conn, 'sale', $billing_id, 'تكلفة البضاعة المباعة (مصروف)', 'المخزون / البضاعة', $total_cost, "إثبات تكلفة مبيعات فاتورة #$billing_id", $user_display, $box_id, 'YER', 1.0, null);
        }
    }

    return ['ok' => true, 'id' => $billing_id, 'message' =>
        "✅ تم حفظ فاتورة المبيعات رقم #{$billing_id} بنجاح بإجمالي " . number_format($grand_total, 2) . " ر.ي.\nرابط عرض الفاتورة: ../sales/view.php?id={$billing_id}"];
}

// =====================================================================
// فاتورة مشتريات: معاينة + تنفيذ حقيقي (آجل بالكامل على المورد)
// =====================================================================
function ai_build_purchase_preview($conn, $supplier_name, $items_input) {
    $resolved = [];
    $not_found = [];
    $grand_total = 0;

    foreach ($items_input as $it) {
        $pname = $it['product_name'] ?? '';
        $qty = max(1, intval($it['qty'] ?? 1));
        $prod = ai_resolve_product($conn, $pname);
        $price = isset($it['buy_price']) ? doubleval($it['buy_price']) : ($prod ? doubleval($prod['buy_price']) : 0);

        if (!$prod && $price <= 0) { $not_found[] = $pname; continue; }

        $line_total = $price * $qty;
        $grand_total += $line_total;
        $resolved[] = [
            'id' => $prod ? intval($prod['id']) : 0,
            'name' => $prod ? $prod['name'] : $pname,
            'qty' => $qty, 'price' => $price, 'line_total' => $line_total,
            'is_new' => $prod ? false : true
        ];
    }

    if (empty($resolved)) {
        return ['ok' => false, 'error' => "لم أتمكن من تحديد بيانات كافية للأصناف (يلزم سعر شراء إذا كان الصنف جديداً): " . implode(', ', $not_found)];
    }

    $lines = [];
    foreach ($resolved as $r) {
        $lines[] = "- {$r['name']} × {$r['qty']} = " . number_format($r['line_total'], 2) . " ر.ي" . ($r['is_new'] ? " (منتج جديد سيُضاف)" : "");
    }

    $preview = "📋 *معاينة فاتورة مشتريات* (لم تُحفظ بعد):\n" .
               "المورد: {$supplier_name}\n" . implode("\n", $lines) .
               "\n------------------------\nالإجمالي: " . number_format($grand_total, 2) . " ر.ي (آجل بالكامل على المورد)\n\n" .
               "✅ للتأكيد والحفظ الفعلي اكتب: تأكيد\n❌ للإلغاء اكتب: إلغاء";

    return ['ok' => true, 'preview' => $preview, 'payload' => [
        'supplier_name' => $supplier_name, 'items' => $resolved, 'grand_total' => $grand_total
    ]];
}

function ai_execute_purchase($conn, $payload) {
    date_default_timezone_set("Asia/Aden");
    $build_date = date('Y-m-d');
    $supplier_name = $conn->real_escape_string($payload['supplier_name']);
    list($box_id, $box_name) = ai_get_box_context($conn);
    $user_display = $_SESSION['SESS_FIRST_NAME'] ?? 'مساعد AI';
    $grand_total = $payload['grand_total'];

    $supplier_id = 0;
    $res_s = $conn->query("SELECT supp_id FROM suppliers WHERE supp_name = '$supplier_name' LIMIT 1");
    if ($res_s && $res_s->num_rows > 0) $supplier_id = intval($res_s->fetch_assoc()['supp_id']);

    $conn->begin_transaction();

    $sql_insert = "INSERT INTO `purchases`(`date`,`supp_name`,`total`,`remark`,`currency_code`,`exchange_rate`,`box_id`,`remaining_total`)
                   VALUES ('$build_date','$supplier_name','$grand_total','تم الإنشاء عبر المساعد الذكي','YER',1.0,$box_id,'$grand_total')";
    if (!$conn->query($sql_insert)) {
        $conn->rollback();
        return ['ok' => false, 'error' => 'فشل حفظ الفاتورة: ' . $conn->error];
    }
    $billing_id = $conn->insert_id;

    foreach ($payload['items'] as $it) {
        $p_id = intval($it['id']);
        $qty = intval($it['qty']);
        $price = doubleval($it['price']);
        $name_esc = $conn->real_escape_string($it['name']);
        $line_total = $it['line_total'];

        $sql_item = "INSERT INTO `purchase_items`(`purchase_id`,`buys_date`,`supp_name`,`supp_id`,`name`,`quantity`,`buy_price`,`pushtosupp`,`total_d`,`s`)
                     VALUES ('$billing_id','$build_date','$supplier_name',$supplier_id,'$name_esc','$qty','$line_total','0','$line_total',0)";
        if (!$conn->query($sql_item)) {
            $conn->rollback();
            return ['ok' => false, 'error' => 'فشل حفظ بند الفاتورة: ' . $conn->error];
        }

        if ($p_id > 0) {
            $conn->query("UPDATE `products` SET `quantity`=`quantity`+$qty, `buy_price`=$price, `total`=`quantity`*`buy_price` WHERE `id`=$p_id");
            $user_esc = $conn->real_escape_string($user_display);
            $conn->query("INSERT INTO `inventory_log` (`product_id`,`product_name`,`type`,`qty_change`,`new_qty`,`reason`,`user`)
                          SELECT $p_id, name, 'purchase', $qty, quantity, 'عملية شراء عبر المساعد الذكي بفاتورة رقم #$billing_id', '$user_esc' FROM products WHERE id=$p_id");
        } else {
            $res_cat = $conn->query("SELECT catid FROM categories WHERE d_s=0 LIMIT 1");
            $cat_id = ($res_cat && $res_cat->num_rows > 0) ? intval($res_cat->fetch_assoc()['catid']) : 0;
            if ($cat_id <= 0) {
                $conn->query("INSERT INTO categories (name, d_s) VALUES ('عام',0)");
                $cat_id = $conn->insert_id;
            }
            $sale_price_val = $price * 1.25;
            $conn->query("INSERT INTO products (name, quantity, buy_price, sale_price, catid, date, delete_status) VALUES ('$name_esc', $qty, $price, $sale_price_val, $cat_id, NOW(), 0)");
        }
    }

    if (!empty($supplier_name) && $grand_total > 0) {
        $conn->query("UPDATE `suppliers` SET `supp_daain` = `supp_daain` + $grand_total WHERE `supp_name` = '$supplier_name'");
    }

    if (function_exists('post_journal_entry')) {
        post_journal_entry($conn, 'purchase', $billing_id, 'المخزون / البضاعة', 'الذمم الدائنة - ' . $supplier_name, $grand_total, "شراء بضاعة (آجل) عبر المساعد الذكي فاتورة رقم #$billing_id", $user_display, $box_id, 'YER', 1.0, null);
    }

    $conn->commit();
    return ['ok' => true, 'id' => $billing_id, 'message' =>
        "✅ تم حفظ فاتورة المشتريات رقم #{$billing_id} بنجاح بإجمالي " . number_format($grand_total, 2) . " ر.ي (آجل بالكامل للمورد {$supplier_name})."];
}

// =====================================================================
// سند قبض: معاينة + تنفيذ حقيقي
// =====================================================================
function ai_build_receipt_preview($conn, $customer_name, $amount, $note) {
    $amount = doubleval($amount);
    if ($amount <= 0) return ['ok' => false, 'error' => 'يجب تحديد مبلغ أكبر من صفر.'];

    $cust_esc = $conn->real_escape_string($customer_name);
    $resolved_name = $customer_name;
    $debt_info = '';
    $res = $conn->query("SELECT cust_name, cust_madeen FROM customers WHERE cust_name LIKE '%$cust_esc%' LIMIT 1");
    if ($res && $res->num_rows > 0) {
        $row = $res->fetch_assoc();
        $resolved_name = $row['cust_name'];
        $debt_info = "المديونية الحالية: " . number_format($row['cust_madeen'], 2) . " ر.ي";
    } else {
        $debt_info = "⚠️ تنبيه: لم يتم العثور على عميل بهذا الاسم بالضبط، سيُسجَّل السند باسم: {$customer_name}";
    }

    $preview = "📋 *معاينة سند قبض* (لم يُحفظ بعد):\nالعميل: {$resolved_name}\nالمبلغ: " . number_format($amount, 2) . " ر.ي\nالبيان: {$note}\n{$debt_info}\n\n" .
               "✅ للتأكيد والحفظ الفعلي اكتب: تأكيد\n❌ للإلغاء اكتب: إلغاء";

    return ['ok' => true, 'preview' => $preview, 'payload' => ['customer_name' => $resolved_name, 'amount' => $amount, 'note' => $note]];
}

function ai_execute_receipt($conn, $payload) {
    date_default_timezone_set("Asia/Aden");
    $build_date = date('Y-m-d');
    $cust_name = $conn->real_escape_string($payload['customer_name']);
    $price = doubleval($payload['amount']);
    $note = $conn->real_escape_string($payload['note']);
    list($box_id, $box_name) = ai_get_box_context($conn);
    $user_display = $_SESSION['SESS_FIRST_NAME'] ?? 'مساعد AI';

    $sql = "INSERT INTO `receipts`(`q_date`,`cust_name`,`q_price`,`remark`,`total`,`s`,`box_id`) VALUES ('$build_date','$cust_name','$price','$note','$price',0,$box_id)";
    if (!$conn->query($sql)) return ['ok' => false, 'error' => 'فشل حفظ السند: ' . $conn->error];
    $qid = $conn->insert_id;

    $conn->query("UPDATE `customers` SET `cust_madeen` = `cust_madeen` - $price WHERE `cust_name` = '$cust_name'");
    if (function_exists('update_box_balance')) {
        update_box_balance($conn, $box_id, $price, 'addition', "سند قبض رقم #$qid عبر المساعد الذكي - عميل: $cust_name", $build_date);
    }
    if (function_exists('post_journal_entry')) {
        post_journal_entry($conn, 'receipt', $qid, 'الصندوق - ' . $box_name, 'الذمم المدينة - ' . $cust_name, $price, "تحصيل دفعة بسند قبض رقم #$qid عبر المساعد الذكي", $user_display, $box_id);
    }

    return ['ok' => true, 'id' => $qid, 'message' => "✅ تم حفظ سند القبض رقم #{$qid} بنجاح بمبلغ " . number_format($price, 2) . " ر.ي من العميل {$cust_name}."];
}

// =====================================================================
// سند صرف: معاينة + تنفيذ حقيقي
// =====================================================================
function ai_build_expense_preview($conn, $expense_type, $amount, $note) {
    $amount = doubleval($amount);
    if ($amount <= 0) return ['ok' => false, 'error' => 'يجب تحديد مبلغ أكبر من صفر.'];
    $preview = "📋 *معاينة سند صرف* (لم يُحفظ بعد):\nالبند: {$expense_type}\nالمبلغ: " . number_format($amount, 2) . " ر.ي\nالبيان: {$note}\n\n" .
               "✅ للتأكيد والحفظ الفعلي اكتب: تأكيد\n❌ للإلغاء اكتب: إلغاء";
    return ['ok' => true, 'preview' => $preview, 'payload' => ['expense_type' => $expense_type, 'amount' => $amount, 'note' => $note]];
}

function ai_execute_expense($conn, $payload) {
    date_default_timezone_set("Asia/Aden");
    $build_date = date('Y-m-d');
    $expense_type = $conn->real_escape_string($payload['expense_type']);
    $price = doubleval($payload['amount']);
    $note = $conn->real_escape_string($payload['note']);
    list($box_id, $box_name) = ai_get_box_context($conn);
    $user_display = $_SESSION['SESS_FIRST_NAME'] ?? 'مساعد AI';

    $sql = "INSERT INTO `treasury_expenses`(`sdate`,`st`,`sprice`,`sremark`,`tot`,`s`,`box_id`) VALUES ('$build_date','$expense_type','$price','$note','$price',0,$box_id)";
    if (!$conn->query($sql)) return ['ok' => false, 'error' => 'فشل حفظ السند: ' . $conn->error];
    $sid = $conn->insert_id;

    $conn->query("INSERT INTO `expenses`(`m_date`,`sname`,`m_price`,`remark`,`s`) VALUES ('$build_date','$expense_type','$price','$note',0)");
    if (function_exists('update_box_balance')) {
        update_box_balance($conn, $box_id, $price, 'discount', "سند صرف رقم #$sid عبر المساعد الذكي - بند: $expense_type", $build_date);
    }
    if (function_exists('post_journal_entry')) {
        post_journal_entry($conn, 'expense', $sid, 'مصروفات - ' . $expense_type, 'الصندوق - ' . $box_name, $price, "صرف مبلغ بسند صرف رقم #$sid عبر المساعد الذكي", $user_display, $box_id);
    }

    return ['ok' => true, 'id' => $sid, 'message' => "✅ تم حفظ سند الصرف رقم #{$sid} بنجاح بمبلغ " . number_format($price, 2) . " ر.ي لبند: {$expense_type}."];
}

// دالة تنفيذ الإجراء المعلّق حسب نوعه
function ai_build_journal_preview($conn, $debit_acc, $credit_acc, $amount, $description) {
    $amount = doubleval($amount);
    if ($amount <= 0) return ['ok' => false, 'error' => 'يجب تحديد مبلغ أكبر من صفر.'];
    $preview = "📋 *معاينة قيد يومية رسمي* (لم يُحفظ بعد):\n" .
               "الحساب المدين (من حـ/): {$debit_acc}\n" .
               "الحساب الدائن (إلى حـ/): {$credit_acc}\n" .
               "المبلغ: " . number_format($amount, 2) . " ر.ي\n" .
               "البيان: {$description}\n\n" .
               "✅ للتأكيد والحفظ الفعلي اكتب: تأكيد\n" .
               "❌ للإلغاء اكتب: إلغاء";
    return ['ok' => true, 'preview' => $preview, 'payload' => [
        'debit_acc' => $debit_acc, 'credit_acc' => $credit_acc, 'amount' => $amount, 'description' => $description
    ]];
}

function ai_execute_journal($conn, $payload) {
    list($box_id, $box_name) = ai_get_box_context($conn);
    $user_display = $_SESSION['SESS_FIRST_NAME'] ?? 'مساعد AI';
    $debit_acc = $payload['debit_acc'];
    $credit_acc = $payload['credit_acc'];
    $amount = doubleval($payload['amount']);
    $description = $payload['description'];

    if (function_exists('post_journal_entry')) {
        $res = post_journal_entry($conn, 'adjustment', 0, $debit_acc, $credit_acc, $amount, $description, $user_display, $box_id);
        if ($res) {
            return ['ok' => true, 'message' => "✅ تم تسجيل وحفظ القيد المحاسبي المزدوج بنجاح بمبلغ " . number_format($amount, 2) . " ر.ي."];
        }
    }
    return ['ok' => false, 'error' => 'فشل تسجيل القيد المحاسبي المزدوج. تأكد من وجود الحسابات في دليل الحسابات.'];
}

function ai_execute_pending($conn, $pending) {
    switch ($pending['type']) {
        case 'sale': return ai_execute_sale($conn, $pending['payload']);
        case 'purchase': return ai_execute_purchase($conn, $pending['payload']);
        case 'receipt': return ai_execute_receipt($conn, $pending['payload']);
        case 'expense': return ai_execute_expense($conn, $pending['payload']);
        case 'journal': return ai_execute_journal($conn, $pending['payload']);
        default: return ['ok' => false, 'error' => 'نوع عملية غير معروف.'];
    }
}

// =====================================================================
// تعليمات النظام لـ Gemini
// =====================================================================
$kb_text = build_kb_text($SITE_HELP_KB);
$system_instruction = "أنت مساعد ذكي ونظام خبير متكامل في AQNEX POS للمبيعات والمخازن، وتجيب باللغة العربية بأسلوب ودي ومهني ومباشر.

لديك نوعان من الردود:

النوع الأول - تنفيذ عملية عبر أداة (Tool):
إذا طلب المستخدم عمل/تسجيل فاتورة، سند صرف، سند قبض، الاستعلام عن مخزون، خلاصة مالية، جرد المخزن، أو السيولة، رد بكود JSON فقط بالصيغة المحددة أدناه (بدون أي نص قبله أو بعده):

1. check_stock: {\"tool\": \"check_stock\", \"parameters\": {\"product_name\": \"اسم الصنف\"}}
2. get_financial_summary: {\"tool\": \"get_financial_summary\", \"parameters\": {\"period\": \"today\" | \"yesterday\" | \"month\"}}
3. create_sales_invoice_prefill: {\"tool\": \"create_sales_invoice_prefill\", \"parameters\": {\"customer_name\": \"اسم العميل\", \"items\": [{\"product_name\": \"اسم الصنف\", \"qty\": 1, \"price\": 10.0}]}}
4. create_purchase_invoice_prefill: {\"tool\": \"create_purchase_invoice_prefill\", \"parameters\": {\"supplier_name\": \"اسم المورد\", \"items\": [{\"product_name\": \"اسم الصنف\", \"qty\": 1, \"price\": 10.0}]}}
5. create_receipt_voucher_prefill: {\"tool\": \"create_receipt_voucher_prefill\", \"parameters\": {\"customer_name\": \"اسم العميل\", \"amount\": 100.0, \"remark\": \"بيان القبض\"}}
6. create_expense_voucher_prefill: {\"tool\": \"create_expense_voucher_prefill\", \"parameters\": {\"service\": \"البند مثل: كهرباء/ماء/وجبة غداء...\", \"amount\": 100.0, \"remark\": \"بيان الصرف\"}}
7. get_inventory_insights: {\"tool\": \"get_inventory_insights\", \"parameters\": {}}
8. get_treasury_balance: {\"tool\": \"get_treasury_balance\", \"parameters\": {}}
9. navigate_help: {\"tool\": \"navigate_help\", \"parameters\": {\"topic\": \"وصف موجز لما يريده المستخدم\"}}

استخدم navigate_help عندما يسأل المستخدم 'كيف أفعل كذا' أو يطلب التنقل لصفحة معينة، ولديك دليل صفحات النظام أدناه لمساعدتك على الإجابة الصحيحة:
{$kb_text}

النوع الثاني - رد حواري مباشر (نص عادي بدون JSON):
لأي سؤال عام آخر يخص النظام، أو استفسارات عمل عامة (نصائح بيع، شرح مصطلح محاسبي، ...), أجب بنص طبيعي واضح ومفيد مباشرة دون أي صيغة JSON. لا تقل أبداً أنك لا تستطيع المساعدة في سؤال عام؛ بدلاً من ذلك اشرح بأفضل ما تعرفه عن نظام نقاط البيع.

قواعد هامة:
- لا تبتكر JSON عشوائي، استخدم فقط الصيغ المحددة أعلاه وفقط حين تكون العملية مطلوبة فعلاً.
- عند تغذيتك بنتائج استعلام من النظام، صغ رداً نهائياً دافئاً ومهنياً ومبسطاً بدون أي JSON.";

// =====================================================================
// قراءة مدخلات المستخدم
// =====================================================================
$inputData = json_decode(file_get_contents('php://input'), true);
$user_message = trim($inputData['message'] ?? '');

if (empty($user_message)) {
    echo json_encode(["status" => "error", "message" => "رسالة فارغة."], JSON_UNESCAPED_UNICODE);
    exit;
}

$history = $_SESSION['AI_CHAT_HISTORY'] ?? [];
if (count($history) > 10) {
    $history = array_slice($history, -10);
}

// =====================================================================
// الأولوية المطلقة: التحقق من وجود إجراء معلّق بانتظار تأكيد/إلغاء
// هذا الفحص يتم قبل أي استدعاء لـ Gemini حتى يعمل بثقة في كل الحالات
// =====================================================================
$msg_lower_check = mb_strtolower($user_message, 'UTF-8');
if (!empty($_SESSION['AI_PENDING_ACTION'])) {
    $pending = $_SESSION['AI_PENDING_ACTION'];

    if (preg_match('/^(تأكيد|نعم|أكد|اكد|موافق|تم|قم بالحفظ|حفظ)/u', $msg_lower_check)) {
        $result = ai_execute_pending($conn, $pending);
        unset($_SESSION['AI_PENDING_ACTION']);

        $final_text = $result['ok'] ? $result['message'] : ("❌ " . $result['error']);
        $history[] = ["role" => "user", "text" => $user_message];
        $history[] = ["role" => "model", "text" => $final_text];
        $_SESSION['AI_CHAT_HISTORY'] = $history;

        echo json_encode(["status" => "success", "message" => $final_text], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (preg_match('/^(إلغاء|الغاء|لا|تراجع|رفض|كنسل)/u', $msg_lower_check)) {
        unset($_SESSION['AI_PENDING_ACTION']);
        $final_text = "❌ تم إلغاء العملية المعلّقة بدون أي حفظ في قاعدة البيانات.";
        $history[] = ["role" => "user", "text" => $user_message];
        $history[] = ["role" => "model", "text" => $final_text];
        $_SESSION['AI_CHAT_HISTORY'] = $history;
        echo json_encode(["status" => "success", "message" => $final_text], JSON_UNESCAPED_UNICODE);
        exit;
    }
    // أي رسالة أخرى تُعتبر تجاوزاً للعملية المعلّقة، نلغيها ضمنياً ونستمر بالطلب الجديد
    unset($_SESSION['AI_PENDING_ACTION']);
}

function call_gemini($api_key, $prompt, $system_instruction, $chat_history = []) {
    $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=" . urlencode($api_key);
    $contents = [];
    foreach ($chat_history as $hist) {
        $contents[] = ["role" => $hist['role'], "parts" => [["text" => $hist['text']]]];
    }
    $contents[] = ["role" => "user", "parts" => [["text" => $prompt]]];

    $payload = [
        "contents" => $contents,
        "systemInstruction" => ["parts" => [["text" => $system_instruction]]],
        "generationConfig" => ["temperature" => 0.3]
    ];

    $curl = curl_init();
    curl_setopt_array($curl, array(
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => "POST",
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => array("Content-Type: application/json"),
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false
    ));

    $response = curl_exec($curl);
    $err = curl_error($curl);
    curl_close($curl);
    if ($err) return null;
    return json_decode($response, true);
}

$gemini_res = null;
if (!empty($api_key)) {
    $gemini_res = call_gemini($api_key, $user_message, $system_instruction, $history);
}

// =====================================================================
// الوضع الأوفلاين
// =====================================================================
if (empty($api_key) || !$gemini_res) {
    $msg_lower = mb_strtolower(trim($user_message), 'UTF-8');
    $text_response = null;

    $kb_match = match_help_kb($msg_lower, $SITE_HELP_KB);
    if ($kb_match) {
        $text_response = "أهلاً بك! :\n" . $kb_match['answer'] . "\n\n👉 [الانتقال للصفحة](" . $kb_match['url'] . ")";
    }

    // 1. فاتورة بيع / مبيعات (Sales Invoice)
    if ($text_response === null && preg_match('/(فاتورة بيع|فاتورة مبيعات|بيع لـ|بيع للعميل|بيع)/ui', $msg_lower)) {
        // Extract customer name
        $customer_name = 'عميل نقدي';
        if (preg_match('/(?:للعميل|العميل|باسم|بإسم|لـ)\s+([^\s،,:\(\)]+)/ui', $user_message, $m)) {
            $customer_name = trim($m[1]);
        }
        
        // Extract items using database product lookup
        $items = [];
        $res_p = $conn->query("SELECT name, buy_price, sale_price FROM products WHERE delete_status=0");
        if ($res_p) {
            while ($p = $res_p->fetch_assoc()) {
                $escaped_name = preg_quote($p['name'], '/');
                if (preg_match('/' . $escaped_name . '/ui', $user_message)) {
                    $qty = 1;
                    // Check if a number is before or after the product name
                    if (preg_match('/(\d+)\s*(?:حبة|قطع|قطعة|حبات|كمية)?\s*' . $escaped_name . '/ui', $user_message, $m_qty)) {
                        $qty = max(1, intval($m_qty[1]));
                    } elseif (preg_match('/' . $escaped_name . '\s*(?:كمية|بعدد|عدد)?\s*(\d+)/ui', $user_message, $m_qty)) {
                        $qty = max(1, intval($m_qty[1]));
                    }
                    $items[] = [
                        'product_name' => $p['name'],
                        'qty' => $qty,
                        'price' => doubleval($p['sale_price'])
                    ];
                }
            }
        }
        
        if (!empty($items)) {
            // Check paid amount: e.g. "دفع 500" or "مدفوع 1000"
            $paid_amount = null;
            if (preg_match('/(?:دفع|مدفوع|سدد)\s*(\d+)/ui', $user_message, $m_paid)) {
                $paid_amount = doubleval($m_paid[1]);
            }
            
            $r = ai_build_sale_preview($conn, $customer_name, $items, $paid_amount);
            if ($r['ok']) {
                $_SESSION['AI_PENDING_ACTION'] = ['type' => 'sale', 'payload' => $r['payload']];
                $text_response = $r['preview'];
            } else {
                $text_response = "❌ " . $r['error'];
            }
        }
    }

    // 2. فاتورة مشتريات (Purchase Invoice)
    if ($text_response === null && preg_match('/(فاتورة مشتريات|فاتورة شراء|مشتريات|شراء من|شراء)/ui', $msg_lower)) {
        // Extract supplier name
        $supplier_name = 'مورد افتراضي';
        if (preg_match('/(?:من المورد|المورد|باسم|بإسم|من)\s+([^\s،,:\(\)]+)/ui', $user_message, $m)) {
            $supplier_name = trim($m[1]);
        }
        
        $items = [];
        // Match existing products first
        $res_p = $conn->query("SELECT name, buy_price FROM products WHERE delete_status=0");
        if ($res_p) {
            while ($p = $res_p->fetch_assoc()) {
                $escaped_name = preg_quote($p['name'], '/');
                if (preg_match('/' . $escaped_name . '/ui', $user_message)) {
                    $qty = 1;
                    if (preg_match('/(\d+)\s*(?:حبة|قطع|قطعة|حبات|كمية)?\s*' . $escaped_name . '/ui', $user_message, $m_qty)) {
                        $qty = max(1, intval($m_qty[1]));
                    } elseif (preg_match('/' . $escaped_name . '\s*(?:كمية|بعدد|عدد)?\s*(\d+)/ui', $user_message, $m_qty)) {
                        $qty = max(1, intval($m_qty[1]));
                    }
                    
                    // Custom buy price: e.g. "بسعر 500"
                    $buy_price = doubleval($p['buy_price']);
                    if (preg_match('/' . $escaped_name . '.*?(?:بسعر|سعر|قيمة)\s*(\d+)/ui', $user_message, $m_pr)) {
                        $buy_price = doubleval($m_pr[1]);
                    }
                    
                    $items[] = [
                        'product_name' => $p['name'],
                        'qty' => $qty,
                        'price' => $buy_price
                    ];
                }
            }
        }
        
        // Match new product if no existing product matched
        if (empty($items)) {
            if (preg_match('/(?:شراء|صنف)\s+([^\d\s]+(?:\s+[^\d\s]+)*)\s+(\d+)\s*(?:حبة|قطع|قطعة)?\s*(?:بسعر|سعر|بقيمة|قيمة)?\s*(\d+)/ui', $user_message, $m_new)) {
                $items[] = [
                    'product_name' => trim($m_new[1]),
                    'qty' => intval($m_new[2]),
                    'price' => doubleval($m_new[3])
                ];
            }
        }
        
        if (!empty($items)) {
            $r = ai_build_purchase_preview($conn, $supplier_name, $items);
            if ($r['ok']) {
                $_SESSION['AI_PENDING_ACTION'] = ['type' => 'purchase', 'payload' => $r['payload']];
                $text_response = $r['preview'];
            } else {
                $text_response = "❌ " . $r['error'];
            }
        }
    }

    // 3. قيد يومية رسمي (Journal Entry)
    if ($text_response === null && preg_match('/(قيد|قيد يومية|قيد محاسبي|سجل قيد)/ui', $msg_lower)) {
        $debit_acc = '';
        $credit_acc = '';
        $amount = 0;
        $description = 'تسوية قيد يومية عام';
        
        // Parse debit account: e.g. "من حساب الصندوق" or "مدين الصندوق"
        if (preg_match('/(?:من حساب|من حـ\/|مدين|من)\s+([^\s،,:\-\>]+)/ui', $user_message, $m_deb)) {
            $debit_acc = trim($m_deb[1]);
        }
        // Parse credit account: e.g. "إلى حساب المبيعات" or "دائن المبيعات"
        if (preg_match('/(?:إلى حساب|الى حساب|إلى حـ\/|الى حـ\/|دائن|إلى|الى)\s+([^\s،,:\-\>]+)/ui', $user_message, $m_crd)) {
            $credit_acc = trim($m_crd[1]);
        }
        // Parse amount
        if (preg_match('/(?:بمبلغ|مبلغ|بقيمة|قيمة)?\s*(\d+)/ui', $msg_lower, $m_amt)) {
            $amount = doubleval($m_amt[1]);
        }
        // Parse description
        if (preg_match('/(?:بيان|ملاحظة|ملاحظات|سبب|لأجل|عن|شرح)\s+([^\s،,]+)/ui', $user_message, $m_rem)) {
            $description = trim($m_rem[1]);
        }
        
        if (!empty($debit_acc) && !empty($credit_acc) && $amount > 0) {
            $r = ai_build_journal_preview($conn, $debit_acc, $credit_acc, $amount, $description);
            if ($r['ok']) {
                $_SESSION['AI_PENDING_ACTION'] = ['type' => 'journal', 'payload' => $r['payload']];
                $text_response = $r['preview'];
            } else {
                $text_response = "❌ " . $r['error'];
            }
        }
    }

    // 4. سند قبض (Receipt Voucher)
    if ($text_response === null && preg_match('/(سند قبض|سندقبض|قبض|استلمت|تحصيل|مقبوضات)/ui', $msg_lower)) {
        $cust_name = "عميل افتراضي";
        if (preg_match('/(?:من العميل|العميل|باسم|بإسم|من|للعميل)\s+([^\s،,]+)/ui', $user_message, $m)) {
            $cust_name = trim($m[1]);
        }
        $amount = 0;
        if (preg_match('/(?:بمبلغ|مبلغ|بقيمة|قيمة)?\s*(\d+)/ui', $msg_lower, $m_amt)) {
            $amount = doubleval($m_amt[1]);
        }
        $remark = "دفعة من الحساب";
        if (preg_match('/(?:بيان|ملاحظة|ملاحظات|سبب|لأجل|عن)\s+([^\s،,]+)/ui', $user_message, $m_rem)) {
            $remark = trim($m_rem[1]);
        }
        
        if ($amount > 0) {
            $r = ai_build_receipt_preview($conn, $cust_name, $amount, $remark);
            if ($r['ok']) {
                $_SESSION['AI_PENDING_ACTION'] = ['type' => 'receipt', 'payload' => $r['payload']];
                $text_response = $r['preview'];
            } else {
                $text_response = "❌ " . $r['error'];
            }
        }
    }

    // 5. سند صرف (Expense Voucher)
    if ($text_response === null && preg_match('/(سند صرف|سندصرف|صرف|مصروف|مصاريف|دفع)/ui', $msg_lower)) {
        $expense_type = "أخرى";
        $valid_services = ['وجبة فطور', 'وجبة غداء', 'وجبة عشاء', 'رواتب', 'أجور', 'كهرباء', 'ماء', 'إيجار', 'تلفون', 'إنترنت', 'خاصة'];
        foreach ($valid_services as $vs) {
            if (mb_strpos($msg_lower, $vs) !== false) {
                $expense_type = $vs;
                break;
            }
        }
        $amount = 0;
        if (preg_match('/(?:بمبلغ|مبلغ|بقيمة|قيمة)?\s*(\d+)/ui', $msg_lower, $m_amt)) {
            $amount = doubleval($m_amt[1]);
        }
        $remark = "مصروفات تشغيلية";
        if (preg_match('/(?:بيان|ملاحظة|ملاحظات|سبب|لأجل|عن)\s+([^\s،,]+)/ui', $user_message, $m_rem)) {
            $remark = trim($m_rem[1]);
        }
        
        if ($amount > 0) {
            $r = ai_build_expense_preview($conn, $expense_type, $amount, $remark);
            if ($r['ok']) {
                $_SESSION['AI_PENDING_ACTION'] = ['type' => 'expense', 'payload' => $r['payload']];
                $text_response = $r['preview'];
            } else {
                $text_response = "❌ " . $r['error'];
            }
        }
    }

    // 6. استفسار عن المخزون
    if ($text_response === null && preg_match('/(مخزون|متوفر|كمية|سعر صنف)\s+([^\s،,]+)/u', $msg_lower, $matches)) {
        $p_name = $conn->real_escape_string($matches[2]);
        $res = $conn->query("SELECT name, quantity, sale_price FROM products WHERE name LIKE '%$p_name%' AND delete_status='0' LIMIT 5");
        $data_result = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $data_result[] = "المنتج: {$row['name']} | الكمية المتاحة: {$row['quantity']} | سعر البيع: " . number_format($row['sale_price'], 2) . " ر.ي";
            }
        }
        $text_response = empty($data_result) ? "أهلاً بك! : لم أتمكن من العثور على صنف يطابق '{$p_name}'." : "أهلاً بك! : نتائج البحث:\n" . implode("\n", $data_result);
    }
    // 7. خلاصة مالية
    elseif ($text_response === null && preg_match('/(أرباح|ارباح|خلاصة|الخلاصة|مبيعات|المبيعات|مصاريف|المصاريف|التدفق|التقرير المالي)/u', $msg_lower)) {
        $period = 'today';
        if (preg_match('/(أمس|امس|البارحة|سابقة|سابق)/u', $msg_lower)) $period = 'yesterday';
        elseif (preg_match('/(شهر|شهري)/u', $msg_lower)) $period = 'month';

        if ($period === 'month') {
            $m_start = date('Y-m-01'); $m_end = date('Y-m-t');
            $sales = assistant_db_val($conn, "SELECT SUM(total) as v FROM sales WHERE (build_date BETWEEN '$m_start' AND '$m_end') AND delete_status=0", 'v');
            $profit = assistant_db_val($conn, "SELECT SUM(prifet) as v FROM sales WHERE (build_date BETWEEN '$m_start' AND '$m_end') AND delete_status=0", 'v');
            $expenses = assistant_db_val($conn, "SELECT SUM(sprice) as v FROM treasury_expenses WHERE sdate BETWEEN '$m_start' AND '$m_end'", 'v');
        } else {
            $target_date = ($period === 'yesterday') ? date('Y-m-d', strtotime('-1 day')) : date('Y-m-d');
            $sales = assistant_db_val($conn, "SELECT SUM(total) as v FROM sales WHERE build_date='$target_date' AND delete_status=0", 'v');
            $profit = assistant_db_val($conn, "SELECT SUM(prifet) as v FROM sales WHERE build_date='$target_date' AND delete_status=0", 'v');
            $expenses = assistant_db_val($conn, "SELECT SUM(sprice) as v FROM treasury_expenses WHERE sdate='$target_date'", 'v');
        }
        $text_response = "أهلاً بك! :\nملخص الفترة ({$period}):\n- المبيعات: " . number_format($sales, 2) . " ر.ي\n- الأرباح: " . number_format($profit, 2) . " ر.ي\n- المصاريف: " . number_format($expenses, 2) . " ر.ي";
    }
    // 8. سيولة الصناديق
    elseif ($text_response === null && preg_match('/(سيولة|الصناديق|صندوق|خزينة|كاش|الخزنة)/u', $msg_lower)) {
        $res = $conn->query("SELECT SUM(mony) as total_mony FROM treasury");
        $total_mony = $res ? floatval($res->fetch_assoc()['total_mony']) : 0.0;
        $res_details = $conn->query("SELECT name, mony FROM treasury WHERE is_active = 1");
        $details = [];
        if ($res_details) while ($row = $res_details->fetch_assoc()) $details[] = "- {$row['name']}: " . number_format($row['mony'], 2) . " ر.ي";
        $details_str = empty($details) ? "لا توجد صناديق نشطة." : implode("\n", $details);
        $text_response = "أهلاً بك! :\nالرصيد الكلي: " . number_format($total_mony, 2) . " ر.ي\n\n{$details_str}";
    }

    if ($text_response === null) {
        $text_response = "أهلاً بك! يمكنني مساعدتك محلياً في:\n" .
                         "1. فحص مخزون صنف (مثال: 'مخزون آيفون')\n" .
                         "2. خلاصة الأرباح والمبيعات (مثال: 'خلاصة أرباح اليوم')\n" .
                         "3. السيولة المالية ورصيد الصناديق (مثال: 'رصيد الصناديق')\n" .
                         "4. إنشاء فاتورة مبيعات فعلية (مثال: 'فاتورة بيع للعميل أحمد صنف شاحن كمية 2')\n" .
                         "5. إنشاء فاتورة مشتريات فعلية (مثال: 'فاتورة شراء من المورد محمد صنف بطارية كمية 5 بسعر 1000')\n" .
                         "6. تسجيل قيد يومية رسمي مزدوج (مثال: 'سجل قيد من الصندوق الرئيسي إلى المبيعات بقيمة 5000 بيان تسوية')\n" .
                         "7. سند قبض (مثال: 'سند قبض من العميل أحمد بمبلغ 5000')\n" .
                         "8. سند صرف (مثال: 'سند صرف رواتب بمبلغ 1000')\n" .
                         "9. شرح استخدام صفحات النظام (مثال: 'كيف أضيف عميل جديد؟')";
    }

    $history[] = ["role" => "user", "text" => $user_message];
    $history[] = ["role" => "model", "text" => $text_response];
    $_SESSION['AI_CHAT_HISTORY'] = $history;
    echo json_encode(["status" => "success", "message" => $text_response], JSON_UNESCAPED_UNICODE);
    exit;
}

// =====================================================================
// معالجة رد Gemini (وضع الاتصال)
// =====================================================================
$text_response = $gemini_res['candidates'][0]['content']['parts'][0]['text'] ?? '';
$json_start = strpos($text_response, '{');
$json_end = strrpos($text_response, '}');

if ($json_start !== false && $json_end !== false && $json_end > $json_start) {
    $json_str = substr($text_response, $json_start, $json_end - $json_start + 1);
    $tool_call = json_decode($json_str, true);

    if (isset($tool_call['tool'])) {
        $tool = $tool_call['tool'];
        $params = $tool_call['parameters'] ?? [];
        $data_result = null;

        // ===== أدوات إنشاء فواتير/سندات: معاينة فقط ثم خروج مباشر بدون إعادة صياغة من Gemini =====
        if ($tool === 'create_sale_invoice') {
            $r = ai_build_sale_preview($conn, $params['customer_name'] ?? 'عميل نقدي', $params['items'] ?? [], $params['paid_amount'] ?? null);
            $final_msg = $r['ok'] ? $r['preview'] : ("❌ " . $r['error']);
            if ($r['ok']) $_SESSION['AI_PENDING_ACTION'] = ['type' => 'sale', 'payload' => $r['payload']];

            $history[] = ["role" => "user", "text" => $user_message];
            $history[] = ["role" => "model", "text" => $final_msg];
            $_SESSION['AI_CHAT_HISTORY'] = $history;
            echo json_encode(["status" => "success", "message" => $final_msg], JSON_UNESCAPED_UNICODE);
            exit;
        }
        elseif ($tool === 'create_purchase_invoice') {
            $r = ai_build_purchase_preview($conn, $params['supplier_name'] ?? 'مورد افتراضي', $params['items'] ?? []);
            $final_msg = $r['ok'] ? $r['preview'] : ("❌ " . $r['error']);
            if ($r['ok']) $_SESSION['AI_PENDING_ACTION'] = ['type' => 'purchase', 'payload' => $r['payload']];

            $history[] = ["role" => "user", "text" => $user_message];
            $history[] = ["role" => "model", "text" => $final_msg];
            $_SESSION['AI_CHAT_HISTORY'] = $history;
            echo json_encode(["status" => "success", "message" => $final_msg], JSON_UNESCAPED_UNICODE);
            exit;
        }
        elseif ($tool === 'create_receipt') {
            $r = ai_build_receipt_preview($conn, $params['customer_name'] ?? '', $params['amount'] ?? 0, $params['note'] ?? 'دفعة من الحساب');
            $final_msg = $r['ok'] ? $r['preview'] : ("❌ " . $r['error']);
            if ($r['ok']) $_SESSION['AI_PENDING_ACTION'] = ['type' => 'receipt', 'payload' => $r['payload']];

            $history[] = ["role" => "user", "text" => $user_message];
            $history[] = ["role" => "model", "text" => $final_msg];
            $_SESSION['AI_CHAT_HISTORY'] = $history;
            echo json_encode(["status" => "success", "message" => $final_msg], JSON_UNESCAPED_UNICODE);
            exit;
        }
        elseif ($tool === 'create_expense') {
            $r = ai_build_expense_preview($conn, $params['expense_type'] ?? 'أخرى', $params['amount'] ?? 0, $params['note'] ?? 'صرف');
            $final_msg = $r['ok'] ? $r['preview'] : ("❌ " . $r['error']);
            if ($r['ok']) $_SESSION['AI_PENDING_ACTION'] = ['type' => 'expense', 'payload' => $r['payload']];

            $history[] = ["role" => "user", "text" => $user_message];
            $history[] = ["role" => "model", "text" => $final_msg];
            $_SESSION['AI_CHAT_HISTORY'] = $history;
            echo json_encode(["status" => "success", "message" => $final_msg], JSON_UNESCAPED_UNICODE);
            exit;
        }
        // ===== الأدوات الاستعلامية القديمة (تذهب لـ Gemini لصياغة الرد) =====
        elseif ($tool === 'check_stock') {
            $p_name = $conn->real_escape_string($params['product_name'] ?? '');
            $res = $conn->query("SELECT name, quantity, sale_price FROM products WHERE name LIKE '%$p_name%' AND delete_status='0' LIMIT 5");
            $data_result = [];
            if ($res) while ($row = $res->fetch_assoc()) $data_result[] = "المنتج: {$row['name']} | الكمية: {$row['quantity']} | السعر: " . number_format($row['sale_price'], 2) . " ر.ي";
            $data_result = empty($data_result) ? "لم يتم العثور على منتج يطابق '{$p_name}'." : implode("\n", $data_result);
        }
        elseif ($tool === 'get_financial_summary') {
            $period = $params['period'] ?? 'today';
            if ($period === 'month') {
                $m_start = date('Y-m-01'); $m_end = date('Y-m-t');
                $sales = assistant_db_val($conn, "SELECT SUM(total) as v FROM sales WHERE (build_date BETWEEN '$m_start' AND '$m_end') AND delete_status=0", 'v');
                $profit = assistant_db_val($conn, "SELECT SUM(prifet) as v FROM sales WHERE (build_date BETWEEN '$m_start' AND '$m_end') AND delete_status=0", 'v');
                $expenses = assistant_db_val($conn, "SELECT SUM(sprice) as v FROM treasury_expenses WHERE sdate BETWEEN '$m_start' AND '$m_end'", 'v');
            } else {
                $target_date = ($period === 'yesterday') ? date('Y-m-d', strtotime('-1 day')) : date('Y-m-d');
                $sales = assistant_db_val($conn, "SELECT SUM(total) as v FROM sales WHERE build_date='$target_date' AND delete_status=0", 'v');
                $profit = assistant_db_val($conn, "SELECT SUM(prifet) as v FROM sales WHERE build_date='$target_date' AND delete_status=0", 'v');
                $expenses = assistant_db_val($conn, "SELECT SUM(sprice) as v FROM treasury_expenses WHERE sdate='$target_date'", 'v');
            }
            $data_result = "ملخص ({$period}):\n- المبيعات: " . number_format($sales, 2) . " ر.ي\n- الأرباح: " . number_format($profit, 2) . " ر.ي\n- المصاريف: " . number_format($expenses, 2) . " ر.ي";
        }
        elseif ($tool === 'get_inventory_insights') {
            $tot_p = assistant_db_val($conn, "SELECT COUNT(*) as v FROM products WHERE delete_status=0", 'v');
            $val_p = assistant_db_val($conn, "SELECT SUM(total) as v FROM products WHERE delete_status=0", 'v');
            $low_threshold = 5;
            $low_p = assistant_db_val($conn, "SELECT COUNT(*) as v FROM products WHERE delete_status=0 AND quantity > 0 AND quantity <= $low_threshold", 'v');
            $out_p = assistant_db_val($conn, "SELECT COUNT(*) as v FROM products WHERE delete_status=0 AND quantity = 0", 'v');
            $res_low_list = $conn->query("SELECT name, quantity FROM products WHERE delete_status=0 AND quantity <= $low_threshold ORDER BY quantity ASC LIMIT 10");
            $low_items = [];
            if ($res_low_list) while ($l = $res_low_list->fetch_assoc()) $low_items[] = "- {$l['name']} (الكمية: {$l['quantity']})";
            $low_items_str = empty($low_items) ? "لا توجد أصناف منخفضة." : implode("\n", $low_items);
            $data_result = "إجمالي الأصناف: {$tot_p}\nالقيمة: " . number_format($val_p, 2) . " ر.ي\nمقاربة على النفاد: {$low_p}\nنافذة: {$out_p}\n\nتحتاج إعادة طلب:\n{$low_items_str}";
        }
        elseif ($tool === 'get_treasury_balance') {
            $res = $conn->query("SELECT SUM(mony) as total_mony FROM treasury");
            $total_mony = $res ? floatval($res->fetch_assoc()['total_mony']) : 0.0;
            $res_details = $conn->query("SELECT name, mony FROM treasury WHERE is_active = 1");
            $details = [];
            if ($res_details) while ($row = $res_details->fetch_assoc()) $details[] = "- {$row['name']}: " . number_format($row['mony'], 2) . " ر.ي";
            $details_str = empty($details) ? "لا توجد صناديق نشطة." : implode("\n", $details);
            $data_result = "الرصيد الكلي: " . number_format($total_mony, 2) . " ر.ي\n\n{$details_str}";
        }
        elseif ($tool === 'navigate_help') {
            $topic = mb_strtolower($params['topic'] ?? '', 'UTF-8');
            $kb_match = match_help_kb($topic, $SITE_HELP_KB);
            $data_result = $kb_match ? ($kb_match['answer'] . "\nرابط الصفحة: " . $kb_match['url']) : "لا توجد صفحة مخصصة، أجب بمعرفتك العامة عن نظام نقاط البيع.";
        }

        $tool_prompt = "تم تنفيذ استعلام النظام بنجاح. بيانات النتيجة:\n=== START SYSTEM DATA ===\n{$data_result}\n=== END SYSTEM DATA ===\nصغ رداً نهائياً واضحاً وودياً بناءً على هذه البيانات.";

        $history[] = ["role" => "user", "text" => $user_message];
        $final_res = call_gemini($api_key, $tool_prompt, $system_instruction, $history);
        $final_text = $final_res['candidates'][0]['content']['parts'][0]['text'] ?? 'حدث خطأ في صياغة الرد.';

        $history[] = ["role" => "model", "text" => $final_text];
        $_SESSION['AI_CHAT_HISTORY'] = $history;
        echo json_encode(["status" => "success", "message" => $final_text], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$history[] = ["role" => "user", "text" => $user_message];
$history[] = ["role" => "model", "text" => $text_response];
$_SESSION['AI_CHAT_HISTORY'] = $history;
echo json_encode(["status" => "success", "message" => $text_response], JSON_UNESCAPED_UNICODE);
exit;