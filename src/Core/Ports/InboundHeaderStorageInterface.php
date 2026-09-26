<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ports;

interface InboundHeaderStorageInterface
{
    public function readHeaderBlock(string $relativePath): string;
}
