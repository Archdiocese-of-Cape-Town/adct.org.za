<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ingestion\Imap;

interface TransportInterface
{
    public function connect(MailboxConnectionConfig $config): void;

    public function enableTls(): void;

    public function write(string $data): void;

    public function readLine(): string;

    public function readBytes(int $length): string;

    public function close(): void;
}
