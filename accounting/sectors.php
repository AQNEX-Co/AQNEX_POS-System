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
<title>عرض قطاعات الأعمال</title>
<style>
.page-inner { padding: 16px 20px; }

/* ===== ترويسة الصفحة الموحدة ===== */
.page-title-bar {
    display: flex; align-items: center; justify-content: space-between;
    padding: 10px 0 14px; border-bottom: 2px solid #1e40af; margin-bottom: 16px;
    flex-wrap: wrap; gap: 10px;
}
.page-title-bar .ptb-left { display: flex; align-items: center; gap: 10px; }
.page-title-bar .icon-wrap {
    width: 34px; height: 34px;
    background: #1e40af; color: #fff;
    display: flex; align-items: center; justify-content: center; font-size: 1rem;
}
.page-title-bar h4 { margin: 0; font-size: 0.95rem; font-weight: 700; color: var(--text-color); }
.page-title-bar small { font-size: 0.72rem; color: #64748b; display: block; }
.page-title-bar .ptb-actions { display: flex; gap: 8px; }
</style>

<div class="page-inner">

<div class="page-title-bar no-print">
    <div class="ptb-left">
        <div class="icon-wrap"><i class="bi bi-grid-3x3-gap"></i></div>
        <div>
            <h4>قطاعات الأعمال والأنشطة</h4>
            <small>عرض وتصنيف عمليات المبيعات، المشتريات، المنتجات، والقيود حسب قطاعات الشركة المختلفة.</small>
        </div>
    </div>
    <div class="ptb-actions">
        <a href="../home.php" class="btn btn-sm btn-light text-decoration-none" style="font-size: 0.8rem; border: 1px solid #cbd5e1;">
            <i class="bi bi-arrow-left"></i> عودة للرئيسية
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

</div> <!-- End .page-inner -->

<?php
require_once($dir_prefix . 'includes/footer.php');
?>
