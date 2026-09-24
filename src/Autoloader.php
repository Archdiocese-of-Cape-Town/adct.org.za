<?php

namespace ADCT\ParishIntake;

final class Autoloader
{
    public static function register(): void
    {
        spl_autoload_register([self::class, 'autoload']);
    }

    private static function autoload(string $class): void
    {
        $prefix = __NAMESPACE__ . '\\';

        if (strpos($class, $prefix) !== 0) {
            return;
        }

        $relative = substr($class, strlen($prefix));
        $path = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';

        if (is_readable($path)) {
            require_once $path;
        }
    }
}
