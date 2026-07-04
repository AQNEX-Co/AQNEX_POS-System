<?php
namespace AQNEX\Utils;

class Helper
{
    public static function basePath(string $path = ''): string
    {
        return rtrim(APP_BASE_PATH, '/') . ($path ? '/' . ltrim($path, '/') : '');
    }

    public static function publicPath(string $path = ''): string
    {
        return rtrim(APP_BASE_PATH, '/') . ($path ? '/' . ltrim($path, '/') : '');
    }

    public static function normalizePath(string $path): string
    {
        return str_replace('\\', '/', $path);
    }

    public static function redirect(string $url): void
    {
        header('Location: ' . $url);
        exit();
    }
}
