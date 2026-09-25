<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Sources;

use InvalidArgumentException;

final class SourceHealthState
{
    public readonly string $status;
    public readonly ?string $lastCheckedAt;
    public readonly ?string $lastSuccessAt;
    public readonly ?string $lastItemAt;
    public readonly int $consecutiveFailures;
    public readonly ?string $lastError;

    public function __construct(
        string $status,
        ?string $lastCheckedAt,
        ?string $lastSuccessAt,
        ?string $lastItemAt,
        int $consecutiveFailures,
        ?string $lastError
    ) {
        if ($consecutiveFailures < 0) {
            throw new InvalidArgumentException('A source failure count cannot be negative.');
        }

        $this->status = SourceStatus::assertValid($status);
        $this->lastCheckedAt = $lastCheckedAt;
        $this->lastSuccessAt = $lastSuccessAt;
        $this->lastItemAt = $lastItemAt;
        $this->consecutiveFailures = $consecutiveFailures;
        $this->lastError = $lastError;
    }

    public function equals(self $other): bool
    {
        return $this->status === $other->status
            && $this->lastCheckedAt === $other->lastCheckedAt
            && $this->lastSuccessAt === $other->lastSuccessAt
            && $this->lastItemAt === $other->lastItemAt
            && $this->consecutiveFailures === $other->consecutiveFailures
            && $this->lastError === $other->lastError;
    }
}
