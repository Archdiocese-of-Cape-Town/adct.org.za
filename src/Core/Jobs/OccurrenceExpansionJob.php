<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Jobs;

use ADCT\ParishIntake\Core\Events\OccurrenceWindow;
use ADCT\ParishIntake\Core\Ports\ClockInterface;
use ADCT\ParishIntake\Core\Ports\OccurrenceMaintenanceInterface;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;

final class OccurrenceExpansionJob extends AbstractJob
{
    private const DUE_INTERVAL_SECONDS = 86400;

    public function __construct(
        private OccurrenceMaintenanceInterface $maintenance,
        private ClockInterface $clock,
        private DateTimeZone $timezone
    ) {
        parent::__construct('expand_occurrences', 'Expand event occurrences', self::DUE_INTERVAL_SECONDS);
    }

    public function isDue(DateTimeImmutable $now, JobState $state): bool
    {
        return $state->checkpoint !== null || parent::isDue($now, $state);
    }

    public function processNext(?string $checkpoint): ?JobStepResult
    {
        if ($checkpoint === null) {
            $eventId = 0;
            $window = OccurrenceWindow::rollingTwelveMonths($this->clock->now(), $this->timezone);
        } else {
            [$eventId, $window] = $this->decodeCheckpoint($checkpoint);
        }

        $nextEventId = $this->maintenance->nextEventIdAfter($eventId);

        if ($nextEventId === null) {
            return $checkpoint === null
                ? null
                : JobStepResult::completeAt(null);
        }

        if ($nextEventId < 1 || $nextEventId <= $eventId) {
            throw new RuntimeException('Occurrence maintenance returned an invalid event cursor.');
        }

        $this->maintenance->rebuildEvent($nextEventId, $window);
        $followingEventId = $this->maintenance->nextEventIdAfter($nextEventId);

        if ($followingEventId === null) {
            return JobStepResult::completeAt(null);
        }

        if ($followingEventId <= $nextEventId) {
            throw new RuntimeException('Occurrence maintenance returned an invalid next-event cursor.');
        }

        return JobStepResult::continueAt($this->encodeCheckpoint($nextEventId, $window));
    }

    /**
     * @return array{0: int, 1: OccurrenceWindow}
     */
    private function decodeCheckpoint(string $checkpoint): array
    {
        $parts = explode('|', $checkpoint);

        if (
            count($parts) !== 3
            || preg_match('/^[1-9]\d*$/', $parts[0]) !== 1
        ) {
            throw new InvalidArgumentException('The occurrence expansion checkpoint is invalid.');
        }

        $eventId = filter_var($parts[0], FILTER_VALIDATE_INT, [
            'options' => [
                'min_range' => 1,
            ],
        ]);

        if (! is_int($eventId)) {
            throw new InvalidArgumentException('The occurrence expansion checkpoint event ID is invalid.');
        }

        return [
            $eventId,
            OccurrenceWindow::fromLocalDates($parts[1], $parts[2], $this->timezone),
        ];
    }

    private function encodeCheckpoint(int $eventId, OccurrenceWindow $window): string
    {
        return $eventId
            . '|'
            . $window->startLocal->format('Y-m-d')
            . '|'
            . $window->endLocal->format('Y-m-d');
    }
}
