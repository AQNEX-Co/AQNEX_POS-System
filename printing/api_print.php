<?php
/**
 * واجهة استقبال أوامر الطباعة المباشرة (Silent Print API)
 * تستقبل طلبات الطباعة عبر AJAX وتوجهها إلى PrinterManager للطباعة الفورية.
 */
$dir_prefix = '../';
require_once(__DIR__ . '/../includes/connect.php');
require_once(__DIR__ . '/../includes/auth.php');
require_once(__DIR__ . '/../includes/modules.php');
require_once(__DIR__ . '/EscPosBuilder.php');
require_once(__DIR__ . '/ZplBuilder.php');
require_once(__DIR__ . '/PrinterManager.php');

use AQNEX\Printing\EscPosBuilder;
use AQNEX\Printing\ZplBuilder;
use AQNEX\Printing\PrinterManager;

// التحقق من الصلاحيات
check_permission(['admin', 'cashier']);

header('Content-Type: application/json; charset=utf-8');

$action = isset($_GET['action']) ? $_GET['action'] : 'print_invoice';

// 1. طباعة فاتورة مبيعات
if ($action === 'print_invoice') {
    $sale_id = isset($_GET['sale_id']) ? intval($_GET['sale_id']) : 0;
    if ($sale_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'رقم الفاتورة غير صحيح.']);
        exit;
    }

    // التحقق من تفعيل موديول الطباعة الحرارية
    if (!is_module_enabled('thermal_printing')) {
        echo json_encode(['success' => false, 'message' => 'موديول الطباعة الحرارية معطل حالياً.']);
        exit;
    }

    // جلب الطابعة الافتراضية
    $res_printer = $conn->query("SELECT * FROM `printer_settings` WHERE `is_default` = 1 AND `d_s` = '0' LIMIT 1");
    $printer = $res_printer ? $res_printer->fetch_assoc() : null;
    
    // إذا لم تكن هناك طابعة افتراضية، نبحث عن أول طابعة حرارية
    if (!$printer) {
        $res_printer = $conn->query("SELECT * FROM `printer_settings` WHERE `printer_type` IN ('thermal_80', 'thermal_58') AND `d_s` = '0' LIMIT 1");
        $printer = $res_printer ? $res_printer->fetch_assoc() : null;
    }

    if (!$printer) {
        echo json_encode(['success' => false, 'message' => 'لم يتم إعداد أي طابعة كطابعة افتراضية في النظام. يرجى إعداد الطابعة أولاً.']);
        exit;
    }

    // جلب بيانات الفاتورة والإعدادات العامة
    $res_sale = $conn->query("SELECT * FROM `sales` WHERE `id` = $sale_id LIMIT 1");
    $sale = $res_sale ? $res_sale->fetch_assoc() : null;
    if (!$sale) {
        echo json_encode(['success' => false, 'message' => 'لم يتم العثور على الفاتورة في قاعدة البيانات.']);
        exit;
    }

    $res_settings = $conn->query("SELECT * FROM `settings` WHERE `id` = 1 LIMIT 1");
    $settings = $res_settings ? $res_settings->fetch_assoc() : null;

    $store_name = $settings['store_name'] ?? 'تكنولوجيا فون';
    $store_phone = $settings['phone'] ?? '';
    $store_address = $settings['address'] ?? '';
    $receipt_footer = $settings['receipt_footer'] ?? 'شكرًا لزيارتكم!';
    $tax_percent = doubleval($settings['tax_percent'] ?? 0);

    // جلب عناصر الفاتورة
    $items = [];
    $res_items = $conn->query("SELECT * FROM `sales_items` WHERE `sales_id` = $sale_id");
    if ($res_items) {
        while ($row = $res_items->fetch_assoc()) {
            // إزالة بادئة معرف المنتج من الاسم إن وجدت
            $p_name = $row['name'];
            $parts = explode(' ', $p_name, 2);
            if (count($parts) > 1 && is_numeric($parts[0])) {
                $p_name = $parts[1];
            }
            $items[] = [
                'name' => $p_name,
                'quantity' => intval($row['quantity']),
                'unit_price' => doubleval($row['unit_price']),
                'total' => doubleval($row['all_tot']),
                'discount' => doubleval($row['d']),
                'remaining' => doubleval($row['dis'])
            ];
        }
    }

    // تحديد عرض الفاتورة (80 مم أو 58 مم)
    $is_58 = ($printer['printer_type'] === 'thermal_58');
    $chars_width = $is_58 ? 32 : 48; // عدد الحروف التقريبي في السطر

    // بناء أوامر الطباعة الحرارية ESC/POS
    $builder = new EscPosBuilder();
    $builder->initialize()
            ->alignCenter()
            ->setFontSize(2, 2)
            ->setBold(true)
            ->line($store_name)
            ->setFontSize(1, 1)
            ->setBold(false);

    if (!empty($store_phone)) {
        $builder->line("هاتف: " . $store_phone);
    }
    if (!empty($store_address)) {
        $builder->line("العنوان: " . $store_address);
    }
    
    $builder->line(str_repeat("-", $chars_width))
            ->alignRight()
            ->line("فاتورة مبيعات رقم: #" . $sale_id)
            ->line("التاريخ: " . $sale['build_date'])
            ->line("العميل: " . (!empty($sale['cust_name']) ? $sale['cust_name'] : 'عميل نقدي'))
            ->line("العملة: " . $sale['currency_code'])
            ->line(str_repeat("-", $chars_width));

    // طباعة عناصر الفاتورة
    // الجدول: الاسم | الكمية | السعر | الإجمالي
    if ($is_58) {
        // تنسيق ضيق لـ 58 مم
        $builder->line("الصنف          الكمية  السعر  الإجمالي");
        $builder->line(str_repeat("-", $chars_width));
        foreach ($items as $item) {
            $name_short = mb_substr($item['name'], 0, 12, 'utf-8');
            $line_str = sprintf(
                "%-14s %-6d %-6.0f %-6.0f",
                $name_short,
                $item['quantity'],
                $item['unit_price'],
                $item['total']
            );
            $builder->line($line_str);
        }
    } else {
        // تنسيق مريح لـ 80 مم
        $builder->line("الصنف                    الكمية   السعر    الإجمالي");
        $builder->line(str_repeat("-", $chars_width));
        foreach ($items as $item) {
            // معالجة النصوص العربية بشكل صحيح لملء الفراغات
            $name_padded = mb_str_pad($item['name'], 24, " ");
            $line_str = sprintf(
                "%s %5d %8.2f %10.2f",
                $name_padded,
                $item['quantity'],
                $item['unit_price'],
                $item['total']
            );
            $builder->line($line_str);
        }
    }

    $builder->line(str_repeat("-", $chars_width));

    // حساب الحسابات الكلية
    $subtotal = doubleval($sale['total']);
    $tax = ($tax_percent > 0) ? ($subtotal * $tax_percent) / 100 : 0;
    
    // حساب إجمالي الخصم
    $total_discount = 0;
    $total_remaining = 0;
    foreach ($items as $item) {
        $total_discount += $item['discount'];
        $total_remaining += $item['remaining'];
    }
    
    $net_total = $subtotal + $tax - $total_discount;

    $builder->alignRight()
            ->line("الإجمالي الفرعي: " . number_format($subtotal, 2) . " " . $sale['currency_code']);
    
    if ($tax > 0) {
        $builder->line("الضريبة (" . $tax_percent . "%): " . number_format($tax, 2) . " " . $sale['currency_code']);
    }
    if ($total_discount > 0) {
        $builder->line("خصم الفاتورة: " . number_format($total_discount, 2) . " " . $sale['currency_code']);
    }
    
    $builder->setBold(true)
            ->line("صافي الفاتورة: " . number_format($net_total, 2) . " " . $sale['currency_code'])
            ->setBold(false)
            ->line("المقبوض (المدفوع): " . number_format($net_total - $total_remaining, 2) . " " . $sale['currency_code'])
            ->line("المتبقي (المديونية): " . number_format($total_remaining, 2) . " " . $sale['currency_code'])
            ->line(str_repeat("-", $chars_width));

    // توليد رمز استجابة سريعة QR Code للفوترة الإلكترونية المبسطة
    // التنسيق: اسم المتجر | التاريخ والوقت | صافي المبلغ | قيمة الضريبة
    $qr_text = sprintf(
        "Store: %s\nDate: %s\nTotal: %.2f %s\nTax: %.2f",
        $store_name,
        $sale['build_date'],
        $net_total,
        $sale['currency_code'],
        $tax
    );
    
    $builder->alignCenter()
            ->line()
            ->qrCode($qr_text)
            ->line()
            ->line($receipt_footer)
            ->feed(4)
            ->cut();

    // إرسال أمر الطباعة
    $result = PrinterManager::sendJob($printer, $builder->getBuffer());
    echo json_encode($result);
    exit;
}

// 2. طباعة ملصقات الباركود والأسعار
if ($action === 'print_label') {
    $product_id = isset($_GET['product_id']) ? intval($_GET['product_id']) : 0;
    $quantity = isset($_GET['qty']) ? intval($_GET['qty']) : 1;
    $unit_id = isset($_GET['unit_id']) && !empty($_GET['unit_id']) ? intval($_GET['unit_id']) : null;

    if ($product_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'رقم المنتج غير صحيح.']);
        exit;
    }

    if (!is_module_enabled('label_printing')) {
        echo json_encode(['success' => false, 'message' => 'موديول طباعة ملصقات الباركود معطل حالياً.']);
        exit;
    }

    // جلب طابعة ملصقات الباركود الافتراضية
    $res_printer = $conn->query("SELECT * FROM `printer_settings` WHERE `printer_type` = 'label_zpl' AND `d_s` = '0' LIMIT 1");
    $printer = $res_printer ? $res_printer->fetch_assoc() : null;

    if (!$printer) {
        echo json_encode(['success' => false, 'message' => 'لم يتم العثور على طابعة ملصقات (ZPL) نشطة بالنظام.']);
        exit;
    }

    // جلب بيانات المنتج
    $res_p = $conn->query("SELECT * FROM `products` WHERE `id` = $product_id AND `delete_status` = 0 LIMIT 1");
    $product = $res_p ? $res_p->fetch_assoc() : null;
    if (!$product) {
        echo json_encode(['success' => false, 'message' => 'المنتج غير موجود أو تم حذفه.']);
        exit;
    }

    $price = $product['sale_price'];
    $barcode = $product['barcode'];
    $name = $product['name'];

    // التحقق من وجود وحدة وتعديل السعر والباركود بناء عليها
    if ($unit_id !== null) {
        $res_u = $conn->query("SELECT * FROM `product_units` WHERE `id` = $unit_id AND `product_id` = $product_id AND `d_s` = '0' LIMIT 1");
        $unit = $res_u ? $res_u->fetch_assoc() : null;
        if ($unit) {
            $price = $unit['sale_price'];
            $name .= " (" . $unit['unit_name'] . ")";
            
            // جلب باركود هذه الوحدة
            $res_bc = $conn->query("SELECT barcode FROM `product_barcodes` WHERE `product_id` = $product_id AND `unit_id` = $unit_id AND `d_s` = '0' LIMIT 1");
            if ($res_bc && $res_bc->num_rows > 0) {
                $bc_row = $res_bc->fetch_assoc();
                $barcode = $bc_row['barcode'];
            }
        }
    }

    if (empty($barcode)) {
        echo json_encode(['success' => false, 'message' => 'عذراً، هذا المنتج ليس له كود باركود مسجل لطباعته.']);
        exit;
    }

    // بناء أوامر ZPL
    $zpl = new ZplBuilder();
    $zpl->setLabelWidth(400)   // عرض الملصق 50 مم تقريباً
        ->setLabelHeight(240);  // طول الملصق 30 مم تقريباً

    for ($i = 0; $i < $quantity; $i++) {
        // نص العنوان (الاسم)
        $zpl->text(20, 20, $name, 22);
        
        // طباعة الباركود (EAN13 أو Code128 حسب طوله)
        if (strlen($barcode) === 13 && is_numeric($barcode)) {
            $zpl->barcodeEan13(20, 60, $barcode, 80);
        } else {
            $zpl->barcodeCode128(20, 60, $barcode, 80);
        }
        
        // طباعة السعر
        $zpl->text(20, 170, "Price: " . number_format($price, 2) . " YER", 24, "0", "N");
        
        // نهاية الملصق
        if ($i < $quantity - 1) {
            $zpl->start(); // بدء ملصق جديد في حال طباعة كميات متعددة
        }
    }

    $payload = $zpl->getPayload();

    // إرسال لـ PrinterManager
    $result = PrinterManager::sendJob($printer, $payload);
    echo json_encode($result);
    exit;
}

// 3. طباعة صفحة فحص الطابعة (Test Page)
if ($action === 'test_printer') {
    $printer_id = isset($_GET['printer_id']) ? intval($_GET['printer_id']) : 0;
    if ($printer_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'معرف الطابعة غير صحيح.']);
        exit;
    }

    $res_p = $conn->query("SELECT * FROM `printer_settings` WHERE `id` = $printer_id LIMIT 1");
    $printer = $res_p ? $res_p->fetch_assoc() : null;
    if (!$printer) {
        echo json_encode(['success' => false, 'message' => 'الطابعة المطلوبة غير موجودة.']);
        exit;
    }

    if ($printer['printer_type'] === 'label_zpl') {
        $zpl = new ZplBuilder();
        $zpl->setLabelWidth(400)->setLabelHeight(240)
            ->text(40, 40, "AQNEX POS SYSTEM", 28)
            ->text(40, 90, "PRINTER CONNECTION TEST SUCCESS", 20)
            ->text(40, 140, "PRINTER: " . $printer['printer_name'], 20)
            ->barcodeCode128(40, 180, "TEST12345", 50, "N");
        $result = PrinterManager::sendJob($printer, $zpl->getPayload());
    } else {
        $builder = new EscPosBuilder();
        $builder->initialize()
                ->alignCenter()
                ->setFontSize(2, 2)
                ->setBold(true)
                ->line("AQNEX POS")
                ->setFontSize(1, 1)
                ->line("PRINTER TEST PAGE")
                ->line(str_repeat("-", 40))
                ->alignRight()
                ->line("Printer Name: " . $printer['printer_name'])
                ->line("Type: " . $printer['printer_type'])
                ->line("Connection: " . $printer['connection_type'])
                ->line("Address/Path: " . $printer['ip_address'])
                ->line("Time: " . date("Y-m-d H:i:s"))
                ->line(str_repeat("-", 40))
                ->alignCenter()
                ->qrCode("AQNEX POS - CONNECTION OK")
                ->feed(3)
                ->cut();
        $result = PrinterManager::sendJob($printer, $builder->getBuffer());
    }

    echo json_encode($result);
    exit;
}

// دالة مكملة لملء الفراغات باللغة العربية
function mb_str_pad($str, $pad_len, $pad_str = " ", $dir = STR_PAD_RIGHT) {
    $str_len = mb_strlen($str, 'utf-8');
    if ($str_len >= $pad_len) {
        return $str;
    }
    
    // الحروف العربية تأخذ حجماً مساوياً للحروف العادية في الطابعة
    $pad_amount = $pad_len - $str_len;
    $padding = str_repeat($pad_str, $pad_amount);
    
    return $dir === STR_PAD_RIGHT ? $str . $padding : $padding . $str;
}
