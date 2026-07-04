<?php
$dir_prefix = '../';
$module = 'customers';
require_once($dir_prefix . 'includes/header.php');

check_permission(['admin', 'cashier']);
if (!isset($_GET['id']) || empty($_GET['id'])) {
    echo "<div class='alert alert-danger rounded-0'>خطأ: لم يتم تحديد العميل.</div>";
    require_once($dir_prefix . 'includes/footer.php');
    exit;
}

$id = $conn->real_escape_string($_GET['id']);
$sql = "SELECT * FROM customers WHERE cust_id='$id'";
$result = $conn->query($sql);

if (!$result || $result->num_rows == 0) {
    echo "<div class='alert alert-danger rounded-0'>خطأ: العميل غير موجود.</div>";
    require_once($dir_prefix . 'includes/footer.php');
    exit;
}

$customer = $result->fetch_assoc();
$cust_name = $customer['cust_name'];

// جلب العمليات المحاسبية من دفتر اليومية الفعلي لحساب مديونية العميل
$transactions = [];
$total_debt = 0;
$total_paid = 0;
$total_returns = 0;

$sql_je = "SELECT * FROM journal_entries 
           WHERE (debit_entity_type = 'customer' AND debit_entity_id = '$id')
              OR (credit_entity_type = 'customer' AND credit_entity_id = '$id')
           ORDER BY created_at ASC, id ASC";
$res_je = $conn->query($sql_je);

if ($res_je) {
    while ($row = $res_je->fetch_assoc()) {
        $is_debit = ($row['debit_entity_type'] === 'customer' && intval($row['debit_entity_id']) === intval($id));
        $debit_val = $is_debit ? floatval($row['amount']) : 0.0;
        $credit_val = !$is_debit ? floatval($row['amount']) : 0.0;
        
        $total_debt += $debit_val;
        $total_paid += $credit_val;
        
        $transactions[] = [
            'date' => date('Y-m-d', strtotime($row['created_at'])),
            'doc_no' => $row['ref_id'],
            'desc' => $row['description'],
            'debit' => $debit_val,
            'credit' => $credit_val
        ];
    }
}

// المتبقي النهائي
$remaining_balance = $total_debt - $total_paid;
?>
<title>كشف حساب العميل: <?php echo htmlspecialchars($cust_name); ?> - تكنولوجيا فون</title>

<style>
@media print {
    body { background:#fff !important; }
    .no-print { display:none !important; }
    .card-flat { page-break-inside: avoid; }
    .table-flat { page-break-inside: auto; width:100%; }
    .table-flat thead { display: table-header-group; }
    .table-flat tfoot { display: table-footer-group; }
    .table-flat tr { page-break-inside: avoid; page-break-after: auto; }
    .table-flat th, .table-flat td { font-size: 11pt; }
    .stat-card, .stat-card .stat-info, .stat-card .stat-icon { display: none !important; }
}

</style>




<div class="row no-print mb-4">
    <div class="col-md-6">
        <h3 class="text-secondary font-weight-bold">
            <i class="fa fa-file-text-o ml-2"></i>كشف حساب العميل
        </h3>
        <p class="text-muted small mb-0">كشف حركات المبيعات وسندات القبض للعميل: <strong><?php echo htmlspecialchars($cust_name); ?></strong></p>
    </div>
    <div class="col-md-6 text-left">
        <a href="../sales/create.php" class="btn-flat btn-flat-primary btn-sm ml-2 text-decoration-none">
            <i class="fa fa-shopping-cart ml-1"></i>إضافة مبيعات
        </a>
        <a href="../receipts/create.php" class="btn-flat btn-flat-success btn-sm ml-2 text-decoration-none">
            <i class="fa fa-plus-circle ml-1"></i>إضافة مقبوضات
        </a>
        <button onclick="window.print()" class="btn-flat btn-flat-info btn-sm ml-2" style="background-color: var(--accent-info); color:#fff;">
            <i class="fa fa-printer ml-1"></i>طباعة كشف الحساب
        </button>
        <?php if (!empty($customer['phone'])): ?>
        <button id="send-ledger-wa-btn" class="btn-flat btn-flat-success btn-sm ml-2" style="background-color: #25d366; color:#fff; border: none;">
            <i class="fa fa-whatsapp ml-1"></i>إرسال بالواتساب
        </button>
        <?php endif; ?>
        <a href="index.php" class="btn-flat btn-flat-secondary btn-sm text-decoration-none">
            <i class="fa fa-arrow-left ml-1"></i>عودة لقائمة العملاء
        </a>
    </div>
</div>

<!-- حاوية تصوير كشف الحساب بالكامل -->
<div id="ledger-screenshot-area" style="background: #ffffff; padding: 15px;">

<!-- كشف الحساب التفصيلي -->
<div class="card-flat mb-4">
    <div class="card-header bg-light">
        <h5 class="font-weight-bold mb-0">
            <i class="fa fa-users ml-2 text-primary"></i>تفاصيل العميل: <?php echo htmlspecialchars($cust_name); ?>
            <?php if (!empty($customer['phone'])): ?>
                <span class="text-muted small ml-3">(جوال: <?php echo htmlspecialchars($customer['phone']); ?>)</span>
            <?php endif; ?>
        </h5>
    </div>
    <div class="card-body">
        <div class="row no-print">
            <div class="col-md-3">
                <div class="stat-card danger mb-3">
                    <div class="stat-info">
                        <h6>إجمالي مبيعات الأجل (المدين)</h6>
                        <h3><?php echo number_format($total_debt, 2); ?> ر.ي</h3>
                    </div>
                    <div class="stat-icon">
                        <i class="fa fa-shopping-bag text-danger"></i>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card success mb-3">
                    <div class="stat-info">
                        <h6>إجمالي المقبوضات (المسدد/الدائن)</h6>
                        <h3><?php echo number_format($total_paid, 2); ?> ر.ي</h3>
                    </div>
                    <div class="stat-icon">
                        <i class="fa fa-money text-success"></i>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card warning mb-3">
                    <div class="stat-info">
                        <h6>إجمالي المرتجعات (خصم دين)</h6>
                        <h3><?php echo number_format($total_returns, 2); ?> ر.ي</h3>
                    </div>
                    <div class="stat-icon">
                        <i class="fa fa-reply text-warning"></i>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <?php
                $balance_class = $remaining_balance > 0 ? 'danger' : ($remaining_balance < 0 ? 'success' : 'secondary');
                $balance_title = $remaining_balance > 0 ? 'المتبقي النهائي (عليه)' : ($remaining_balance < 0 ? 'المتبقي النهائي (له)' : 'المتبقي النهائي (متزن)');
                ?>
                <div class="stat-card <?php echo $balance_class; ?> mb-3">
                    <div class="stat-info">
                        <h6><?php echo $balance_title; ?></h6>
                        <h3><?php echo number_format(abs($remaining_balance), 2); ?> ر.ي</h3>
                    </div>
                    <div class="stat-icon">
                        <i class="fa fa-balance-scale"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- جدول كشف الحساب الموحد -->
<div class="card-flat">
    <div class="card-header bg-light">
        <h5 class="mb-0 font-weight-bold"><i class="fa fa-file-text-o ml-2 text-primary"></i>تفاصيل كشف الحساب الموحد (مرتبة تاريخياً)</h5>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table-flat text-right" dir="rtl">
                <thead>
                    <tr class="text-center">
                        <th width="5%">#</th>
                        <th width="15%">التاريخ</th>
                        <th width="15%">رقم المستند</th>
                        <th width="35%">البيان</th>
                        <th width="10%">مدين (عليه)</th>
                        <th width="10%">دائن (له)</th>
                        <th width="10%">الرصيد التراكمي</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $running_balance = 0.0;
                    $sum_debit = 0.0;
                    $sum_credit = 0.0;
                    if (!empty($transactions)): 
                        foreach ($transactions as $index => $trans): 
                            $running_balance += ($trans['debit'] - $trans['credit']);
                            $sum_debit += $trans['debit'];
                            $sum_credit += $trans['credit'];
                    ?>
                            <tr class="text-center">
                                <td><?php echo $index + 1; ?></td>
                                <td><?php echo htmlspecialchars($trans['date']); ?></td>
                                <td class="font-weight-bold text-secondary">#<?php echo htmlspecialchars($trans['doc_no']); ?></td>
                                <td class="text-right pr-3"><?php echo htmlspecialchars($trans['desc']); ?></td>
                                <td class="text-danger font-weight-bold"><?php echo ($trans['debit'] > 0) ? number_format($trans['debit'], 2) : '-'; ?></td>
                                <td class="text-success font-weight-bold"><?php echo ($trans['credit'] > 0) ? number_format($trans['credit'], 2) : '-'; ?></td>
                                <td class="font-weight-bold" dir="ltr"><?php echo number_format($running_balance, 2); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" class="text-center text-muted p-3">لا توجد حركات مسجلة لهذا العميل.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
                <tfoot>
                    <tr style="font-weight: 800; background: #f8f9fa;" class="text-center">
                        <td></td>
                        <td></td>
                        <td>الخلاصة النهائية</td>
                        <td class="text-left font-weight-bold">إجمالي المطالبات:</td>
                        <td class="text-danger font-weight-bold"><?php echo number_format($sum_debit, 2); ?> YER</td>
                        <td class="text-success font-weight-bold"><?php echo number_format($sum_credit, 2); ?> YER</td>
                        <td class="bg-dark text-white font-weight-bold" dir="ltr"><?php echo number_format($running_balance, 2); ?> YER</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>
</div> <!-- إغلاق ledger-screenshot-area -->

<!-- WhatsApp Ledger Send Integration -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<script>
document.addEventListener("DOMContentLoaded", function() {
    const sendBtn = document.getElementById('send-ledger-wa-btn');
    if (sendBtn) {
        sendBtn.addEventListener('click', function() {
            const captureArea = document.getElementById('ledger-screenshot-area');
            if (!captureArea) return;
            
            // Disable button during processing
            sendBtn.disabled = true;
            sendBtn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> جاري التصوير...';
            
            // Create status alert
            const alertDiv = document.createElement('div');
            alertDiv.style.position = 'fixed';
            alertDiv.style.top = '25px';
            alertDiv.style.right = '25px';
            alertDiv.style.zIndex = '999999';
            alertDiv.style.fontFamily = "'Tajawal', sans-serif";
            alertDiv.className = 'alert alert-info shadow-lg border-0 rounded-0';
            alertDiv.innerHTML = '<i class="fa fa-refresh fa-spin ml-2"></i> جاري تحويل كشف الحساب لصورة وإرساله بالواتساب...';
            document.body.appendChild(alertDiv);
            
            // Ensure white background during screenshot
            const originalBg = captureArea.style.background;
            captureArea.style.background = '#ffffff';
            
            html2canvas(captureArea, {
                scale: 2,
                useCORS: true,
                backgroundColor: '#ffffff'
            }).then(canvas => {
                captureArea.style.background = originalBg;
                const base64Image = canvas.toDataURL('image/png');
                
                fetch('../api/send_whatsapp_ledger.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        customer_id: <?php echo intval($id); ?>,
                        image_base64: base64Image
                    })
                })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        alertDiv.className = 'alert alert-success shadow-lg border-0 rounded-0';
                        alertDiv.innerHTML = '<i class="fa fa-check ml-2"></i> ' + data.message;
                    } else {
                        alertDiv.className = 'alert alert-danger shadow-lg border-0 rounded-0';
                        alertDiv.innerHTML = '<i class="fa fa-times ml-2"></i> فشل إرسال الواتساب: ' + data.message;
                    }
                    setTimeout(() => alertDiv.remove(), 4000);
                    sendBtn.disabled = false;
                    sendBtn.innerHTML = '<i class="fa fa-whatsapp ml-1"></i> إرسال بالواتساب';
                })
                .catch(err => {
                    alertDiv.className = 'alert alert-danger shadow-lg border-0 rounded-0';
                    alertDiv.innerHTML = '<i class="fa fa-times ml-2"></i> عطل في الاتصال ببوابة الواتساب.';
                    setTimeout(() => alertDiv.remove(), 4000);
                    sendBtn.disabled = false;
                    sendBtn.innerHTML = '<i class="fa fa-whatsapp ml-1"></i> إرسال بالواتساب';
                });
            });
        });
    }
});
</script>

<?php
require_once($dir_prefix . 'includes/footer.php');
?>
