<?php
header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$dir_prefix = '../';
require_once($dir_prefix . 'includes/connect.php');
require_once($dir_prefix . 'app/Services/AccountingService.php');

$input = json_decode(file_get_contents('php://input'), true);
$customer_id = intval($input['customer_id'] ?? 0);
$image_base64 = $input['image_base64'] ?? '';

if ($customer_id <= 0 || empty($image_base64)) {
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

// 2. Get customer details
$res_c = $conn->query("SELECT cust_name, phone FROM customers WHERE cust_id = $customer_id AND d_s = 0 LIMIT 1");
$cust = $res_c ? $res_c->fetch_assoc() : null;
if (!$cust || empty($cust['phone'])) {
    echo json_encode(['status' => 'error', 'message' => 'العميل غير موجود أو لا يوجد رقم هاتف مسجل له']);
    exit;
}

$phone = preg_replace('/[^0-9]/', '', $cust['phone']);
$customer_name = $cust['cust_name'];

// 3. Get customer balance
$balance = \AQNEX\Services\AccountingService::getCustomerBalance($conn, $customer_id);

// 4. Construct message
$msg = "شريكنا العزيز: " . htmlspecialchars($customer_name) . "، مرفق لكم كشف الحساب التفصيلي الخاص بكم. رصيد حسابكم الحالي هو: " . number_format($balance, 2) . " ر.ي. شكراً لتعاملكم معنا.";

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
    CURLOPT_TIMEOUT => 25
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
    echo json_encode(['status' => 'success', 'message' => 'تم إرسال كشف الحساب بنجاح عبر الواتساب']);
} else {
    echo json_encode(['status' => 'error', 'message' => 'بوابة الواتساب أرجعت خطأ: ' . ($response ?: 'استجابة فارغة')]);
}
exit;
