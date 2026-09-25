<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Jobs;

use ADCT\ParishIntake\Core\Mail\ConfirmationEmailPreviewService;
use ADCT\ParishIntake\Core\Mail\ConfirmationEmailHeaderUnavailableException;
use ADCT\ParishIntake\Core\Mail\ConfirmationEmailOutcome;
use ADCT\ParishIntake\Core\Mail\ConfirmationEmailQueueConflictException;
use ADCT\ParishIntake\Core\Mail\ConfirmationEmailReason;
use ADCT\ParishIntake\Core\Mail\ConfirmationEmailResult;
use ADCT\ParishIntake\Core\Ports\ClockInterface;
use ADCT\ParishIntake\Core\Ports\ConfirmationEmailJobSourceInterface;

final class ConfirmationEmailPreviewJob extends AbstractJob
{
    public const DEFAULT_INTERVAL_SECONDS = 600;

    public function __construct(
        private readonly ConfirmationEmailJobSourceInterface $source,
        private readonly ConfirmationEmailPreviewService $previews,
        private readonly ClockInterface $clock
    ) {
        parent::__construct(
            'queue_confirmation_previews',
            'Queue event confirmation previews',
            self::DEFAULT_INTERVAL_SECONDS
        );
    }

    public function processNext(?string $checkpoint): ?JobStepResult
    {
        try {
            $batch = $this->source->nextPending();
        } catch (ConfirmationEmailHeaderUnavailableException $failure) {
            $this->source->recordResult(
                $failure->messageId,
                new ConfirmationEmailResult(
                    ConfirmationEmailOutcome::FAILED,
                    ConfirmationEmailReason::RAW_MESSAGE_UNAVAILABLE
                ),
                $this->clock->now()
            );

            return JobStepResult::continueAt(null);
        }

        if ($batch === null) {
            return null;
        }

        try {
            $result = $this->previews->enqueuePreview($batch);
        } catch (ConfirmationEmailQueueConflictException) {
            $result = new ConfirmationEmailResult(
                ConfirmationEmailOutcome::FAILED,
                ConfirmationEmailReason::QUEUE_CONFLICT
            );
        }

        $this->source->recordResult($batch->messageId, $result, $this->clock->now());

        return JobStepResult::continueAt(null);
    }
}
