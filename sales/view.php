<?php

$dir_prefix = '../';
$module = 'sales';
$no_print_header = true;
require_once($dir_prefix . 'includes/header.php');

check_permission(['admin', 'cashier']);

if (!isset($_GET['id']) || empty($_GET['id'])) {
    echo "<div class='alert alert-danger rounded-0'>خطأ: لم يتم تحديد رقم الفاتورة.</div>";
    require_once($dir_prefix . 'includes/footer.php');
    exit;
}

$invoice_id = intval($_GET['id']);

$sql_invoice = "SELECT * FROM sales WHERE id = $invoice_id";
$res_invoice = $conn->query($sql_invoice);
$invoice = ($res_invoice) ? $res_invoice->fetch_assoc() : null;

if (!$invoice) {
    echo "<div class='alert alert-danger rounded-0'>خطأ: الفاتورة غير موجودة.</div>";
    require_once($dir_prefix . 'includes/footer.php');
    exit;
}

$sql_items = "SELECT * FROM sales_items WHERE sales_id = $invoice_id";
$result_items = $conn->query($sql_items);

$settings_res = $conn->query("SELECT * FROM settings WHERE id = 1");
$settings = $settings_res ? $settings_res->fetch_assoc() : null;
if (!$settings) {
    $settings = ['store_name' => 'تكنولوجيا فون','phone' => '','address' => '','currency' => 'ريال يمني','tax_percent' => 0,'printer_type' => 'receipt_80mm','receipt_footer' => 'شكرًا لزيارتكم!'];
}

$is_thermal = ($settings['printer_type'] === 'receipt_80mm' || $settings['printer_type'] === 'receipt_58mm');
$currency = $settings['currency'];

// حساب المجاميع مسبقاً
$grand_line_total = 0;
$grand_paid = 0;
$grand_discount = 0;
$grand_remaining = 0;
$items_data = [];
if ($result_items && $result_items->num_rows > 0) {
    while ($row = $result_items->fetch_assoc()) {
        $p_name = $row['name'];
        $parts = explode(' ', $p_name, 2);
        if (count($parts) > 1 && is_numeric($parts[0])) $p_name = $parts[1];
        $line_total = floatval($row['all_tot']);
        $paid = floatval($row['bush']);
        $discount = floatval($row['d']);
        $remaining = floatval($row['dis']);
        $grand_line_total += $line_total;
        $grand_paid += $paid;
        $grand_discount += $discount;
        $grand_remaining += $remaining;
        $items_data[] = ['name' => $p_name, 'quantity' => $row['quantity'], 'unit_price' => floatval($row['unit_price']), 'line_total' => $line_total, 'discount' => $discount, 'remaining' => $remaining];
    }
}
$tax_val = ($settings['tax_percent'] > 0) ? ($grand_line_total * floatval($settings['tax_percent'])) / 100 : 0;
$net_total = $grand_line_total + $tax_val - $grand_discount;
?>
<title>فاتورة مبيعات #<?php echo $invoice_id; ?> - <?php echo htmlspecialchars($settings['store_name']); ?></title>

<style>
/* ======= طباعة عامة ======= */
@media print {
    body { background: #fff !important; color: #000 !important; margin: 0 !important; padding: 0 !important; font-size: 11pt !important; }
    .no-print { display: none !important; }
    #content { padding: 0 !important; margin: 0 !important; }
    .wrapper { display: block !important; }
    #sidebar { display: none !important; }
    .card-flat { border: none !important; background: transparent !important; }
    .card-body { padding: 0 !important; }
    <?php if ($is_thermal): ?>
    .a4-invoice-box { display: none !important; }
    .thermal-receipt-box { width: <?php echo ($settings['printer_type'] === 'receipt_58mm') ? '58mm' : '80mm'; ?> !important; margin: 0 !important; padding: 2mm !important; font-size: 9pt !important; }
    .thermal-receipt-box th, .thermal-receipt-box td { font-size: 9pt !important; padding: 1mm 0 !important; }
    <?php else: ?>
    .thermal-receipt-box { display: none !important; }
    .a4-invoice-box { width: 100% !important; padding: 8mm !important; margin: 0 !important; }
    .inv-header { border-bottom: 2px solid #000 !important; }
    .inv-table th { background: #1e293b !important; color: #fff !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .inv-totals { border-top: 2px solid #000 !important; }
    <?php endif; ?>
}
/* ======= شاشة ======= */
@media screen {
    <?php if ($is_thermal): ?>
    .thermal-receipt-box { max-width: <?php echo ($settings['printer_type'] === 'receipt_58mm') ? '340px' : '420px'; ?>; margin: 20px auto; background: #fff; border: 1px dashed #aaa; padding: 15px; font-family: 'Courier New', monospace; font-size: 13px; }
    .a4-invoice-box { display: none; }
    <?php else: ?>
    .thermal-receipt-box { display: none; }
    .a4-invoice-box { max-width: 950px; margin: 20px auto; background: #fff; padding: 30px; border: 1px solid #e2e8f0; }
    <?php endif; ?>
}
/* ======= أنماط مشتركة ======= */
.inv-header { padding-bottom: 15px; margin-bottom: 20px; border-bottom: 2px solid #1e293b; }
.inv-store-name { font-size: 1.6rem; font-weight: 700; color: #0f172a; }
.inv-store-sub { font-size: 0.88rem; color: #64748b; margin: 2px 0; }
.inv-title-box { text-align: left; }
.inv-title-box h2 { font-size: 1.4rem; font-weight: 700; color: #0f172a; margin: 0; }
.inv-title-box .inv-num { font-size: 1rem; color: #0369a1; font-weight: 600; margin: 4px 0 0 0; }
.inv-title-box .inv-date { font-size: 0.85rem; color: #64748b; }
.inv-info-section { background: #f8fafc; border: 1px solid #e2e8f0; padding: 12px 16px; margin-bottom: 20px; }
.inv-info-row { display: flex; justify-content: space-between; flex-wrap: wrap; gap: 10px; }
.inv-info-item { font-size: 0.9rem; }
.inv-info-item span { font-weight: 700; }
.inv-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
.inv-table th { background: #1e293b; color: #fff; padding: 10px 12px; font-size: 0.88rem; font-weight: 600; text-align: center; border: 1px solid #1e293b; }
.inv-table td { padding: 9px 12px; font-size: 0.88rem; border: 1px solid #e2e8f0; text-align: center; vertical-align: middle; }
.inv-table tbody tr:nth-child(even) { background: #f8fafc; }
.inv-table td:first-child { text-align: right; font-weight: 600; }
.inv-totals { margin-top: 20px; }
.totals-box { width: 300px; margin-right: auto; border: 1px solid #e2e8f0; }
.totals-box table { width: 100%; border-collapse: collapse; }
.totals-box table tr td { padding: 8px 12px; font-size: 0.9rem; border-bottom: 1px solid #e2e8f0; }
.totals-box table tr:last-child td { border-bottom: none; }
.totals-box .tot-label { text-align: right; color: #475569; }
.totals-box .tot-value { text-align: left; font-weight: 700; }
.totals-box .tot-grand { background: #1e293b; color: #fff; font-size: 1.05rem; }
.totals-box .tot-paid { color: #0f766e; }
.totals-box .tot-remaining { color: #be123c; }
.inv-footer { text-align: center; border-top: 1px solid #e2e8f0; margin-top: 30px; padding-top: 15px; color: #64748b; font-size: 0.85rem; }
.inv-sig { display: flex; justify-content: space-between; margin-top: 40px; padding-top: 10px; font-size: 0.85rem; }
.inv-sig div { text-align: center; border-top: 1px solid #000; padding-top: 5px; min-width: 150px; }
/* الحراري */
.dashed-line { border-top: 1px dashed #000; margin: 6px 0; }
.double-dashed-line { border-top: 3px double #000; margin: 6px 0; }
.receipt-table th, .receipt-table td { border: none !important; }
</style>

<div class="card-flat">
    <div class="card-header no-print">
        <h5><?php echo get_icon('reports', 'ml-2'); ?> فاتورة مبيعات #<?php echo $invoice_id; ?></h5>
        <div>
            <button onclick="window.print()" class="btn-flat btn-flat-success btn-sm ml-2">
                <?php echo get_icon('print', 'ml-1'); ?> طباعة
            </button>
            <a href="index.php" class="btn-flat btn-flat-secondary btn-sm text-decoration-none ml-2">
                <?php echo get_icon('logout', 'ml-1'); ?> القائمة
            </a>
        </div>
    </div>
    <div class="card-body">

<?php if ($is_thermal): ?>
<!-- ===== فاتورة حرارية ===== -->
<div class="thermal-receipt-box">
    <div class="text-center">
        <?php if (!empty($global_settings['logo'])): ?>
        <img src="<?php echo htmlspecialchars($logo_url); ?>" style="max-height:50px; width:auto; margin-bottom:5px;"><br>
        <?php endif; ?>
        <strong style="font-size:14px;"><?php echo htmlspecialchars($settings['store_name']); ?></strong><br>
        <span style="font-size:10px;"><?php echo htmlspecialchars($settings['address']); ?></span><br>
        <span style="font-size:10px;">هاتف: <?php echo htmlspecialchars($settings['phone']); ?></span>
    </div>
    <div class="dashed-line"></div>
    <div style="font-size:12px;">
        <strong>فاتورة مبيعات</strong><br>
        رقم: #<?php echo $invoice_id; ?> &nbsp;|&nbsp; <?php echo htmlspecialchars($invoice['build_date']); ?><br>
        العميل: <?php echo htmlspecialchars($invoice['cust_name'] ?: 'نقدي'); ?>
    </div>
    <div class="dashed-line"></div>
    <table class="receipt-table" style="width:100%; font-size:11px;">
        <thead>
            <tr style="border-bottom:1px dashed #000;">
                <th style="text-align:right; width:50%;">الصنف</th>
                <th style="text-align:center; width:15%;">ك</th>
                <th style="text-align:left; width:35%;">المجموع</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($items_data as $item): ?>
            <tr>
                <td style="text-align:right; font-weight:bold;"><?php echo htmlspecialchars($item['name']); ?></td>
                <td style="text-align:center;"><?php echo $item['quantity']; ?></td>
                <td style="text-align:left;"><?php echo number_format($item['line_total'], 0); ?></td>
            </tr>
            <tr style="font-size:9px; color:#555;">
                <td colspan="3" style="text-align:right; padding-bottom:4px;">
                    <?php echo $item['quantity']; ?> × <?php echo number_format($item['unit_price'], 0); ?>
                    <?php if ($item['discount'] > 0) echo ' (خصم: ' . number_format($item['discount'], 0) . ')'; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <div class="dashed-line"></div>
    <table class="receipt-table" style="width:100%; font-size:11px;">
        <tr><td style="text-align:right;">المجموع:</td><td style="text-align:left;"><?php echo number_format($grand_line_total, 0); ?></td></tr>
        <?php if ($grand_discount > 0): ?><tr><td style="text-align:right;">الخصم:</td><td style="text-align:left;">- <?php echo number_format($grand_discount, 0); ?></td></tr><?php endif; ?>
        <?php if ($tax_val > 0): ?><tr><td style="text-align:right;">الضريبة (<?php echo $settings['tax_percent']; ?>%):</td><td style="text-align:left;"><?php echo number_format($tax_val, 0); ?></td></tr><?php endif; ?>
        <tr style="font-weight:bold; font-size:13px;"><td style="text-align:right;">المدفوع:</td><td style="text-align:left;"><?php echo number_format($grand_paid, 0); ?> <?php echo $currency; ?></td></tr>
        <?php if ($grand_remaining > 0): ?><tr style="font-weight:bold; color:#be123c;"><td style="text-align:right;">المتبقي:</td><td style="text-align:left;"><?php echo number_format($grand_remaining, 0); ?> <?php echo $currency; ?></td></tr><?php endif; ?>
    </table>
    <div class="double-dashed-line"></div>
    <div style="text-align:center; font-size:10px; margin-top:8px;">
        <strong><?php echo nl2br(htmlspecialchars($settings['receipt_footer'])); ?></strong>
    </div>
</div>

<?php else: ?>
<!-- ===== فاتورة A4 ===== -->
<div class="a4-invoice-box">
    <!-- الترويسة -->
    <div class="inv-header">
        <div class="row align-items-center">
            <div class="col-md-7">
                <?php if (!empty($global_settings['logo'])): ?>
                <img src="<?php echo htmlspecialchars($logo_url); ?>" style="max-height:70px; width:auto; margin-bottom:8px;"><br>
                <?php endif; ?>
                <div class="inv-store-name"><?php echo htmlspecialchars($settings['store_name']); ?></div>
                <div class="inv-store-sub"><?php echo htmlspecialchars($settings['address']); ?></div>
                <div class="inv-store-sub">هاتف: <?php echo htmlspecialchars($settings['phone']); ?></div>
            </div>
            <div class="col-md-5 inv-title-box">
                <h2>فاتورة مبيعات رسمية</h2>
                <p class="inv-num">رقم الفاتورة: #<?php echo $invoice_id; ?></p>
                <p class="inv-date">التاريخ: <?php echo htmlspecialchars($invoice['build_date']); ?></p>
            </div>
        </div>
    </div>

    <!-- معلومات الفاتورة -->
    <div class="inv-info-section">
        <div class="inv-info-row">
            <div class="inv-info-item">العميل: <span><?php echo htmlspecialchars($invoice['cust_name'] ?: 'عميل نقدي'); ?></span></div>
            <div class="inv-info-item">طريقة الدفع: <span class="badge badge-<?php echo ($grand_remaining > 0) ? 'warning' : 'success'; ?> px-2"><?php echo ($grand_remaining > 0) ? 'دفع جزئي / أجل' : 'نقداً كامل'; ?></span></div>
            <div class="inv-info-item">المسؤول: <span><?php echo isset($_SESSION['SESS_FIRST_NAME']) ? htmlspecialchars($_SESSION['SESS_FIRST_NAME']) : '---'; ?></span></div>
        </div>
    </div>

    <!-- جدول المنتجات -->
    <table class="inv-table">
        <thead>
            <tr>
                <th style="width:5%; text-align:center;">#</th>
                <th style="width:35%; text-align:right;">اسم المنتج / الصنف</th>
                <th style="width:10%;">الكمية</th>
                <th style="width:15%;">سعر الوحدة</th>
                <th style="width:15%;">الإجمالي</th>
                <th style="width:10%;">الخصم</th>
                <th style="width:10%;">الصافي</th>
            </tr>
        </thead>
        <tbody>
            <?php $i = 1; foreach ($items_data as $item): ?>
            <tr>
                <td style="text-align:center; color:#64748b;"><?php echo $i++; ?></td>
                <td style="text-align:right; font-weight:600;"><?php echo htmlspecialchars($item['name']); ?></td>
                <td><?php echo $item['quantity']; ?></td>
                <td><?php echo number_format($item['unit_price'], 2); ?></td>
                <td><?php echo number_format($item['line_total'], 2); ?></td>
                <td style="color:#b45309;"><?php echo number_format($item['discount'], 2); ?></td>
                <td style="font-weight:700;"><?php echo number_format($item['line_total'] - $item['discount'], 2); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <!-- المجاميع -->
    <div class="inv-totals">
        <div class="row">
            <div class="col-md-7">
                <?php if (!empty($invoice['remark'])): ?>
                <div style="background:#f8fafc; border:1px solid #e2e8f0; padding:12px; font-size:0.88rem;">
                    <strong>ملاحظات:</strong> <?php echo nl2br(htmlspecialchars($invoice['remark'])); ?>
                </div>
                <?php endif; ?>
            </div>
            <div class="col-md-5">
                <div class="totals-box">
                    <table>
                        <tr>
                            <td class="tot-label">المجموع الفرعي:</td>
                            <td class="tot-value"><?php echo number_format($grand_line_total, 2); ?> <?php echo $currency; ?></td>
                        </tr>
                        <?php if ($grand_discount > 0): ?>
                        <tr>
                            <td class="tot-label">إجمالي الخصم:</td>
                            <td class="tot-value" style="color:#b45309;">- <?php echo number_format($grand_discount, 2); ?> <?php echo $currency; ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if ($tax_val > 0): ?>
                        <tr>
                            <td class="tot-label">ضريبة (<?php echo $settings['tax_percent']; ?>%):</td>
                            <td class="tot-value"><?php echo number_format($tax_val, 2); ?> <?php echo $currency; ?></td>
                        </tr>
                        <?php endif; ?>
                        <tr class="tot-grand">
                            <td class="tot-label" style="color:#fff; font-size:1rem;">المبلغ المستلم:</td>
                            <td class="tot-value tot-paid" style="color:#4ade80; font-size:1.05rem;"><?php echo number_format($grand_paid, 2); ?> <?php echo $currency; ?></td>
                        </tr>
                        <?php if ($grand_remaining > 0): ?>
                        <tr style="background:#fff5f5;">
                            <td class="tot-label tot-remaining">المتبقي (أجل):</td>
                            <td class="tot-value tot-remaining"><?php echo number_format($grand_remaining, 2); ?> <?php echo $currency; ?></td>
                        </tr>
                        <?php endif; ?>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- التوقيعات والتذييل -->
    <div class="inv-sig no-print">
        <div>توقيع المسؤول<br><small>___________________</small></div>
        <div>ختم المتجر<br><small>___________________</small></div>
        <div>توقيع العميل<br><small>___________________</small></div>
    </div>

    <div class="inv-footer">
        <p style="font-weight:600; font-size:1rem; margin-bottom:4px;"><?php echo htmlspecialchars($settings['receipt_footer']); ?></p>
        <small style="color:#94a3b8;"><?php echo htmlspecialchars($settings['store_name']); ?> &copy; <?php echo date("Y"); ?></small>
    </div>
</div>
<?php endif; ?>

    </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function() {
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('autoprint') === '1') window.print();
    window.addEventListener("keydown", function(e) {
        if (e.key === "Enter") { e.preventDefault(); window.print(); }
    });
});
</script>

<?php require_once($dir_prefix . 'includes/footer.php'); ?>
