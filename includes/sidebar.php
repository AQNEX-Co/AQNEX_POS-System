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
    <div class="sidebar-header" style="padding:14px 16px;">
        <!-- اسم النظام -->
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px;">
            <div style="width:28px;height:28px;background:linear-gradient(135deg,#1d4ed8,#0ea5e9);border-radius:4px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <i class="bi bi-grid-3x3-gap-fill" style="color:#fff;font-size:.75rem;"></i>
            </div>
            <div>
                <div style="font-weight:800;font-size:.82rem;color:#fff;letter-spacing:.04em;line-height:1.1;">AQNEX</div>
                <div style="font-size:.62rem;color:#94a3b8;letter-spacing:.06em;line-height:1;">SYSTEM</div>
            </div>
        </div>
        <!-- حقل البحث في القوائم -->
        <div style="position:relative;">
            <i class="bi bi-search" style="position:absolute;top:50%;right:9px;transform:translateY(-50%);color:#64748b;font-size:.72rem;pointer-events:none;"></i>
            <input type="search" id="sidebar-search" placeholder="ابحث في القوائم..." aria-label="بحث في الشريط الجانبي"
                style="width:100%;padding:6px 28px 6px 8px;font-size:0.78rem;border-radius:4px;border:1px solid #2c3b4a;background:#1a2736;color:#cbd5e1;direction:rtl;outline:none;transition:border-color .2s;"
                onfocus="this.style.borderColor='#1d4ed8'" onblur="this.style.borderColor='#2c3b4a'" />
        </div>
    </div>


    <ul class="list-unstyled components">
        <!-- الرئيسية -->
        <li class="<?php echo ($module == 'dashboard') ? 'active' : ''; ?>">
            <a href="<?php echo $prefix; ?>home.php">
                <?php echo get_icon('home', 'sidebar-icon'); ?>
                الرئيسية
            </a>
        </li>

        <!-- 1. تهيئة النظام -->
        <?php if ($is_admin || sidebar_has_access('settings') || sidebar_has_access('users') || sidebar_has_access('box') || sidebar_has_access('journal')): ?>
        <li>
            <?php 
            $is_config_active = in_array($module, ['users', 'box', 'accounts']) || ($module == 'settings' && in_array(basename($_SERVER['PHP_SELF']), ['index.php', 'currencies.php', 'license.php', 'utilities.php', 'backup.php', 'printers.php', 'modules.php']));
            ?>
            <a href="#configSubmenu" data-toggle="collapse" aria-expanded="<?php echo $is_config_active ? 'true' : 'false'; ?>" class="dropdown-toggle">
                <span class="icon-wrapper"><?php echo get_icon('settings', 'sidebar-icon'); ?></span>
                <span class="menu-text">تهيئة النظام</span>
                <span class="arrow-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
                </span>
            </a>

            <ul class="collapse list-unstyled <?php echo $is_config_active ? 'show' : ''; ?>" id="configSubmenu">
                <?php if ($is_admin || sidebar_has_access('box')): ?>
                <li class="<?php echo ($module == 'box') ? 'active' : ''; ?>">
                    <a href="<?php echo $prefix; ?>box/index.php">
                        <span class="icon-wrapper"><?php echo get_icon('bank', 'sidebar-icon'); ?></span>
                        تهيئة الصناديق والخزائن
                    </a>
                </li>
                <?php endif; ?>

                <?php if ($is_admin): ?>
                <li class="<?php echo ($module == 'settings' && basename($_SERVER['PHP_SELF']) == 'currencies.php') ? 'active' : ''; ?>">
                    <a href="<?php echo $prefix; ?>settings/currencies.php">
                        <span class="icon-wrapper"><?php echo get_icon('expenses', 'sidebar-icon'); ?></span>
                        تهيئة العملات والأسعار
                    </a>
                </li>
                <?php endif; ?>

                <?php if ($is_admin || sidebar_has_access('journal')): ?>
                <li class="<?php echo ($module == 'accounts' && basename($_SERVER['PHP_SELF']) == 'accounts.php') ? 'active' : ''; ?>">
                    <a href="<?php echo $prefix; ?>accounting/accounts.php">
                        <span class="icon-wrapper"><?php echo get_icon('list', 'sidebar-icon'); ?></span>
                        الدليل المحاسبي
                    </a>
                </li>
                <?php endif; ?>

                <?php if ($is_admin || sidebar_has_access('users')): ?>
                <li class="<?php echo ($module == 'users') ? 'active' : ''; ?>">
                    <a href="<?php echo $prefix; ?>users/index.php">
                        <span class="icon-wrapper"><?php echo get_icon('users', 'sidebar-icon'); ?></span>
                        المستخدمين والصلاحيات
                    </a>
                </li>
                <?php endif; ?>
        <!-- تغيير كلمة المرور للمستخدم -->
        <?php if ($user_id > 0): ?>
        <li class="<?php echo ($module == 'changeuser') ? 'active' : ''; ?>">
            <a href="<?php echo $prefix; ?>auth/changeuser.php">
                <span class="icon-wrapper"><?php echo get_icon('changeuser', 'sidebar-icon'); ?></span>
                تغيير كلمة مرور المستخدم
            </a>
        </li>
        <?php endif; ?>
                <?php if ($is_admin || sidebar_has_access('settings')): ?>
                <li class="<?php echo ($module == 'settings' && basename($_SERVER['PHP_SELF']) == 'index.php') ? 'active' : ''; ?>">
                    <a href="<?php echo $prefix; ?>settings/index.php">
                        <span class="icon-wrapper"><?php echo get_icon('settings', 'sidebar-icon'); ?></span>
                        إعدادات النظام العامة
                    </a>
                </li>
                <?php endif; ?>

                <?php if ($is_admin): ?>
                <li class="<?php echo ($module == 'settings' && basename($_SERVER['PHP_SELF']) == 'license.php') ? 'active' : ''; ?>">
                    <a href="<?php echo $prefix; ?>settings/license.php">
                        <span class="icon-wrapper"><?php echo get_icon('bolt', 'sidebar-icon'); ?></span>
                        الترخيص وتحديثات النظام
                    </a>
                </li>
                <li class="<?php echo ($module == 'settings' && basename($_SERVER['PHP_SELF']) == 'utilities.php') ? 'active' : ''; ?>">
                    <a href="<?php echo $prefix; ?>settings/utilities.php">
                        <span class="icon-wrapper"><i class="bi bi-wrench-adjustable sidebar-icon"></i></span>
                        الأدوات المساعدة والتقنية
                    </a>
                </li>
                <?php endif; ?>
                <!-- يمكنك إضافة صفحات تهيئة إضافية هنا -->
            </ul>
        </li>
        <?php endif; ?>

        <!-- 2. إدارة المخزون -->
        <?php if ($is_admin || sidebar_has_access('categories') || sidebar_has_access('products')): ?>
        <li>
            <?php 
            $is_inventory_active = in_array($module, ['categories', 'products', 'inventory']);
            ?>
            <a href="#inventorySubmenu" data-toggle="collapse" aria-expanded="<?php echo $is_inventory_active ? 'true' : 'false'; ?>" class="dropdown-toggle">
                <span class="icon-wrapper"><?php echo get_icon('products', 'sidebar-icon'); ?></span>
                <span class="menu-text">إدارة المخزون</span>
                <span class="arrow-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
                </span>
            </a>
            <ul class="collapse list-unstyled <?php echo $is_inventory_active ? 'show' : ''; ?>" id="inventorySubmenu">
                <?php if ($is_admin || sidebar_has_access('categories')): ?>
                <li class="<?php echo ($module == 'categories') ? 'active' : ''; ?>">
                    <a href="<?php echo $prefix; ?>categories/index.php">
                        <span class="icon-wrapper"><?php echo get_icon('categories', 'sidebar-icon'); ?></span>
                        أصناف وتصنيفات السلع
                    </a>
                </li>
                <?php endif; ?>

                <?php if ($is_admin || sidebar_has_access('products')): ?>
                <li class="<?php echo ($module == 'products' && basename($_SERVER['PHP_SELF']) == 'index.php') ? 'active' : ''; ?>">
                    <a href="<?php echo $prefix; ?>products/index.php">
                        <span class="icon-wrapper"><?php echo get_icon('products', 'sidebar-icon'); ?></span>
                        جرد البضائع والكميات
                    </a>
                </li>
                <li class="<?php echo ($module == 'products' && basename($_SERVER['PHP_SELF']) == 'inventory.php') ? 'active' : ''; ?>">
                    <a href="<?php echo $prefix; ?>products/inventory.php">
                        <span class="icon-wrapper"><?php echo get_icon('inventory', 'sidebar-icon'); ?></span>
                        مراقبة وتسوية المخزون
                    </a>
                </li>
                <?php endif; ?>
                
                <?php if (function_exists('is_module_enabled') && is_module_enabled('label_printing') && ($is_admin || sidebar_has_access('products'))): ?>
                <li class="<?php echo ($module == 'products' && basename($_SERVER['PHP_SELF']) == 'print_labels.php') ? 'active' : ''; ?>">
                    <a href="<?php echo $prefix; ?>products/print_labels.php">
                        <span class="icon-wrapper"><?php echo get_icon('archive', 'sidebar-icon'); ?></span>
                        طباعة ملصقات الباركود
                    </a>
                </li>
                <?php endif; ?>
                <!-- يمكنك إضافة صفحات مخزون إضافية هنا -->
            </ul>
        </li>
        <?php endif; ?>

        <!-- 3. إدارة الحسابات الأستاذ العام -->
        <?php if ($is_admin || sidebar_has_access('journal') || sidebar_has_access('receipts') || sidebar_has_access('expenses') || sidebar_has_access('reports')): ?>
        <li>
            <?php 
            $is_ledger_active = in_array($module, ['accounts', 'journal', 'receipts', 'expenses', 'reports']);
            ?>
            <a href="#ledgerSubmenu" data-toggle="collapse" aria-expanded="<?php echo $is_ledger_active ? 'true' : 'false'; ?>" class="dropdown-toggle">
                <span class="icon-wrapper"><?php echo get_icon('bank', 'sidebar-icon'); ?></span>
                <span class="menu-text">إدارة الحسابات الأستاذ العام</span>
                <span class="arrow-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
                </span>
            </a>
            <ul class="collapse list-unstyled <?php echo $is_ledger_active ? 'show' : ''; ?>" id="ledgerSubmenu">
                <?php if ($is_admin || sidebar_has_access('journal')): ?>
                <li class="<?php echo ($module == 'accounts' && basename($_SERVER['PHP_SELF']) == 'accounts.php') ? 'active' : ''; ?>">
                    <a href="<?php echo $prefix; ?>accounting/accounts.php">
                        <span class="icon-wrapper"><?php echo get_icon('list', 'sidebar-icon'); ?></span>
                        الدليل المحاسبي  
                    </a>
                </li>
                <li class="<?php echo ($module == 'journal') ? 'active' : ''; ?>">
                    <a href="<?php echo $prefix; ?>accounting/journal.php">
                        <span class="icon-wrapper"><?php echo get_icon('journal', 'sidebar-icon'); ?></span>
                        دفتر القيود اليومية
                    </a>
                </li>
                <?php endif; ?>

                <?php if ($is_admin || sidebar_has_access('receipts')): ?>
                <li class="<?php echo ($module == 'receipts') ? 'active' : ''; ?>">
                    <a href="<?php echo $prefix; ?>receipts/index.php">
                        <span class="icon-wrapper"><?php echo get_icon('receipts', 'sidebar-icon'); ?></span>
                        سندات المقبوضات (القبض)
                    </a>
                </li>
                <?php endif; ?>
                
                <?php if ($is_admin || sidebar_has_access('expenses')): ?>
                <li class="<?php echo ($module == 'expenses') ? 'active' : ''; ?>">
                    <a href="<?php echo $prefix; ?>expenses/index.php">
                        <span class="icon-wrapper"><?php echo get_icon('expenses', 'sidebar-icon'); ?></span>
                        سندات المدفوعات (الصرف)
                    </a>
                </li>
                <?php endif; ?>

                <?php if ($is_admin || sidebar_has_access('reports')): ?>
                <li class="<?php echo ($module == 'reports' && basename($_SERVER['PHP_SELF']) == 'daily.php') ? 'active' : ''; ?>">
                    <a href="<?php echo $prefix; ?>reports/daily.php">
                        <span class="icon-wrapper"><?php echo get_icon('reports', 'sidebar-icon'); ?></span>
                        ملخص الحركة اليومية المالي
                    </a>
                </li>
                <li class="<?php echo ($module == 'reports' && basename($_SERVER['PHP_SELF']) == 'history.php') ? 'active' : ''; ?>">
                    <a href="<?php echo $prefix; ?>reports/history.php">
                        <span class="icon-wrapper"><i class="bi bi-calendar-range sidebar-icon"></i></span>
                        كشف الحركات بفترة زمنية
                    </a>
                </li>
                <?php endif; ?>
                <!-- يمكنك إضافة صفحات حسابات إضافية هنا -->
            </ul>
        </li>
        <?php endif; ?>

        <!-- 4. إدارة المشتريات والموردين -->
        <?php if ($is_admin || sidebar_has_access('purchases') || sidebar_has_access('suppliers')): ?>
        <li>
            <?php 
            $is_purchases_active = in_array($module, ['purchases', 'suppliers']);
            ?>
            <a href="#purchasesSubmenu" data-toggle="collapse" aria-expanded="<?php echo $is_purchases_active ? 'true' : 'false'; ?>" class="dropdown-toggle">
                <span class="icon-wrapper"><?php echo get_icon('purchases', 'sidebar-icon'); ?></span>
                <span class="menu-text">إدارة المشتريات والموردين</span>
                <span class="arrow-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
                </span>
            </a>
            <ul class="collapse list-unstyled <?php echo $is_purchases_active ? 'show' : ''; ?>" id="purchasesSubmenu">
                <?php if ($is_admin || sidebar_has_access('purchases')): ?>
                <li class="<?php echo ($module == 'purchases' && basename($_SERVER['PHP_SELF']) == 'create.php') ? 'active' : ''; ?>">
                    <a href="<?php echo $prefix; ?>purchases/create.php">
                        <span class="icon-wrapper"><?php echo get_icon('plus', 'sidebar-icon'); ?></span>
                        فاتورة شراء جديدة
                    </a>
                </li>
                <li class="<?php echo ($module == 'purchases' && basename($_SERVER['PHP_SELF']) == 'index.php') ? 'active' : ''; ?>">
                    <a href="<?php echo $prefix; ?>purchases/index.php">
                        <span class="icon-wrapper"><?php echo get_icon('list', 'sidebar-icon'); ?></span>
                        سجل فواتير المشتريات
                    </a>
                </li>
                <li class="<?php echo ($module == 'purchases' && basename($_SERVER['PHP_SELF']) == 'returns.php') ? 'active' : ''; ?>">
                    <a href="<?php echo $prefix; ?>purchases/returns.php">
                        <span class="icon-wrapper"><?php echo get_icon('return', 'sidebar-icon'); ?></span>
                        مردودات ومسترجعات الشراء
                    </a>
                </li>
                <li class="<?php echo ($module == 'purchases' && basename($_SERVER['PHP_SELF']) == 'import.php') ? 'active' : ''; ?>">
                    <a href="<?php echo $prefix; ?>purchases/import.php">
                        <span class="icon-wrapper"><i class="bi bi-file-earmark-arrow-up sidebar-icon"></i></span>
                        استيراد فواتير المشتريات
                    </a>
                </li>
                <?php endif; ?>
                
                <?php if ($is_admin || sidebar_has_access('suppliers')): ?>
                <li class="<?php echo ($module == 'suppliers') ? 'active' : ''; ?>">
                    <a href="<?php echo $prefix; ?>suppliers/index.php">
                        <span class="icon-wrapper"><?php echo get_icon('suppliers', 'sidebar-icon'); ?></span>
                        دليل وبيانات الموردين
                    </a>
                </li>
                <?php endif; ?>
                <!-- يمكنك إضافة صفحات مشتريات إضافية هنا -->
            </ul>
        </li>
        <?php endif; ?>

        <!-- 5. إدارة المبيعات والعملاء -->
        <?php if ($is_admin || sidebar_has_access('sales') || sidebar_has_access('customers')): ?>
        <li>
            <?php 
            $is_sales_active = in_array($module, ['sales', 'customers', 'installments', 'repair']);
            ?>
            <a href="#salesSubmenu" data-toggle="collapse" aria-expanded="<?php echo $is_sales_active ? 'true' : 'false'; ?>" class="dropdown-toggle">
                <span class="icon-wrapper"><?php echo get_icon('sales', 'sidebar-icon'); ?></span>
                <span class="menu-text">إدارة المبيعات والعملاء</span>
                <span class="arrow-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
                </span>
            </a>
            <ul class="collapse list-unstyled <?php echo $is_sales_active ? 'show' : ''; ?>" id="salesSubmenu">
                <?php if ($is_admin || sidebar_has_access('sales')): ?>
                <li class="<?php echo ($module == 'sales' && basename($_SERVER['PHP_SELF']) == 'create.php') ? 'active' : ''; ?>">
                    <a href="<?php echo $prefix; ?>sales/create.php">
                        <span class="icon-wrapper"><?php echo get_icon('plus', 'sidebar-icon'); ?></span>
                        فاتورة مبيعات جديدة
                    </a>
                </li>
                <li class="<?php echo ($module == 'sales' && basename($_SERVER['PHP_SELF']) == 'index.php') ? 'active' : ''; ?>">
                    <a href="<?php echo $prefix; ?>sales/index.php">
                        <span class="icon-wrapper"><?php echo get_icon('list', 'sidebar-icon'); ?></span>
                        سجل فواتير المبيعات
                    </a>
                </li>
                <li class="<?php echo ($module == 'sales' && basename($_SERVER['PHP_SELF']) == 'returns.php') ? 'active' : ''; ?>">
                    <a href="<?php echo $prefix; ?>sales/returns.php">
                        <span class="icon-wrapper"><?php echo get_icon('return', 'sidebar-icon'); ?></span>
                        مردودات المبيعات
                    </a>
                </li>
                <?php endif; ?>

                <?php 
                @include_once(__DIR__ . '/modules.php');
                if (function_exists('is_module_enabled') && is_module_enabled('installments') && ($is_admin || sidebar_has_access('sales'))): 
                ?>
                <li class="<?php echo ($module == 'installments') ? 'active' : ''; ?>">
                    <a href="<?php echo $prefix; ?>installments/plans.php">
                        <span class="icon-wrapper"><?php echo get_icon('money', 'sidebar-icon text-success'); ?></span>
                        جدولة الأقساط والتحصيل
                    </a>
                </li>
                <?php endif; ?>

                <?php 
                if (function_exists('is_module_enabled') && is_module_enabled('repair_service') && ($is_admin || sidebar_has_access('sales'))): 
                ?>
                <li class="<?php echo ($module == 'repair') ? 'active' : ''; ?>">
                    <a href="<?php echo $prefix; ?>repair/index.php">
                        <span class="icon-wrapper"><?php echo get_icon('briefcase', 'sidebar-icon text-info'); ?></span>
                        صيانة الأجهزة والأعطال
                    </a>
                </li>
                <?php endif; ?>

                <?php if ($is_admin || sidebar_has_access('customers')): ?>
                <li class="<?php echo ($module == 'customers') ? 'active' : ''; ?>">
                    <a href="<?php echo $prefix; ?>customers/index.php">
                        <span class="icon-wrapper"><?php echo get_icon('customers', 'sidebar-icon'); ?></span>
                        دليل وبيانات العملاء
                    </a>
                </li>
                <?php endif; ?>
                <!-- يمكنك إضافة صفحات مبيعات إضافية هنا -->
            </ul>
        </li>
        <?php endif; ?>



        <!-- تسجيل الخروج -->
        <li>
            <a href="<?php echo $prefix; ?>auth/logout.php" onclick="return confirm('هل أنت متأكد من تسجيل الخروج؟')">
                <?php echo get_icon('logout', 'sidebar-icon text-danger'); ?>
                تسجيل الخروج من النظام
            </a>
        </li>
    </ul>
</nav>