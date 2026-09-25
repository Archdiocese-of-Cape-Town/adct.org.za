<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Sources;

use ADCT\ParishIntake\Core\Ports\ClockInterface;
use ADCT\ParishIntake\Core\Ports\SourceHealthStoreInterface;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use InvalidArgumentException;
use RuntimeException;

final class SourceHealthRecorder
{
    public const UNRELIABLE_FAILURE_THRESHOLD = 5;
    public const MAXIMUM_ERROR_LENGTH = 500;
    private const MAXIMUM_COMPARE_AND_SET_ATTEMPTS = 5;

    public function __construct(
        private SourceHealthStoreInterface $healthStore,
        private ClockInterface $clock,
        private int $unreliableFailureThreshold = self::UNRELIABLE_FAILURE_THRESHOLD
    ) {
        if ($unreliableFailureThreshold < 1) {
            throw new InvalidArgumentException('The unreliable failure threshold must be positive.');
        }
    }

    public function recordSuccess(int $sourceId, ?DateTimeImmutable $itemAt = null): void
    {
        $checkedAt = $this->timestamp($this->clock->now());
        $successAt = $checkedAt;
        $itemTimestamp = $itemAt === null ? null : $this->timestamp($itemAt);

        $this->update($sourceId, static function (SourceHealthState $current) use (
            $checkedAt,
            $successAt,
            $itemTimestamp
        ): SourceHealthState {
            return new SourceHealthState(
                $current->status,
                $checkedAt,
                $successAt,
                $itemTimestamp ?? $current->lastItemAt,
                0,
                null
            );
        }, $checkedAt);
    }

    public function recordFailure(int $sourceId, string $error): void
    {
        $error = $this->safeError($error);
        $checkedAt = $this->timestamp($this->clock->now());
        $threshold = $this->unreliableFailureThreshold;

        $this->update($sourceId, static function (SourceHealthState $current) use (
            $checkedAt,
            $error,
            $threshold
        ): SourceHealthState {
            $failures = $current->consecutiveFailures + 1;
            $status = $current->status;

            if (
                ! in_array($status, [SourceStatus::PAUSED, SourceStatus::DISABLED], true)
                && $failures >= $threshold
            ) {
                $status = SourceStatus::UNRELIABLE;
            }

            return new SourceHealthState(
                $status,
                $checkedAt,
                $current->lastSuccessAt,
                $current->lastItemAt,
                $failures,
                $error
            );
        }, $checkedAt);
    }

    public function recordChecked(int $sourceId): void
    {
        $checkedAt = $this->timestamp($this->clock->now());

        $this->update($sourceId, static function (SourceHealthState $current) use ($checkedAt): SourceHealthState {
            return new SourceHealthState(
                $current->status,
                $checkedAt,
                $current->lastSuccessAt,
                $current->lastItemAt,
                $current->consecutiveFailures,
                $current->lastError
            );
        }, $checkedAt);
    }

    /**
     * @param callable(SourceHealthState): SourceHealthState $transition
     */
    private function update(
        int $sourceId,
        callable $transition,
        string $updatedAt
    ): void {
        if ($sourceId < 1) {
            throw new InvalidArgumentException('A source ID must be positive.');
        }

        for ($attempt = 0; $attempt < self::MAXIMUM_COMPARE_AND_SET_ATTEMPTS; ++$attempt) {
            $current = $this->healthStore->findHealth($sourceId);

            if ($current === null) {
                throw new DomainException('The source could not be found for a health update.');
            }

            $replacement = $transition($current);

            if ($this->healthStore->saveHealthIfUnchanged($sourceId, $current, $replacement, $updatedAt)) {
                return;
            }

            $latest = $this->healthStore->findHealth($sourceId);

            if ($latest === null) {
                throw new DomainException('The source could not be found for a health update.');
            }

            if ($latest->equals($replacement)) {
                return;
            }
        }

        throw new RuntimeException('The source health could not be saved after concurrent updates.');
    }

    private function timestamp(DateTimeImmutable $dateTime): string
    {
        return $dateTime
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
    }

    private function safeError(string $error): string
    {
        $error = trim($error);

        if ($error === '') {
            throw new InvalidArgumentException('A source failure needs a technical error message.');
        }

        $error = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $error) ?? $error;
        $error = preg_replace('/https?:\/\/\S+/iu', '[redacted URL]', $error) ?? $error;
        $error = preg_replace(
            '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/iu',
            '[redacted email]',
            $error
        ) ?? $error;
        $error = preg_replace(
            '/(?<![\pL\pN])\+?\d[\d .()\-]{6,}\d(?![\pL\pN])/u',
            '[redacted number]',
            $error
        ) ?? $error;
        $error = trim($error);

        if (preg_match('/^.{0,' . self::MAXIMUM_ERROR_LENGTH . '}/us', $error, $matches) === 1) {
            return $matches[0];
        }

        return substr($error, 0, self::MAXIMUM_ERROR_LENGTH);
    }
}
