<?php
header('Content-Type: application/json; charset=utf-8');
require_once('../includes/db.php');

$name = trim($_POST['supp_name'] ?? $_POST['name'] ?? '');
$phone = trim($_POST['phone'] ?? '');

if (empty($name)) {
    echo json_encode(['success' => false, 'message' => 'اسم المورد مطلوب']);
    exit;
}

// Check if supplier already exists
$stmt = $conn->prepare("SELECT supp_id, supp_name FROM suppliers WHERE supp_name = ? AND d_s = 0 LIMIT 1");
$stmt->bind_param("s", $name);
$stmt->execute();
$res = $stmt->get_result();

if ($res && $row = $res->fetch_assoc()) {
    echo json_encode([
        'success' => true,
        'supp_id' => intval($row['supp_id']),
        'supp_name' => $row['supp_name'],
        'message' => 'المورد موجود مسبقاً'
    ]);
    exit;
}

// Insert new supplier
$ins = $conn->prepare("INSERT INTO suppliers (supp_name, phone, d_s, supp_daain, supp_madeen) VALUES (?, ?, 0, 0, 0)");
$ins->bind_param("ss", $name, $phone);

if ($ins->execute()) {
    $new_id = $conn->insert_id;
    echo json_encode([
        'success' => true,
        'supp_id' => $new_id,
        'supp_name' => $name,
        'message' => 'تم إضافة المورد بنجاح'
    ]);
} else {
    echo json_encode(['success' => false, 'message' => 'فشل إضافة المورد في قاعدة البيانات']);
}
