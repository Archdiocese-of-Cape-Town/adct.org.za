<?php

require_once __DIR__ . '/ReviewQueueCheck.php';

ReviewQueueCheck::run(static function (string $message): never {
    WP_CLI::error($message);
});

WP_CLI::success('Installed-ZIP review queue checks passed.');
