<?php
/**
 * شاشة كشف التلاعب بوقت وتاريخ النظام AQNEX Anti-Tamper Error Screen
 */
$dir_prefix = '../';
require_once(__DIR__ . '/../includes/connect.php');

// استعلام لجلب آخر توقيت تم تشغيل النظام فيه بنجاح
$res = $conn->query("SELECT last_run_time FROM system_time_check LIMIT 1");
$lastLog = ($res && $res->num_rows > 0) ? $res->fetch_assoc() : null;
$lastRunTime = $lastLog ? $lastLog['last_run_time'] : 'غير متوفر';
$currentTime = date('Y-m-d H:i:s');
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تنبيه أمني: تلاعب بالوقت - AQNEX POS</title>
    <link rel="stylesheet" type="text/css" href="../files/bower_components/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" type="text/css" href="../files/bower_components/font-awesome/css/font-awesome.min.css">
    <link rel="stylesheet" type="text/css" href="../files/bootstrap-icons/bootstrap-icons.min.css">
    <link rel="stylesheet" type="text/css" href="../css/custom.css">
    <style>
        body {
            background-color: var(--body-bg) !important;
            min-height: 100vh;
            color: var(--text-color) !important;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .error-card {
            max-width: 600px;
            width: 100%;
            background: #fff !important;
            border: 1px solid var(--border-color) !important;
            padding: 40px;
            text-align: center;
        }
        .warning-icon {
            font-size: 4.5rem;
            color: var(--accent-danger) !important;
            margin-bottom: 20px;
        }
        h3 {
            color: var(--accent-danger) !important;
            font-weight: 700;
            margin-bottom: 15px;
        }
        .desc-text {
            color: var(--text-color) !important;
            font-size: 0.95rem;
            line-height: 1.6;
            margin-bottom: 30px;
        }
        .time-badge {
            background: #f8fafc !important;
            border: 1px solid var(--border-color) !important;
            padding: 15px;
            margin-bottom: 10px;
            font-family: monospace;
            font-size: 1rem;
            color: var(--accent-danger) !important;
            font-weight: bold;
        }
        .btn-retry {
            background-color: var(--accent-danger) !important;
            color: #fff !important;
            font-weight: 700;
            border: none !important;
            padding: 12px 30px !important;
            cursor: pointer;
            transition: background-color 0.2s ease;
            width: 100%;
        }
        .btn-retry:hover {
            background-color: #a20f32 !important;
        }
    </style>
</head>
<body>

<div class="error-card">
    <div class="warning-icon">
        <i class="fa fa-clock-o"></i>
    </div>
    
    <h3>تم تجميد النظام! كشف تلاعب بالوقت</h3>
    
    <p class="desc-text text-justify">
        تنبيه أمني: تم اكتشاف تراجع في ساعة وتوقيت نظام التشغيل الخاص بك. لحماية سلامة الترخيص التجاري، وسجلات مبيعات وحسابات النظام الحساسة، تم قفل التطبيق تلقائياً لمنع أي عمليات جديدة.
    </p>

    <div class="row text-right mb-4">
        <div class="col-md-6 mb-2">
            <label class="small text-secondary font-weight-bold d-block mb-1" style="color:#94a3b8;">آخر تشغيل مسجل بالنظام:</label>
            <div class="time-badge">
                <?php echo $lastRunTime; ?>
            </div>
        </div>
        <div class="col-md-6 mb-2">
            <label class="small text-secondary font-weight-bold d-block mb-1">توقيت جهازك الحالي:</label>
            <div class="time-badge" style="color: var(--accent-info) !important;">
                <?php echo $currentTime; ?>
            </div>
        </div>
    </div>

    <div class="text-right p-3 mb-4" style="background: #fef2f2; border: 1px solid #fca5a5;">
        <h6 class="font-weight-bold" style="color: #b91c1c;"><i class="fa fa-question-circle ml-1"></i> كيف يمكنني حل هذه المشكلة؟</h6>
        <span class="small d-block text-secondary" style="color: #7f1d1d; line-height: 1.5;">
            1. قم بمزامنة ساعة الكمبيوتر الخاص بك لتتوافق مع التوقيت الفعلي الحالي للبلاد.<br>
            2. تأكد من أن السنة والشهر واليوم والساعة مضبوطين بدقة.<br>
            3. بعد تعديل توقيت جهازك، اضغط على زر التحديث في الأسفل لإعادة الفحص والتشغيل.
        </span>
    </div>

    <button onclick="window.location.reload();" class="btn btn-retry w-100">
        <i class="fa fa-refresh ml-2"></i> تحديث الصفحة وإعادة الفحص
    </button>
    
    <div class="mt-3">
        <small class="text-muted">
            إذا استمرت المشكلة، يرجى التواصل مع فريق الدعم الفني لشركة AQNEX لتقديم المساعدة.
        </small>
    </div>
</div>

</body>
</html>
