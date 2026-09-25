<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Core\Jobs;

use ADCT\ParishIntake\Core\Events\OccurrenceWindow;
use ADCT\ParishIntake\Core\Jobs\JobState;
use ADCT\ParishIntake\Core\Jobs\OccurrenceExpansionJob;
use ADCT\ParishIntake\Core\Ports\ClockInterface;
use ADCT\ParishIntake\Core\Ports\OccurrenceMaintenanceInterface;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class OccurrenceExpansionJobTest extends TestCase
{
    private DateTimeZone $timezone;

    protected function setUp(): void
    {
        $this->timezone = new DateTimeZone('Africa/Johannesburg');
    }

    public function testDailyJobResumesItsCheckpointWithTheOriginalWindow(): void
    {
        $clock = new MutableOccurrenceClock(new DateTimeImmutable('2026-09-25T23:00:00+02:00'));
        $maintenance = new FakeOccurrenceMaintenance([11, 12, 13]);
        $job = new OccurrenceExpansionJob($maintenance, $clock, $this->timezone);

        self::assertSame('expand_occurrences', $job->id());
        self::assertTrue($job->isDue($clock->now(), JobState::empty()));

        $firstStep = $job->processNext(null);
        self::assertNotNull($firstStep);
        self::assertFalse($firstStep->isComplete());
        self::assertNotNull($firstStep->checkpoint());

        $clock->current = new DateTimeImmutable('2026-09-26T01:00:00+02:00');
        $resumedState = new JobState($firstStep->checkpoint(), $clock->now());
        self::assertTrue($job->isDue($clock->now(), $resumedState));

        $secondStep = $job->processNext($firstStep->checkpoint());
        self::assertNotNull($secondStep);
        self::assertFalse($secondStep->isComplete());
        $lastStep = $job->processNext($secondStep->checkpoint());

        self::assertNotNull($lastStep);
        self::assertTrue($lastStep->isComplete());
        self::assertNull($lastStep->checkpoint());
        self::assertSame([11, 12, 13], array_column($maintenance->rebuilds, 'event_id'));
        self::assertSame([
            ['2026-09-25', '2027-09-25'],
            ['2026-09-25', '2027-09-25'],
            ['2026-09-25', '2027-09-25'],
        ], array_map(
            static fn (array $rebuild): array => [
                $rebuild['window']->startLocal->format('Y-m-d'),
                $rebuild['window']->endLocal->format('Y-m-d'),
            ],
            $maintenance->rebuilds
        ));
    }

    public function testEmptyRunDoesNotCreateWorkAndTheNextRunIsDueAfterOneDay(): void
    {
        $clock = new MutableOccurrenceClock(new DateTimeImmutable('2026-09-25T08:00:00+02:00'));
        $job = new OccurrenceExpansionJob(new FakeOccurrenceMaintenance([]), $clock, $this->timezone);

        self::assertNull($job->processNext(null));
        self::assertFalse($job->isDue($clock->now()->modify('+23 hours'), new JobState(
            null,
            $clock->now()
        )));
        self::assertTrue($job->isDue($clock->now()->modify('+24 hours'), new JobState(
            null,
            $clock->now()
        )));
    }

    public function testInvalidMaintenanceCheckpointIsRejected(): void
    {
        $job = new OccurrenceExpansionJob(
            new FakeOccurrenceMaintenance([]),
            new MutableOccurrenceClock(new DateTimeImmutable('2026-09-25T08:00:00+02:00')),
            $this->timezone
        );

        $this->expectException(InvalidArgumentException::class);
        $job->processNext('not-a-checkpoint');
    }
}

final class MutableOccurrenceClock implements ClockInterface
{
    public function __construct(public DateTimeImmutable $current)
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->current;
    }
}

final class FakeOccurrenceMaintenance implements OccurrenceMaintenanceInterface
{
    /**
     * @var list<array{event_id: int, window: OccurrenceWindow}>
     */
    public array $rebuilds = [];

    /**
     * @var list<int>
     */
    public array $deletedEventIds = [];

    /**
     * @param list<int> $eventIds
     */
    public function __construct(private array $eventIds)
    {
    }

    public function nextEventIdAfter(int $eventId): ?int
    {
        foreach ($this->eventIds as $candidate) {
            if ($candidate > $eventId) {
                return $candidate;
            }
        }

        return null;
    }

    public function rebuildEvent(int $eventId, OccurrenceWindow $window): void
    {
        $this->rebuilds[] = [
            'event_id' => $eventId,
            'window' => $window,
        ];
    }

    public function deleteEventOccurrences(int $eventId): void
    {
        $this->deletedEventIds[] = $eventId;
    }
}
