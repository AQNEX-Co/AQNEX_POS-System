<?php
require_once(__DIR__ . '/../includes/connect.php');

if (isset($_POST['s'])) {
    $product_id = intval($_POST['s']);
    $sql = "SELECT buy_price FROM products WHERE id = $product_id";
    $result = $conn->query($sql);
    if ($result && $row = $result->fetch_assoc()) {
        echo $row['buy_price'];
    } else {
        echo "0";
    }
    exit;
}
?>
