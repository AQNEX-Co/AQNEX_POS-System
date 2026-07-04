<?php
$dir_prefix = '../';
$module = 'repair';
require_once($dir_prefix . 'includes/header.php');
@include_once($dir_prefix . 'includes/modules.php');
@include_once($dir_prefix . 'includes/accounting_helper.php');

// التحقق من الصلاحيات
check_permission(['admin', 'cashier']);

if (!is_module_enabled('repair_service')) {
    echo '<div class="alert alert-danger rounded-0">موديول الصيانة غير مفعل.</div>';
    require_once($dir_prefix . 'includes/footer.php');
    exit;
}

if (!isset($_GET['id']) || empty($_GET['id'])) {
    echo "<div class='alert alert-danger rounded-0'>خطأ: لم يتم تحديد تذكرة الصيانة.</div>";
    require_once($dir_prefix . 'includes/footer.php');
    exit;
}

$ticket_id = intval($_GET['id']);
$error = '';
$success = '';

// 1. معالجة تحديث حالة التذكرة
if (isset($_POST['btn_update_status'])) {
    $status = $conn->real_escape_string($_POST['status']);
    $diagnosis = $conn->real_escape_string(trim($_POST['diagnosis']));
    
    $stmt = $conn->prepare("UPDATE `repair_tickets` SET `status` = ?, `diagnosis` = ? WHERE `id` = ?");
    $stmt->bind_param("ssi", $status, $diagnosis, $ticket_id);
    if ($stmt->execute()) {
        $success = 'تم تحديث حالة التذكرة والتشخيص بنجاح.';
    } else {
        $error = 'فشل تحديث التذكرة: ' . $conn->error;
    }
    $stmt->close();
}

// 2. معالجة إضافة قطعة غيار من المخزن
if (isset($_POST['btn_add_part'])) {
    $product_id = intval($_POST['part_product_id']);
    $qty = doubleval($_POST['part_qty']);
    
    if ($product_id <= 0 || $qty <= 0) {
        $error = 'يرجى اختيار صنف صحيح وكمية صالحة.';
    } else {
        // جلب بيانات الصنف
        $res_prod = $conn->query("SELECT name, buy_price, sale_price, quantity FROM products WHERE id = $product_id AND delete_status = 0 LIMIT 1");
        $prod = $res_prod ? $res_prod->fetch_assoc() : null;
        
        if (!$prod) {
            $error = 'الصنف المختار غير متوفر.';
        } else if (doubleval($prod['quantity']) < $qty) {
            $error = 'الكمية المطلوبة غير متوفرة بالمخزن. المتوفر حالياً: ' . $prod['quantity'];
        } else {
            $part_name = $prod['name'];
            $cost = $prod['sale_price']; // السعر المحتسب على العميل
            
            // خصم المخزون
            $conn->query("UPDATE products SET quantity = quantity - $qty WHERE id = $product_id");
            
            // تسجيل حركة المخزون
            $sql_log = "INSERT INTO `inventory_log` (`product_id`, `product_name`, `type`, `qty_change`, `new_qty`, `reason`, `user`) 
                        SELECT $product_id, name, 'repair_use', -$qty, quantity, 'سحب قطعة غيار لتذكرة صيانة رقم #$ticket_id', '" . $_SESSION['SESS_FIRST_NAME'] . "' 
                        FROM products WHERE id = $product_id";
            $conn->query($sql_log);
            
            // إدراج في قطع الصيانة المستهلكة
            $stmt = $conn->prepare("INSERT INTO `repair_parts_used` (`repair_ticket_id`, `product_id`, `part_name`, `quantity`, `cost`) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("iisdd", $ticket_id, $product_id, $part_name, $qty, $cost);
            $stmt->execute();
            $stmt->close();
            
            $success = 'تم سحب وإضافة قطعة الغيار بنجاح.';
        }
    }
}

// 3. حذف قطعة غيار وإرجاعها للمخزن
if (isset($_GET['del_part'])) {
    $part_used_id = intval($_GET['del_part']);
    
    $res_p = $conn->query("SELECT * FROM `repair_parts_used` WHERE `id` = $part_used_id LIMIT 1");
    $part_used = $res_p ? $res_p->fetch_assoc() : null;
    
    if ($part_used) {
        $product_id = intval($part_used['product_id']);
        $qty = doubleval($part_used['quantity']);
        
        // إرجاع للمخزن
        if ($product_id > 0) {
            $conn->query("UPDATE products SET quantity = quantity + $qty WHERE id = $product_id");
            // تسجيل حركة الإرجاع
            $sql_log = "INSERT INTO `inventory_log` (`product_id`, `product_name`, `type`, `qty_change`, `new_qty`, `reason`, `user`) 
                        SELECT $product_id, name, 'repair_return', $qty, quantity, 'إلغاء سحب قطعة غيار لتذكرة صيانة رقم #$ticket_id', '" . $_SESSION['SESS_FIRST_NAME'] . "' 
                        FROM products WHERE id = $product_id";
            $conn->query($sql_log);
        }
        
        $conn->query("DELETE FROM `repair_parts_used` WHERE `id` = $part_used_id");
        $success = 'تم حذف قطعة الغيار وإرجاعها للمخزن بنجاح.';
    }
}

// 4. تسوية وتصفية الحساب والتسليم النهائي للعميل
if (isset($_POST['btn_settle_ticket'])) {
    $final_cost = doubleval($_POST['final_cost']);
    $payment_method = $conn->real_escape_string($_POST['payment_method']); // cash, credit
    
    // جلب مجموع أسعار وتكاليف قطع الغيار المستهلكة
    $res_parts_totals = $conn->query("
        SELECT SUM(rpu.quantity * rpu.cost) as total_charged,
               SUM(rpu.quantity * p.buy_price) as total_cogs
        FROM repair_parts_used rpu
        LEFT JOIN products p ON rpu.product_id = p.id
        WHERE rpu.repair_ticket_id = $ticket_id
    ");
    $parts_totals = $res_parts_totals ? $res_parts_totals->fetch_assoc() : ['total_charged' => 0, 'total_cogs' => 0];
    
    $parts_charged = doubleval($parts_totals['total_charged']);
    $parts_cogs = doubleval($parts_totals['total_cogs']);
    
    // حساب تكلفة خدمات العمل / اليد الفنية
    $service_rev = $final_cost - $parts_charged;
    if ($service_rev < 0) {
        $service_rev = 0; // في حال خفض السعر النهائي لأقل من قيمة القطع
    }

    $active_box_id = get_user_box_id($conn, $_SESSION['SESS_MEMBER_ID']);
    $box_name = get_box_name($conn, $active_box_id);

    // جلب بيانات التذكرة الحالية
    $res_t = $conn->query("SELECT r.*, c.cust_name FROM repair_tickets r LEFT JOIN customers c ON r.customer_id = c.cust_id WHERE r.id = $ticket_id LIMIT 1");
    $ticket = $res_t ? $res_t->fetch_assoc() : null;

    if ($ticket) {
        $customer_name = $ticket['cust_name'] ?: 'عميل نقدي';
        $user_display = $_SESSION['SESS_FIRST_NAME'];
        
        // تحديث التذكرة كمسلمة ومنتهية
        $conn->query("UPDATE `repair_tickets` SET `status` = 'delivered', `final_cost` = $final_cost, `delivered_date` = NOW() WHERE `id` = $ticket_id");

        // تسجيل الحركات المحاسبية القيود المزدوجة
        if ($payment_method === 'cash') {
            // تحصيل الصندوق
            if ($final_cost > 0) {
                // قيد تحصيل إجمالي
                post_journal_entry($conn, 'receipt', $ticket_id, 'الصندوق - ' . $box_name, 'إيرادات الصيانة والخدمات', $final_cost, "تحصيل كاش صيانة جهاز تذكرة رقم #$ticket_id - $customer_name", $user_display, $active_box_id);
                // ترحيل النقدية إلى صندوق الكاشير الفعلي لتحديث الرصيد المالي
                update_box_balance($conn, $active_box_id, $final_cost, 'addition', "تحصيل نقدي صيانة جهاز تذكرة رقم #$ticket_id", date("Y-m-d"));
            }
        } else {
            // ترحيل لمديونية العميل (الذمم المدينة)
            if ($final_cost > 0) {
                if ($ticket['customer_id'] > 0) {
                    $conn->query("UPDATE customers SET cust_madeen = cust_madeen + $final_cost WHERE cust_id = " . $ticket['customer_id']);
                }
                post_journal_entry($conn, 'receipt', $ticket_id, 'الذمم المدينة - ' . $customer_name, 'إيرادات الصيانة والخدمات', $final_cost, "تصفية صيانة آجلة تذكرة رقم #$ticket_id - $customer_name", $user_display, $active_box_id);
            }
        }
        
        // قيد محاسبة تكلفة البضاعة لقطع الغيار
        if ($parts_cogs > 0) {
            post_journal_entry($conn, 'expense', $ticket_id, 'تكلفة البضاعة المباعة (مصروف)', 'المخزون / البضاعة', $parts_cogs, "تكلفة قطع غيار صيانة تذكرة #$ticket_id", $user_display, $active_box_id);
        }

        $success = 'تم تصفية وتسليم جهاز التذكرة وترحيل قيود الصيانة بنجاح.';
    }
}

// 5. جلب بيانات التذكرة الحالية
$res_t = $conn->query("
    SELECT r.*, c.cust_name, u.full_name as tech_name, u.username as tech_username 
    FROM repair_tickets r 
    LEFT JOIN customers c ON r.customer_id = c.cust_id 
    LEFT JOIN users u ON r.technician_id = u.userid
    WHERE r.id = $ticket_id LIMIT 1
");
$ticket = $res_t ? $res_t->fetch_assoc() : null;

if (!$ticket) {
    echo '<div class="alert alert-danger rounded-0">تذكرة الصيانة غير موجودة.</div>';
    require_once($dir_prefix . 'includes/footer.php');
    exit;
}

// جلب قطع الغيار المستخدمة
$parts_used = [];
$res_parts = $conn->query("SELECT * FROM `repair_parts_used` WHERE `repair_ticket_id` = $ticket_id");
$total_parts_cost = 0;
if ($res_parts) {
    while($row = $res_parts->fetch_assoc()) {
        $parts_used[] = $row;
        $total_parts_cost += doubleval($row['quantity'] * $row['cost']);
    }
}

// جلب قائمة السلع لقطع الغيار
$products_list = [];
$res_prod = $conn->query("SELECT id, name, sale_price, quantity FROM products WHERE delete_status = 0 ORDER BY id DESC");
if ($res_prod) {
    while ($row = $res_prod->fetch_assoc()) {
        $products_list[] = $row;
    }
}
$products_json = json_encode($products_list, JSON_UNESCAPED_UNICODE);
?>

<title>تذكرة صيانة #<?php echo htmlspecialchars($ticket['ticket_number']); ?> - تكنولوجيا فون</title>

<style>
.product-search-container { position: relative; }
.autocomplete-dropdown {
    position: absolute; top: 100%; right: 0; width: 100%; background: #fff;
    border: 1px solid var(--secondary); border-top: none; max-height: 200px;
    overflow-y: auto; z-index: 1050; box-shadow: 0 4px 8px rgba(0,0,0,0.15);
}
.autocomplete-item { padding: 8px 12px; cursor: pointer; border-bottom: 1px solid #f1f5f9; text-align: right; }
.autocomplete-item:hover { background-color: #f8f9fa; }
</style>

<div class="card-flat">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5><?php echo get_icon('briefcase', 'ml-2 text-primary'); ?> تفاصيل تذكرة الصيانة: <?php echo htmlspecialchars($ticket['ticket_number']); ?></h5>
        <div class="no-print">
            <a href="print_sticker.php?id=<?php echo $ticket_id; ?>" target="_blank" class="btn btn-info btn-sm rounded-0 text-decoration-none"><?php echo get_icon('tag', 'ml-1'); ?> طباعة ملصق الصيانة</a>
            <button class="btn btn-primary btn-sm rounded-0 mr-1" onclick="window.print()"><?php echo get_icon('print', 'ml-1'); ?> طباعة</button>
            <a href="index.php" class="btn btn-secondary btn-sm rounded-0 mr-1 text-decoration-none"><?php echo get_icon('logout', 'ml-1'); ?> عودة</a>
        </div>
    </div>
    
    <div class="card-body">
        <?php if (!empty($error)): ?>
            <div class="alert alert-danger rounded-0 mb-4"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <?php if (!empty($success)): ?>
            <div class="alert alert-success rounded-0 mb-4"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <!-- معلومات التذكرة -->
        <div class="row mb-4">
            <div class="col-md-6">
                <table class="table-flat border">
                    <tr>
                        <th style="width: 40%;">رقم التذكرة</th>
                        <td><strong><?php echo htmlspecialchars($ticket['ticket_number']); ?></strong></td>
                    </tr>
                    <tr>
                        <th>العميل</th>
                        <td><?php echo htmlspecialchars($ticket['cust_name'] ?: 'عميل نقدي'); ?></td>
                    </tr>
                    <tr>
                        <th>الجهاز</th>
                        <td><?php echo htmlspecialchars($ticket['device_name'] ?: ($ticket['device_brand'] . ' - ' . $ticket['device_type'])); ?></td>
                    </tr>
                    <tr>
                        <th>نوع العطل</th>
                        <td><?php echo htmlspecialchars($ticket['issue_type'] ?: '-'); ?></td>
                    </tr>
                    <tr>
                        <th>تاريخ التسليم المتوقع</th>
                        <td><?php echo $ticket['expected_delivery_date'] ? date('Y-m-d', strtotime($ticket['expected_delivery_date'])) : '-'; ?></td>
                    </tr>
                    <tr>
                        <th>الرقم التسلسلي / IMEI</th>
                        <td><code><?php echo htmlspecialchars($ticket['imei'] ?: '-'); ?></code></td>
                    </tr>
                    <tr>
                        <th>الفني المسؤول</th>
                        <td><?php echo htmlspecialchars($ticket['tech_name'] ?: $ticket['tech_username']); ?></td>
                    </tr>
                </table>
            </div>
            
            <div class="col-md-6">
                <table class="table-flat border">
                    <tr>
                        <th style="width: 40%;">حالة التذكرة</th>
                        <td>
                            <?php 
                            switch ($ticket['status']) {
                                case 'received': echo '<span class="badge badge-secondary px-3 py-1 rounded-0">تم الاستلام</span>'; break;
                                case 'in_progress': echo '<span class="badge badge-info px-3 py-1 rounded-0">قيد الصيانة والفحص</span>'; break;
                                case 'waiting_parts': echo '<span class="badge badge-warning px-3 py-1 rounded-0">بانتظار قطع</span>'; break;
                                case 'completed': echo '<span class="badge badge-success px-3 py-1 rounded-0">جاهز للتسليم</span>'; break;
                                case 'delivered': echo '<span class="badge badge-dark px-3 py-1 rounded-0">تم التسليم والتحصيل</span>'; break;
                                case 'cancelled': echo '<span class="badge badge-danger px-3 py-1 rounded-0">ملغاة</span>'; break;
                            }
                            ?>
                        </td>
                    </tr>
                    <tr>
                        <th>التكلفة التقديرية</th>
                        <td><strong><?php echo number_format($ticket['estimated_cost'], 2); ?> ر.ي</strong></td>
                    </tr>
                    <tr>
                        <th>التكلفة النهائية</th>
                        <td><strong class="text-success"><?php echo number_format($ticket['final_cost'], 2); ?> ر.ي</strong></td>
                    </tr>
                    <tr>
                        <th>تاريخ الاستلام</th>
                        <td><?php echo $ticket['received_date']; ?></td>
                    </tr>
                    <tr>
                        <th>تاريخ التسليم</th>
                        <td><?php echo $ticket['delivered_date'] ?: 'لا يزال قيد المعالجة'; ?></td>
                    </tr>
                </table>
            </div>

            <div class="col-md-12 mt-3">
                <div class="p-3 bg-light border">
                    <h6 class="font-weight-bold text-secondary mb-2">وصف العطل كما حدده العميل:</h6>
                    <p class="mb-0 text-dark"><?php echo nl2br(htmlspecialchars($ticket['problem_description'])); ?></p>
                </div>
            </div>

            <?php if (!empty($ticket['diagnosis'])): ?>
                <div class="col-md-12 mt-3">
                    <div class="p-3 bg-light border border-info">
                        <h6 class="font-weight-bold text-info mb-2">تشخيص الفني وحل المشكلة:</h6>
                        <p class="mb-0 text-dark"><?php echo nl2br(htmlspecialchars($ticket['diagnosis'])); ?></p>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($ticket['status'] !== 'delivered' && $ticket['status'] !== 'cancelled'): ?>
        <!-- لوحة التحكم بالفني لتحديث الحالة والتشخيص -->
        <div class="card bg-light border rounded-0 mb-4 no-print">
            <div class="card-header bg-secondary text-white rounded-0">
                <h6 class="font-weight-bold mb-0">تحديث حالة التذكرة والتشخيص الفني</h6>
            </div>
            <div class="card-body">
                <form method="POST">
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="font-weight-bold text-secondary">حالة الإصلاح *</label>
                            <select name="status" class="form-control rounded-0" required>
                                <option value="received" <?php echo $ticket['status'] === 'received' ? 'selected' : ''; ?>>تم الاستلام (بانتظار الفحص)</option>
                                <option value="in_progress" <?php echo $ticket['status'] === 'in_progress' ? 'selected' : ''; ?>>قيد الصيانة والفحص</option>
                                <option value="waiting_parts" <?php echo $ticket['status'] === 'waiting_parts' ? 'selected' : ''; ?>>في انتظار قطع الغيار من المخزن</option>
                                <option value="completed" <?php echo $ticket['status'] === 'completed' ? 'selected' : ''; ?>>تم الإصلاح وجاهز للتسليم</option>
                                <option value="cancelled" <?php echo $ticket['status'] === 'cancelled' ? 'selected' : ''; ?>>ملغاة (تعذر الإصلاح)</option>
                            </select>
                        </div>
                        <div class="col-md-8 mb-3">
                            <label class="font-weight-bold text-secondary">التشخيص والتقرير الفني</label>
                            <input type="text" name="diagnosis" class="form-control rounded-0" placeholder="تقرير مختصر عما تم إصلاحه بالجهاز..." value="<?php echo htmlspecialchars($ticket['diagnosis']); ?>">
                        </div>
                    </div>
                    <div class="text-left">
                        <button type="submit" name="btn_update_status" class="btn btn-primary rounded-0"><?php echo get_icon('check', 'ml-1'); ?> تحديث حالة الفحص</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- إدارة قطع الغيار المسحوبة -->
        <div class="card border rounded-0 mb-4 no-print">
            <div class="card-header bg-danger text-white rounded-0">
                <h6 class="font-weight-bold mb-0">قطع الغيار المستخدمة من المخازن</h6>
            </div>
            <div class="card-body">
                <!-- فورم سحب قطعة غيار -->
                <form method="POST" class="row mb-4">
                    <div class="col-md-8 mb-2">
                        <label class="font-weight-bold text-secondary">البحث عن قطعة الغيار بمخزن المحل</label>
                        <div class="product-search-container">
                            <input type="text" id="partSearchInput" class="form-control rounded-0" placeholder="ابحث باسم القطعة أو الباركود..." autocomplete="off">
                            <input type="hidden" name="part_product_id" id="partProductId">
                            <div id="autocompleteDropdown" class="autocomplete-dropdown d-none"></div>
                        </div>
                    </div>
                    <div class="col-md-2 mb-2">
                        <label class="font-weight-bold text-secondary">الكمية</label>
                        <input type="number" name="part_qty" class="form-control rounded-0 text-center" value="1" min="1" step="any">
                    </div>
                    <div class="col-md-2 mb-2 d-flex align-items-end">
                        <button type="submit" name="btn_add_part" class="btn btn-danger btn-block rounded-0">سحب وصرف القطعة</button>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <!-- جدول قطع الغيار المسحوبة حالياً للفاتورة -->
        <div class="mb-4">
            <h6 class="font-weight-bold text-secondary border-bottom pb-2">قطع الغيار المحتسبة للتذكرة</h6>
            <div class="table-responsive">
                <table class="table-flat border">
                    <thead>
                        <tr>
                            <th>اسم القطعة</th>
                            <th style="width: 15%;">الكمية</th>
                            <th style="width: 20%;">السعر للوحدة</th>
                            <th style="width: 20%;">المجموع</th>
                            <?php if ($ticket['status'] !== 'delivered' && $ticket['status'] !== 'cancelled'): ?>
                                <th class="no-print" style="width: 10%;">إجراء</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($parts_used)): ?>
                            <tr>
                                <td colspan="5" class="text-center text-muted py-3">لم يتم سحب أي قطع غيار لهذا الجهاز حالياً.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($parts_used as $p): ?>
                                <tr>
                                    <td class="font-weight-bold text-dark"><?php echo htmlspecialchars($p['part_name']); ?></td>
                                    <td><?php echo $p['quantity']; ?></td>
                                    <td><?php echo number_format($p['cost'], 2); ?> ر.ي</td>
                                    <td class="font-weight-bold text-secondary"><?php echo number_format($p['quantity'] * $p['cost'], 2); ?> ر.ي</td>
                                    <?php if ($ticket['status'] !== 'delivered' && $ticket['status'] !== 'cancelled'): ?>
                                        <td class="no-print">
                                            <a href="?id=<?php echo $ticket_id; ?>&del_part=<?php echo $p['id']; ?>" 
                                               class="btn btn-outline-danger btn-sm rounded-0"
                                               onclick="return confirm('هل تريد إرجاع هذه القطعة للمخزن وإلغاء سحبها؟')">
                                                إرجاع
                                            </a>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                            <tr class="bg-light">
                                <td colspan="3" class="text-left font-weight-bold">إجمالي تكلفة قطع الغيار:</td>
                                <td colspan="2" class="font-weight-bold text-primary" style="font-size: 1.1rem;"><?php echo number_format($total_parts_cost, 2); ?> ر.ي</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php if ($ticket['status'] !== 'delivered' && $ticket['status'] !== 'cancelled' && $ticket['status'] === 'completed'): ?>
        <!-- لوحة تصفية الحساب والتسليم النهائي للعميل -->
        <div id="settlement" class="card bg-success border-success rounded-0 mb-4 no-print text-white">
            <div class="card-header bg-success border-success rounded-0">
                <h6 class="font-weight-bold mb-0">تسليم الجهاز وتصفية الحساب المالي للعميل</h6>
            </div>
            <div class="card-body bg-light text-dark">
                <form method="POST">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="font-weight-bold text-secondary">إجمالي التكلفة النهائية المحتسبة (قطع الغيار + أجر اليد) *</label>
                            <div class="input-group">
                                <input type="number" step="any" name="final_cost" id="finalCostInput" class="form-control form-control-lg rounded-0 font-weight-bold text-center border-success" 
                                       value="<?php echo max($ticket['estimated_cost'], $total_parts_cost); ?>" min="<?php echo $total_parts_cost; ?>" required>
                                <div class="input-group-append">
                                    <span class="input-group-text rounded-0 bg-success text-white">ر.ي</span>
                                </div>
                            </div>
                            <small class="text-muted d-block mt-1">تكلفة قطع غيار التذكرة الإلزامية: <?php echo number_format($total_parts_cost, 2); ?> ر.ي</small>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="font-weight-bold text-secondary">طريقة تحصيل الحساب *</label>
                            <select name="payment_method" class="form-control form-control-lg rounded-0" required>
                                <option value="cash">تحصيل نقدي كاش (يدخل الصندوق مباشرة)</option>
                                <?php if ($ticket['customer_id'] > 0): ?>
                                    <option value="credit">تسجيل على حساب العميل آجل (مديونية)</option>
                                <?php endif; ?>
                            </select>
                        </div>
                    </div>
                    <div class="text-left mt-3">
                        <button type="submit" name="btn_settle_ticket" class="btn btn-success btn-lg px-5 rounded-0"><?php echo get_icon('check', 'ml-1'); ?> تأكيد تسليم الجهاز وتحصيل المبالغ</button>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
const availableProducts = <?php echo $products_json; ?>;

document.addEventListener("DOMContentLoaded", function() {
    const partSearchInput = document.getElementById("partSearchInput");
    const dropdown = document.getElementById("autocompleteDropdown");
    const partProductId = document.getElementById("partProductId");
    
    if (partSearchInput) {
        partSearchInput.addEventListener("input", function() {
            const query = this.value.trim().toLowerCase();
            if (!query) {
                dropdown.classList.add("d-none");
                dropdown.innerHTML = "";
                return;
            }

            const matches = availableProducts.filter(p => 
                p.name.toLowerCase().includes(query)
            ).slice(0, 10);

            if (matches.length === 0) {
                dropdown.innerHTML = '<div class="autocomplete-item text-center text-muted">لا يوجد نتائج بمستودعات المحل</div>';
                dropdown.classList.remove("d-none");
                return;
            }

            let html = "";
            matches.forEach(p => {
                html += `
                    <div class="autocomplete-item" data-id="${p.id}" data-price="${p.sale_price}">
                        <div class="font-weight-bold text-dark">${p.name}</div>
                        <small class="text-muted">السعر: ${p.sale_price} ر.ي | المتوفر: ${p.quantity}</small>
                    </div>
                `;
            });
            dropdown.innerHTML = html;
            dropdown.classList.remove("d-none");
        });

        dropdown.addEventListener("click", function(e) {
            const item = e.target.closest(".autocomplete-item");
            if (item) {
                const id = item.getAttribute("data-id");
                partProductId.value = id;
                partSearchInput.value = item.querySelector(".font-weight-bold").textContent;
                
                dropdown.classList.add("d-none");
                dropdown.innerHTML = "";
            }
        });

        document.addEventListener("click", function(e) {
            if (!e.target.closest(".product-search-container")) {
                dropdown.classList.add("d-none");
            }
        });
    }
});
</script>

<?php require_once($dir_prefix . 'includes/footer.php'); ?>
