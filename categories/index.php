<?php
$dir_prefix = '../';
$module = 'categories';
require_once($dir_prefix . 'includes/header.php');

check_permission(['admin', 'inventory']);

$msg = isset($_GET['msg']) ? $_GET['msg'] : '';

// معالجة الإضافة والتعديل والحذف لمجموعات وأقسام الاصناف
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'save') {
        $catid = intval($_POST['catid'] ?? 0);
        $name = $conn->real_escape_string(trim($_POST['name'] ?? ''));

        if (!empty($name)) {
            if ($catid > 0) {
                $conn->query("UPDATE categories SET name = '$name' WHERE catid = $catid");
                $msg = 'updated';
            } else {
                $conn->query("INSERT INTO categories (name) VALUES ('$name')");
                $msg = 'added';
            }
        }
        echo "<script>window.location='index.php?msg=$msg';</script>";
        exit;
    }
}

// استعلام الفئات مع عدد الأصناف في كل مجموعة
$sql = "SELECT c.catid, c.name, COUNT(p.id) AS products_count 
        FROM categories c 
        LEFT JOIN products p ON c.catid = p.catid AND p.delete_status = '0' 
        GROUP BY c.catid, c.name 
        ORDER BY c.catid DESC";
$result = $conn->query($sql);
?>
<title>ادارة مجموعات الاصناف  - AQNEX POS</title>

<!-- Toptitle Toolbar -->
<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 no-print">
    <div>
        <h4 class="font-weight-bold text-dark mb-1">
            <i class="bi bi-tags text-primary ml-2"></i>ادارة مجموعات الاصناف 
        </h4>
        <small class="text-muted">تنظيم كتالوج الاصناف وتوزيع الأصناف حسب الأقسام والمجموعات</small>
    </div>
    <div class="d-flex gap-2">
        <button type="button" class="btn btn-primary btn-sm px-3 font-weight-bold rounded-0" onclick="openCategoryModal(0, '')">
            <i class="bi bi-plus-circle ml-1"></i> إضافة مجموعة جديدة
        </button>
        <a href="../products/index.php" class="btn btn-outline-info btn-sm px-3 font-weight-bold rounded-0">
            <i class="bi bi-boxes ml-1"></i> جرد الاصناف
        </a>
        <a href="../home.php" class="btn btn-secondary btn-sm px-3 font-weight-bold rounded-0">
            <i class="bi bi-arrow-right ml-1"></i> عودة
        </a>
    </div>
</div>

<?php if ($msg === 'added'): ?>
    <div class="alert alert-success alert-dismissible fade show rounded-0 py-2 mb-3" role="alert">
        <strong>✓ تم إضافة المجموعة بنجاح!</strong>
        <button type="button" class="close py-2" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button>
    </div>
<?php elseif ($msg === 'updated'): ?>
    <div class="alert alert-info alert-dismissible fade show rounded-0 py-2 mb-3" role="alert">
        <strong>✓ تم تحديث اسم المجموعة بنجاح!</strong>
        <button type="button" class="close py-2" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button>
    </div>
<?php endif; ?>

<!-- Search Card -->
<div class="card border-0 shadow-sm rounded-0 mb-3 no-print">
    <div class="card-body p-2">
        <div class="row align-items-center">
            <div class="col-md-6">
                <input type="text" id="searchInput" class="form-control rounded-0" placeholder="ابحث باسم المجموعة أو المجموعة..." oninput="filterCategoriesTable()">
            </div>
        </div>
    </div>
</div>

<!-- Table Card -->
<div class="card border-0 shadow-sm rounded-0">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-bordered table-hover text-center mb-0" id="categoriesTable">
                <thead class="bg-light">
                    <tr>
                        <th style="width: 15%;"># رقم المجموعة</th>
                        <th>اسم المجموعة / المجموعة الرئيسي</th>
                        <th style="width: 20%;">عدد الاصناف المسجلة</th>
                        <th class="no-print" style="width: 25%;">الإجراءات والعمليات</th>
                    </tr>
                </thead>
                <tbody id="categoriesTableBody">
                    <?php
                    if ($result && $result->num_rows > 0) {
                        while ($row = $result->fetch_assoc()) {
                            $cnt = intval($row['products_count']);
                            ?>
                            <tr class="category-row">
                                <td class="font-weight-bold">#<?php echo $row['catid']; ?></td>
                                <td class="font-weight-bold text-dark text-right pr-4"><?php echo htmlspecialchars($row['name']); ?></td>
                                <td><span class="badge badge-info px-3 py-1"><?php echo number_format($cnt); ?> صنف</span></td>
                                <td class="no-print">
                                    <button type="button" class="btn btn-xs btn-primary px-2" onclick="openCategoryModal(<?php echo $row['catid']; ?>, '<?php echo htmlspecialchars(addslashes($row['name'])); ?>')">
                                        <i class="bi bi-pencil-square ml-1"></i> تعديل الاسم
                                    </button>
                                    <a href="../products/category.php?id=<?php echo $row['catid']; ?>" class="btn btn-xs btn-outline-success px-2 ml-1">
                                        <i class="bi bi-grid ml-1"></i> عرض الاصناف
                                    </a>
                                    <a href="delete.php?id=<?php echo $row['catid']; ?>" onclick="return confirm('تأكيد الحذف: هل أنت متأكد من حذف هذا المجموعة؟')" class="btn btn-xs btn-outline-danger px-2 ml-1">
                                        <i class="bi bi-trash"></i> حذف
                                    </a>
                                </td>
                            </tr>
                            <?php
                        }
                    } else {
                        echo '<tr><td colspan="4" class="text-center text-muted p-4">لا توجد أصناف أو أقسام مسجلة حالياً.</td></tr>';
                    }
                    ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal: إضافة / تعديل مجموعة -->
<div class="modal fade" id="categoryModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content rounded-0">
            <form method="POST">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="catid" id="modal_catid" value="0">
                
                <div class="modal-header bg-primary text-white py-2">
                    <h6 class="modal-title font-weight-bold" id="modal_title"><i class="bi bi-tag-fill ml-1"></i> إضافة مجموعة جديد</h6>
                    <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body p-3">
                    <div class="form-group mb-0">
                        <label class="font-weight-bold text-dark mb-1">اسم المجموعة / المجموعة *</label>
                        <input type="text" name="name" id="modal_name" class="form-control rounded-0" placeholder="أدخل اسم المجموعة (مثال: جوالات، إلكترونيات، إكسسوارات...)" required>
                    </div>
                </div>
                <div class="modal-footer py-2">
                    <button type="submit" class="btn btn-primary btn-sm rounded-0 font-weight-bold px-4">حفظ البيانات</button>
                    <button type="button" class="btn btn-secondary btn-sm rounded-0" data-dismiss="modal">إلغاء</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script type="text/javascript">
function openCategoryModal(catid, name) {
    document.getElementById('modal_catid').value = catid;
    document.getElementById('modal_name').value = name;
    document.getElementById('modal_title').innerHTML = (catid > 0) ? '<i class="bi bi-pencil-square ml-1"></i> تعديل اسم المجموعة' : '<i class="bi bi-plus-circle ml-1"></i> إضافة مجموعة جديد';
    $('#categoryModal').modal('show');
    setTimeout(() => { document.getElementById('modal_name').focus(); }, 300);
}

function filterCategoriesTable() {
    const q = (document.getElementById('searchInput').value || '').toLowerCase();
    document.querySelectorAll('.category-row').forEach(row => {
        const text = row.innerText.toLowerCase();
        row.style.display = text.includes(q) ? '' : 'none';
    });
}
</script>

<?php require_once($dir_prefix . 'includes/footer.php'); ?>
