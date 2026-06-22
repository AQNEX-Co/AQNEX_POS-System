<?php
$dir_prefix = '../';
$module = 'products';
require_once($dir_prefix . 'includes/header.php');

check_permission(['admin', 'inventory']);
$step = 1;
$error = '';
$success = '';
$headers = [];
$temp_file = $dir_prefix . 'files/tmp_import.csv';

if (isset($_POST['upload_csv'])) {
    if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] == UPLOAD_ERR_OK) {
        $file_type = pathinfo($_FILES['csv_file']['name'], PATHINFO_EXTENSION);
        if (strtolower($file_type) !== 'csv') {
            $error = 'الرجاء تحميل ملف بتنسيق CSV فقط.';
        } else {
            // نقل الملف إلى مجلد مؤقت للعمل عليه في الخطوات القادمة
            if (move_uploaded_file($_FILES['csv_file']['tmp_name'], $temp_file)) {
                // قراءة السطر الأول للحصول على الترويسات
                if (($handle = fopen($temp_file, "r")) !== FALSE) {
                    // كشف الترميز والفاصل
                    $first_line = fgets($handle);
                    fclose($handle);
                    
                    // الكشف عن الفاصلة (فاصلة عادية أو منقوطة)
                    $delimiter = (strpos($first_line, ';') !== false) ? ';' : ',';
                    
                    // قراءة الترويسات بشكل صحيح
                    if (($handle = fopen($temp_file, "r")) !== FALSE) {
                        $data = fgetcsv($handle, 1000, $delimiter);
                        if ($data) {
                            foreach ($data as $index => $col) {
                                // إزالة علامات UTF-8 BOM إذا وجدت
                                $col = preg_replace('/[\x{FEFF}\x{FFFE}]/u', '', $col);
                                $headers[$index] = trim($col);
                            }
                            $step = 2;
                        } else {
                            $error = 'تعذر قراءة بيانات ترويسة الملف.';
                            @unlink($temp_file);
                        }
                        fclose($handle);
                    }
                } else {
                    $error = 'تعذر فتح ملف الـ CSV المرفوع.';
                }
            } else {
                $error = 'حدث خطأ أثناء حفظ الملف المؤقت.';
            }
        }
    } else {
        $error = 'الرجاء اختيار ملف CSV للتحميل.';
    }
} elseif (isset($_POST['process_import'])) {
    // خطوة 3: معالجة الاستيراد
    $map_name = intval($_POST['map_name']);
    $map_category = isset($_POST['map_category']) && $_POST['map_category'] !== '' ? intval($_POST['map_category']) : -1;
    $map_barcode = isset($_POST['map_barcode']) && $_POST['map_barcode'] !== '' ? intval($_POST['map_barcode']) : -1;
    $map_qty = isset($_POST['map_qty']) && $_POST['map_qty'] !== '' ? intval($_POST['map_qty']) : -1;
    $map_buy = isset($_POST['map_buy']) && $_POST['map_buy'] !== '' ? intval($_POST['map_buy']) : -1;
    $map_sale = isset($_POST['map_sale']) && $_POST['map_sale'] !== '' ? intval($_POST['map_sale']) : -1;

    if (file_exists($temp_file)) {
        if (($handle = fopen($temp_file, "r")) !== FALSE) {
            // كشف الفاصلة
            $first_line = fgets($handle);
            rewind($handle);
            $delimiter = (strpos($first_line, ';') !== false) ? ';' : ',';
            
            // تخطي السطر الأول (الترويسات)
            fgetcsv($handle, 1000, $delimiter);
            
            $imported_count = 0;
            $new_categories_count = 0;
            $conn->begin_transaction();
            
            try {
                // جلب تصنيف افتراضي
                $default_cat_id = 0;
                $sql_def = "SELECT catid FROM categories ORDER BY catid ASC LIMIT 1";
                $res_def = $conn->query($sql_def);
                if ($res_def && $res_def->num_rows > 0) {
                    $default_cat_id = intval($res_def->fetch_assoc()['catid']);
                } else {
                    // إنشاء تصنيف افتراضي إذا لم يوجد أي تصنيف
                    $conn->query("INSERT INTO categories (name) VALUES ('عام')");
                    $default_cat_id = $conn->insert_id;
                    $new_categories_count++;
                }

                while (($row = fgetcsv($handle, 1000, $delimiter)) !== FALSE) {
                    $name = isset($row[$map_name]) ? trim($row[$map_name]) : '';
                    if (empty($name)) continue;

                    // استخراج فئة المنتج
                    $cat_id = $default_cat_id;
                    if ($map_category >= 0 && isset($row[$map_category])) {
                        $cat_name = trim($row[$map_category]);
                        if (!empty($cat_name)) {
                            // البحث عن الفئة
                            $sql_find_cat = "SELECT catid FROM categories WHERE name = '" . $conn->real_escape_string($cat_name) . "'";
                            $res_find = $conn->query($sql_find_cat);
                            if ($res_find && $res_find->num_rows > 0) {
                                $cat_id = intval($res_find->fetch_assoc()['catid']);
                            } else {
                                // إضافة فئة جديدة تلقائياً
                                $sql_add_cat = "INSERT INTO categories (name) VALUES ('" . $conn->real_escape_string($cat_name) . "')";
                                $conn->query($sql_add_cat);
                                $cat_id = $conn->insert_id;
                                $new_categories_count++;
                            }
                        }
                    }

                    // استخراج الباركود
                    $barcode = ($map_barcode >= 0 && isset($row[$map_barcode])) ? $conn->real_escape_string(trim($row[$map_barcode])) : '';

                    // استخراج البيانات الرقمية
                    $qty = ($map_qty >= 0 && isset($row[$map_qty])) ? intval($row[$map_qty]) : 0;
                    $buy_price = ($map_buy >= 0 && isset($row[$map_buy])) ? doubleval($row[$map_buy]) : 0.0;
                    $sale_price = ($map_sale >= 0 && isset($row[$map_sale])) ? doubleval($row[$map_sale]) : 0.0;
                    
                    // إجمالي القيمة
                    $total_val = $qty * $buy_price;
                    $today = date("Y-m-d H:i:s");
                    
                    $name_esc = $conn->real_escape_string($name);
                    
                    // التحقق مما إذا كان المنتج مسجل مسبقاً بالاسم أو الباركود
                    $sql_chk = "SELECT id FROM products WHERE (name = '$name_esc' OR (barcode = '$barcode' AND barcode != '')) AND delete_status = 0";
                    $res_chk = $conn->query($sql_chk);
                    
                    if ($res_chk && $res_chk->num_rows > 0) {
                        // تحديث الكميات والأسعار إذا كان مسجل
                        $existing_id = $res_chk->fetch_assoc()['id'];
                        $sql_up = "UPDATE products SET 
                                   barcode = IF(barcode = '' OR barcode IS NULL, '$barcode', barcode),
                                   quantity = quantity + $qty, 
                                   buy_price = '$buy_price', 
                                   sale_price = '$sale_price', 
                                   total = (quantity + $qty) * '$buy_price' 
                                   WHERE id = $existing_id";
                        $conn->query($sql_up);
                    } else {
                        // إدراج منتج جديد
                        $sql_ins = "INSERT INTO products (name, barcode, quantity, buy_price, sale_price, catid, total, date, delete_status) 
                                    VALUES ('$name_esc', '$barcode', '$qty', '$buy_price', '$sale_price', '$cat_id', '$total_val', '$today', 0)";
                        $conn->query($sql_ins);
                    }
                    $imported_count++;
                }
                
                $conn->commit();
                $success = "تم استيراد وتحديث $imported_count منتجاً بنجاح! (تم إنشاء $new_categories_count تصنيفاً جديداً).";
                @unlink($temp_file);
                $step = 3;
            } catch (Exception $e) {
                $conn->rollback();
                $error = 'حدث خطأ أثناء معالجة البيانات: ' . $e->getMessage();
                @unlink($temp_file);
            }
            fclose($handle);
        } else {
            $error = 'تعذر فتح الملف المؤقت للقراءة.';
        }
    } else {
        $error = 'الملف المرفوع المؤقت غير موجود، الرجاء إعادة المحاولة.';
    }
}
?>
<title>استيراد المنتجات من إكسل CSV - تكنولوجيا فون</title>

<div class="row no-print mb-4">
    <div class="col-md-6">
        <h3 class="text-secondary font-weight-bold">
            <?php echo get_icon('import', 'ml-2 text-primary'); ?> استيراد المنتجات من ملف Excel / CSV
        </h3>
        <p class="text-muted small mb-0">يمكنك رفع قائمة منتجاتك دفعة واحدة بدلاً من إدخالها يدوياً.</p>
    </div>
    <div class="col-md-6 text-left">
        <a href="index.php" class="btn-flat btn-flat-secondary btn-sm text-decoration-none">
            <?php echo get_icon('logout', 'ml-1'); ?> عودة لقائمة المنتجات
        </a>
    </div>
</div>

<div class="row justify-content-center">
    <div class="col-lg-8">
        <?php if (!empty($error)): ?>
            <div class="alert alert-danger rounded-0 mb-4"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <?php if (!empty($success)): ?>
            <div class="alert alert-success rounded-0 mb-4"><?php echo $success; ?></div>
        <?php endif; ?>

        <?php if ($step === 1): ?>
            <!-- خطوة 1: رفع الملف -->
            <div class="card-flat">
                <div class="card-header bg-light">
                    <h5>الخطوة 1: اختيار ملف الاستيراد</h5>
                </div>
                <div class="card-body">
                    <form method="POST" enctype="multipart/form-data">
                        <div class="form-group mb-4">
                            <label class="font-weight-bold text-secondary mb-2">اختر ملف CSV *</label>
                            <input type="file" name="csv_file" class="form-control rounded-0" accept=".csv" required>
                            <small class="text-muted mt-2 d-block">
                                * نصيحة: لحفظ ملف الإكسل بتنسيق CSV، افتح ملف البضاعة في Excel ثم اختر <strong>Save As (حفظ باسم)</strong> وحدد التنسيق <strong>CSV (Comma delimited) (*.csv)</strong>.
                            </small>
                        </div>
                        
                        <div class="text-left">
                            <button type="submit" name="upload_csv" class="btn-flat btn-flat-primary">
                                <?php echo get_icon('import', 'ml-1'); ?> رفع الملف ومطابقة الأعمدة
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            
        <?php elseif ($step === 2): ?>
            <!-- خطوة 2: مطابقة الأعمدة -->
            <div class="card-flat">
                <div class="card-header bg-light">
                    <h5>الخطوة 2: مطابقة أعمدة الملف مع حقول النظام</h5>
                </div>
                <div class="card-body">
                    <div class="alert alert-warning rounded-0 mb-4">
                        الرجاء اختيار العمود المقابل لكل حقل في النظام من ملفك المرفوع.
                    </div>
                    
                    <form method="POST">
                        <div class="row">
                            <!-- اسم المنتج -->
                            <div class="col-md-4 mb-3">
                                <label class="font-weight-bold text-secondary mb-2">اسم المنتج *</label>
                                <select name="map_name" class="form-control rounded-0" required>
                                    <option value="">-- اختر العمود المقابل --</option>
                                    <?php foreach ($headers as $index => $name): ?>
                                        <option value="<?php echo $index; ?>" <?php echo (stripos($name, 'اسم') !== false || stripos($name, 'product') !== false || stripos($name, 'name') !== false) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($name); ?> (عمود <?php echo $index + 1; ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- تصنيف المنتج -->
                            <div class="col-md-4 mb-3">
                                <label class="font-weight-bold text-secondary mb-2">تصنيف / فئة المنتج</label>
                                <select name="map_category" class="form-control rounded-0">
                                    <option value="">-- اختياري (تحديد فئة افتراضية) --</option>
                                    <?php foreach ($headers as $index => $name): ?>
                                        <option value="<?php echo $index; ?>" <?php echo (stripos($name, 'صنف') !== false || stripos($name, 'تصنيف') !== false || stripos($name, 'category') !== false || stripos($name, 'categories') !== false) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($name); ?> (عمود <?php echo $index + 1; ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- رمز الباركود -->
                            <div class="col-md-4 mb-3">
                                <label class="font-weight-bold text-secondary mb-2">رمز الباركود</label>
                                <select name="map_barcode" class="form-control rounded-0">
                                    <option value="">-- اختياري (توليد تلقائي/فارغ) --</option>
                                    <?php foreach ($headers as $index => $name): ?>
                                        <option value="<?php echo $index; ?>" <?php echo (stripos($name, 'باركود') !== false || stripos($name, 'باركودا') !== false || stripos($name, 'barcode') !== false || stripos($name, 'ean') !== false || stripos($name, 'رمز') !== false) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($name); ?> (عمود <?php echo $index + 1; ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- الكمية -->
                            <div class="col-md-4 mb-3">
                                <label class="font-weight-bold text-secondary mb-2">الكمية المتوفرة</label>
                                <select name="map_qty" class="form-control rounded-0">
                                    <option value="">-- اختياري (القيمة الافتراضية 0) --</option>
                                    <?php foreach ($headers as $index => $name): ?>
                                        <option value="<?php echo $index; ?>" <?php echo (stripos($name, 'كمية') !== false || stripos($name, 'عدد') !== false || stripos($name, 'qty') !== false || stripos($name, 'quantity') !== false) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($name); ?> (عمود <?php echo $index + 1; ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- سعر الشراء -->
                            <div class="col-md-4 mb-3">
                                <label class="font-weight-bold text-secondary mb-2">سعر الشراء</label>
                                <select name="map_buy" class="form-control rounded-0">
                                    <option value="">-- اختياري (القيمة الافتراضية 0.0) --</option>
                                    <?php foreach ($headers as $index => $name): ?>
                                        <option value="<?php echo $index; ?>" <?php echo (stripos($name, 'شراء') !== false || stripos($name, 'تكلفة') !== false || stripos($name, 'buy') !== false || stripos($name, 'cost') !== false) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($name); ?> (عمود <?php echo $index + 1; ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- سعر البيع -->
                            <div class="col-md-4 mb-3">
                                <label class="font-weight-bold text-secondary mb-2">سعر البيع</label>
                                <select name="map_sale" class="form-control rounded-0">
                                    <option value="">-- اختياري (القيمة الافتراضية 0.0) --</option>
                                    <?php foreach ($headers as $index => $name): ?>
                                        <option value="<?php echo $index; ?>" <?php echo (stripos($name, 'بيع') !== false || stripos($name, 'سعر') !== false || stripos($name, 'sale') !== false || stripos($name, 'price') !== false) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($name); ?> (عمود <?php echo $index + 1; ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <hr class="my-4">

                        <div class="text-left">
                            <button type="submit" name="process_import" class="btn-flat btn-flat-success">
                                <?php echo get_icon('check', 'ml-1'); ?> بدء استيراد البيانات للمخزن
                            </button>
                            <a href="import.php" class="btn-flat btn-flat-secondary text-decoration-none mr-2">إلغاء</a>
                        </div>
                    </form>
                </div>
            </div>
            
        <?php elseif ($step === 3): ?>
            <!-- خطوة 3: النجاح والعودة -->
            <div class="card-flat text-center p-5">
                <div class="text-success mb-3">
                    <svg xmlns="http://www.w3.org/2000/svg" width="60" height="60" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="d-inline-block"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                </div>
                <h4 class="font-weight-bold text-secondary mb-4">اكتملت عملية الاستيراد بنجاح!</h4>
                <a href="index.php" class="btn-flat btn-flat-primary text-decoration-none">
                    <?php echo get_icon('products', 'ml-1'); ?> عرض قائمة البضائع والمستودع
                </a>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php
require_once($dir_prefix . 'includes/footer.php');
?>
