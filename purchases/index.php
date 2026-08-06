<?php
$dir_prefix = '../';
$module = 'purchases';
require_once($dir_prefix . 'includes/header.php');

check_permission(['admin', 'inventory', 'cashier']);

// ─── معاملات البحث والفلترة ────────────────────────────
$search      = isset($_GET['search'])    ? trim($_GET['search'])    : '';
$from_date   = isset($_GET['from_date']) ? trim($_GET['from_date']) : '';
$to_date     = isset($_GET['to_date'])   ? trim($_GET['to_date'])   : '';
$supp_filter = isset($_GET['supplier'])  ? trim($_GET['supplier'])  : '';
$per_page    = 25;
$page        = max(1, intval($_GET['page'] ?? 1));
$offset      = ($page - 1) * $per_page;

// بناء شرط الاستعلام (مع إضافة شرط الحذف الناعم d_s = 0)
$where = "WHERE p.d_s = 0";
if (!empty($search))      $where .= " AND (p.supp_name LIKE '%" . $conn->real_escape_string($search) . "%' OR p.id LIKE '%" . $conn->real_escape_string($search) . "%' OR p.invoice_no LIKE '%" . $conn->real_escape_string($search) . "%' OR p.remark LIKE '%" . $conn->real_escape_string($search) . "%')";
if (!empty($from_date))   $where .= " AND p.invoice_date >= '" . $conn->real_escape_string($from_date) . "'";
if (!empty($to_date))     $where .= " AND p.invoice_date <= '" . $conn->real_escape_string($to_date) . "'";
if (!empty($supp_filter)) $where .= " AND p.supp_name = '" . $conn->real_escape_string($supp_filter) . "'";

// إجمالي السجلات للترقيم
$total_rows  = (int)($conn->query("SELECT COUNT(*) as c FROM purchase_invoices_mst p $where")->fetch_assoc()['c'] ?? 0);
$total_pages = max(1, ceil($total_rows / $per_page));

// السجلات الحالية مع حساب عدد الأصناف من جدول التفاصيل الجديد
$result = $conn->query("SELECT p.id, p.invoice_no, p.invoice_date, p.supp_name, p.total_amount, p.currency_code, p.remark,
    (SELECT COUNT(*) FROM purchase_invoices_dtl d WHERE d.invoice_id = p.id AND d.d_s = 0) as items_count 
    FROM purchase_invoices_mst p 
    $where 
    ORDER BY p.id DESC 
    LIMIT $per_page OFFSET $offset");

// الإحصائيات الإجمالية للمجموعة المفلترة (مع خصم المرتجعات النشطة)
$stats_sql = "SELECT 
    COALESCE(SUM(p.total_amount), 0) - COALESCE(SUM(r.refund_amount), 0) as total_purchases,
    COUNT(DISTINCT p.id) as count 
    FROM purchase_invoices_mst p
    LEFT JOIN (
        SELECT original_purchase_id, SUM(total_amount) as refund_amount
        FROM purchase_returns_mst
        WHERE d_s = 0
        GROUP BY original_purchase_id
    ) r ON r.original_purchase_id = p.id 
    $where";
$stats = $conn->query($stats_sql)->fetch_assoc();
$stat_purchases = (float)($stats['total_purchases'] ?? 0);
$stat_count     = (int)($stats['count'] ?? 0);

// الذمم للموردين (المبالغ الآجلة غير مدفوعة)
$supp_debt = (float)($conn->query("SELECT COALESCE(SUM(supp_daain),0) as v FROM suppliers WHERE d_s=0")->fetch_assoc()['v'] ?? 0);

$currency = $global_settings['currency'] ?? 'ر.ي';
$is_admin = (trim($_SESSION['SESS_LAST_NAME']) === 'admin' || empty($_SESSION['SESS_LAST_NAME']));

// رسائل التغذية الراجعة
$msg = $_GET['msg'] ?? '';
?>
<title>إدارة المشتريات - <?php echo htmlspecialchars($global_settings['store_name'] ?? 'AQNEX'); ?></title>

<!-- Loading Overlay -->
<div class="loading-overlay" id="loadingOverlay">
    <div class="loading-spinner"></div>
    <div class="loading-text">جاري التحميل...</div>
    <div class="loading-bar"><div class="loading-progress"></div></div>
</div>

<?php if ($msg === 'deleted'): ?>
<div class="alert alert-success rounded-0 mb-3 no-print"><i class="bi bi-check-circle-fill me-2"></i> تم حذف فاتورة المشتريات بنجاح واسترجاع الكميات للمخزن، وتعديل مديونية المورد ورصيد الصندوق.</div>
<?php elseif ($msg === 'error'): ?>
<div class="alert alert-danger rounded-0 mb-3 no-print"><i class="bi bi-exclamation-triangle-fill me-2"></i> حدث خطأ أثناء الحذف. حاول مرة أخرى.</div>
<?php elseif ($msg === 'notfound'): ?>
<div class="alert alert-danger rounded-0 mb-3 no-print"><i class="bi bi-exclamation-triangle-fill me-2"></i> الفاتورة المطلوبة غير موجودة.</div>
<?php elseif ($msg === 'invalid'): ?>
<div class="alert alert-danger rounded-0 mb-3 no-print"><i class="bi bi-exclamation-triangle-fill me-2"></i> رقم فاتورة غير صالح.</div>
<?php endif; ?>

<div class="card-flat">
    <!-- ═══ رأس الصفحة ═══ -->
    <div class="card-header no-print d-flex flex-wrap gap-2 align-items-center justify-content-between">
        <h5 class="mb-0"><?php echo get_icon('purchases', 'ml-2 text-primary'); ?> إدارة فواتير المشتريات</h5>
        <div class="d-flex flex-wrap gap-2">
            <a href="create.php" class="btn-flat btn-flat-primary btn-sm text-decoration-none">
                <?php echo get_icon('plus', 'ml-2'); ?> فاتورة جديدة
            </a>
            <a href="returns.php" class="btn-flat btn-sm text-decoration-none" style="background:#8e44ad;color:#fff;">
                <i class="bi bi-arrow-counterclockwise ml-2"></i> مردودات
            </a>
            <a href="import.php" class="btn-flat btn-sm text-decoration-none" style="background:var(--accent-info);color:#fff;" title="استيراد من إكسل">
                <i class="bi bi-file-earmark-arrow-up ml-2"></i> استيراد
            </a>
            <a href="../includes/export.php?type=purchases" class="btn-flat btn-sm text-decoration-none no-print" style="background:var(--accent-success);color:#fff;">
                <i class="bi bi-file-earmark-excel ml-2"></i> تصدير إكسل
            </a>
            <button onclick="window.print()" class="btn-flat btn-flat-secondary btn-sm no-print">
                <?php echo get_icon('print', 'ml-2'); ?> طباعة
            </button>
            <a href="../home.php" class="btn-flat btn-flat-secondary btn-sm text-decoration-none no-print">
                <?php echo get_icon('logout', 'ml-2'); ?> رئيسية
            </a>
        </div>
    </div>

    <div class="card-body">
        <!-- ═══ بطاقات الإحصائيات ═══ -->
        <div class="row g-3 mb-4 no-print">
            <div class="col-md-4 col-6">
                <div style="background:linear-gradient(135deg,#4facfe,#00f2fe);border-radius:12px;padding:16px 20px;color:#fff;">
                    <div style="font-size:.8rem;opacity:.9;">صافي المشتريات</div>
                    <div style="font-size:1.4rem;font-weight:700;"><?php echo number_format($stat_purchases, 0); ?></div>
                    <div style="font-size:.75rem;opacity:.8;"><?php echo $currency; ?> &bull; <?php echo $stat_count; ?> فاتورة</div>
                </div>
            </div>
            <div class="col-md-4 col-6">
                <div style="background:linear-gradient(135deg,#f093fb,#f5576c);border-radius:12px;padding:16px 20px;color:#fff;">
                    <div style="font-size:.8rem;opacity:.9;">ذمم الموردين (الآجل)</div>
                    <div style="font-size:1.4rem;font-weight:700;"><?php echo number_format($supp_debt, 0); ?></div>
                    <div style="font-size:.75rem;opacity:.8;"><?php echo $currency; ?> غير مسدّد</div>
                </div>
            </div>
            <div class="col-md-4 col-6">
                <div style="background:linear-gradient(135deg,#43e97b,#38f9d7);border-radius:12px;padding:16px 20px;color:#fff;">
                    <div style="font-size:.8rem;opacity:.9;">المسدّد للموردين</div>
                    <div style="font-size:1.4rem;font-weight:700;"><?php echo number_format(max(0, $stat_purchases - $supp_debt), 0); ?></div>
                    <div style="font-size:.75rem;opacity:.8;"><?php echo $currency; ?></div>
                </div>
            </div>
        </div>

        <!-- ═══ نموذج البحث ═══ -->
        <form method="GET" class="no-print mb-3">
            <div class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small text-muted mb-1">بحث (رقم الفاتورة / المورد / ملاحظات)</label>
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
                    <label class="form-label small text-muted mb-1">المورد</label>
                    <input type="text" name="supplier" class="form-control form-control-sm" placeholder="اسم المورد..." value="<?php echo htmlspecialchars($supp_filter); ?>">
                </div>
                <div class="col-md-3 d-flex gap-3">
                    <button type="submit" class="btn-flat btn-flat-primary btn-sm flex-grow-1">
                        <i class="bi bi-search ml-2"></i> بحث
                    </button>
                    <a href="index.php" class="btn-flat btn-flat-secondary btn-sm flex-grow-1 text-decoration-none text-center">
                        <i class="bi bi-x-circle ml-2"></i> مسح
                    </a>
                    <a href="index.php?from_date=<?php echo date('Y-m-d'); ?>&to_date=<?php echo date('Y-m-d'); ?>" class="btn-flat btn-flat-primary btn-sm text-decoration-none text-center" style="background:#e2e8f0;color:#475569;white-space:nowrap;">
                      <i class="bi bi-calendar ml-2"></i> اليوم
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
                        <th>المورد</th>
                        <th style="width:110px;">التاريخ</th>
                        <th style="width:80px;">الأصناف</th>
                        <th style="width:140px;">إجمالي الفاتورة</th>
                        <th style="width:80px;">العملة</th>
                        <th>ملاحظات</th>
                        <th class="no-print text-center" style="width:150px;">الإجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    if ($result && $result->num_rows > 0) {
                        while ($row = $result->fetch_assoc()) {
                            $cc = $row['currency_code'] ?? 'YER';
                            ?>
                            <tr class="fw-bold" style="font-size:.85rem;">
                                <td>
                                    <strong class="text-primary">#<?php echo $row['id']; ?></strong><br>
                                    <small class="text-muted"><?php echo htmlspecialchars($row['invoice_no'] ?? ''); ?></small>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars($row['supp_name'] ?: 'مورد عام'); ?>
                                </td>
                                <td class="text-muted"><?php echo htmlspecialchars($row['invoice_date']); ?></td>
                                <td class="text-center">
                                    <span style="background:#e0e7ff;color:#3730a3;padding:2px 10px;border-radius:20px;font-size:.8rem;font-weight:600;">
                                        <?php echo (int)$row['items_count']; ?> صنف
                                    </span>
                                </td>
                                <td class="fw-bold"><?php echo number_format($row['total_amount'], 0); ?> <small class="text-muted"><?php echo $currency; ?></small></td>
                                <td>
                                    <span class="badge badge-secondary px-2 py-1" style="font-size:.75rem;font-weight:600;">
                                        <?php echo htmlspecialchars($cc); ?>
                                    </span>
                                </td>
                                <td class="text-muted" style="font-size:.85rem;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                                    <?php echo htmlspecialchars($row['remark'] ?? ''); ?>
                                </td>
                                <td class="no-print text-center">
                                    <a href="view.php?id=<?php echo $row['id']; ?>" class="btn-flat btn-flat-primary btn-sm py-1 px-2 text-decoration-none" title="عرض الفاتورة">
                                        <?php echo get_icon('eye', 'ml-1'); ?> عرض
                                    </a>
                                    <?php if ($is_admin): ?>
                                    <a href="create.php?id=<?php echo $row['id']; ?>" class="btn-flat btn-flat-secondary btn-sm py-1 px-2 text-decoration-none" title="تعديل">
                                        <?php echo get_icon('edit', 'ml-1'); ?> تعديل
                                    </a>
                                    <a href="delete.php?id=<?php echo $row['id']; ?>" class="btn-flat btn-flat-danger btn-sm py-1 px-2 text-decoration-none" title="حذف"
                                       onclick="return confirm('تأكيد حذف فاتورة #<?php echo $row['id']; ?>\nسيتم استرجاع الكميات للمخزن، وتعديل مديونية المورد ورصيد الصندوق.\nهذا الإجراء لا يمكن التراجع عنه!')">
                                        <i class="fa fa-trash ml-1"></i> مسح
                                    </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php
                        }
                    } else {
                        echo "<tr><td colspan='8' class='text-center text-muted p-5'>
                            <i class='bi bi-inbox' style='font-size:48px;opacity:.3;display:block;margin-bottom:12px;'></i>
                            لا توجد فواتير مشتريات مطابقة للبحث
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