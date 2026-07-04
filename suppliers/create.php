<?php
$dir_prefix = '../';
$module = 'suppliers';
require_once($dir_prefix . 'includes/header.php');

check_permission(['admin', 'inventory']);

if (isset($_POST['btn'])) {
    date_default_timezone_set("Asia/Aden");
    $today = date("Y-m-d H:i:s");
    
    $supp_name = $conn->real_escape_string(trim($_POST['supp_name']));
    $phone = $conn->real_escape_string(trim($_POST['phone']));
    $email = $conn->real_escape_string(trim($_POST['email']));
    $address = $conn->real_escape_string(trim($_POST['address']));
    $company_name = $conn->real_escape_string(trim($_POST['company_name']));
    $notes = $conn->real_escape_string(trim($_POST['notes']));
    
    // حقول الدائن والمدين
    $supp_daain = isset($_POST['supp_daain']) ? doubleval($_POST['supp_daain']) : 0;
    $supp_madeen = isset($_POST['supp_madeen']) ? doubleval($_POST['supp_madeen']) : 0;
    
    // التحقق من عدم تكرار الاسم
    $chk = $conn->query("SELECT supp_id FROM suppliers WHERE supp_name = '$supp_name' AND d_s = 0");
    if ($chk && $chk->num_rows > 0) {
        $error = "اسم المورد مسجل بالفعل في النظام. يرجى استخدام اسم مختلف.";
    } else {
        $sql = "INSERT INTO Suppliers (supp_name, phone, email, address, company_name, notes, buy_date, supp_daain, supp_madeen) 
                VALUES ('$supp_name', '$phone', '$email', '$address', '$company_name', '$notes', '$today', $supp_daain, $supp_madeen)";
        if ($conn->query($sql)) {
            echo "<script>window.location='index.php';</script>";
            exit;
        } else {
            $error = "خطأ أثناء إضافة المورد: " . $conn->error;
        }
    }
}
?>
<title>إضافة مورد جديد - تكنولوجيا فون</title>

<?php
$res_stats = $conn->query("SELECT COUNT(*) as total_supp, COALESCE(SUM(supp_daain), 0) as total_debt FROM suppliers WHERE d_s = 0");
$stats = ($res_stats) ? $res_stats->fetch_assoc() : ['total_supp' => 0, 'total_debt' => 0];
?>

<div class="row no-print mb-4">
    <div class="col-md-6">
        <h3 class="text-secondary font-weight-bold">
            <i class="fa fa-industry ml-2 text-primary"></i> إضافة مورد جديد للنظام
        </h3>
        <p class="text-muted small mb-0">قم بإدخال بيانات المورد الجديد، تفاصيل الشركة، الهواتف والعناوين الخاصة به.</p>
    </div>
    <div class="col-md-6 text-left d-flex align-items-center justify-content-end">
        <a href="index.php" class="btn btn-secondary btn-sm rounded-0 text-decoration-none font-weight-bold">
            <i class="fa fa-list ml-1"></i> عرض قائمة الموردين
        </a>
    </div>
</div>

<div class="row">
    <div class="col-lg-8 mb-4">
        <div class="card-flat">
            <div class="card-header bg-light d-flex align-items-center">
                <h5 class="mb-0 font-weight-bold text-dark"><i class="fa fa-id-card ml-2 text-primary"></i>بيانات المورد الأساسية</h5>
            </div>
            <div class="card-body">
                <?php if (isset($error)): ?>
                    <div class="alert alert-danger rounded-0 mb-4"><?php echo $error; ?></div>
                <?php endif; ?>

                <form method="POST">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <div class="form-group">
                                <label class="font-weight-bold text-secondary mb-2">اسم المورد بالكامل <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <div class="input-group-prepend">
                                        <span class="input-group-text bg-light rounded-0"><i class="fa fa-user text-muted"></i></span>
                                    </div>
                                    <input type="text" class="form-control rounded-0" name="supp_name" placeholder="مثال: شركة التكنولوجيا الحديثة" required>
                                </div>
                            </div>
                        </div>
                        
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
                        
                        <div class="col-md-6 mb-3">
                            <div class="form-group">
                                <label class="font-weight-bold text-secondary mb-2">اسم الشركة / المؤسسة الموردة</label>
                                <div class="input-group">
                                    <div class="input-group-prepend">
                                        <span class="input-group-text bg-light rounded-0"><i class="fa fa-building text-muted"></i></span>
                                    </div>
                                    <input type="text" class="form-control rounded-0" name="company_name" placeholder="أدخل اسم الشركة التجاري">
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <div class="form-group">
                                <label class="font-weight-bold text-secondary mb-2">البريد الإلكتروني للمورد</label>
                                <div class="input-group">
                                    <div class="input-group-prepend">
                                        <span class="input-group-text bg-light rounded-0"><i class="fa fa-envelope text-muted"></i></span>
                                    </div>
                                    <input type="email" class="form-control rounded-0" name="email" placeholder="supplier@company.com">
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-12 mb-3">
                            <div class="form-group">
                                <label class="font-weight-bold text-secondary mb-2">العنوان / المقر الرئيسي للشركة</label>
                                <div class="input-group">
                                    <div class="input-group-prepend">
                                        <span class="input-group-text bg-light rounded-0"><i class="fa fa-map-marker text-muted"></i></span>
                                    </div>
                                    <input type="text" class="form-control rounded-0" name="address" placeholder="المحافظة - المدينة - الشارع - رقم المكتب أو المبنى">
                                </div>
                            </div>
                        </div>
                        
                        <!-- حقول الدائن والمدين الجديدة -->
                        <div class="col-md-6 mb-3">
                            <div class="form-group">
                                <label class="font-weight-bold text-secondary mb-2">
                                    <i class="fa fa-arrow-down text-success ml-1"></i> رصيد الدائن (له) - اختياري
                                </label>
                                <div class="input-group">
                                    <div class="input-group-prepend">
                                        <span class="input-group-text bg-light rounded-0">ر.ي</span>
                                    </div>
                                    <input type="number" step="0.01" class="form-control rounded-0 text-center font-weight-bold" name="supp_daain" value="0" placeholder="0.00">
                                </div>
                                <small class="form-text text-muted">المبالغ المستحقة للمورد من النظام (ذمم دائنة)</small>
                            </div>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <div class="form-group">
                                <label class="font-weight-bold text-secondary mb-2">
                                    <i class="fa fa-arrow-up text-danger ml-1"></i> رصيد المدين (عليه) - اختياري
                                </label>
                                <div class="input-group">
                                    <div class="input-group-prepend">
                                        <span class="input-group-text bg-light rounded-0">ر.ي</span>
                                    </div>
                                    <input type="number" step="0.01" class="form-control rounded-0 text-center font-weight-bold" name="supp_madeen" value="0" placeholder="0.00">
                                </div>
                                <small class="form-text text-muted">المبالغ المستحقة على المورد للنظام (ذمم مدينة)</small>
                            </div>
                        </div>
                        
                        <div class="col-md-12 mb-3">
                            <div class="form-group">
                                <label class="font-weight-bold text-secondary mb-2">ملاحظات وشروط خاصة بالتعامل</label>
                                <textarea class="form-control rounded-0" name="notes" rows="4" placeholder="اكتب هنا أي ملاحظات حول طبيعة التعامل، شروط السداد، الخصومات المتفق عليها..."></textarea>
                            </div>
                        </div>
                    </div>
                    
                    <hr class="my-4">
                    
                    <div class="form-group mb-0 text-left">
                        <button type="submit" class="btn btn-success rounded-0 font-weight-bold px-5 py-2" name="btn">
                            <i class="fa fa-check ml-1"></i> حفظ بيانات المورد الجديد
                        </button>
                        <a href="index.php" class="btn btn-secondary rounded-0 font-weight-bold px-4 py-2 mr-2 text-decoration-none">إلغاء وعودة</a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-4 mb-4">
        <div class="card-flat mb-4 border-left-primary">
            <div class="card-header bg-light">
                <h6 class="mb-0 font-weight-bold text-secondary"><i class="fa fa-bar-chart ml-2"></i> مؤشرات الموردين الحالية</h6>
            </div>
            <div class="card-body">
                <div class="d-flex align-items-center justify-content-between mb-3 border-bottom pb-2">
                    <span class="text-muted small">إجمالي الموردين المسجلين:</span>
                    <span class="badge badge-primary font-weight-bold" style="font-size: 0.95rem;"><?php echo number_format($stats['total_supp']); ?></span>
                </div>
                <div class="d-flex align-items-center justify-content-between">
                    <span class="text-muted small">إجمالي حساب الدائنين (الالتزامات):</span>
                    <span class="text-danger font-weight-bold" style="font-size: 1.1rem;"><?php echo number_format($stats['total_debt'], 2); ?> ر.ي</span>
                </div>
            </div>
        </div>

        <div class="card-flat">
            <div class="card-header bg-light">
                <h6 class="mb-0 font-weight-bold text-secondary"><i class="fa fa-info-circle ml-2"></i> إرشادات وسياسات الموردين</h6>
            </div>
            <div class="card-body text-secondary small" style="line-height: 1.6;">
                <p class="mb-2"><strong class="text-dark">حساب الدائنين:</strong> عند شراء بضائع بالأجل، يسجل النظام مبالغ الفواتير غير المدفوعة تلقائياً في حساب مديونية الموردين لتتمكن من مراجعتها وسدادها لاحقاً.</p>
                <p class="mb-2"><strong class="text-dark">الاسم المعتمد:</strong> يفضل تسجيل اسم الشركة الرسمي مع اسم الشخص المسؤول لتسهيل عمليات الفوترة والمطابقة المالية.</p>
                <p class="m-0"><strong class="text-dark">ملاحظات التعامل:</strong> استخدم حقل الملاحظات لتسجيل شروط التسليم والدفع المتفق عليها لمنع حدوث خلافات أثناء التوريد.</p>
            </div>
        </div>
    </div>
</div>

<?php
require_once($dir_prefix . 'includes/footer.php');
?>