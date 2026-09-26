<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Core\Events;

use ADCT\ParishIntake\Core\Events\ListingRange;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ListingRangeTest extends TestCase
{
    private DateTimeZone $timezone;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->timezone = new DateTimeZone('Africa/Johannesburg');
        $this->now = new DateTimeImmutable('2026-09-25 14:00:00', $this->timezone);
    }

    public function testPresetWindowsDoNotIncludePastDays(): void
    {
        $week = $this->range('week');
        self::assertSame('2026-09-25', $week->from->format('Y-m-d'));
        self::assertSame('2026-09-27', $week->through->format('Y-m-d'));

        $month = $this->range('month');
        self::assertSame('2026-09-25', $month->from->format('Y-m-d'));
        self::assertSame('2026-09-30', $month->through->format('Y-m-d'));

        $upcoming = $this->range('upcoming');
        self::assertSame('2027-09-25', $upcoming->through->format('Y-m-d'));
    }

    public function testExplicitDatesAreInclusiveAndClampedToToday(): void
    {
        $range = $this->range('range', '2026-09-24', '2026-10-12');
        self::assertSame('2026-09-25', $range->from->format('Y-m-d'));
        self::assertSame('2026-10-12', $range->through->format('Y-m-d'));
    }

    public function testLeapDayYearBoundaryMatchesOccurrenceExpansion(): void
    {
        $range = new ListingRange(
            'upcoming',
            '',
            '',
            new DateTimeImmutable('2028-02-29 12:00:00', $this->timezone),
            $this->timezone
        );
        self::assertSame('2029-02-28', $range->through->format('Y-m-d'));
    }

    public function testTodayUsesJohannesburgRatherThanUtcDate(): void
    {
        $range = new ListingRange(
            'upcoming',
            '',
            '',
            new DateTimeImmutable('2026-09-24 23:30:00', new DateTimeZone('UTC')),
            $this->timezone
        );
        self::assertSame('2026-09-25', $range->from->format('Y-m-d'));
    }

    public function testInvalidAndUnboundedDatesAreRejected(): void
    {
        foreach ([
            ['range', '2026-02-30', '2026-10-01'],
            ['range', '12/10/2026', '2026-10-15'],
            ['range', '2026-10-15', '2026-10-01'],
            ['range', '2026-09-20', '2026-09-24'],
            ['range', '2026-09-25', '2027-09-26'],
            ['unknown', '', ''],
        ] as [$period, $from, $through]) {
            try {
                $this->range($period, $from, $through);
                self::fail('Invalid date range was accepted: ' . $period . ' ' . $from);
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    private function range(string $period, string $from = '', string $through = ''): ListingRange
    {
        return new ListingRange($period, $from, $through, $this->now, $this->timezone);
    }
}
