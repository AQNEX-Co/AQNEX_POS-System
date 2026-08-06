<?php
// settings/settings_nav.php
$active_tab = $active_tab ?? 'enterprise';

// Fetch support token for the F2 modal
$support_token_nav = '123';
if (isset($conn) && $conn) {
    try {
        $res_s = $conn->query("SELECT support_token FROM settings WHERE id = 1 LIMIT 1");
        if ($res_s && $row_s = $res_s->fetch_assoc()) {
            $support_token_nav = $row_s['support_token'] ?? '123';
        }
    } catch (Exception $e) {}
} elseif (isset($pdo) && $pdo) {
    try {
        $stmt_s = $pdo->query("SELECT support_token FROM settings WHERE id = 1 LIMIT 1");
        if ($stmt_s && $row_s = $stmt_s->fetch()) {
            $support_token_nav = $row_s['support_token'] ?? '123';
        }
    } catch (Exception $e) {}
}
if (empty($support_token_nav)) {
    $support_token_nav = '123';
}
?>

<!-- Unified Settings & Setup Navigation Menu -->
<div class="settings-nav-wrapper no-print">
    <ul class="nav nav-tabs-custom" id="settingsTabs" role="tablist">
        <!-- المجموعة الأولى: الإعدادات العامة -->
        <span class="nav-group-title"><i class="bi bi-gear-wide ml-1"></i> الإعدادات العامة:</span>
        <li class="nav-item">
            <a class="nav-link <?php echo ($active_tab === 'enterprise') ? 'active' : ''; ?>" href="index.php">
                <i class="bi bi-building"></i> المنشأة والتوطين
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo ($active_tab === 'financial') ? 'active' : ''; ?>" href="financial.php">
                <i class="bi bi-calendar-event"></i> النظام المالي والضرائب
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo ($active_tab === 'currencies') ? 'active' : ''; ?>" href="currencies.php">
                <i class="bi bi-currency-exchange"></i> العملات
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo ($active_tab === 'rules') ? 'active' : ''; ?>" href="rules.php">
                <i class="bi bi-shield-lock"></i> سياسات العمل
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo ($active_tab === 'units') ? 'active' : ''; ?>" href="units.php">
                <i class="bi bi-tags"></i> وحدات القياس
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo ($active_tab === 'modules') ? 'active' : ''; ?>" href="modules.php">
                <i class="bi bi-cpu"></i> موديولات النظام
            </a>
        </li>

        <div class="nav-divider"></div>

        <!-- المجموعة الثانية: تهيئة النظام والتقنيات -->
        <span class="nav-group-title"><i class="bi bi-sliders ml-1"></i> تهيئة النظام:</span>
        <li class="nav-item">
            <a class="nav-link <?php echo ($active_tab === 'printers') ? 'active' : ''; ?>" href="printers.php">
                <i class="bi bi-printer"></i> الطباعة
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo ($active_tab === 'locations') ? 'active' : ''; ?>" href="locations.php">
                <i class="bi bi-geo-alt"></i> الفروع والمواقع
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo ($active_tab === 'backup') ? 'active' : ''; ?>" href="backup.php">
                <i class="bi bi-cloud-arrow-up"></i> النسخ الاحتياطي
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo ($active_tab === 'license') ? 'active' : ''; ?>" href="license.php">
                <i class="bi bi-key"></i> التفعيل والترخيص
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo ($active_tab === 'utilities') ? 'active' : ''; ?>" href="utilities.php">
                <i class="bi bi-tools"></i> أدوات الصيانة
            </a>
        </li>
    </ul>
</div>

<!-- Floating Wrench Support Button -->
<button id="btn-support-trigger" class="btn-support-float" title="وضع مهندس الدعم (F2)" style="position: fixed; bottom: 20px; left: 20px; z-index: 1050; border-radius: 50%; width: 45px; height: 45px; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 10px rgba(0,0,0,0.25); border: 2px solid var(--accent); background: var(--ink-900); cursor: pointer; transition: transform 0.2s ease, background-color 0.2s;">
    <i class="bi bi-wrench" style="font-size: 1.2rem; color: #fff;"></i>
</button>

<!-- F2 Support Modal (appears when user presses F2 or clicks floating button) -->
<div class="modal fade" id="modal-support-f2" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
            <form id="form-support-f2">
                <div class="modal-header bg-dark text-white">
                    <h5 class="modal-title" style="font-size:0.95rem; font-weight:700;"><i class="bi bi-wrench ml-1"></i> فتح وضع الدعم الفني لادارة قاعدة البيانات</h5>
                    <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body text-right">
                    <p class="small text-muted mb-3">يرجى إدخال الرقم السري المعتمد للدخول لوضع مهندس الدعم (الافتراضي هو 123).</p>
                    <div class="form-group mb-3">
                        <input type="password" id="support-f2-input" class="form-control rounded-0 text-center" placeholder="أدخل الرقم السري" required>
                    </div>
                    <div class="form-check text-right mb-2 font-weight-bold" style="padding-right: 20px;">
                        <input class="form-check-input" type="checkbox" id="support-use-adminer" checked>
                        <label class="form-check-label mr-4" for="support-use-adminer">
                            استخدام أداة إدارة قاعدة البيانات المباشرة (Adminer)
                        </label>
                    </div>
                </div>
                <div class="modal-footer d-flex justify-content-between">
                    <button type="button" class="btn-formal-secondary" data-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn-formal-primary">تأكيد الدخول</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Keyboard shortcut listener (F2)
    document.addEventListener('keydown', function(e) {
        if (e.key === 'F2') {
            e.preventDefault();
            $('#modal-support-f2').modal('show');
        }
    });

    // Floating button click listener
    var btnTrigger = document.getElementById('btn-support-trigger');
    if (btnTrigger) {
        btnTrigger.addEventListener('click', function(e) {
            e.preventDefault();
            $('#modal-support-f2').modal('show');
        });
    }

    // Support Login Form submission handler
    var formSupportF2 = document.getElementById('form-support-f2');
    if (formSupportF2) {
        formSupportF2.addEventListener('submit', function(ev) {
            ev.preventDefault();
            var token = document.getElementById('support-f2-input').value.trim();
            if (!token) {
                alert('يرجى إدخال الرقم السري.');
                return;
            }
            var useAdminer = document.getElementById('support-use-adminer') && document.getElementById('support-use-adminer').checked;
            
            // Build support tools redirection URL
            var url = '<?php echo $dir_prefix; ?>support_tools/index.php?auth=' + encodeURIComponent(token);
            if (useAdminer) {
                url += '&use_adminer=1';
            }
            
            window.open(url, '_blank');
            $('#modal-support-f2').modal('hide');
        });
    }
});
</script>
