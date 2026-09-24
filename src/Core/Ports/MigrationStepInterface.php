<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ports;

interface MigrationStepInterface
{
    public function version(): int;

    public function apply(): void;
}
