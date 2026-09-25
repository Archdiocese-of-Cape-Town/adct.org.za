<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Core\Events;

use ADCT\ParishIntake\Core\Events\OccurrenceWindow;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class OccurrenceWindowTest extends TestCase
{
    private DateTimeZone $timezone;

    protected function setUp(): void
    {
        $this->timezone = new DateTimeZone('Africa/Johannesburg');
    }

    public function testRollingWindowUsesInclusiveLocalDateBoundaries(): void
    {
        $window = OccurrenceWindow::rollingTwelveMonths(
            new DateTimeImmutable('2026-09-25T23:45:00+02:00'),
            $this->timezone
        );

        self::assertSame('2026-09-25', $window->startLocal->format('Y-m-d'));
        self::assertSame('2027-09-25', $window->endLocal->format('Y-m-d'));
        self::assertSame('Africa/Johannesburg', $window->startLocal->getTimezone()->getName());
        self::assertTrue($window->contains(new DateTimeImmutable('2026-09-25T00:00:00+02:00')));
        self::assertTrue($window->contains(new DateTimeImmutable('2027-09-25T23:59:00+02:00')));
        self::assertFalse($window->contains(new DateTimeImmutable('2026-09-24T23:59:00+02:00')));
        self::assertFalse($window->contains(new DateTimeImmutable('2027-09-26T00:00:00+02:00')));
    }

    public function testLeapDayAnniversaryClampsToTheLastDayOfFebruary(): void
    {
        $window = OccurrenceWindow::rollingTwelveMonths(
            new DateTimeImmutable('2028-02-29T08:00:00+02:00'),
            $this->timezone
        );

        self::assertSame('2028-02-29', $window->startLocal->format('Y-m-d'));
        self::assertSame('2029-02-28', $window->endLocal->format('Y-m-d'));
    }

    public function testLocalDateFactoryRejectsInvalidAndReversedRanges(): void
    {
        try {
            OccurrenceWindow::fromLocalDates('2026-02-30', '2026-03-01', $this->timezone);
            self::fail('An invalid local date must not be accepted.');
        } catch (InvalidArgumentException) {
        }

        $this->expectException(InvalidArgumentException::class);
        OccurrenceWindow::fromLocalDates('2026-10-02', '2026-10-01', $this->timezone);
    }
}
