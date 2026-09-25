<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Sources;

use InvalidArgumentException;

final class Source
{
    public const DEFAULT_POLL_INTERVAL_MINUTES = 1440;
    public const MINIMUM_POLL_INTERVAL_MINUTES = 10;
    public const MAXIMUM_POLL_INTERVAL_MINUTES = 4294967295;

    public readonly int $id;
    public readonly ?int $parishId;
    public readonly string $type;
    public readonly string $identifier;
    public readonly string $role;
    public readonly string $status;
    public readonly ?int $pollIntervalMinutes;
    public readonly ?string $lastCheckedAt;
    public readonly ?string $lastSuccessAt;
    public readonly ?string $lastItemAt;
    public readonly int $consecutiveFailures;
    public readonly ?string $lastError;

    public function __construct(
        int $id,
        ?int $parishId,
        string $type,
        string $identifier,
        string $role = SourceRole::MONITORED,
        string $status = SourceStatus::ACTIVE,
        ?int $pollIntervalMinutes = null,
        ?string $lastCheckedAt = null,
        ?string $lastSuccessAt = null,
        ?string $lastItemAt = null,
        int $consecutiveFailures = 0,
        ?string $lastError = null
    ) {
        if ($id < 0 || ($parishId !== null && $parishId < 1)) {
            throw new InvalidArgumentException('A source needs a non-negative ID and a positive parish ID when linked.');
        }

        $this->id = $id;
        $this->parishId = $parishId;
        $this->type = SourceType::assertValid($type);
        $this->identifier = SourceType::normalizeIdentifier($type, $identifier);
        $this->role = SourceRole::assertValid($role);
        $this->status = SourceStatus::assertValid($status);

        if (
            $pollIntervalMinutes !== null
            && (
                $pollIntervalMinutes < self::MINIMUM_POLL_INTERVAL_MINUTES
                || $pollIntervalMinutes > self::MAXIMUM_POLL_INTERVAL_MINUTES
            )
        ) {
            throw new InvalidArgumentException(
                'A source poll interval must be at least 10 minutes and fit the database field.'
            );
        }

        $this->pollIntervalMinutes = $type === SourceType::MANUAL
            ? null
            : ($pollIntervalMinutes ?? self::DEFAULT_POLL_INTERVAL_MINUTES);

        if ($consecutiveFailures < 0) {
            throw new InvalidArgumentException('A source failure count cannot be negative.');
        }

        $this->lastCheckedAt = $lastCheckedAt;
        $this->lastSuccessAt = $lastSuccessAt;
        $this->lastItemAt = $lastItemAt;
        $this->consecutiveFailures = $consecutiveFailures;
        $this->lastError = $lastError;
    }
}
