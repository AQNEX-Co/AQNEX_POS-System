<?php
$module = isset($module) ? $module : '';
$prefix = isset($prefix) ? $prefix : '';

$role = isset($_SESSION['SESS_LAST_NAME']) ? trim($_SESSION['SESS_LAST_NAME']) : '';
$user_id = isset($_SESSION['SESS_MEMBER_ID']) ? intval($_SESSION['SESS_MEMBER_ID']) : 0;
$is_admin = ($role === 'admin' || empty($role)); // المدير أو افتراضي
$is_cashier = ($role === 'cashier');
$is_inventory = ($role === 'inventory');

// تحديد الصلاحيات المتاحة للمستخدم الحالي
$allowed_modules = [];
if ($is_admin) {
    $allowed_modules = ['sales', 'purchases', 'products', 'categories', 'box', 'receipts', 'expenses', 'customers', 'suppliers', 'reports', 'users', 'settings', 'journal'];
} else {
    $has_custom = false;
    if ($user_id > 0 && isset($conn)) {
        $res_user = $conn->query("SELECT custom_permissions FROM users WHERE userid = $user_id LIMIT 1");
        if ($res_user) {
            $user_row = $res_user->fetch_assoc();
            $custom = trim($user_row['custom_permissions'] ?? '');
            if (!empty($custom)) {
                $allowed_modules = array_map('trim', explode(',', $custom));
                $has_custom = true;
            }
        }
    }
    if (!$has_custom && isset($conn)) {
        $settings_res = $conn->query("SELECT cashier_permissions, inventory_permissions FROM settings WHERE id = 1");
        $settings = $settings_res ? $settings_res->fetch_assoc() : null;
        if ($settings) {
            if ($role === 'cashier') {
                $allowed_modules = array_map('trim', explode(',', $settings['cashier_permissions'] ?? ''));
            } elseif ($role === 'inventory') {
                $allowed_modules = array_map('trim', explode(',', $settings['inventory_permissions'] ?? ''));
            }
        }
    }
}

// دالة فحص الوصول للفرع في القائمة
if (!function_exists('sidebar_has_access')) {
    function sidebar_has_access($mod_name) {
        global $allowed_modules, $is_admin;
        if ($is_admin) return true;
        return in_array($mod_name, $allowed_modules);
    }
}
?>

<nav id="sidebar" class="no-print">
    <div class="sidebar-header">
        <div class="text-center mb-2">
            <img src="<?php echo $logo_url; ?>" style="max-height: 55px; max-width: 100%; border: 1px solid rgba(255,255,255,0.1); padding: 2px; background: #fff;" class="rounded">
        </div>
        <h4 class="page-title mb-1">
            <?php 
            $store_display = !empty($global_settings['store_name']) ? htmlspecialchars($global_settings['store_name']) : 'نظام إدارة المتجر';
            echo $store_display; ?>
        </h4>
    </div>

    <ul class="list-unstyled components">
        <!-- الرئيسية -->
        <li class="<?php echo ($module == 'dashboard') ? 'active' : ''; ?>">
            <a href="<?php echo $prefix; ?>home.php">
                <?php echo get_icon('home', 'sidebar-icon'); ?>
                الرئيسية
            </a>
        </li>
        
        <!-- إدارة المبيعات -->
        <?php if ($is_admin || sidebar_has_access('sales')): ?>
        <li>
            <a href="#salesSubmenu" data-toggle="collapse" aria-expanded="<?php echo in_array($module, ['sales']) ? 'true' : 'false'; ?>" class="dropdown-toggle">
                <span class="icon-wrapper"><?php echo get_icon('sales', 'sidebar-icon'); ?></span>
                <span class="menu-text">إدارة المبيعات</span>
                <span class="arrow-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
                </span>
            </a>

            <ul class="collapse list-unstyled <?php echo in_array($module, ['sales']) ? 'show' : ''; ?>" id="salesSubmenu">
                <li class="<?php echo ($module == 'sales' && basename($_SERVER['PHP_SELF']) == 'create.php') ? 'active' : ''; ?>">
                    <a href="<?php echo $prefix; ?>sales/create.php">
                        <span class="icon-wrapper"><?php echo get_icon('plus', 'sidebar-icon'); ?></span>
                        فاتورة بيع جديدة
                    </a>
                </li>
                <li class="<?php echo ($module == 'sales' && basename($_SERVER['PHP_SELF']) == 'index.php') ? 'active' : ''; ?>">
                    <a href="<?php echo $prefix; ?>sales/index.php">
                        <span class="icon-wrapper"><?php echo get_icon('list', 'sidebar-icon'); ?></span>
                        فواتير المبيعات
                    </a>
                </li>
                <li class="<?php echo ($module == 'sales' && basename($_SERVER['PHP_SELF']) == 'returns.php') ? 'active' : ''; ?>">
                    <a href="<?php echo $prefix; ?>sales/returns.php">
                        <span class="icon-wrapper"><?php echo get_icon('return', 'sidebar-icon'); ?></span>
                        مردودات المبيعات
                    </a>
                </li>
            </ul>
        </li>
        <?php endif; ?>

        <!-- إدارة المشتريات -->
        <?php if ($is_admin || sidebar_has_access('purchases')): ?>
        <li>
            <a href="#purchasesSubmenu" data-toggle="collapse" aria-expanded="<?php echo in_array($module, ['purchases']) ? 'true' : 'false'; ?>" class="dropdown-toggle">
                <span class="icon-wrapper"><?php echo get_icon('purchases', 'sidebar-icon'); ?></span>
                <span class="menu-text">إدارة المشتريات</span>
                <span class="arrow-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
                </span>
            </a>
            <ul class="collapse list-unstyled <?php echo in_array($module, ['purchases']) ? 'show' : ''; ?>" id="purchasesSubmenu">
                <li class="<?php echo ($module == 'purchases' && basename($_SERVER['PHP_SELF']) == 'create.php') ? 'active' : ''; ?>">
                    <a href="<?php echo $prefix; ?>purchases/create.php">
                        <span class="icon-wrapper"><?php echo get_icon('plus', 'sidebar-icon'); ?></span>
                        فاتورة شراء جديدة
                    </a>
                </li>
                <li class="<?php echo ($module == 'purchases' && basename($_SERVER['PHP_SELF']) == 'index.php') ? 'active' : ''; ?>">
                    <a href="<?php echo $prefix; ?>purchases/index.php">
                        <span class="icon-wrapper"><?php echo get_icon('list', 'sidebar-icon'); ?></span>
                        فواتير المشتريات
                    </a>
                </li>
                <li class="<?php echo ($module == 'purchases' && basename($_SERVER['PHP_SELF']) == 'returns.php') ? 'active' : ''; ?>">
                    <a href="<?php echo $prefix; ?>purchases/returns.php">
                        <span class="icon-wrapper"><?php echo get_icon('return', 'sidebar-icon'); ?></span>
                        مردودات المشتريات
                    </a>
                </li>
            </ul>
        </li>
        <?php endif; ?>

        <!-- إدارة الحسابات -->
        <?php if ($is_admin || sidebar_has_access('box') || sidebar_has_access('receipts') || sidebar_has_access('expenses') || sidebar_has_access('journal')): ?>
        <li>
            <a href="#accountsSubmenu" data-toggle="collapse" aria-expanded="<?php echo in_array($module, ['box', 'receipts', 'expenses', 'journal']) ? 'true' : 'false'; ?>" class="dropdown-toggle">
                <span class="icon-wrapper"><?php echo get_icon('bank', 'sidebar-icon'); ?></span>
                <span class="menu-text">إدارة الحسابات</span>
                <span class="arrow-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
                </span>
            </a>
            <ul class="collapse list-unstyled <?php echo in_array($module, ['box', 'receipts', 'expenses', 'journal']) ? 'show' : ''; ?>" id="accountsSubmenu">
                <?php if ($is_admin || sidebar_has_access('box')): ?>
                <li class="<?php echo ($module == 'box') ? 'active' : ''; ?>">
                    <a href="<?php echo $prefix; ?>box/index.php">
                        <span class="icon-wrapper"><?php echo get_icon('bank', 'sidebar-icon'); ?></span>
                        إدارة الصناديق
                    </a>
                </li>
                <?php endif; ?>
                
                <?php if ($is_admin || sidebar_has_access('receipts')): ?>
                <li class="<?php echo ($module == 'receipts') ? 'active' : ''; ?>">
                    <a href="<?php echo $prefix; ?>receipts/index.php">
                        <span class="icon-wrapper"><?php echo get_icon('receipts', 'sidebar-icon'); ?></span>
                        سندات القبض
                    </a>
                </li>
                <?php endif; ?>
                
                <?php if ($is_admin || sidebar_has_access('expenses')): ?>
                <li class="<?php echo ($module == 'expenses') ? 'active' : ''; ?>">
                    <a href="<?php echo $prefix; ?>expenses/index.php">
                        <span class="icon-wrapper"><?php echo get_icon('expenses', 'sidebar-icon'); ?></span>
                        سندات الصرف
                    </a>
                </li>
                <?php endif; ?>

                <?php if ($is_admin || sidebar_has_access('journal')): ?>
                <li class="<?php echo ($module == 'journal') ? 'active' : ''; ?>">
                    <a href="<?php echo $prefix; ?>accounting/journal.php">
                        <span class="icon-wrapper"><?php echo get_icon('journal', 'sidebar-icon'); ?></span>
                        القيود اليومية
                    </a>
                </li>
                <?php endif; ?>
            </ul>
        </li>
        <?php endif; ?>

        <!-- إدارة المخزون -->
        <?php if ($is_admin || sidebar_has_access('categories') || sidebar_has_access('products')): ?>
        <li>
            <a href="#inventorySubmenu" data-toggle="collapse" aria-expanded="<?php echo in_array($module, ['categories', 'products', 'inventory']) ? 'true' : 'false'; ?>" class="dropdown-toggle">
                <span class="icon-wrapper"><?php echo get_icon('products', 'sidebar-icon'); ?></span>
                <span class="menu-text">إدارة المخزون</span>
                <span class="arrow-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
                </span>
            </a>
            <ul class="collapse list-unstyled <?php echo in_array($module, ['categories', 'products', 'inventory']) ? 'show' : ''; ?>" id="inventorySubmenu">
                <?php if ($is_admin || sidebar_has_access('categories')): ?>
                <li class="<?php echo ($module == 'categories') ? 'active' : ''; ?>">
                    <a href="<?php echo $prefix; ?>categories/index.php">
                        <span class="icon-wrapper"><?php echo get_icon('categories', 'sidebar-icon'); ?></span>
                        أصناف المنتجات
                    </a>
                </li>
                <?php endif; ?>
                <?php if ($is_admin || sidebar_has_access('products')): ?>
                <li class="<?php echo ($module == 'products' && basename($_SERVER['PHP_SELF']) == 'index.php') ? 'active' : ''; ?>">
                    <a href="<?php echo $prefix; ?>products/index.php">
                        <span class="icon-wrapper"><?php echo get_icon('products', 'sidebar-icon'); ?></span>
                        جرد المخزن والكميات
                    </a>
                </li>
                <li class="<?php echo ($module == 'products' && basename($_SERVER['PHP_SELF']) == 'inventory.php') ? 'active' : ''; ?>">
                    <a href="<?php echo $prefix; ?>products/inventory.php">
                        <span class="icon-wrapper"><?php echo get_icon('inventory', 'sidebar-icon'); ?></span>
                        مراقبة وتسوية المخزون
                    </a>
                </li>
                <?php endif; ?>
            </ul>
        </li>
        <?php endif; ?>

        <!-- إدارة العملاء -->
        <?php if ($is_admin || sidebar_has_access('customers')): ?>
        <li class="<?php echo ($module == 'customers') ? 'active' : ''; ?>">
            <a href="<?php echo $prefix; ?>customers/index.php">
                <?php echo get_icon('customers', 'sidebar-icon'); ?>
                إدارة العملاء
            </a>
        </li>
        <?php endif; ?>

        <!-- إدارة الموردين -->
        <?php if ($is_admin || sidebar_has_access('suppliers')): ?>
        <li class="<?php echo ($module == 'suppliers') ? 'active' : ''; ?>">
            <a href="<?php echo $prefix; ?>suppliers/index.php">
                <?php echo get_icon('suppliers', 'sidebar-icon'); ?>
                إدارة الموردين
            </a>
        </li>
        <?php endif; ?>

        <!-- إدارة النظام -->
        <?php if ($is_admin || sidebar_has_access('reports') || sidebar_has_access('users') || sidebar_has_access('settings')): ?>
        <li>
            <a href="#systemSubmenu" data-toggle="collapse" aria-expanded="<?php echo in_array($module, ['reports', 'users', 'settings']) ? 'true' : 'false'; ?>" class="dropdown-toggle">
                <span class="icon-wrapper"><?php echo get_icon('bolt', 'sidebar-icon'); ?></span>
                <span class="menu-text">إدارة النظام</span>
                <span class="arrow-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
                </span>
            </a>
            <ul class="collapse list-unstyled <?php echo in_array($module, ['reports', 'users', 'settings']) ? 'show' : ''; ?>" id="systemSubmenu">
                <?php if ($is_admin || sidebar_has_access('reports')): ?>
                <li class="<?php echo ($module == 'reports') ? 'active' : ''; ?>">
                    <a href="<?php echo $prefix; ?>reports/daily.php">
                        <span class="icon-wrapper"><?php echo get_icon('reports', 'sidebar-icon'); ?></span>
                        التقارير والاحصائيات
                    </a>
                </li>
                <?php endif; ?>
                <?php if ($is_admin || sidebar_has_access('users')): ?>
                <li class="<?php echo ($module == 'users') ? 'active' : ''; ?>">
                    <a href="<?php echo $prefix; ?>users/index.php">
                        <span class="icon-wrapper"><?php echo get_icon('users', 'sidebar-icon'); ?></span>
                        إدارة المستخدمين
                    </a>
                </li>
                <?php endif; ?>
                <?php if ($is_admin || sidebar_has_access('settings')): ?>
                <li class="<?php echo ($module == 'settings' && basename($_SERVER['PHP_SELF']) == 'index.php') ? 'active' : ''; ?>">
                    <a href="<?php echo $prefix; ?>settings/index.php">
                        <span class="icon-wrapper"><?php echo get_icon('settings', 'sidebar-icon'); ?></span>
                        إعدادات المتجر
                    </a>
                </li>
                <li class="<?php echo ($module == 'settings' && basename($_SERVER['PHP_SELF']) == 'backup.php') ? 'active' : ''; ?>">
                    <a href="<?php echo $prefix; ?>settings/backup.php">
                        <span class="icon-wrapper"><?php echo get_icon('database', 'sidebar-icon'); ?></span>
                        النسخ الاحتياطي والاستعادة
                    </a>
                </li>
                <?php endif; ?>
                        <li class="<?php echo ($module == 'changeuser') ? 'active' : ''; ?>">
            <a href="<?php echo $prefix; ?>auth/changeuser.php">
                <?php echo get_icon('changeuser', 'sidebar-icon'); ?>
                تغيير كلمة المرور
            </a>
        </li>
            </ul>
        </li>
        <?php endif; ?>


        <li>
            <a href="<?php echo $prefix; ?>auth/logout.php" onclick="return confirm('هل أنت متأكد من تسجيل الخروج؟')">
                <?php echo get_icon('logout', 'sidebar-icon text-danger'); ?>
                تسجيل الخروج
            </a>
        </li>
    </ul>
</nav>
