<?php

final class AppInfo
{
    public static function version(): string
    {
        $path = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'VERSION';
        if (!is_file($path)) {
            return '0.0.0-dev';
        }

        $version = trim((string) file_get_contents($path));
        return $version === '' ? '0.0.0-dev' : $version;
    }

    public static function userAgent(): string
    {
        return 'dynamic-dns-worker/' . self::version();
    }
}
