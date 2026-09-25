<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ports;

interface InboundMailStorageReaderInterface extends InboundMailStorageInterface
{
    public function readRawMessage(string $relativePath): string;
}
