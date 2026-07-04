<?php
spl_autoload_register(function ($class) {
    $prefix = 'AQNEX\\';
    $baseDir = __DIR__ . '/../../app/';

    if (strpos($class, $prefix) !== 0) {
        return;
    }

    $relativeClass = substr($class, strlen($prefix));
    $relativePath = str_replace('\\', '/', $relativeClass) . '.php';
    $file = $baseDir . $relativePath;

    if (file_exists($file)) {
        require_once $file;
    }
});
