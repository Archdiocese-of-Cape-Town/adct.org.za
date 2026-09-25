<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Parsing;

use ADCT\ParishIntake\Core\Events\RRuleValidator;
use ADCT\ParishIntake\Core\Parsing\Input\Message;
use ADCT\ParishIntake\Core\Parsing\PipelineFactory;
use ADCT\ParishIntake\Core\Ports\ClockInterface;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RecurrenceDetectionStageTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function supportedPhrases(): iterable
    {
        yield 'first Friday of the month' => [
            'Every first Friday (of the month)',
            '2026-10-02',
            'FREQ=MONTHLY;BYDAY=1FR',
        ];
        yield 'first and third Sunday' => [
            '1st and 3rd Sunday',
            '2026-10-04',
            'FREQ=MONTHLY;BYDAY=1SU,3SU',
        ];
        yield 'two weekly weekdays' => [
            'Every Tuesday and Thursday',
            '2026-10-01',
            'FREQ=WEEKLY;BYDAY=TU,TH',
        ];
        yield 'weekdays' => [
            'Weekdays',
            '2026-10-01',
            'FREQ=WEEKLY;BYDAY=MO,TU,WE,TH,FR',
        ];
        yield 'weekly weekday list' => [
            'Weekly on Monday, Wednesday and Friday',
            '2026-10-02',
            'FREQ=WEEKLY;BYDAY=MO,WE,FR',
        ];
        yield 'every second week' => [
            'Every second week',
            '2026-10-02',
            'FREQ=WEEKLY;INTERVAL=2',
        ];
        yield 'monthly on the fifteenth' => [
            'Monthly on the 15th',
            '2026-10-15',
            'FREQ=MONTHLY;BYMONTHDAY=15',
        ];
        yield 'until a yearless date' => [
            'Every Tuesday and Thursday until 30 November',
            '2026-10-01',
            'FREQ=WEEKLY;BYDAY=TU,TH;UNTIL=20261130T215959Z',
        ];
        yield 'nine-day novena' => [
            'Novena for 9 days',
            '2026-11-02',
            'FREQ=DAILY;COUNT=9',
        ];
        yield 'last Friday of the month' => [
            'Last Friday of the month',
            '2026-10-30',
            'FREQ=MONTHLY;BYDAY=-1FR',
        ];
    }

    #[DataProvider('supportedPhrases')]
    public function testBuildsValidatedRulesAndKeepsTheExplicitAnchor(
        string $phrase,
        string $anchorDate,
        string $expectedRule
    ): void {
        $date = new DateTimeImmutable($anchorDate, new DateTimeZone('Africa/Johannesburg'));
        $body = sprintf(
            'The event begins on %s at 18:00. %s.',
            $date->format('j F Y'),
            $phrase
        );
        $result = self::parse($body);
        $recurrence = $result['recurrence'];

        self::assertSame('recurring_event', $result['classification']);
        self::assertSame($anchorDate, $result['fields']['event_date'] ?? null);
        self::assertSame($expectedRule, $recurrence['rrule'] ?? null);
        self::assertSame($phrase, $recurrence['text'] ?? null);
        self::assertArrayNotHasKey('ambiguous', $recurrence);

        $validation = (new RRuleValidator())->validate(
            $recurrence['rrule'] ?? null,
            ! isset($result['fields']['event_time'])
        );
        self::assertTrue($validation->isValid(), implode(' ', $validation->errors));
    }

    public function testYearlessUntilRollsForwardFromTheAnchorAndIsNotUsedAsTheStartDate(): void
    {
        $result = self::parse(
            'The event begins on 2 December 2026 at 18:00. Every Tuesday and Thursday until 30 November.'
        );

        self::assertSame('2026-12-02', $result['fields']['event_date'] ?? null);
        self::assertSame(
            'FREQ=WEEKLY;BYDAY=TU,TH;UNTIL=20271130T215959Z',
            $result['recurrence']['rrule'] ?? null
        );
        self::assertStringContainsString('year', strtolower(implode(' ', $result['notes'])));
    }

    public function testDeterministicRecurrenceInfersTheNextOccurrenceAndLowersConfidence(): void
    {
        $inferred = self::parse('Every first Friday at 18:00.', 'Example event', '2026-10-01 09:00:00');
        $explicit = self::parse(
            'The event begins on 2 October 2026 at 18:00. Every first Friday.',
            'Example event',
            '2026-10-01 09:00:00'
        );

        self::assertSame('2026-10-02', $inferred['fields']['event_date'] ?? null);
        self::assertSame('FREQ=MONTHLY;BYDAY=1FR', $inferred['recurrence']['rrule'] ?? null);
        self::assertStringContainsString('recurrence_anchor_inferred', implode(' ', $inferred['notes']));
        self::assertTrue($inferred['recurrence']['anchor_inferred'] ?? false);
        self::assertEqualsWithDelta(0.05, $explicit['confidence'] - $inferred['confidence'], 0.0001);
    }

    public function testUsesTheDateParsersBulletinReferenceWhenInferringAnAnchor(): void
    {
        $result = self::parse(
            "Bulletin 1 September to 7 September 2026\nEvery first Friday at 18:00.",
            'Example event',
            '2026-10-01 09:00:00'
        );

        self::assertSame('2026-09-04', $result['fields']['event_date'] ?? null);
        self::assertStringContainsString('recurrence_anchor_inferred', implode(' ', $result['notes']));
    }

    public function testYearlessUntilUsesAnInferredAnchorInsteadOfTheEndDateAsTheStart(): void
    {
        $result = self::parse(
            'Every Tuesday and Thursday until 30 November at 18:00.',
            'Example event',
            '2026-09-25 09:00:00'
        );

        self::assertSame('2026-09-29', $result['fields']['event_date'] ?? null);
        self::assertSame(
            'FREQ=WEEKLY;BYDAY=TU,TH;UNTIL=20261130T215959Z',
            $result['recurrence']['rrule'] ?? null
        );
        self::assertStringContainsString('recurrence_anchor_inferred', implode(' ', $result['notes']));
    }

    public function testMonthlyDayAnchorMovesToTheFirstOccurrenceAfterTheReferenceDate(): void
    {
        $result = self::parse('Monthly on the 15th at 18:00.', 'Example event', '2026-10-16 09:00:00');

        self::assertSame('2026-11-15', $result['fields']['event_date'] ?? null);
        self::assertSame('FREQ=MONTHLY;BYMONTHDAY=15', $result['recurrence']['rrule'] ?? null);
        self::assertStringContainsString('recurrence_anchor_inferred', implode(' ', $result['notes']));
    }

    public function testAmbiguousMonthlyPrototypeStillRequiresConfirmation(): void
    {
        $result = self::parse('Every month.', 'Example event');
        $rule = $result['recurrence']['rrule'] ?? null;

        self::assertSame('FREQ=MONTHLY', $rule);
        self::assertTrue($result['recurrence']['ambiguous'] ?? false);
        self::assertTrue($result['reprocess_needed']);
        self::assertTrue((new RRuleValidator())->validate($rule)->isValid());
    }

    public function testInjectedClockProvidesTheAnchorWhenReceivedDateIsMissing(): void
    {
        $result = self::parse('Every first Friday at 18:00.', 'Example event', null);

        self::assertSame('2026-10-02', $result['fields']['event_date'] ?? null);
        self::assertStringContainsString('recurrence_anchor_inferred', implode(' ', $result['notes']));
    }

    public function testYearlessUntilWithoutAnEventTimeEmitsAValidatorCompatibleDateTime(): void
    {
        $result = self::parse('Every Tuesday until 30 November.', 'Example event', '2026-10-01 09:00:00');
        $rule = $result['recurrence']['rrule'] ?? null;

        self::assertSame('2026-10-06', $result['fields']['event_date'] ?? null);
        self::assertSame('FREQ=WEEKLY;BYDAY=TU;UNTIL=20261130T215959Z', $rule);
        self::assertTrue((new RRuleValidator())->validate($rule)->isValid());
    }

    public function testLiturgicalRecurrenceIsUnanchoredAndNeedsConfirmation(): void
    {
        $ambiguous = self::parse('Daily during Lent at 18:00.', 'Prayer service');
        $clear = self::parse(
            'Every Tuesday at 18:00.',
            'Prayer service'
        );

        self::assertArrayNotHasKey('event_date', $ambiguous['fields']);
        self::assertArrayNotHasKey('rrule', $ambiguous['recurrence']);
        self::assertSame('daily', $ambiguous['recurrence']['frequency'] ?? null);
        self::assertTrue($ambiguous['recurrence']['ambiguous'] ?? false);
        self::assertTrue($ambiguous['reprocess_needed']);
        self::assertStringContainsString(
            'recurrence_ambiguous_season',
            implode(' ', $ambiguous['notes'])
        );
        self::assertLessThan($clear['confidence'], $ambiguous['confidence']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonRecurringPhrases(): iterable
    {
        yield 'first Friday with a single date' => [
            'Our first Friday healing Mass is a one-time event on 2 October 2026 at 18:00.',
        ];
        yield 'every blessing' => [
            'Every blessing is shared with the parish community.',
        ];
        yield 'weekly newsletter schedule' => [
            'The weekly newsletter is sent every Tuesday.',
        ];
        yield 'duration without a novena' => [
            'The community clean-up lasts for 9 days, beginning on 2 October 2026.',
        ];
    }

    #[DataProvider('nonRecurringPhrases')]
    public function testNonRecurringPhrasesNeverProduceAnRRule(string $body): void
    {
        $result = self::parse($body, 'Community notice');

        self::assertSame([], $result['recurrence']);
        self::assertNotSame('recurring_event', $result['classification']);
    }

    private static function parse(
        string $body,
        string $subject = 'Example event',
        ?string $receivedAt = '2026-09-25 09:00:00'
    ): array
    {
        $referenceDate = new DateTimeImmutable(
            '2026-09-25 09:00:00',
            new DateTimeZone('Africa/Johannesburg')
        );
        $message = new Message(
            'email',
            'recurrence-test',
            'events@example.test',
            'Example Parish Office',
            $subject,
            $body,
            [],
            $receivedAt === null
                ? null
                : new DateTimeImmutable($receivedAt, new DateTimeZone('Africa/Johannesburg'))
        );

        return (new PipelineFactory(new RecurrenceTestClock($referenceDate)))
            ->create()
            ->parse($message)
            ->toArray();
    }
}

final class RecurrenceTestClock implements ClockInterface
{
    public function __construct(private DateTimeImmutable $time)
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->time;
    }
}
