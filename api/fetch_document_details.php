<?php
$dir_prefix = '../';
require_once(__DIR__ . '/../includes/connect.php');
require_once(__DIR__ . '/../app/Services/SettingsService.php');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['SESS_MEMBER_ID'])) {
    echo '<div class="alert alert-danger text-center font-weight-bold">غير مصرح بالوصول</div>';
    exit;
}

// جلب إعدادات المنشأة والشعار
$settings = \AQNEX\Services\SettingsService::loadSettings($conn);
$store_name_ar = !empty($settings['store_name']) ? $settings['store_name'] : 'شركة أقنكس للأنظمة البرمجية المحدودة';
$store_name_en = !empty($settings['store_name_en']) ? $settings['store_name_en'] : 'AQNEX POS & ERP Systems Co.';
$phone         = !empty($settings['phone']) ? $settings['phone'] : '777777777';
$address_ar    = !empty($settings['address']) ? $settings['address'] : 'اليمن - عدن - الشارع الرئيسي';
$address_en    = !empty($settings['address_en']) ? $settings['address_en'] : 'Main St, Aden, Yemen';
$cr_number     = !empty($settings['commercial_register']) ? $settings['commercial_register'] : 'CR-104928';
$tax_number    = !empty($settings['tax_number']) ? $settings['tax_number'] : 'TAX-300192847';
$logo_src      = !empty($settings['logo']) ? '../' . ltrim($settings['logo'], '/') : '../assets/icon/tec.jpg';

$raw_type = isset($_GET['type']) ? trim($_GET['type']) : 'sale';
$raw_id   = isset($_GET['id']) ? trim($_GET['id']) : '';
$clean_id = intval(preg_replace('/[^0-9]/', '', $raw_id));
$esc_raw  = $conn->real_escape_string($raw_id);

// تطبيع نوع الوثيقة
$type = 'sale';
if (stripos($raw_type, 'مردود') !== false || strtolower($raw_type) === 'purchase_return') {
    $type = 'purchase_return';
} elseif (stripos($raw_type, 'مشتريات') !== false || strtolower($raw_type) === 'purchase') {
    $type = 'purchase';
} elseif (stripos($raw_type, 'قبض') !== false || strtolower($raw_type) === 'receipt') {
    $type = 'receipt';
} elseif (stripos($raw_type, 'صرف') !== false || strtolower($raw_type) === 'payment') {
    $type = 'payment';
} elseif (stripos($raw_type, 'سند') !== false || strtolower($raw_type) === 'voucher') {
    $type = 'voucher';
} elseif (stripos($raw_type, 'قيد') !== false || strtolower($raw_type) === 'journal') {
    $type = 'journal';
}

// اكتشاف تلقائي: رقم يبدأ بـ PR- هو مردود مشتريات
if ($type !== 'purchase_return' && preg_match('/^PR-/i', $raw_id)) {
    $type = 'purchase_return';
}

function renderOfficialHeader($store_name_ar, $store_name_en, $phone, $address_ar, $address_en, $cr_number, $tax_number, $logo_src) {
    ?>
    <div class="official-enterprise-header">
        <div class="header-right">
            <div class="company-title-ar"><?php echo htmlspecialchars($store_name_ar); ?></div>
            <div class="company-info-item">السجل التجاري: <strong><?php echo htmlspecialchars($cr_number); ?></strong></div>
            <div class="company-info-item">الرقم الضريبي: <strong><?php echo htmlspecialchars($tax_number); ?></strong></div>
            <div class="company-info-item">الهاتف: <strong><?php echo htmlspecialchars($phone); ?></strong></div>
            <div class="company-info-item">العنوان: <?php echo htmlspecialchars($address_ar); ?></div>
        </div>
        
        <div class="header-center">
            <img src="<?php echo htmlspecialchars($logo_src); ?>" class="company-logo-img" alt="Logo" onerror="this.src='../assets/icon/tec.jpg'">
        </div>
        
        <div class="header-left">
            <div class="company-title-en"><?php echo htmlspecialchars($store_name_en); ?></div>
            <div class="company-info-item">C.R: <strong><?php echo htmlspecialchars($cr_number); ?></strong></div>
            <div class="company-info-item">VAT No: <strong><?php echo htmlspecialchars($tax_number); ?></strong></div>
            <div class="company-info-item">Tel: <strong><?php echo htmlspecialchars($phone); ?></strong></div>
            <div class="company-info-item">Addr: <?php echo htmlspecialchars($address_en); ?></div>
        </div>
    </div>
    <?php
}

// ────────────────────────────────────────────────────────────────────────
// 1. معاينة فاتورة المبيعات (Sales Invoice)
// ────────────────────────────────────────────────────────────────────────
if ($type === 'sale') {
    $mst = null;
    $dtl = [];

    $chk = $conn->query("SHOW TABLES LIKE 'sales_invoices_mst'");
    if ($chk && $chk->num_rows > 0) {
        $res = $conn->query("SELECT * FROM sales_invoices_mst WHERE id = $clean_id OR invoice_no = '$esc_raw' LIMIT 1");
        if ($res && $res->num_rows > 0) {
            $mst = $res->fetch_assoc();
            $inv_id = intval($mst['id']);
            $res_dtl = $conn->query("SELECT * FROM sales_invoices_dtl WHERE invoice_id = $inv_id AND d_s = 0");
            if ($res_dtl) {
                while ($r = $res_dtl->fetch_assoc()) $dtl[] = $r;
            }
        }
    }

    if (!$mst && $clean_id > 0) {
        $chk_old = $conn->query("SHOW TABLES LIKE 'sales'");
        if ($chk_old && $chk_old->num_rows > 0) {
            $res_old = $conn->query("SELECT * FROM sales WHERE id = $clean_id LIMIT 1");
            if ($res_old && $res_old->num_rows > 0) {
                $row_old = $res_old->fetch_assoc();
                $mst = [
                    'id' => $row_old['id'],
                    'invoice_no' => $row_old['id'],
                    'invoice_date' => $row_old['build_date'] ?? date('Y-m-d'),
                    'cust_name' => $row_old['cust_name'] ?: 'عميل نقدي',
                    'net_amount' => $row_old['total'] ?? 0,
                    'paid_amount' => $row_old['total'] ?? 0,
                    'remaining_amount' => 0,
                    'invoice_type' => 'cash',
                    'remark' => $row_old['remark'] ?? '',
                    'username' => $row_old['username'] ?? 'المدير'
                ];
            }
        }
    }

    if (!$mst) {
        echo '<div class="alert alert-danger text-center p-3 font-weight-bold">لم يتم العثور على فاتورة المبيعات رقم #' . htmlspecialchars($raw_id) . '</div>';
        exit;
    }

    $inv_type_label = ($mst['invoice_type'] === 'credit') ? '<span class="badge bg-warning text-dark px-2 py-1">آجل</span>' : '<span class="badge bg-success px-2 py-1">نقداً</span>';
    ?>
    <div class="sap-doc-wrap dir-rtl text-right p-2">
        <?php renderOfficialHeader($store_name_ar, $store_name_en, $phone, $address_ar, $address_en, $cr_number, $tax_number, $logo_src); ?>

        <div class="official-report-banner">
            <h3>فاتورة مبيعات (Sales Invoice)</h3>
            <div class="banner-sub">رقم الفاتورة: <strong>#<?php echo htmlspecialchars($mst['invoice_no'] ?: $mst['id']); ?></strong> | التاريخ: <strong><?php echo htmlspecialchars($mst['invoice_date']); ?></strong></div>
        </div>

        <div class="row mb-3 p-2 bg-light border">
            <div class="col-md-6"><strong>اسم العميل:</strong> <?php echo htmlspecialchars($mst['cust_name'] ?: 'عميل نقدي'); ?></div>
            <div class="col-md-6 text-left"><strong>طريقة الدفع:</strong> <?php echo $inv_type_label; ?> | <strong>الكاشير:</strong> <?php echo htmlspecialchars($mst['username'] ?? 'المدير'); ?></div>
            <div class="col-md-12 mt-1"><strong>البيان / ملاحظات:</strong> <?php echo htmlspecialchars($mst['remark'] ?: 'بيع بضاعة'); ?></div>
        </div>

        <table class="sap-grid-table table-bordered mb-3">
            <thead>
                <tr>
                    <th style="width:5%;">#</th>
                    <th style="width:40%;">اسم الصنف / المنتج</th>
                    <th style="width:15%;">الوحدة</th>
                    <th style="width:12%;">الكمية</th>
                    <th style="width:14%;">السعر (ر.ي)</th>
                    <th style="width:14%;">الإجمالي (ر.ي)</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($dtl)): ?>
                    <?php $idx = 1; foreach ($dtl as $d): ?>
                    <tr>
                        <td class="text-center font-weight-bold"><?php echo $idx++; ?></td>
                        <td class="font-weight-bold"><?php echo htmlspecialchars($d['product_name']); ?></td>
                        <td class="text-center"><?php echo htmlspecialchars($d['unit_name'] ?: 'حبة'); ?></td>
                        <td class="text-center font-weight-bold"><?php echo number_format($d['quantity']); ?></td>
                        <td class="text-center"><?php echo number_format($d['unit_price'], 2); ?></td>
                        <td class="text-center font-weight-bold text-dark"><?php echo number_format($d['total_price'], 2); ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="6" class="text-center p-3 text-muted">إجمالي الفاتورة الموحد: <strong><?php echo number_format($mst['net_amount'], 2); ?> ر.ي</strong></td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <div class="row justify-content-end mb-3">
            <div class="col-md-6">
                <table class="table table-sm table-bordered bg-light mb-0">
                    <tr>
                        <th class="bg-white">صافي الفاتورة:</th>
                        <td class="font-weight-bold text-primary text-center" style="font-size:1.1rem;"><?php echo number_format($mst['net_amount'], 2); ?> ر.ي</td>
                    </tr>
                    <tr>
                        <th class="bg-white">المبلغ المدفوع:</th>
                        <td class="font-weight-bold text-success text-center"><?php echo number_format($mst['paid_amount'], 2); ?> ر.ي</td>
                    </tr>
                    <tr>
                        <th class="bg-white">المبلغ المتبقي:</th>
                        <td class="font-weight-bold text-danger text-center"><?php echo number_format($mst['remaining_amount'], 2); ?> ر.ي</td>
                    </tr>
                </table>
            </div>
        </div>
    </div>
    <?php
    exit;
}

// ────────────────────────────────────────────────────────────────────────
// 2. معاينة فاتورة المشتريات (Purchase Invoice)
// ────────────────────────────────────────────────────────────────────────
if ($type === 'purchase') {
    $mst = null;
    $dtl = [];

    $chk = $conn->query("SHOW TABLES LIKE 'purchase_invoices_mst'");
    if ($chk && $chk->num_rows > 0) {
        $res = $conn->query("SELECT * FROM purchase_invoices_mst WHERE id = $clean_id OR invoice_no = '$esc_raw' LIMIT 1");
        if ($res && $res->num_rows > 0) {
            $mst = $res->fetch_assoc();
            $inv_id = intval($mst['id']);
            $res_dtl = $conn->query("SELECT * FROM purchase_invoices_dtl WHERE invoice_id = $inv_id AND d_s = 0");
            if ($res_dtl) {
                while ($r = $res_dtl->fetch_assoc()) $dtl[] = $r;
            }
        }
    }

    if (!$mst && $clean_id > 0) {
        $chk_old = $conn->query("SHOW TABLES LIKE 'purchases'");
        if ($chk_old && $chk_old->num_rows > 0) {
            $res_old = $conn->query("SELECT * FROM purchases WHERE id = $clean_id LIMIT 1");
            if ($res_old && $res_old->num_rows > 0) {
                $row_old = $res_old->fetch_assoc();
                $mst = [
                    'id' => $row_old['id'],
                    'invoice_no' => $row_old['id'],
                    'invoice_date' => $row_old['date'] ?? date('Y-m-d'),
                    'supp_name' => $row_old['supp_name'] ?: 'مورد عام',
                    'net_amount' => $row_old['total'] ?? 0,
                    'paid_amount' => $row_old['total'] ?? 0,
                    'remaining_amount' => $row_old['remaining_total'] ?? 0,
                    'invoice_type' => $row_old['invoice_type'] ?? 'cash',
                    'remark' => $row_old['remark'] ?? '',
                    'username' => 'المدير'
                ];
            }
        }
    }

    if (!$mst) {
        echo '<div class="alert alert-danger text-center p-3 font-weight-bold">لم يتم العثور على فاتورة المشتريات رقم #' . htmlspecialchars($raw_id) . '</div>';
        exit;
    }

    $inv_type_label = ($mst['invoice_type'] === 'credit') ? '<span class="badge bg-warning text-dark px-2 py-1">آجل</span>' : '<span class="badge bg-success px-2 py-1">نقداً</span>';
    ?>
    <div class="sap-doc-wrap dir-rtl text-right p-2">
        <?php renderOfficialHeader($store_name_ar, $store_name_en, $phone, $address_ar, $address_en, $cr_number, $tax_number, $logo_src); ?>

        <div class="official-report-banner">
            <h3>فاتورة مشتريات (Purchase Invoice)</h3>
            <div class="banner-sub">رقم الفاتورة: <strong>#<?php echo htmlspecialchars($mst['invoice_no'] ?: $mst['id']); ?></strong> | التاريخ: <strong><?php echo htmlspecialchars($mst['invoice_date']); ?></strong></div>
        </div>

        <div class="row mb-3 p-2 bg-light border">
            <div class="col-md-6"><strong>اسم المورد:</strong> <?php echo htmlspecialchars($mst['supp_name'] ?: 'مورد عام'); ?></div>
            <div class="col-md-6 text-left"><strong>طريقة الدفع:</strong> <?php echo $inv_type_label; ?> | <strong>المستخدم:</strong> <?php echo htmlspecialchars($mst['username'] ?? 'المدير'); ?></div>
            <div class="col-md-12 mt-1"><strong>البيان / ملاحظات:</strong> <?php echo htmlspecialchars($mst['remark'] ?: 'توريد بضاعة'); ?></div>
        </div>

        <table class="sap-grid-table table-bordered mb-3">
            <thead>
                <tr>
                    <th style="width:5%;">#</th>
                    <th style="width:40%;">اسم الصنف / المنتج</th>
                    <th style="width:15%;">الوحدة</th>
                    <th style="width:12%;">الكمية</th>
                    <th style="width:14%;">سعر الشراء (ر.ي)</th>
                    <th style="width:14%;">الإجمالي (ر.ي)</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($dtl)): ?>
                    <?php $idx = 1; foreach ($dtl as $d): ?>
                    <tr>
                        <td class="text-center font-weight-bold"><?php echo $idx++; ?></td>
                        <td class="font-weight-bold"><?php echo htmlspecialchars($d['product_name']); ?></td>
                        <td class="text-center"><?php echo htmlspecialchars($d['unit_name'] ?: 'حبة'); ?></td>
                        <td class="text-center font-weight-bold"><?php echo number_format($d['quantity']); ?></td>
                        <td class="text-center"><?php echo number_format($d['unit_cost'] ?? $d['unit_price'] ?? 0, 2); ?></td>
                        <td class="text-center font-weight-bold text-dark"><?php echo number_format($d['total_cost'] ?? $d['total_price'] ?? 0, 2); ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="6" class="text-center p-3 text-muted">إجمالي الفاتورة الموحد: <strong><?php echo number_format($mst['net_amount'], 2); ?> ر.ي</strong></td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <div class="row justify-content-end mb-3">
            <div class="col-md-6">
                <table class="table table-sm table-bordered bg-light mb-0">
                    <tr>
                        <th class="bg-white">صافي مشتريات الفاتورة:</th>
                        <td class="font-weight-bold text-primary text-center" style="font-size:1.1rem;"><?php echo number_format($mst['net_amount'], 2); ?> ر.ي</td>
                    </tr>
                    <tr>
                        <th class="bg-white">المبلغ المدفوع:</th>
                        <td class="font-weight-bold text-success text-center"><?php echo number_format($mst['paid_amount'], 2); ?> ر.ي</td>
                    </tr>
                    <tr>
                        <th class="bg-white">المبلغ المتبقي لدين المورد:</th>
                        <td class="font-weight-bold text-danger text-center"><?php echo number_format($mst['remaining_amount'], 2); ?> ر.ي</td>
                    </tr>
                </table>
            </div>
        </div>
    </div>
    <?php
    exit;
}

// ────────────────────────────────────────────────────────────────────────
// 2b. معاينة مردود مشتريات (Purchase Return)
// ────────────────────────────────────────────────────────────────────────
if ($type === 'purchase_return') {
    $mst = null;
    $dtl = [];

    $chk = $conn->query("SHOW TABLES LIKE 'purchase_returns_mst'");
    if ($chk && $chk->num_rows > 0) {
        $res = $conn->query("SELECT * FROM purchase_returns_mst WHERE id = $clean_id OR return_no = '$esc_raw' LIMIT 1");
        if ($res && $res->num_rows > 0) {
            $mst = $res->fetch_assoc();
            $ret_id = intval($mst['id']);
            $res_dtl = $conn->query("SELECT * FROM purchase_returns_dtl WHERE return_id = $ret_id AND d_s = 0");
            if ($res_dtl) {
                while ($r = $res_dtl->fetch_assoc()) $dtl[] = $r;
            }
        }
    }

    if (!$mst) {
        echo '<div class="alert alert-danger text-center p-3 font-weight-bold">لم يتم العثور على مردود المشتريات رقم #' . htmlspecialchars($raw_id) . '</div>';
        exit;
    }

    $method_label = ($mst['refund_method'] === 'cash')
        ? '<span class="badge bg-success px-2 py-1">نقداً</span>'
        : '<span class="badge bg-warning text-dark px-2 py-1">آجل (خصم من الذمة)</span>';
    ?>
    <div class="sap-doc-wrap dir-rtl text-right p-2">
        <?php renderOfficialHeader($store_name_ar, $store_name_en, $phone, $address_ar, $address_en, $cr_number, $tax_number, $logo_src); ?>

        <div class="official-report-banner">
            <h3>مردود مشتريات (Purchase Return)</h3>
            <div class="banner-sub">رقم المردود: <strong>#<?php echo htmlspecialchars($mst['return_no'] ?: $mst['id']); ?></strong> | التاريخ: <strong><?php echo htmlspecialchars($mst['return_date']); ?></strong></div>
        </div>

        <div class="row mb-3 p-2 bg-light border">
            <div class="col-md-6"><strong>اسم المورد:</strong> <?php echo htmlspecialchars($mst['supp_name'] ?: 'مورد عام'); ?></div>
            <div class="col-md-6 text-left"><strong>طريقة الرد:</strong> <?php echo $method_label; ?></div>
            <div class="col-md-12 mt-1"><strong>سبب المردود:</strong> <?php echo htmlspecialchars($mst['reason'] ?: 'مردود بضاعة'); ?></div>
        </div>

        <table class="sap-grid-table table-bordered mb-3">
            <thead>
                <tr>
                    <th style="width:5%;">#</th>
                    <th style="width:40%;">اسم الصنف / المنتج</th>
                    <th style="width:12%;">الوحدة</th>
                    <th style="width:12%;">الكمية</th>
                    <th style="width:15%;">سعر الشراء (ر.ي)</th>
                    <th style="width:16%;">إجمالي المردود (ر.ي)</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($dtl)): ?>
                    <?php $idx = 1; foreach ($dtl as $d): ?>
                    <tr>
                        <td class="text-center font-weight-bold"><?php echo $idx++; ?></td>
                        <td class="font-weight-bold"><?php echo htmlspecialchars($d['product_name']); ?></td>
                        <td class="text-center"><?php echo htmlspecialchars($d['unit_name'] ?? 'حبة'); ?></td>
                        <td class="text-center font-weight-bold"><?php echo number_format($d['quantity']); ?></td>
                        <td class="text-center"><?php echo number_format($d['unit_cost'], 2); ?></td>
                        <td class="text-center font-weight-bold text-danger"><?php echo number_format($d['total_cost'], 2); ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="6" class="text-center p-3 text-muted">إجمالي المردود: <strong><?php echo number_format($mst['total_amount'], 2); ?> ر.ي</strong></td></tr>
                <?php endif; ?>
            </tbody>
        </table>

        <div class="row justify-content-end mb-3">
            <div class="col-md-5">
                <table class="table table-sm table-bordered bg-light mb-0">
                    <tr>
                        <th class="bg-white">إجمالي المردود:</th>
                        <td class="font-weight-bold text-danger text-center" style="font-size:1.1rem;"><?php echo number_format($mst['total_amount'], 2); ?> ر.ي</td>
                    </tr>
                </table>
            </div>
        </div>
    </div>
    <?php
    exit;
}

// ────────────────────────────────────────────────────────────────────────
// 3. معاينة سند القبض وسند الصرف (Receipt / Payment Voucher)
// ────────────────────────────────────────────────────────────────────────
if ($type === 'receipt' || $type === 'payment' || $type === 'voucher') {
    $v_data = null;
    $v_kind = ($type === 'payment') ? 'payment' : 'receipt';

    // البحث في جدول receipt_vouchers_mst أو payment_vouchers_mst
    if ($v_kind === 'receipt') {
        $chk_rv = $conn->query("SHOW TABLES LIKE 'receipt_vouchers_mst'");
        if ($chk_rv && $chk_rv->num_rows > 0) {
            $res_rv = $conn->query("SELECT * FROM receipt_vouchers_mst WHERE id = $clean_id OR voucher_no = '$esc_raw' LIMIT 1");
            if ($res_rv && $res_rv->num_rows > 0) {
                $row = $res_rv->fetch_assoc();
                $v_data = [
                    'voucher_no' => $row['voucher_no'] ?: $row['id'],
                    'voucher_type' => 'receipt',
                    'voucher_date' => $row['voucher_date'],
                    'party_name' => $row['party_name'] ?: 'عميل',
                    'amount' => floatval($row['total_amount']),
                    'notes' => $row['remark'] ?: 'سند قبض مالي'
                ];
            }
        }

        if (!$v_data && $clean_id > 0) {
            $chk_r = $conn->query("SHOW TABLES LIKE 'receipts'");
            if ($chk_r && $chk_r->num_rows > 0) {
                $res_r = $conn->query("SELECT * FROM receipts WHERE qid = $clean_id LIMIT 1");
                if ($res_r && $res_r->num_rows > 0) {
                    $row = $res_r->fetch_assoc();
                    $v_data = [
                        'voucher_no' => 'REC-' . str_pad($row['qid'], 5, '0', STR_PAD_LEFT),
                        'voucher_type' => 'receipt',
                        'voucher_date' => $row['q_date'] ?? date('Y-m-d'),
                        'party_name' => $row['cust_name'] ?: 'عميل',
                        'amount' => floatval($row['q_price']),
                        'notes' => $row['remark'] ?: 'تحصيل دفعة مالية'
                    ];
                }
            }
        }
    } else {
        $chk_pv = $conn->query("SHOW TABLES LIKE 'payment_vouchers_mst'");
        if ($chk_pv && $chk_pv->num_rows > 0) {
            $res_pv = $conn->query("SELECT * FROM payment_vouchers_mst WHERE id = $clean_id OR voucher_no = '$esc_raw' LIMIT 1");
            if ($res_pv && $res_pv->num_rows > 0) {
                $row = $res_pv->fetch_assoc();
                $v_data = [
                    'voucher_no' => $row['voucher_no'] ?: $row['id'],
                    'voucher_type' => 'payment',
                    'voucher_date' => $row['voucher_date'],
                    'party_name' => $row['party_name'] ?: 'مورد',
                    'amount' => floatval($row['total_amount']),
                    'notes' => $row['remark'] ?: 'سند صرف مالي'
                ];
            }
        }

        if (!$v_data && $clean_id > 0) {
            $chk_b = $conn->query("SHOW TABLES LIKE 'bush'");
            if ($chk_b && $chk_b->num_rows > 0) {
                $res_b = $conn->query("SELECT * FROM bush WHERE bush_id = $clean_id LIMIT 1");
                if ($res_b && $res_b->num_rows > 0) {
                    $row = $res_b->fetch_assoc();
                    $v_data = [
                        'voucher_no' => 'PAY-' . str_pad($row['bush_id'], 5, '0', STR_PAD_LEFT),
                        'voucher_type' => 'payment',
                        'voucher_date' => $row['bush_date'] ?? date('Y-m-d'),
                        'party_name' => $row['supp_name'] ?: 'مورد',
                        'amount' => floatval($row['bush_price']),
                        'notes' => $row['remark'] ?: 'سداد مستحقات مالية'
                    ];
                }
            }
        }
    }

    // البحث في جدول القيود/السندات المحاسبية الموحد كـ fallback
    if (!$v_data) {
        $chk_av = $conn->query("SHOW TABLES LIKE 'accounting_vouchers'");
        if ($chk_av && $chk_av->num_rows > 0) {
            $res_av = $conn->query("SELECT * FROM accounting_vouchers WHERE id = $clean_id OR voucher_no = '$esc_raw' LIMIT 1");
            if ($res_av && $res_av->num_rows > 0) {
                $row = $res_av->fetch_assoc();
                $v_data = [
                    'voucher_no' => $row['voucher_no'],
                    'voucher_type' => $row['voucher_type'],
                    'voucher_date' => $row['voucher_date'],
                    'party_name' => $row['party_name'],
                    'amount' => floatval($row['amount']),
                    'notes' => $row['description']
                ];
            }
        }
    }

    if (!$v_data) {
        echo '<div class="alert alert-danger text-center p-3 font-weight-bold">لم يتم العثور على السند رقم #' . htmlspecialchars($raw_id) . '</div>';
        exit;
    }

    $is_receipt = ($v_data['voucher_type'] === 'receipt');
    $v_type_title = $is_receipt ? 'سند قبض  (Receipt Voucher)' : 'سند صرف  (Payment Voucher)';
    $v_color = $is_receipt ? 'success' : 'danger';
    ?>
    <div class="sap-doc-wrap dir-rtl text-right p-2">
        <?php renderOfficialHeader($store_name_ar, $store_name_en, $phone, $address_ar, $address_en, $cr_number, $tax_number, $logo_src); ?>

        <div class="official-report-banner">
            <h3 class="text-<?php echo $v_color; ?>"><?php echo $v_type_title; ?></h3>
            <div class="banner-sub">رقم السند: <strong>#<?php echo htmlspecialchars($v_data['voucher_no']); ?></strong> | التاريخ: <strong><?php echo htmlspecialchars($v_data['voucher_date']); ?></strong></div>
        </div>

        <div class="row mb-3 p-3 bg-light border" style="font-size:0.95rem;">
            <div class="col-md-7 mb-2">
                <strong><?php echo $is_receipt ? 'استلمنا من السيد / الجهة:' : 'صرفنا إلى السيد / الجهة:'; ?></strong>
                <span class="font-weight-bold text-dark mr-1"><?php echo htmlspecialchars($v_data['party_name']); ?></span>
            </div>
            <div class="col-md-5 mb-2 text-left">
                <strong>مبلغ وقدره:</strong>
                <span class="badge bg-<?php echo $v_color; ?> text-white px-3 py-2 font-weight-bold" style="font-size:1.1rem;">
                    <?php echo number_format($v_data['amount'], 2); ?> ريال يمني
                </span>
            </div>
            <div class="col-md-12 mt-2 pt-2 border-top">
                <strong>البيان / سبب المعاملة:</strong>
                <span class="text-muted mr-1"><?php echo htmlspecialchars($v_data['notes']); ?></span>
            </div>
        </div>
    </div>
    <?php
    exit;
}
?>
