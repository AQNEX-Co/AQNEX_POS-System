<?php
$dir_prefix = '../';
$module = 'purchases';
$no_print_header = true;
require_once($dir_prefix . 'includes/header.php');

check_permission(['admin', 'inventory', 'cashier']);

if (!isset($_GET['id']) || empty($_GET['id'])) {
    echo "<div class='alert alert-danger rounded-0'>خطأ: لم يتم تحديد رقم الفاتورة.</div>";
    require_once($dir_prefix . 'includes/footer.php');
    exit;
}

$invoice_id = intval($_GET['id']);

// 1. جلب بيانات رأس الفاتورة (Master)
$sql_invoice = "SELECT * FROM purchase_invoices_mst WHERE id = $invoice_id AND d_s = 0";
$res_invoice = $conn->query($sql_invoice);
$invoice = ($res_invoice) ? $res_invoice->fetch_assoc() : null;

if (!$invoice) {
    echo "<div class='alert alert-danger rounded-0'>خطأ: الفاتورة غير موجودة أو تم حذفها.</div>";
    require_once($dir_prefix . 'includes/footer.php');
    exit;
}

$invoice_no = $invoice['invoice_no'] ?? '#' . $invoice_id;
$build_date = $invoice['invoice_date'];
$supplier_name = $invoice['supp_name'];
$total_amount = doubleval($invoice['total_amount']);
$paid_amount = doubleval($invoice['paid_amount']);
$remaining_amount = doubleval($invoice['remaining_amount']);
$remark = $invoice['remark'];
$currency_code = $invoice['currency_code'] ?? 'YER';
$exchange_rate = doubleval($invoice['exchange_rate'] ?? 1.0);
if ($exchange_rate <= 0) $exchange_rate = 1.0;

// 2. جلب بنود الفاتورة (Detail)
$sql_items = "SELECT * FROM purchase_invoices_dtl WHERE invoice_id = $invoice_id AND d_s = 0 ORDER BY id ASC";
$result_items = $conn->query($sql_items);

// 3. جلب ملخص المرتجعات لهذه الفاتورة (لخصمها من الكميات المعروضة)
$ret_lookup = [];
$sql_returns = "SELECT d.product_id, SUM(d.quantity) as ret_qty, SUM(d.total_cost) as ret_amount
                FROM purchase_returns_dtl d
                JOIN purchase_returns_mst m ON d.return_id = m.id
                WHERE m.original_purchase_id = $invoice_id AND m.d_s = 0
                GROUP BY d.product_id";
$res_returns = $conn->query($sql_returns);
if ($res_returns) {
    while ($ret_row = $res_returns->fetch_assoc()) {
        $ret_lookup[$ret_row['product_id']] = [
            'qty' => doubleval($ret_row['ret_qty']),
            'amount' => doubleval($ret_row['ret_amount'])
        ];
    }
}

// إعدادات المتجر
$store_name = $global_settings['store_name'] ?? 'المتجر';
$phone = $global_settings['phone'] ?? '';
$address = $global_settings['address'] ?? '';
$currency_symbol = $global_settings['currency'] ?? 'ر.ي';
$logo_url = !empty($global_settings['logo']) ? $dir_prefix . $global_settings['logo'] : '';
?>
<title>فاتورة مشتريات <?php echo htmlspecialchars($invoice_no); ?> - <?php echo htmlspecialchars($store_name); ?></title>

<style>
    @page { size: A4 portrait; margin: 15mm; }
    body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f1f5f9; color: #0f172a; direction: rtl; }
    
    @media print {
        body { background: #fff !important; color: #000 !important; margin: 0 !important; padding: 0 !important; font-size: 11pt !important; }
        .no-print { display: none !important; }
        #content, .wrapper, .card-body { padding: 0 !important; margin: 0 !important; background: #fff !important; }
        #sidebar, .navbar-top { display: none !important; }
        .inv-box { margin: 0 !important; padding: 0 !important; border: none !important; box-shadow: none !important; width: 100% !important; max-width: 100% !important; }
        .inv-table thead { display: table-header-group; }
        .inv-table tr { page-break-inside: avoid; }
        .inv-header { border-bottom: 2px solid #000 !important; }
    }

    @media screen {
        .inv-box { max-width: 900px; margin: 30px auto; background: #fff; padding: 40px; border: 1px solid #e2e8f0; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1); border-radius: 8px; }
    }

    .inv-header { padding-bottom: 20px; margin-bottom: 25px; border-bottom: 2px solid #e2e8f0; }
    .inv-store-name { font-size: 1.8rem; font-weight: 800; color: #0f172a; letter-spacing: -0.5px; }
    .inv-store-sub { font-size: 0.9rem; color: #475569; margin-top: 4px; display: flex; align-items: center; gap: 6px; }
    .inv-title-box { text-align: left; }
    .inv-title-box h2 { font-size: 1.6rem; font-weight: 800; margin: 0; color: #1e293b; }
    .inv-badge { display: inline-block; background: #0f172a; color: #fff; padding: 4px 12px; border-radius: 4px; font-size: 0.85rem; font-weight: 600; margin-bottom: 8px; }
    .inv-meta { font-size: 0.95rem; color: #334155; margin: 6px 0; font-weight: 500; }
    .inv-meta strong { color: #0f172a; }

    .inv-info-section { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 16px; margin-bottom: 25px; }
    .inv-info-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; }
    .inv-info-item { font-size: 0.95rem; color: #475569; }
    .inv-info-item span { display: block; font-weight: 700; color: #0f172a; font-size: 1.05rem; margin-top: 4px; }

    .inv-table { width: 100%; border-collapse: collapse; margin-bottom: 25px; font-size: 0.95rem; }
    .inv-table th { background: #f1f5f9; color: #0f172a; font-weight: 700; padding: 12px; border: 1px solid #cbd5e1; text-align: center; }
    .inv-table td { padding: 12px; border: 1px solid #e2e8f0; color: #334155; text-align: center; }
    .inv-table td.text-right { text-align: right; }
    .inv-table tbody tr:nth-child(even) { background: #fafafa; }
    .inv-table tbody tr:hover { background: #f1f5f9; }

    .inv-summary { width: 100%; max-width: 400px; margin-right: auto; margin-top: 10px; }
    .inv-summary table { width: 100%; border-collapse: collapse; }
    .inv-summary td { padding: 10px 14px; border-bottom: 1px solid #e2e8f0; font-size: 1rem; }
    .inv-summary tr:last-child td { border-bottom: none; }
    .inv-summary .label { text-align: right; color: #475569; font-weight: 500; }
    .inv-summary .value { text-align: left; font-weight: 700; color: #0f172a; }
    .inv-summary .grand-total .value { color: #0f172a; font-size: 1.2rem; }
    .inv-summary .paid .value { color: #059669; }
    .inv-summary .remaining .value { color: #dc2626; }

    .inv-remark { background: #fffbeb; border: 1px solid #fcd34d; border-radius: 6px; padding: 12px 16px; font-size: 0.9rem; color: #92400e; margin-bottom: 20px; }
    
    .inv-sig { display: flex; justify-content: space-between; margin-top: 60px; padding-top: 20px; direction: rtl; page-break-inside: avoid; }
    .sig-box { text-align: center; min-width: 180px; }
    .sig-box .title { font-weight: 700; color: #0f172a; margin-bottom: 40px; font-size: 0.95rem; }
    .sig-box .line { border-top: 1px solid #0f172a; width: 100%; margin: 0 auto; }
    .sig-box .sub { font-size: 0.8rem; color: #64748b; margin-top: 6px; }

    .inv-footer { text-align: center; border-top: 1px solid #e2e8f0; margin-top: 40px; padding-top: 16px; color: #64748b; font-size: 0.85rem; }

    .inv-actions { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 1px solid #e2e8f0; }
    .btn-plain { background: #fff; border: 1px solid #cbd5e1; color: #0f172a; padding: 8px 16px; text-decoration: none; font-size: 0.9rem; border-radius: 6px; display: inline-flex; align-items: center; gap: 6px; transition: all 0.2s; cursor: pointer; }
    .btn-plain:hover { background: #f1f5f9; border-color: #94a3b8; }
    .btn-plain.primary { background: #0f172a; color: #fff; border-color: #0f172a; }
    .btn-plain.primary:hover { background: #1e293b; }
</style>

<div class="card-flat">
    <div class="card-body">
        <div class="inv-box">
            <!-- أزرار التحكم (لا تظهر في الطباعة) -->
            <div class="inv-actions no-print">
                <div style="font-size:1.1rem; font-weight:700; color:#0f172a;">
                    <i class="bi bi-file-earmark-text text-primary ml-2"></i> معاينة فاتورة مشتريات
                </div>
                <div class="buttons" style="display: flex; gap: 10px;">
                    <button onclick="window.print()" class="btn-plain primary">
                        <i class="bi bi-printer"></i> طباعة الفاتورة
                    </button>
                    <?php if (in_array($_SESSION['SESS_LAST_NAME'], ['admin'], true)): ?>
                    <a href="edit.php?id=<?php echo $invoice_id; ?>" class="btn-plain">
                        <i class="bi bi-pencil"></i> تعديل
                    </a>
                    <?php endif; ?>
                    <a href="index.php" class="btn-plain">
                        <i class="bi bi-arrow-right"></i> عودة للقائمة
                    </a>
                </div>
            </div>

            <!-- الترويسة -->
            <div class="inv-header">
                <div class="row align-items-center">
                    <div class="col-md-7">
                        <?php if (!empty($logo_url)): ?>
                        <img src="<?php echo htmlspecialchars($logo_url); ?>" style="max-height:60px; width:auto; margin-bottom:10px;"><br>
                        <?php endif; ?>
                        <div class="inv-store-name"><?php echo htmlspecialchars($store_name); ?></div>
                        <?php if (!empty($address)): ?>
                        <div class="inv-store-sub"><i class="bi bi-geo-alt"></i> <?php echo htmlspecialchars($address); ?></div>
                        <?php endif; ?>
                        <?php if (!empty($phone)): ?>
                        <div class="inv-store-sub"><i class="bi bi-telephone"></i> <?php echo htmlspecialchars($phone); ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-5 inv-title-box">
                        <span class="inv-badge">فاتورة مشتريات رسمية</span>
                        <h2><?php echo htmlspecialchars($invoice_no); ?></h2>
                        <div class="inv-meta">التاريخ: <strong><?php echo htmlspecialchars($build_date); ?></strong></div>
                        <div class="inv-meta">المورد: <strong><?php echo htmlspecialchars($supplier_name ?: 'مورد عام'); ?></strong></div>
                    </div>
                </div>
            </div>

            <!-- معلومات إضافية -->
            <div class="inv-info-section">
                <div class="inv-info-grid">
                    <div class="inv-info-item">
                        حالة الدفع
                        <span>
                            <?php 
                            $type_text = 'نقدي';
                            if ($invoice['invoice_type'] === 'credit') $type_text = 'آجل';
                            elseif ($invoice['invoice_type'] === 'account') $type_text = 'من حساب';
                            echo $type_text;
                            ?>
                        </span>
                    </div>
                    <div class="inv-info-item">
                        العملة
                        <span><?php echo htmlspecialchars($currency_code); ?></span>
                    </div>
                    <?php if ($currency_code !== 'YER' && $exchange_rate > 1): ?>
                    <div class="inv-info-item">
                        سعر الصرف
                        <span><?php echo number_format($exchange_rate, 2); ?> ر.ي</span>
                    </div>
                    <?php endif; ?>
                    <div class="inv-info-item">
                        تم الإنشاء بواسطة
                        <span><?php echo htmlspecialchars($invoice['user_id'] ? 'المستخدم #' . $invoice['user_id'] : 'النظام'); ?></span>
                    </div>
                </div>
            </div>

            <!-- جدول البنود -->
            <table class="inv-table">
                <thead>
                    <tr>
                        <th style="width:5%;">#</th>
                        <th style="width:35%; text-align:right;">اسم المنتج / الصنف</th>
                        <th style="width:10%;">الوحدة</th>
                        <th style="width:15%;">الكمية</th>
                        <th style="width:15%;">سعر الوحدة</th>
                        <th style="width:20%;">الإجمالي</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $num = 1;
                    if ($result_items && $result_items->num_rows > 0):
                        while ($item = $result_items->fetch_assoc()):
                            $orig_qty = doubleval($item['quantity']);
                            $ret_info = $ret_lookup[$item['product_id']] ?? ['qty' => 0, 'amount' => 0];
                            $ret_qty = $ret_info['qty'];
                            $net_qty = max(0, $orig_qty - $ret_qty);
                            
                            $unit_cost = doubleval($item['unit_cost']);
                            $line_total = doubleval($item['total_cost']);
                            $net_line_total = max(0, $line_total - $ret_info['amount']);
                            
                            // عرض السعر والجمالي بالعملة الأصلية للفاتورة
                            $display_unit_cost = $unit_cost / $exchange_rate;
                            $display_line_total = $net_line_total / $exchange_rate;
                    ?>
                    <tr>
                        <td><?php echo $num++; ?></td>
                        <td class="text-right" style="font-weight:600;">
                            <?php echo htmlspecialchars($item['product_name']); ?>
                            <?php if (!empty($item['barcode'])): ?>
                                <br><small class="text-muted" style="font-weight:400; font-family: monospace;"><?php echo htmlspecialchars($item['barcode']); ?></small>
                            <?php endif; ?>
                            <?php if ($ret_qty > 0): ?>
                                <br><small class="text-danger" style="font-weight:600;"><i class="bi bi-arrow-counterclockwise"></i> تم إرجاع <?php echo $ret_qty; ?> وحدة</small>
                            <?php endif; ?>
                        </td>
                        <td><?php echo htmlspecialchars($item['unit_name'] ?: 'وحدة'); ?></td>
                        <td style="font-weight:700; <?php echo $ret_qty > 0 ? 'color:#dc2626;' : ''; ?>">
                            <?php echo number_format($net_qty, 2); ?>
                            <?php if ($ret_qty > 0): ?>
                                <br><small style="color:#64748b; font-weight:400;">(أصلي: <?php echo $orig_qty; ?>)</small>
                            <?php endif; ?>
                        </td>
                        <td><?php echo number_format($display_unit_cost, 2); ?></td>
                        <td style="font-weight:700;"><?php echo number_format($display_line_total, 2); ?></td>
                    </tr>
                    <?php endwhile; else: ?>
                    <tr><td colspan="6" class="text-center text-muted p-4">لا توجد بنود مسجلة في هذه الفاتورة</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <!-- المجاميع والملاحظات -->
            <div class="row">
                <div class="col-md-7">
                    <?php if (!empty($remark)): ?>
                    <div class="inv-remark">
                        <strong><i class="bi bi-info-circle"></i> ملاحظات:</strong><br>
                        <?php echo nl2br(htmlspecialchars($remark)); ?>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="col-md-5">
                    <div class="inv-summary">
                        <table>
                            <tr class="grand-total">
                                <td class="label">إجمالي الفاتورة</td>
                                <td class="value"><?php echo number_format($total_amount / $exchange_rate, 2); ?> <?php echo $currency_code; ?></td>
                            </tr>
                            <tr class="paid">
                                <td class="label">المبلغ المدفوع</td>
                                <td class="value"><?php echo number_format($paid_amount / $exchange_rate, 2); ?> <?php echo $currency_code; ?></td>
                            </tr>
                            <tr class="remaining">
                                <td class="label">الرصيد المتبقي (المديونية)</td>
                                <td class="value"><?php echo number_format($remaining_amount / $exchange_rate, 2); ?> <?php echo $currency_code; ?></td>
                            </tr>
                            <?php if ($currency_code !== 'YER' && $exchange_rate > 1): ?>
                            <tr style="background:#f8fafc; border-top:2px solid #e2e8f0;">
                                <td class="label" style="font-size:0.85rem; color:#64748b;">المكافئ بالريال اليمني</td>
                                <td class="value" style="font-size:0.95rem; color:#0f172a;"><?php echo number_format($total_amount, 2); ?> ر.ي</td>
                            </tr>
                            <?php endif; ?>
                        </table>
                    </div>
                </div>
            </div>

            <!-- التواقيع الرسمية -->
            <div class="inv-sig">
                <div class="sig-box">
                    <div class="title">توقيع أمين المستودع / المستلم</div>
                    <div class="line"></div>
                    <div class="sub">الاسم: ___________________</div>
                </div>
                <div class="sig-box">
                    <div class="title">ختم المؤسسة / المتجر</div>
                    <div class="line" style="border: 2px dashed #cbd5e1; height: 60px; border-top: none;"></div>
                </div>
                <div class="sig-box">
                    <div class="title">توقيع المدير المالي / المسؤول</div>
                    <div class="line"></div>
                    <div class="sub">الاسم: ___________________</div>
                </div>
            </div>

            <!-- التذييل -->
            <div class="inv-footer">
                <small>تم إصدار هذه الفاتورة إلكترونياً بواسطة نظام <?php echo htmlspecialchars($store_name); ?> &copy; <?php echo date("Y"); ?></small>
            </div>
        </div>
    </div>
</div>

<?php require_once($dir_prefix . 'includes/footer.php'); ?>