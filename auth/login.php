<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
// بادئة المسار للمجلد الرئيسي
$dir_prefix = '../';

// تضمين ترويسة HTML والمكونات المشتركة
require_once($dir_prefix . "includes/header.php");

// إذا كان المستخدم مسجلاً دخوله بالفعل، يتم توجيهه للرئيسية
if (isset($_SESSION['SESS_MEMBER_ID']) && !empty($_SESSION['SESS_MEMBER_ID'])) {
    header("Location: ../home.php");
    exit();
}

$error_message = '';

if (isset($_POST['submit'])) {
    $username = $conn->real_escape_string($_POST['username']);
    $password = $conn->real_escape_string($_POST['password']);
    
    // جلب بيانات المستخدم لمطابقة الجلسة
    $sql = "SELECT * FROM users WHERE username='$username' AND password='$password' LIMIT 1";
    $result = $conn->query($sql);
    
    if ($result && $result->num_rows > 0) {
        $member = $result->fetch_assoc();
        
        session_regenerate_id(true);
        $_SESSION['SESS_MEMBER_ID'] = $member['userid'];
        $_SESSION['SESS_FIRST_NAME'] = $member['username'];
        // الاسم الكامل - يأخذ full_name إن وُجد وإلا يستخدم username
        $_SESSION['SESS_FULL_NAME'] = !empty($member['full_name']) ? $member['full_name'] : $member['username'];
        $_SESSION['SESS_LAST_NAME'] = isset($member['position']) ? $member['position'] : 'مسؤول';
        
        session_write_close();
        header("Location: ../home.php");
        exit();
    } else {
        $error_message = "خطأ: اسم المستخدم أو كلمة المرور غير صحيحة.";
    }
}
?>
<title>تسجيل الدخول - AQNEX POS</title>
<style>
    body {
        background: linear-gradient(135deg, #0f172a 0%, #1e293b 50%, #0f172a 100%) !important;
        min-height: 100vh;
    }
    #content {
        padding: 0 !important;
    }
    .login-wrapper {
        min-height: 100vh;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 20px;
    }
    .login-card {
        background: #fff;
        border: none;
        width: 100%;
        max-width: 420px;
        padding: 40px 35px;
        box-shadow: 0 20px 60px rgba(0,0,0,0.4) !important;
        border-radius: 4px !important;
    }
    .login-logo {
        width: 85px;
        height: 85px;
        object-fit: cover;
        border: 3px solid #e2e8f0;
        border-radius: 50% !important;
        margin-bottom: 20px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.15) !important;
    }
    .login-card .form-control {
        border: 1px solid #e2e8f0 !important;
        border-radius: 4px !important;
        padding: 10px 12px !important;
        font-size: 0.9rem !important;
        height: auto !important;
    }
    .login-card .input-group-text {
        border-radius: 4px 0 0 4px !important;
        background: #f8fafc;
        border-color: #e2e8f0 !important;
        color: #64748b;
    }
    .login-card .btn-login {
        background: linear-gradient(135deg, #0369a1, #0284c7);
        color: #fff !important;
        border: none !important;
        border-radius: 4px !important;
        padding: 12px 20px !important;
        font-size: 0.95rem !important;
        font-weight: 700 !important;
        width: 100%;
        cursor: pointer;
        transition: all 0.2s ease !important;
        box-shadow: 0 4px 12px rgba(3,105,161,0.3) !important;
    }
    .login-card .btn-login:hover {
        background: linear-gradient(135deg, #0284c7, #0369a1);
        transform: translateY(-1px);
        box-shadow: 0 6px 16px rgba(3,105,161,0.4) !important;
    }
    .login-divider {
        border-color: #e2e8f0;
        margin: 20px 0;
    }
</style>


<div class="login-wrapper">
    <div class="login-card text-center">
        <!-- الشعار -->
        <img src="<?php echo $logo_url; ?>" alt="<?php echo !empty($global_settings['store_name']) ? htmlspecialchars($global_settings['store_name']) : 'AQNEX POS'; ?>" class="login-logo">
        <h4 class="font-weight-bold mb-1" style="color:#1e293b"><?php echo !empty($global_settings['store_name']) ? htmlspecialchars($global_settings['store_name']) : 'AQNEX POS'; ?></h4>
        <p class="text-muted small mb-4">نظام إدارة المبيعات والمخازن</p>
        
        <?php if (!empty($error_message)): ?>
            <div class="alert alert-danger text-right small mb-3" style="border-radius:4px !important;">
                <i class="fa fa-exclamation-triangle ml-1"></i> <?php echo $error_message; ?>
            </div>
        <?php endif; ?>
        
        <!-- نموذج الدخول -->
        <form method="POST" class="text-right">
            <div class="form-group mb-3">
                <label class="text-secondary small font-weight-bold mb-1">اسم المستخدم</label>
                <div class="input-group">
                    <div class="input-group-prepend">
                        <span class="input-group-text"><i class="fa fa-user"></i></span>
                    </div>
                    <input type="text" name="username" class="form-control" placeholder="أدخل اسم المستخدم" required autofocus>
                </div>
            </div>
            
            <div class="form-group mb-4">
                <label class="text-secondary small font-weight-bold mb-1">كلمة المرور</label>
                <div class="input-group">
                    <div class="input-group-prepend">
                        <span class="input-group-text"><i class="fa fa-lock"></i></span>
                    </div>
                    <input type="password" name="password" class="form-control" placeholder="أدخل كلمة المرور" required>
                </div>
            </div>
            
            <button type="submit" name="submit" class="btn-login">
                <i class="fa fa-sign-in ml-2"></i> دخــــول
            </button>
            
            <hr class="login-divider">
            
            <div class="text-center">
                <a href="forget.php" class="text-muted small text-decoration-none">
                    <i class="fa fa-question-circle ml-1"></i> نسيت بيانات الحساب؟
                </a>
            </div>
        </form>
    </div>
</div>

<?php
require_once($dir_prefix . "includes/footer.php");
?>
