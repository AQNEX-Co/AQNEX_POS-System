<?php
$dir_prefix = '../';
require_once($dir_prefix . "includes/header.php");

if (isset($_SESSION['SESS_MEMBER_ID']) && !empty($_SESSION['SESS_MEMBER_ID'])) {
    header("Location: ../home.php");
    exit();
}

$success_output = '';
$error_message = '';

if (isset($_POST['btn'])) {
    $phone = $conn->real_escape_string(trim($_POST['phone']));
    
    if (empty($phone)) {
        $error_message = "يرجى إدخال رقم الجوال أولاً.";
    } else {
        // التحقق الديناميكي من اسم الجدول (users أو user)
        $table_name = 'users';
        $check_t = $conn->query("SHOW TABLES LIKE 'users'");
        if ($check_t && $check_t->num_rows == 0) {
            $check_user = $conn->query("SHOW TABLES LIKE 'user'");
            if ($check_user && $check_user->num_rows > 0) {
                $table_name = 'user';
            }
        }
        
        $sql_a = "SELECT * FROM `$table_name` WHERE phone='$phone' LIMIT 1";
        $result1 = $conn->query($sql_a);
        
        if ($result1 && $result1->num_rows > 0) {
            $r = $result1->fetch_assoc();
            $success_output = "<strong>اسم المستخدم:</strong> " . htmlspecialchars($r['username']) . "<br><strong>كلمة المرور:</strong> " . htmlspecialchars($r['password']);
        } else {
            $error_message = "خطأ: رقم الهاتف المدخل غير مطابق لأي مستخدم مسجل بالنظام.";
        }
    }
}
?>
<title>استعادة بيانات الحساب - AQNEX POS</title>
<style>
    body {
        background: linear-gradient(135deg, #0f172a 0%, #1e293b 50%, #0f172a 100%) !important;
        min-height: 100vh;
    }
    #content {
        padding: 0 !important;
    }
    .recovery-wrapper {
        min-height: 100vh;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 20px;
    }
    .recovery-card {
        background: #fff;
        border: none;
        width: 100%;
        max-width: 480px;
        padding: 40px 35px;
        box-shadow: 0 20px 60px rgba(0,0,0,0.4) !important;
        border-radius: 4px !important;
    }
    .recovery-logo {
        width: 75px;
        height: 75px;
        object-fit: cover;
        border: 3px solid #e2e8f0;
        border-radius: 50% !important;
        margin-bottom: 20px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.15) !important;
    }
    .recovery-card .form-control {
        border: 1px solid #e2e8f0 !important;
        border-radius: 4px !important;
        padding: 10px 12px !important;
        font-size: 0.9rem !important;
        height: auto !important;
    }
    .recovery-card .input-group-text {
        border-radius: 4px 0 0 4px !important;
        background: #f8fafc;
        border-color: #e2e8f0 !important;
        color: #64748b;
    }
    .recovery-card .btn-recover {
        background: linear-gradient(135deg, #0f766e, #0d9488);
        color: #fff !important;
        border: none !important;
        border-radius: 4px !important;
        padding: 12px 20px !important;
        font-size: 0.95rem !important;
        font-weight: 700 !important;
        width: 100%;
        cursor: pointer;
        transition: all 0.2s ease !important;
        box-shadow: 0 4px 12px rgba(15,118,110,0.3) !important;
    }
    .recovery-card .btn-recover:hover {
        background: linear-gradient(135deg, #0d9488, #0f766e);
        transform: translateY(-1px);
        box-shadow: 0 6px 16px rgba(15,118,110,0.4) !important;
    }
</style>

<div class="recovery-wrapper">
    <div class="recovery-card text-center">
        <!-- الشعار -->
        <img src="<?php echo $logo_url; ?>" alt="AQNEX POS" class="recovery-logo">
        <h4 class="font-weight-bold mb-1" style="color:#1e293b">استعادة بيانات الحساب</h4>
        <p class="text-muted small mb-4">أدخل رقم الهاتف المسجل لعرض واستعادة بيانات دخولك</p>
        
        <?php if (!empty($error_message)): ?>
            <div class="alert alert-danger text-right small mb-3" style="border-radius:4px !important;">
                <i class="fa fa-exclamation-triangle ml-1"></i> <?php echo $error_message; ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($success_output)): ?>
            <div class="alert alert-success text-right mb-4" style="border-radius:4px !important;">
                <i class="fa fa-check-circle ml-1"></i> <strong>تم الاستعادة بنجاح:</strong><br>
                <div class="mt-2 p-2 bg-light border text-dark font-weight-bold" style="border-radius:4px !important;">
                    <?php echo $success_output; ?>
                </div>
            </div>
        <?php endif; ?>
        
        <!-- نموذج التحقق -->
        <form method="POST" class="text-right">
            <div class="form-group mb-4">
                <label class="text-secondary small font-weight-bold mb-1">رقم التلفون المسجل *</label>
                <div class="input-group">
                    <div class="input-group-prepend">
                        <span class="input-group-text"><i class="fa fa-phone"></i></span>
                    </div>
                    <input type="text" name="phone" class="form-control" placeholder="أدخل رقم الجوال المسجل" required>
                </div>
            </div>
            
            <button type="submit" name="btn" class="btn-recover">
                <i class="fa fa-shield ml-2"></i> استعادة بيانات الحساب
            </button>
            
            <div class="text-center mt-4">
                <a href="login.php" class="text-muted small text-decoration-none">
                    <i class="fa fa-arrow-right ml-1"></i> العودة لشاشة تسجيل الدخول
                </a>
            </div>
        </form>
    </div>
</div>

<?php
require_once($dir_prefix . "includes/footer.php");
?>
