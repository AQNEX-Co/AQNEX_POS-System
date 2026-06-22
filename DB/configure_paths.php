<?php
/**
 * تعديل مسارات ملفات تكوين الخوادم (httpd.conf, php.ini, my.ini)
 * لتعمل بمسارات مطلقة ديناميكية تناسب خيار مسار التثبيت المختار من العميل.
 */

if (php_sapi_name() !== 'cli') {
    die("هذا السكربت مخصص للتشغيل عبر CLI فقط أثناء التثبيت.");
}

$installPath = isset($argv[1]) ? trim($argv[1]) : '';
if (empty($installPath)) {
    die("خطأ: يرجى تمرير مسار التثبيت كمعامل.");
}

// توحيد المسارات بالشرطة المائلة للأمام متوافقة مع Apache/PHP/MariaDB
$installPath = str_replace('\\', '/', $installPath);
$installPath = rtrim($installPath, '/');

$apacheConfPath = $installPath . '/runtime/apache/conf/httpd.conf';
$phpIniPath     = $installPath . '/runtime/php/php.ini';
$mariadbCnfPath = $installPath . '/runtime/mariadb/my.ini';

echo "مسار التثبيت المكتشف: $installPath\n";

// 1. تحديث إعدادات Apache
if (file_exists($apacheConfPath)) {
    $confContent = file_get_contents($apacheConfPath);
    
    // تعديل جذر الخادم SRVROOT
    $confContent = preg_replace(
        '/Define\s+SRVROOT\s+".*"/', 
        'Define SRVROOT "' . $installPath . '/runtime/apache"', 
        $confContent
    );
    
    // تعديل منفذ الاستماع
    $confContent = preg_replace(
        '/Listen\s+\d+/', 
        'Listen 8181', 
        $confContent
    );
    
    // تعديل مسار كود التطبيق DocumentRoot
    $confContent = preg_replace(
        '/DocumentRoot\s+".*"/', 
        'DocumentRoot "' . $installPath . '/app"', 
        $confContent
    );
    
    $confContent = preg_replace(
        '/<Directory\s+".*">/', 
        '<Directory "' . $installPath . '/app">', 
        $confContent
    );

    // البحث عن ملفات مكتبات OpenSSL ديناميكياً لمنع فشل تحميل إضافات curl و openssl في Apache على ويندوز
    $libcrypto = '';
    $libssl = '';
    $phpDir = $installPath . '/runtime/php';
    if (is_dir($phpDir)) {
        $files = scandir($phpDir);
        foreach ($files as $file) {
            if (preg_match('/^libcrypto-.*\.dll$/i', $file)) {
                $libcrypto = $file;
            }
            if (preg_match('/^libssl-.*\.dll$/i', $file)) {
                $libssl = $file;
            }
        }
    }

    $loadFiles = '';
    if (!empty($libcrypto)) {
        $loadFiles .= "LoadFile \"" . $installPath . "/runtime/php/" . $libcrypto . "\"\n";
    }
    if (!empty($libssl)) {
        $loadFiles .= "LoadFile \"" . $installPath . "/runtime/php/" . $libssl . "\"\n";
    }

    // إضافة إعدادات تشغيل PHP
    if (strpos($confContent, 'php_module') === false) {
        $phpConfig = "\n\n# PHP Integration\n" .
                     $loadFiles .
                     "LoadModule php_module \"" . $installPath . "/runtime/php/php8apache2_4.dll\"\n" .
                     "PHPIniDir \"" . $installPath . "/runtime/php\"\n" .
                     "AddType application/x-httpd-php .php\n";
        $confContent .= $phpConfig;
    } else {
        // تحديث المسارات إذا كانت موجودة بالفعل
        $confContent = preg_replace(
            '/LoadModule\s+php_module\s+".*"/', 
            'LoadModule php_module "' . $installPath . '/runtime/php/php8apache2_4.dll"', 
            $confContent
        );
        $confContent = preg_replace(
            '/PHPIniDir\s+".*"/', 
            'PHPIniDir "' . $installPath . '/runtime/php"', 
            $confContent
        );
        // إزالة أسطر LoadFile القديمة إن وجدت لتجنب التكرار
        $confContent = preg_replace('/LoadFile\s+".*\/runtime\/php\/lib(crypto|ssl)-.*\.dll"\s*\n/', '', $confContent);
        // إضافة أسطر LoadFile الجديدة قبل LoadModule مباشرة
        if (!empty($loadFiles)) {
            $confContent = str_replace(
                'LoadModule php_module',
                $loadFiles . 'LoadModule php_module',
                $confContent
            );
        }
    }
    
    // تعديل DirectoryIndex ليفضل index.php
    $confContent = preg_replace(
        '/DirectoryIndex\s+index\.html/', 
        'DirectoryIndex index.php index.html', 
        $confContent
    );

    file_put_contents($apacheConfPath, $confContent);
    echo "✓ تم تحديث ملف إعدادات Apache (httpd.conf) بنجاح.\n";
} else {
    echo "⚠ ملف إعدادات Apache غير موجود على المسار: $apacheConfPath\n";
}

// 2. تحديث إعدادات PHP
if (!file_exists($phpIniPath)) {
    if (file_exists($installPath . '/runtime/php/php.ini-production')) {
        copy($installPath . '/runtime/php/php.ini-production', $phpIniPath);
        echo "✓ تم إنشاء ملف php.ini من النسخة php.ini-production.\n";
    } elseif (file_exists($installPath . '/runtime/php/php.ini-development')) {
        copy($installPath . '/runtime/php/php.ini-development', $phpIniPath);
        echo "✓ تم إنشاء ملف php.ini من النسخة php.ini-development.\n";
    }
}

if (file_exists($phpIniPath)) {
    $iniContent = file_get_contents($phpIniPath);
    
    // تعديل مسار الإضافات extension_dir وإزالة الفاصلة المنقوطة إن وجدت (باستخدام [ \t]* لتفادي دمج الأسطر)
    $iniContent = preg_replace(
        '/;?[ \t]*extension_dir[ \t]*=[ \t]*".*"/', 
        'extension_dir = "' . $installPath . '/runtime/php/ext"', 
        $iniContent
    );

    // تفعيل الإضافات الضرورية في php.ini
    $extensions = ['mysqli', 'mbstring', 'openssl', 'gd', 'curl', 'zip', 'pdo_mysql'];
    foreach ($extensions as $ext) {
        $iniContent = preg_replace(
            '/;extension\s*=\s*' . $ext . '/', 
            'extension=' . $ext, 
            $iniContent
        );
    }
    
    file_put_contents($phpIniPath, $iniContent);
    echo "✓ تم تحديث ملف إعدادات PHP (php.ini) بنجاح.\n";
} else {
    echo "⚠ ملف إعدادات PHP غير موجود على المسار: $phpIniPath\n";
}

// 3. تهيئة مجلد بيانات MariaDB (في حال عدم وجوده)
$mariaDbDataDir = $installPath . '/runtime/mariadb/data';
if (!file_exists($mariaDbDataDir)) {
    echo "جاري تهيئة قاعدة بيانات MariaDB لأول مرة...\n";
    $installDbCmd = '"' . $installPath . '/runtime/mariadb/bin/mariadb-install-db.exe" --datadir="' . $mariaDbDataDir . '"';
    $output = shell_exec($installDbCmd);
    echo $output;
    echo "✓ تم تهيئة قاعدة بيانات MariaDB بنجاح.\n";
}

// 4. تحديث إعدادات MariaDB
$mariadbCnfPath1 = $installPath . '/runtime/mariadb/my.ini';
$mariadbCnfPath2 = $installPath . '/runtime/mariadb/data/my.ini';

// إذا كان الملف موجودًا في مجلد data فقط، نقوم بنسخه للمجلد الرئيسي ليسهل إدارته وقراءته بواسطة الخدمة
if (!file_exists($mariadbCnfPath1) && file_exists($mariadbCnfPath2)) {
    copy($mariadbCnfPath2, $mariadbCnfPath1);
}

$mariadbCnfPath = file_exists($mariadbCnfPath1) ? $mariadbCnfPath1 : $mariadbCnfPath2;

if (file_exists($mariadbCnfPath)) {
    $cnfContent = file_get_contents($mariadbCnfPath);
    
    // تعديل مسار الخادم الرئيسي ومسار مجلد البيانات data
    $cnfContent = preg_replace(
        '/basedir\s*=\s*".*"/', 
        'basedir = "' . $installPath . '/runtime/mariadb"', 
        $cnfContent
    );
    
    $cnfContent = preg_replace(
        '/datadir\s*=\s*".*"/', 
        'datadir = "' . $installPath . '/runtime/mariadb/data"', 
        $cnfContent
    );

    // تعديل منفذ الاستماع لقاعدة البيانات لمنع التعارض مع منفذ MySQL الافتراضي
    if (strpos($cnfContent, 'port') === false) {
        $cnfContent = str_replace('[mysqld]', "[mysqld]\nport = 3307", $cnfContent);
    } else {
        $cnfContent = preg_replace(
            '/port\s*=\s*\d+/', 
            'port = 3307', 
            $cnfContent
        );
    }
    
    file_put_contents($mariadbCnfPath, $cnfContent);
    echo "✓ تم تحديث ملف إعدادات MariaDB (my.ini) بنجاح.\n";
} else {
    echo "⚠ ملف إعدادات MariaDB غير موجود على المسار: $mariadbCnfPath\n";
}

// 5. تحديث منفذ الاتصال في تطبيق الويب (connect.php)
$connectPhpPath = $installPath . '/app/includes/connect.php';
if (file_exists($connectPhpPath)) {
    $connContent = file_get_contents($connectPhpPath);
    // تحديث المنفذ الافتراضي إلى 3307
    $connContent = preg_replace(
        '/new mysqli\(\$servername,\s*\$username,\s*\$password,\s*\$dbname\)/',
        'new mysqli($servername, $username, $password, $dbname, 3307)',
        $connContent
    );
    file_put_contents($connectPhpPath, $connContent);
    echo "✓ تم تحديث منفذ قاعدة البيانات في ملف الاتصال (connect.php) إلى 3307 بنجاح.\n";
} else {
    echo "⚠ ملف الاتصال connect.php غير موجود على المسار: $connectPhpPath\n";
}

echo "✓ تم الانتهاء من جميع الإعدادات النسبية للمسارات بنجاح.\n";
?>
