<?php
$dir_prefix = '../';
require_once($dir_prefix . 'includes/connect.php');
require_once($dir_prefix . 'includes/auth.php');

// Verify session and admin status before outputting any HTML to prevent "headers already sent" warning
if (!\AQNEX\Services\AuthService::isAdmin()) {
    \AQNEX\Services\AuthService::denyAccess();
}

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: journal.php?msg=invalid');
    exit;
}

$journal_id = intval($_GET['id']);

$res = $conn->query("SELECT * FROM accounting_journal WHERE id = $journal_id LIMIT 1");
if ($res && $res->num_rows > 0) {
    $row = $res->fetch_assoc();
    $ref_type = $conn->real_escape_string($row['ref_type']);
    $ref_id = intval($row['ref_id']);
    $debit = $conn->real_escape_string($row['account_debit']);
    $credit = $conn->real_escape_string($row['account_credit']);
    $amount = doubleval($row['amount']);
    
    $conn->begin_transaction();
    try {
        // Delete from accounting_journal
        $conn->query("DELETE FROM accounting_journal WHERE id = $journal_id");
        
        // Delete corresponding row from journal_entries
        $conn->query("DELETE FROM journal_entries WHERE ref_type = '$ref_type' AND ref_id = $ref_id AND account_debit = '$debit' AND account_credit = '$credit' AND amount = $amount");
        
        $conn->commit();
        header('Location: journal.php?msg=deleted');
    } catch (Exception $e) {
        $conn->rollback();
        header('Location: journal.php?msg=error');
    }
} else {
    header('Location: journal.php?msg=notfound');
}
exit;
?>
