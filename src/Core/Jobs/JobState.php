<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Jobs;

use DateTimeImmutable;
use InvalidArgumentException;

final class JobState
{
    public readonly ?string $checkpoint;
    public readonly ?DateTimeImmutable $lastRunAt;
    public readonly ?DateTimeImmutable $lastSuccessAt;
    public readonly ?string $lastErrorMessage;
    public readonly ?DateTimeImmutable $lastErrorAt;
    public readonly int $itemsProcessed;
    public readonly ?string $lastTrigger;
    public readonly int $consecutiveFailures;

    public function __construct(
        ?string $checkpoint = null,
        ?DateTimeImmutable $lastRunAt = null,
        ?DateTimeImmutable $lastSuccessAt = null,
        ?string $lastErrorMessage = null,
        ?DateTimeImmutable $lastErrorAt = null,
        int $itemsProcessed = 0,
        ?string $lastTrigger = null,
        int $consecutiveFailures = 0
    ) {
        if ($itemsProcessed < 0 || $consecutiveFailures < 0) {
            throw new InvalidArgumentException('The processed item count cannot be negative.');
        }

        if (($lastErrorMessage === null) !== ($lastErrorAt === null)) {
            throw new InvalidArgumentException('A job error must have both a message and a timestamp.');
        }

        $this->checkpoint = $checkpoint;
        $this->lastRunAt = $lastRunAt;
        $this->lastSuccessAt = $lastSuccessAt;
        $this->lastErrorMessage = $lastErrorMessage;
        $this->lastErrorAt = $lastErrorAt;
        $this->itemsProcessed = $itemsProcessed;
        $this->lastTrigger = $lastTrigger;
        $this->consecutiveFailures = $consecutiveFailures;
    }

    public static function empty(): self
    {
        return new self();
    }

    public function withRunStarted(DateTimeImmutable $startedAt, string $trigger = 'internal'): self
    {
        return new self(
            $this->checkpoint,
            $startedAt,
            $this->lastSuccessAt,
            $this->lastErrorMessage,
            $this->lastErrorAt,
            0,
            $trigger,
            $this->consecutiveFailures
        );
    }

    public function withCheckpoint(?string $checkpoint, int $itemsProcessed): self
    {
        return new self(
            $checkpoint,
            $this->lastRunAt,
            $this->lastSuccessAt,
            $this->lastErrorMessage,
            $this->lastErrorAt,
            $itemsProcessed,
            $this->lastTrigger,
            $this->consecutiveFailures
        );
    }

    public function withRunSucceeded(DateTimeImmutable $succeededAt, int $itemsProcessed): self
    {
        return new self(
            $this->checkpoint,
            $this->lastRunAt,
            $succeededAt,
            $this->lastErrorMessage,
            $this->lastErrorAt,
            $itemsProcessed,
            $this->lastTrigger,
            0
        );
    }

    public function withRunFailed(
        DateTimeImmutable $failedAt,
        int $itemsProcessed,
        string $message
    ): self {
        return new self(
            $this->checkpoint,
            $this->lastRunAt,
            $this->lastSuccessAt,
            $message,
            $failedAt,
            $itemsProcessed,
            $this->lastTrigger,
            $this->consecutiveFailures + 1
        );
    }
}
