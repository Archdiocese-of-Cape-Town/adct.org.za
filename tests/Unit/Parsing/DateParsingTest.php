<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Parsing;

use ADCT\ParishIntake\Parsing\Input\Message;
use ADCT\ParishIntake\Parsing\PipelineFactory;
use ADCT\ParishIntake\Support\ClockInterface;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DateParsingTest extends TestCase
{
    private static function parse(
        string $body,
        string $referenceDate = '2026-10-01 12:00:00',
        string $subject = ''
    ): array {
        $message = new Message(
            'email',
            'date-test',
            'events@example.test',
            'Example Parish Office',
            $subject,
            $body,
            [],
            new DateTimeImmutable($referenceDate, new DateTimeZone('Africa/Johannesburg'))
        );

        return (new PipelineFactory())
            ->create()
            ->parse($message)
            ->toArray();
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function supportedDateForms(): array
    {
        return [
            'South African day-first numeric date' => ['12/10/2026', '2026-10-12', '2026-10-01'],
            'ISO date' => ['2026-10-05', '2026-10-05', '2026-10-01'],
            'ordinal date' => ['5th October', '2026-10-05', '2026-10-01'],
            'weekday and abbreviated month' => ['Sat 5 Oct', '2024-10-05', '2024-10-01'],
            'date range start' => ['5-6 October', '2026-10-05', '2026-10-01'],
            'date range with an en dash' => ['5–6 October', '2026-10-05', '2026-10-01'],
            'dotted date with two-digit year' => ['Sat 15.6.24', '2024-06-15', '2024-06-01'],
            'weekday and abbreviated month without year' => ['Mon 07 Sept', '2026-09-07', '2026-09-01'],
            'ordinal date without year' => ['9th September', '2027-09-09', '2026-09-10'],
            'full weekday and ordinal day' => ['Saturday 15th June', '2026-06-15', '2026-06-01'],
            'Tuesday abbreviation' => ['Tues 15 Sep', '2026-09-15', '2026-09-01'],
            'month-first date with year' => ['October 5, 2026', '2026-10-05', '2026-10-01'],
            'date on reference day' => ['5th October', '2026-10-05', '2026-10-05'],
        ];
    }

    #[DataProvider('supportedDateForms')]
    public function testExtractsSupportedDateForms(
        string $text,
        string $expectedDate,
        string $referenceDate
    ): void {
        $result = self::parse($text, $referenceDate);

        self::assertSame($expectedDate, $result['fields']['event_date'] ?? null);
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function relativeDateForms(): array
    {
        return [
            'this Sunday is the next Sunday on or after the reference date' => [
                'this Sunday',
                '2026-10-04',
                '2026-10-01',
            ],
            'next Friday is strictly after the reference date' => [
                'next Friday',
                '2026-10-09',
                '2026-10-02',
            ],
            'next Friday before Friday is the upcoming Friday' => [
                'next Friday',
                '2026-10-02',
                '2026-10-01',
            ],
            'tomorrow' => ['tomorrow', '2026-10-02', '2026-10-01'],
            'tonight' => ['tonight', '2026-10-01', '2026-10-01'],
        ];
    }

    #[DataProvider('relativeDateForms')]
    public function testResolvesRelativeDates(
        string $text,
        string $expectedDate,
        string $referenceDate
    ): void {
        $result = self::parse($text, $referenceDate);

        self::assertSame($expectedDate, $result['fields']['event_date'] ?? null);
    }

    public function testResolvesReferenceDatesInJohannesburgTime(): void
    {
        $result = self::parse('tomorrow', '2026-09-30T22:30:00+00:00');

        self::assertSame('2026-10-02', $result['fields']['event_date'] ?? null);
    }

    public function testUsesInjectedClockWhenMessageHasNoReceivedDate(): void
    {
        $message = new Message(
            'email',
            'clock-test',
            'events@example.test',
            'Example Parish Office',
            '',
            'tomorrow'
        );
        $clock = new FrozenClock(new DateTimeImmutable(
            '2026-10-01 12:00:00',
            new DateTimeZone('Africa/Johannesburg')
        ));

        $result = (new PipelineFactory($clock))->create()->parse($message)->toArray();

        self::assertSame('2026-10-02', $result['fields']['event_date'] ?? null);
    }

    public function testReceivedDateTakesPrecedenceOverInjectedClock(): void
    {
        $message = new Message(
            'email',
            'received-date-test',
            'events@example.test',
            'Example Parish Office',
            '',
            'tomorrow',
            [],
            new DateTimeImmutable('2026-10-01 12:00:00', new DateTimeZone('Africa/Johannesburg'))
        );
        $clock = new FrozenClock(new DateTimeImmutable(
            '2026-09-01 12:00:00',
            new DateTimeZone('Africa/Johannesburg')
        ));

        $result = (new PipelineFactory($clock))->create()->parse($message)->toArray();

        self::assertSame('2026-10-02', $result['fields']['event_date'] ?? null);
    }

    public function testBulletinDateRangeSetsReferenceForYearlessEvents(): void
    {
        $result = self::parse(
            'Mass on 07 September.',
            '2026-10-01',
            'Bulletin 06 September to 13 September 2026'
        );

        self::assertSame('2026-09-07', $result['fields']['event_date'] ?? null);
    }

    public function testPreservesDateRangeEnd(): void
    {
        $result = self::parse('5-6 October', '2026-10-01');

        self::assertSame('2026-10-05', $result['fields']['event_date'] ?? null);
        self::assertSame('2026-10-06', $result['fields']['event_end_date'] ?? null);
    }

    public function testDottedDateIsNotMistakenForATime(): void
    {
        $result = self::parse('Sat 15.06.24', '2024-06-01');

        self::assertSame('2024-06-15', $result['fields']['event_date'] ?? null);
        self::assertArrayNotHasKey('event_time', $result['fields']);
    }

    public function testDecimalAmountIsNotMistakenForADottedTime(): void
    {
        $result = self::parse('Registration costs R 20.00.');

        self::assertArrayNotHasKey('event_time', $result['fields']);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function supportedTimeForms(): array
    {
        return [
            '24-hour time' => ['18:00', '18:00'],
            '24-hour time with leading zero' => ['08:30', '08:30'],
            'pm time' => ['6pm', '18:00'],
            'midnight in 12-hour time' => ['12am', '00:00'],
            'noon in 12-hour time' => ['12pm', '12:00'],
            'dotted pm time' => ['6.30pm', '18:30'],
            'dotted am time with space' => ['8.30 am', '08:30'],
            'range uses its start time' => ['from 9am to 1pm', '09:00'],
        ];
    }

    #[DataProvider('supportedTimeForms')]
    public function testExtractsLocalTimesAsTwentyFourHourValues(string $text, string $expectedTime): void
    {
        $result = self::parse('The event begins ' . $text . '.');

        self::assertSame($expectedTime, $result['fields']['event_time'] ?? null);
    }

    public function testPreservesTimeRangeEnd(): void
    {
        $result = self::parse('The event runs from 9am to 1pm.');

        self::assertSame('09:00', $result['fields']['event_time'] ?? null);
        self::assertSame('13:00', $result['fields']['event_end_time'] ?? null);
    }

    public function testSingleDatesAndTimesOmitEndFields(): void
    {
        $dateResult = self::parse('5 October', '2026-10-01');
        $timeResult = self::parse('The event begins at 18:00.');

        self::assertArrayNotHasKey('event_end_date', $dateResult['fields']);
        self::assertArrayNotHasKey('event_end_time', $timeResult['fields']);
    }

    public function testWeekdayMismatchIsFlaggedAndLowersConfidence(): void
    {
        $mismatched = self::parse('Sat 5 Oct', '2026-10-01');
        $withoutWeekday = self::parse('5 Oct', '2026-10-01');

        self::assertSame('2026-10-05', $mismatched['fields']['event_date'] ?? null);
        self::assertLessThan($withoutWeekday['confidence'], $mismatched['confidence']);
        self::assertStringContainsString('weekday', strtolower(implode(' ', $mismatched['notes'])));
    }

    public function testDateRangeEndBeforeStartIsFlaggedAndLowersConfidence(): void
    {
        $reversed = self::parse('6-5 October', '2026-10-01');
        $ordered = self::parse('5-6 October', '2026-10-01');

        self::assertSame('2026-10-06', $reversed['fields']['event_date'] ?? null);
        self::assertSame('2026-10-05', $reversed['fields']['event_end_date'] ?? null);
        self::assertLessThan($ordered['confidence'], $reversed['confidence']);
        self::assertStringContainsString('end', strtolower(implode(' ', $reversed['notes'])));
    }

    public function testTimeRangeEndBeforeStartIsFlaggedAndLowersConfidence(): void
    {
        $reversed = self::parse('The event runs from 9pm to 1am.');
        $ordered = self::parse('The event runs from 9pm to 11pm.');

        self::assertSame('21:00', $reversed['fields']['event_time'] ?? null);
        self::assertSame('01:00', $reversed['fields']['event_end_time'] ?? null);
        self::assertLessThan($ordered['confidence'], $reversed['confidence']);
        self::assertStringContainsString('end', strtolower(implode(' ', $reversed['notes'])));
    }
}

final class FrozenClock implements ClockInterface
{
    public function __construct(private DateTimeImmutable $time)
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->time;
    }
}
