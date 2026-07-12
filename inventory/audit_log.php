<?php
$dir_prefix = '../';
$module = 'inventory';
$report_title = 'سجل حركات المخازن التفصيلي';
require_once($dir_prefix . 'includes/header.php');

check_permission(['admin']);

// فلاتر البحث
$search      = isset($_GET['search']) ? $conn->real_escape_string($_GET['search']) : '';
$action_type = isset($_GET['action_type']) ? $conn->real_escape_string($_GET['action_type']) : '';
$warehouse_id= isset($_GET['warehouse_id']) ? intval($_GET['warehouse_id']) : 0;
$from_date   = isset($_GET['from_date']) ? $conn->real_escape_string($_GET['from_date']) : '';
$to_date     = isset($_GET['to_date']) ? $conn->real_escape_string($_GET['to_date']) : '';

$where_clauses = ["1=1"];

if (!empty($search)) {
    $where_clauses[] = "(p.name LIKE '%$search%' OR p.barcode LIKE '%$search%' OR l.user_id LIKE '%$search%')";
}
if (!empty($action_type)) {
    $where_clauses[] = "l.action_type = '$action_type'";
}
if ($warehouse_id > 0) {
    $where_clauses[] = "l.warehouse_id = $warehouse_id";
}
if (!empty($from_date)) {
    $where_clauses[] = "l.created_at >= '$from_date 00:00:00'";
}
if (!empty($to_date)) {
    $where_clauses[] = "l.created_at <= '$to_date 23:59:59'";
}

$where_sql = implode(" AND ", $where_clauses);

// جلب حركات سجل التدقيق
$sql = "
    SELECT 
        l.id,
        l.product_id,
        l.action_type,
        l.quantity,
        l.cost_price,
        l.warehouse_id,
        l.reference_table,
        l.reference_id,
        l.user_id,
        l.created_at,
        p.name as product_name,
        p.barcode,
        w.name as wh_name
    FROM inventory_audit_log l
    JOIN products p ON l.product_id = p.id
    JOIN warehouses w ON l.warehouse_id = w.id
    WHERE $where_sql
    ORDER BY l.id DESC
    LIMIT 200
";

$result = $conn->query($sql);

$warehouses = [];
$res_w = $conn->query("SELECT id, name FROM warehouses ORDER BY id ASC");
if ($res_w) while ($row = $res_w->fetch_assoc()) $warehouses[] = $row;

// ترجمة الحركات للعربية
function get_action_label($type) {
    switch ($type) {
        case 'in': return '<span class="badge bg-success text-white">🟢 وارد / توريد</span>';
        case 'out': return '<span class="badge bg-danger text-white">🔴 صادر / مبيعات</span>';
        case 'transfer_out': return '<span class="badge bg-warning text-dark">🟡 تحويل صادر</span>';
        case 'transfer_in': return '<span class="badge bg-info text-white">🔵 تحويل وارد</span>';
        case 'adjustment': return '<span class="badge bg-secondary text-white">⚪ تسوية / جرد</span>';
        default: return $type;
    }
}
?>

<title>سجل حركات المخازن - AQNEX</title>

<style>
.log-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);
}
.log-table th {
    background: #0f172a;
    color: #fff;
    font-size: 0.8rem;
    font-weight: 700;
}
.log-table td {
    font-size: 0.78rem;
    vertical-align: middle;
}
</style>

<div class="page-inner">
    
    <!-- Title Bar -->
    <div class="page-title-bar mb-4">
        <div class="ptb-left">
            <div class="icon-wrap"><i class="bi bi-clock-history"></i></div>
            <div>
                <h4>سجل حركة المخزن وتدقيق الكميات (Audit Log)</h4>
                <small>متابعة تفصيلية لحركات التوريد والصرف والتحويل والتسويات التاريخية</small>
            </div>
        </div>
        <div class="ptb-actions">
            <a href="../home.php" class="btn btn-sm btn-light border">
                <i class="bi bi-arrow-left ml-1"></i> عودة
            </a>
        </div>
    </div>

    <!-- Filter Bar -->
    <div class="log-card mb-4">
        <div class="card-body p-3">
            <form method="GET">
                <div class="row">
                    <div class="col-md-3 mb-2">
                        <label class="form-label" style="font-size: 0.75rem; font-weight: bold;">البحث بالاسم / الباركود</label>
                        <input type="text" name="search" class="form-control form-control-sm" placeholder="اسم الصنف، باركود..." value="<?= htmlspecialchars($search) ?>">
                    </div>
                    <div class="col-md-2 mb-2">
                        <label class="form-label" style="font-size: 0.75rem; font-weight: bold;">نوع الحركة</label>
                        <select name="action_type" class="form-control form-control-sm">
                            <option value="">-- الكل --</option>
                            <option value="in" <?= $action_type == 'in' ? 'selected' : '' ?>>وارد</option>
                            <option value="out" <?= $action_type == 'out' ? 'selected' : '' ?>>صادر</option>
                            <option value="transfer_out" <?= $action_type == 'transfer_out' ? 'selected' : '' ?>>تحويل صادر</option>
                            <option value="transfer_in" <?= $action_type == 'transfer_in' ? 'selected' : '' ?>>تحويل وارد</option>
                            <option value="adjustment" <?= $action_type == 'adjustment' ? 'selected' : '' ?>>تسوية</option>
                        </select>
                    </div>
                    <div class="col-md-3 mb-2">
                        <label class="form-label" style="font-size: 0.75rem; font-weight: bold;">المستودع</label>
                        <select name="warehouse_id" class="form-control form-control-sm">
                            <option value="0">-- جميع المستودعات --</option>
                            <?php foreach ($warehouses as $wh): ?>
                                <option value="<?= $wh['id'] ?>" <?= $warehouse_id == $wh['id'] ? 'selected' : '' ?>><?= htmlspecialchars($wh['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2 mb-2">
                        <label class="form-label" style="font-size: 0.75rem; font-weight: bold;">من تاريخ</label>
                        <input type="date" name="from_date" class="form-control form-control-sm" value="<?= $from_date ?>">
                    </div>
                    <div class="col-md-2 mb-2">
                        <label class="form-label" style="font-size: 0.75rem; font-weight: bold;">إلى تاريخ</label>
                        <input type="date" name="to_date" class="form-control form-control-sm" value="<?= $to_date ?>">
                    </div>
                </div>
                <div class="text-left mt-2">
                    <button type="submit" class="btn btn-sm btn-primary px-4"><i class="fa fa-filter ml-1"></i> تصفية الحركات</button>
                    <a href="audit_log.php" class="btn btn-sm btn-outline-secondary ml-2">إعادة تعيين</a>
                </div>
            </form>
        </div>
    </div>

    <!-- Table Card -->
    <div class="log-card">
        <div class="card-body p-2">
            <div class="table-responsive">
                <table class="table table-bordered table-striped log-table text-center mb-0">
                    <thead>
                        <tr>
                            <th>رقم الحركة</th>
                            <th>التاريخ والوقت</th>
                            <th>المستودع</th>
                            <th style="text-align:right;">المنتج / الصنف</th>
                            <th>نوع الحركة</th>
                            <th>الكمية</th>
                            <th>سعر التكلفة</th>
                            <th>مرجع العملية</th>
                            <th>بواسطة</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($result && $result->num_rows > 0): while ($row = $result->fetch_assoc()): ?>
                            <tr>
                                <td>#LOG-<?= $row['id'] ?></td>
                                <td style="font-family: monospace;"><?= $row['created_at'] ?></td>
                                <td class="font-weight-bold"><?= htmlspecialchars($row['wh_name']) ?></td>
                                <td style="text-align:right; font-weight: 700;">
                                    [<?= htmlspecialchars($row['barcode'] ?: $row['product_id']) ?>] <?= htmlspecialchars($row['product_name']) ?>
                                </td>
                                <td><?= get_action_label($row['action_type']) ?></td>
                                <td style="font-family: monospace; font-weight: bold;"><?= number_format((float)$row['quantity'], 2) ?></td>
                                <td style="font-family: monospace;"><?= number_format((float)$row['cost_price'], 2) ?></td>
                                <td style="font-size:0.75rem;">
                                    <?= htmlspecialchars($row['reference_table'] ?: '—') ?>
                                    <?= $row['reference_id'] ? '#' . $row['reference_id'] : '' ?>
                                </td>
                                <td class="text-primary font-weight-bold"><?= htmlspecialchars($row['user_id']) ?></td>
                            </tr>
                        <?php endwhile; else: ?>
                            <tr>
                                <td colspan="9" class="text-center py-4 text-muted">لا توجد حركات مخزنية مطابقة لخيارات الفلترة.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>

<?php require_once($dir_prefix . 'includes/footer.php'); ?>
