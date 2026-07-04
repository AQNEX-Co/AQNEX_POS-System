<?php
$dir_prefix = '../';
$module = 'sectors';
require_once($dir_prefix . 'includes/header.php');

// حماية الصفحة للمدير فقط
check_permission(['admin']);

// جلب قائمة القطاعات الحالية
$sectors_list = [];
$res = $conn->query("SELECT * FROM sectors ORDER BY id ASC");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $sectors_list[] = $row;
    }
}
?>
<title>عرض قطاعات الأعمال - تكنولوجيا فون</title>

<div class="row mb-4 no-print">
    <div class="col-md-6">
        <h3 class="text-secondary font-weight-bold">
            <i class="bi bi-grid-3x3-gap ml-2 text-primary"></i> قطاعات الأعمال والأنشطة
        </h3>
        <p class="text-muted small mb-0">عرض وتصنيف عمليات المبيعات، المشتريات، المنتجات، والقيود حسب قطاعات الشركة المختلفة.</p>
    </div>
    <div class="col-md-6 text-left">
        <a href="../home.php" class="btn-flat btn-flat-secondary btn-sm text-decoration-none">
            <?php echo get_icon('logout', 'ml-1'); ?> عودة للرئيسية
        </a>
    </div>
</div>

<div class="card-flat">
    <div class="card-header">
        <h5>قطاعات الأعمال المتاحة في النظام (ثابتة للنظام الهيكلي للشركة)</h5>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table-flat">
                <thead>
                    <tr>
                        <th style="width: 15%;">رقم القطاع</th>
                        <th style="width: 35%;">اسم القطاع</th>
                        <th>الوصف والبيان والنشاط التفصيلي</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($sectors_list)): ?>
                        <tr><td colspan="3" class="text-center text-muted p-4">لا توجد قطاعات أعمال مهيأة بعد</td></tr>
                    <?php else: ?>
                        <?php foreach ($sectors_list as $sector): ?>
                            <tr>
                                <td class="font-weight-bold text-muted">#<?php echo $sector['id']; ?></td>
                                <td class="font-weight-bold text-right pr-3"><?php echo htmlspecialchars($sector['name']); ?></td>
                                <td class="text-muted text-right pr-3"><?php echo htmlspecialchars($sector['description'] ?: '-'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
require_once($dir_prefix . 'includes/footer.php');
?>
