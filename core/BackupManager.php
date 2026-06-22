<?php
namespace AQNEX\Core;

class BackupManager {
    private $db;
    private $backupDir;

    public function __construct($dbConnection) {
        $this->db = $dbConnection;
        $this->backupDir = str_replace('\\', '/', realpath(__DIR__ . '/../')) . '/backups/';
        if (!is_dir($this->backupDir)) {
            mkdir($this->backupDir, 0777, true);
        }
    }

    /**
     * إنشاء نسخة احتياطية كاملة (قاعدة بيانات + مرفقات)
     */
    public function createBackup($type = 'manual', $user = 'system') {
        $date = date('Y-m-d_H-i-s');
        $filename = "backup_{$date}_{$type}";
        $sqlPath = $this->backupDir . $filename . ".sql";
        $zipPath = $this->backupDir . $filename . ".zip";

        // 1. تحديد مسار mysqldump
        // نبحث أولاً في المجلد المحمول runtime، ثم نلجأ للمتغير البيئي لنظام التشغيل
        $mysqldumpPath = str_replace('\\', '/', realpath(__DIR__ . '/../')) . '/runtime/mariadb/bin/mysqldump.exe';
        if (!file_exists($mysqldumpPath)) {
            $mysqldumpPath = 'mysqldump'; // استخدام المتغير البيئي للويندوز كخيار بديل
        }
        
        // تشغيل الأمر للتصدير
        // نستخدم المنفذ 3307 كما هو مخطط للنظام التجاري الموزع، مع البقاء متوافقين مع الوضع الافتراضي 3306 إذا لم يتم نقله بعد
        $port = '3306'; 
        // فحص سريع إذا كان المنفذ الافتراضي للاتصال الحالي ليس 3306
        // في XAMPP الافتراضي هو 3306.
        $command = "\"{$mysqldumpPath}\" -u root tech > \"{$sqlPath}\"";
        
        system($command, $returnVar);

        // إذا فشل استخدام المسار المباشر، نحاول استخدام المحرك العادي للـ SQL بدون مسار مطلق
        if ($returnVar !== 0) {
            $commandFallback = "mysqldump -u root tech > \"{$sqlPath}\"";
            system($commandFallback, $returnVarFallback);
            if ($returnVarFallback !== 0) {
                // فشل التصدير عبر cmd، نلجأ لطريقة PHP Native الاحتياطية لتوليد SQL لمنع توقف المحرك
                $sqlContent = $this->exportDatabasePHP();
                file_put_contents($sqlPath, $sqlContent);
            }
        }

        if (!file_exists($sqlPath)) {
            return ['status' => false, 'message' => 'فشل تصدير ملف قاعدة البيانات.'];
        }

        // 2. ضغط قاعدة البيانات وملفات المرفقات (uploads) في ملف ZIP واحد
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE) === TRUE) {
            $zip->addFile($sqlPath, basename($sqlPath));
            
            // إضافة ملفات uploads
            $uploadsDir = str_replace('\\', '/', realpath(__DIR__ . '/../')) . '/uploads/';
            if (is_dir($uploadsDir)) {
                $files = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($uploadsDir, \RecursiveDirectoryIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::LEAVES_ONLY
                );
                foreach ($files as $name => $file) {
                    if (!$file->isDir()) {
                        $filePath = str_replace('\\', '/', $file->getRealPath());
                        $relativePath = 'uploads/' . substr($filePath, strlen($uploadsDir));
                        $zip->addFile($filePath, $relativePath);
                    }
                }
            }
            $zip->close();
            
            // حذف ملف sql المؤقت
            @unlink($sqlPath);

            // 3. تسجيل العملية في قاعدة البيانات
            $fileSize = file_exists($zipPath) ? filesize($zipPath) : 0;
            $stmt = $this->db->prepare("INSERT INTO system_backups (backup_name, file_path, backup_type, file_size, created_by) VALUES (?, ?, ?, ?, ?)");
            $relativeZipPath = 'backups/' . basename($zipPath);
            $stmt->bind_param("sssis", $filename, $relativeZipPath, $type, $fileSize, $user);
            $stmt->execute();

            return ['status' => true, 'filename' => basename($zipPath), 'size' => $fileSize];
        }

        return ['status' => false, 'message' => 'فشل إنشاء ملف الـ ZIP المضغوط.'];
    }

    /**
     * طريقة PHP احتياطية كاملة لتصدير قاعدة البيانات في حال عدم توفر أدوات السطر البرمجي
     */
    private function exportDatabasePHP() {
        $tables = [];
        $result = $this->db->query("SHOW TABLES");
        while ($row = $result->fetch_row()) {
            $tables[] = $row[0];
        }

        $sql = "SET FOREIGN_KEY_CHECKS=0;\n\n";

        foreach ($tables as $table) {
            // جلب بنية الجدول
            $resStructure = $this->db->query("SHOW CREATE TABLE `$table`");
            $rowStructure = $resStructure->fetch_row();
            $sql .= "DROP TABLE IF EXISTS `$table`;\n";
            $sql .= $rowStructure[1] . ";\n\n";

            // جلب البيانات
            $resData = $this->db->query("SELECT * FROM `$table`");
            $fieldsCount = $resData->field_count;

            while ($row = $resData->fetch_row()) {
                $sql .= "INSERT INTO `$table` VALUES(";
                for ($i = 0; $i < $fieldsCount; $i++) {
                    if (isset($row[$i])) {
                        $escaped = $this->db->real_escape_string($row[$i]);
                        $sql .= "'$escaped'";
                    } else {
                        $sql .= "NULL";
                    }
                    if ($i < ($fieldsCount - 1)) {
                        $sql .= ",";
                    }
                }
                $sql .= ");\n";
            }
            $sql .= "\n\n";
        }
        $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";
        return $sql;
    }
}
