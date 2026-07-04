<?php
namespace AQNEX\Core;

class Bootstrap
{
    public static function initialize(): void
    {
        $config = require __DIR__ . '/../Config/config.php';

        date_default_timezone_set($config['app']['timezone'] ?? 'UTC');
        define('APP_ROOT', rtrim(str_replace('\\', '/', realpath(__DIR__ . '/../../')), '/'));
        define('APP_BASE_PATH', APP_ROOT . '/');

        require_once __DIR__ . '/Autoloader.php';
        require_once __DIR__ . '/../Utils/Helper.php';

        \AQNEX\Config\Database::loadConfig($config);
    }
}
