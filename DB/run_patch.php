<?php
$conn = new mysqli('localhost','root','','aq_pos');
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error . "\n");
}
$conn->set_charset('utf8mb4');

$sql = file_get_contents(__DIR__ . '/patch_v5.sql');
// Split by semicolon, but ignore those in single quotes or comments. A simple split for this file should work, or we can use multi_query.
if ($conn->multi_query($sql)) {
    do {
        // Store first result set
        if ($result = $conn->store_result()) {
            $result->free();
        }
    } while ($conn->next_result());
    echo "✓ Patch v5 executed successfully!\n";
} else {
    echo "❌ Error executing patch: " . $conn->error . "\n";
}
?>
