<?php
$dir_prefix = '../';
$module = 'customers';
require_once($dir_prefix . 'includes/header.php');

check_permission(['admin', 'cashier']);
if (isset($_POST['btn'])) {
    date_default_timezone_set("Asia/Aden");
    $today = date("Y-m-d H:i:s");
    
    $cust_name = $conn->real_escape_string($_POST['cust_name']);
    $phone = $conn->real_escape_string($_POST['phone']);
    
    $sql = "INSERT INTO customers (cust_name, phone, sale_date) VALUES ('$cust_name', '$phone', '$today')";
    if ($conn->query($sql)) {
        echo "<script>window.location='index.php';</script>";
        exit;
    } else {
        $error = "خطأ أثناء إضافة العميل: " . $conn->error;
    }
}
?>
<title>إضافة عميل جديد - تكنولوجيا فون</title>

<div class="row no-print mb-4">
    <div class="col-md-6">
        <h3 class="text-secondary font-weight-bold">
            <i class="fa fa-users-plus ml-2"></i>إضافة عميل جديد
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
                <h5>بيانات العميل الجديد</h5>
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
                                <input type="text" class="form-control" name="cust_name" placeholder="أدخل اسم العميل بالكامل" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="font-weight-bold text-secondary">رقم الجوال <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="phone" placeholder="أدخل رقم الجوال" required>
                            </div>
                        </div>
                    </div>
                    
                    <hr class="my-4">
                    
                    <div class="form-group mb-0 text-left">
                        <button type="submit" class="btn-flat btn-flat-success" name="btn">
                            <i class="fa fa-plus ml-1"></i>حفظ وإضافة العميل
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
