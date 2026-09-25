<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Mail;

use DateTimeImmutable;
use InvalidArgumentException;

final class MailQueueRecord
{
    public function __construct(
        public readonly int $id,
        public readonly OutboundEmail $email,
        public readonly MailQueueStatus $status,
        public readonly int $attempts,
        public readonly DateTimeImmutable $createdAt,
        public readonly DateTimeImmutable $updatedAt,
        public readonly ?DateTimeImmutable $nextAttemptAt = null,
        public readonly ?DateTimeImmutable $sentAt = null,
        public readonly ?string $errorCode = null
    ) {
        if ($id < 1 || $attempts < 0) {
            throw new InvalidArgumentException('A queued email has invalid identifiers or attempts.');
        }
    }

    public function claimed(DateTimeImmutable $claimedAt): self
    {
        return new self(
            $this->id,
            $this->email,
            MailQueueStatus::SENDING,
            $this->attempts + 1,
            $this->createdAt,
            $claimedAt
        );
    }
}
