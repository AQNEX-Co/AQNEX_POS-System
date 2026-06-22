<?php
$dir_prefix = '../';
$module = 'purchases';
require_once($dir_prefix . 'includes/header.php');

check_permission(['admin', 'inventory']);

// جلب الفواتير الإجمالية بدلاً من البنود المفردة - لعرض قائمة فواتير منظمة
$sql = "SELECT p.*, 
        (SELECT COUNT(*) FROM purchase_items pi WHERE pi.buys_date = p.date AND pi.supp_name = p.supp_name) as items_count
        FROM purchases p ORDER BY p.id DESC";
$result = $conn->query($sql);

// إذا لم يكن جدول purchases يعمل أو فارغاً نعرض purchase_items
if (!$result || $result->num_rows == 0) {
    // Fallback: عرض بنود الشراء المباشرة
    $sql = "SELECT * FROM purchase_items WHERE s = '0' ORDER BY buyid DESC";
    $result = $conn->query($sql);
    $fallback_mode = true;
} else {
    $fallback_mode = false;
}
?>
<title>إدارة فواتير المشتريات - تكنولوجيا فون</title>

<?php
// رسائل التغذية الراجعة
$msg = isset($_GET['msg']) ? $_GET['msg'] : '';
?>
<?php if ($msg === 'deleted'): ?>
<div class="alert alert-success rounded-0 mb-3 no-print"><?php echo get_icon('check','ml-1'); ?> تم حذف فاتورة المشتريات بنجاح واسترجاع الكميات للمخزن.</div>
<?php elseif ($msg === 'error'): ?>
<div class="alert alert-danger rounded-0 mb-3 no-print"><?php echo get_icon('info-circle','ml-1'); ?> حدث خطأ أثناء الحذف. حاول مرة أخرى.</div>
<?php endif; ?>
<div class="card-flat">
<div class="card-header no-print">
    <h5><?php echo get_icon('purchases', 'ml-2 text-primary'); ?> إدارة فواتير المشتريات</h5>
    <div>
            <a href="create.php" class="btn-flat btn-flat-primary btn-sm ml-2 text-decoration-none">
                <?php echo get_icon('plus', 'ml-1'); ?> إضافة فاتورة جديدة
            </a>
        <a href="import.php" class="btn-flat btn-flat-primary btn-sm ml-2 text-decoration-none" style="background-color: var(--accent-info); color: #fff;" title="استيراد من إكسل">
            <i class="bi bi-file-earmark-arrow-up ml-1"></i> استيراد من إكسل
        </a>
        <a href="../includes/export.php?type=purchases" class="btn-flat btn-flat-success btn-sm ml-2 text-decoration-none" style="background-color: var(--accent-success); color: #fff;" title="تصدير إلى إكسل">
            <i class="bi bi-file-earmark-excel ml-1"></i> تصدير إكسل
        </a>
        <button onclick="window.print()" class="btn-flat btn-flat-success btn-sm ml-2" title="طباعة">
            <?php echo get_icon('print', 'ml-1'); ?> طباعة القائمة
        </button>
        <a href="../home.php" class="btn-flat btn-flat-secondary btn-sm text-decoration-none" title="العودة للرئيسية">
            <?php echo get_icon('logout', 'ml-1'); ?> عودة
        </a>
    </div>
</div>
    <div class="card-body">


        <div class="table-responsive">
            <table class="table-flat">
                <thead>
                    <tr>
                        <?php if (!$fallback_mode): ?>
                        <th style="width: 8%;">رقم الفاتورة</th>
                        <th>اسم المورد</th>
                        <th style="width: 12%;">عدد الأصناف</th>
                        <th style="width: 15%;">إجمالي الفاتورة</th>
                        <th style="width: 12%;">العملة</th>
                        <th style="width: 14%;">تاريخ الشراء</th>
                        <th class="no-print" style="width: 16%;">الإجراءات</th>
                        <?php else: ?>
                        <th>رقم المعاملة</th>
                        <th>اسم المورد</th>
                        <th>اسم المنتج</th>
                        <th>الكمية</th>
                        <th>سعر الشراء الكلي</th>
                        <th>تاريخ الشراء</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    if ($result && $result->num_rows > 0) {
                        while($row = $result->fetch_assoc()) {
                            if (!$fallback_mode): ?>
                            <tr>
                                <td class="font-weight-bold text-secondary">#<?php echo $row['id']; ?></td>
                                <td class="font-weight-bold"><?php echo $row['supp_name'] ? htmlspecialchars($row['supp_name']) : 'مورد عام'; ?></td>
                                <td class="text-center"><?php echo intval($row['items_count']); ?> صنف</td>
                                <td class="font-weight-bold text-dark"><?php echo number_format($row['total'], 2); ?></td>
                                <td>
                                    <?php $cc = isset($row['currency_code']) ? $row['currency_code'] : 'YER'; ?>
                                    <span class="badge badge-secondary px-2 py-1 font-weight-normal"><?php echo htmlspecialchars($cc); ?></span>
                                </td>
                                <td><?php echo htmlspecialchars($row['date']); ?></td>
                                <td class="no-print">
                                    <a href="view.php?id=<?php echo $row['id']; ?>" class="btn-flat btn-flat-primary btn-sm py-1 px-2 ml-1 text-decoration-none">
                                        <?php echo get_icon('eye', 'ml-1'); ?> تفاصيل
                                    </a>
                                    <?php if ($is_admin): ?>
                                    <a href="delete.php?id=<?php echo $row['id']; ?>" class="btn-flat btn-flat-danger btn-sm py-1 px-2 text-decoration-none" onclick="return confirm('تأكيد حذف فاتورة #<?php echo $row['id']; ?>\nسيتم استرجاع الكميات للمخزن.\nهذا الإجراء لا يمكن التراجع عنه!')">
                                        <?php echo get_icon('trash', 'ml-1'); ?> حذف
                                    </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php else: ?>
                            <tr>
                                <td>#<?php echo $row['buyid']; ?></td>
                                <td class="font-weight-bold text-secondary"><?php echo $row['supp_name'] ? htmlspecialchars($row['supp_name']) : 'مورد عام'; ?></td>
                                <td class="text-right pr-4 font-weight-bold text-secondary"><?php echo htmlspecialchars($row['name']); ?></td>
                                <td><?php echo htmlspecialchars($row['quantity']); ?></td>
                                <td class="font-weight-bold text-dark"><?php echo number_format($row['buy_price'], 2); ?></td>
                                <td><?php echo htmlspecialchars($row['buys_date']); ?></td>
                            </tr>
                            <?php endif;
                        }
                    } else {
                        $colspan = $fallback_mode ? 6 : 7;
                        echo "<tr><td colspan='$colspan' class='text-center text-muted p-4'>لا توجد فواتير مشتريات مسجلة</td></tr>";
                    }
                    ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
require_once($dir_prefix . 'includes/footer.php');
?>
