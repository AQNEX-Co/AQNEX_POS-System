<?php
$dir_prefix = '../';
$module = 'suppliers';
require_once($dir_prefix . 'includes/header.php');

check_permission(['admin', 'inventory']);

if (!isset($_GET['id']) || empty($_GET['id'])) {
    echo "<script>window.location='index.php';</script>";
    exit;
}

$supp_id = intval($_GET['id']);
$sql = "SELECT * FROM suppliers WHERE supp_id = $supp_id AND d_s = '0'";
$result = $conn->query($sql);

if (!$result || $result->num_rows == 0) {
    echo "<script>window.location='index.php';</script>";
    exit;
}

$supplier = $result->fetch_assoc();

if (isset($_POST['btn'])) {
    $supp_name = $conn->real_escape_string(trim($_POST['supp_name']));
    $phone = $conn->real_escape_string(trim($_POST['phone']));
    $email = $conn->real_escape_string(trim($_POST['email']));
    $address = $conn->real_escape_string(trim($_POST['address']));
    $company_name = $conn->real_escape_string(trim($_POST['company_name']));
    $notes = $conn->real_escape_string(trim($_POST['notes']));
    
    // حقول الدائن والمدين
    $supp_daain = isset($_POST['supp_daain']) ? doubleval($_POST['supp_daain']) : 0;
    $supp_madeen = isset($_POST['supp_madeen']) ? doubleval($_POST['supp_madeen']) : 0;
    
    // التحقق من عدم تكرار الاسم مع مورد آخر
    $chk = $conn->query("SELECT supp_id FROM suppliers WHERE supp_name = '$supp_name' AND d_s = 0 AND supp_id != $supp_id");
    if ($chk && $chk->num_rows > 0) {
        $error = "اسم المورد مسجل بالفعل لمورد آخر في النظام.";
    } else {
        $sql = "UPDATE suppliers SET 
                supp_name = '$supp_name', 
                phone = '$phone', 
                email = '$email', 
                address = '$address', 
                company_name = '$company_name', 
                notes = '$notes',
                supp_daain = $supp_daain,
                supp_madeen = $supp_madeen
                WHERE supp_id = $supp_id";
                
        if ($conn->query($sql)) {
            echo "<script>window.location='index.php';</script>";
            exit;
        } else {
            $error = "خطأ أثناء تحديث بيانات المورد: " . $conn->error;
        }
    }
}
?>
<title>تعديل بيانات المورد - تكنولوجيا فون</title>

<div class="row no-print mb-4">
    <div class="col-md-6">
        <h3 class="text-secondary font-weight-bold">
            <i class="fa fa-edit ml-2"></i>تعديل بيانات المورد
        </h3>
    </div>
    <div class="col-md-6 text-left">
        <a href="index.php" class="btn-flat btn-flat-secondary btn-sm text-decoration-none">
            <i class="fa fa-arrow-left ml-1"></i>عودة لقائمة الموردين
        </a>
    </div>
</div>

<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="card-flat">
            <div class="card-header">
                <h5>تعديل بيانات المورد: <?php echo htmlspecialchars($supplier['supp_name']); ?></h5>
            </div>
            <div class="card-body">
                <?php if (isset($error)): ?>
                    <div class="alert alert-danger rounded-0 mb-3"><?php echo $error; ?></div>
                <?php endif; ?>

                <form method="POST">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="font-weight-bold text-secondary">اسم المورد <span class="text-danger">*</span></label>
                                <input type="text" class="form-control rounded-0" name="supp_name" value="<?php echo htmlspecialchars($supplier['supp_name']); ?>" placeholder="أدخل اسم المورد بالكامل" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="font-weight-bold text-secondary">رقم الجوال <span class="text-danger">*</span></label>
                                <input type="text" class="form-control rounded-0" name="phone" value="<?php echo htmlspecialchars($supplier['phone']); ?>" placeholder="أدخل رقم الجوال" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="font-weight-bold text-secondary">اسم الشركة</label>
                                <input type="text" class="form-control rounded-0" name="company_name" value="<?php echo htmlspecialchars($supplier['company_name'] ?? ''); ?>" placeholder="أدخل اسم الشركة الموردة">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="font-weight-bold text-secondary">البريد الإلكتروني</label>
                                <input type="email" class="form-control rounded-0" name="email" value="<?php echo htmlspecialchars($supplier['email'] ?? ''); ?>" placeholder="supplier@example.com">
                            </div>
                        </div>
                        <div class="col-md-12">
                            <div class="form-group">
                                <label class="font-weight-bold text-secondary">العنوان</label>
                                <input type="text" class="form-control rounded-0" name="address" value="<?php echo htmlspecialchars($supplier['address'] ?? ''); ?>" placeholder="المحافظة - المدينة - الشارع">
                            </div>
                        </div>
                        
                        <!-- حقول الدائن والمدين -->
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="font-weight-bold text-secondary">
                                    <i class="fa fa-arrow-down text-success ml-1"></i> رصيد الدائن (له)
                                </label>
                                <div class="input-group">
                                    <div class="input-group-prepend">
                                        <span class="input-group-text bg-light rounded-0">ر.ي</span>
                                    </div>
                                    <input type="number" step="0.01" class="form-control rounded-0 text-center font-weight-bold" name="supp_daain" value="<?php echo number_format($supplier['supp_daain'] ?? 0, 2, '.', ''); ?>">
                                </div>
                                <small class="form-text text-muted">المبالغ المستحقة للمورد (ذمم دائنة)</small>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="font-weight-bold text-secondary">
                                    <i class="fa fa-arrow-up text-danger ml-1"></i> رصيد المدين (عليه)
                                </label>
                                <div class="input-group">
                                    <div class="input-group-prepend">
                                        <span class="input-group-text bg-light rounded-0">ر.ي</span>
                                    </div>
                                    <input type="number" step="0.01" class="form-control rounded-0 text-center font-weight-bold" name="supp_madeen" value="<?php echo number_format($supplier['supp_madeen'] ?? 0, 2, '.', ''); ?>">
                                </div>
                                <small class="form-text text-muted">المبالغ المستحقة على المورد (ذمم مدينة)</small>
                            </div>
                        </div>
                        
                        <div class="col-md-12">
                            <div class="form-group">
                                <label class="font-weight-bold text-secondary">ملاحظات إضافية</label>
                                <textarea class="form-control rounded-0" name="notes" rows="3" placeholder="أدخل أي تفاصيل أو شروط خاصة بالتعامل مع المورد..."><?php echo htmlspecialchars($supplier['notes'] ?? ''); ?></textarea>
                            </div>
                        </div>
                    </div>
                    
                    <hr class="my-4">
                    
                    <div class="form-group mb-0 text-left">
                        <button type="submit" class="btn-flat btn-flat-success" name="btn">
                            <i class="fa fa-save ml-1"></i>حفظ التعديلات
                        </button>
                        <a href="index.php" class="btn-flat btn-flat-secondary mr-2 text-decoration-none">إلغاء</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php
require_once($dir_prefix . 'includes/footer.php');
?>