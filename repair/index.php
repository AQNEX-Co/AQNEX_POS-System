<?php
$dir_prefix = '../';
$module = 'repair';
require_once($dir_prefix . 'includes/header.php');
@include_once($dir_prefix . 'includes/modules.php');

// التحقق من صلاحية الصيانة أو الأدمن
check_permission(['admin', 'cashier']);

// التحقق من تفعيل الموديول
if (!is_module_enabled('repair_service')) {
    echo '
    <div class="card-flat">
        <div class="card-body text-center py-5">
            <h4 class="text-danger mb-3">' . get_icon('briefcase', 'ml-2') . 'موديول الصيانة غير مفعل</h4>
            <p class="text-muted">يرجى تفعيل موديول إدارة الصيانة والأجهزة من إعدادات النظام لتتمكن من استخدام هذه الشاشة.</p>
            ' . (($_SESSION['SESS_LAST_NAME'] === 'admin') ? '<a href="../settings/modules.php" class="btn btn-primary rounded-0 px-4">لوحة إدارة الموديولات</a>' : '') . '
        </div>
    </div>';
    require_once($dir_prefix . 'includes/footer.php');
    exit;
}

$status_filter = isset($_GET['status']) ? $conn->real_escape_string($_GET['status']) : '';
$search_query = isset($_GET['search']) ? $conn->real_escape_string(trim($_GET['search'])) : '';

// بناء الاستعلام مع الفلترة
$where_clauses = ["r.d_s = '0'"];
if (!empty($status_filter)) {
    $where_clauses[] = "r.status = '$status_filter'";
}
if (!empty($search_query)) {
    $where_clauses[] = "(r.ticket_number LIKE '%$search_query%' OR r.device_name LIKE '%$search_query%' OR r.issue_type LIKE '%$search_query%' OR r.device_brand LIKE '%$search_query%' OR r.device_type LIKE '%$search_query%' OR c.cust_name LIKE '%$search_query%')";
}

$where_sql = implode(" AND ", $where_clauses);

$sql_tickets = "
    SELECT r.*, c.cust_name 
    FROM repair_tickets r 
    LEFT JOIN customers c ON r.customer_id = c.cust_id 
    WHERE $where_sql 
    ORDER BY r.id DESC
";
$res_tickets = $conn->query($sql_tickets);
?>

<!-- ======================== Onyx Pro Header Bar (مطابق لشاشة المبيعات) ======================== -->
<div class="aqnex-window-header no-print mb-2" style="background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%); color: #fff; padding: 10px 16px; border-radius: 4px; display: flex; align-items: center; justify-content: space-between; border-bottom: 2px solid #3b82f6;">
    <div style="display: flex; align-items: center; gap: 10px;">
        <span style="font-weight: 800; font-size: 1.15rem; color: #38bdf8; text-shadow: 0 1px 2px rgba(0,0,0,0.5);">
            <i class="bi bi-tools ml-2"></i> مركز الصيانة وأجهزة العملاء
        </span>
        <span class="badge badge-primary px-2 py-1" style="font-size: 0.75rem; background:#2563eb;">إدارة الأجهزة والتذاكر</span>
    </div>
    <div style="font-size: 0.85rem; color: #94a3b8;">
        <i class="bi bi-pc-display ml-1"></i> تكنولوجيا فون — نظام إدارة الصيانة المتقدم
    </div>
</div>

<!-- ======================== Onyx Pro Toolbar ======================== -->
<div class="aqnex-toolbar no-print mb-3" style="background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 4px; padding: 6px 12px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px;">
    <div style="display: flex; align-items: center; gap: 6px;">
        <!-- ➕ فتح تذكرة جديدة -->
        <button type="button" class="tool-btn" title="فتح تذكرة صيانة جديدة" onclick="window.location.href='create.php';" style="color: #2563eb; border-color: #93c5fd;">
            <i class="bi bi-plus-lg"></i> <span>تذكرة جديدة</span>
        </button>

        <div style="height: 20px; width: 1px; background: #cbd5e1; margin: 0 4px;"></div>

        <!-- 🔄 تحديث -->
        <button type="button" class="tool-btn" title="تحديث القائمة" onclick="window.location.reload();">
            <i class="bi bi-arrow-clockwise"></i>
        </button>

        <!-- 🖨️ طباعة -->
        <button type="button" class="tool-btn" title="طباعة السجل" onclick="window.print();">
            <i class="bi bi-printer"></i>
        </button>
    </div>
</div>

<!-- بطاقة البحث والفلترة -->
<div class="card p-3 mb-3 no-print" style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:4px;">
    <form method="GET" class="row align-items-center">
        <div class="col-md-5 mb-2">
            <div class="aqnex-form-group">
                <label class="aqnex-label font-weight-bold text-secondary">البحث السريع:</label>
                <input type="text" name="search" class="aqnex-input" placeholder="ابحث برقم التذكرة، العميل، نوع الجهاز، أو IMEI..." value="<?php echo htmlspecialchars($search_query); ?>">
            </div>
        </div>
        <div class="col-md-4 mb-2">
            <div class="aqnex-form-group">
                <label class="aqnex-label font-weight-bold text-secondary">حالة التذكرة:</label>
                <select name="status" class="aqnex-select">
                    <option value="">-- كل حالات التذاكر --</option>
                    <option value="received" <?php echo $status_filter === 'received' ? 'selected' : ''; ?>>تم الاستلام</option>
                    <option value="in_progress" <?php echo $status_filter === 'in_progress' ? 'selected' : ''; ?>>قيد الفحص والصيانة</option>
                    <option value="waiting_parts" <?php echo $status_filter === 'waiting_parts' ? 'selected' : ''; ?>>في انتظار قطع الغيار</option>
                    <option value="completed" <?php echo $status_filter === 'completed' ? 'selected' : ''; ?>>جاهز للتسليم</option>
                    <option value="delivered" <?php echo $status_filter === 'delivered' ? 'selected' : ''; ?>>تم التسليم للعميل</option>
                    <option value="cancelled" <?php echo $status_filter === 'cancelled' ? 'selected' : ''; ?>>ملغاة</option>
                </select>
            </div>
        </div>
        <div class="col-md-3 mb-2 d-flex gap-2" style="margin-top: 22px;">
            <button type="submit" class="btn btn-primary font-weight-bold px-3 py-1" style="font-size:0.85rem;"><i class="bi bi-search ml-1"></i> تصفية</button>
            <a href="index.php" class="btn btn-outline-secondary font-weight-bold px-3 py-1 mr-2" style="font-size:0.85rem;">إعادة تعيين</a>
        </div>
    </form>
</div>

<div class="card p-3" style="background:#fff; border:1px solid #e2e8f0; border-radius:4px;">
    <div class="table-responsive">
        <table class="table table-bordered table-hover text-center" style="font-size:0.87rem; vertical-align:middle;">
            <thead style="background:#f1f5f9; color:#334155;">
                <tr>
                    <th>رقم التذكرة</th>
                    <th>العميل</th>
                    <th>نوع الجهاز</th>
                    <th>الرقم التسلسلي / IMEI</th>
                    <th>الحالة</th>
                    <th>التكلفة التقديرية</th>
                    <th>التكلفة النهائية</th>
                    <th>تاريخ الاستلام</th>
                    <th class="no-print">إجراء</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$res_tickets || $res_tickets->num_rows == 0): ?>
                    <tr>
                        <td colspan="9" class="text-center text-muted py-4">لا توجد تذاكر صيانة مطابقة للخيارات المدخلة.</td>
                    </tr>
                <?php else: ?>
                    <?php while ($t = $res_tickets->fetch_assoc()): ?>
                        <tr>
                            <td class="font-weight-bold">
                                <a href="view.php?id=<?php echo $t['id']; ?>" class="text-primary font-weight-bold">
                                    <?php echo htmlspecialchars($t['ticket_number']); ?>
                                </a>
                            </td>
                            <td class="font-weight-bold"><?php echo htmlspecialchars($t['cust_name'] ?: 'عميل نقدي'); ?></td>
                            <td><?php echo htmlspecialchars($t['device_name'] ?: ($t['device_brand'] . ' - ' . $t['device_type'])); ?></td>
                            <td class="dir-ltr text-center"><code><?php echo htmlspecialchars($t['imei'] ?: '-'); ?></code></td>
                            <td>
                                <?php 
                                switch ($t['status']) {
                                    case 'received': 
                                        echo '<span class="badge badge-secondary px-2 py-1">تم الاستلام</span>'; 
                                        break;
                                    case 'in_progress': 
                                        echo '<span class="badge badge-info px-2 py-1">قيد الصيانة</span>'; 
                                        break;
                                    case 'waiting_parts': 
                                        echo '<span class="badge badge-warning px-2 py-1 text-dark">بانتظار قطع</span>'; 
                                        break;
                                    case 'completed': 
                                        echo '<span class="badge badge-success px-2 py-1">جاهز للتسليم</span>'; 
                                        break;
                                    case 'delivered': 
                                        echo '<span class="badge badge-dark px-2 py-1">تم التسليم والتحصيل</span>'; 
                                        break;
                                    case 'cancelled': 
                                        echo '<span class="badge badge-danger px-2 py-1">ملغاة</span>'; 
                                        break;
                                }
                                ?>
                            </td>
                            <td class="text-primary font-weight-bold"><?php echo number_format($t['estimated_cost'], 2); ?> ر.ي</td>
                            <td class="text-success font-weight-bold"><?php echo number_format($t['final_cost'], 2); ?> ر.ي</td>
                            <td><?php echo date('Y-m-d H:i', strtotime($t['received_date'])); ?></td>
                            <td class="no-print">
                                <a href="view.php?id=<?php echo $t['id']; ?>" class="btn btn-outline-primary btn-sm px-2 py-0" title="عرض وتفاصيل"><i class="bi bi-eye"></i> عرض</a>
                                <?php if ($t['status'] !== 'delivered' && $t['status'] !== 'cancelled'): ?>
                                    <a href="view.php?id=<?php echo $t['id']; ?>#settlement" class="btn btn-success btn-sm px-2 py-0 mr-1" title="تسليم وتصفية"><i class="bi bi-check-circle"></i> تسليم</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once($dir_prefix . 'includes/footer.php'); ?>

