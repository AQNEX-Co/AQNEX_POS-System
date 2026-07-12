<?php
$dir_prefix = '../';
require_once($dir_prefix . 'includes/connect.php');
require_once($dir_prefix . 'includes/auth.php');

// التحقق من الصلاحيات والمسؤول
if (!\AQNEX\Services\AuthService::isAdmin()) {
    \AQNEX\Services\AuthService::denyAccess();
}

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: journal.php?msg=invalid');
    exit;
}

$entry_id = intval($_GET['id']);

// فحص وجود القيد المزدوج
$res = $conn->query("SELECT * FROM accounting_journal_entries WHERE id = $entry_id LIMIT 1");
if ($res && $res->num_rows > 0) {
    $row = $res->fetch_assoc();
    $source_type = $conn->real_escape_string($row['source_type']);
    $source_id = intval($row['source_id']);

    $conn->begin_transaction();
    try {
        // 1. حذف بنود القيد من جدول البنود
        $conn->query("DELETE FROM accounting_journal_items WHERE entry_id = $entry_id");

        // 2. حذف رأس القيد من جدول القيود المزدوجة
        $conn->query("DELETE FROM accounting_journal_entries WHERE id = $entry_id");

        // 3. حذف القيد المقابل من جدول القيود القديم (المبسط) في حال كان مرحل من مبيعات أو مشتريات
        if ($source_type !== 'manual' && $source_id > 0) {
            $conn->query("DELETE FROM accounting_journal WHERE ref_type = '$source_type' AND ref_id = $source_id");
        }

        $conn->commit();
        header('Location: journal.php?msg=deleted');
    } catch (Exception $e) {
        $conn->rollback();
        header('Location: journal.php?msg=error');
    }
} else {
    // إذا لم يوجد في القيود المزدوجة، نقوم بالحذف الاحتياطي من الجدول القديم مباشرة
    $conn->query("DELETE FROM accounting_journal WHERE id = $entry_id");
    header('Location: journal.php?msg=deleted');
}
exit;
?>
