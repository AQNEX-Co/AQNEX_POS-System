<?php
$dir_prefix = '../';
$module = 'suppliers';
require_once($dir_prefix . 'includes/header.php');

check_permission(['admin', 'inventory']);

// 1. جلب بيانات المتجر
$store_sql = "SELECT * FROM settings LIMIT 1";
$store_res = $conn->query($store_sql);
$store = $store_res ? $store_res->fetch_assoc() : [];
$store_name = $store['store_name'] ?? 'اسم المتجر'; 
$logo_url = !empty($store['logo']) ? $store['logo'] : '';

if (!isset($_GET['id']) || empty($_GET['id'])) {
    header("Location: " . $dir_prefix . "reports/account_statement.php?type=supplier");
    exit;
}

$id = intval($_GET['id']); // تأمين المتغير كرقم صحيح

// جلب بيانات المورد وأرصدته المباشرة
$sql = "SELECT supp_name, phone, address, supp_daain, supp_madeen FROM suppliers WHERE supp_id = $id AND d_s = '0'";
$result = $conn->query($sql);

if (!$result || $result->num_rows == 0) {
    echo "<div class='alert alert-danger'>خطأ: المورد غير موجود أو تم حذفه.</div>";
    require_once($dir_prefix . 'includes/footer.php');
    exit;
}
$supplier = $result->fetch_assoc();
$supp_name = $supplier['supp_name'];

// --- جلب الحركات من جدول السندات المحاسبية ---
$transactions = [];
$total_debit = 0;   // مدين (عليه)
$total_credit = 0;  // دائن (له)

// ملاحظة: نستخدم party_type = 'supplier' كما تم حفظها في دالة create_payment
$sql_v = "SELECT voucher_type, voucher_no, voucher_date, amount, description, created_at 
          FROM accounting_vouchers 
          WHERE party_type = 'supplier' AND party_id = $id AND status = 'posted'
          ORDER BY voucher_date ASC, id ASC";
          
$res_v = $conn->query($sql_v);

if ($res_v && $res_v->num_rows > 0) {
    while ($row = $res_v->fetch_assoc()) {
        // منطق محاسبة الموردين:
        // voucher_type = 'payment' (سند صرف) -> نحن دفعنا للمورد -> يقل ديننا له -> يُسجل كـ "دائن" (له)
        // voucher_type = 'receipt' (سند قبض) -> نحن استلمنا من المورد -> يُسجل كـ "مدين" (عليه)
        
        $is_payment = ($row['voucher_type'] === 'payment');
        $debit_val = $is_payment ? 0.0 : floatval($row['amount']);
        $credit_val = $is_payment ? floatval($row['amount']) : 0.0;
        
        $total_debit += $debit_val;
        $total_credit += $credit_val;
        
        $transactions[] = [
            'v_no' => $row['voucher_no'],
            'date' => date('Y-m-d', strtotime($row['voucher_date'])),
            'desc' => !empty($row['description']) ? $row['description'] : ($is_payment ? 'سند صرف للمورد' : 'سند قبض من المورد'),
            'debit' => $debit_val,
            'credit' => $credit_val
        ];
    }
}

// حساب الرصيد النهائي بناءً على الأعمدة المباشرة لضمان الدقة المطلقة
$final_balance = floatval($supplier['supp_daain']) - floatval($supplier['supp_madeen']);
?>

<style>
    .statement-box { background: #fff; padding: 30px; border: 1px solid #ddd; margin-bottom: 20px; direction: rtl; }
    .header-table { width: 100%; border-bottom: 2px solid #333; margin-bottom: 20px; }
    .report-table { width: 100%; border-collapse: collapse; margin-top: 20px; }
    .report-table th { background: #f4f4f4; border: 1px solid #ddd; padding: 10px; text-align: center; color: #333; font-weight: bold; }
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
    <div class="no-print mb-3 text-left">
        <button onclick="window.print();" class="btn btn-dark"><i class="fa fa-print"></i> طباعة كشف الحساب</button>
        <a href="index.php" class="btn btn-secondary">العودة للموردين</a>
    </div>

    <div class="statement-box">
        <table class="header-table">
            <tr>
                <td style="width: 33%; text-align: right; vertical-align: top;">
                    <h2 style="margin:0;"><?php echo htmlspecialchars($store_name); ?></h2>
                    <p style="margin:5px 0;">كشف حساب مورد </p>
                    <p style="margin:0; font-size:13px;">تاريخ الاستخراج: <?php echo date('Y-m-d'); ?></p>
                </td>
                <td style="width: 33%; text-align: center;">
                    <?php if (!empty($logo_url)): ?>
                        <img src="<?php echo htmlspecialchars($logo_url); ?>" style="max-height:60px; width:auto; margin-bottom:5px;"><br>
                    <?php endif; ?>
                </td>
                <td style="width: 33%; text-align: left; vertical-align: top; font-size: 14px;">
                    <strong>بيانات المورد:</strong><br>
                    الاسم: <?php echo htmlspecialchars($supp_name); ?><br>
                    الجوال: <?php echo htmlspecialchars($supplier['phone'] ?? '-'); ?><br>
                    العنوان: <?php echo htmlspecialchars($supplier['address'] ?? '-'); ?>
                </td>
            </tr>
        </table>

        <h4 class="text-center" style="text-decoration: underline;">تفاصيل حركة الحساب</h4>

        <table class="report-table">
            <thead>
                <tr>
                    <th width="5%">#</th>
                    <th width="10%">رقم السند</th>
                    <th width="15%">التاريخ</th>
                    <th width="35%">البيان</th>
                    <th width="10%">مدين (عليه)</th>
                    <th width="10%">دائن (له)</th>
                    <th width="15%">الرصيد التراكمي</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $running_balance = 0;
                if (empty($transactions)): ?>
                    <tr>
                        <td colspan="7" class="text-center text-muted p-4">لا توجد حركات مسجلة (سندات صرف أو قبض) لهذا المورد.</td>
                    </tr>
                <?php else: 
                    foreach ($transactions as $key => $trans): 
                        $running_balance += ($trans['credit'] - $trans['debit']);
                ?>
                <tr>
                    <td><?php echo $key + 1; ?></td>
                    <td style="font-family: monospace;"><?php echo htmlspecialchars($trans['v_no']); ?></td>
                    <td><?php echo $trans['date']; ?></td>
                    <td style="text-align: right;"><?php echo htmlspecialchars($trans['desc']); ?></td>
                    <td style="color: green; font-weight: bold;"><?php echo ($trans['debit'] > 0) ? number_format($trans['debit'], 2) : '-'; ?></td>
                    <td style="color: red; font-weight: bold;"><?php echo ($trans['credit'] > 0) ? number_format($trans['credit'], 2) : '-'; ?></td>
                    <td dir="ltr" style="font-weight: bold; color: #333;"><?php echo number_format($running_balance, 2); ?></td>
                </tr>
                <?php 
                    endforeach; 
                endif; 
                ?>
            </tbody>
        </table>

        <table class="totals-box">
            <tr>
                <td class="bg-light">إجمالي الدائن (له):</td>
                <td style="color: red;"><?php echo number_format($supp_daain, 2); ?></td>
            </tr>
            <tr>
                <td class="bg-light">إجمالي المدين (عليه):</td>
                <td style="color: green;"><?php echo number_format($supp_madeen, 2); ?></td>
            </tr>
            <tr style="background: #333; color: #fff;">
                <td>الرصيد النهائي (من جدول الموردين):</td>
                <td><?php echo number_format($final_balance, 2); ?> ر.ي</td>
            </tr>
        </table>

        <div style="margin-top: 50px; display: flex; justify-content: space-between;">
            <div style="text-align: center; width: 200px;">
                <p>توقيع المحاسب</p>
                <p style="border-top: 1px solid #333; margin-top: 40px;">.......................</p>
            </div>
            <div style="text-align: center; width: 200px;">
                <p>ختم المتجر</p>
                <div style="height: 60px; border: 1px dashed #ccc; margin-top: 10px;"></div>
            </div>
        </div>
    </div>
</div>

<?php require_once($dir_prefix . 'includes/footer.php'); ?>