<?php
$dir_prefix = '../';
require_once($dir_prefix . 'includes/connect.php');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['SESS_MEMBER_ID'])) {
    exit('Access Denied');
}

$ticket_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$res = $conn->query("
    SELECT r.*, c.cust_name, c.phone as cust_phone
    FROM repair_tickets r
    LEFT JOIN customers c ON r.customer_id = c.cust_id
    WHERE r.id = $ticket_id AND r.d_s = '0'
    LIMIT 1
");
$ticket = $res ? $res->fetch_assoc() : null;

if (!$ticket) {
    exit('Ticket not found.');
}

// جلب اسم المحل من الإعدادات
$store_name = "تكنولوجيا فون";
$res_s = $conn->query("SELECT store_name FROM settings WHERE id = 1 LIMIT 1");
if ($res_s && $res_s->num_rows > 0) {
    $store_name = $res_s->fetch_assoc()['store_name'];
}
?>
<!DOCTYPE html>
<html dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>ملصق صيانة #<?php echo htmlspecialchars($ticket['ticket_number']); ?></title>
    <style>
        body {
            margin: 0;
            padding: 5px;
            font-family: Arial, Helvetica, sans-serif;
            font-size: 11px;
            color: #000;
            background: #fff;
            width: 50mm; /* عرض ملصق الحراري القياسي */
            box-sizing: border-box;
        }
        .sticker-container {
            border: 1px dashed #000;
            padding: 6px;
            text-align: right;
            box-sizing: border-box;
        }
        .store-title {
            font-size: 13px;
            font-weight: bold;
            text-align: center;
            border-bottom: 1px solid #000;
            padding-bottom: 3px;
            margin-bottom: 5px;
        }
        .ticket-no {
            font-size: 15px;
            font-weight: bold;
            text-align: center;
            background: #000;
            color: #fff;
            padding: 3px;
            margin: 5px 0;
        }
        .row-item {
            margin-bottom: 3px;
            line-height: 1.2;
        }
        .label {
            font-weight: bold;
        }
        .footer-text {
            font-size: 9px;
            text-align: center;
            margin-top: 5px;
            border-top: 1px dotted #000;
            padding-top: 3px;
        }
        @media print {
            body {
                width: 50mm;
            }
            .no-print {
                display: none;
            }
        }
    </style>
</head>
<body onload="window.print(); setTimeout(window.close, 1000);">
    <div class="sticker-container">
        <div class="store-title"><?php echo htmlspecialchars($store_name); ?></div>
        
        <div class="ticket-no">#<?php echo htmlspecialchars($ticket['ticket_number']); ?></div>
        
        <div class="row-item">
            <span class="label">التاريخ:</span> <?php echo date('Y-m-d', strtotime($ticket['received_date'])); ?>
        </div>
        <div class="row-item">
            <span class="label">العميل:</span> <?php echo htmlspecialchars($ticket['cust_name'] ?: 'عميل نقدي'); ?>
        </div>
        <?php if (!empty($ticket['cust_phone'])): ?>
        <div class="row-item">
            <span class="label">الهاتف:</span> <?php echo htmlspecialchars($ticket['cust_phone']); ?>
        </div>
        <?php endif; ?>
        <div class="row-item">
            <span class="label">الجهاز:</span> <?php echo htmlspecialchars($ticket['device_brand'] . ' ' . $ticket['device_name']); ?>
        </div>
        <div class="row-item">
            <span class="label">العطل:</span> <?php echo htmlspecialchars($ticket['issue_type'] === 'other' ? $ticket['custom_issue_type'] : $ticket['issue_type']); ?>
        </div>
        
        <div class="footer-text">صيانة الأجهزة والأعطال</div>
    </div>
</body>
</html>
