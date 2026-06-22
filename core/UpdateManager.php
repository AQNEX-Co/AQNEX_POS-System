<?php
namespace AQNEX\Core;

class UpdateManager {
    private $db;
    private $projectDir;
    private $tempDir;

    public function __construct($dbConnection) {
        $this->db = $dbConnection;
        $this->projectDir = str_replace('\\', '/', realpath(__DIR__ . '/../')) . '/';
        $this->tempDir = $this->projectDir . 'storage/temp_update/';
        if (!is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0777, true);
        }
    }

    /**
     * تطبيق حزمة تحديث مرفعوعة من العميل
     */
    public function applyUpdate($zipFilePath) {
        if (!file_exists($zipFilePath)) {
            return ['status' => false, 'message' => 'ملف حزمة التحديث غير موجود.'];
        }

        // 1. فك ضغط الحزمة إلى مجلد مؤقت للتحقق
        $zip = new \ZipArchive();
        if ($zip->open($zipFilePath) !== TRUE) {
            return ['status' => false, 'message' => 'ملف التحديث تالف أو غير صالح البنية (ليس ملف ZIP).'];
        }

        // تنظيف المجلد المؤقت أولاً
        $this->cleanDir($this->tempDir);
        $zip->extractTo($this->tempDir);
        $zip->close();

        // 2. التحقق من وجود ملف metadata.json
        $metaPath = $this->tempDir . 'metadata.json';
        if (!file_exists($metaPath)) {
            return ['status' => false, 'message' => 'حزمة التحديث لا تحتوي على ملف التعريف metadata.json.'];
        }

        $meta = json_decode(file_get_contents($metaPath), true);
        if (!$meta || !isset($meta['version'])) {
            return ['status' => false, 'message' => 'ملف التعريف metadata.json تالف أو لا يحتوي على رقم الإصدار.'];
        }

        $version = $meta['version'];
        $description = $meta['description'] ?? 'تحديث نظام تلقائي';
        $releaseDate = $meta['release_date'] ?? date('Y-m-d');

        // 3. أخذ نسخة احتياطية كاملة تلقائياً للنظام وقاعدة البيانات قبل الاستبدال للحماية
        $backupManager = new BackupManager($this->db);
        $backupResult = $backupManager->createBackup('daily', 'system_update_auto');
        if (!$backupResult['status']) {
            return ['status' => false, 'message' => 'تم إلغاء التحديث لتعذر أخذ نسخة احتياطية تلقائية: ' . $backupResult['message']];
        }

        // 4. استبدال الملفات البرمجية
        // نمر على كل الملفات المرفقة في مجلد التحديث ونقوم بنسخها للمشروع
        $updateFilesDir = $this->tempDir . 'files/';
        $success = true;
        if (is_dir($updateFilesDir)) {
            $success = $this->copyDirectory($updateFilesDir, $this->projectDir);
        }

        if (!$success) {
            $this->logUpdate($version, $releaseDate, $description, 'failed');
            return ['status' => false, 'message' => 'حدث خطأ أثناء نسخ واستبدال ملفات النظام الجديدة.'];
        }

        // 5. تشغيل ترقيات قاعدة البيانات (SQL Migrations) إن وجدت
        $migrationSqlPath = $this->tempDir . 'migration.sql';
        if (file_exists($migrationSqlPath)) {
            $sqlContent = file_get_contents($migrationSqlPath);
            if (!empty(trim($sqlContent))) {
                if (!$this->executeMultiQuery($sqlContent)) {
                    $this->logUpdate($version, $releaseDate, $description, 'failed');
                    return ['status' => false, 'message' => 'تم تحديث الملفات بنجاح ولكن فشل تطبيق ترقيات قاعدة البيانات: ' . $this->db->error];
                }
            }
        }

        // 6. تسجيل نجاح عملية التحديث
        $this->logUpdate($version, $releaseDate, $description, 'success');
        
        // تنظيف المجلدات والملفات المؤقتة
        $this->cleanDir($this->tempDir);
        @rmdir($this->tempDir);
        @unlink($zipFilePath);

        return ['status' => true, 'version' => $version, 'message' => 'تم تحديث النظام وترقيته إلى الإصدار الجديد بنجاح!'];
    }

    /**
     * نسخ مجلد بالكامل بمحتوياته بشكل متكرر
     */
    private function copyDirectory($source, $destination) {
        $dir = opendir($source);
        if (!$dir) return false;

        @mkdir($destination, 0777, true);
        while (false !== ($file = readdir($dir))) {
            if (($file != '.') && ($file != '..')) {
                // منع تعديل ملفات الاتصال بالخادم والترخيص المحلي لمنع حدوث مشاكل
                if ($file === 'connect.php' || $file === 'license.AQNEX' || $file === 'public.key' || $file === 'private.key') {
                    continue;
                }
                
                if (is_dir($source . '/' . $file)) {
                    $this->copyDirectory($source . '/' . $file, $destination . '/' . $file);
                } else {
                    copy($source . '/' . $file, $destination . '/' . $file);
                }
            }
        }
        closedir($dir);
        return true;
    }

    /**
     * تشغيل استعلامات SQL متعددة لقاعدة البيانات
     */
    private function executeMultiQuery($sql) {
        if ($this->db->multi_query($sql)) {
            do {
                if ($result = $this->db->store_result()) {
                    $result->free();
                }
            } while ($this->db->next_result());
            return true;
        }
        return false;
    }

    /**
     * تنظيف مجلد بالكامل
     */
    private function cleanDir($dir) {
        if (!is_dir($dir)) return;
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            (is_dir("$dir/$file")) ? $this->cleanDir("$dir/$file") : unlink("$dir/$file");
        }
    }

    /**
     * تسجيل بيانات التحديث في قاعدة البيانات
     */
    private function logUpdate($version, $releaseDate, $description, $status) {
        $stmt = $this->db->prepare("INSERT INTO system_updates (version, released_date, description, status) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("ssss", $version, $releaseDate, $description, $status);
        return $stmt->execute();
    }
}
