<?php
require_once __DIR__ . '/../includes/connect.php';

// جلب رمز الدعم الفني الموثق من الإعدادات بقاعدة البيانات
$res_s = $conn->query("SELECT support_token FROM settings WHERE id = 1 LIMIT 1");
$support_token = ($res_s && $row_s = $res_s->fetch_assoc()) ? ($row_s['support_token'] ?? '') : '';
if (empty($support_token)) {
    $support_token = '123';
}

$auth = $_GET['auth'] ?? '';
if (!is_string($auth) || !hash_equals($support_token, $auth)) {
    header($_SERVER['SERVER_PROTOCOL'] . ' 404 Not Found');
    echo '404 Not Found';
    exit;
}

$adminerPath = __DIR__ . '/adminer.php';
$use_adminer = intval($_GET['use_adminer'] ?? 0);
if ($use_adminer === 1 && file_exists($adminerPath)) {
    // إذا كان أدمنر موجوداً، قم بتشغيله كالمعتاد
    $configPath = __DIR__ . '/../app/Config/config.php';
    if (file_exists($configPath)) {
        $config = require $configPath;
        $dbConfig = $config['db'] ?? [];
        $serverHost = $dbConfig['host'] ?? 'localhost';
        $serverPort = (int)($dbConfig['port'] ?? 3306);
        $dbName = $dbConfig['name'] ?? 'aqnex_pos';
        $dbUser = $dbConfig['user'] ?? 'root';
        $dbPass = $dbConfig['pass'] ?? '';
        
        if ($serverHost === 'localhost') {
            $serverHost = '127.0.0.1';
        }
        $server = $serverHost . ':' . $serverPort;
        $_GET['server'] = $server;
        $_GET['username'] = $dbUser;
        $_GET['db'] = $dbName;
    }
    require_once $adminerPath;
    exit;
}

// ==========================================
// أوفلاين - أداة دعم محلي بديلة (Fallback Utility)
// ==========================================


// ==========================================
// العمليات التلقائية لقاعدة البيانات (CRUD)
// ==========================================
$crud_table = $_GET['tbl'] ?? '';
$crud_pk = $_GET['pk'] ?? '';
$crud_val = $_GET['val'] ?? '';
$crud_act = $_GET['act'] ?? '';
$crud_msg = '';

if (!empty($crud_act)) {
    if ($crud_act === 'del') {
        $table_esc = $conn->real_escape_string($crud_table);
        $pk_esc = $conn->real_escape_string($crud_pk);
        $val_esc = $conn->real_escape_string($crud_val);
        if ($conn->query("DELETE FROM `$table_esc` WHERE `$pk_esc` = '$val_esc'")) {
            header("Location: index.php?auth=" . urlencode($auth) . "&msg=deleted");
            exit;
        } else {
            $crud_msg = "خطأ أثناء الحذف: " . $conn->error;
        }
    }

    // إضافة عمود في جدول
    if ($crud_act === 'add_column') {
        $table_esc = $conn->real_escape_string($crud_table);
        $col_name = $conn->real_escape_string($_POST['col_name'] ?? '');
        $col_type = trim($_POST['col_type'] ?? 'VARCHAR(255)');
        $col_null = (isset($_POST['col_null']) && $_POST['col_null']) ? 'NULL' : 'NOT NULL';
        $col_default = isset($_POST['col_default']) && $_POST['col_default'] !== '' ? "DEFAULT '" . $conn->real_escape_string($_POST['col_default']) . "'" : '';
        if (empty($col_name) || empty($col_type)) {
            $crud_msg = 'اسم العمود أو نوع العمود غير صالح.';
        } else {
            $sql = "ALTER TABLE `$table_esc` ADD COLUMN `" . $col_name . "` " . $col_type . " $col_null $col_default";
            if ($conn->query($sql)) {
                header("Location: index.php?auth=" . urlencode($auth) . "&msg=column_added&tbl=" . urlencode($crud_table));
                exit;
            } else {
                $crud_msg = 'خطأ أثناء إضافة العمود: ' . $conn->error;
            }
        }
    }

    // حذف عمود من جدول
    if ($crud_act === 'drop_column') {
        $table_esc = $conn->real_escape_string($crud_table);
        $col_name = $conn->real_escape_string($_POST['col_name'] ?? '');
        if (empty($col_name)) {
            $crud_msg = 'لم يتم تحديد اسم العمود للحذف.';
        } else {
            $sql = "ALTER TABLE `$table_esc` DROP COLUMN `" . $col_name . "`";
            if ($conn->query($sql)) {
                header("Location: index.php?auth=" . urlencode($auth) . "&msg=column_dropped&tbl=" . urlencode($crud_table));
                exit;
            } else {
                $crud_msg = 'خطأ أثناء حذف العمود: ' . $conn->error;
            }
        }
    }
    
    if ($crud_act === 'save_edit') {
        $table_esc = $conn->real_escape_string($crud_table);
        $pk_esc = $conn->real_escape_string($crud_pk);
        $val_esc = $conn->real_escape_string($crud_val);
        
        $updates = [];
        foreach ($_POST as $col => $v) {
            if ($col === 'submit') continue;
            $col_esc = $conn->real_escape_string($col);
            $v_esc = $conn->real_escape_string($v);
            $updates[] = "`$col_esc` = '$v_esc'";
        }
        $update_str = implode(', ', $updates);
        if ($conn->query("UPDATE `$table_esc` SET $update_str WHERE `$pk_esc` = '$val_esc'")) {
            header("Location: index.php?auth=" . urlencode($auth) . "&msg=updated");
            exit;
        } else {
            $crud_msg = "خطأ أثناء التحديث: " . $conn->error;
        }
    }

    if ($crud_act === 'save_add') {
        $table_esc = $conn->real_escape_string($crud_table);
        
        $cols = [];
        $vals = [];
        foreach ($_POST as $col => $v) {
            if ($col === 'submit') continue;
            $cols[] = "`" . $conn->real_escape_string($col) . "`";
            $vals[] = "'" . $conn->real_escape_string($v) . "'";
        }
        $cols_str = implode(', ', $cols);
        $vals_str = implode(', ', $vals);
        if ($conn->query("INSERT INTO `$table_esc` ($cols_str) VALUES ($vals_str)")) {
            header("Location: index.php?auth=" . urlencode($auth) . "&msg=added");
            exit;
        } else {
            $crud_msg = "خطأ أثناء الإضافة: " . $conn->error;
        }
    }
}

$query = $_POST['sql_query'] ?? '';
$query_result = null;
$query_error = '';

if (!empty($query)) {
    // الحماية المبدئية - هذه الأداة للمشرف الفني فقط محلياً
    if ($conn->multi_query($query)) {
        $query_result = [];
        do {
            if ($result = $conn->store_result()) {
                $rows = [];
                while ($row = $result->fetch_assoc()) {
                    $rows[] = $row;
                }
                $query_result[] = $rows;
                $result->free();
            }
        } while ($conn->next_result());
    } else {
        $query_error = $conn->error;
    }
}

// استعلام لجلب قائمة الجداول وإحصائياتها
$tables = [];
$res_t = $conn->query("SHOW TABLE STATUS");
if ($res_t) {
    while ($row = $res_t->fetch_assoc()) {
        $tables[] = [
            'name' => $row['Name'],
            'rows' => $row['Rows'],
            'size' => round(($row['Data_length'] + $row['Index_length']) / 1024 / 1024, 2) . ' MB'
        ];
    }
}
?>
<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
    <meta charset="UTF-8">
    <title>أدوات الدعم الفني المحلي - أوفلاين</title>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-primary: #0f172a;
            --bg-secondary: #1e293b;
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
            --accent: #38bdf8;
            --success: #10b981;
            --danger: #ef4444;
        }
        body {
            font-family: 'Tajawal', sans-serif;
            background-color: var(--bg-primary);
            color: var(--text-main);
            margin: 0;
            padding: 0;
            display: flex;
            height: 100vh;
            overflow: hidden;
        }
        /* تصميم الشريط الجانبي */
        .sidebar {
            width: 300px;
            background-color: var(--bg-secondary);
            border-left: 1px solid rgba(255,255,255,0.05);
            display: flex;
            flex-direction: column;
            height: 100%;
        }
        .sidebar-header {
            padding: 20px;
            border-bottom: 1px solid rgba(255,255,255,0.05);
            text-align: center;
        }
        .sidebar-header h3 {
            margin: 0;
            font-size: 1.1rem;
            color: var(--accent);
        }
        .table-list {
            flex-grow: 1;
            overflow-y: auto;
            padding: 10px;
        }
        .table-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 12px;
            margin-bottom: 5px;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            color: var(--text-main);
            font-size: 0.9rem;
        }
        .table-item:hover {
            background-color: rgba(255,255,255,0.05);
            color: var(--accent);
        }
        .table-badge {
            background-color: rgba(255,255,255,0.1);
            padding: 2px 6px;
            border-radius: 10px;
            font-size: 0.75rem;
            color: var(--text-muted);
        }
        /* محتوى العرض الرئيسي */
        .main-content {
            flex-grow: 1;
            display: flex;
            flex-direction: column;
            height: 100%;
            overflow: hidden;
            padding: 20px;
            box-sizing: border-box;
        }
        .editor-container {
            background-color: var(--bg-secondary);
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
            border: 1px solid rgba(255,255,255,0.05);
        }
        textarea.sql-input {
            width: 100%;
            height: 120px;
            background-color: var(--bg-primary);
            border: 1px solid rgba(255,255,255,0.1);
            color: #38bdf8;
            font-family: monospace;
            padding: 10px;
            box-sizing: border-box;
            border-radius: 6px;
            resize: vertical;
            font-size: 0.95rem;
            outline: none;
        }
        textarea.sql-input:focus {
            border-color: var(--accent);
        }
        .btn-run {
            background-color: var(--accent);
            color: var(--bg-primary);
            border: none;
            padding: 10px 24px;
            font-weight: bold;
            border-radius: 6px;
            cursor: pointer;
            font-family: 'Tajawal', sans-serif;
            font-size: 0.95rem;
            transition: opacity 0.2s;
        }
        .btn-run:hover {
            opacity: 0.9;
        }
        .results-container {
            flex-grow: 1;
            overflow: auto;
            background-color: var(--bg-secondary);
            border-radius: 8px;
            padding: 15px;
            border: 1px solid rgba(255,255,255,0.05);
        }
        table.data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.85rem;
            text-align: right;
        }
        table.data-table th {
            background-color: var(--bg-primary);
            padding: 10px;
            border-bottom: 2px solid rgba(255,255,255,0.1);
            color: var(--accent);
        }
        table.data-table td {
            padding: 8px 10px;
            border-bottom: 1px solid rgba(255,255,255,0.05);
        }
        table.data-table tr:hover {
            background-color: rgba(255,255,255,0.02);
        }
        .alert {
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 15px;
        }
        .alert-danger {
            background-color: rgba(239, 68, 68, 0.15);
            border: 1px solid var(--danger);
            color: #fca5a5;
        }
        .quick-actions {
            margin-bottom: 15px;
            display: flex;
            gap: 10px;
        }
        .btn-quick {
            background-color: rgba(255,255,255,0.05);
            color: var(--text-main);
            border: 1px solid rgba(255,255,255,0.1);
            padding: 6px 12px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.8rem;
            font-family: 'Tajawal', sans-serif;
        }
        .btn-quick:hover {
            background-color: rgba(255,255,255,0.1);
            color: var(--accent);
        }
    </style>
</head>
<body>

<div class="sidebar">
    <div class="sidebar-header">
        <h3>الدعم الفني المحلي (Offline)</h3>
        <span style="font-size: 0.8rem; color: var(--text-muted);">أداة استعراض قاعدة البيانات البديلة</span>
    </div>
    <div class="table-list">
        <?php foreach ($tables as $t): ?>
            <div class="table-item" onclick="loadTableQuery('<?php echo htmlspecialchars($t['name']); ?>')">
                <span><?php echo htmlspecialchars($t['name']); ?></span>
                <span class="table-badge"><?php echo $t['rows']; ?> صف</span>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<div class="main-content">
    <div class="quick-actions" style="display: flex; flex-wrap: wrap; gap: 10px; align-items: center;">
        <button class="btn-quick" onclick="setQuery('SELECT * FROM settings WHERE id = 1;')">فحص الإعدادات العامة</button>
        <button class="btn-quick" onclick="setQuery('SELECT * FROM users LIMIT 10;')">فحص الحسابات والمستخدمين</button>
        <button class="btn-quick" onclick="setQuery('SELECT SUM(mony) FROM treasury;')">رصيد الصناديق الكلي</button>
        <button class="btn-quick" onclick="setQuery('SELECT * FROM inventory_log ORDER BY id DESC LIMIT 50;')">سجل حركة المخزون الأخير</button>
        <a href="index.php?auth=<?php echo urlencode($auth); ?>&use_adminer=1" class="btn-quick" style="text-decoration: none; background-color: var(--accent); color: var(--bg-primary); font-weight: bold; border: none; padding: 6px 15px; border-radius: 4px; display: inline-flex; align-items: center; gap: 5px;">🔧 تشغيل لوحة التحكم الكلاسيكية (Adminer)</a>
    </div>

    <div class="editor-container">
        <form method="POST" id="queryForm">
            <textarea class="sql-input" name="sql_query" id="sql_query" placeholder="اكتب استعلام SQL هنا... (مثال: SELECT * FROM products LIMIT 10)"><?php echo htmlspecialchars($query); ?></textarea>
            <div style="text-align: left; margin-top: 10px;">
                <button type="submit" class="btn-run">تشغيل الاستعلام</button>
            </div>
        </form>
    </div>

    <div class="results-container">
        <?php if (isset($_GET['msg'])): ?>
            <div style="background-color: var(--success); color: var(--bg-primary); padding: 10px; border-radius: 6px; margin-bottom: 15px; font-weight: bold;">
                <?php
                if ($_GET['msg'] === 'deleted') echo "✓ تم حذف الصف بنجاح!";
                if ($_GET['msg'] === 'updated') echo "✓ تم تحديث الصف بنجاح!";
                if ($_GET['msg'] === 'added') echo "✓ تم إضافة الصف بنجاح!";
                ?>
            </div>
        <?php endif; ?>
        <?php if (!empty($crud_msg)): ?>
            <div class="alert alert-danger">
                <?php echo htmlspecialchars($crud_msg); ?>
            </div>
        <?php endif; ?>

        <?php if ($crud_act === 'edit_form' || $crud_act === 'add_form'): 
            $table_esc = $conn->real_escape_string($crud_table);
            $pk_esc = $conn->real_escape_string($crud_pk);
            $val_esc = $conn->real_escape_string($crud_val);
            
            $row_data = [];
            if ($crud_act === 'edit_form') {
                $res_row = $conn->query("SELECT * FROM `$table_esc` WHERE `$pk_esc` = '$val_esc' LIMIT 1");
                if ($res_row) $row_data = $res_row->fetch_assoc();
            }
            
            $res_cols = $conn->query("SHOW COLUMNS FROM `$table_esc`");
        ?>
            <div style="background-color: var(--bg-secondary); padding: 20px; border-radius: 8px; border: 1px solid rgba(255,255,255,0.05); margin-bottom: 20px;">
                <h4 style="color: var(--accent); margin-top: 0;">
                    <?php echo $crud_act === 'edit_form' ? "تعديل صف في جدول `$crud_table`" : "إضافة صف جديد في جدول `$crud_table`"; ?>
                </h4>
                <form method="POST" action="index.php?auth=<?php echo urlencode($auth); ?>&act=<?php echo $crud_act === 'edit_form' ? 'save_edit' : 'save_add'; ?>&tbl=<?php echo urlencode($crud_table); ?>&pk=<?php echo urlencode($crud_pk); ?>&val=<?php echo urlencode($crud_val); ?>">
                    <?php while ($col = $res_cols->fetch_assoc()): 
                        $col_name = $col['Field'];
                        $is_pk = ($col['Key'] === 'PRI' || $col_name === $crud_pk);
                        $val_input = $row_data[$col_name] ?? '';
                    ?>
                        <div style="margin-bottom: 15px; text-align: right;">
                            <label style="display: block; font-weight: bold; margin-bottom: 5px; color: var(--text-muted);">
                                <?php echo htmlspecialchars($col_name); ?> 
                                <?php if ($is_pk) echo '<span style="color: var(--danger); font-size: 0.8rem;">(مفتاح أساسي)</span>'; ?>
                            </label>
                            <input type="text" name="<?php echo htmlspecialchars($col_name); ?>" 
                                   value="<?php echo htmlspecialchars($val_input); ?>" 
                                   style="width: 100%; height: 40px; background-color: var(--bg-primary); border: 1px solid rgba(255,255,255,0.1); color: var(--text-main); padding: 5px 10px; border-radius: 4px; box-sizing: border-box;"
                                   <?php if ($is_pk && $crud_act === 'edit_form') echo 'readonly'; ?>>
                        </div>
                    <?php endwhile; ?>
                    <div style="margin-top: 20px; text-align: left;">
                        <button type="submit" name="submit" class="btn-run" style="background-color: var(--success); color: var(--bg-primary);">حفظ التغييرات</button>
                        <a href="index.php?auth=<?php echo urlencode($auth); ?>" class="btn-quick" style="text-decoration: none; padding: 10px 20px; display: inline-block;">إلغاء</a>
                    </div>
                </form>
            </div>
        <?php endif; ?>

        <?php if ($crud_act === 'add_column_form' || $crud_act === 'drop_column_form'): 
            $table_esc = $conn->real_escape_string($crud_table);
            $res_cols = $conn->query("SHOW COLUMNS FROM `$table_esc`");
            $cols = [];
            if ($res_cols) {
                while ($c = $res_cols->fetch_assoc()) $cols[] = $c;
            }
        ?>
            <div style="background-color: var(--bg-secondary); padding: 20px; border-radius: 8px; border: 1px solid rgba(255,255,255,0.05); margin-bottom: 20px;">
                <h4 style="color: var(--accent); margin-top: 0;">
                    <?php echo $crud_act === 'add_column_form' ? "إضافة عمود جديد إلى جدول `$crud_table`" : "حذف عمود من جدول `$crud_table`"; ?>
                </h4>
                <?php if ($crud_act === 'add_column_form'): ?>
                    <form method="POST" action="index.php?auth=<?php echo urlencode($auth); ?>&act=add_column&tbl=<?php echo urlencode($crud_table); ?>">
                        <div style="margin-bottom: 12px; text-align: right;"><label style="font-weight:bold; color:var(--text-muted);">اسم العمود</label><input type="text" name="col_name" required style="width:100%; height:36px;" /></div>
                        <div style="margin-bottom: 12px; text-align: right;"><label style="font-weight:bold; color:var(--text-muted);">نوع العمود (مثال: VARCHAR(255), INT, TEXT)</label><input type="text" name="col_type" value="VARCHAR(255)" required style="width:100%; height:36px;" /></div>
                        <div style="margin-bottom: 12px; text-align: right;"><label style="font-weight:bold; color:var(--text-muted);"><input type="checkbox" name="col_null" value="1"> السماح بالقيم الفارغة (NULL)</label></div>
                        <div style="margin-bottom: 12px; text-align: right;"><label style="font-weight:bold; color:var(--text-muted);">قيمة افتراضية (اختياري)</label><input type="text" name="col_default" style="width:100%; height:36px;" /></div>
                        <div style="margin-top: 12px; text-align: left;"><button type="submit" class="btn-run" style="background-color:var(--accent); color:var(--bg-primary);">أضف العمود</button> <a href="index.php?auth=<?php echo urlencode($auth); ?>" class="btn-quick">إلغاء</a></div>
                    </form>
                <?php else: ?>
                    <form method="POST" action="index.php?auth=<?php echo urlencode($auth); ?>&act=drop_column&tbl=<?php echo urlencode($crud_table); ?>" onsubmit="return confirm('هل أنت متأكد من حذف هذا العمود؟ سيتم فقدان البيانات المحتواة فيه.');">
                        <div style="margin-bottom:12px; text-align:right;"><label style="font-weight:bold; color:var(--text-muted);">اختر العمود للحذف</label>
                            <select name="col_name" required style="width:100%; height:36px;">
                                <?php foreach ($cols as $c): ?>
                                    <option value="<?php echo htmlspecialchars($c['Field']); ?>"><?php echo htmlspecialchars($c['Field'] . ' — ' . $c['Type']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div style="margin-top: 12px; text-align: left;"><button type="submit" class="btn-run" style="background-color:var(--danger);">احذف العمود</button> <a href="index.php?auth=<?php echo urlencode($auth); ?>" class="btn-quick">إلغاء</a></div>
                    </form>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($query_error)): ?>
            <div class="alert alert-danger">
                <strong>خطأ في الاستعلام:</strong><br>
                <?php echo htmlspecialchars($query_error); ?>
            </div>
        <?php endif; ?>

        <?php if ($query_result !== null): ?>
            <?php foreach ($query_result as $index => $rows): ?>
                <?php
                // فحص ما إذا كان الاستعلام هو SELECT عادي لإظهار أزرار الإجراءات
                $tableName = '';
                $primaryKey = '';
                if (preg_match('/^\s*SELECT\s+\*\s+FROM\s+`?([a-zA-Z0-9_-]+)`?/i', $query, $matches)) {
                    $tableName = $matches[1];
                    $res_k = $conn->query("SHOW KEYS FROM `$tableName` WHERE Key_name = 'PRIMARY'");
                    if ($res_k && $row_k = $res_k->fetch_assoc()) {
                        $primaryKey = $row_k['Column_name'];
                    }
                }
                ?>
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                    <h4 style="color: var(--accent); margin: 0;">النتيجة #<?php echo $index + 1; ?> (عدد الصفوف: <?php echo count($rows); ?>)</h4>
                    <?php if (!empty($tableName) && !empty($primaryKey)): ?>
                        <a href="index.php?auth=<?php echo urlencode($auth); ?>&act=add_form&tbl=<?php echo urlencode($tableName); ?>&pk=<?php echo urlencode($primaryKey); ?>" class="btn-quick" style="text-decoration: none; display: inline-block; background-color: var(--success); color: var(--bg-primary); font-weight: bold;">+ إضافة صف جديد</a>
                        <a href="index.php?auth=<?php echo urlencode($auth); ?>&act=add_column_form&tbl=<?php echo urlencode($tableName); ?>" class="btn-quick" style="text-decoration: none; display: inline-block;">＋ إضافة عمود</a>
                        <a href="index.php?auth=<?php echo urlencode($auth); ?>&act=drop_column_form&tbl=<?php echo urlencode($tableName); ?>" class="btn-quick" style="text-decoration: none; display: inline-block;">− حذف عمود</a>
                    <?php endif; ?>
                </div>

                <?php if (empty($rows)): ?>
                    <p class="text-muted" style="margin-bottom: 20px;">تم تنفيذ الاستعلام بنجاح (لم يتم إرجاع أي صفوف أو تم التحديث بنجاح).</p>
                <?php else: ?>
                    <div style="overflow-x: auto; margin-bottom: 30px;">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <?php foreach (array_keys($rows[0]) as $col): ?>
                                        <th><?php echo htmlspecialchars($col); ?></th>
                                    <?php endforeach; ?>
                                    <?php if (!empty($tableName) && !empty($primaryKey)): ?>
                                        <th style="text-align: center;">الإجراءات</th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rows as $row): ?>
                                    <tr>
                                        <?php foreach ($row as $val): ?>
                                            <td><?php echo htmlspecialchars($val ?? 'NULL'); ?></td>
                                        <?php endforeach; ?>
                                        <?php if (!empty($tableName) && !empty($primaryKey)): ?>
                                            <td style="text-align: center;">
                                                <a href="index.php?auth=<?php echo urlencode($auth); ?>&act=edit_form&tbl=<?php echo urlencode($tableName); ?>&pk=<?php echo urlencode($primaryKey); ?>&val=<?php echo urlencode($row[$primaryKey]); ?>" style="color: var(--accent); margin-left: 12px; text-decoration: none; font-weight: bold;">تعديل</a>
                                                <a href="index.php?auth=<?php echo urlencode($auth); ?>&act=del&tbl=<?php echo urlencode($tableName); ?>&pk=<?php echo urlencode($primaryKey); ?>&val=<?php echo urlencode($row[$primaryKey]); ?>" onclick="return confirm('هل أنت متأكد من حذف هذا الصف؟')" style="color: var(--danger); text-decoration: none; font-weight: bold;">حذف</a>
                                            </td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        <?php else: 
            // إذا لم يتم كتابة استعلام، ولكن تم الضغط على نموذج إضافة/تعديل من الرابط المباشر
            if ($crud_act !== 'edit_form' && $crud_act !== 'add_form'):
        ?>
            <p style="color: var(--text-muted); text-align: center; margin-top: 50px;">اكتب استعلاماً في الأعلى أو اختر أحد الجداول من الشريط الجانبي لعرض محتوياته.</p>
        <?php 
            endif;
        endif; ?>
    </div>
</div>

<script>
function loadTableQuery(tableName) {
    document.getElementById('sql_query').value = 'SELECT * FROM `' + tableName + '` LIMIT 50;';
    document.getElementById('queryForm').submit();
}
function setQuery(sql) {
    document.getElementById('sql_query').value = sql;
    document.getElementById('queryForm').submit();
}
</script>

</body>
</html>
