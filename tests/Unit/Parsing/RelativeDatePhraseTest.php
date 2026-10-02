<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Parsing;

use ADCT\ParishIntake\Core\Parsing\Input\Message;
use ADCT\ParishIntake\Core\Parsing\PipelineFactory;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Relative date phrases that a parish actually writes. Before #132 the recognised
 * set was only "this <weekday>", "next <weekday>", "tomorrow" and "tonight", so
 * phrases such as "this coming Saturday" silently produced no event_date at all --
 * a submission that looked successfully parsed while having quietly lost its date.
 *
 * The reference date is 2026-09-28 (a Monday) throughout, matching the newsletter
 * that surfaced the defect.
 *
 * Not covered here, deliberately: typing a deadline as a deadline rather than an
 * attendable event. #132 asks for it but it needs a distinct field and a publishing
 * change, so it is tracked separately -- see the note in
 * docs/parish-intake-project-backlog.md.
 */
final class RelativeDatePhraseTest extends TestCase
{
    private const REFERENCE = '2026-09-28 08:00:00';

    private static function parse(string $body, string $referenceDate = self::REFERENCE): array
    {
        $message = new Message(
            'email',
            'relative-date-test',
            'events@example.test',
            'Fictional Office',
            '',
            $body,
            [],
            new DateTimeImmutable($referenceDate, new DateTimeZone('Africa/Johannesburg'))
        );

        return (new PipelineFactory())
            ->create()
            ->parse($message)
            ->toArray();
    }

    private static function eventDate(string $body, string $referenceDate = self::REFERENCE): ?string
    {
        return self::parse($body, $referenceDate)['fields']['event_date'] ?? null;
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function weekdayPhraseProvider(): array
    {
        return [
            // 2026-09-28 is a Monday, so Saturday is 3 October 2026.
            'this Saturday, unwrapped' => ['The centenary Mass is on this Saturday.', '2026-10-03'],
            'this Saturday, wrapped' => ["The centenary Mass is on this\nSaturday.", '2026-10-03'],
            'this coming Saturday' => ['The centenary Mass is on this coming Saturday.', '2026-10-03'],
            'this coming Saturday, wrapped' => ["The centenary Mass is on this coming\nSaturday.", '2026-10-03'],
            'coming Saturday' => ['The parish lunch is on coming Saturday.', '2026-10-03'],
            'coming Wednesday' => ['Catechism class is on coming Wednesday.', '2026-09-30'],
            'this coming Friday' => ['Retreat is on this coming Friday.', '2026-10-02'],
        ];
    }

    #[DataProvider('weekdayPhraseProvider')]
    public function testRelativeWeekdayPhraseResolves(string $body, string $expected): void
    {
        self::assertSame($expected, self::eventDate($body));
    }

    /**
     * "end of this month" is relative to the reference date, so those cases use the
     * newsletter reference. The explicit-year cases are dated before the reference,
     * so they use one early in 2026 -- otherwise the year-roll behaviour, not the
     * last-day-of-month behaviour, is what gets tested.
     *
     * @return array<string, array{string, string, string}>
     */
    public static function endOfMonthProvider(): array
    {
        return [
            'end of this month' => ['Please send responses by the end of this month.', '2026-09-30', self::REFERENCE],
            'end of this month, no "the"' => ['Please send responses by end of this month.', '2026-09-30', self::REFERENCE],
            'end of the month' => ['Please send responses by the end of the month.', '2026-09-30', self::REFERENCE],
            'end of September' => ['Diocesan returns are due end of September.', '2026-09-30', self::REFERENCE],
            'end of September 2026' => ['Diocesan returns are due end of September 2026.', '2026-09-30', self::REFERENCE],
            'end of December 2026' => ['The appeal closes end of December 2026.', '2026-12-31', self::REFERENCE],
            'end of February 2027' => ['The appeal closes end of February 2027.', '2027-02-28', self::REFERENCE],
            // In the past relative to the reference, so read against an earlier date.
            'end of February 2026' => ['The appeal closes end of February 2026.', '2026-02-28', '2026-01-05 08:00:00'],
            // A leap February must resolve to the 29th, not the 28th.
            'end of February 2028' => ['The appeal closes end of February 2028.', '2028-02-29', '2028-01-05 08:00:00'],
            'end of April 2026' => ['The appeal closes end of April 2026.', '2026-04-30', self::REFERENCE],
        ];
    }

    #[DataProvider('endOfMonthProvider')]
    public function testEndOfMonthResolvesToTheLastDayOfTheMonth(
        string $body,
        string $expected,
        string $referenceDate
    ): void {
        self::assertSame($expected, self::eventDate($body, $referenceDate));
    }

    /**
     * A month and day with no year is resolved forward from the reference date, so
     * these run against a reference in the month *before* September. That keeps the
     * case about the ordinal suffix rather than about year rolling, which
     * RelativeDateYearRollTest covers separately.
     *
     * @return array<string, array{string, string}>
     */
    public static function monthDayOrdinalProvider(): array
    {
        return [
            'September 1st' => ['The new term starts September 1st.', '2026-09-01'],
            'September 2nd' => ['The new term starts September 2nd.', '2026-09-02'],
            'September 3rd' => ['The new term starts September 3rd.', '2026-09-03'],
            'September 11th' => ['The new term starts September 11th.', '2026-09-11'],
            'September 12th' => ['The new term starts September 12th.', '2026-09-12'],
            'September 13th' => ['The new term starts September 13th.', '2026-09-13'],
            'the first of September' => ['The new term starts on the first of September.', '2026-09-01'],
            'the first of October' => ['The new term starts on the first of October.', '2026-10-01'],
            'the first of September 2026' => ['The new term starts on the first of September 2026.', '2026-09-01'],
        ];
    }

    #[DataProvider('monthDayOrdinalProvider')]
    public function testMonthDayOrdinalResolves(string $body, string $expected): void
    {
        self::assertSame($expected, self::eventDate($body, '2026-08-15 08:00:00'));
    }

    /**
     * Regression guard: the phrases that already worked before #132 must keep working.
     *
     * @return array<string, array{string, string}>
     */
    public static function existingPhraseProvider(): array
    {
        return [
            'this Saturday' => ['Mass is on this Saturday.', '2026-10-03'],
            'next Saturday' => ['Mass is on next Saturday.', '2026-10-03'],
            'tomorrow' => ['Mass is tomorrow.', '2026-09-29'],
            'tonight' => ['Mass is tonight.', '2026-09-28'],
        ];
    }

    #[DataProvider('existingPhraseProvider')]
    public function testExistingRelativePhrasesStillResolve(string $body, string $expected): void
    {
        self::assertSame($expected, self::eventDate($body));
    }

    /**
     * Regression guard: an explicit date must still beat nothing, and an explicit date
     * with its own day must not be disturbed by the new month-day rules.
     */
    public function testExplicitDayMonthDateStillWins(): void
    {
        self::assertSame('2026-10-12', self::eventDate('The Harvest Lunch is on 12 October 2026.'));
    }

    /**
     * "end of <Month>" must resolve to the last day, never the first. This is the
     * specific error the issue called out.
     */
    public function testEndOfMonthIsNotResolvedToTheFirstOfTheMonth(): void
    {
        self::assertNotSame('2026-09-01', self::eventDate('Returns are due end of September.'));
    }

    /**
     * "this coming <weekday>" is a synonym of "this <weekday>", not a separate rule,
     * so the "coming" wording must not shift the date. A bare weekday and the
     * qualified form have to agree.
     */
    public function testComingWordingIsASynonymAndDoesNotShiftTheDate(): void
    {
        $thisSaturday = self::eventDate('The Mass is on this Saturday.');

        self::assertSame($thisSaturday, self::eventDate('The Mass is on this coming Saturday.'));
        self::assertSame($thisSaturday, self::eventDate('The Mass is on coming Saturday.'));
    }

    /**
     * Regression guard. A bare weekday is not a "this weekday" phrase and must not
     * start resolving to a date on its own -- the qualifier is what makes the phrase
     * relative. This is also what keeps a recurrence phrase such as "every Tuesday"
     * from being read as a one-off event.
     */
    public function testBareWeekdayIsNotResolvedAsARelativeDate(): void
    {
        self::assertNull(self::eventDate('The choir practises on Tuesday.'));
    }

    /**
     * Regression guard. "on or after 29 September" carries a real date that the
     * day-month pattern must still win, rather than a bare "September" being taken
     * as a weekday-style reference.
     */
    public function testOnOrAfterDateIsStillResolved(): void
    {
        self::assertSame('2026-09-29', self::eventDate('Recurs on or after 29 September 2026.'));
    }
}
