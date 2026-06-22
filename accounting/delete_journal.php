<?php
$dir_prefix = '../';
require_once($dir_prefix . 'includes/header.php');

check_permission(['admin']);

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: journal.php?msg=invalid');
    exit;
}

$journal_id = intval($_GET['id']);

// التحقق من وجود القيد
$res = $conn->query("SELECT id FROM accounting_journal WHERE id = $journal_id");
if (!$res || $res->num_rows === 0) {
    header('Location: journal.php?msg=notfound');
    exit;
}

// حذف القيد
if ($conn->query("DELETE FROM accounting_journal WHERE id = $journal_id")) {
    header('Location: journal.php?msg=deleted');
} else {
    header('Location: journal.php?msg=error');
}
exit;
?>
