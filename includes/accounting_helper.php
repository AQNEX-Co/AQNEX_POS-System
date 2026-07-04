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
// دالة قراءة رصيد الصندوق الحالي
// ======================================================
if (!function_exists('get_box_balance')) {
    function get_box_balance($conn, $box_id) {
        $box_id = intval($box_id);
        $res = $conn->query("SELECT mony FROM treasury WHERE box_id = $box_id LIMIT 1");
        if ($res && $res->num_rows > 0) {
            return doubleval($res->fetch_assoc()['mony']);
        }
        return 0.0;
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

        if ($type !== 'addition') {
            $current_balance = get_box_balance($conn, $box_id);
            if ($current_balance < $amount) {
                return false;
            }
        }

        // تحديث الرصيد في جدول الصناديق
        if ($type === 'addition') {
            $sql_update = "UPDATE treasury SET mony = mony + $amount WHERE box_id = $box_id";
        } else {
            $sql_update = "UPDATE treasury SET mony = mony - $amount WHERE box_id = $box_id";
        }
        if (!$conn->query($sql_update)) {
            return false;
        }

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
    function post_journal_entry($conn, $ref_type, $ref_id, $debit_acc, $credit_acc, $amount, $desc, $user, $box_id = null, $curr = 'YER', $rate = 1.0, $sector_id = null) {
        return \AQNEX\Services\AccountingService::post(
            $conn, 
            $ref_type, 
            $ref_id, 
            $debit_acc, 
            $credit_acc, 
            $amount, 
            $desc, 
            $user, 
            $box_id, 
            'general', 
            null, 
            'general', 
            null, 
            $curr, 
            $rate, 
            $sector_id
        );
    }
}

// ======================================================
// دالة ترحيل المبيعات النقدية المعلقة إلى الصندوق المالي
// ======================================================
if (!function_exists('transfer_sales_to_box')) {
    function transfer_sales_to_box($conn, $box_id, $user_name) {
        return 0.0;
    }
}
?>
