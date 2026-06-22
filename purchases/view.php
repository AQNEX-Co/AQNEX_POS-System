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

$sql_items = "SELECT * FROM purchase_items WHERE buys_date = '" . $conn->real_escape_string($build_date) . "' AND supp_name = '" . $conn->real_escape_string($supplier_name) . "'";
$result_items = $conn->query($sql_items);

$settings_res = $conn->query("SELECT * FROM settings WHERE id = 1");
$settings = $settings_res ? $settings_res->fetch_assoc() : null;
$store_name = $settings ? $settings['store_name'] : 'المتجر';
$phone = $settings ? $settings['phone'] : '';
$address = $settings ? $settings['address'] : '';
$currency = $settings ? $settings['currency'] : 'ريال يمني';
?>
<title>فاتورة مشتريات #<?php echo $invoice_id; ?> - <?php echo htmlspecialchars($store_name); ?></title>

<style>
@media print {
    body { background:#fff !important; color:#000 !important; margin:0 !important; padding:0 !important; font-size:11pt !important; }
    .no-print { display:none !important; }
    #content { padding:0 !important; margin:0 !important; }
    .wrapper { display:block !important; }
    #sidebar { display:none !important; }
    .card-flat { border:none !important; background:transparent !important; }
    .card-body { padding:0 !important; }
    .inv-table th { background:#1e293b !important; color:#fff !important; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
    .inv-header { border-bottom:2px solid #000 !important; }
}
@media screen {
    .inv-box { max-width:950px; margin:20px auto; background:#fff; padding:30px; border:1px solid #e2e8f0; }
}
.inv-header { padding-bottom:15px; margin-bottom:20px; border-bottom:2px solid #1e293b; }
.inv-store-name { font-size:1.6rem; font-weight:700; color:#0f172a; }
.inv-store-sub { font-size:0.88rem; color:#64748b; margin:2px 0; }
.inv-title-box h2 { font-size:1.4rem; font-weight:700; color:#0f172a; margin:0; text-align:left; }
.inv-title-box .inv-num { font-size:1rem; color:#0369a1; font-weight:600; margin:4px 0 0 0; text-align:left; }
.inv-title-box .inv-date { font-size:0.85rem; color:#64748b; text-align:left; }
.inv-info-section { background:#f8fafc; border:1px solid #e2e8f0; padding:12px 16px; margin-bottom:20px; }
.inv-info-row { display:flex; justify-content:space-between; flex-wrap:wrap; gap:10px; }
.inv-info-item { font-size:0.9rem; }
.inv-info-item span { font-weight:700; }
.inv-table { width:100%; border-collapse:collapse; margin-bottom:20px; }
.inv-table th { background:#1e293b; color:#fff; padding:10px 12px; font-size:0.88rem; font-weight:600; text-align:center; border:1px solid #1e293b; }
.inv-table td { padding:9px 12px; font-size:0.88rem; border:1px solid #e2e8f0; text-align:center; vertical-align:middle; }
.inv-table tbody tr:nth-child(even) { background:#f8fafc; }
.inv-table td:first-child { text-align:center; color:#64748b; }
.inv-table td:nth-child(2) { text-align:right; font-weight:600; }
.totals-box { width:300px; margin-right:auto; border:1px solid #e2e8f0; }
.totals-box table { width:100%; border-collapse:collapse; }
.totals-box table tr td { padding:8px 12px; font-size:0.9rem; border-bottom:1px solid #e2e8f0; }
.totals-box table tr:last-child td { border-bottom:none; }
.totals-box .tot-label { text-align:right; color:#475569; }
.totals-box .tot-value { text-align:left; font-weight:700; }
.totals-box .tot-grand { background:#1e293b; color:#fff; font-size:1.05rem; }
.inv-footer { text-align:center; border-top:1px solid #e2e8f0; margin-top:30px; padding-top:15px; color:#64748b; font-size:0.85rem; }
</style>

<div class="card-flat">
    <div class="card-header no-print">
        <h5><?php echo get_icon('purchases', 'ml-2'); ?> فاتورة مشتريات #<?php echo $invoice_id; ?></h5>
        <div>
            <button onclick="window.print()" class="btn-flat btn-flat-success btn-sm ml-2">
                <?php echo get_icon('print', 'ml-1'); ?> طباعة
            </button>
            <a href="index.php" class="btn-flat btn-flat-secondary btn-sm text-decoration-none ml-2">
                <?php echo get_icon('logout', 'ml-1'); ?> عودة
            </a>
        </div>
    </div>
    <div class="card-body">
        <div class="inv-box">
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
                    if ($result_items && $result_items->num_rows > 0):
                        while ($item = $result_items->fetch_assoc()):
                            $item_total_base = doubleval($item['buy_price']);
                            $item_paid_base = doubleval($item['pushtosupp']);
                            $item_remaining_base = doubleval($item['total_d']);
                            $item_total_orig = $item_total_base / $exchange_rate;
                            $item_paid_orig = $item_paid_base / $exchange_rate;
                            $item_remaining_orig = $item_remaining_base / $exchange_rate;
                            $qty = intval($item['quantity']);
                            $unit_price = $qty > 0 ? ($item_total_orig / $qty) : 0;
                            $calc_total += $item_total_orig;
                            $calc_paid += $item_paid_orig;
                            $calc_remaining += $item_remaining_orig;
                    ?>
                    <tr>
                        <td><?php echo $num++; ?></td>
                        <td><?php echo htmlspecialchars($item['name']); ?></td>
                        <td><?php echo $qty; ?></td>
                        <td><?php echo number_format($unit_price, 2); ?></td>
                        <td style="font-weight:700;"><?php echo number_format($item_total_orig, 2); ?></td>
                    </tr>
                    <?php endwhile; else: ?>
                    <tr><td colspan="5" class="text-center text-muted p-4">لا توجد بنود</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <!-- المجاميع -->
            <div class="row">
                <div class="col-md-7">
                    <?php if (!empty($remark)): ?>
                    <div style="background:#f8fafc; border:1px solid #e2e8f0; padding:12px; font-size:0.88rem;">
                        <strong>ملاحظات:</strong> <?php echo nl2br(htmlspecialchars($remark)); ?>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="col-md-5">
                    <div class="totals-box">
                        <table>
                            <tr><td class="tot-label">إجمالي الفاتورة:</td><td class="tot-value"><?php echo number_format($calc_total, 2); ?> <?php echo $currency_code; ?></td></tr>
                            <tr><td class="tot-label">المدفوع للمورد:</td><td class="tot-value" style="color:#0f766e;"><?php echo number_format($calc_paid, 2); ?> <?php echo $currency_code; ?></td></tr>
                            <?php if ($calc_remaining > 0): ?>
                            <tr><td class="tot-label">المتبقي (مديونية):</td><td class="tot-value" style="color:#be123c;"><?php echo number_format($calc_remaining, 2); ?> <?php echo $currency_code; ?></td></tr>
                            <?php endif; ?>
                            <?php if ($currency_code !== 'YER'): ?>
                            <tr class="tot-grand">
                                <td class="tot-label" style="color:#fff;">المكافئ بالريال:</td>
                                <td class="tot-value" style="color:#93c5fd;"><?php echo number_format($total_base, 2); ?> ر.ي</td>
                            </tr>
                            <?php endif; ?>
                        </table>
                    </div>
                </div>
            </div>

            <div class="inv-footer">
                <small><?php echo htmlspecialchars($store_name); ?> &copy; <?php echo date("Y"); ?></small>
            </div>
        </div>
    </div>
</div>

<?php require_once($dir_prefix . 'includes/footer.php'); ?>
