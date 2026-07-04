<?php
$dir_prefix = '../';
$module = 'sales';
require_once($dir_prefix . 'includes/header.php');

check_permission(['admin', 'cashier']);

// ─── معاملات البحث والفلترة ────────────────────────────
$search      = isset($_GET['search'])    ? trim($_GET['search'])    : '';
$from_date   = isset($_GET['from_date']) ? trim($_GET['from_date']) : '';
$to_date     = isset($_GET['to_date'])   ? trim($_GET['to_date'])   : '';
$cust_filter = isset($_GET['customer'])  ? trim($_GET['customer'])  : '';
$per_page    = 25;
$page        = max(1, intval($_GET['page'] ?? 1));
$offset      = ($page - 1) * $per_page;

// بناء شرط الاستعلام
$where = "WHERE delete_status = 0";
if (!empty($search))      $where .= " AND (cust_name LIKE '%" . $conn->real_escape_string($search) . "%' OR id LIKE '%" . $conn->real_escape_string($search) . "%' OR remark LIKE '%" . $conn->real_escape_string($search) . "%')";
if (!empty($from_date))   $where .= " AND build_date >= '" . $conn->real_escape_string($from_date) . "'";
if (!empty($to_date))     $where .= " AND build_date <= '" . $conn->real_escape_string($to_date) . "'";
if (!empty($cust_filter)) $where .= " AND cust_name = '" . $conn->real_escape_string($cust_filter) . "'";

$returns_subquery = "(SELECT sales_id, SUM(refund_amount) as refund_amount, SUM(profit_impact) as profit_impact FROM sales_returns WHERE status='active' GROUP BY sales_id)";

// إجمالي السجلات للترقيم (نستبعد الفواتير التي تم إرجاعها بالكامل)
$total_rows_query = "SELECT COUNT(*) as c FROM sales s LEFT JOIN $returns_subquery r ON r.sales_id = s.id $where AND GREATEST(0, s.total - COALESCE(r.refund_amount, 0)) > 0";
$total_rows   = (int)($conn->query($total_rows_query)->fetch_assoc()['c'] ?? 0);
$total_pages  = max(1, ceil($total_rows / $per_page));

// السجلات الحالية
$result = $conn->query("SELECT s.*, COALESCE(r.refund_amount, 0) as refund_amount, COALESCE(r.profit_impact, 0) as profit_impact, GREATEST(0, s.total - COALESCE(r.refund_amount, 0)) as net_total, GREATEST(0, s.prifet - COALESCE(r.profit_impact, 0)) as net_profit FROM sales s LEFT JOIN $returns_subquery r ON r.sales_id = s.id $where AND GREATEST(0, s.total - COALESCE(r.refund_amount, 0)) > 0 ORDER BY s.id DESC LIMIT $per_page OFFSET $offset");

// الإحصائيات الإجمالية للمجموعة المفلترة
$stats = $conn->query("SELECT COALESCE(SUM(CASE WHEN s.total > COALESCE(r.refund_amount, 0) THEN s.total - COALESCE(r.refund_amount, 0) ELSE 0 END),0) as total_sales, COALESCE(SUM(CASE WHEN s.prifet > COALESCE(r.profit_impact, 0) THEN s.prifet - COALESCE(r.profit_impact, 0) ELSE 0 END),0) as total_profit, COALESCE(SUM(s.remaining_total),0) as total_remaining, COUNT(CASE WHEN s.total > COALESCE(r.refund_amount, 0) THEN 1 END) as count FROM sales s LEFT JOIN $returns_subquery r ON r.sales_id = s.id $where AND GREATEST(0, s.total - COALESCE(r.refund_amount, 0)) > 0")->fetch_assoc();
$stat_sales     = (float)$stats['total_sales'];
$stat_profit    = (float)$stats['total_profit'];
$stat_remaining = (float)$stats['total_remaining'];
$stat_count     = (int)$stats['count'];

$currency = $global_settings['currency'] ?? 'ر.ي';
?>
<title>إدارة المبيعات - <?php echo htmlspecialchars($global_settings['store_name'] ?? 'AQNEX'); ?></title>

<!-- Loading Overlay -->
<div class="loading-overlay" id="loadingOverlay">
    <div class="loading-spinner"></div>
    <div class="loading-text">جاري التحميل...</div>
    <div class="loading-bar"><div class="loading-progress"></div></div>
</div>

<div class="card-flat">
    <!-- ═══ رأس الصفحة ═══ -->
    <div class="card-header no-print d-flex flex-wrap gap-2 align-items-center justify-content-between">
        <h5 class="mb-0"><?php echo get_icon('sales', 'ml-2 text-primary'); ?> إدارة فواتير المبيعات</h5>
        <div class="d-flex flex-wrap gap-2">
            <a href="create.php" class="btn-flat btn-flat-primary btn-sm text-decoration-none">
                <?php echo get_icon('plus', 'ml-1'); ?> فاتورة جديدة
            </a>
            <a href="returns.php" class="btn-flat btn-flat-secondary btn-sm text-decoration-none" style="background:#8e44ad;color:#fff;">
                <i class="bi bi-arrow-return-left ml-1"></i> مردودات
            </a>
            <a href="../includes/export.php?type=sales" class="btn-flat btn-flat-success btn-sm text-decoration-none no-print" style="background:var(--accent-success);color:#fff;">
                <i class="bi bi-file-earmark-excel ml-1"></i> تصدير إكسل
            </a>
            <button onclick="window.print()" class="btn-flat btn-flat-secondary btn-sm no-print">
                <?php echo get_icon('print', 'ml-1'); ?> طباعة
            </button>
            <a href="../home.php" class="btn-flat btn-flat-secondary btn-sm text-decoration-none no-print">
                <?php echo get_icon('logout', 'ml-1'); ?> رئيسية
            </a>
        </div>
    </div>

    <div class="card-body">
        <!-- ═══ بطاقات الإحصائيات ═══ -->
        <div class="row g-3 mb-4 no-print">
            <div class="col-md-3 col-6">
                <div style="background:linear-gradient(135deg,#11998e,#38ef7d);border-radius:12px;padding:16px 20px;color:#fff;">
                    <div style="font-size:.8rem;opacity:.9;">إجمالي المبيعات</div>
                    <div style="font-size:1.4rem;font-weight:700;"><?php echo number_format($stat_sales, 0); ?></div>
                    <div style="font-size:.75rem;opacity:.8;"><?php echo $currency; ?> &bull; <?php echo $stat_count; ?> فاتورة</div>
                </div>
            </div>
            <?php if ($is_admin): ?>
            <div class="col-md-3 col-6">
                <div style="background:linear-gradient(135deg,#4facfe,#00f2fe);border-radius:12px;padding:16px 20px;color:#fff;">
                    <div style="font-size:.8rem;opacity:.9;">إجمالي الأرباح</div>
                    <div style="font-size:1.4rem;font-weight:700;"><?php echo number_format($stat_profit, 0); ?></div>
                    <div style="font-size:.75rem;opacity:.8;"><?php echo $currency; ?> &bull; <?php echo $stat_sales > 0 ? number_format($stat_profit / $stat_sales * 100, 1) : 0; ?>% نسبة ربح</div>
                </div>
            </div>
            <?php endif; ?>
            <div class="col-md-3 col-6">
                <div style="background:linear-gradient(135deg,#f093fb,#f5576c);border-radius:12px;padding:16px 20px;color:#fff;">
                    <div style="font-size:.8rem;opacity:.9;">ذمم مدينة (آجل)</div>
                    <div style="font-size:1.4rem;font-weight:700;"><?php echo number_format($stat_remaining, 0); ?></div>
                    <div style="font-size:.75rem;opacity:.8;"><?php echo $currency; ?> غير محصّل</div>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div style="background:linear-gradient(135deg,#667eea,#764ba2);border-radius:12px;padding:16px 20px;color:#fff;">
                    <div style="font-size:.8rem;opacity:.9;">المحصّل نقداً</div>
                    <div style="font-size:1.4rem;font-weight:700;"><?php echo number_format($stat_sales - $stat_remaining, 0); ?></div>
                    <div style="font-size:.75rem;opacity:.8;"><?php echo $currency; ?></div>
                </div>
            </div>
        </div>

        <!-- ═══ نموذج البحث ═══ -->
        <form method="GET" class="no-print mb-3">
            <div class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small text-muted mb-1">بحث (رقم فاتورة / عميل / ملاحظات)</label>
                    <input type="text" name="search" class="form-control form-control-sm" placeholder="ابحث هنا..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small text-muted mb-1">من تاريخ</label>
                    <input type="date" name="from_date" class="form-control form-control-sm" value="<?php echo htmlspecialchars($from_date); ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small text-muted mb-1">إلى تاريخ</label>
                    <input type="date" name="to_date" class="form-control form-control-sm" value="<?php echo htmlspecialchars($to_date); ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small text-muted mb-1">العميل</label>
                    <input type="text" name="customer" class="form-control form-control-sm" placeholder="اسم العميل..." value="<?php echo htmlspecialchars($cust_filter); ?>">
                </div>
                <div class="col-md-3 d-flex gap-2">
                    <button type="submit" class="btn-flat btn-flat-primary btn-sm flex-grow-1">
                        <i class="bi bi-search me-1"></i> بحث
                    </button>
                    <a href="index.php" class="btn-flat btn-flat-secondary btn-sm flex-grow-1 text-decoration-none text-center">
                        <i class="bi bi-x-circle me-1"></i> مسح
                    </a>
                    <!-- زر اختصار: اليوم -->
                    <a href="index.php?from_date=<?php echo date('Y-m-d'); ?>&to_date=<?php echo date('Y-m-d'); ?>" class="btn-flat btn-sm text-decoration-none text-center" style="background:#e2e8f0;color:#475569;white-space:nowrap;">
                        اليوم
                    </a>
                </div>
            </div>
        </form>

        <!-- ═══ جدول الفواتير ═══ -->
        <div class="table-responsive">
            <table class="table-flat w-100">
                <thead>
                    <tr>
                        <th style="width:70px;">#</th>
                        <th>العميل</th>
                        <th style="width:110px;">التاريخ</th>
                        <th style="width:130px;">إجمالي الفاتورة</th>
                        <?php if ($is_admin): ?>
                        <th style="width:110px;">الربح</th>
                        <?php endif; ?>
                        <th style="width:110px;">الآجل</th>
                        <th>ملاحظات</th>
                        <th class="no-print text-center" style="width:150px;">الإجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    if ($result && $result->num_rows > 0) {
                        while ($row = $result->fetch_assoc()) {
                            $has_remaining = (float)$row['remaining_total'] > 0;
                            ?>
                            <tr <?php echo $has_remaining ? 'style="border-right:3px solid #f59e0b;"' : ''; ?>>
                                <td><strong class="text-primary">#<?php echo $row['id']; ?></strong></td>
                                <td class="fw-bold">
                                    <i class="bi bi-person-fill me-1 text-muted"></i>
                                    <?php echo $row['cust_name'] ? htmlspecialchars($row['cust_name']) : '<span class="text-muted">عميل نقدي</span>'; ?>
                                </td>
                                <td class="text-muted"><?php echo htmlspecialchars($row['build_date']); ?></td>
                                <td class="fw-bold"><?php echo number_format((float)($row['net_total'] ?? 0), 0); ?> <small class="text-muted"><?php echo $currency; ?></small></td>
                                <?php if ($is_admin): ?>
                                <td class="text-success fw-bold"><?php echo number_format((float)($row['net_profit'] ?? 0), 0); ?></td>
                                <?php endif; ?>
                                <td>
                                    <?php if ($has_remaining): ?>
                                    <span style="background:#fef3c7;color:#92400e;padding:2px 8px;border-radius:20px;font-size:.8rem;font-weight:600;">
                                        <?php echo number_format($row['remaining_total'], 0); ?>
                                    </span>
                                    <?php else: ?>
                                    <span style="background:#dcfce7;color:#166534;padding:2px 8px;border-radius:20px;font-size:.8rem;">مسدّد</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-muted" style="font-size:.85rem; max-width:180px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                                    <?php echo htmlspecialchars($row['remark']); ?>
                                </td>
                                <td class="no-print text-center">
                                    <a href="view.php?id=<?php echo $row['id']; ?>" class="btn-flat btn-flat-primary btn-sm py-1 px-2 text-decoration-none" title="عرض الفاتورة">
                                        <?php echo get_icon('eye', 'ml-1'); ?> عرض
                                    </a>
                                    <a href="edit.php?id=<?php echo $row['id']; ?>" class="btn-flat btn-flat-primary btn-sm py-1 px-2 ml-1 text-decoration-none">
                                        <?php echo get_icon('edit', 'ml-1'); ?> تعديل
                                    </a>
                                    <?php if ($is_admin): ?>
                                    <a href="delete.php?id=<?php echo $row['id']; ?>" onclick="return confirm('هل أنت متأكد من حذف الفاتورة #<?php echo $row['id']; ?>؟')" class="btn-flat btn-flat-danger btn-sm py-1 px-2 text-decoration-none" title="حذف">
                                                                                <i class="fa fa-trash ml-1"></i>مسح
                                    </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php
                        }
                    } else {
                        $colspan = $is_admin ? 8 : 7;
                        echo "<tr><td colspan='$colspan' class='text-center text-muted p-5'>
                            <i class='bi bi-inbox' style='font-size:48px;opacity:.3;display:block;margin-bottom:12px;'></i>
                            لا توجد فواتير مبيعات مطابقة للبحث
                        </td></tr>";
                    }
                    ?>
                </tbody>
            </table>
        </div>

        <!-- ═══ الترقيم ═══ -->
        <?php if ($total_pages > 1): ?>
        <div class="d-flex justify-content-between align-items-center mt-3 no-print">
            <div class="text-muted small">
                عرض <?php echo min($offset + 1, $total_rows); ?> - <?php echo min($offset + $per_page, $total_rows); ?> من <?php echo number_format($total_rows); ?> فاتورة
            </div>
            <nav>
                <ul class="pagination pagination-sm mb-0" style="gap:4px;">
                    <?php if ($page > 1): ?>
                    <li class="page-item">
                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">
                            <i class="bi bi-chevron-right"></i>
                        </a>
                    </li>
                    <?php endif; ?>
                    <?php
                    $start_p = max(1, $page - 2);
                    $end_p   = min($total_pages, $page + 2);
                    for ($p = $start_p; $p <= $end_p; $p++):
                    ?>
                    <li class="page-item <?php echo $p == $page ? 'active' : ''; ?>">
                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $p])); ?>"><?php echo $p; ?></a>
                    </li>
                    <?php endfor; ?>
                    <?php if ($page < $total_pages): ?>
                    <li class="page-item">
                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">
                            <i class="bi bi-chevron-left"></i>
                        </a>
                    </li>
                    <?php endif; ?>
                </ul>
            </nav>
        </div>
        <?php endif; ?>

    </div>
</div>

<?php require_once($dir_prefix . 'includes/footer.php'); ?>
