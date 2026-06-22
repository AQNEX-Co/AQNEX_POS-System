<?php
// ======================================================
// دالة الحصول على معرف صندوق المستخدم الحالي
// ======================================================
if (!function_exists('get_user_box_id')) {
    function get_user_box_id($conn, $user_id) {
        $user_id = intval($user_id);
        $res = $conn->query("SELECT box_id FROM treasury WHERE user_id = $user_id AND is_active = 1 LIMIT 1");
        if ($res && $res->num_rows > 0) {
            return intval($res->fetch_assoc()['box_id']);
        }
        return 1; // الافتراضي هو الصندوق الرئيسي
    }
}

// ======================================================
// دالة الحصول على اسم الصندوق المالي
// ======================================================
if (!function_exists('get_box_name')) {
    function get_box_name($conn, $box_id) {
        $box_id = intval($box_id);
        $res = $conn->query("SELECT name FROM treasury WHERE box_id = $box_id LIMIT 1");
        if ($res && $res->num_rows > 0) {
            return $res->fetch_assoc()['name'];
        }
        return 'الصندوق الرئيسي';
    }
}

// ======================================================
// دالة تحديث رصيد الصندوق وتسجيل المعاملة التاريخية
// ======================================================
if (!function_exists('update_box_balance')) {
    function update_box_balance($conn, $box_id, $amount, $type, $remark, $date) {
        $box_id = intval($box_id);
        $amount = doubleval($amount);
        $type = trim($type); // 'addition' (إيداع/مقبوضات) أو 'discount' (سحب/مصاريف)
        $remark = $conn->real_escape_string($remark);
        $date = $conn->real_escape_string($date);

        if ($amount <= 0) return true;

        // تحديث الرصيد في جدول الصناديق
        if ($type === 'addition') {
            $sql_update = "UPDATE treasury SET mony = mony + $amount WHERE box_id = $box_id";
        } else {
            $sql_update = "UPDATE treasury SET mony = mony - $amount WHERE box_id = $box_id";
        }
        $conn->query($sql_update);

        // تسجيل المعاملة في جدول حركات الصناديق
        $statue = ($type === 'addition') ? 'addition' : 'discount';
        $sql_log = "INSERT INTO treasury_transactions (mony, statue, remark, datte, box_id) 
                    VALUES ($amount, '$statue', '$remark', '$date', $box_id)";
        return $conn->query($sql_log);
    }
}

// ======================================================
// دالة تسجيل قيد محاسبي مزدوج (Double-entry Journal Line)
// ======================================================
if (!function_exists('post_journal_entry')) {
    function post_journal_entry($conn, $ref_type, $ref_id, $debit_acc, $credit_acc, $amount, $desc, $user, $box_id = null, $curr = 'YER', $rate = 1.0) {
        $ref_type = $conn->real_escape_string($ref_type);
        $ref_id = intval($ref_id);
        $debit_acc = $conn->real_escape_string($debit_acc);
        $credit_acc = $conn->real_escape_string($credit_acc);
        $amount = doubleval($amount);
        $desc = $conn->real_escape_string($desc);
        $user = $conn->real_escape_string($user);
        $curr = $conn->real_escape_string($curr);
        $rate = doubleval($rate);
        $box_val = ($box_id === null) ? "NULL" : intval($box_id);

        if ($amount == 0) return true;

        $amount_foreign = $amount / ($rate > 0 ? $rate : 1.0);

        $sql = "INSERT INTO accounting_journal 
                (ref_type, ref_id, account_debit, account_credit, amount, description, currency_code, exchange_rate, amount_foreign, user, box_id) 
                VALUES 
                ('$ref_type', $ref_id, '$debit_acc', '$credit_acc', $amount, '$desc', '$curr', $rate, $amount_foreign, '$user', $box_val)";
        return $conn->query($sql);
    }
}
?>
