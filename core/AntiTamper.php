<?php
namespace AQNEX\Core;

class AntiTamper {
    private $db;

    public function __construct($dbConnection) {
        $this->db = $dbConnection;
    }

    /**
     * التحقق من سلامة وقت نظام التشغيل ومقارنته بآخر توقيت مسجل بقاعدة البيانات
     */
    public function checkSystemTime() {
        $nowDate = date('Y-m-d');
        $nowDateTime = date('Y-m-d H:i:s');

        // جلب آخر تاريخ ووقت مسجل للنظام
        $res = $this->db->query("SELECT last_run_time, last_run_date FROM system_time_check ORDER BY id DESC LIMIT 1");
        $lastLog = ($res && $res->num_rows > 0) ? $res->fetch_assoc() : null;

        if ($lastLog) {
            $lastRunTime = $lastLog['last_run_time'];
            $lastRunDate = $lastLog['last_run_date'];

            // حساب فارق تراجع التوقيت بالثواني
            $diffSeconds = strtotime($lastRunTime) - strtotime($nowDateTime);

            if ($diffSeconds >= 86400) { // تراجع بيوم أو أكثر (86400 ثانية)
                // تفعيل قفل التلاعب في جدول التراخيص لتجميد البرنامج
                $this->db->query("UPDATE system_licensing SET tampering_lock = 1 WHERE id = 1");
                return false;
            }

            // إذا كان التراجع بسيطاً (أقل من يوم)، لا نحدث الوقت لتجنب التلاعب التراكمي، ونسمح بالتشغيل
            if ($diffSeconds > 0) {
                return true;
            }
        }

        // 2. تحديث جدول حماية التوقيت بالتوقيت الحالي (فقط في حال كان التوقيت يسير للأمام أو مساوٍ للوقت الأخير)
        if ($lastLog) {
            $this->db->query("UPDATE system_time_check SET last_run_date = '$nowDate', last_run_time = '$nowDateTime' WHERE id = 1");
        } else {
            $this->db->query("INSERT INTO system_time_check (last_run_date, last_run_time) VALUES ('$nowDate', '$nowDateTime')");
        }

        return true;
    }

    /**
     * التحقق مما إذا كان هناك قفل تلاعب نشط على الترخيص
     */
    public function isLocked() {
        $res = $this->db->query("SELECT tampering_lock FROM system_licensing LIMIT 1");
        if ($res && $res->num_rows > 0) {
            return intval($res->fetch_assoc()['tampering_lock']) === 1;
        }
        return false;
    }

    /**
     * إلغاء قفل التلاعب (يُستدعى فقط من قبل الدعم الفني أو عبر ترخيص تفعيل جديد)
     */
    public function unlock() {
        return $this->db->query("UPDATE system_licensing SET tampering_lock = 0 WHERE id = 1");
    }
}
?>
