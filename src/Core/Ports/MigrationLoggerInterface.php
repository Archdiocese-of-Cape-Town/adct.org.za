<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ports;

use Throwable;

interface MigrationLoggerInterface
{
    public function migrationFailed(int $version, Throwable $failure): void;

    public function migrationsSucceeded(int $version): void;
}
