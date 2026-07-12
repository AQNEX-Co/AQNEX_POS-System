<?php
$dir_prefix = '../';
$module = 'purchases';
$no_print_header = true;
require_once($dir_prefix . 'includes/header.php');

check_permission(['admin', 'inventory']);

if (!isset($_GET['id']) || empty($_GET['id'])) {
    echo "<div class='alert alert-danger rounded-0'>خطأ: لم يتم تحديد رقم الفاتورة.</div>";
    require_once($dir_prefix . 'includes/footer.php');
    exit;
}

$invoice_id = intval($_GET['id']);
$sql_invoice = "SELECT * FROM purchases WHERE id = $invoice_id";
$res_invoice = $conn->query($sql_invoice);
$invoice = ($res_invoice) ? $res_invoice->fetch_assoc() : null;

if (!$invoice) {
    echo "<div class='alert alert-danger rounded-0'>خطأ: الفاتورة غير موجودة.</div>";
    require_once($dir_prefix . 'includes/footer.php');
    exit;
}

$build_date = $invoice['date'];
$supplier_name = $invoice['supp_name'];
$total_base = doubleval($invoice['total']);
$remark = $invoice['remark'];
$currency_code = isset($invoice['currency_code']) ? $invoice['currency_code'] : 'YER';
$exchange_rate = isset($invoice['exchange_rate']) ? doubleval($invoice['exchange_rate']) : 1.0;
if ($exchange_rate <= 0) $exchange_rate = 1.0;
$total_original = $total_base / $exchange_rate;

// جلب بنود الفاتورة - أولاً بـ purchase_id (الأحدث)، وإلا بالتاريخ+المورد (للبيانات القديمة)
$sql_items = "SELECT * FROM purchase_items WHERE purchase_id = $invoice_id";
$result_items = $conn->query($sql_items);
// Fallback للبيانات القديمة التي لا تحتوي على purchase_id
if (!$result_items || $result_items->num_rows == 0) {
    $sql_items = "SELECT * FROM purchase_items WHERE buys_date = '" . $conn->real_escape_string($build_date) . "' AND supp_name = '" . $conn->real_escape_string($supplier_name) . "'";
    $result_items = $conn->query($sql_items);
}


$settings_res = $conn->query("SELECT * FROM settings WHERE id = 1");
$settings = $settings_res ? $settings_res->fetch_assoc() : null;
$store_name = $settings ? $settings['store_name'] : 'المتجر';
$phone = $settings ? $settings['phone'] : '';
$address = $settings ? $settings['address'] : '';
$currency = $settings ? $settings['currency'] : 'ريال يمني';
?>
<title>فاتورة مشتريات #<?php echo $invoice_id; ?> - <?php echo htmlspecialchars($store_name); ?></title>

<style>
@page { size: A4 portrait; margin: 20mm; }
body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #fff; color: #0f172a; }
@media print {
    body { background: #fff !important; color: #000 !important; margin: 0 !important; padding: 0 !important; font-size: 11pt !important; }
    .no-print { display: none !important; }
    #content { padding: 0 !important; margin: 0 !important; }
    .wrapper { display: block !important; }
    #sidebar { display: none !important; }
    html, body { width: 210mm; }
    .inv-box { margin: 0; padding: 0; border: none; width: 100% !important; }
    .inv-table thead { display: table-header-group; }
    .inv-table tfoot { display: table-footer-group; }
    .inv-table tr { page-break-inside: avoid; page-break-after: auto; }
    .inv-header { border-bottom: 2px solid #000 !important; }
}
@media screen {
    .inv-box { max-width: 950px; margin: 20px auto; background: #fff; padding: 30px; border: 1px solid #e2e8f0; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1), 0 2px 4px -1px rgba(0,0,0,0.06); }
}
.inv-header { padding-bottom: 12px; margin-bottom: 16px; border-bottom: 1px solid #334155; }
.inv-store-name { font-size: 1.7rem; font-weight: 700; letter-spacing: 0.02em; }
.inv-store-sub { font-size: 0.9rem; color: #475569; margin: 2px 0; }
.inv-title-box h2 { font-size: 1.45rem; font-weight: 700; margin: 0; text-align: left; }
.inv-title-box .inv-num, .inv-title-box .inv-date { font-size: 0.95rem; color: #0f172a; margin: 4px 0 0; }
.inv-info-section { background: #f8fafc; border: 1px solid #e2e8f0; padding: 14px 16px; margin-bottom: 20px; }
.inv-info-row { display: flex; justify-content: space-between; flex-wrap: wrap; gap: 14px; }
.inv-info-item { font-size: 0.95rem; line-height: 1.6; }
.inv-info-item span { font-weight: 700; }
.inv-table { width: 100%; border-collapse: collapse; margin-bottom: 18px; }
.inv-table th, .inv-table td { border: 1px solid #d1d5db; padding: 10px 12px; }
.inv-table thead th { background: #f1f5f9; color: #0f172a; font-weight: 700; }
.inv-table td { color: #0f172a; }
.inv-table tbody tr:nth-child(even) { background: transparent; }
.inv-table td:first-child { text-align: center; width: 6%; }
.inv-table td:nth-child(2) { text-align: right; }
.inv-table td:nth-child(3), .inv-table td:nth-child(4), .inv-table td:nth-child(5) { text-align: center; }
.inv-summary-bottom { width: 100%; max-width: 430px; margin-top: 20px; margin-right: auto; border: 1px solid #e2e8f0; }
.inv-summary-bottom table { width: 100%; border-collapse: collapse; }
.inv-summary-bottom td { padding: 10px 12px; border-bottom: 1px solid #e2e8f0; font-size: 0.95rem; }
.inv-summary-bottom tr:last-child td { border-bottom: none; }
.inv-summary-bottom .label { text-align: right; color: #475569; }
.inv-summary-bottom .value { text-align: left; font-weight: 700; }
.inv-summary-bottom .grand-total .value { color: #0f172a; font-size: 1rem; }
.inv-footer { text-align: center; border-top: 1px solid #e2e8f0; margin-top: 24px; padding-top: 14px; color: #475569; font-size: 0.9rem; }
.inv-actions { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; margin-bottom: 16px; }
.inv-actions .buttons { display: flex; gap: 10px; flex-wrap: wrap; }
.btn-plain { background: #f8fafc; border: 1px solid #cbd5e1; color: #0f172a; padding: 8px 14px; text-decoration: none; font-size: 0.95rem; border-radius: 6px; display: inline-block; }
.btn-plain:hover { background: #e2e8f0; }
</style>

<div class="card-flat">
    <div class="card-body">
        <div class="inv-box">
            <div class="inv-actions no-print">
                <div style="font-size:1rem; font-weight:700;">فاتورة مشتريات #<?php echo $invoice_id; ?></div>
                <div class="buttons">
                    <button onclick="window.print()" class="btn-plain">طباعة</button>
                    <?php if (in_array($_SESSION['SESS_LAST_NAME'], ['admin'], true)): ?>
                    <a href="edit.php?id=<?php echo $invoice_id; ?>" class="btn-plain">تعديل</a>
                    <?php endif; ?>
                    <a href="index.php" class="btn-plain">عودة</a>
                </div>
            </div>
            <!-- الترويسة -->
            <div class="inv-header">
                <div class="row align-items-center">
                    <div class="col-md-7">
                        <?php if (!empty($global_settings['logo'])): ?>
                        <img src="<?php echo htmlspecialchars($logo_url); ?>" style="max-height:70px; width:auto; margin-bottom:8px;"><br>
                        <?php endif; ?>
                        <div class="inv-store-name"><?php echo htmlspecialchars($store_name); ?></div>
                        <div class="inv-store-sub"><?php echo htmlspecialchars($address); ?></div>
                        <div class="inv-store-sub">هاتف: <?php echo htmlspecialchars($phone); ?></div>
                    </div>
                    <div class="col-md-5 inv-title-box">
                        <h2>فاتورة مشتريات رسمية</h2>
                        <p class="inv-num">رقم الفاتورة: #<?php echo $invoice_id; ?></p>
                        <p class="inv-date">التاريخ: <?php echo htmlspecialchars($build_date); ?></p>
                    </div>
                </div>
            </div>

            <!-- معلومات الفاتورة -->
            <div class="inv-info-section">
                <div class="inv-info-row">
                    <div class="inv-info-item">المورد: <span><?php echo htmlspecialchars($supplier_name ?: 'غير محدد'); ?></span></div>
                    <div class="inv-info-item">العملة: <span><?php echo htmlspecialchars($currency_code); ?></span></div>
                    <?php if ($currency_code !== 'YER'): ?>
                    <div class="inv-info-item">سعر الصرف: <span><?php echo number_format($exchange_rate, 2); ?> ر.ي</span></div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- جدول البنود -->
            <table class="inv-table">
                <thead>
                    <tr>
                        <th style="width:5%;">#</th>
                        <th style="width:40%; text-align:right;">اسم المنتج / الصنف</th>
                        <th style="width:10%;">الكمية</th>
                        <th style="width:20%;">سعر الوحدة (<?php echo $currency_code; ?>)</th>
                        <th style="width:25%;">الإجمالي (<?php echo $currency_code; ?>)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $num = 1;
                    $calc_total = 0;
                    $calc_paid = 0;
                    $calc_remaining = 0;
                    // جلب إجمالي المرتجعات لهذه الفاتورة
                    $res_total_ret = $conn->query("SELECT COALESCE(SUM(refund_amount),0) AS total_ret FROM purchase_returns WHERE purchase_id = $invoice_id AND status='active'");
                    $total_ret_row = $res_total_ret ? $res_total_ret->fetch_assoc() : ['total_ret' => 0];
                    $total_returns = doubleval($total_ret_row['total_ret']);

                    if ($result_items && $result_items->num_rows > 0):
                        while ($item = $result_items->fetch_assoc()):
                            $item_total_base = doubleval($item['buy_price']);
                            $item_paid_base = doubleval($item['pushtosupp']);
                            $item_remaining_base = doubleval($item['total_d']);

                            // حساب المردودات لهذا البند (مطابقة بالاسم داخل نفس الفاتورة)
                            $ret_res = $conn->query("SELECT COALESCE(SUM(quantity),0) AS ret_qty, COALESCE(SUM(refund_amount),0) AS ret_amount FROM purchase_returns WHERE purchase_id = $invoice_id AND product_name='" . $conn->real_escape_string($item['name']) . "' AND status='active'");
                            $ret_row = $ret_res ? $ret_res->fetch_assoc() : ['ret_qty' => 0, 'ret_amount' => 0];
                            $ret_qty = intval($ret_row['ret_qty']);
                            $ret_amount = doubleval($ret_row['ret_amount']);

                            $item_total_orig = ($item_total_base - $ret_amount) / $exchange_rate;
                            $item_paid_orig = max(0, $item_paid_base - $ret_amount) / $exchange_rate;
                            $item_remaining_orig = max(0, $item_remaining_base - $ret_amount) / $exchange_rate;

                            $qty = max(0, intval($item['quantity']) - $ret_qty);
                            $unit_price = $qty > 0 ? ($item_total_orig / $qty) : 0;
                            $calc_total += $item_total_orig;
                            $calc_paid += $item_paid_orig;
                            $calc_remaining += $item_remaining_orig;
                    ?>
                    <tr>
                        <td><?php echo $num++; ?></td>
                        <td><?php echo htmlspecialchars($item['name']); ?><?php if ($ret_qty > 0) echo ' <small class="text-danger">(مرتجع: ' . $ret_qty . ')</small>'; ?></td>
                        <td><?php echo $qty; ?></td>
                        <td><?php echo number_format($unit_price, 2); ?></td>
                        <td style="font-weight:700;"><?php echo number_format($item_total_orig, 2); ?></td>
                    </tr>
                    <?php endwhile; else: ?>
                    <tr><td colspan="5" class="text-center text-muted p-4">لا توجد بنود</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <!-- المجاميع (تقرير رسمي أسفل الفاتورة) -->
            <div class="inv-summary-bottom" style="margin-top:20px;">
                <?php if (!empty($remark)): ?>
                <div style="background:#f8fafc; border:1px solid #e2e8f0; padding:12px; font-size:0.88rem; margin-bottom:12px;">
                    <strong>ملاحظات:</strong> <?php echo nl2br(htmlspecialchars($remark)); ?>
                </div>
                <?php endif; ?>

                <table style="width:100%; border-collapse:collapse;">
                    <tbody>
                        <tr>
                            <td style="text-align:right; padding:8px 12px; border-top:2px solid #e2e8f0;">إجمالي الفاتورة</td>
                            <td style="text-align:left; padding:8px 12px; border-top:2px solid #e2e8f0; font-weight:700;"><?php echo number_format($calc_total, 2); ?> <?php echo $currency_code; ?></td>
                        </tr>
                        <tr>
                            <td style="text-align:right; padding:8px 12px;">المدفوع للمورد</td>
                            <td style="text-align:left; padding:8px 12px; color:#0f766e; font-weight:700;"><?php echo number_format($calc_paid, 2); ?> <?php echo $currency_code; ?></td>
                        </tr>
                        <tr>
                            <td style="text-align:right; padding:8px 12px;">المتبقي (مديونية)</td>
                            <td style="text-align:left; padding:8px 12px; color:#be123c; font-weight:700;"><?php echo number_format($calc_remaining, 2); ?> <?php echo $currency_code; ?></td>
                        </tr>
                        <?php if ($currency_code !== 'YER'): ?>
                        <tr>
                            <td style="text-align:right; padding:8px 12px;">المكافئ بالريال</td>
                            <td style="text-align:left; padding:8px 12px; font-weight:700; color:#93c5fd;"><?php echo number_format($total_base, 2); ?> ر.ي</td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- التوقيعات والتذييل -->
            <div class="inv-sig" style="display: flex; justify-content: space-between; margin-top: 50px; padding-top: 10px; font-size: 0.85rem; direction: rtl;">
                <div style="text-align: center; min-width: 150px; border-top: 1px solid #000; padding-top: 5px;">توقيع المستلم/أمين المستودع<br><small>___________________</small></div>
                <div style="text-align: center; min-width: 150px; border-top: 1px solid #000; padding-top: 5px;">ختم المؤسسة/المتجر<br><small>___________________</small></div>
                <div style="text-align: center; min-width: 150px; border-top: 1px solid #000; padding-top: 5px;">توقيع المدير المالي/المسؤول<br><small>___________________</small></div>
            </div>

            <div class="inv-footer">
                <small><?php echo htmlspecialchars($store_name); ?> &copy; <?php echo date("Y"); ?></small>
            </div>
        </div>
    </div>
</div>

<?php require_once($dir_prefix . 'includes/footer.php'); ?>
