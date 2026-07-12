<?php
// تضمين البنية الجديدة للتهيئة قبل إعداد الاتصال
require_once(__DIR__ . '/../app/Core/Bootstrap.php');
\AQNEX\Core\Bootstrap::initialize();

// تحميل إعدادات الاتصال من البنية المهيكلة
require_once(__DIR__ . '/../app/Config/Database.php');

$conn = null;
$conn_error = '';

try {
    $conn = \AQNEX\Config\Database::createMysqli();
} catch (\Exception $e) {
    $conn_error = $e->getMessage();
    $conn = null;
}

if (!$conn) {
    // التحقق إذا كان الرابط المطلوب يقع داخل أدوات الدعم الفني لتجنب مقاطعة عمل مهندس الدعم
    $requestUri = $_SERVER['REQUEST_URI'] ?? '';
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
    if (strpos($requestUri, '/support_tools/') !== false || strpos($scriptName, '/support_tools/') !== false) {
        $conn = null;
        $pdo = null;
        return;
    }

    // عرض صفحة خطأ الاتصال المنمقة بهوية AQNEX POS
    header('HTTP/1.1 500 Internal Server Error');
    $prefix = $dir_prefix ?? '../';
    ?>
    <!DOCTYPE html>
    <html lang="ar" dir="rtl">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>فشل الاتصال بقاعدة البيانات - AQNEX POS</title>
        <!-- Icons -->
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
        <style>
            :root {
                --ink-900: #0f1b2d;
                --ink-700: #1e3148;
                --ink-500: #46607e;
                --accent: #1d4ed8;
                --accent-dark: #1e3a8a;
                --bad: #b91c1c;
                --radius: 8px;
            }
            body {
                background-color: #f6f8fb;
                font-family: system-ui, -apple-system, sans-serif;
                color: var(--ink-700);
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                margin: 0;
            }
            .error-card {
                background: #ffffff;
                border: 1px solid #e1e7ee;
                border-radius: var(--radius);
                box-shadow: 0 10px 25px rgba(15, 27, 45, 0.08);
                width: 100%;
                max-width: 500px;
                padding: 40px 30px;
                text-align: center;
            }
            .error-icon {
                font-size: 3.5rem;
                color: var(--bad);
                margin-bottom: 20px;
            }
            .error-title {
                color: var(--ink-900);
                font-weight: 800;
                font-size: 1.5rem;
                margin-bottom: 12px;
            }
            .error-desc {
                font-size: 0.95rem;
                color: var(--ink-500);
                line-height: 1.6;
                margin-bottom: 30px;
            }
            .btn-retry {
                background-color: var(--accent);
                color: #ffffff;
                font-weight: 700;
                padding: 12px 30px;
                border-radius: 4px;
                border: none;
                transition: background-color 0.15s;
                text-decoration: none;
                display: inline-block;
                cursor: pointer;
            }
            .btn-retry:hover {
                background-color: var(--accent-dark);
                color: #ffffff;
            }
            .support-trigger-corner {
                position: fixed;
                bottom: 20px;
                left: 20px;
                background-color: var(--ink-900);
                border: 2px solid var(--accent);
                color: #ffffff;
                border-radius: 50%;
                width: 45px;
                height: 45px;
                display: flex;
                align-items: center;
                justify-content: center;
                cursor: pointer;
                box-shadow: 0 4px 10px rgba(0,0,0,0.15);
                transition: transform 0.2s, background-color 0.2s;
            }
            .support-trigger-corner:hover {
                transform: scale(1.1);
                background-color: var(--accent);
            }
            /* Modal style */
            .custom-modal {
                display: none;
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background-color: rgba(15, 27, 45, 0.5);
                z-index: 2000;
                align-items: center;
                justify-content: center;
            }
            .custom-modal-content {
                background: #ffffff;
                border-radius: var(--radius);
                width: 100%;
                max-width: 420px;
                padding: 30px;
                box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            }
            .form-control {
                width: 100%;
                padding: 10px;
                margin-bottom: 15px;
                border: 1px solid #e1e7ee;
                border-radius: 4px;
                box-sizing: border-box;
                text-align: center;
                font-weight: bold;
            }
            .btn-modal {
                padding: 8px 16px;
                border-radius: 4px;
                border: 1px solid transparent;
                cursor: pointer;
                font-weight: bold;
            }
            .btn-modal-primary {
                background-color: var(--accent);
                color: white;
            }
            .btn-modal-secondary {
                background-color: #ffffff;
                border-color: #cbd5e1;
                color: var(--ink-700);
            }
        </style>
    </head>
    <body>
        <div class="error-card">
            <div class="error-icon"><i class="bi bi-database-exclamation"></i></div>
            <h4 class="error-title">فشل الاتصال بقاعدة البيانات</h4>
            <p class="error-desc">
                فشل الاتصال بقاعدة البيانات. يرجى التحقق من إعدادات الاتصال.
                <br>
                <small style="color: var(--bad); font-weight: bold;"><?php echo htmlspecialchars($conn_error); ?></small>
            </p>
            <a href="" class="btn-retry"><i class="bi bi-arrow-clockwise"></i> إعادة المحاولة</a>
        </div>

        <!-- Floating Fallback Button -->
        <div class="support-trigger-corner" id="support-trigger" title="وضع مهندس الدعم (F2)">
            <i class="bi bi-wrench"></i>
        </div>

        <!-- Support F2 modal -->
        <div class="custom-modal" id="modal-offline-support">
            <div class="custom-modal-content text-right">
                <h5 class="font-weight-bold mb-3" style="color: var(--ink-900);"><i class="bi bi-wrench"></i> وضع مهندس الدعم الفني</h5>
                <p class="small text-muted mb-4">أدخل الرقم السري المعتمد للدخول إلى لوحة الدعم الفني وإدارة قاعدة البيانات دون اتصال.</p>
                <form id="offline-support-form">
                    <div class="form-group">
                        <input type="password" id="offline-pwd" class="form-control" placeholder="رقم المرور للدعم الفني (الافتراضي 123)" required>
                    </div>
                    <div style="display: flex; justify-content: space-between; gap: 10px;">
                        <button type="button" class="btn-modal btn-modal-secondary" id="btn-close-modal">إلغاء</button>
                        <button type="submit" class="btn-modal btn-modal-primary">تأكيد الدخول</button>
                    </div>
                </form>
            </div>
        </div>

        <script>
            // F2 Key listener
            document.addEventListener('keydown', function(e) {
                if (e.key === 'F2') {
                    e.preventDefault();
                    document.getElementById('modal-offline-support').style.display = 'flex';
                }
            });

            // Click floating button
            document.getElementById('support-trigger').addEventListener('click', function(e) {
                e.preventDefault();
                document.getElementById('modal-offline-support').style.display = 'flex';
            });

            // Close modal
            document.getElementById('btn-close-modal').addEventListener('click', function() {
                document.getElementById('modal-offline-support').style.display = 'none';
            });

            // Form Submit handler
            document.getElementById('offline-support-form').addEventListener('submit', function(e) {
                e.preventDefault();
                var pwd = document.getElementById('offline-pwd').value.trim();
                if (pwd === '123') {
                    // Redirect to support tools with Adminer
                    var prefix = '<?php echo $prefix; ?>';
                    window.location.href = prefix + 'support_tools/index.php?auth=' + encodeURIComponent(pwd) + '&use_adminer=1';
                } else {
                    alert('رقم المرور للدعم الفني غير صحيح!');
                }
            });
        </script>
    </body>
    </html>
    <?php
    exit();
}

// تضمين النواة الأمنية الحالية للحفاظ على آلية التفعيل كما هي
require_once(__DIR__ . '/../core/bootstrap.php');
?>
