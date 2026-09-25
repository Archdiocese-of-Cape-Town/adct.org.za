<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Jobs;

use ADCT\ParishIntake\Core\Mail\ConfirmationEmailPreviewService;
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
        $batch = $this->source->nextPending();

        if ($batch === null) {
            return null;
        }

        $result = $this->previews->enqueuePreview($batch);
        $this->source->recordResult($batch->messageId, $result, $this->clock->now());

        return JobStepResult::continueAt(null);
    }
}
