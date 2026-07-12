<?php
$dir_prefix = '../';
$module = 'inventory';
$report_title = 'الباتشات قريبة الانتهاء';
require_once($dir_prefix . 'includes/header.php');

check_permission(['admin']);

use AQNEX\Services\InventoryService;

$days = isset($_GET['days']) ? intval($_GET['days']) : 30;
$nearExpiryData = InventoryService::getNearExpiryItems($conn, $days);
?>

<title>الباتشات قريبة الانتهاء - AQNEX</title>

<style>
.expiry-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);
}
.expiry-table th {
    background: #0f172a;
    color: #fff;
    font-size: 0.8rem;
    font-weight: 700;
}
.expiry-table td {
    font-size: 0.78rem;
    vertical-align: middle;
}
.days-critical { color: #dc2626; font-weight: 900; background: #fee2e2; }
.days-warning { color: #d97706; font-weight: 800; background: #fef3c7; }
.days-safe { color: #059669; font-weight: 700; }
</style>

<div class="page-inner">
    
    <!-- Title Bar -->
    <div class="page-title-bar mb-4">
        <div class="ptb-left">
            <div class="icon-wrap"><i class="bi bi-calendar-x"></i></div>
            <div>
                <h4>تواريخ صلاحية الباتشات والمنتجات (قطاع البقالة والتموينات)</h4>
                <small>رصد الباتشات قريبة الانتهاء ومتابعة فترات الصلاحية للمواد الغذائية والتموينية</small>
            </div>
        </div>
        <div class="ptb-actions">
            <form method="GET" class="d-flex align-items-center" style="gap: 5px;">
                <label class="mb-0 text-muted font-weight-bold" style="font-size:0.75rem; white-space:nowrap;">فترة التنبيه:</label>
                <select name="days" class="form-control form-control-sm border" onchange="this.form.submit()">
                    <option value="15" <?= $days == 15 ? 'selected' : '' ?>>15 يوم</option>
                    <option value="30" <?= $days == 30 ? 'selected' : '' ?>>30 يوم (شهر)</option>
                    <option value="60" <?= $days == 60 ? 'selected' : '' ?>>60 يوم (شهرين)</option>
                    <option value="90" <?= $days == 90 ? 'selected' : '' ?>>90 يوم (3 أشهر)</option>
                </select>
            </form>
            <a href="../home.php" class="btn btn-sm btn-light border ml-2">
                <i class="bi bi-arrow-left ml-1"></i> عودة
            </a>
        </div>
    </div>

    <!-- Table Card -->
    <div class="expiry-card">
        <div class="card-body p-3">
            <div class="table-responsive">
                <table class="table table-bordered table-striped expiry-table text-center mb-0">
                    <thead>
                        <tr>
                            <th># م</th>
                            <th style="text-align:right;">اسم المنتج</th>
                            <th>رقم الباتش / الدفعة</th>
                            <th>تاريخ الانتهاء</th>
                            <th>الكمية المتبقية بالباتش</th>
                            <th>تكلفة الشراء</th>
                            <th>الأيام المتبقية للانتهاء</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($nearExpiryData)): $idx = 1; foreach ($nearExpiryData as $item): ?>
                            <?php 
                            $days_left = intval($item['days_left']);
                            $cell_class = '';
                            if ($days_left <= 0) {
                                $cell_class = 'days-critical';
                                $days_lbl = 'منتهي الصلاحية!';
                            } else if ($days_left <= 10) {
                                $cell_class = 'days-critical';
                                $days_lbl = 'متبقي ' . $days_left . ' يوم';
                            } else if ($days_left <= 30) {
                                $cell_class = 'days-warning';
                                $days_lbl = 'متبقي ' . $days_left . ' يوم';
                            } else {
                                $cell_class = 'days-safe';
                                $days_lbl = 'متبقي ' . $days_left . ' يوم';
                            }
                            ?>
                            <tr>
                                <td><?= $idx++ ?></td>
                                <td style="text-align:right; font-weight:700;"><?= htmlspecialchars($item['product_name']) ?></td>
                                <td style="font-family: monospace; font-weight: bold;"><?= htmlspecialchars($item['batch_number']) ?></td>
                                <td style="font-family: monospace; font-weight: bold; color: #b91c1c;"><?= $item['expiry_date'] ?></td>
                                <td style="font-family: monospace; font-weight: bold;"><?= number_format((float)$item['quantity'], 2) ?></td>
                                <td style="font-family: monospace;"><?= number_format((float)$item['cost_price'], 2) ?></td>
                                <td class="<?= $cell_class ?>" style="font-weight: 800; font-size: 0.8rem;">
                                    <?= $days_lbl ?>
                                </td>
                            </tr>
                        <?php endforeach; else: ?>
                            <tr>
                                <td colspan="7" class="text-center py-4 text-muted">ممتاز! لا توجد باتشات قريبة الانتهاء ضمن الفترة المحددة.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>

<?php require_once($dir_prefix . 'includes/footer.php'); ?>
