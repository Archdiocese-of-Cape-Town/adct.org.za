<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ingestion;

use ADCT\ParishIntake\Core\Ingestion\Imap\MailboxEncryption;
use ADCT\ParishIntake\Core\Sources\Source;
use InvalidArgumentException;

final readonly class MailboxSettings
{
    public const DEFAULT_PORT = 993;
    public const DEFAULT_MAX_MESSAGE_SIZE_BYTES = 30 * 1024 * 1024;

    public int $pollIntervalMinutes;

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
        public int $sourceId = 0,
        ?int $pollIntervalMinutes = null,
        public ?string $lastCheckedAt = null,
        public int $consecutiveFailures = 0
    ) {
        if ($id < 0 || $sourceId < 0) {
            throw new InvalidArgumentException('Mailbox and source IDs cannot be negative.');
        }

        $pollIntervalMinutes ??= Source::DEFAULT_POLL_INTERVAL_MINUTES;

        if (
            $pollIntervalMinutes < Source::MINIMUM_POLL_INTERVAL_MINUTES
            || $pollIntervalMinutes > Source::MAXIMUM_POLL_INTERVAL_MINUTES
        ) {
            throw new InvalidArgumentException('The source poll interval is outside the supported range.');
        }

        if ($consecutiveFailures < 0) {
            throw new InvalidArgumentException('The source failure count cannot be negative.');
        }

        $this->pollIntervalMinutes = $pollIntervalMinutes;
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
            $sourceId,
            $this->pollIntervalMinutes,
            $this->lastCheckedAt,
            $this->consecutiveFailures
        );
    }

    public function withSourcePollingState(
        ?int $pollIntervalMinutes,
        ?string $lastCheckedAt,
        int $consecutiveFailures
    ): self {
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
            $this->id,
            $this->sourceId,
            $pollIntervalMinutes,
            $lastCheckedAt,
            $consecutiveFailures
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
