<?php
$dir_prefix = '../';
$module = 'categories';
require_once($dir_prefix . 'includes/header.php');

check_permission(['admin', 'inventory']);
$sql = "SELECT * FROM categories ORDER BY catid DESC";
$result = $conn->query($sql);
?>
<title>إدارة أصناف المنتجات - تكنولوجيا فون</title>

<div class="row no-print mb-4">
    <div class="col-md-6">
        <h3 class="text-secondary font-weight-bold">
            <i class="fa fa-tags ml-2"></i>إدارة أصناف المنتجات
        </h3>
    </div>
    <div class="col-md-6 text-left">
        <a href="create.php" class="btn-flat btn-flat-primary btn-sm ml-2 text-decoration-none">
            <i class="fa fa-plus ml-1"></i>إضافة صنف جديد
        </a>
        <a href="../home.php" class="btn-flat btn-flat-secondary btn-sm text-decoration-none">
            <i class="fa fa-arrow-left ml-1"></i>عودة للرئيسية
        </a>
    </div>
</div>

<div class="card-flat">
    <div class="card-header no-print">
        <h5>أصناف المنتجات المسجلة</h5>
        <div class="d-flex align-items-center">
            <span class="ml-2 font-weight-bold">البحث:</span>
            <input type="text" id="searchInput" class="form-control form-control-sm" style="width: 250px;" placeholder="ابحث باسم الصنف...">
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table-flat" id="categoriesTable">
                <thead>
                    <tr>
                        <th style="width: 15%;">رقم الصنف</th>
                        <th>اسم التصنيف / الصنف</th>
                        <th class="no-print" style="width: 30%;">العمليات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    if ($result && $result->num_rows > 0) {
                        while($row = $result->fetch_assoc()) {
                            ?>
                            <tr class="category-row">
                                <td>#<?php echo $row['catid']; ?></td>
                                <td class="font-weight-bold text-secondary text-right pr-4"><?php echo htmlspecialchars($row['name']); ?></td>
                                <td class="no-print">
                                    <a href="../products/category.php?id=<?php echo $row['catid']; ?>" class="btn-flat btn-flat-success btn-sm py-1 px-2 ml-1 text-decoration-none">
                                        <i class="bi bi-grid ml-1"></i>استعرض المنتجات
                                    </a>
                                    <a href="delete.php?id=<?php echo $row['catid']; ?>" onclick="return confirm('هل أنت متأكد من حذف هذا الصنف؟')" class="btn-flat btn-flat-danger btn-sm py-1 px-2 text-decoration-none">
                                        <i class="fa fa-trash ml-1"></i>حذف
                                    </a>
                                </td>
                            </tr>
                            <?php
                        }
                    } else {
                        echo '<tr><td colspan="3" class="text-center text-muted p-4">لا توجد أصناف مسجلة</td></tr>';
                    }
                    ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script type="text/javascript">
document.addEventListener("DOMContentLoaded", function() {
    const searchInput = document.getElementById("searchInput");
    if (searchInput) {
        searchInput.addEventListener("keyup", function() {
            const filter = this.value.toUpperCase();
            document.querySelectorAll(".category-row").forEach(function(row) {
                const text = row.innerText.toUpperCase();
                if (text.indexOf(filter) > -1) {
                    row.style.display = "";
                } else {
                    row.style.display = "none";
                }
            });
        });
    }
});
</script>

<?php
require_once($dir_prefix . 'includes/footer.php');
?>
