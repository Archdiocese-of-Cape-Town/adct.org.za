<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ports;

use ADCT\ParishIntake\Core\Mail\MailQueueClaimResult;
use ADCT\ParishIntake\Core\Mail\MailQueueEnqueueResult;
use ADCT\ParishIntake\Core\Mail\MailQueueRecord;
use ADCT\ParishIntake\Core\Mail\MailQueueStats;
use ADCT\ParishIntake\Core\Mail\MailQueueStatus;
use ADCT\ParishIntake\Core\Mail\OutboundEmail;
use DateTimeImmutable;

interface MailQueueRepositoryInterface
{
    public function enqueue(
        OutboundEmail $email,
        MailQueueStatus $initialStatus,
        DateTimeImmutable $now
    ): MailQueueEnqueueResult;

    public function findByRecipientAndGroupKey(string $recipient, string $groupKey): ?MailQueueRecord;

    public function findNextDue(DateTimeImmutable $now): ?MailQueueRecord;

    public function findExpiredClaim(DateTimeImmutable $cutoff): ?MailQueueRecord;

    public function claim(
        int $id,
        DateTimeImmutable $now,
        int $hourlyCap,
        int $windowSeconds
    ): MailQueueClaimResult;

    public function markSent(MailQueueRecord $claim, DateTimeImmutable $sentAt): bool;

    public function markDeliveryFailure(
        MailQueueRecord $claim,
        DateTimeImmutable $failedAt,
        ?DateTimeImmutable $retryAt,
        string $errorCode
    ): bool;

    public function markSuppressed(MailQueueRecord $queued, DateTimeImmutable $suppressedAt): bool;

    public function markInterrupted(
        MailQueueRecord $claim,
        DateTimeImmutable $recoveredAt,
        ?DateTimeImmutable $retryAt
    ): bool;

    public function stats(DateTimeImmutable $now, int $windowSeconds): MailQueueStats;

    public function pruneSentBefore(DateTimeImmutable $cutoff, int $limit): int;
}
