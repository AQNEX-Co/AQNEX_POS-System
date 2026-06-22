<?php
$dir_prefix = '../';
$module = 'categories';
require_once($dir_prefix . 'includes/header.php');

check_permission(['admin', 'inventory']);
if (isset($_POST['btn_save'])) {
    $name = $conn->real_escape_string($_POST['name']);
    
    if (!empty($name)) {
        $sql = "INSERT INTO categories (name) VALUES ('$name')";
        if ($conn->query($sql)) {
            echo "<script>window.location='index.php';</script>";
            exit;
        } else {
            $error = "حدث خطأ أثناء إضافة الصنف: " . $conn->error;
        }
    }
}
?>
<title>إضافة صنف جديد - تكنولوجيا فون</title>

<div class="row no-print mb-4">
    <div class="col-md-6">
        <h3 class="text-secondary font-weight-bold">
            <i class="fa fa-tags ml-2"></i>إضافة صنف منتجات جديد
        </h3>
    </div>
    <div class="col-md-6 text-left">
        <a href="index.php" class="btn-flat btn-flat-secondary btn-sm text-decoration-none">
            <i class="fa fa-arrow-left ml-1"></i>عودة لقائمة الأصناف
        </a>
    </div>
</div>

<div class="row justify-content-center">
    <div class="col-md-6">
        <div class="card-flat">
            <div class="card-header">
                <h5>بيانات الصنف الجديد</h5>
            </div>
            <div class="card-body">
                <?php if (isset($error)): ?>
                    <div class="alert alert-danger rounded-0 mb-3"><?php echo $error; ?></div>
                <?php endif; ?>

                <form method="POST">
                    <div class="form-group mb-4">
                        <label class="font-weight-bold text-secondary mb-2">اسم الصنف الجديد <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control rounded-0" placeholder="مثل: هواتف ذكية، شواحن، إكسسوارات..." required>
                    </div>

                    <button type="submit" name="btn_save" class="btn-flat btn-flat-primary btn-block py-2">
                        <i class="fa fa-check ml-1"></i> إنشاء الصنف
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php
require_once($dir_prefix . 'includes/footer.php');
?>
