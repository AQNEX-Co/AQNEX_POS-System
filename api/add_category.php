<?php
require_once '../includes/connect.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'طريقة طلب غير صالحة']);
    exit;
}

$name = trim($_POST['name'] ?? '');

if (empty($name)) {
    echo json_encode(['status' => 'error', 'message' => 'اسم المجموعة لا يمكن أن يكون فارغاً']);
    exit;
}

// التحقق من تكرار الاسم
$name_esc = $conn->real_escape_string($name);
$check = $conn->query("SELECT catid FROM categories WHERE name = '$name_esc' AND d_s = 0 LIMIT 1");
if ($check && $check->num_rows > 0) {
    $row = $check->fetch_assoc();
    echo json_encode(['status' => 'success', 'id' => $row['catid'], 'name' => $name]);
    exit;
}

// الإدراج في قاعدة البيانات
$insert = $conn->query("INSERT INTO categories (name, d_s) VALUES ('$name_esc', 0)");
if ($insert) {
    $new_id = $conn->insert_id;
    echo json_encode(['status' => 'success', 'id' => $new_id, 'name' => $name]);
} else {
    echo json_encode(['status' => 'error', 'message' => 'فشلت إضافة المجموعة في قاعدة البيانات']);
}
exit;
