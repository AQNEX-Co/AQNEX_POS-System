<?php
$conn = new mysqli('localhost','root','','aq_pos');
$conn->set_charset('utf8mb4');

echo "== settings columns ==" . PHP_EOL;
$r = $conn->query('DESCRIBE settings');
if ($r) { while($row = $r->fetch_assoc()) echo $row['Field'] . ' (' . $row['Type'] . ')' . PHP_EOL; }
else echo 'ERROR: ' . $conn->error . PHP_EOL;

echo PHP_EOL . "== users columns ==" . PHP_EOL;
$r = $conn->query('DESCRIBE users');
if ($r) { while($row = $r->fetch_assoc()) echo $row['Field'] . ' (' . $row['Type'] . ')' . PHP_EOL; }
else echo 'ERROR: ' . $conn->error . PHP_EOL;

echo PHP_EOL . "== sales_items columns ==" . PHP_EOL;
$r = $conn->query('DESCRIBE sales_items');
if ($r) { while($row = $r->fetch_assoc()) echo $row['Field'] . ' (' . $row['Type'] . ')' . PHP_EOL; }
else echo 'ERROR: ' . $conn->error . PHP_EOL;

echo PHP_EOL . "== purchases table ==" . PHP_EOL;
$r = $conn->query('SHOW TABLES LIKE "purchases"');
echo ($r && $r->num_rows > 0) ? "EXISTS" : "MISSING";

echo PHP_EOL . "== purchases columns ==" . PHP_EOL;
$r = $conn->query('DESCRIBE purchases');
if ($r) { while($row = $r->fetch_assoc()) echo $row['Field'] . ' (' . $row['Type'] . ')' . PHP_EOL; }
else echo 'ERROR: ' . $conn->error . PHP_EOL;

echo PHP_EOL . "== currencies table ==" . PHP_EOL;
$r = $conn->query('SHOW TABLES LIKE "currencies"');
echo ($r && $r->num_rows > 0) ? "EXISTS" : "MISSING";
echo PHP_EOL;

echo PHP_EOL . "== inventory_log table ==" . PHP_EOL;
$r = $conn->query('SHOW TABLES LIKE "inventory_log"');
echo ($r && $r->num_rows > 0) ? "EXISTS" : "MISSING";
echo PHP_EOL;
