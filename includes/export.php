<?php
$dir_prefix = '../';
require_once(__DIR__ . '/connect.php');

// التأكد من بدء الجلسة
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// استدعاء التحقق من الصلاحيات
require_once(__DIR__ . '/auth.php');

$type = isset($_GET['type']) ? $_GET['type'] : '';

// التحقق من الصلاحيات بناء على نوع التصدير
if ($type === 'products') {
    check_permission(['admin', 'inventory']);
} elseif ($type === 'sales') {
    check_permission(['admin', 'cashier']);
} elseif ($type === 'purchases') {
    check_permission(['admin', 'inventory']);
} elseif ($type === 'customers') {
    check_permission(['admin', 'cashier']);
} elseif ($type === 'suppliers') {
    check_permission(['admin', 'inventory']);
} elseif ($type === 'purchases_template') {
    check_permission(['admin', 'inventory']);
} else {
    die("نوع تصدير غير صالح أو غير مصرح به.");
}

// إرسال الترويسات الصحيحة لتحميل ملف CSV متوافق مع إكسل ومرمز بـ UTF-8
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="export_' . $type . '_' . date('Y-m-d_H-i') . '.csv"');

// طباعة UTF-8 BOM لفتح الملف باللغة العربية بشكل صحيح في إكسل
echo "\xEF\xBB\xBF";

$output = fopen('php://output', 'w');

if ($type === 'products') {
    // تصدير المنتجات
    fputcsv($output, ['رقم المنتج', 'اسم المنتج', 'رمز الباركود', 'الكمية', 'سعر الشراء', 'سعر البيع', 'القيمة الإجمالية', 'تاريخ الإضافة']);
    
    $sql = "SELECT id, name, barcode, quantity, buy_price, sale_price, total, date FROM products WHERE delete_status = 0 ORDER BY id DESC";
    $result = $conn->query($sql);
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            fputcsv($output, [
                $row['id'],
                $row['name'],
                $row['barcode'],
                $row['quantity'],
                number_format($row['buy_price'], 2, '.', ''),
                number_format($row['sale_price'], 2, '.', ''),
                number_format($row['total'], 2, '.', ''),
                $row['date']
            ]);
        }
    }
} elseif ($type === 'sales') {
    // تصدير المبيعات
    fputcsv($output, ['رقم الفاتورة', 'اسم العميل', 'المبلغ المدفوع (الصافي)', 'الأرباح المحققة', 'ملاحظات الفاتورة', 'تاريخ البيع']);
    
    $sql = "SELECT id, cust_name, total, prifet, remark, build_date FROM sales WHERE delete_status = 0 ORDER BY id DESC";
    $result = $conn->query($sql);
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            fputcsv($output, [
                $row['id'],
                $row['cust_name'] ? $row['cust_name'] : 'عميل نقدي',
                number_format($row['total'], 2, '.', ''),
                number_format($row['prifet'], 2, '.', ''),
                $row['remark'],
                $row['build_date']
            ]);
        }
    }
} elseif ($type === 'purchases') {
    // تصدير فواتير المشتريات
    fputcsv($output, ['رقم المعاملة', 'اسم المورد', 'اسم المنتج', 'الكمية', 'سعر الشراء الكلي', 'تاريخ الشراء']);
    
    $sql = "SELECT buyid, supp_name, name, quantity, buy_price, buys_date FROM purchase_items WHERE s = 0 ORDER BY buyid DESC";
    $result = $conn->query($sql);
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            fputcsv($output, [
                $row['buyid'],
                $row['supp_name'] ? $row['supp_name'] : 'مورد عام',
                $row['name'],
                $row['quantity'],
                number_format($row['buy_price'], 2, '.', ''),
                $row['buys_date']
            ]);
        }
    }
} elseif ($type === 'customers') {
    // تصدير العملاء
    fputcsv($output, ['رقم العميل', 'اسم العميل', 'رقم الهاتف', 'إجمالي المديونية (مدين)', 'تاريخ التسجيل']);
    
    $sql = "SELECT cust_id, cust_name, phone, cust_madeen, sale_date FROM customers WHERE d_s = 0 ORDER BY cust_id DESC";
    $result = $conn->query($sql);
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            fputcsv($output, [
                $row['cust_id'],
                $row['cust_name'],
                $row['phone'],
                number_format($row['cust_madeen'], 2, '.', ''),
                $row['sale_date']
            ]);
        }
    }
} elseif ($type === 'suppliers') {
    // تصدير الموردين
    fputcsv($output, ['رقم المورد', 'اسم المورد', 'رقم الهاتف', 'الرصيد الدائن', 'تاريخ التسجيل']);
    
    $sql = "SELECT supp_id, supp_name, phone, supp_daain, sale_date FROM suppliers WHERE d_s = 0 ORDER BY supp_id DESC";
    $result = $conn->query($sql);
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            fputcsv($output, [
                $row['supp_id'],
                $row['supp_name'],
                $row['phone'],
                number_format($row['supp_daain'], 2, '.', ''),
                $row['sale_date']
            ]);
        }
    }
} elseif ($type === 'purchases_template') {
    // تنزيل نموذج استيراد المشتريات الفارغ
    header('Content-Disposition: attachment; filename="purchases_import_template.csv"');
    fputcsv($output, ['اسم_المنتج', 'الكمية', 'سعر_الشراء_الفردي', 'المبلغ_المدفوع']);
    // صفوف توضيحية (يتم حذفها قبل الرفع)
    fputcsv($output, ['مثال: سماعة ابل اير بودز', '5', '12500.00', '30000.00']);
    fputcsv($output, ['مثال: بطارية LG', '10', '3500.00', '25000.00']);
}

fclose($output);
exit;
