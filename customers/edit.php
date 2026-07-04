<?php
$dir_prefix = '../';
$module = 'customers';
require_once($dir_prefix . 'includes/header.php');

check_permission(['admin', 'cashier']);

if (!isset($_GET['id']) || empty($_GET['id'])) {
    echo "<script>window.location='index.php';</script>";
    exit;
}

$cust_id = intval($_GET['id']);
$sql = "SELECT * FROM customers WHERE cust_id = $cust_id AND d_s = '0'";
$result = $conn->query($sql);

if (!$result || $result->num_rows == 0) {
    echo "<script>window.location='index.php';</script>";
    exit;
}

$customer = $result->fetch_assoc();

if (isset($_POST['btn'])) {
    $cust_name = $conn->real_escape_string(trim($_POST['cust_name']));
    $phone = $conn->real_escape_string(trim($_POST['phone']));
    $email = $conn->real_escape_string(trim($_POST['email']));
    $address = $conn->real_escape_string(trim($_POST['address']));
    $credit_limit = doubleval($_POST['credit_limit']);
    $notes = $conn->real_escape_string(trim($_POST['notes']));
    
    $sql = "UPDATE customers SET 
            cust_name = '$cust_name', 
            phone = '$phone', 
            email = '$email', 
            address = '$address', 
            credit_limit = $credit_limit, 
            notes = '$notes' 
            WHERE cust_id = $cust_id";
            
    if ($conn->query($sql)) {
        echo "<script>window.location='index.php';</script>";
        exit;
    } else {
        $error = "خطأ أثناء تحديث بيانات العميل: " . $conn->error;
    }
}
?>
<title>تعديل بيانات العميل - تكنولوجيا فون</title>

<div class="row no-print mb-4">
    <div class="col-md-6">
        <h3 class="text-secondary font-weight-bold">
            <i class="fa fa-edit ml-2"></i>تعديل بيانات العميل
        </h3>
    </div>
    <div class="col-md-6 text-left">
        <a href="index.php" class="btn-flat btn-flat-secondary btn-sm text-decoration-none">
            <i class="fa fa-arrow-left ml-1"></i>عودة لقائمة العملاء
        </a>
    </div>
</div>

<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="card-flat">
            <div class="card-header">
                <h5>تعديل بيانات العميل: <?php echo htmlspecialchars($customer['cust_name']); ?></h5>
            </div>
            <div class="card-body">
                <?php if (isset($error)): ?>
                    <div class="alert alert-danger rounded-0 mb-3"><?php echo $error; ?></div>
                <?php endif; ?>

                <form method="POST">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="font-weight-bold text-secondary">اسم العميل <span class="text-danger">*</span></label>
                                <input type="text" class="form-control rounded-0" name="cust_name" value="<?php echo htmlspecialchars($customer['cust_name']); ?>" placeholder="أدخل اسم العميل بالكامل" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="font-weight-bold text-secondary">رقم الجوال <span class="text-danger">*</span></label>
                                <input type="text" class="form-control rounded-0" name="phone" value="<?php echo htmlspecialchars($customer['phone']); ?>" placeholder="أدخل رقم الجوال" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="font-weight-bold text-secondary">البريد الإلكتروني</label>
                                <input type="email" class="form-control rounded-0" name="email" value="<?php echo htmlspecialchars($customer['email'] ?? ''); ?>" placeholder="email@example.com">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="font-weight-bold text-secondary">حد الائتمان الأقصى للآجل (ر.ي)</label>
                                <input type="number" step="any" class="form-control rounded-0" name="credit_limit" value="<?php echo htmlspecialchars($customer['credit_limit'] ?? '0.00'); ?>">
                            </div>
                        </div>
                        <div class="col-md-12">
                            <div class="form-group">
                                <label class="font-weight-bold text-secondary">العنوان السكني / العمل</label>
                                <input type="text" class="form-control rounded-0" name="address" value="<?php echo htmlspecialchars($customer['address'] ?? ''); ?>" placeholder="المحافظة - المديرية - الشارع">
                            </div>
                        </div>
                        <div class="col-md-12">
                            <div class="form-group">
                                <label class="font-weight-bold text-secondary">ملاحظات إضافية حول العميل</label>
                                <textarea class="form-control rounded-0" name="notes" rows="3" placeholder="أدخل أي ملاحظات حول طبيعة تعامل العميل..."><?php echo htmlspecialchars($customer['notes'] ?? ''); ?></textarea>
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
