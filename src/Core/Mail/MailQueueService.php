<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Mail;

use ADCT\ParishIntake\Core\Ports\ClockInterface;
use ADCT\ParishIntake\Core\Ports\MailerInterface;
use ADCT\ParishIntake\Core\Ports\MailQueueImmediateDispatchInterface;
use ADCT\ParishIntake\Core\Ports\MailQueueRepositoryInterface;
use ADCT\ParishIntake\Core\Ports\RecipientPolicyInterface;

final class MailQueueService implements MailerInterface
{
    public function __construct(
        private readonly MailQueueRepositoryInterface $repository,
        private readonly RecipientPolicyInterface $recipientPolicy,
        private readonly ClockInterface $clock,
        private readonly ?MailQueueImmediateDispatchInterface $immediateDispatch = null
    ) {
    }

    public function enqueue(OutboundEmail $email): MailQueueEnqueueResult
    {
        $suppressed = ! $this->recipientPolicy->allows($email->recipient);
        $result = $this->repository->enqueue(
            $email,
            $suppressed ? MailQueueStatus::SUPPRESSED : MailQueueStatus::QUEUED,
            $this->clock->now()
        );

        if (
            ! $suppressed
            && $email->priority === MailPriority::LOGIN_OR_CONFIRMATION
            && in_array($result->status, [MailQueueStatus::QUEUED, MailQueueStatus::SENDING], true)
        ) {
            $this->immediateDispatch?->dispatchImmediately();
        }

        return $result;
    }

    public function stats(): MailQueueStats
    {
        return $this->repository->stats(
            $this->clock->now(),
            MailQueueConfiguration::HOURLY_WINDOW_SECONDS
        );
    }
}
