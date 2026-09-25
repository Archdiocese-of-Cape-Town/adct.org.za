<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Mail;

use ADCT\ParishIntake\Core\Jobs\JobRunner;
use ADCT\ParishIntake\Core\Jobs\JobRunStatus;
use ADCT\ParishIntake\Core\Jobs\MailQueueSenderJob;
use ADCT\ParishIntake\Core\Ports\MailQueueImmediateDispatchInterface;

final class WordPressMailQueueImmediateDispatch implements MailQueueImmediateDispatchInterface
{
    private const TIME_BUDGET_SECONDS = 5;
    private const ITEM_BUDGET = 1;

    public function __construct(
        private readonly JobRunner $runner,
        private readonly MailQueueSenderJob $job
    ) {
    }

    public function dispatchImmediately(): void
    {
        $result = $this->runner->run(
            $this->job,
            true,
            self::TIME_BUDGET_SECONDS,
            self::ITEM_BUDGET
        );

        if ($result->status === JobRunStatus::FAILED) {
            error_log('[ADCT Parish Intake] Immediate mail dispatch failed; see Scheduled jobs.');
        }
    }
}
