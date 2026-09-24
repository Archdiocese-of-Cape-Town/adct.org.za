<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Jobs;

use DateTimeImmutable;

interface JobInterface
{
    public function id(): string;

    public function label(): string;

    public function isDue(DateTimeImmutable $now, JobState $state): bool;

    /**
     * Process one item from the checkpoint, or return null when no work remains.
     */
    public function processNext(?string $checkpoint): ?JobStepResult;
}
