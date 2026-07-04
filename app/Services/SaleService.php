<?php
namespace AQNEX\Services;

class SaleService
{
    public static function lookupProductByBarcode(\mysqli $conn, string $barcode): array
    {
        $barcode = trim($barcode);
        if ($barcode === '') {
            return ['found' => false, 'message' => 'الباركود المرسل فارغ.'];
        }

        // 1. البحث في الأرقام التسلسلية / IMEI
        if (is_module_enabled('serial_imei_tracking')) {
            $stmt = $conn->prepare(
                "SELECT ps.id AS serial_id, ps.serial_number, ps.imei_1, ps.imei_2, " .
                "p.id, p.name, p.sale_price, p.buy_price, p.quantity, p.barcode, " .
                "0 AS is_unit, NULL AS unit_id, 'الوحدة الأساسية' AS unit_name, 1.0000 AS conversion_factor, " .
                "p.track_expiry, c.requires_serial " .
                "FROM product_serials ps " .
                "INNER JOIN products p ON ps.product_id = p.id " .
                "LEFT JOIN categories c ON p.catid = c.catid " .
                "WHERE (ps.serial_number = ? OR ps.imei_1 = ? OR ps.imei_2 = ?) " .
                "AND ps.status = 'in_stock' AND ps.d_s = '0' AND p.delete_status = 0 " .
                "LIMIT 1"
            );

            if ($stmt) {
                $stmt->bind_param('sss', $barcode, $barcode, $barcode);
                $stmt->execute();
                $result = $stmt->get_result();
                $row = $result ? $result->fetch_assoc() : null;
                $stmt->close();

                if ($row) {
                    return [
                        'found' => true,
                        'product' => $row,
                        'scanned_serial' => [
                            'id' => intval($row['serial_id']),
                            'serial_number' => $row['serial_number'],
                            'imei_1' => $row['imei_1'],
                            'imei_2' => $row['imei_2'],
                        ],
                    ];
                }
            }
        }

        // 2. البحث في الباركود الأساسي
        $stmt = $conn->prepare(
            "SELECT p.id, p.name, p.sale_price, p.buy_price, p.quantity, p.barcode, " .
            "0 AS is_unit, NULL AS unit_id, 'الوحدة الأساسية' AS unit_name, 1.0000 AS conversion_factor, " .
            "p.track_expiry, c.requires_serial " .
            "FROM products p " .
            "LEFT JOIN categories c ON p.catid = c.catid " .
            "WHERE p.barcode = ? AND p.delete_status = 0 LIMIT 1"
        );

        if ($stmt) {
            $stmt->bind_param('s', $barcode);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result ? $result->fetch_assoc() : null;
            $stmt->close();

            if ($row) {
                return ['found' => true, 'product' => $row];
            }
        }

        // 3. البحث في الباركودات المرتبطة بالوحدات
        $stmt = $conn->prepare(
            "SELECT p.id, p.name, IFNULL(pu.sale_price, p.sale_price) AS sale_price, " .
            "IFNULL(pu.purchase_price, p.buy_price) AS buy_price, p.quantity, pb.barcode, " .
            "1 AS is_unit, pb.unit_id, pu.unit_name, IFNULL(pu.conversion_factor, 1.0000) AS conversion_factor, " .
            "p.track_expiry, c.requires_serial " .
            "FROM product_barcodes pb " .
            "INNER JOIN products p ON pb.product_id = p.id " .
            "LEFT JOIN product_units pu ON pb.unit_id = pu.id " .
            "LEFT JOIN categories c ON p.catid = c.catid " .
            "WHERE pb.barcode = ? AND pb.d_s = '0' AND p.delete_status = 0 LIMIT 1"
        );

        if ($stmt) {
            $stmt->bind_param('s', $barcode);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result ? $result->fetch_assoc() : null;
            $stmt->close();

            if ($row) {
                return ['found' => true, 'product' => $row];
            }
        }

        return ['found' => false, 'message' => 'الرمز غير مسجل بقاعدة البيانات.'];
    }
}
