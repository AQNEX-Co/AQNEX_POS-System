<?php
header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$dir_prefix = '../';
require_once($dir_prefix . 'includes/connect.php');
require_once($dir_prefix . 'app/Services/AccountingService.php');

$input = json_decode(file_get_contents('php://input'), true);
$invoice_id = intval($input['invoice_id'] ?? 0);
$type = $input['type'] ?? 'sale';
$image_base64 = $input['image_base64'] ?? '';

if ($invoice_id <= 0 || empty($image_base64)) {
    echo json_encode(['status' => 'error', 'message' => 'بيانات ناقصة']);
    exit;
}

// 1. Get settings
$settings_res = $conn->query("SELECT * FROM settings WHERE id = 1 LIMIT 1");
$settings = $settings_res ? $settings_res->fetch_assoc() : null;
if (!$settings || intval($settings['whatsapp_enabled'] ?? 0) !== 1) {
    echo json_encode(['status' => 'error', 'message' => 'ميزة الواتساب غير مفعلة']);
    exit;
}

$token = $settings['whatsapp_token'] ?? '';
$instanceId = $settings['whatsapp_instance'] ?? '';
if (empty($token) || empty($instanceId)) {
    echo json_encode(['status' => 'error', 'message' => 'إعدادات الواتساب غير مكتملة']);
    exit;
}

// 2. Get invoice and customer
$res = $conn->query("SELECT cust_name, total FROM sales WHERE id = $invoice_id");
$sale = $res ? $res->fetch_assoc() : null;
if (!$sale) {
    echo json_encode(['status' => 'error', 'message' => 'الفاتورة غير موجودة']);
    exit;
}

$customer_name = $sale['cust_name'];
$total = doubleval($sale['total']);

if (empty($customer_name) || $customer_name === 'عميل نقدي') {
    echo json_encode(['status' => 'error', 'message' => 'لا يمكن إرسال رسالة لعميل نقدي']);
    exit;
}

$cust_esc = $conn->real_escape_string($customer_name);
$res_c = $conn->query("SELECT cust_id, phone FROM customers WHERE cust_name = '$cust_esc' AND d_s = 0 LIMIT 1");
$cust = $res_c ? $res_c->fetch_assoc() : null;
if (!$cust || empty($cust['phone'])) {
    echo json_encode(['status' => 'error', 'message' => 'لا يوجد رقم هاتف مسجل للعميل']);
    exit;
}

$phone = preg_replace('/[^0-9]/', '', $cust['phone']);
$cust_id = intval($cust['cust_id']);

// 3. Get customer balance
$balance = \AQNEX\Services\AccountingService::getCustomerBalance($conn, $cust_id);

// 4. Construct message
if ($type === 'return') {
    $msg = "شريكنا العزيز، تم تسجيل مرتجع مبيعات للفاتورة رقم #{$invoice_id} باسمكم. رصيد حسابكم الحالي هو: " . number_format($balance, 2) . " ر.ي. شكراً لتعاملكم معنا.";
} else {
    $msg = "شريكنا العزيز، تم تسجيل فاتورة مبيعات جديدة رقم #{$invoice_id} باسمكم بمبلغ: " . number_format($total, 2) . " ر.ي. رصيد حسابكم الحالي هو: " . number_format($balance, 2) . " ر.ي. شكراً لتعاملكم معنا.";
}

// 5. Send via UltraMsg
$params = [
    'token' => $token,
    'to' => $phone,
    'image' => $image_base64,
    'caption' => $msg
];

$curl = curl_init();
curl_setopt_array($curl, [
    CURLOPT_URL => "https://api.ultramsg.com/{$instanceId}/messages/image",
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST => "POST",
    CURLOPT_POSTFIELDS => http_build_query($params),
    CURLOPT_HTTPHEADER => ["content-type: application/x-www-form-urlencoded"],
    CURLOPT_TIMEOUT => 20
]);

$response = curl_exec($curl);
$err = curl_error($curl);
curl_close($curl);

if ($err) {
    echo json_encode(['status' => 'error', 'message' => 'فشل في الاتصال ببوابة الواتساب: ' . $err]);
    exit;
}

$resDecoded = json_decode($response, true);
if (isset($resDecoded['sent']) && $resDecoded['sent'] == 'true') {
    echo json_encode(['status' => 'success', 'message' => 'تم إرسال الفاتورة والرسالة بنجاح عبر الواتساب']);
} else {
    echo json_encode(['status' => 'error', 'message' => 'بوابة الواتساب أرجعت خطأ: ' . ($response ?: 'استجابة فارغة')]);
}
exit;
