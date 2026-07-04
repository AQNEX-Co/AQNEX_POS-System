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

<title>إدارة الصيانة والأجهزة - تكنولوجيا فون</title>

<div class="card-flat">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5><?php echo get_icon('briefcase', 'ml-2 text-primary'); ?> تذاكر صيانة الأجهزة والأعطال</h5>
        <a href="create.php" class="btn-flat btn-flat-success btn-sm text-decoration-none">
            <?php echo get_icon('plus', 'ml-1'); ?> فتح تذكرة صيانة جديدة
        </a>
    </div>

    <!-- فلترة وبحث -->
    <div class="card-body bg-light border-bottom no-print">
        <form method="GET" class="row">
            <div class="col-md-4 mb-2">
                <input type="text" name="search" class="form-control rounded-0" placeholder="ابحث برقم التذكرة، العميل، نوع الجهاز..." value="<?php echo htmlspecialchars($search_query); ?>">
            </div>
            <div class="col-md-3 mb-2">
                <select name="status" class="form-control rounded-0">
                    <option value="">-- كل حالات التذاكر --</option>
                    <option value="received" <?php echo $status_filter === 'received' ? 'selected' : ''; ?>>تم الاستلام</option>
                    <option value="in_progress" <?php echo $status_filter === 'in_progress' ? 'selected' : ''; ?>>قيد الفحص والصيانة</option>
                    <option value="waiting_parts" <?php echo $status_filter === 'waiting_parts' ? 'selected' : ''; ?>>في انتظار قطع الغيار</option>
                    <option value="completed" <?php echo $status_filter === 'completed' ? 'selected' : ''; ?>>جاهز للتسليم</option>
                    <option value="delivered" <?php echo $status_filter === 'delivered' ? 'selected' : ''; ?>>تم التسليم للعميل</option>
                    <option value="cancelled" <?php echo $status_filter === 'cancelled' ? 'selected' : ''; ?>>ملغاة</option>
                </select>
            </div>
            <div class="col-md-2 mb-2">
                <button type="submit" class="btn btn-primary btn-block rounded-0"><?php echo get_icon('search'); ?> تصفية</button>
            </div>
            <div class="col-md-2 mb-2">
                <a href="index.php" class="btn btn-secondary btn-block rounded-0">إعادة تعيين</a>
            </div>
        </form>
    </div>

    <div class="card-body">
        <div class="table-responsive">
            <table class="table-flat border">
                <thead>
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
                                    <a href="view.php?id=<?php echo $t['id']; ?>" class="text-decoration-none">
                                        <?php echo htmlspecialchars($t['ticket_number']); ?>
                                    </a>
                                </td>
                                <td><?php echo htmlspecialchars($t['cust_name'] ?: 'عميل نقدي'); ?></td>
                                <td><?php echo htmlspecialchars($t['device_name'] ?: ($t['device_brand'] . ' - ' . $t['device_type'])); ?></td>
                                <td class="dir-ltr text-right"><code><?php echo htmlspecialchars($t['imei'] ?: '-'); ?></code></td>
                                <td>
                                    <?php 
                                    switch ($t['status']) {
                                        case 'received': 
                                            echo '<span class="badge badge-secondary px-2 py-1 rounded-0">تم الاستلام</span>'; 
                                            break;
                                        case 'in_progress': 
                                            echo '<span class="badge badge-info px-2 py-1 rounded-0">قيد الصيانة</span>'; 
                                            break;
                                        case 'waiting_parts': 
                                            echo '<span class="badge badge-warning px-2 py-1 rounded-0">بانتظار قطع</span>'; 
                                            break;
                                        case 'completed': 
                                            echo '<span class="badge badge-success px-2 py-1 rounded-0">جاهز للتسليم</span>'; 
                                            break;
                                        case 'delivered': 
                                            echo '<span class="badge badge-dark px-2 py-1 rounded-0">تم التسليم والتحصيل</span>'; 
                                            break;
                                        case 'cancelled': 
                                            echo '<span class="badge badge-danger px-2 py-1 rounded-0">ملغاة</span>'; 
                                            break;
                                    }
                                    ?>
                                </td>
                                <td class="text-primary font-weight-bold"><?php echo number_format($t['estimated_cost'], 2); ?> ر.ي</td>
                                <td class="text-success font-weight-bold"><?php echo number_format($t['final_cost'], 2); ?> ر.ي</td>
                                <td><?php echo date('Y-m-d H:i', strtotime($t['received_date'])); ?></td>
                                <td class="no-print">
                                    <a href="view.php?id=<?php echo $t['id']; ?>" class="btn btn-outline-primary btn-sm rounded-0">عرض وتفاصيل</a>
                                    <?php if ($t['status'] !== 'delivered' && $t['status'] !== 'cancelled'): ?>
                                        <a href="view.php?id=<?php echo $t['id']; ?>#settlement" class="btn btn-success btn-sm rounded-0">تسليم وتصفية</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once($dir_prefix . 'includes/footer.php'); ?>
