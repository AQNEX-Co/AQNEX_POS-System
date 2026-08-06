<?php
require_once __DIR__ . '/../includes/connect.php';
$r = $conn->query('DESCRIBE sales_invoices_dtl');
echo "sales_invoices_dtl columns:\n";
while ($row = $r->fetch_assoc()) echo '  ' . $row['Field'] . "\n";
