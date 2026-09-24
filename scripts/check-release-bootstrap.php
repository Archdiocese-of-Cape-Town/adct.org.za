<?php

declare(strict_types=1);

if ($argc !== 2) {
    fwrite(STDERR, "Usage: php check-release-bootstrap.php <plugin-directory>\n");
    exit(2);
}

$pluginDirectory = rtrim($argv[1], "/\\");
$pluginFile = $pluginDirectory . DIRECTORY_SEPARATOR . 'adct-parish-intake.php';

if (! is_file($pluginFile)) {
    fwrite(STDERR, "Plugin bootstrap is missing.\n");
    exit(1);
}

if (! is_file($pluginDirectory . DIRECTORY_SEPARATOR . 'vendor-prefixed' . DIRECTORY_SEPARATOR . 'autoload.php')) {
    fwrite(STDERR, "Prefixed dependency autoloader is missing.\n");
    exit(1);
}

define('ABSPATH', $pluginDirectory . DIRECTORY_SEPARATOR);

function register_activation_hook($file, $callback): void
{
}

require $pluginFile;

if (! class_exists(ADCT\ParishIntake\Autoloader::class, false)
    || ! class_exists(ADCT\ParishIntake\Plugin::class, false)
) {
    fwrite(STDERR, "Plugin autoloader did not load the bootstrap classes.\n");
    exit(1);
}

fwrite(STDOUT, "Plugin bootstrap loaded under plain PHP.\n");
