<?php
/**
 * مساعد الباركود (Barcode Helper)
 * يتولى حساب رقم التحقق EAN13 وتوليد باركودات فريدة للمنتجات.
 */

if (!function_exists('calculate_ean13_checksum')) {
    function calculate_ean13_checksum(string $digits): int {
        if (strlen($digits) < 12) {
            $digits = str_pad($digits, 12, '0', STR_PAD_LEFT);
        }
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $digit = intval($digits[$i]);
            // الوزن المعتمد في EAN-13: الأوزان تتناوب بين 1 و 3
            $weight = ($i % 2 === 0) ? 1 : 3;
            $sum += $digit * $weight;
        }
        $mod = $sum % 10;
        return ($mod === 0) ? 0 : (10 - $mod);
    }
}

if (!function_exists('generate_unique_barcode')) {
    function generate_unique_barcode(): string {
        global $conn;
        
        if (!isset($conn) || !$conn) {
            return '200' . str_pad(mt_rand(1, 9999999999), 10, '0', STR_PAD_LEFT);
        }

        do {
            // المعيار القياسي EAN13 الداخلي يبدأ بـ 200 للمحلات التجارية
            $digits = '200' . str_pad(mt_rand(0, 999999999), 9, '0', STR_PAD_LEFT);
            $checksum = calculate_ean13_checksum($digits);
            $barcode = $digits . $checksum;

            // التأكد من عدم تكراره في جدول المنتجات الأساسي
            $stmt = $conn->prepare("SELECT id FROM products WHERE barcode = ? LIMIT 1");
            $stmt->bind_param("s", $barcode);
            $stmt->execute();
            $stmt->store_result();
            $exists = $stmt->num_rows > 0;
            $stmt->close();

            // التأكد من عدم تكراره في جدول الباركودات المتعددة
            if (!$exists) {
                $stmt = $conn->prepare("SELECT id FROM product_barcodes WHERE barcode = ? AND d_s = '0' LIMIT 1");
                $stmt->bind_param("s", $barcode);
                $stmt->execute();
                $stmt->store_result();
                $exists = $stmt->num_rows > 0;
                $stmt->close();
            }
        } while ($exists);

        return $barcode;
    }
}
?>
