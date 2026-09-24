<?php

namespace ADCT\ParishIntake\WordPress;

final class Autoloader
{
    public static function register(): void
    {
        spl_autoload_register([self::class, 'autoload']);
    }

    private static function autoload(string $class): void
    {
        $prefix = 'ADCT\\ParishIntake\\';

        if (strpos($class, $prefix) !== 0) {
            return;
        }

        $relative = substr($class, strlen($prefix));
        $path = dirname(__DIR__) . DIRECTORY_SEPARATOR
            . str_replace('\\', DIRECTORY_SEPARATOR, $relative)
            . '.php';

        if (is_readable($path)) {
            require_once $path;
        }
    }
}
