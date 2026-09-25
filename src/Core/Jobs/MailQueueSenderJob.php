<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Jobs;

use ADCT\ParishIntake\Core\Mail\MailQueueDispatcher;

final class MailQueueSenderJob extends AbstractJob implements JobRunLifecycleInterface
{
    private bool $sentRowsPruned = false;

    public function __construct(private readonly MailQueueDispatcher $dispatcher)
    {
        parent::__construct('send_mail', 'Send queued email', 600);
    }

    public function beginRun(): void
    {
        $this->sentRowsPruned = false;
    }

    public function processNext(?string $checkpoint): ?JobStepResult
    {
        if (! $this->sentRowsPruned) {
            $this->dispatcher->pruneSent();
            $this->sentRowsPruned = true;
        }

        $result = $this->dispatcher->dispatchOne();

        if (! $result->status->processedItem()) {
            return null;
        }

        return JobStepResult::continueAt(null);
    }
}
