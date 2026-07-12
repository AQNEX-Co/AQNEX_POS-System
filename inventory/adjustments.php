<?php
$dir_prefix = '../';
$module = 'inventory';
$report_title = 'تسويات المخزون والتلفيات';
require_once($dir_prefix . 'includes/header.php');

check_permission(['admin']);

use AQNEX\Services\InventoryService;

$msg = '';
$err = '';

// معالجة إنشاء تسوية جديدة
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $warehouse_id    = intval($_POST['warehouse_id'] ?? 1);
    $adjustment_date = $_POST['adjustment_date'] ?? date('Y-m-d');
    $notes           = trim($_POST['notes'] ?? '');
    
    $product_id      = intval($_POST['product_id'] ?? 0);
    $quantity        = floatval($_POST['quantity'] ?? 0);
    $type            = $_POST['type'] ?? 'damaged'; // 'damaged'|'discrepancy'|'other'
    $cost_price      = floatval($_POST['cost_price'] ?? 0);

    if ($product_id <= 0 || $quantity == 0) {
        $err = 'يرجى اختيار صنف صحيح وإدخال كمية تسوية غير صفرية.';
    } else {
        $result = InventoryService::adjustStock($conn, [
            'warehouse_id'    => $warehouse_id,
            'adjustment_date' => $adjustment_date,
            'notes'           => $notes,
            'created_by'      => $_SESSION['SESS_FIRST_NAME'] ?? 'system',
            'items'           => [
                [
                    'product_id' => $product_id,
                    'quantity'   => $quantity,
                    'type'       => $type,
                    'cost_price' => $cost_price
                ]
            ]
        ]);

        if ($result['success']) {
            $msg = 'تم تسجيل حركة التسوية وتحديث قيود اليومية بنجاح.';
        } else {
            $err = $result['error'];
        }
    }
}

// جلب المنتجات والمستودعات والتسويات السابقة
$products = [];
$res_p = $conn->query("SELECT id, name, buy_price FROM products WHERE delete_status = 0 ORDER BY name ASC");
if ($res_p) while ($row = $res_p->fetch_assoc()) $products[] = $row;

$warehouses = [];
$res_w = $conn->query("SELECT id, name FROM warehouses ORDER BY id ASC");
if ($res_w) while ($row = $res_w->fetch_assoc()) $warehouses[] = $row;

$adjustments = [];
$sql_a = "
    SELECT 
        a.id,
        a.warehouse_id,
        a.adjustment_date,
        a.notes,
        a.created_by,
        w.name as wh_name,
        ai.product_id,
        ai.quantity,
        ai.type,
        ai.cost_price,
        p.name as product_name
    FROM stock_adjustments a
    JOIN stock_adjustment_items ai ON a.id = ai.adjustment_id
    JOIN products p ON ai.product_id = p.id
    JOIN warehouses w ON a.warehouse_id = w.id
    ORDER BY a.id DESC
";
$res_a = $conn->query($sql_a);
if ($res_a) while ($row = $res_a->fetch_assoc()) $adjustments[] = $row;
?>

<title>تسويات المخزون والتلفيات - AQNEX</title>

<style>
.adj-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);
    margin-bottom: 20px;
}
.adj-header {
    background: linear-gradient(135deg, #1e3a8a 0%, #0f172a 100%);
    color: #fff;
    font-weight: 700;
    padding: 12px 20px;
    border-top-left-radius: 7px;
    border-top-right-radius: 7px;
}
</style>

<div class="page-inner">
    
    <!-- Title Bar -->
    <div class="page-title-bar mb-4">
        <div class="ptb-left">
            <div class="icon-wrap"><i class="bi bi-sliders"></i></div>
            <div>
                <h4>تسويات المخزون وتسجيل التلفيات</h4>
                <small>تسوية العجز أو الفائض المخزني، وتسجيل البضائع التالفة مع عكس القيد محاسبياً</small>
            </div>
        </div>
        <div class="ptb-actions">
            <a href="../home.php" class="btn btn-sm btn-light border">
                <i class="bi bi-arrow-left ml-1"></i> عودة
            </a>
        </div>
    </div>

    <?php if (!empty($msg)): ?>
        <div class="alert alert-success rounded-0 mb-3"><i class="bi bi-check-circle-fill ml-2"></i> <?= $msg ?></div>
    <?php endif; ?>

    <?php if (!empty($err)): ?>
        <div class="alert alert-danger rounded-0 mb-3"><i class="bi bi-exclamation-triangle-fill ml-2"></i> <?= $err ?></div>
    <?php endif; ?>

    <div class="row">
        
        <!-- Form Panel -->
        <div class="col-lg-4">
            <div class="adj-card">
                <div class="adj-header">
                    <i class="bi bi-plus-circle ml-2"></i> تسجيل حركة تسوية جديدة
                </div>
                <div class="card-body p-3">
                    <form method="POST" id="adjustmentForm">
                        
                        <div class="mb-3">
                            <label class="form-label font-weight-bold">تاريخ التسوية</label>
                            <input type="date" name="adjustment_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label font-weight-bold">المستودع المعني</label>
                            <select name="warehouse_id" class="form-control" required>
                                <?php foreach ($warehouses as $wh): ?>
                                    <option value="<?= $wh['id'] ?>"><?= htmlspecialchars($wh['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label font-weight-bold">اختر الصنف / المنتج</label>
                            <select name="product_id" id="adjProductSelect" class="form-control select2" required onchange="updateDefaultCost()">
                                <option value="">-- اختر المنتج للتسوية --</option>
                                <?php foreach ($products as $p): ?>
                                    <option value="<?= $p['id'] ?>" data-cost="<?= floatval($p['buy_price']) ?>"><?= htmlspecialchars($p['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label font-weight-bold">كمية التسوية</label>
                            <input type="number" step="0.0001" name="quantity" class="form-control" placeholder="مثال: -5 للتلف أو 3 للزيادة" required>
                            <small class="text-muted">الكمية السالبة تمثل عجز/تلف، والموجبة زيادة.</small>
                        </div>

                        <div class="mb-3">
                            <label class="form-label font-weight-bold">نوع التسوية</label>
                            <select name="type" class="form-control" required>
                                <option value="damaged">بضاعة تالفة (تولد قيد خسائر 5105)</option>
                                <option value="discrepancy">فروقات جرد مخزني</option>
                                <option value="other">تسويات أخرى</option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label font-weight-bold">سعر تكلفة وحدة التسوية</label>
                            <input type="number" step="0.0001" name="cost_price" id="adjCostPrice" class="form-control" placeholder="0.00" required>
                            <small class="text-muted">سيتم جلب تكلفة الشراء الحالية تلقائياً.</small>
                        </div>

                        <div class="mb-3">
                            <label class="form-label font-weight-bold">البيان / أسباب التسوية</label>
                            <textarea name="notes" class="form-control" rows="2" placeholder="السبب التفصيلي لحركة الجرد أو التلف..."></textarea>
                        </div>

                        <button type="submit" class="btn btn-primary btn-block py-2 font-weight-bold" style="background: linear-gradient(135deg, #1e3a8a 0%, #0f172a 100%); border: none;">
                            <i class="bi bi-check-circle ml-1"></i> حفظ وتثبيت حركة التسوية
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- History List -->
        <div class="col-lg-8">
            <div class="adj-card">
                <div class="adj-header">
                    <i class="bi bi-list-check ml-2"></i> سجل تسويات وجرد التلفيات السابقة
                </div>
                <div class="card-body p-2">
                    <div class="table-responsive">
                        <table class="table table-bordered table-striped text-center mb-0" style="font-size: 0.78rem;">
                            <thead class="table-dark">
                                <tr>
                                    <th>رقم التسوية</th>
                                    <th>التاريخ</th>
                                    <th>المستودع</th>
                                    <th style="text-align:right;">اسم المنتج</th>
                                    <th>الكمية المعدلة</th>
                                    <th>النوع</th>
                                    <th>تكلفة الوحدة</th>
                                    <th>إجمالي التكلفة</th>
                                    <th>المسؤول / الملاحظات</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($adjustments)): foreach ($adjustments as $a): ?>
                                    <?php 
                                    $is_loss = floatval($a['quantity']) < 0; 
                                    $total_val = abs(floatval($a['quantity']) * floatval($a['cost_price']));
                                    ?>
                                    <tr>
                                        <td style="font-weight:bold;">#ADJ-<?= $a['id'] ?></td>
                                        <td><?= $a['adjustment_date'] ?></td>
                                        <td><?= htmlspecialchars($a['wh_name']) ?></td>
                                        <td style="text-align:right; font-weight:700;"><?= htmlspecialchars($a['product_name']) ?></td>
                                        <td style="font-weight:bold; color: <?= $is_loss ? '#dc2626' : '#10b981' ?>;">
                                            <?= floatval($a['quantity']) ?>
                                        </td>
                                        <td>
                                            <?php if ($a['type'] === 'damaged'): ?>
                                                <span class="badge bg-danger text-white">تلف / خسارة</span>
                                            <?php elseif ($a['type'] === 'discrepancy'): ?>
                                                <span class="badge bg-warning text-dark">فروق جرد</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary text-white">أخرى</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= number_format((float)$a['cost_price'], 2) ?></td>
                                        <td style="font-weight:800;"><?= number_format($total_val, 2) ?></td>
                                        <td style="text-align:right; font-size:0.72rem;">
                                            <span class="text-primary font-weight-bold"><?= htmlspecialchars($a['created_by']) ?></span><br>
                                            <small class="text-muted"><?= htmlspecialchars($a['notes'] ?: '—') ?></small>
                                        </td>
                                    </tr>
                                <?php endforeach; else: ?>
                                    <tr>
                                        <td colspan="9" class="text-center py-4 text-muted">لا توجد تسويات مخزنية مسجلة.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<script>
function updateDefaultCost() {
    const select = document.getElementById('adjProductSelect');
    const selectedOption = select.options[select.selectedIndex];
    const cost = selectedOption ? parseFloat(selectedOption.getAttribute('data-cost')) : 0;
    document.getElementById('adjCostPrice').value = cost.toFixed(2);
}
</script>

<?php require_once($dir_prefix . 'includes/footer.php'); ?>
