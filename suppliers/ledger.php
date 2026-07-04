<?php
$dir_prefix = '../';
$module = 'suppliers';
require_once($dir_prefix . 'includes/header.php');

check_permission(['admin', 'inventory']);

// 1. جلب بيانات المتجر من قاعدة البيانات (الشعار والاسم)
$store_sql = "SELECT * FROM settings LIMIT 1";
$store_res = $conn->query($store_sql);
$store = $store_res->fetch_assoc();
$store_name = $store['store_name'] ?? 'تكنولوجيا فون'; 

if (!isset($_GET['id']) || empty($_GET['id'])) {
    echo "<div class='alert alert-danger'>خطأ: لم يتم تحديد المورد.</div>";
    require_once($dir_prefix . 'includes/footer.php');
    exit;
}

$id = $conn->real_escape_string($_GET['id']);
$sql = "SELECT * FROM Suppliers WHERE supp_id='$id'";
$result = $conn->query($sql);
if (!$result || $result->num_rows == 0) {
    echo "<div class='alert alert-danger'>خطأ: المورد غير موجود.</div>";
    require_once($dir_prefix . 'includes/footer.php');
    exit;
}
$supplier = $result->fetch_assoc();
$supp_name = $supplier['supp_name'];

// --- جلب العمليات المحاسبية من دفتر اليومية الفعلي لحساب مديونية المورد ---
$transactions = [];
$total_debit = 0; // مدين / عليه
$total_credit = 0; // دائن / له

$sql_je = "SELECT * FROM journal_entries 
           WHERE (debit_entity_type = 'supplier' AND debit_entity_id = '$id')
              OR (credit_entity_type = 'supplier' AND credit_entity_id = '$id')
           ORDER BY created_at ASC, id ASC";
$res_je = $conn->query($sql_je);

if ($res_je) {
    while ($row = $res_je->fetch_assoc()) {
        $is_debit = ($row['debit_entity_type'] === 'supplier' && intval($row['debit_entity_id']) === intval($id));
        $debit_val = $is_debit ? floatval($row['amount']) : 0.0;
        $credit_val = !$is_debit ? floatval($row['amount']) : 0.0;
        
        $total_debit += $debit_val;
        $total_credit += $credit_val;
        
        $transactions[] = [
            'date' => date('Y-m-d', strtotime($row['created_at'])),
            'desc' => $row['description'],
            'debit' => $debit_val,
            'credit' => $credit_val
        ];
    }
}
?>

<style>
    /* التنسيق العام والكشف */
    .statement-box { background: #fff; padding: 30px; border: 1px solid #ddd; margin-bottom: 20px; direction: rtl; }
    .header-table { width: 100%; border-bottom: 2px solid #333; margin-bottom: 20px; }
    .report-table { width: 100%; border-collapse: collapse; margin-top: 20px; }
    .report-table th { background: #f4f4f4; border: 1px solid #ddd; padding: 10px; text-align: center; color: #333; }
    .report-table td { border: 1px solid #ddd; padding: 10px; text-align: center; font-size: 14px; }
    .totals-box { width: 300px; margin-right: auto; margin-top: 20px; border: 2px solid #333; }
    .totals-box td { padding: 8px; font-weight: bold; border: 1px solid #ddd; }
    .bg-light { background-color: #f8f9fa; }
    
    @media print {
        .no-print { display: none !important; }
        .statement-box { border: none; padding: 0; }
        body { background: #fff; }
    }
</style>

<div class="container-fluid mt-3">
    <!-- أزرار التحكم -->
    <div class="no-print mb-3 text-left">
        <button onclick="window.print();" class="btn btn-dark"><i class="fa fa-printer"></i> طباعة كشف الحساب</button>
        <a href="index.php" class="btn btn-secondary">العودة للموردين</a>
    </div>

    <div class="statement-box">
        <!-- الترويسة الرسمية -->
        <table class="header-table">
            <tr>
                <td style="width: 33%; text-align: right; vertical-align: top;">
                    <h2 style="margin:0;"><?php echo $store_name; ?></h2>
                    <p style="margin:5px 0;">كشف حساب مورد </p>
                    <p style="margin:0; font-size:13px;">تاريخ الاستخراج: <?php echo date('Y-m-d'); ?></p>
                </td>
                <td style="width: 33%; text-align: center;">
        <?php if (!empty($global_settings['logo'])): ?>
        <img src="<?php echo htmlspecialchars($logo_url); ?>" style="max-height:50px; width:auto; margin-bottom:5px;"><br>
        <?php endif; ?>                </td>
                <td style="width: 33%; text-align: left; vertical-align: top; font-size: 14px;">
                    <strong>بيانات المورد:</strong><br>
                    الاسم: <?php echo htmlspecialchars($supp_name); ?><br>
                    الجوال: <?php echo $supplier['phone']; ?><br>
                    العنوان: <?php echo $supplier['address']; ?>
                </td>
            </tr>
        </table>

        <h4 class="text-center" style="text-decoration: underline;">تفاصيل حركة الحساب</h4>

        <!-- الجدول الموحد للعمليات -->
        <table class="report-table">
            <thead>
                <tr>
                    <th width="5%">#</th>
                    <th width="15%">التاريخ</th>
                    <th width="45%">البيان (نوع العملية)</th>
                    <th width="10%">مدين (عليه)</th>
                    <th width="10%">دائن (له)</th>
                    <th width="15%">الرصيد التراكمي</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $total_debit = 0;
                $total_credit = 0;
                $balance = 0;
                foreach ($transactions as $key => $trans): 
                    $total_debit += $trans['debit'];
                    $total_credit += $trans['credit'];
                    $balance += ($trans['credit'] - $trans['debit']);
                ?>
                <tr>
                    <td><?php echo $key + 1; ?></td>
                    <td><?php echo $trans['date']; ?></td>
                    <td style="text-align: right;"><?php echo $trans['desc']; ?></td>
                    <td style="color: green; font-weight: bold;"><?php echo ($trans['debit'] > 0) ? number_format($trans['debit'], 2) : '-'; ?></td>
                    <td style="color: red; font-weight: bold;"><?php echo ($trans['credit'] > 0) ? number_format($trans['credit'], 2) : '-'; ?></td>
                    <td dir="ltr"><?php echo number_format($balance, 2); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <!-- إجماليات آخر الصفحة -->
        <table class="totals-box">
            <tr>
                <td class="bg-light">إجمالي الدائن (له):</td>
                <td style="color: red;"><?php echo number_format($total_credit, 2); ?></td>
            </tr>
            <tr>
                <td class="bg-light">إجمالي المدين (عليه):</td>
                <td style="color: green;"><?php echo number_format($total_debit, 2); ?></td>
            </tr>
            <tr style="background: #333; color: #fff;">
                <td>الرصيد المتبقي:</td>
                <td><?php echo number_format($balance, 2); ?> ر.ي</td>
            </tr>
        </table>

        <!-- منطقة التواقيع -->
        <div style="margin-top: 50px; display: flex; justify-content: space-between;">
            <div style="text-align: center; width: 200px;">
                <p>توقيع المحاسب</p>
                <p>.......................</p>
            </div>
            <div style="text-align: center; width: 200px;">
                <p>ختم المتجر</p>
                <div style="height: 80px; border: 1px dashed #ccc;"></div>
            </div>
        </div>
    </div>
</div>

<?php require_once($dir_prefix . 'includes/footer.php'); ?>