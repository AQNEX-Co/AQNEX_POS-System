<?php
require_once(__DIR__ . '/../app/Services/ConfigService.php');
$industry_type = \AQNEX\Services\ConfigService::get('industry_type', 'General');

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

// تحديد القائمة النشطة حالياً بناءً على الصفحة والـ Module لتوسيع القوائم الفرعية تلقائياً
$is_ai_active = ($module == 'ai');
$is_sales_active = in_array($module, ['sales', 'pos', 'quotes', 'returns_sales', 'cancellations']);
$is_purchases_active = in_array($module, ['purchases', 'suppliers', 'purchase_returns', 'requisitions']);
$is_inventory_active = in_array($module, ['categories', 'products', 'inventory', 'serials', 'batches', 'transfer']);
$is_repair_active = ($module == 'repair');
$is_finance_active = in_array($module, ['box', 'banks', 'receipts', 'expenses', 'finance_transfer', 'daily_close']);
$is_accounting_active = in_array($module, ['accounts', 'journal', 'journal_entry', 'ledger', 'vouchers', 'receipt_voucher', 'payment_voucher', 'trial_balance', 'cost_centers', 'taxes']);
$is_crm_active = in_array($module, ['customers', 'installments', 'loyalty']);
$is_hr_active = in_array($module, ['employees', 'commissions', 'users']) && ($module !== 'settings');
$is_reports_active = ($module == 'reports');
$is_settings_active = ($module == 'settings');
?>

<nav id="sidebar" class="no-print">
    <div class="sidebar-header" style="padding:14px 16px;">

        <!-- حقل البحث في القوائم -->
        <div style="position:relative;">
            <i class="bi bi-search" style="position:absolute;top:50%;right:9px;transform:translateY(-50%);color:#64748b;font-size:.72rem;pointer-events:none;"></i>
            <input type="search" id="sidebar-search" placeholder="ابحث في القوائم..." aria-label="بحث في الشريط الجانبي"
                style="width:100%;padding:6px 28px 6px 8px;font-size:0.78rem;border-radius:4px;border:1px solid #2c3b4a;background:#1a2736;color:#cbd5e1;direction:rtl;outline:none;transition:border-color .2s;"
                onfocus="this.style.borderColor='#1d4ed8'" onblur="this.style.borderColor='#2c3b4a'" />
        </div>
    </div>

    <ul class="list-unstyled components">
        
        <!-- لوحة التحكم الرئيسية (منفصلة لحالها في البداية) -->
        <li class="<?php echo ($module == 'dashboard') ? 'active' : ''; ?>">
            <a href="<?php echo $prefix; ?>home.php">
                <span class="icon-wrapper"><i class="bi bi-house-door"></i></span>
                <span class="menu-text">لوحة التحكم</span>
            </a>
        </li>

        <!-- عنوان فرعي للإدارات والأقسام
        <li style="padding: 15px 16px 5px; font-size: 0.65rem; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: 1.2px; border-top: 1px solid rgba(255,255,255,0.03); margin-top: 5px;">
            الإدارات والأنظمة
        </li> -->

        <!-- 1. AQNEX AI (AI Studio) -->
        <li class="<?php echo $is_ai_active ? 'active' : ''; ?>">
            <a href="#aiSubmenu" data-toggle="collapse" aria-expanded="<?php echo $is_ai_active ? 'true' : 'false'; ?>" class="dropdown-toggle">
                <span class="icon-wrapper"><i class="bi bi-cpu"></i></span>
                <span class="menu-text">AQNEX AI</span>
                <span class="arrow-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
                </span>
            </a>
            <ul class="collapse list-unstyled <?php echo $is_ai_active ? 'show' : ''; ?>" id="aiSubmenu">
                <li class="<?php echo ($module == 'settings' && isset($_GET['tab']) && $_GET['tab'] == 'assistant') ? 'active' : ''; ?>">
                    <a href="<?php echo $prefix; ?>settings/index.php?tab=assistant">
                        <span class="icon-wrapper"><i class="bi bi-robot"></i></span>
                        مساعد المدير الذكي
                    </a>
                </li>
                <li>
                    <a href="<?php echo $prefix; ?>#">
                        <span class="icon-wrapper"><i class="bi bi-graph-down"></i></span>
                        التنبؤ بنقص المخزون
                    </a>
                </li>
                <li>
                    <a href="<?php echo $prefix; ?>#">
                        <span class="icon-wrapper"><i class="bi bi-shield-exclamation"></i></span>
                        تحليل المخاطر الائتمانية
                    </a>
                </li>
            </ul>
        </li>

        <!-- 7. ادارة الحسابات (General Accounting) -->
        <?php if ($is_admin || sidebar_has_access('journal')): ?>
        <li class="<?php echo $is_accounting_active ? 'active' : ''; ?>">
            <a href="#accountingSubmenu" data-toggle="collapse" aria-expanded="<?php echo $is_accounting_active ? 'true' : 'false'; ?>" class="dropdown-toggle">
                <span class="icon-wrapper"><i class="bi bi-calculator"></i></span>
                <span class="menu-text">ادارة الحسابات</span>
                <span class="arrow-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
                </span>
            </a>
            <ul class="collapse list-unstyled <?php echo $is_accounting_active ? 'show' : ''; ?>" id="accountingSubmenu">
                <li class="<?php echo ($module == 'accounts' && basename($_SERVER['PHP_SELF']) == 'accounts.php') ? 'active' : ''; ?>">
                    <a href="<?php echo $prefix; ?>accounting/accounts.php">
                        <span class="icon-wrapper"><i class="bi bi-diagram-3"></i></span>
الدليل المحاسبي                    </a>
                </li>
                <li class="<?php echo ($module == 'journal') ? 'active' : ''; ?>">
                    <a href="<?php echo $prefix; ?>accounting/journal.php">
                        <span class="icon-wrapper"><i class="bi bi-journal-text"></i></span>
                        قيود اليومية
                    </a>
                </li>

                <li class="<?php echo ($module == 'receipt_voucher') ? 'active' : ''; ?>">
                    <a href="<?php echo $prefix; ?>accounting/receipt_voucher.php">
                        <span class="icon-wrapper" style="color:#10b981;"><i class="bi bi-arrow-down-circle-fill"></i></span>
                        سند القبض
                    </a>
                </li>
                <li class="<?php echo ($module == 'payment_voucher') ? 'active' : ''; ?>">
                    <a href="<?php echo $prefix; ?>accounting/payment_voucher.php">
                        <span class="icon-wrapper" style="color:#ef4444;"><i class="bi bi-arrow-up-circle-fill"></i></span>
                        سند الصرف
                    </a>
                </li>
                <li class="<?php echo ($module == 'ledger') ? 'active' : ''; ?>">
                    <a href="<?php echo $prefix; ?>accounting/ledger.php">
                        <span class="icon-wrapper"><i class="bi bi-folder2-open"></i></span>
                        دفتر الأستاذ
                    </a>
                </li>
                <li class="<?php echo ($module == 'trial_balance') ? 'active' : ''; ?>">
                    <a href="<?php echo $prefix; ?>accounting/trial_balance.php">
                        <span class="icon-wrapper"><i class="bi bi-bar-chart-steps"></i></span>
                        ميزان المراجعة
                    </a>
                </li>
            </ul>
        </li>
        <?php endif; ?>
        <!-- 2. إدارة المبيعات  (Sales & POS) -->
        <?php if ($is_admin || sidebar_has_access('sales')): ?>
        <li class="<?php echo $is_sales_active ? 'active' : ''; ?>">
            <a href="#salesSubmenu" data-toggle="collapse" aria-expanded="<?php echo $is_sales_active ? 'true' : 'false'; ?>" class="dropdown-toggle">
                <span class="icon-wrapper"><i class="bi bi-cart"></i></span>
                <span class="menu-text">إدارة المبيعات </span>
                <span class="arrow-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
                </span>
            </a>
            <ul class="collapse list-unstyled <?php echo $is_sales_active ? 'show' : ''; ?>" id="salesSubmenu">
                <!-- <li>
                    <a href="<?php echo $prefix; ?>sales/create.php">
                        <span class="icon-wrapper"><i class="bi bi-cpu-fill"></i></span>
                        نقطة البيع (POS)
                    </a>
                </li> -->
                <li class="<?php echo ($module == 'sales' && basename($_SERVER['PHP_SELF']) == 'create.php') ? 'active' : ''; ?>">
                    <a href="<?php echo $prefix; ?>sales/create.php">
                        <span class="icon-wrapper"><i class="bi bi-file-earmark-plus"></i></span>
                        فاتورة مبيعات
                    </a>
                </li>
                <li class="<?php echo ($module == 'sales' && basename($_SERVER['PHP_SELF']) == 'index.php') ? 'active' : ''; ?>">
                    <a href="<?php echo $prefix; ?>sales/index.php">
                        <span class="icon-wrapper"><i class="bi bi-file-earmark-plus"></i></span>
                        سجل المبيعات
                    </a>
                </li>
                <li class="<?php echo ($module == 'sales' && basename($_SERVER['PHP_SELF']) == 'returns.php') ? 'active' : ''; ?>">
                    <a href="<?php echo $prefix; ?>sales/returns.php">
                        <span class="icon-wrapper"><i class="bi bi-arrow-counterclockwise"></i></span>
                        مرتجع مبيعات
                    </a>
                </li>
                <li>
                    <a href="<?php echo $prefix; ?>#">
                        <span class="icon-wrapper"><i class="bi bi-file-earmark-text"></i></span>
                        عروض الأسعار
                    </a>
                </li>
            </ul>
        </li>
        <?php endif; ?>

        <!-- 3. إدارة المشتريات والموردين (Purchases & Suppliers) -->
        <?php if ($is_admin || sidebar_has_access('purchases') || sidebar_has_access('suppliers')): ?>
        <li class="<?php echo $is_purchases_active ? 'active' : ''; ?>">
            <a href="#purchasesSubmenu" data-toggle="collapse" aria-expanded="<?php echo $is_purchases_active ? 'true' : 'false'; ?>" class="dropdown-toggle">
                <span class="icon-wrapper"><i class="bi bi-bag-check"></i></span>
                <span class="menu-text">إدارة المشتريات </span>
                <span class="arrow-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
                </span>
            </a>
            <ul class="collapse list-unstyled <?php echo $is_purchases_active ? 'show' : ''; ?>" id="purchasesSubmenu">
                <?php if ($is_admin || sidebar_has_access('purchases')): ?>
                <li class="<?php echo ($module == 'purchases' && basename($_SERVER['PHP_SELF']) == 'create.php') ? 'active' : ''; ?>">
                    <a href="<?php echo $prefix; ?>purchases/create.php">
                        <span class="icon-wrapper"><i class="bi bi-cart-plus"></i></span>
                        فاتورة مشتريات
                    </a>
                </li>
                <li class="<?php echo ($module == 'purchases' && basename($_SERVER['PHP_SELF']) == 'index.php') ? 'active' : ''; ?>">
                    <a href="<?php echo $prefix; ?>purchases/index.php">
                        <span class="icon-wrapper"><i class="bi bi-file-earmark-plus"></i></span>
                        سجل المشتريات
                    </a>
                </li>
                <li class="<?php echo ($module == 'purchases' && basename($_SERVER['PHP_SELF']) == 'returns.php') ? 'active' : ''; ?>">
                    <a href="<?php echo $prefix; ?>purchases/returns.php">
                        <span class="icon-wrapper"><i class="bi bi-arrow-left-right"></i></span>
                        مرتجع مشتريات
                    </a>
                </li>
                <?php endif; ?>
                
                <?php if ($is_admin || sidebar_has_access('suppliers')): ?>
                <li class="<?php echo ($module == 'suppliers') ? 'active' : ''; ?>">
                    <a href="<?php echo $prefix; ?>suppliers/index.php">
                        <span class="icon-wrapper"><i class="bi bi-person-lines-fill"></i></span>
                        إدارة الموردين
                    </a>
                </li>
                <?php endif; ?>
                <li>
                    <a href="<?php echo $prefix; ?>#">
                        <span class="icon-wrapper"><i class="bi bi-journal-check"></i></span>
                        طلبات الشراء
                    </a>
                </li>
            </ul>
        </li>
        <?php endif; ?>

        <!-- 4. إدارة المخزون (Inventory Management) -->
        <?php if ($is_admin || sidebar_has_access('products') || sidebar_has_access('categories')): ?>
        <li class="<?php echo $is_inventory_active ? 'active' : ''; ?>">
            <a href="#inventorySubmenu" data-toggle="collapse" aria-expanded="<?php echo $is_inventory_active ? 'true' : 'false'; ?>" class="dropdown-toggle">
                <span class="icon-wrapper"><i class="bi bi-box-seam"></i></span>
                <span class="menu-text">إدارة المخزون</span>
                <span class="arrow-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
                </span>
            </a>
            <ul class="collapse list-unstyled <?php echo $is_inventory_active ? 'show' : ''; ?>" id="inventorySubmenu">
                <?php if ($is_admin || sidebar_has_access('categories')): ?>
                <li class="<?php echo ($module == 'categories') ? 'active' : ''; ?>">
                    <a href="<?php echo $prefix; ?>categories/index.php">
                        <span class="icon-wrapper"><i class="bi bi-grid-fill"></i></span>
مجموعات الاصناف                    </a>
                </li>
                <?php endif; ?>
                <?php if ($is_admin || sidebar_has_access('products')): ?>
                <li class="<?php echo ($module == 'products' && basename($_SERVER['PHP_SELF']) == 'index.php') ? 'active' : ''; ?>">
                    <a href="<?php echo $prefix; ?>products/index.php">
                        <span class="icon-wrapper"><i class="bi bi-tags"></i></span>
                     الاصناف والمنتجات
                    </a>
                </li>
                <?php endif; ?>
                

                <?php if ($is_admin): ?>
                <li class="<?php echo ($module == 'settings' && basename($_SERVER['PHP_SELF']) == 'units.php') ? 'active' : ''; ?>">
                    <a href="<?php echo $prefix; ?>settings/units.php">
                        <span class="icon-wrapper"><i class="bi bi-sliders"></i></span>
                        إدارة الوحدات
                    </a>
                </li>
                <li class="<?php echo ($module == 'inventory' && basename($_SERVER['PHP_SELF']) == 'stock_in.php') ? 'active' : ''; ?>">
                    <a href="<?php echo $prefix; ?>inventory/stock_in.php">
                        <span class="icon-wrapper"><i class="bi bi-box-arrow-in-down"></i></span>
                        التوريد المخزني
                    </a>
                </li>
                <?php endif; ?>

                <li class="<?php echo ($module == 'inventory' && basename($_SERVER['PHP_SELF']) == 'transfers.php') ? 'active' : ''; ?>">
                    <a href="<?php echo $prefix; ?>inventory/transfers.php">
                        <span class="icon-wrapper"><i class="bi bi-arrow-right-left"></i></span>
                        تحويل مخزني
                    </a>
                </li>

                <li class="<?php echo ($module == 'inventory' && basename($_SERVER['PHP_SELF']) == 'adjustments.php') ? 'active' : ''; ?>">
                    <a href="<?php echo $prefix; ?>inventory/adjustments.php">
                        <span class="icon-wrapper"><i class="bi bi-tools"></i></span>
                        تسوية التلفيات
                    </a>
                </li>

                <?php if ($industry_type === 'Grocery'): ?>
                <li class="<?php echo ($module == 'inventory' && basename($_SERVER['PHP_SELF']) == 'near_expiry.php') ? 'active' : ''; ?>">
                    <a href="<?php echo $prefix; ?>inventory/near_expiry.php">
                        <span class="icon-wrapper"><i class="bi bi-calendar-event"></i></span>
                        تواريخ الصلاحية (Batches)
                    </a>
                </li>
                <?php endif; ?>

                <li class="<?php echo ($module == 'inventory' && basename($_SERVER['PHP_SELF']) == 'valuation.php') ? 'active' : ''; ?>">
                    <a href="<?php echo $prefix; ?>inventory/valuation.php">
                        <span class="icon-wrapper"><i class="bi bi-calculator"></i></span>
                        تقييم المخزون (FIFO)
                    </a>
                </li>

                <li class="<?php echo ($module == 'inventory' && basename($_SERVER['PHP_SELF']) == 'audit_log.php') ? 'active' : ''; ?>">
                    <a href="<?php echo $prefix; ?>inventory/audit_log.php">
                        <span class="icon-wrapper"><i class="bi bi-clock-history"></i></span>
                        سجل حركة المخزن
                    </a>
                </li>

                <li class="<?php echo ($module == 'products' && basename($_SERVER['PHP_SELF']) == 'inventory.php') ? 'active' : ''; ?>">
                    <a href="<?php echo $prefix; ?>products/inventory.php">
                        <span class="icon-wrapper"><i class="bi bi-clipboard-check"></i></span>
                        جرد المخزون
                    </a>
                </li>
            </ul>
        </li>
        <?php endif; ?>

        <!-- 5. مركز الصيانة والورشة (Repair Center) -->
        <?php if (($is_admin || sidebar_has_access('sales')) && $industry_type === 'Telecom'): ?>
        <li class="<?php echo $is_repair_active ? 'active' : ''; ?>">
            <a href="#repairSubmenu" data-toggle="collapse" aria-expanded="<?php echo $is_repair_active ? 'true' : 'false'; ?>" class="dropdown-toggle">
                <span class="icon-wrapper"><i class="bi bi-wrench-adjustable-circle"></i></span>
                <span class="menu-text">مركز الصيانة </span>
                <span class="arrow-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
                </span>
            </a>
            <ul class="collapse list-unstyled <?php echo $is_repair_active ? 'show' : ''; ?>" id="repairSubmenu">
                <li class="<?php echo ($module == 'repair') ? 'active' : ''; ?>">
                    <a href="<?php echo $prefix; ?>repair/index.php">
                        <span class="icon-wrapper"><i class="bi bi-tools"></i></span>
                        تذاكر الصيانة
                    </a>
                </li>
                <!-- <li>
                    <a href="<?php echo $prefix; ?>#">
                        <span class="icon-wrapper"><i class="bi bi-display"></i></span>
                        لوحة الفنيين
                    </a>
                </li> -->
                <li>
                    <a href="<?php echo $prefix; ?>#">
                        <span class="icon-wrapper"><i class="bi bi-puzzle"></i></span>
                        قطع الغيار المستهلكة
                    </a>
                </li>
                <li>
                    <a href="<?php echo $prefix; ?>#">
                        <span class="icon-wrapper"><i class="bi bi-file-earmark-diff"></i></span>
                        سجل الاستلام والتسليم
                    </a>
                </li>
            </ul>
        </li>
        <?php endif; ?>
        <!-- 6. الخزينة والبنوك (Finance & Treasury) -->
        <?php if ($is_admin || sidebar_has_access('box') || sidebar_has_access('receipts') || sidebar_has_access('expenses')): ?>
        <li class="<?php echo $is_finance_active ? 'active' : ''; ?>">
            <a href="#financeSubmenu" data-toggle="collapse" aria-expanded="<?php echo $is_finance_active ? 'true' : 'false'; ?>" class="dropdown-toggle">
                <span class="icon-wrapper"><i class="bi bi-bank"></i></span>
                <span class="menu-text">الخزينة والبنوك</span>
                <span class="arrow-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
                </span>
            </a>
            <ul class="collapse list-unstyled <?php echo $is_finance_active ? 'show' : ''; ?>" id="financeSubmenu">
                <?php if ($is_admin || sidebar_has_access('box')): ?>
                <li class="<?php echo ($module == 'box') ? 'active' : ''; ?>">
                    <a href="<?php echo $prefix; ?>box/index.php">
                        <span class="icon-wrapper"><i class="bi bi-wallet2"></i></span>
                        الصناديق المالية
                    </a>
                </li>
                <?php endif; ?>
                <li>
                    <a href="<?php echo $prefix; ?>#">
                        <span class="icon-wrapper"><i class="bi bi-credit-card"></i></span>
                        الحسابات البنكية
                    </a>
                </li>
                <?php if ($is_admin || sidebar_has_access('receipts')): ?>
                <li class="<?php echo ($module == 'receipts') ? 'active' : ''; ?>">
                    <a href="<?php echo $prefix; ?>receipts/index.php">
                        <span class="icon-wrapper"><i class="bi bi-journal-plus"></i></span>
                        سندات القبض (مبيعات)
                    </a>
                </li>
                <?php endif; ?>
                <?php if ($is_admin || sidebar_has_access('expenses')): ?>
                <li class="<?php echo ($module == 'expenses') ? 'active' : ''; ?>">
                    <a href="<?php echo $prefix; ?>expenses/index.php">
                        <span class="icon-wrapper"><i class="bi bi-journal-minus"></i></span>
                        سندات الصرف (مصاريف)
                    </a>
                </li>
                <?php endif; ?>
                <li>
                    <a href="<?php echo $prefix; ?>#">
                        <span class="icon-wrapper"><i class="bi bi-arrow-left-right"></i></span>
                        تحويل مالي
                    </a>
                </li>
                <li>
                    <a href="<?php echo $prefix; ?>#">
                        <span class="icon-wrapper"><i class="bi bi-lock"></i></span>
                        إغلاق اليومية
                    </a>
                </li>
            </ul>
        </li>
        <?php endif; ?>
        <!-- 8. العملاء والائتمان (CRM & Credit) -->
        <?php if ($is_admin || sidebar_has_access('customers') || sidebar_has_access('sales')): ?>
        <li class="<?php echo $is_crm_active ? 'active' : ''; ?>">
            <a href="#crmSubmenu" data-toggle="collapse" aria-expanded="<?php echo $is_crm_active ? 'true' : 'false'; ?>" class="dropdown-toggle">
                <span class="icon-wrapper"><i class="bi bi-people"></i></span>
                <span class="menu-text">ادارة العملاء</span>
                <span class="arrow-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
                </span>
            </a>
            <ul class="collapse list-unstyled <?php echo $is_crm_active ? 'show' : ''; ?>" id="crmSubmenu">
                <?php if ($is_admin || sidebar_has_access('customers')): ?>
                <li class="<?php echo ($module == 'customers') ? 'active' : ''; ?>">
                    <a href="<?php echo $prefix; ?>customers/index.php">
                        <span class="icon-wrapper"><i class="bi bi-person-badge"></i></span>
                        سجل العملاء
                    </a>
                </li>
                <?php endif; ?>
                
                <?php if (function_exists('is_module_enabled') && is_module_enabled('installments') && ($is_admin || sidebar_has_access('sales'))): ?>
                <li class="<?php echo ($module == 'installments') ? 'active' : ''; ?>">
                    <a href="<?php echo $prefix; ?>installments/plans.php">
                        <span class="icon-wrapper"><i class="bi bi-calendar-check"></i></span>
                        إدارة الأقساط
                    </a>
                </li>
                <?php endif; ?>
                <li>
                    <a href="<?php echo $prefix; ?>#">
                        <span class="icon-wrapper"><i class="bi bi-award"></i></span>
                        نظام الولاء
                    </a>
                </li>
            </ul>
        </li>
        <?php endif; ?>

        <!-- 9. الموارد البشرية (Human Resources) -->
        <?php if ($is_admin || sidebar_has_access('users')): ?>
        <li class="<?php echo $is_hr_active ? 'active' : ''; ?>">
            <a href="#hrSubmenu" data-toggle="collapse" aria-expanded="<?php echo $is_hr_active ? 'true' : 'false'; ?>" class="dropdown-toggle">
                <span class="icon-wrapper"><i class="bi bi-person-workspace"></i></span>
                <span class="menu-text">الموارد البشرية</span>
                <span class="arrow-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
                </span>
            </a>
            <ul class="collapse list-unstyled <?php echo $is_hr_active ? 'show' : ''; ?>" id="hrSubmenu">
                <li class="<?php echo ($module == 'users' && basename($_SERVER['PHP_SELF']) == 'index.php') ? 'active' : ''; ?>">
                    <a href="<?php echo $prefix; ?>users/index.php">
                        <span class="icon-wrapper"><i class="bi bi-person-badge-fill"></i></span>
                        الموظفين
                    </a>
                </li>
                <li>
                    <a href="<?php echo $prefix; ?>#">
                        <span class="icon-wrapper"><i class="bi bi-cash-stack"></i></span>
                        عمولات المبيعات/الفنيين
                    </a>
                </li>
            </ul>
        </li>
        <?php endif; ?>

        <!-- 10. التقارير (Reports) -->
        <?php if ($is_admin || sidebar_has_access('reports')): ?>
        <li class="<?php echo $is_reports_active ? 'active' : ''; ?>">
            <a href="#reportsSubmenu" data-toggle="collapse" aria-expanded="<?php echo $is_reports_active ? 'true' : 'false'; ?>" class="dropdown-toggle">
                <span class="icon-wrapper"><i class="bi bi-bar-chart-line"></i></span>
                <span class="menu-text">التقارير</span>
                <span class="arrow-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
                </span>
            </a>
            <ul class="collapse list-unstyled <?php echo $is_reports_active ? 'show' : ''; ?>" id="reportsSubmenu">
                <li class="<?php echo ($module == 'reports' && basename($_SERVER['PHP_SELF']) == 'daily.php') ? 'active' : ''; ?>">
                    <a href="<?php echo $prefix; ?>reports/daily.php">
                        <span class="icon-wrapper"><i class="bi bi-graph-up"></i></span>
                        تقارير مالية
                    </a>
                </li>
                <li class="<?php echo ($module == 'reports' && basename($_SERVER['PHP_SELF']) == 'sales_reports.php') ? 'active' : ''; ?>">
                    <a href="<?php echo $prefix; ?>reports/sales_reports.php">
                        <span class="icon-wrapper"><i class="bi bi-receipt"></i></span>
                        تقارير مبيعات وأرباح
                    </a>
                </li>
                <li>
                    <a href="<?php echo $prefix; ?>#">
                        <span class="icon-wrapper"><i class="bi bi-box"></i></span>
                        تقارير مخزون
                    </a>
                </li>
            </ul>
        </li>
        <?php endif; ?>

        <!-- 11. الإعدادات والتهيئة (Setup & Settings) -->
        <?php if ($is_admin || sidebar_has_access('settings') || sidebar_has_access('users')): ?>
        <li class="<?php echo $is_settings_active ? 'active' : ''; ?>">
            <a href="#settingsSubmenu" data-toggle="collapse" aria-expanded="<?php echo $is_settings_active ? 'true' : 'false'; ?>" class="dropdown-toggle">
                <span class="icon-wrapper"><i class="bi bi-gear-wide-connected"></i></span>
                <span class="menu-text">الإعدادات والتهيئة</span>
                <span class="arrow-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
                </span>
            </a>
            <ul class="collapse list-unstyled <?php echo $is_settings_active ? 'show' : ''; ?>" id="settingsSubmenu">
                <?php if ($is_admin || sidebar_has_access('settings')): ?>
                <li class="<?php echo ($module == 'settings' && basename($_SERVER['PHP_SELF']) == 'index.php') ? 'active' : ''; ?>">
                    <a href="<?php echo $prefix; ?>settings/index.php">
                        <span class="icon-wrapper"><i class="bi bi-info-circle"></i></span>
                        إعدادات النظام
                    </a>
                </li>
                <?php endif; ?>
                <li>
                    <a href="<?php echo $prefix; ?>#">
                        <span class="icon-wrapper"><i class="bi bi-geo-alt"></i></span>
                        إدارة الفروع والمخازن
                    </a>
                </li>
                <?php if ($is_admin || sidebar_has_access('users')): ?>
                <li class="<?php echo ($module == 'users') ? 'active' : ''; ?>">
                    <a href="<?php echo $prefix; ?>users/index.php">
                        <span class="icon-wrapper"><i class="bi bi-shield-lock"></i></span>
                        المستخدمين والصلاحيات
                    </a>
                </li>
                <?php endif; ?>
                <?php if ($is_admin): ?>
                <li class="<?php echo ($module == 'settings' && basename($_SERVER['PHP_SELF']) == 'printers.php') ? 'active' : ''; ?>">
                    <a href="<?php echo $prefix; ?>settings/printers.php">
                        <span class="icon-wrapper"><i class="bi bi-printer"></i></span>
                        إعدادات الطباعة
                    </a>
                </li>
                <li class="<?php echo ($module == 'settings' && basename($_SERVER['PHP_SELF']) == 'backup.php') ? 'active' : ''; ?>">
                    <a href="<?php echo $prefix; ?>settings/backup.php">
                        <span class="icon-wrapper"><i class="bi bi-cloud-arrow-up"></i></span>
                        النسخ الاحتياطي
                    </a>
                </li>
                <li class="<?php echo ($module == 'settings' && basename($_SERVER['PHP_SELF']) == 'license.php') ? 'active' : ''; ?>">
                    <a href="<?php echo $prefix; ?>settings/license.php">
                        <span class="icon-wrapper"><i class="bi bi-key"></i></span>
                        التفعيل والترخيص
                    </a>
                </li>
                <?php endif; ?>
                        <!-- تغيير كلمة المرور للمستخدم -->
        <?php if ($user_id > 0): ?>
        <li class="<?php echo ($module == 'changeuser') ? 'active' : ''; ?>">
            <a href="<?php echo $prefix; ?>auth/changeuser.php">
                <span class="icon-wrapper"><i class="bi bi-key-fill"></i></span>
                تغيير كلمة مرور المستخدم
            </a>
        </li>
        <?php endif; ?>
            </ul>
            
        </li>
        
        <?php endif; ?>
    </ul>
</nav>