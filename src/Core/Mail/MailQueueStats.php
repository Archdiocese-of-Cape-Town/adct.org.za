<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Mail;

use InvalidArgumentException;

final class MailQueueStats
{
    public function __construct(
        public readonly int $pendingCount,
        public readonly ?int $oldestPendingAgeSeconds,
        public readonly int $sentInLastHour,
        public readonly int $failedCount
    ) {
        if (
            $pendingCount < 0
            || ($oldestPendingAgeSeconds !== null && $oldestPendingAgeSeconds < 0)
            || $sentInLastHour < 0
            || $failedCount < 0
        ) {
            throw new InvalidArgumentException('Mail queue statistics cannot be negative.');
        }

        if (($pendingCount === 0) !== ($oldestPendingAgeSeconds === null)) {
            throw new InvalidArgumentException('Oldest pending age must match the pending count.');
        }
    }
}
