<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ingestion;

use ADCT\ParishIntake\Core\Ingestion\Imap\MailboxEncryption;
use InvalidArgumentException;

final readonly class MailboxSettings
{
    public const DEFAULT_PORT = 993;
    public const DEFAULT_MAX_MESSAGE_SIZE_BYTES = 30 * 1024 * 1024;

    public function __construct(
        public string $label,
        public string $host,
        public int $port,
        public MailboxEncryption $encryption,
        public string $username,
        public string $inboxFolder,
        public string $processedFolder,
        public int $maxMessageSizeBytes,
        public bool $active,
        public int $id = 0,
        public int $sourceId = 0
    ) {
        if ($id < 0 || $sourceId < 0) {
            throw new InvalidArgumentException('Mailbox and source IDs cannot be negative.');
        }
    }

    public function withIdentity(int $id, int $sourceId): self
    {
        return new self(
            $this->label,
            $this->host,
            $this->port,
            $this->encryption,
            $this->username,
            $this->inboxFolder,
            $this->processedFolder,
            $this->maxMessageSizeBytes,
            $this->active,
            $id,
            $sourceId
        );
    }

    public function secretScope(): string
    {
        if ($this->id < 1) {
            throw new InvalidArgumentException('A saved mailbox ID is required for its password scope.');
        }

        return 'mailbox-' . $this->id;
    }
}
