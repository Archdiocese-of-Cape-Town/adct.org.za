<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Mail;

use ADCT\ParishIntake\Core\Ports\ClockInterface;
use ADCT\ParishIntake\Core\Ports\MailDeliveryInterface;
use ADCT\ParishIntake\Core\Ports\MailQueueRepositoryInterface;
use ADCT\ParishIntake\Core\Ports\RecipientPolicyInterface;
use DateTimeImmutable;
use Throwable;

final class MailQueueDispatcher
{
    public function __construct(
        private readonly MailQueueRepositoryInterface $repository,
        private readonly MailDeliveryInterface $delivery,
        private readonly RecipientPolicyInterface $recipientPolicy,
        private readonly ClockInterface $clock,
        private readonly MailQueueConfiguration $configuration
    ) {
    }

    public function dispatchOne(): MailQueueDispatchResult
    {
        $now = $this->clock->now();
        $expiredClaim = $this->repository->findExpiredClaim(
            $now->modify('-' . MailQueueConfiguration::HOURLY_WINDOW_SECONDS . ' seconds')
        );

        if ($expiredClaim !== null) {
            return $this->recoverInterruptedClaim($expiredClaim, $now);
        }

        $queued = $this->repository->findNextDue($now);

        if ($queued === null) {
            return new MailQueueDispatchResult(MailQueueDispatchStatus::EMPTY);
        }

        if (! $this->recipientPolicy->allows($queued->email->recipient)) {
            $suppressed = $this->repository->markSuppressed($queued, $now);

            return new MailQueueDispatchResult(
                $suppressed ? MailQueueDispatchStatus::SUPPRESSED : MailQueueDispatchStatus::CLAIM_LOST,
                $suppressed ? $queued->id : null
            );
        }

        $claim = $this->repository->claim(
            $queued->id,
            $now,
            $this->configuration->hourlyCap,
            MailQueueConfiguration::HOURLY_WINDOW_SECONDS
        );

        if ($claim->status !== MailQueueClaimStatus::CLAIMED) {
            $status = match ($claim->status) {
                MailQueueClaimStatus::CAP_REACHED => MailQueueDispatchStatus::CAP_REACHED,
                MailQueueClaimStatus::LOCK_BUSY => MailQueueDispatchStatus::LOCK_BUSY,
                MailQueueClaimStatus::NOT_CLAIMABLE => MailQueueDispatchStatus::CLAIM_LOST,
                MailQueueClaimStatus::CLAIMED => MailQueueDispatchStatus::CLAIM_LOST,
            };

            return new MailQueueDispatchResult($status);
        }

        $claimed = $claim->record;

        try {
            $delivery = $this->delivery->deliver($claimed->email);
        } catch (Throwable) {
            $delivery = MailDeliveryResult::failed('delivery_exception');
        }

        if ($delivery->sent) {
            $markedSent = $this->repository->markSent($claimed, $this->clock->now());

            return new MailQueueDispatchResult(
                $markedSent ? MailQueueDispatchStatus::SENT : MailQueueDispatchStatus::CLAIM_LOST,
                $markedSent ? $claimed->id : null
            );
        }

        $retryAt = $this->nextAttemptAt($claimed->attempts, $this->clock->now());
        $markedFailed = $this->repository->markDeliveryFailure(
            $claimed,
            $this->clock->now(),
            $retryAt,
            $delivery->failureCode ?? 'delivery_failed'
        );

        if (! $markedFailed) {
            return new MailQueueDispatchResult(MailQueueDispatchStatus::CLAIM_LOST);
        }

        return new MailQueueDispatchResult(
            $retryAt === null ? MailQueueDispatchStatus::FAILED : MailQueueDispatchStatus::RETRY_SCHEDULED,
            $claimed->id
        );
    }

    public function pruneSent(): int
    {
        $cutoff = $this->clock->now()->modify(
            '-' . MailQueueConfiguration::SENT_RETENTION_DAYS . ' days'
        );

        return $this->repository->pruneSentBefore(
            $cutoff,
            MailQueueConfiguration::PRUNE_BATCH_SIZE
        );
    }

    private function recoverInterruptedClaim(
        MailQueueRecord $claim,
        DateTimeImmutable $recoveredAt
    ): MailQueueDispatchResult {
        $retryAt = $this->nextAttemptAt($claim->attempts, $recoveredAt);
        $recovered = $this->repository->markInterrupted($claim, $recoveredAt, $retryAt);

        if (! $recovered) {
            return new MailQueueDispatchResult(MailQueueDispatchStatus::CLAIM_LOST);
        }

        return new MailQueueDispatchResult(
            $retryAt === null
                ? MailQueueDispatchStatus::FAILED
                : MailQueueDispatchStatus::INTERRUPTED_REQUEUED,
            $claim->id
        );
    }

    private function nextAttemptAt(int $attempts, DateTimeImmutable $now): ?DateTimeImmutable
    {
        if ($attempts >= MailQueueConfiguration::MAX_ATTEMPTS) {
            return null;
        }

        return $now->modify(
            '+' . $this->configuration->retryDelaySeconds($attempts) . ' seconds'
        );
    }
}
