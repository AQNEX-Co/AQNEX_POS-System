<?php
require_once(__DIR__ . '/../includes/connect.php');

if (isset($_POST['drop_services'])) {
    $product_id = intval($_POST['drop_services']);
    $sql = "SELECT sale_price FROM products WHERE id = $product_id";
    $result = $conn->query($sql);
    if ($result && $row = $result->fetch_assoc()) {
        echo $row['sale_price'];
    } else {
        echo "0";
    }
    exit;
}
?>
