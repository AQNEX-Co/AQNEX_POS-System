<?php
// settings/backup.php
$module = 'settings';
$dir_prefix = '../';

// تصدير النسخة الاحتياطية قبل تحميل رأس الصفحة لمنع حدوث مشاكل في الـ Headers
if (isset($_GET['export'])) {
    $servername = "localhost";
    $username = "root";
    $password = "";
    $dbname = "aq_pos";

    $conn = new \mysqli($servername, $username, $password, $dbname, 3307);
    if ($conn->connect_error) {
        die("فشل الاتصال بقاعدة البيانات: " . $conn->connect_error);
    }
    $conn->set_charset("utf8mb4");

    // تنظيف المخزن المؤقت للـ PHP
    if (ob_get_level()) {
        ob_end_clean();
    }

    $backup_name = 'aq_pos_backup_' . date('Y-m-d_H-i-s') . '.sql';

    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $backup_name . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    echo "-- AQNEX POS Database Backup\n";
    echo "-- Database: aq_pos\n";
    echo "-- Generated: " . date('Y-m-d H:i:s') . "\n";
    echo "-- --------------------------------------------------------\n\n";

    echo "SET FOREIGN_KEY_CHECKS = 0;\n";
    echo "SET NAMES utf8mb4;\n\n";

    $tables = [];
    $result = $conn->query("SHOW TABLES");
    if ($result) {
        while ($row = $result->fetch_row()) {
            $tables[] = $row[0];
        }
    }

    foreach ($tables as $table) {
        echo "-- --------------------------------------------------------\n";
        echo "-- Table structure for table `$table`\n";
        echo "-- --------------------------------------------------------\n\n";

        $create_res = $conn->query("SHOW CREATE TABLE `$table`");
        if ($create_res) {
            $create_row = $create_res->fetch_row();
            $create_sql = $create_row[1];
            // تحويل إلى CREATE TABLE IF NOT EXISTS
            $create_sql = preg_replace('/CREATE TABLE/i', 'CREATE TABLE IF NOT EXISTS', $create_sql);
            echo $create_sql . ";\n\n";
        }

        echo "-- Dumping data for table `$table`\n\n";

        $data_res = $conn->query("SELECT * FROM `$table`");
        if ($data_res) {
            $fields_info = $data_res->fetch_fields();
            $columns = [];
            foreach ($fields_info as $field) {
                $columns[] = "`" . $field->name . "`";
            }
            $columns_str = implode(', ', $columns);

            while ($row = $data_res->fetch_assoc()) {
                $values = [];
                foreach ($fields_info as $field) {
                    $val = $row[$field->name];
                    if ($val === null) {
                        $values[] = "NULL";
                    } else {
                        $escaped = $conn->real_escape_string($val);
                        $values[] = "'" . $escaped . "'";
                    }
                }
                $values_str = implode(', ', $values);
                echo "REPLACE INTO `$table` ($columns_str) VALUES ($values_str);\n";
            }
            echo "\n";
        }
    }

    echo "SET FOREIGN_KEY_CHECKS = 1;\n";
    $conn->close();
    exit;
}

require_once(__DIR__ . '/../includes/header.php');

// حماية الصفحة: المدير فقط يمكنه الدخول أو من لديه صلاحية إعدادات النظام
if (!$is_admin && !sidebar_has_access('settings')) {
    echo "<div class='container mt-5 text-right'><div class='alert alert-danger'>غير مسموح لك بالوصول إلى هذه الصفحة.</div></div>";
    require_once(__DIR__ . '/../includes/footer.php');
    exit;
}

$message = '';
$message_type = 'success';

// استيراد النسخة الاحتياطية
if (isset($_POST['restore'])) {
    if (isset($_FILES['backup_file']) && $_FILES['backup_file']['error'] === UPLOAD_ERR_OK) {
        $file_tmp = $_FILES['backup_file']['tmp_name'];
        $file_name = $_FILES['backup_file']['name'];
        $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

        if ($file_ext !== 'sql') {
            $message = "خطأ: يرجى رفع ملف بنوع SQL فقط (.sql).";
            $message_type = "danger";
        } else {
            $sql_content = file($file_tmp);
            $query_buffer = '';
            $success_count = 0;
            $error_count = 0;

            // تعطيل فحص المفاتيح الخارجية لتجنب القيود المؤقتة أثناء الاستيراد
            $conn->query("SET FOREIGN_KEY_CHECKS = 0;");
            $conn->query("SET NAMES utf8mb4;");

            foreach ($sql_content as $line) {
                $trimmed_line = trim($line);
                if (empty($trimmed_line) || strpos($trimmed_line, '--') === 0 || strpos($trimmed_line, '/*') === 0) {
                    continue;
                }

                $query_buffer .= $line;

                if (substr(rtrim($trimmed_line), -1) === ';') {
                    if ($conn->query($query_buffer)) {
                        $success_count++;
                    } else {
                        $error_count++;
                        // للاستكشاف في حال وجود خطأ كبير
                        // error_log("Backup Restore Error: " . $conn->error . " | Query: " . $query_buffer);
                    }
                    $query_buffer = '';
                }
            }

            $conn->query("SET FOREIGN_KEY_CHECKS = 1;");

            if ($error_count === 0) {
                $message = "تمت استعادة البيانات بنجاح تام! عدد العمليات الناجحة: $success_count.";
                $message_type = "success";
            } else {
                $message = "تمت الاستعادة بنجاح مع وجود $error_count أخطاء بسيطة. العمليات الناجحة: $success_count.";
                $message_type = "warning";
            }
        }
    } else {
        $message = "خطأ: يرجى اختيار ملف نسخة احتياطية (.sql) صالح أولاً.";
        $message_type = "danger";
    }
}
?>

<style>
    .backup-container {
        padding: 20px;
        background: #f8fafc;
        min-height: calc(100vh - 80px);
    }
    .backup-card {
        border: none;
        border-radius: 12px;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05);
        background: #ffffff;
        transition: transform 0.3s ease, box-shadow 0.3s ease;
        margin-bottom: 25px;
        overflow: hidden;
    }
    .backup-card:hover {
        box-shadow: 0 8px 30px rgba(0, 0, 0, 0.08);
    }
    .backup-header-gradient {
        background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
        color: #ffffff;
        padding: 20px 25px;
    }
    .backup-header-gradient-alt {
        background: linear-gradient(135deg, #0284c7 0%, #0369a1 100%);
        color: #ffffff;
        padding: 20px 25px;
    }
    .card-title-icon {
        font-size: 1.5rem;
        margin-left: 10px;
        vertical-align: middle;
    }
    .btn-backup {
        padding: 12px 24px;
        font-size: 1rem;
        font-weight: 600;
        border-radius: 8px;
        transition: all 0.2s ease;
    }
    .btn-export {
        background: linear-gradient(135deg, #0284c7 0%, #0369a1 100%);
        border: none;
        color: #ffffff;
    }
    .btn-export:hover {
        opacity: 0.9;
        transform: scale(1.02);
        color: #ffffff;
    }
    .btn-restore {
        background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
        border: none;
        color: #ffffff;
    }
    .btn-restore:hover {
        opacity: 0.9;
        transform: scale(1.02);
        color: #ffffff;
    }
    .file-upload-wrapper {
        border: 2px dashed #cbd5e1;
        border-radius: 10px;
        padding: 30px;
        text-align: center;
        background: #f8fafc;
        cursor: pointer;
        transition: all 0.3s ease;
    }
    .file-upload-wrapper:hover {
        border-color: #0284c7;
        background: #f0f9ff;
    }
    .file-upload-icon {
        font-size: 2.5rem;
        color: #94a3b8;
        margin-bottom: 10px;
    }
    .info-box {
        background-color: #f0fdf4;
        border-right: 4px solid #16a34a;
        color: #15803d;
        padding: 15px;
        border-radius: 4px;
        font-size: 0.9rem;
    }
</style>

<div class="backup-container text-right">
    <!-- عنوان الصفحة الرئيسي -->
    <div class="row mb-4">
        <div class="col-12">
            <h2 class="font-weight-bold" style="color: #1e293b;">
                <i class="bi bi-database card-title-icon" style="color: #0284c7;"></i>
                النسخ الاحتياطي واستعادة البيانات
            </h2>
            <p class="text-muted" style="font-size: 0.95rem;">
                إدارة وحماية بيانات النظام بالكامل. يمكنك تصدير نسخة احتياطية من جداولك أو استعادتها بأمان وتحديثها دون خطر فقدان بقية البيانات المدخلة.
            </p>
        </div>
    </div>

    <!-- رسائل التنبيه والنجاح -->
    <?php if (!empty($message)): ?>
        <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show rounded-lg shadow-sm border-0 mb-4" role="alert">
            <div class="d-flex align-items-center">
                <i class="bi <?php echo ($message_type === 'success') ? 'bi-check-circle-fill' : (($message_type === 'warning') ? 'bi-exclamation-triangle-fill' : 'bi-x-circle-fill'); ?> ml-2" style="font-size: 1.2rem;"></i>
                <div>
                    <?php echo htmlspecialchars($message); ?>
                </div>
            </div>
            <button type="text" class="close" data-dismiss="alert" aria-label="Close" style="outline: none;">
                <span aria-hidden="true">&times;</span>
            </button>
        </div>
    <?php endif; ?>

    <div class="row">
        <!-- قسم التصدير -->
        <div class="col-md-6">
            <div class="card backup-card">
                <div class="backup-header-gradient-alt">
                    <h5 class="m-0 font-weight-bold">
                        <i class="bi bi-cloud-arrow-down card-title-icon"></i>
                        تصدير نسخة احتياطية
                    </h5>
                </div>
                <div class="card-body p-4">
                    <p class="card-text text-muted" style="line-height: 1.6;">
                        يقوم النظام بإنشاء ملف استعلامات SQL يحتوي على هيكل الجداول والبيانات بصيغة <strong>REPLACE INTO</strong>.
                    </p>
                    <div class="info-box mb-4">
                        <i class="bi bi-info-circle-fill ml-1"></i>
                        <strong>ميزة هامة:</strong> صيغة REPLACE تضمن أنه عند استعادة هذا الملف مستقبلاً، سيتم استبدال البيانات المتطابقة بالمعرف (Primary Key) مع المحافظة على البيانات الجديدة الأخرى المضافة بعد التصدير دون حذفها.
                    </div>
                    <div class="text-center py-3">
                        <a href="?export=1" class="btn btn-export btn-backup shadow">
                            <i class="bi bi-download ml-1"></i>
                            إنشاء وتحميل ملف النسخة الاحتياطية (.sql)
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- قسم الاستعادة -->
        <div class="col-md-6">
            <div class="card backup-card">
                <div class="backup-header-gradient">
                    <h5 class="m-0 font-weight-bold">
                        <i class="bi bi-cloud-arrow-up card-title-icon"></i>
                        استعادة نسخة احتياطية
                    </h5>
                </div>
                <div class="card-body p-4">
                    <form method="post" enctype="multipart/form-data">
                        <p class="card-text text-muted" style="line-height: 1.6;">
                            حدد ملف نسخة احتياطية صالح بصيغة <strong>.sql</strong> تم تصديره مسبقاً من هذا النظام ليتم تركيبه واستعادة السجلات.
                        </p>
                        
                        <div class="form-group mb-4">
                            <div class="file-upload-wrapper" id="upload-wrapper" onclick="document.getElementById('backup_file').click();">
                                <i class="bi bi-file-earmark-arrow-up file-upload-icon" id="upload-icon"></i>
                                <h6 class="font-weight-bold" id="upload-text">اضغط هنا أو اسحب الملف للرفع</h6>
                                <p class="text-muted small m-0" id="file-details">ملفات SQL فقط (.sql)</p>
                                <input type="file" name="backup_file" id="backup_file" style="display: none;" accept=".sql" onchange="fileSelected(this)">
                            </div>
                        </div>

                        <div class="text-center">
                            <button type="submit" name="restore" class="btn btn-restore btn-backup shadow px-5" onclick="return confirm('تنبيه: سيتم استعادة البيانات واستبدال السجلات المشتركة الحالية بما يقابلها في ملف النسخة. هل تريد الاستمرار؟')">
                                <i class="bi bi-play-circle ml-1"></i>
                                بدء استعادة البيانات
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    function fileSelected(input) {
        const file = input.files[0];
        const wrapper = document.getElementById('upload-wrapper');
        const icon = document.getElementById('upload-icon');
        const text = document.getElementById('upload-text');
        const details = document.getElementById('file-details');

        if (file) {
            wrapper.style.borderColor = '#16a34a';
            wrapper.style.backgroundColor = '#f0fdf4';
            icon.className = 'bi bi-file-earmark-check-fill file-upload-icon';
            icon.style.color = '#16a34a';
            text.innerText = 'تم اختيار الملف بنجاح!';
            text.style.color = '#15803d';
            details.innerHTML = `<strong>الاسم:</strong> ${file.name} <br> <strong>الحجم:</strong> ${(file.size / 1024).toFixed(2)} كيلوبايت`;
        }
    }

    // إضافة ميزة السحب والإفلات للملفات
    const dropZone = document.getElementById('upload-wrapper');
    const fileInput = document.getElementById('backup_file');

    ['dragenter', 'dragover'].forEach(eventName => {
        dropZone.addEventListener(eventName, (e) => {
            e.preventDefault();
            dropZone.style.borderColor = '#0284c7';
            dropZone.style.backgroundColor = '#f0f9ff';
        }, false);
    });

    ['dragleave', 'drop'].forEach(eventName => {
        dropZone.addEventListener(eventName, (e) => {
            e.preventDefault();
            if (eventName === 'drop') {
                const dt = e.dataTransfer;
                const files = dt.files;
                fileInput.files = files;
                fileSelected(fileInput);
            } else {
                dropZone.style.borderColor = '#cbd5e1';
                dropZone.style.backgroundColor = '#f8fafc';
            }
        }, false);
    });
</script>

<?php
require_once(__DIR__ . '/../includes/footer.php');
?>
