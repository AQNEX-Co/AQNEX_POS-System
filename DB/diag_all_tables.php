<?php
$conn = new mysqli('localhost','root','','aq_pos', 3307);
$conn->set_charset('utf8mb4');

$tables = ['treasury', 'treasury_transactions', 'treasury_expenses', 'receipts', 'accounting_journal', 'sales_returns', 'sales', 'purchases', 'purchase_items', 'purchase_returns', 'products', 'suppliers'];

foreach ($tables as $table) {
    echo "== $table columns ==\n";
    $r = $conn->query("DESCRIBE `$table`");
    if ($r) {
        while($row = $r->fetch_assoc()) {
            echo "  " . $row['Field'] . ' (' . $row['Type'] . ') ' . ($row['Key'] ? '['.$row['Key'].']' : '') . ' ' . $row['Extra'] . PHP_EOL;
        }
    } else {
        echo "ERROR: " . $conn->error . PHP_EOL;
    }
    echo PHP_EOL;
}
