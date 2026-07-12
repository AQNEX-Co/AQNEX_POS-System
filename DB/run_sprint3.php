<?php
/**
 * AQNEX POS – One-time migration runner
 * Runs Sprint 3 SQL migration to create missing tables
 * Access: http://localhost/tech/DB/run_sprint3.php
 */
require_once __DIR__ . '/../includes/connect.php';

// Security: only admin can run
if (session_status() === PHP_SESSION_NONE) session_start();
$role = $_SESSION['SESS_LAST_NAME'] ?? '';
if ($role !== 'admin' && $role !== '') {
    die('<h2 style="color:red;font-family:monospace;">غير مصرح</h2>');
}

$sql = file_get_contents(__DIR__ . '/migrations/sprint3_fix_tables.sql');

// Split by semicolons and run each statement
$statements = array_filter(array_map('trim', explode(';', $sql)));

$results = [];
foreach ($statements as $stmt) {
    if (empty($stmt) || strpos($stmt, '--') === 0) continue;
    if ($conn->query($stmt)) {
        $results[] = ['ok', substr($stmt, 0, 80)];
    } else {
        $results[] = ['err', $conn->error . ' — ' . substr($stmt, 0, 80)];
    }
}

header('Content-Type: text/html; charset=utf-8');
echo '<html dir="rtl"><head><meta charset="utf-8"><title>Migration</title>
<style>body{font-family:monospace;padding:20px;background:#0f172a;color:#e2e8f0;}
.ok{color:#4ade80;} .err{color:#f87171;}</style></head><body>';
echo '<h2 style="color:#38bdf8">AQNEX – Sprint 3 Migration</h2>';
foreach ($results as [$status, $msg]) {
    echo "<div class=\"$status\">[" . strtoupper($status) . "] " . htmlspecialchars($msg) . "</div>\n";
}
echo '<br><hr><p style="color:#94a3b8">تم تشغيل الترحيل. يمكنك حذف هذا الملف بعد التحقق.</p>';
echo '</body></html>';
