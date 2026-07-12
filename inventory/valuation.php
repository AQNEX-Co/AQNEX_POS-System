<?php
$dir_prefix = '../';
$module = 'inventory';
$report_title = 'تقرير تقييم المخزون المالي';
require_once($dir_prefix . 'includes/header.php');

check_permission(['admin']);

use AQNEX\Services\InventoryService;

$valuationData = InventoryService::getValuationReport($conn);

$totalStockQty = 0.0;
$totalStockValue = 0.0;
foreach ($valuationData as $row) {
    $totalStockQty += floatval($row['total_qty']);
    $totalStockValue += floatval($row['total_valuation']);
}
?>

<title>تقييم المخزون - AQNEX</title>

<style>
.val-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);
}
.val-table th {
    background: #0f172a;
    color: #fff;
    font-size: 0.8rem;
    font-weight: 700;
    text-align: center;
}
.val-table td {
    font-size: 0.78rem;
    vertical-align: middle;
    text-align: center;
}
.summary-bar {
    background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
    color: #fff;
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 20px;
    display: flex;
    justify-content: space-around;
    align-items: center;
    flex-wrap: wrap;
    gap: 15px;
}
.summary-box {
    text-align: center;
}
.summary-box .label {
    font-size: 0.72rem;
    color: #94a3b8;
    margin-bottom: 5px;
    text-transform: uppercase;
}
.summary-box .value {
    font-size: 1.5rem;
    font-weight: 800;
    font-family: monospace;
}
</style>

<div class="page-inner">
    
    <!-- Title Bar -->
    <div class="page-title-bar mb-4">
        <div class="ptb-left">
            <div class="icon-wrap"><i class="bi bi-calculator"></i></div>
            <div>
                <h4>تقرير تقييم المخزون المالي</h4>
                <small>حساب القيمة الإجمالية والتفصيلية للأصناف في المخازن بتكلفة FIFO</small>
            </div>
        </div>
        <div class="ptb-actions">
            <a href="stock_in.php" class="btn btn-sm btn-primary text-white text-decoration-none ml-2" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%); border: none;">
                <i class="bi bi-plus-lg ml-1"></i> توريد مخزني جديد
            </a>
            <button onclick="window.print()" class="btn btn-sm btn-light border ml-2">
                <i class="bi bi-printer ml-1"></i> طباعة
            </button>
            <a href="../home.php" class="btn btn-sm btn-light border">
                <i class="bi bi-arrow-left ml-1"></i> عودة
            </a>
        </div>
    </div>

    <!-- Summary Box -->
    <div class="summary-bar">
        <div class="summary-box">
            <div class="label">إجمالي كميات الأصناف بالمستودع</div>
            <div class="value text-success"><?= number_format($totalStockQty, 2) ?></div>
            <small style="color: #94a3b8;">وحدة</small>
        </div>
        <div class="summary-box" style="border-right: 1px dashed rgba(255,255,255,0.15); border-left: 1px dashed rgba(255,255,255,0.15); padding: 0 40px;">
            <div class="label">إجمالي القيمة المالية للمخزون (FIFO)</div>
            <div class="value text-warning"><?= number_format($totalStockValue, 2) ?></div>
            <small style="color: #94a3b8;">ريال يمني</small>
        </div>
        <div class="summary-box">
            <div class="label">عدد الأصناف الفريدة</div>
            <div class="value text-info"><?= count($valuationData) ?></div>
            <small style="color: #94a3b8;">صنف نشط</small>
        </div>
    </div>

    <!-- Table Card -->
    <div class="val-card">
        <div class="card-body p-3">
            <div class="table-responsive">
                <table class="table table-bordered table-striped val-table mb-0">
                    <thead>
                        <tr>
                            <th># م</th>
                            <th>الباركود</th>
                            <th style="text-align: right;">اسم المنتج / الصنف</th>
                            <th>الكمية المتاحة</th>
                            <th>آخر سعر شراء</th>
                            <th>إجمالي قيمة المخزون (FIFO)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($valuationData)): $idx = 1; foreach ($valuationData as $val): ?>
                            <tr>
                                <td><?= $idx++ ?></td>
                                <td style="font-family: monospace; font-weight: bold;"><?= htmlspecialchars($val['barcode'] ?: '—') ?></td>
                                <td style="text-align: right; font-weight: 700;"><?= htmlspecialchars($val['product_name']) ?></td>
                                <td style="font-family: monospace; font-weight: bold; color: #1e3a8a;"><?= number_format((float)$val['total_qty'], 2) ?></td>
                                <td style="font-family: monospace;"><?= number_format((float)$val['last_buy_price'], 2) ?></td>
                                <td style="font-family: monospace; font-weight: 800; color: #047857; background: #f0fdf4;">
                                    <?= number_format((float)$val['total_valuation'], 2) ?>
                                </td>
                            </tr>
                        <?php endforeach; else: ?>
                            <tr>
                                <td colspan="6" class="text-center py-4 text-muted">لا يوجد مخزون مسجل حالياً في النظام.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>

<?php require_once($dir_prefix . 'includes/footer.php'); ?>
