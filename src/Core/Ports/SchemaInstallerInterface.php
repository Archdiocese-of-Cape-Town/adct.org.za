<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ports;

interface SchemaInstallerInterface
{
    public function install(string $sqlTemplate): void;
}
