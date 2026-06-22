<?php
$dir_prefix = '../';
require_once($dir_prefix . 'includes/auth.php');
require_once($dir_prefix . 'includes/connect.php');

if (isset($_GET['id'])) {
    $id = $conn->real_escape_string($_GET['id']);
    $sql = "DELETE FROM products WHERE id='$id'";
    $conn->query($sql);
}

header('Location: index.php');
exit;
?>
