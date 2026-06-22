<?php
$conn = new mysqli('localhost','root','','aq_pos');
if ($conn->connect_error) { die("Connection failed: " . $conn->connect_error); }
$conn->set_charset('utf8mb4');

$sql = file_get_contents(__DIR__ . '/patch_v7.sql');
if ($conn->multi_query($sql)) {
    do {
        if ($result = $conn->store_result()) { $result->free(); }
    } while ($conn->next_result());
    echo "✓ Patch v7 executed successfully! full_name column added.";
} else {
    echo "❌ Error: " . $conn->error;
}
?>
