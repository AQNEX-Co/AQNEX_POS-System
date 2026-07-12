<?php
$dir_prefix = '../';
$module = 'inventory';
$report_title = 'التحويل المخزني بين المستودعات';
require_once($dir_prefix . 'includes/header.php');

check_permission(['admin']);

use AQNEX\Services\InventoryService;

$msg = '';
$err = '';

// معالجة اعتماد التحويل
if (isset($_POST['action']) && $_POST['action'] === 'approve') {
    $transfer_id = intval($_POST['transfer_id'] ?? 0);
    $result = InventoryService::approveTransfer($conn, $transfer_id, $_SESSION['SESS_FIRST_NAME'] ?? 'admin');
    if ($result['success']) {
        $msg = 'تم اعتماد التحويل بنظام المخازن وتحديث الكميات بنجاح.';
    } else {
        $err = $result['error'];
    }
}

// معالجة رفض التحويل
if (isset($_POST['action']) && $_POST['action'] === 'reject') {
    $transfer_id = intval($_POST['transfer_id'] ?? 0);
    if ($conn->query("UPDATE `stock_transfers` SET status = 'rejected' WHERE id = $transfer_id")) {
        $msg = 'تم رفض وإلغاء طلب التحويل.';
    } else {
        $err = $conn->error;
    }
}

// معالجة إنشاء تحويل جديد
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create') {
    $from_wh = intval($_POST['from_warehouse_id'] ?? 0);
    $to_wh   = intval($_POST['to_warehouse_id'] ?? 0);
    $notes   = trim($_POST['notes'] ?? '');
    
    $product_ids = $_POST['product_ids'] ?? [];
    $quantities  = $_POST['quantities'] ?? [];

    $items = [];
    foreach ($product_ids as $idx => $p_id) {
        $p_id = intval($p_id);
        $qty  = floatval($quantities[$idx] ?? 0);
        if ($p_id > 0 && $qty > 0) {
            $items[] = [
                'product_id' => $p_id,
                'quantity' => $qty,
                'batch_id' => null,
                'serial_id' => null
            ];
        }
    }

    if ($from_wh === $to_wh) {
        $err = 'المستودع المصدر والمستودع المستهدف لا يمكن أن يكونا نفس المستودع.';
    } else if (empty($items)) {
        $err = 'يرجى اختيار صنف واحد على الأقل وإدخال كمية صحيحة.';
    } else {
        $result = InventoryService::transferStock($conn, [
            'from_warehouse_id' => $from_wh,
            'to_warehouse_id'   => $to_wh,
            'transfer_date'     => date('Y-m-d'),
            'notes'             => $notes,
            'created_by'        => $_SESSION['SESS_FIRST_NAME'] ?? 'system',
            'items'             => $items
        ]);

        if ($result['success']) {
            $msg = 'تم إنشاء طلب التحويل بنجاح وهو الآن بانتظار الاعتماد.';
        } else {
            $err = $result['error'];
        }
    }
}

// جلب المستودعات والأصناف والطلبات السابقة
$warehouses = [];
$res_w = $conn->query("SELECT id, name FROM warehouses ORDER BY id ASC");
if ($res_w) while ($row = $res_w->fetch_assoc()) $warehouses[] = $row;

$products = [];
$res_p = $conn->query("SELECT id, name, quantity FROM products WHERE delete_status = 0 ORDER BY name ASC");
if ($res_p) while ($row = $res_p->fetch_assoc()) $products[] = $row;

// جلب طلبات التحويل
$transfers = [];
$sql_t = "
    SELECT 
        t.id,
        t.from_warehouse_id,
        t.to_warehouse_id,
        t.transfer_date,
        t.status,
        t.created_by,
        t.approved_by,
        t.notes,
        w1.name as from_wh_name,
        w2.name as to_wh_name
    FROM stock_transfers t
    JOIN warehouses w1 ON t.from_warehouse_id = w1.id
    JOIN warehouses w2 ON t.to_warehouse_id = w2.id
    ORDER BY t.id DESC
";
$res_t = $conn->query($sql_t);
if ($res_t) while ($row = $res_t->fetch_assoc()) $transfers[] = $row;
?>

<title>التحويل المخزني - AQNEX</title>

<style>
.trans-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);
    margin-bottom: 20px;
}
.trans-header {
    background: linear-gradient(135deg, #1e3a8a 0%, #0f172a 100%);
    color: #fff;
    font-weight: 700;
    padding: 12px 20px;
    border-top-left-radius: 7px;
    border-top-right-radius: 7px;
}
.badge-pending { background: #fef3c7; color: #d97706; padding: 4px 10px; border-radius: 20px; font-weight: bold; font-size: 0.72rem; }
.badge-approved { background: #d1fae5; color: #065f46; padding: 4px 10px; border-radius: 20px; font-weight: bold; font-size: 0.72rem; }
.badge-rejected { background: #fee2e2; color: #991b1b; padding: 4px 10px; border-radius: 20px; font-weight: bold; font-size: 0.72rem; }
</style>

<div class="page-inner">
    
    <!-- Title Bar -->
    <div class="page-title-bar mb-4">
        <div class="ptb-left">
            <div class="icon-wrap"><i class="bi bi-arrow-right-left"></i></div>
            <div>
                <h4>التحويل المخزني بين المستودعات</h4>
                <small>إجراء تحويل بضائع داخلي من مستودع إلى آخر مع تفعيل دورة الاعتماد</small>
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
        
        <!-- Form columns -->
        <div class="col-lg-4">
            <div class="trans-card">
                <div class="trans-header">
                    <i class="bi bi-plus-circle ml-2"></i> طلب تحويل جديد
                </div>
                <div class="card-body p-3">
                    <form method="POST">
                        <input type="hidden" name="action" value="create">
                        
                        <div class="mb-3">
                            <label class="form-label font-weight-bold">من مستودع (المصدر)</label>
                            <select name="from_warehouse_id" class="form-control" required>
                                <?php foreach ($warehouses as $wh): ?>
                                    <option value="<?= $wh['id'] ?>"><?= htmlspecialchars($wh['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label font-weight-bold">إلى مستودع (المستهدف)</label>
                            <select name="to_warehouse_id" class="form-control" required>
                                <?php foreach ($warehouses as $wh): ?>
                                    <option value="<?= $wh['id'] ?>" <?= $wh['id'] == 2 ? 'selected' : '' ?>><?= htmlspecialchars($wh['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3 border-top pt-3">
                            <label class="form-label font-weight-bold">جدول الأصناف المراد نقلها</label>
                            <div id="transferItemsContainer">
                                <div class="row mb-2 item-select-row">
                                    <div class="col-8 pr-1">
                                        <select name="product_ids[]" class="form-control form-control-sm" required>
                                            <option value="">-- اختر المنتج --</option>
                                            <?php foreach ($products as $p): ?>
                                                <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?> (<?= floatval($p['quantity']) ?>)</option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-4 pl-1">
                                        <input type="number" step="0.0001" name="quantities[]" class="form-control form-control-sm" placeholder="الكمية" required>
                                    </div>
                                </div>
                            </div>
                            <button type="button" class="btn btn-xs btn-outline-secondary mt-1" onclick="addTransferItemRow()">
                                <i class="bi bi-plus-lg"></i> إضافة صنف آخر
                            </button>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">ملاحظات التحويل</label>
                            <textarea name="notes" class="form-control" rows="2" placeholder="أدخل أية تفاصيل إضافية..."></textarea>
                        </div>

                        <button type="submit" class="btn btn-primary btn-block py-2 font-weight-bold" style="background: linear-gradient(135deg, #1e3a8a 0%, #0f172a 100%); border: none;">
                            <i class="bi bi-arrow-right-left ml-1"></i> إرسال طلب التحويل
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- History & Approvals List -->
        <div class="col-lg-8">
            <div class="trans-card">
                <div class="trans-header">
                    <i class="bi bi-list-check ml-2"></i> سجل طلبات التحويل والاعتمادات المعلقة
                </div>
                <div class="card-body p-2">
                    <div class="table-responsive">
                        <table class="table table-bordered table-striped text-center mb-0" style="font-size:0.78rem;">
                            <thead class="table-dark">
                                <tr>
                                    <th>رقم الطلب</th>
                                    <th>تاريخ الطلب</th>
                                    <th>من مستودع</th>
                                    <th>إلى مستودع</th>
                                    <th>أنشئ بواسطة</th>
                                    <th>الحالة</th>
                                    <th>الأصناف والتفاصيل</th>
                                    <th>العملية</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($transfers)): foreach ($transfers as $t): ?>
                                    <tr>
                                        <td style="font-weight:bold;">#TR-<?= $t['id'] ?></td>
                                        <td><?= $t['transfer_date'] ?></td>
                                        <td class="text-danger font-weight-bold"><?= htmlspecialchars($t['from_wh_name']) ?></td>
                                        <td class="text-success font-weight-bold"><?= htmlspecialchars($t['to_wh_name']) ?></td>
                                        <td><?= htmlspecialchars($t['created_by']) ?></td>
                                        <td>
                                            <span class="badge-<?= $t['status'] ?>">
                                                <?= $t['status'] === 'pending' ? 'بانتظار الاعتماد' : ($t['status'] === 'approved' ? 'مقبول ومعتمد' : 'مرفوض') ?>
                                            </span>
                                        </td>
                                        <td style="text-align:right; font-size:0.72rem; max-width: 180px;">
                                            <?php
                                            $res_ti = $conn->query("
                                                SELECT ti.quantity, p.name 
                                                FROM stock_transfer_items ti
                                                JOIN products p ON ti.product_id = p.id
                                                WHERE ti.transfer_id = {$t['id']}
                                            ");
                                            if ($res_ti) {
                                                while ($item = $res_ti->fetch_assoc()) {
                                                    echo "• " . htmlspecialchars($item['name']) . " (" . floatval($item['quantity']) . ")<br>";
                                                }
                                            }
                                            if (!empty($t['notes'])) {
                                                echo "<small class='text-muted'>ملاحظة: " . htmlspecialchars($t['notes']) . "</small>";
                                            }
                                            ?>
                                        </td>
                                        <td>
                                            <?php if ($t['status'] === 'pending'): ?>
                                                <div class="d-flex gap-1 justify-content-center">
                                                    <form method="POST" style="display:inline-block;" onsubmit="return confirm('تأكيد الموافقة على تحويل البضائع الفعلي؟')">
                                                        <input type="hidden" name="action" value="approve">
                                                        <input type="hidden" name="transfer_id" value="<?= $t['id'] ?>">
                                                        <button type="submit" class="btn btn-xs btn-success py-0 px-2 font-weight-bold" style="font-size:0.7rem;">اعتماد</button>
                                                    </form>
                                                    <form method="POST" style="display:inline-block;" onsubmit="return confirm('تأكيد رفض طلب التحويل؟')">
                                                        <input type="hidden" name="action" value="reject">
                                                        <input type="hidden" name="transfer_id" value="<?= $t['id'] ?>">
                                                        <button type="submit" class="btn btn-xs btn-danger py-0 px-2 font-weight-bold" style="font-size:0.7rem;">رفض</button>
                                                    </form>
                                                </div>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; else: ?>
                                    <tr>
                                        <td colspan="8" class="text-center py-4 text-muted">لا توجد عمليات تحويل مخزني مسجلة.</td>
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
function addTransferItemRow() {
    const container = document.getElementById('transferItemsContainer');
    const firstRow = container.querySelector('.item-select-row');
    const newRow = firstRow.cloneNode(true);
    
    // Clear selected value and inputs
    newRow.querySelector('select').value = '';
    newRow.querySelector('input').value = '';
    
    container.appendChild(newRow);
}
</script>

<?php require_once($dir_prefix . 'includes/footer.php'); ?>
