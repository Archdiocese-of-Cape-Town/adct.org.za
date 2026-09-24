<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Jobs;

use DateTimeImmutable;
use InvalidArgumentException;

abstract class AbstractJob implements JobInterface
{
    private string $jobId;
    private string $jobLabel;
    private int $dueIntervalSeconds;

    public function __construct(string $jobId, string $jobLabel, int $dueIntervalSeconds)
    {
        if (preg_match('/\A[a-z][a-z0-9_]{0,63}\z/', $jobId) !== 1) {
            throw new InvalidArgumentException('Job IDs must be lowercase letters, digits, and underscores.');
        }

        if (trim($jobLabel) === '') {
            throw new InvalidArgumentException('A job label is required.');
        }

        if ($dueIntervalSeconds < 1) {
            throw new InvalidArgumentException('A job due interval must be positive.');
        }

        $this->jobId = $jobId;
        $this->jobLabel = $jobLabel;
        $this->dueIntervalSeconds = $dueIntervalSeconds;
    }

    final public function id(): string
    {
        return $this->jobId;
    }

    final public function label(): string
    {
        return $this->jobLabel;
    }

    public function isDue(DateTimeImmutable $now, JobState $state): bool
    {
        return $state->lastRunAt === null
            || $now->getTimestamp() >= $state->lastRunAt->getTimestamp() + $this->dueIntervalSeconds;
    }
}
