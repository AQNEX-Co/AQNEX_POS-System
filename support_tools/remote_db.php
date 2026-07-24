<?php
/**
 * AQNEX POS - صفحة الإدارة عن بُعد لقاعدة البيانات
 * Remote Database Access via Adminer
 *
 * الوصول: http://[IP-العميل]:8181/support_tools/remote_db.php
 *
 * الحماية:
 *  - رمز مرور مخصص يُضبط من صفحة الإعدادات (support_token)
 *  - الافتراضي: 123 (يُوصى بتغييره)
 */

session_start();

// ─── إعداد الاتصال بقاعدة البيانات ───────────────────────────────────────────
require_once __DIR__ . '/../app/Core/Bootstrap.php';
\AQNEX\Core\Bootstrap::initialize();
require_once __DIR__ . '/../app/Config/Database.php';

$conn = null;
try {
    $conn = \AQNEX\Config\Database::createMysqli();
} catch (\Exception $e) {
    $conn = null;
}

// ─── جلب رمز الدعم الفني ───────────────────────────────────────────────────
$support_token = '123';
if ($conn) {
    $res_s = @$conn->query("SELECT support_token FROM settings WHERE id = 1 LIMIT 1");
    if ($res_s && $row_s = $res_s->fetch_assoc()) {
        $support_token = $row_s['support_token'] ?? '123';
    }
}
if (empty($support_token)) {
    $support_token = '123';
}

// ─── التحقق من الجلسة أو التوكن ──────────────────────────────────────────────
$isAuthenticated = isset($_SESSION['remote_db_auth']) && $_SESSION['remote_db_auth'] === true;
$loginError = '';

if (!$isAuthenticated && isset($_POST['token'])) {
    $providedToken = trim($_POST['token']);
    if (hash_equals($support_token, $providedToken)) {
        $_SESSION['remote_db_auth'] = true;
        $_SESSION['remote_db_auth_time'] = time();
        $isAuthenticated = true;
        if ($conn) {
            $ip = $conn->real_escape_string($_SERVER['REMOTE_ADDR'] ?? 'unknown');
            @$conn->query("INSERT IGNORE INTO `system_log` (`action`, `details`, `user`, `created_at`)
                           VALUES ('remote_db_access', 'دخول عن بُعد من IP: $ip', 'support_engineer', NOW())");
        }
    } else {
        $loginError = 'رمز المرور غير صحيح!';
        sleep(2);
    }
}

// انتهاء صلاحية الجلسة (8 ساعات)
if ($isAuthenticated && isset($_SESSION['remote_db_auth_time'])) {
    if (time() - $_SESSION['remote_db_auth_time'] > 28800) {
        session_destroy();
        $isAuthenticated = false;
        $loginError = 'انتهت صلاحية الجلسة، يرجى تسجيل الدخول مجدداً.';
    }
}

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: remote_db.php');
    exit;
}

// توجيه إلى Adminer
if ($isAuthenticated && isset($_GET['open_adminer'])) {
    header('Location: adminer.php?server=127.0.0.1%3A3307&username=root&db=aqnex_pos');
    exit;
}

// معلومات قاعدة البيانات
$dbInfo = ['version' => 'غير متاح', 'tables' => 0, 'size' => 'غير متاح'];
if ($conn) {
    $ver = $conn->query("SELECT VERSION() as v");
    if ($ver && $row = $ver->fetch_assoc()) $dbInfo['version'] = $row['v'];
    $tbl = $conn->query("SELECT COUNT(*) as cnt FROM information_schema.TABLES WHERE table_schema = 'aqnex_pos'");
    if ($tbl && $row = $tbl->fetch_assoc()) $dbInfo['tables'] = $row['cnt'];
    $sz = $conn->query("SELECT ROUND(SUM(data_length+index_length)/1024/1024,2) as s FROM information_schema.TABLES WHERE table_schema='aqnex_pos'");
    if ($sz && $row = $sz->fetch_assoc()) $dbInfo['size'] = ($row['s'] ?? 0) . ' MB';
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>إدارة قاعدة البيانات عن بُعد - AQNEX POS</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<style>
@import url('https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800&display=swap');
:root{--bg:#0d1117;--surface:#161b22;--surface2:#21262d;--border:#30363d;--accent:#1d6bff;--accent-glow:rgba(29,107,255,.35);--green:#3fb950;--red:#f85149;--yellow:#d29922;--text:#e6edf3;--muted:#7d8590;--radius:12px}
*{box-sizing:border-box;margin:0;padding:0}
body{background:var(--bg);font-family:'Tajawal',sans-serif;color:var(--text);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
/* ── Login ── */
.login-wrap{width:100%;max-width:440px}
.brand{text-align:center;margin-bottom:32px}
.brand-icon{width:64px;height:64px;background:linear-gradient(135deg,var(--accent),#5b5bd6);border-radius:16px;display:flex;align-items:center;justify-content:center;margin:0 auto 14px;font-size:28px;box-shadow:0 0 30px var(--accent-glow)}
.brand-title{font-size:1.6rem;font-weight:800;background:linear-gradient(135deg,#e6edf3,#7d8590);-webkit-background-clip:text;-webkit-text-fill-color:transparent}
.brand-sub{color:var(--muted);font-size:.9rem;margin-top:4px}
.card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:32px;box-shadow:0 16px 48px rgba(0,0,0,.4)}
.form-group{margin-bottom:20px}
.form-label{display:block;font-weight:600;font-size:.9rem;color:var(--muted);margin-bottom:8px}
.form-control{width:100%;background:var(--surface2);border:1px solid var(--border);border-radius:8px;color:var(--text);padding:12px 16px;font-size:1rem;font-family:'Tajawal',monospace;transition:border-color .2s,box-shadow .2s;letter-spacing:2px;text-align:center}
.form-control:focus{outline:none;border-color:var(--accent);box-shadow:0 0 0 3px var(--accent-glow)}
.btn-primary{width:100%;background:linear-gradient(135deg,var(--accent),#5b5bd6);border:none;border-radius:8px;color:#fff;font-family:'Tajawal',sans-serif;font-size:1rem;font-weight:700;padding:13px;cursor:pointer;transition:opacity .2s,transform .1s;box-shadow:0 4px 15px var(--accent-glow)}
.btn-primary:hover{opacity:.9}.btn-primary:active{transform:scale(.98)}
.alert-danger{background:rgba(248,81,73,.15);border:1px solid rgba(248,81,73,.3);border-radius:8px;padding:12px 16px;color:var(--red);font-size:.9rem;margin-bottom:20px;display:flex;align-items:center;gap:8px}
/* ── Dashboard ── */
.dashboard-wrap{width:100%;max-width:900px}
.top-bar{display:flex;align-items:center;justify-content:space-between;margin-bottom:28px;flex-wrap:wrap;gap:12px}
.top-bar-brand{display:flex;align-items:center;gap:14px}
.top-bar-icon{width:48px;height:48px;background:linear-gradient(135deg,var(--accent),#5b5bd6);border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:22px;box-shadow:0 0 20px var(--accent-glow)}
.top-bar-title{font-size:1.3rem;font-weight:800}
.top-bar-sub{font-size:.8rem;color:var(--muted)}
.btn-logout{background:var(--surface2);border:1px solid var(--border);border-radius:8px;color:var(--muted);font-family:'Tajawal',sans-serif;font-size:.9rem;padding:8px 18px;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:6px;transition:border-color .2s,color .2s}
.btn-logout:hover{border-color:var(--red);color:var(--red)}
.stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px;margin-bottom:24px}
.stat-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:20px;display:flex;align-items:center;gap:16px}
.stat-icon{width:44px;height:44px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0}
.stat-icon.green{background:rgba(63,185,80,.15);color:var(--green)}
.stat-icon.blue{background:rgba(29,107,255,.15);color:var(--accent)}
.stat-icon.yellow{background:rgba(210,153,34,.15);color:var(--yellow)}
.stat-icon.red{background:rgba(248,81,73,.15);color:var(--red)}
.stat-value{font-size:1.2rem;font-weight:800}
.stat-label{font-size:.8rem;color:var(--muted)}
.actions-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:16px;margin-bottom:24px}
.action-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:24px;text-decoration:none;color:var(--text);transition:border-color .2s,transform .15s,box-shadow .2s;display:flex;flex-direction:column;gap:12px}
.action-card:hover{border-color:var(--accent);transform:translateY(-2px);box-shadow:0 8px 24px rgba(29,107,255,.15);color:var(--text)}
.action-icon{width:52px;height:52px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:24px}
.action-icon.blue{background:rgba(29,107,255,.15);color:var(--accent)}
.action-icon.green{background:rgba(63,185,80,.15);color:var(--green)}
.action-icon.yellow{background:rgba(210,153,34,.15);color:var(--yellow)}
.action-icon.red{background:rgba(248,81,73,.15);color:var(--red)}
.action-title{font-size:1rem;font-weight:700}
.action-desc{font-size:.82rem;color:var(--muted);line-height:1.5}
.info-box{background:rgba(29,107,255,.08);border:1px solid rgba(29,107,255,.25);border-radius:10px;padding:16px 20px;font-size:.88rem;color:var(--muted);line-height:1.7}
.info-box strong{color:var(--text)}
.info-box code{background:var(--surface2);border:1px solid var(--border);border-radius:4px;padding:2px 8px;font-size:.83rem;color:#79c0ff;font-family:'Courier New',monospace}
.dot{width:8px;height:8px;border-radius:50%;display:inline-block;margin-left:6px}
.dot.on{background:var(--green);box-shadow:0 0 6px var(--green)}
.dot.off{background:var(--red);box-shadow:0 0 6px var(--red)}
</style>
</head>
<body>

<?php if (!$isAuthenticated): ?>
<div class="login-wrap">
  <div class="brand">
    <div class="brand-icon"><i class="bi bi-database-lock"></i></div>
    <div class="brand-title">AQNEX POS</div>
    <div class="brand-sub">إدارة قاعدة البيانات عن بُعد</div>
  </div>
  <div class="card">
    <?php if ($loginError): ?><div class="alert-danger"><i class="bi bi-exclamation-triangle-fill"></i><?= htmlspecialchars($loginError) ?></div><?php endif; ?>
    <form method="POST">
      <div class="form-group">
        <label class="form-label">🔑 رمز الدخول (Support Token)</label>
        <input type="password" name="token" class="form-control" placeholder="أدخل رمز المرور" autofocus required autocomplete="off">
      </div>
      <button type="submit" class="btn-primary"><i class="bi bi-shield-lock-fill"></i> تأكيد الدخول</button>
    </form>
    <div style="margin-top:20px;padding-top:16px;border-top:1px solid var(--border);text-align:center">
      <p style="font-size:.8rem;color:var(--muted)">هذه الصفحة مخصصة لمهندسي الدعم الفني فقط.<br>للحصول على رمز الدخول، تواصل مع AQNEX Business Solutions.</p>
    </div>
  </div>
</div>

<?php else: ?>
<div class="dashboard-wrap">
  <div class="top-bar">
    <div class="top-bar-brand">
      <div class="top-bar-icon"><i class="bi bi-database-gear"></i></div>
      <div>
        <div class="top-bar-title">إدارة قاعدة البيانات عن بُعد</div>
        <div class="top-bar-sub">AQNEX POS — Support Engineer Panel</div>
      </div>
    </div>
    <a href="?logout=1" class="btn-logout"><i class="bi bi-box-arrow-right"></i> تسجيل الخروج</a>
  </div>

  <div class="stats-grid">
    <div class="stat-card">
      <div class="stat-icon <?= $conn ? 'green' : 'red' ?>"><i class="bi bi-database-<?= $conn ? 'check' : 'x' ?>"></i></div>
      <div>
        <div class="stat-value"><span class="dot <?= $conn ? 'on' : 'off' ?>"></span><?= $conn ? 'متصل' : 'غير متصل' ?></div>
        <div class="stat-label">حالة قاعدة البيانات</div>
      </div>
    </div>
    <?php if ($conn): ?>
    <div class="stat-card">
      <div class="stat-icon blue"><i class="bi bi-table"></i></div>
      <div><div class="stat-value"><?= $dbInfo['tables'] ?></div><div class="stat-label">عدد الجداول</div></div>
    </div>
    <div class="stat-card">
      <div class="stat-icon yellow"><i class="bi bi-hdd"></i></div>
      <div><div class="stat-value"><?= $dbInfo['size'] ?></div><div class="stat-label">حجم قاعدة البيانات</div></div>
    </div>
    <div class="stat-card">
      <div class="stat-icon green"><i class="bi bi-server"></i></div>
      <div><div class="stat-value" style="font-size:.85rem"><?= htmlspecialchars($dbInfo['version']) ?></div><div class="stat-label">إصدار MariaDB</div></div>
    </div>
    <?php endif; ?>
  </div>

  <div class="actions-grid">
    <a href="?open_adminer=1" class="action-card">
      <div class="action-icon blue"><i class="bi bi-layout-text-sidebar"></i></div>
      <div>
        <div class="action-title">📊 فتح Adminer</div>
        <div class="action-desc">واجهة كاملة لإدارة قاعدة البيانات، استعراض الجداول، تشغيل SQL، وتعديل البيانات مباشرة.<br><strong style="color:#79c0ff">بديل phpMyAdmin خفيف وقوي</strong></div>
      </div>
    </a>
    <a href="index.php?auth=<?= urlencode($support_token) ?>&act=export_db" class="action-card">
      <div class="action-icon green"><i class="bi bi-cloud-download"></i></div>
      <div>
        <div class="action-title">💾 تصدير نسخة احتياطية</div>
        <div class="action-desc">تحميل نسخة احتياطية كاملة من قاعدة البيانات بصيغة SQL.</div>
      </div>
    </a>
    <a href="index.php?auth=<?= urlencode($support_token) ?>" class="action-card">
      <div class="action-icon yellow"><i class="bi bi-tools"></i></div>
      <div>
        <div class="action-title">🔧 لوحة الدعم الفني</div>
        <div class="action-desc">أدوات الدعم الكاملة: تشخيص النظام، إعادة تعيين كلمة السر، إدارة الترخيص.</div>
      </div>
    </a>
    <?php if ($conn): ?>
    <a href="?run_check=1" class="action-card">
      <div class="action-icon red"><i class="bi bi-heart-pulse"></i></div>
      <div>
        <div class="action-title">🩺 فحص صحة القاعدة</div>
        <div class="action-desc">التحقق من سلامة الجداول والبيانات الأساسية في النظام.</div>
      </div>
    </a>
    <?php endif; ?>
  </div>

  <div class="info-box">
    <i class="bi bi-info-circle-fill" style="color:var(--accent)"></i>
    <strong> كيفية الوصول من الخارج:</strong><br>
    اطلب من العميل إعطاءك عنوان IP جهازه ثم افتح:<br>
    <code>http://[IP-العميل]:8181/support_tools/remote_db.php</code><br><br>
    إذا كان العميل خلف راوتر، يجب تفعيل <strong>Port Forwarding</strong> على المنفذ <code>8181</code>،
    أو استخدام أداة مثل <strong>ngrok</strong> على جهاز العميل لفتح نفق آمن.<br><br>
    <strong>رمز الدخول:</strong> يُضبط من <code>الإعدادات ← إعدادات الدعم الفني</code> — الافتراضي: <code>123</code>
  </div>
</div>

<?php if ($conn && isset($_GET['run_check'])):
  $checks = [];
  foreach (['products','purchases','purchase_items','sales','customers','suppliers','treasury','accounting_journal'] as $t) {
      $r = $conn->query("SELECT COUNT(*) as cnt FROM `$t`");
      $checks[] = ['t' => $t, 'cnt' => $r ? $r->fetch_assoc()['cnt'] : '-', 'ok' => (bool)$r];
  }
?>
<div style="position:fixed;bottom:20px;left:20px;right:20px;max-width:700px;margin:auto;background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:20px;max-height:300px;overflow-y:auto;z-index:999;box-shadow:0 8px 32px rgba(0,0,0,.5)">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
    <strong><i class="bi bi-heart-pulse" style="color:var(--green)"></i> نتيجة فحص الجداول</strong>
    <a href="?" style="color:var(--muted);text-decoration:none;font-size:1.3rem">✕</a>
  </div>
  <table style="width:100%;border-collapse:collapse;font-size:.85rem">
    <thead><tr style="color:var(--muted)"><th style="text-align:right;padding:6px">الجدول</th><th style="text-align:center;padding:6px">السجلات</th><th style="text-align:center;padding:6px">الحالة</th></tr></thead>
    <tbody>
    <?php foreach ($checks as $c): ?>
    <tr style="border-top:1px solid var(--border)">
      <td style="padding:6px;font-family:monospace"><?= $c['t'] ?></td>
      <td style="padding:6px;text-align:center"><?= $c['cnt'] ?></td>
      <td style="padding:6px;text-align:center"><?= $c['ok'] ? '<span style="color:var(--green)"><i class="bi bi-check-circle-fill"></i> سليم</span>' : '<span style="color:var(--red)"><i class="bi bi-x-circle-fill"></i> مفقود!</span>' ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php endif; ?>
</body>
</html>
