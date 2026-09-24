<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ports;

interface MigrationVersionStoreInterface
{
    public function getVersion(): int;

    public function setVersion(int $version): bool;
}
