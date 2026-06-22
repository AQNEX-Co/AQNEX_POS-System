<?php
$dir_prefix = '../';
$module = 'suppliers';
require_once($dir_prefix . 'includes/header.php');

check_permission(['admin', 'inventory']);
// جلب جميع الموردين الذين لم يتم حذفهم (d_s = 0)
$sql = "SELECT * FROM Suppliers WHERE d_s='0' ORDER BY supp_id DESC";
$result = $conn->query($sql);
?>
<title>إدارة الموردين</title>

<div class="row no-print mb-4">
    <div class="col-md-6">
        <h3 class="text-secondary font-weight-bold">
            <i class="fa fa-briefcase ml-2"></i>إدارة الموردين
        </h3>
    </div>
    <div class="col-md-6 text-left">
        <a href="create.php" class="btn-flat btn-flat-primary btn-sm ml-2 text-decoration-none">
            <i class="fa fa-plus-circle ml-1"></i>إضافة مورد جديد
        </a>
        <a href="../includes/export.php?type=suppliers" class="btn-flat btn-flat-success btn-sm ml-2 text-decoration-none" style="background-color: var(--accent-success); color: #fff;">
            <i class="bi bi-file-earmark-excel ml-1"></i>تصدير إكسل
        </a>
        <a href="pay.php" class="btn-flat btn-flat-success btn-sm ml-2 text-decoration-none">
            <i class="bi bi-cash-coin ml-1"></i>تسديد مورد
        </a>
        <a href="../home.php" class="btn-flat btn-flat-secondary btn-sm text-decoration-none">
            <i class="fa fa-home ml-1"></i>العودة للرئيسية
        </a>
    </div>
</div>

<div class="card-flat">
    <div class="card-header no-print">
        <h5>قائمة الموردين المسجلين</h5>
        <div class="d-flex align-items-center">
            <span class="ml-2 font-weight-bold">البحث:</span>
            <input type="text" id="search" onkeyup="filterSuppliers()" class="form-control form-control-sm" style="width: 250px;" placeholder="ابحث باسم المورد أو الهاتف...">
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table-flat" id="suppliersTable">
                <thead>
                    <tr>
                        <th>اسم المورد</th>
                        <th>دائن \ له</th>
                        <th>مدين \ عليه</th>
                        <th>رقم الجوال</th>
                        <th>تاريخ الإضافة</th>
                        <th class="no-print">الإجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($result && $result->num_rows > 0): ?>
                        <?php while ($row = $result->fetch_assoc()): ?>
                            <tr>
                                <td class="font-weight-bold text-secondary"><?php echo htmlspecialchars($row['supp_name']); ?></td>
                                <td class="text-success font-weight-bold"><?php echo number_format($row['supp_daain'], 2); ?></td>
                                <td class="text-danger font-weight-bold"><?php echo number_format($row['supp_madeen'], 2); ?></td>
                                <td><?php echo htmlspecialchars($row['phone']); ?></td>
                                <td><?php echo htmlspecialchars($row['buy_date']); ?></td>
                                <td class="no-print">
                                    <a href="ledger.php?id=<?php echo $row['supp_id']; ?>" class="btn-flat btn-flat-primary btn-sm py-1 px-2 ml-1 text-decoration-none" title="كشف حساب">
                                        <i class="bi bi-journal-text ml-1"></i>كشف حساب
                                    </a>
                                    <a href="delete.php?id=<?php echo urlencode($row['supp_name']); ?>" onclick="return confirm('هل أنت متأكد من حذف هذا المورد وجميع سجلاته؟')" class="btn-flat btn-flat-danger btn-sm py-1 px-2 ml-1 text-decoration-none" title="مسح">
                                        <i class="fa fa-trash ml-1"></i>مسح
                                    </a>
                                    <?php if (!empty($row['phone'])): ?>
                                        <a href="https://web.whatsapp.com/send?phone=967<?php echo preg_replace('/\D/', '', $row['phone']); ?>&text&type=phone_number&app_absent=0" target="_blank" class="btn-flat btn-flat-success btn-sm py-1 px-2 text-decoration-none" title="واتساب">
                                            <i class="fa fa-whatsapp"></i>ارسال عبر واتساب
                                        </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" class="text-center text-muted p-4">لا يوجد موردين مسجلين حالياً.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
function filterSuppliers() {
    var input = document.getElementById("search");
    var filter = input.value.toUpperCase();
    var table = document.getElementById("suppliersTable");
    var tr = table.getElementsByTagName("tr");
    
    for (var i = 1; i < tr.length; i++) {
        var tdName = tr[i].getElementsByTagName("td")[0];
        var tdPhone = tr[i].getElementsByTagName("td")[3];
        if (tdName || tdPhone) {
            var txtValueName = tdName ? (tdName.textContent || tdName.innerText) : "";
            var txtValuePhone = tdPhone ? (tdPhone.textContent || tdPhone.innerText) : "";
            if (txtValueName.toUpperCase().indexOf(filter) > -1 || txtValuePhone.toUpperCase().indexOf(filter) > -1) {
                tr[i].style.display = "";
            } else {
                tr[i].style.display = "none";
            }
        }
    }
}
</script>

<?php
require_once($dir_prefix . 'includes/footer.php');
?>
