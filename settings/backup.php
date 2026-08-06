<?php
// settings/backup.php
$module = 'settings';
$dir_prefix = '../';

// تصدير النسخة الاحتياطية قبل تحميل رأس الصفحة لمنع حدوث مشاكل في الـ Headers
if (isset($_GET['export'])) {
    $servername = "localhost";
    $username = "root";
    $password = "";
    $dbname = "aqnex_pos";

    $conn = new \mysqli($servername, $username, $password, $dbname, 3307);
    if ($conn->connect_error) {
        die("فشل الاتصال بقاعدة البيانات: " . $conn->connect_error);
    }
    $conn->set_charset("utf8mb4");

    if (ob_get_level()) {
        ob_end_clean();
    }

    $backup_name = 'aqnex_pos_backup_' . date('Y-m-d_H-i-s') . '.sql';

    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $backup_name . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    echo "-- AQNEX POS Database Backup\n";
    echo "-- Database: aqnex_pos\n";
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
            $create_sql = preg_replace('/CREATE TABLE/i', 'CREATE TABLE IF NOT EXISTS', $create_row[1]);
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

if (!$is_admin && !sidebar_has_access('settings')) {
    echo "<div class='container mt-5 text-right'><div class='alert alert-danger'>غير مسموح لك بالوصول إلى هذه الصفحة.</div></div>";
    require_once(__DIR__ . '/../includes/footer.php');
    exit;
}

$message = '';
$message_type = 'success';

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

<title>النسخ الاحتياطي واستعادة البيانات - AQNEX POS</title>
<link rel="stylesheet" href="<?php echo $prefix; ?>assets/css/settings.css">

<div class="settings-shell">
    <div class="row mb-3 no-print align-items-center">
        <div class="col-md-7">
            <span class="eyebrow">إعدادات النظام وإدارة الموارد</span>
            <h3 class="mb-1">
                <span class="icon-chip"><i class="bi bi-cloud-arrow-up"></i></span>
                النسخ الاحتياطي واستعادة البيانات
            </h3>
            <p class="text-muted small mb-0">حماية وتصدير نسخة احتياطية كاملة من قاعدة البيانات واستعادتها بأمان وقت الحاجة.</p>
        </div>
        <div class="col-md-5 text-left">
            <a href="../home.php" class="btn-formal-secondary text-decoration-none">
                <i class="bi bi-arrow-right-short ml-1"></i> العودة للرئيسية
            </a>
        </div>
    </div>

    <div class="row justify-content-center no-print">
        <div class="col-lg-12">
            
            <?php if (!empty($message)): ?>
                <div class="alert-formal is-<?php echo ($message_type === 'danger') ? 'error' : $message_type; ?> mb-4">
                    <i class="bi <?php echo ($message_type === 'success') ? 'bi-check-circle' : 'bi-exclamation-triangle'; ?> ml-1"></i>
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <!-- Shared Sub-Navigation Menu -->
            <?php 
            $active_tab = 'backup'; 
            require_once 'settings_nav.php'; 
            ?>

            <div class="tab-content tab-content-custom mb-5">
                <div class="tab-pane-inner">
                    <h5 class="section-heading">إدارة وتوليد النسخ الاحتياطية واسترجاع السجلات</h5>

                    <div class="row">
                        <!-- Export Section -->
                        <div class="col-md-6 mb-4">
                            <div class="formal-card h-100">
                                <div class="formal-card-head is-accent">
                                    <i class="bi bi-cloud-arrow-down ml-1 text-primary"></i> تصدير نسخة احتياطية (Export SQL)
                                </div>
                                <div class="formal-card-body d-flex flex-column justify-content-between">
                                    <div>
                                        <p class="small text-muted mb-3" style="line-height:1.6;">
                                            يقوم النظام بإنشاء ملف استعلامات SQL متكامل يتضمن هيكل الجداول وبيانات السجلات بصيغة <code>REPLACE INTO</code>.
                                        </p>
                                        <div class="p-3 mb-4 rounded small" style="background: var(--good-soft); border: 1px solid #bbf7d0; color: var(--good);">
                                            <i class="bi bi-shield-check ml-1"></i>
                                            <strong>أمان واستقرار:</strong> تضمن صيغة الاستبدال المحافظة على دمج البيانات وحمايتها من التكرار أو الفقدان عند إعادة الاستيراد.
                                        </div>
                                    </div>
                                    <div class="text-center pt-2">
                                        <a href="?export=1" class="btn-formal-primary justify-content-center btn-block">
                                            <i class="bi bi-download ml-1"></i> إنشاء وتحميل ملف النسخة الاحتياطية (.sql)
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Restore Section -->
                        <div class="col-md-6 mb-4">
                            <div class="formal-card h-100">
                                <div class="formal-card-head is-accent">
                                    <i class="bi bi-cloud-arrow-up ml-1 text-primary"></i> استعادة نسخة احتياطية (Restore SQL)
                                </div>
                                <div class="formal-card-body">
                                    <form method="post" enctype="multipart/form-data">
                                        <p class="small text-muted mb-3" style="line-height:1.6;">
                                            اختر ملف نسخة احتياطية بصيغة <strong>.sql</strong> تم تصديره مسبقاً لاسترجاع بيانات السجلات.
                                        </p>
                                        
                                        <div class="form-group mb-3">
                                            <div class="p-4 text-center border rounded cursor-pointer" id="upload-wrapper" onclick="document.getElementById('backup_file').click();" style="background: var(--surface-soft); border-style: dashed !important; border-color: var(--line) !important;">
                                                <i class="bi bi-file-earmark-arrow-up text-primary" id="upload-icon" style="font-size: 2.2rem;"></i>
                                                <h6 class="font-weight-bold mt-2 mb-1" id="upload-text" style="font-size: 13.5px; color: var(--ink-900);">اضغط هنا أو اسحب الملف للرفع</h6>
                                                <span class="small text-muted d-block" id="file-details">ملفات SQL فقط (.sql)</span>
                                                <input type="file" name="backup_file" id="backup_file" style="display: none;" accept=".sql" onchange="fileSelected(this)">
                                            </div>
                                        </div>

                                        <button type="submit" name="restore" class="btn-formal-success btn-block justify-content-center" onclick="return confirm('تنبيه: سيتم استعادة البيانات واستبدال السجلات الحالية بالمحتوى المرفق. هل تريد الاستمرار؟')">
                                            <i class="bi bi-play-circle ml-1"></i> بدء استعادة البيانات
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
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
        wrapper.style.borderColor = 'var(--good)';
        wrapper.style.backgroundColor = 'var(--good-soft)';
        icon.className = 'bi bi-file-earmark-check-fill text-success';
        text.innerText = 'تم اختيار الملف بنجاح!';
        details.innerHTML = `<strong>الاسم:</strong> ${file.name} <br> <strong>الحجم:</strong> ${(file.size / 1024).toFixed(2)} كيلوبايت`;
    }
}

const dropZone = document.getElementById('upload-wrapper');
const fileInput = document.getElementById('backup_file');

if (dropZone && fileInput) {
    ['dragenter', 'dragover'].forEach(eventName => {
        dropZone.addEventListener(eventName, (e) => {
            e.preventDefault();
            dropZone.style.borderColor = 'var(--accent)';
            dropZone.style.backgroundColor = 'var(--accent-soft)';
        }, false);
    });

    ['dragleave', 'drop'].forEach(eventName => {
        dropZone.addEventListener(eventName, (e) => {
            e.preventDefault();
            if (eventName === 'drop') {
                const dt = e.dataTransfer;
                fileInput.files = dt.files;
                fileSelected(fileInput);
            } else {
                dropZone.style.borderColor = 'var(--line)';
                dropZone.style.backgroundColor = 'var(--surface-soft)';
            }
        }, false);
    });
}
</script>

<?php require_once(__DIR__ . '/../includes/footer.php'); ?>
