<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Core\Events;

use ADCT\ParishIntake\Core\Events\EventDetails;
use ADCT\ParishIntake\Core\Events\ExpandedOccurrence;
use ADCT\ParishIntake\Core\Events\OccurrenceExpander;
use ADCT\ParishIntake\Core\Events\OccurrenceWindow;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class OccurrenceExpanderTest extends TestCase
{
    private DateTimeZone $timezone;

    protected function setUp(): void
    {
        $this->timezone = new DateTimeZone('Africa/Johannesburg');
    }

    public function testKeepsAOnceOffEventAsOneOccurrence(): void
    {
        $occurrences = $this->expand('2026-09-25T09:30', null, '2026-09-25', '2026-09-26');

        self::assertSame(['2026-09-25T09:30'], $this->starts($occurrences));
    }

    public function testDailyIntervalCrossesYearBoundaryAndIncludesBothWindowDates(): void
    {
        $occurrences = $this->expand(
            '2026-12-30T08:00',
            'FREQ=DAILY;INTERVAL=2',
            '2026-12-31',
            '2027-01-07'
        );

        self::assertSame([
            '2027-01-01T08:00',
            '2027-01-03T08:00',
            '2027-01-05T08:00',
            '2027-01-07T08:00',
        ], $this->starts($occurrences));
    }

    public function testWeeklyByDayExpandsOnlyTheSelectedWeekdays(): void
    {
        $occurrences = $this->expand(
            '2026-09-22T18:00',
            'FREQ=WEEKLY;BYDAY=TU,TH',
            '2026-09-21',
            '2026-10-01'
        );

        self::assertSame([
            '2026-09-22T18:00',
            '2026-09-24T18:00',
            '2026-09-29T18:00',
            '2026-10-01T18:00',
        ], $this->starts($occurrences));
    }

    public function testWeeklyIntervalKeepsTheStartWeeksAsItsAnchor(): void
    {
        $occurrences = $this->expand(
            '2026-09-21T18:00',
            'FREQ=WEEKLY;INTERVAL=2;BYDAY=MO,WE',
            '2026-09-21',
            '2026-10-20'
        );

        self::assertSame([
            '2026-09-21T18:00',
            '2026-09-23T18:00',
            '2026-10-05T18:00',
            '2026-10-07T18:00',
            '2026-10-19T18:00',
        ], $this->starts($occurrences));
    }

    public function testWeeklyIntervalKeepsItsPhaseWhenFastForwarding(): void
    {
        $occurrences = $this->expand(
            '2026-01-05T18:00',
            'FREQ=WEEKLY;INTERVAL=2;BYDAY=MO',
            '2026-02-02',
            '2026-03-01'
        );

        self::assertSame([
            '2026-02-02T18:00',
            '2026-02-16T18:00',
        ], $this->starts($occurrences));
    }

    public function testMonthlyFirstFridayIncludesMonthsWithFewerThanFiveFridays(): void
    {
        $occurrences = $this->expand(
            '2026-01-02T09:00',
            'FREQ=MONTHLY;BYDAY=1FR',
            '2026-01-01',
            '2026-05-31'
        );

        self::assertSame([
            '2026-01-02T09:00',
            '2026-02-06T09:00',
            '2026-03-06T09:00',
            '2026-04-03T09:00',
            '2026-05-01T09:00',
        ], $this->starts($occurrences));
    }

    public function testMonthlyIntervalSkipsAlternateMonths(): void
    {
        $occurrences = $this->expand(
            '2026-01-02T09:00',
            'FREQ=MONTHLY;INTERVAL=2;BYDAY=1FR',
            '2026-01-01',
            '2026-06-30'
        );

        self::assertSame([
            '2026-01-02T09:00',
            '2026-03-06T09:00',
            '2026-05-01T09:00',
        ], $this->starts($occurrences));
    }

    public function testMonthlyIntervalKeepsItsPhaseWhenFastForwarding(): void
    {
        $occurrences = $this->expand(
            '2026-01-02T09:00',
            'FREQ=MONTHLY;INTERVAL=2;BYDAY=1FR',
            '2026-05-01',
            '2026-08-31'
        );

        self::assertSame([
            '2026-05-01T09:00',
            '2026-07-03T09:00',
        ], $this->starts($occurrences));
    }

    public function testMonthlyLastSundayUsesTheLastSundayInShortMonths(): void
    {
        $occurrences = $this->expand(
            '2026-01-25T10:00',
            'FREQ=MONTHLY;BYDAY=-1SU',
            '2026-01-01',
            '2026-03-31'
        );

        self::assertSame([
            '2026-01-25T10:00',
            '2026-02-22T10:00',
            '2026-03-29T10:00',
        ], $this->starts($occurrences));
    }

    public function testMonthlyFifthFridaySkipsMonthsWithoutAFifthFriday(): void
    {
        $occurrences = $this->expand(
            '2026-01-30T12:00',
            'FREQ=MONTHLY;BYDAY=5FR',
            '2026-01-01',
            '2026-10-31'
        );

        self::assertSame([
            '2026-01-30T12:00',
            '2026-05-29T12:00',
            '2026-07-31T12:00',
            '2026-10-30T12:00',
        ], $this->starts($occurrences));
    }

    public function testMonthlyByMonthDaySkipsShortMonthsAndSupportsNegativeDays(): void
    {
        $positive = $this->expand(
            '2026-01-31T09:00',
            'FREQ=MONTHLY;BYMONTHDAY=31',
            '2026-01-01',
            '2026-04-30'
        );
        $negative = $this->expand(
            '2026-01-31T09:00',
            'FREQ=MONTHLY;BYMONTHDAY=-1',
            '2026-01-01',
            '2026-04-30'
        );

        self::assertSame([
            '2026-01-31T09:00',
            '2026-03-31T09:00',
        ], $this->starts($positive));
        self::assertSame([
            '2026-01-31T09:00',
            '2026-02-28T09:00',
            '2026-03-31T09:00',
            '2026-04-30T09:00',
        ], $this->starts($negative));
    }

    public function testByDayAndByMonthDayAreCombinedAndByMonthFiltersDailyRules(): void
    {
        $fridayThirteenths = $this->expand(
            '2026-02-13T09:00',
            'FREQ=MONTHLY;BYMONTHDAY=13;BYDAY=FR',
            '2026-02-01',
            '2026-12-31'
        );
        $monthEnds = $this->expand(
            '2026-02-28T09:00',
            'FREQ=DAILY;BYMONTH=2,3;BYMONTHDAY=-1',
            '2026-02-01',
            '2027-04-01'
        );

        self::assertSame([
            '2026-02-13T09:00',
            '2026-03-13T09:00',
            '2026-11-13T09:00',
        ], $this->starts($fridayThirteenths));
        self::assertSame([
            '2026-02-28T09:00',
            '2026-03-31T09:00',
            '2027-02-28T09:00',
            '2027-03-31T09:00',
        ], $this->starts($monthEnds));
    }

    public function testYearlyIntervalByMonthAndLeapDayCombination(): void
    {
        $occurrences = $this->expand(
            '2024-02-29T08:30',
            'FREQ=YEARLY;INTERVAL=2;BYMONTH=2;BYMONTHDAY=29',
            '2024-01-01',
            '2030-12-31'
        );

        self::assertSame([
            '2024-02-29T08:30',
            '2028-02-29T08:30',
        ], $this->starts($occurrences));
    }

    public function testYearlyOrdinalByDayWithoutByMonthUsesTheYear(): void
    {
        $occurrences = $this->expand(
            '2026-12-27T11:00',
            'FREQ=YEARLY;BYDAY=-1SU',
            '2026-01-01',
            '2028-12-31'
        );

        self::assertSame([
            '2026-12-27T11:00',
            '2027-12-26T11:00',
            '2028-12-31T11:00',
        ], $this->starts($occurrences));
    }

    public function testSetPositionSelectsTheLastWeekdayOfEachMonth(): void
    {
        $occurrences = $this->expand(
            '2026-09-30T09:00',
            'FREQ=MONTHLY;BYDAY=MO,TU,WE,TH,FR;BYSETPOS=-1',
            '2026-09-01',
            '2026-11-30'
        );

        self::assertSame([
            '2026-09-30T09:00',
            '2026-10-30T09:00',
            '2026-11-30T09:00',
        ], $this->starts($occurrences));
    }

    public function testCountIncludesTheStartAndIsNotExtendedByAnExclusion(): void
    {
        $occurrences = $this->expand(
            '2026-12-29T09:00',
            'FREQ=DAILY;COUNT=5',
            '2026-12-28',
            '2027-01-10',
            ['2026-12-30T09:00']
        );

        self::assertSame([
            '2026-12-29T09:00',
            '2026-12-31T09:00',
            '2027-01-01T09:00',
            '2027-01-02T09:00',
        ], $this->starts($occurrences));
    }

    public function testCountIsAppliedBeforeAWindowWhoseStartPredatesTheSeriesEnd(): void
    {
        $occurrences = $this->expand(
            '2026-12-01T09:00',
            'FREQ=DAILY;COUNT=10',
            '2026-12-07',
            '2026-12-20'
        );

        self::assertSame([
            '2026-12-07T09:00',
            '2026-12-08T09:00',
            '2026-12-09T09:00',
            '2026-12-10T09:00',
        ], $this->starts($occurrences));
    }

    public function testOldCountedSeriesDoesNotRestartAtTheWindowBoundary(): void
    {
        $occurrences = $this->expand(
            '2026-01-01T09:00',
            'FREQ=DAILY;COUNT=5',
            '2026-09-25',
            '2027-09-25'
        );

        self::assertSame([], $this->starts($occurrences));
    }

    public function testUntilIsInclusiveForLocalAndUtcDateTimesAndAllDayDates(): void
    {
        $local = $this->expand(
            '2026-12-30T09:00',
            'FREQ=DAILY;UNTIL=20261231T090000',
            '2026-12-30',
            '2027-01-02'
        );
        $utc = $this->expand(
            '2026-12-31T23:00',
            'FREQ=DAILY;UNTIL=20261231T210000Z',
            '2026-12-31',
            '2027-01-02'
        );
        $allDay = $this->expand(
            '2026-12-30T00:00',
            'FREQ=DAILY;UNTIL=20261231',
            '2026-12-30',
            '2027-01-02',
            [],
            [],
            true
        );

        self::assertSame([
            '2026-12-30T09:00',
            '2026-12-31T09:00',
        ], $this->starts($local));
        self::assertSame(['2026-12-31T23:00'], $this->starts($utc));
        self::assertSame([
            '2026-12-30T00:00',
            '2026-12-31T00:00',
        ], $this->starts($allDay));
    }

    public function testExceptionsRemoveRuleAndAdditionalDatesWhileRdatesAreDeduplicated(): void
    {
        $occurrences = $this->expand(
            '2026-09-25T09:00',
            'FREQ=WEEKLY;BYDAY=FR',
            '2026-09-25',
            '2026-10-10',
            ['2026-09-25T09:00', '2026-10-02T09:00'],
            ['2026-10-02T09:00', '2026-10-05T09:00', '2026-10-05T09:00']
        );

        self::assertSame([
            '2026-10-05T09:00',
            '2026-10-09T09:00',
        ], $this->starts($occurrences));
    }

    public function testRdateIsIncludedOutsideUntilAndStartDateIsRetainedEvenIfRuleDoesNotMatch(): void
    {
        $occurrences = $this->expand(
            '2026-10-01T09:00',
            'FREQ=MONTHLY;BYDAY=1FR;UNTIL=20261231T090000',
            '2026-10-01',
            '2027-01-01',
            [],
            ['2027-01-01T09:00']
        );

        self::assertSame([
            '2026-10-01T09:00',
            '2026-10-02T09:00',
            '2026-11-06T09:00',
            '2026-12-04T09:00',
            '2027-01-01T09:00',
        ], $this->starts($occurrences));
    }

    public function testFastForwardsAnOldDailySeriesToTheWindow(): void
    {
        $occurrences = $this->expand(
            '1000-01-01T09:00',
            'FREQ=DAILY',
            '2026-09-25',
            '2026-09-27'
        );

        self::assertSame([
            '2026-09-25T09:00',
            '2026-09-26T09:00',
            '2026-09-27T09:00',
        ], $this->starts($occurrences));
    }

    public function testFastForwardsAnOldCountedDailySeriesAcrossGregorianCycles(): void
    {
        $occurrences = $this->expand(
            '1000-01-01T09:00',
            'FREQ=DAILY;COUNT=1000000000',
            '2026-09-25',
            '2026-09-27'
        );

        self::assertSame([
            '2026-09-25T09:00',
            '2026-09-26T09:00',
            '2026-09-27T09:00',
        ], $this->starts($occurrences));
    }

    public function testTimedEndKeepsItsLocalClockTimeAndDayOffset(): void
    {
        $occurrences = $this->expand(
            '2026-09-25T23:00',
            'FREQ=WEEKLY;BYDAY=FR',
            '2026-10-02',
            '2026-10-02',
            [],
            [],
            false,
            '2026-09-26T01:30'
        );

        self::assertSame(['2026-10-02T23:00'], $this->starts($occurrences));
        self::assertSame('2026-10-03T01:30', $occurrences[0]->endLocal?->format('Y-m-d\TH:i'));
    }

    public function testAllDayEndDateIsStoredAsAnExclusiveLocalMidnight(): void
    {
        $occurrences = $this->expand(
            '2026-10-10T18:00',
            null,
            '2026-10-10',
            '2026-10-10',
            [],
            [],
            true,
            '2026-10-11T18:00'
        );

        self::assertSame('2026-10-10T00:00', $occurrences[0]->startLocal->format('Y-m-d\TH:i'));
        self::assertSame('2026-10-12T00:00', $occurrences[0]->endLocal?->format('Y-m-d\TH:i'));
        self::assertSame('2026-10-09T22:00:00', $occurrences[0]->startLocal->setTimezone(
            new DateTimeZone('UTC')
        )->format('Y-m-d\TH:i:s'));
    }

    public function testSouthAfricanLocalClockTimeStaysStableAcrossTheHistoricDstSeason(): void
    {
        $occurrences = $this->expand(
            '2026-10-24T09:00',
            'FREQ=DAILY;COUNT=3',
            '2026-10-24',
            '2026-10-26'
        );

        self::assertSame([
            '2026-10-24T09:00',
            '2026-10-25T09:00',
            '2026-10-26T09:00',
        ], $this->starts($occurrences));
        self::assertSame(
            ['2026-10-24T07:00:00', '2026-10-25T07:00:00', '2026-10-26T07:00:00'],
            array_map(
                static fn (ExpandedOccurrence $occurrence): string => $occurrence->startLocal
                    ->setTimezone(new DateTimeZone('UTC'))
                    ->format('Y-m-d\TH:i:s'),
                $occurrences
            )
        );
    }

    /**
     * @param list<string> $exdates
     * @param list<string> $rdates
     * @return list<ExpandedOccurrence>
     */
    private function expand(
        string $startLocal,
        ?string $rrule,
        string $windowStart,
        string $windowEnd,
        array $exdates = [],
        array $rdates = [],
        bool $allDay = false,
        ?string $endLocal = null
    ): array {
        $details = new EventDetails(
            9,
            19,
            $startLocal,
            $endLocal,
            $allDay,
            $rrule,
            $exdates,
            $rdates,
            false,
            'scheduled',
            null,
            []
        );
        $window = OccurrenceWindow::fromLocalDates($windowStart, $windowEnd, $this->timezone);

        return (new OccurrenceExpander($this->timezone))->expand($details, $window);
    }

    /**
     * @param list<ExpandedOccurrence> $occurrences
     * @return list<string>
     */
    private function starts(array $occurrences): array
    {
        return array_map(
            static fn (ExpandedOccurrence $occurrence): string => $occurrence->startLocal->format('Y-m-d\TH:i'),
            $occurrences
        );
    }
}
