<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ports;

interface InboundMailStorageInterface
{
    public function storeRawMessage(string $rawMessage): string;

    public function storeAttachment(string $content, string $extension): string;

    public function delete(string $relativePath): void;
}
