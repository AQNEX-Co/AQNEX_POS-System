<?php
$dir_prefix = '../';
$module = 'customers';
require_once($dir_prefix . 'includes/header.php');

check_permission(['admin', 'cashier']);
if (isset($_POST['btn'])) {
    date_default_timezone_set("Asia/Aden");
    $today = date("Y-m-d H:i:s");
    
    $cust_name = $conn->real_escape_string(trim($_POST['cust_name']));
    $phone = $conn->real_escape_string(trim($_POST['phone']));
    $email = $conn->real_escape_string(trim($_POST['email']));
    $address = $conn->real_escape_string(trim($_POST['address']));
    $credit_limit = doubleval($_POST['credit_limit']);
    $notes = $conn->real_escape_string(trim($_POST['notes']));
    
    $sql = "INSERT INTO customers (cust_name, phone, email, address, credit_limit, notes, sale_date) 
            VALUES ('$cust_name', '$phone', '$email', '$address', $credit_limit, '$notes', '$today')";
    if ($conn->query($sql)) {
        echo "<script>window.location='index.php';</script>";
        exit;
    } else {
        $error = "خطأ أثناء إضافة العميل: " . $conn->error;
    }
}
?>
<title>إضافة عميل جديد - تكنولوجيا فون</title>

<?php
$res_stats = $conn->query("SELECT COUNT(*) as total_cust, COALESCE(SUM(cust_madeen), 0) as total_debt FROM customers WHERE d_s = 0");
$stats = ($res_stats) ? $res_stats->fetch_assoc() : ['total_cust' => 0, 'total_debt' => 0];
?>

<div class="row no-print mb-4">
    <div class="col-md-6">
        <h3 class="text-secondary font-weight-bold">
            <i class="fa fa-user-plus ml-2 text-primary"></i> إضافة عميل جديد للنظام
        </h3>
        <p class="text-muted small mb-0">قم بإدخال بيانات العميل الجديد وتفاصيل الائتمان والعناوين الخاصة به.</p>
    </div>
    <div class="col-md-6 text-left d-flex align-items-center justify-content-end">
        <a href="index.php" class="btn btn-secondary btn-sm rounded-0 text-decoration-none font-weight-bold">
            <i class="fa fa-list ml-1"></i> عرض قائمة العملاء
        </a>
    </div>
</div>

<div class="row">
    <!-- نموذج الإضافة الرئيسي -->
    <div class="col-lg-8 mb-4">
        <div class="card-flat">
            <div class="card-header bg-light d-flex align-items-center">
                <h5 class="mb-0 font-weight-bold text-dark"><i class="fa fa-id-card ml-2 text-primary"></i>بيانات العميل الأساسية</h5>
            </div>
            <div class="card-body">
                <?php if (isset($error)): ?>
                    <div class="alert alert-danger rounded-0 mb-4"><?php echo $error; ?></div>
                <?php endif; ?>

                <form method="POST">
                    <div class="row">
                        <!-- اسم العميل -->
                        <div class="col-md-6 mb-3">
                            <div class="form-group">
                                <label class="font-weight-bold text-secondary mb-2">اسم العميل بالكامل <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <div class="input-group-prepend">
                                        <span class="input-group-text bg-light rounded-0"><i class="fa fa-user text-muted"></i></span>
                                    </div>
                                    <input type="text" class="form-control rounded-0" name="cust_name" placeholder="مثال: محمد أحمد صالح" required>
                                </div>
                            </div>
                        </div>
                        
                        <!-- رقم الجوال -->
                        <div class="col-md-6 mb-3">
                            <div class="form-group">
                                <label class="font-weight-bold text-secondary mb-2">رقم الجوال <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <div class="input-group-prepend">
                                        <span class="input-group-text bg-light rounded-0"><i class="fa fa-phone text-muted"></i></span>
                                    </div>
                                    <input type="text" class="form-control rounded-0 text-center font-weight-bold" name="phone" placeholder="77xxxxxxx" required>
                                </div>
                            </div>
                        </div>
                        
                        <!-- البريد الإلكتروني -->
                        <div class="col-md-6 mb-3">
                            <div class="form-group">
                                <label class="font-weight-bold text-secondary mb-2">البريد الإلكتروني</label>
                                <div class="input-group">
                                    <div class="input-group-prepend">
                                        <span class="input-group-text bg-light rounded-0"><i class="fa fa-envelope text-muted"></i></span>
                                    </div>
                                    <input type="email" class="form-control rounded-0" name="email" placeholder="customer@company.com">
                                </div>
                            </div>
                        </div>
                        
                        <!-- حد الائتمان -->
                        <div class="col-md-6 mb-3">
                            <div class="form-group">
                                <label class="font-weight-bold text-secondary mb-2">حد الائتمان الأقصى للآجل (ر.ي)</label>
                                <div class="input-group">
                                    <div class="input-group-prepend">
                                        <span class="input-group-text bg-light rounded-0"><i class="fa fa-credit-card text-muted"></i></span>
                                    </div>
                                    <input type="number" step="any" class="form-control rounded-0 text-center font-weight-bold text-danger" name="credit_limit" value="0.00">
                                </div>
                                <small class="text-muted">أقصى مبلغ مديونية مسموح به للعميل. (0 تعني بدون حد)</small>
                            </div>
                        </div>
                        
                        <!-- العنوان -->
                        <div class="col-md-12 mb-3">
                            <div class="form-group">
                                <label class="font-weight-bold text-secondary mb-2">العنوان السكني / مكان العمل</label>
                                <div class="input-group">
                                    <div class="input-group-prepend">
                                        <span class="input-group-text bg-light rounded-0"><i class="fa fa-map-marker text-muted"></i></span>
                                    </div>
                                    <input type="text" class="form-control rounded-0" name="address" placeholder="المحافظة - المدينة - اسم الشارع أو المعلم الشهير">
                                </div>
                            </div>
                        </div>
                        
                        <!-- ملاحظات -->
                        <div class="col-md-12 mb-3">
                            <div class="form-group">
                                <label class="font-weight-bold text-secondary mb-2">ملاحظات إضافية</label>
                                <textarea class="form-control rounded-0" name="notes" rows="4" placeholder="اكتب هنا أي تفاصيل أو تفضيلات خاصة بالتعامل مع هذا العميل..."></textarea>
                            </div>
                        </div>
                    </div>
                    
                    <hr class="my-4">
                    
                    <div class="form-group mb-0 text-left">
                        <button type="submit" class="btn btn-success rounded-0 font-weight-bold px-5 py-2" name="btn">
                            <i class="fa fa-check ml-1"></i> حفظ بيانات العميل الجديد
                        </button>
                        <a href="index.php" class="btn btn-secondary rounded-0 font-weight-bold px-4 py-2 mr-2 text-decoration-none">إلغاء وعودة</a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- الشريط الجانبي للإحصائيات والتوجيهات -->
    <div class="col-lg-4 mb-4">
        <!-- كارت إحصائيات سريعة -->
        <div class="card-flat mb-4 border-left-primary">
            <div class="card-header bg-light">
                <h6 class="mb-0 font-weight-bold text-secondary"><i class="fa fa-bar-chart ml-2"></i> مؤشرات العملاء الحالية</h6>
            </div>
            <div class="card-body">
                <div class="d-flex align-items-center justify-content-between mb-3 border-bottom pb-2">
                    <span class="text-muted small">إجمالي العملاء المسجلين:</span>
                    <span class="badge badge-primary font-weight-bold" style="font-size: 0.95rem;"><?php echo number_format($stats['total_cust']); ?></span>
                </div>
                <div class="d-flex align-items-center justify-content-between">
                    <span class="text-muted small">إجمالي المديونية المستحقة:</span>
                    <span class="text-danger font-weight-bold" style="font-size: 1.1rem;"><?php echo number_format($stats['total_debt'], 2); ?> ر.ي</span>
                </div>
            </div>
        </div>

        <!-- كارت التوجيهات الأمنية والسياسات -->
        <div class="card-flat">
            <div class="card-header bg-light">
                <h6 class="mb-0 font-weight-bold text-secondary"><i class="fa fa-info-circle ml-2"></i> إرشادات وسياسات الائتمان</h6>
            </div>
            <div class="card-body text-secondary small" style="line-height: 1.6;">
                <p class="mb-2"><strong class="text-dark">حد الائتمان:</strong> يساعدك تحديد حد الائتمان في السيطرة على الديون، حيث سيقوم النظام بتنبيه البائع أو منعه في حال تجاوز العميل للحد الائتماني المسموح به.</p>
                <p class="mb-2"><strong class="text-dark">تطابق الهوية:</strong> يرجى التحقق بدقة من الاسم ورقم الهاتف للمنع من حدوث تضارب في السجلات وحساب كشف الحساب بشكل سليم.</p>
                <p class="m-0"><strong class="text-dark">أمان البيانات:</strong> يتم تشفير وتخزين وحفظ السجلات محلياً لضمان أقصى حماية وخصوصية لمعلومات عملائك.</p>
            </div>
        </div>
    </div>
</div>

<?php
require_once($dir_prefix . 'includes/footer.php');
?>
