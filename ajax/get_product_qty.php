<?php
require_once(__DIR__ . '/../includes/connect.php');

if (isset($_POST['services'])) {
    $product_id = intval($_POST['services']);
    $sql = "SELECT quantity FROM products WHERE id = $product_id";
    $result = $conn->query($sql);
    if ($result && $row = $result->fetch_assoc()) {
        echo $row['quantity'];
    } else {
        echo "0";
    }
    exit;
}
?>
