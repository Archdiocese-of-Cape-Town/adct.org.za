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
 * #167: a date or time the extractor recognised but could not resolve used to be
 * dropped with nothing recorded anywhere. The submission then looked successfully
 * parsed -- classification, title and confidence all plausible -- while the field
 * a human most needs was simply empty.
 *
 * A wrong value is visible and invites a question. A missing one does not, which is
 * why an unresolved value has to leave a machine-readable trace.
 *
 * Two shapes have to stay apart, and the distinction is the point of the issue:
 *
 *  - the bulletin carried no time at all. Nothing was lost, so nothing is reported.
 *  - the bulletin carried something that reads as a clock but cannot be resolved.
 *    A value was found and dropped, so the reason is recorded.
 *
 * The reason is a token (`unparsed_time_candidate:2575am`) rather than a sentence, so
 * that a screen can decide what to render without matching on prose. The phrase is
 * carried along for the human, and it is only ever a short clock-shaped or
 * date-shaped fragment -- never a line of the source email.
 */
final class UnparsedDateTimeCandidateTest extends TestCase
{
    private const REFERENCE = '2026-10-01 12:00:00';

    private static function parse(string $body, string $referenceDate = self::REFERENCE): array
    {
        $message = new Message(
            'email',
            'unparsed-candidate-test',
            'events@example.test',
            'Example Parish Office',
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

    /**
     * @return list<string>
     */
    private static function notes(array $result): array
    {
        return array_values(array_filter(
            is_array($result['notes'] ?? null) ? $result['notes'] : [],
            'is_string'
        ));
    }

    private static function reasons(array $result, string $reason): array
    {
        $prefix = $reason . ':';

        return array_values(array_filter(
            self::notes($result),
            static fn (string $note): bool => str_starts_with($note, $prefix)
        ));
    }

    /**
     * #165 noted an unreadable time with a sentence. #167 asks for a reason that a
     * screen can branch on, keeping the phrase so a reviewer can find it in the
     * source email and correct it.
     *
     * @return array<string, array{string}>
     */
    public static function unreadableTimes(): array
    {
        return [
            'an impossible compact hour' => ['The event begins at 2575am.'],
            'an hour below the twelve-hour floor' => ['The event begins at 0am.'],
            'an hour above the twelve-hour ceiling' => ['The event begins at 99pm.'],
            'minutes beyond the hour with a meridiem' => ['The event begins at 2560pm.'],
            'a meridiem spaced off the digits' => ['The event begins at 25 75am.'],
        ];
    }

    #[DataProvider('unreadableTimes')]
    public function testAnUnreadableTimeIsRecordedWithAMachineReadableReason(string $body): void
    {
        $result = self::parse($body);

        self::assertArrayNotHasKey('event_time', $result['fields']);
        self::assertCount(1, self::reasons($result, 'unparsed_time_candidate'));
    }

    public function testTheUnreadableTimeReasonCarriesThePhraseAsWritten(): void
    {
        $result = self::parse('The event begins at 2575am.');

        self::assertSame(
            ['unparsed_time_candidate:2575am'],
            self::reasons($result, 'unparsed_time_candidate')
        );
    }

    /**
     * #167 does not want "make 2575am parse". It wants the failure to be visible.
     * Nothing may be invented in its place either: a default would be a confidently
     * wrong time, which is the worse of the two failures.
     */
    public function testAnUnreadableTimeIsNotCoercedToADefault(): void
    {
        $result = self::parse('The event begins at 2575am.');

        self::assertArrayNotHasKey('event_time', $result['fields']);
        self::assertArrayNotHasKey('event_end_time', $result['fields']);
    }

    /**
     * The date side had no reporting at all before #167 -- not even the sentence
     * #165 added for times -- so these inputs produced an empty field and an empty
     * notes array. Each one is a phrase that reads as a date and does not exist.
     *
     * @return array<string, array{string, string}>
     */
    public static function unreadableDates(): array
    {
        return [
            'a day past the end of the month' => ['The retreat is on 32 October 2026.', '32 October 2026'],
            'a month-first day past the end of the month' => ['The retreat is on October 32.', 'October 32'],
            'a month number outside the calendar in numeric form' => ['The retreat is on 12/13/2026.', '12/13/2026'],
            '29 February in a common year' => ['The retreat is on 29 February 2026.', '29 February 2026'],
            'a month number outside the calendar' => ['The retreat is on 99/99/99.', '99/99/99'],
            'a day past the end of the month in numeric form' => ['The retreat is on 32/10/2026.', '32/10/2026'],
        ];
    }

    #[DataProvider('unreadableDates')]
    public function testAnUnreadableDateIsRecordedWithAMachineReadableReason(
        string $body,
        string $phrase
    ): void {
        $result = self::parse($body);

        self::assertArrayNotHasKey('event_date', $result['fields']);
        self::assertSame(
            ['unparsed_date_candidate:' . $phrase],
            self::reasons($result, 'unparsed_date_candidate')
        );
    }

    /**
     * A bulletin with no time in it is the shape the whole issue turns on. Nothing was
     * lost, so nothing is reported: a warning here would train a reviewer to ignore
     * the warnings, and would make an absent value indistinguishable from a failed one.
     */
    public function testABulletinWithNoTimeAtAllReportsNothing(): void
    {
        $result = self::parse('The retreat is on 12 October 2026. All are welcome.');

        self::assertArrayNotHasKey('event_time', $result['fields']);
        self::assertSame([], self::reasons($result, 'unparsed_time_candidate'));
    }

    /**
     * The same bulletin with one unreadable time in it must raise the warning, so the
     * two shapes are distinguishable from the notes alone.
     */
    public function testABulletinWithAnUnreadableTimeReportsIt(): void
    {
        $result = self::parse('The retreat is on 12 October 2026 and begins at 2575am.');

        self::assertSame('2026-10-12', $result['fields']['event_date'] ?? null);
        self::assertCount(1, self::reasons($result, 'unparsed_time_candidate'));
    }

    /**
     * Regression guard for criterion "10.00-12:00am no longer yields 00:00", which
     * #165 already delivered. It is pinned here because it is the case the issue calls
     * the worst one: a confidently wrong time rather than a missing one.
     */
    public function testADottedRangeSharingAMeridiemDoesNotYieldMidnight(): void
    {
        $result = self::parse('The workshop runs from 10.00-12:00am.');

        self::assertSame('10:00', $result['fields']['event_time'] ?? null);
        self::assertSame('12:00', $result['fields']['event_end_time'] ?? null);
        self::assertSame([], self::reasons($result, 'unparsed_time_candidate'));
    }

    /**
     * A value that resolved is not an unresolved candidate. Reporting one would make
     * every successful parse look like a warning.
     *
     * @return array<string, array{string}>
     */
    public static function valuesThatResolve(): array
    {
        return [
            'a compact time' => ['The pioneer picnic is on 9 October at 830am.'],
            'a dotted time' => ['The vigil is on 9 October at 8.30pm.'],
            'a twenty-four hour time' => ['The vigil is on 9 October at 18:00.'],
            'a readable date' => ['The retreat is on 12 October 2026.'],
            'a day-first numeric date' => ['The retreat is on 12/10/2026.'],
        ];
    }

    #[DataProvider('valuesThatResolve')]
    public function testAValueThatResolvesLeavesNoUnparsedReason(string $body): void
    {
        $result = self::parse($body);

        self::assertSame([], self::reasons($result, 'unparsed_time_candidate'));
        self::assertSame([], self::reasons($result, 'unparsed_date_candidate'));
    }

    /**
     * Regression guards for the digit-run rules #165 tightened. Each of these is a
     * number that is not a time or a date, and a reporting rule loose enough to claim
     * them would start warning on ordinary bulletins.
     *
     * @return array<string, array{string}>
     */
    public static function numbersThatAreNotDatesOrTimes(): array
    {
        return [
            'a seat count' => ['The hall has 1200 seats.'],
            'a bare reference number' => ['Ref 830 meets in the hall.'],
            'a four digit year in prose' => ['The hall opens in 2027.'],
            'a phone number' => ['Ring 021 555 1234 for details.'],
            'a scripture citation' => ['The reading is from Psalms 119:105. Mass is at 18:00.'],
            'a scripture citation with no time' => ['The reading for today is Psalms 119:105.'],
            'an amount of money' => ['Registration costs R 20.00.'],
        ];
    }

    #[DataProvider('numbersThatAreNotDatesOrTimes')]
    public function testANumberThatIsNeitherADateNorATimeIsNotReported(string $body): void
    {
        $result = self::parse($body);

        self::assertSame([], self::reasons($result, 'unparsed_time_candidate'));
        self::assertSame([], self::reasons($result, 'unparsed_date_candidate'));
    }

    /**
     * A scripture citation that resolves to no time is the strongest argument for
     * reporting only unresolved values: the citation tokens are clock-shaped, and
     * #128's guard must keep them out of the reason as well as out of the field.
     */
    public function testAScriptureCitationDoesNotRaiseATimeReason(): void
    {
        $result = self::parse('Read Psalms 119:105-108 at the start of the service.');

        self::assertArrayNotHasKey('event_time', $result['fields']);
        self::assertSame([], self::reasons($result, 'unparsed_time_candidate'));
    }

    /**
         * The phrase is quoted back to a human on a screen that anyone can reach, so it must be
         * the matched fragment and nothing of the notice around it. This notice deliberately
         * carries an address, a phone number and a parish name: none of them may appear in a
         * stored note.
         */
        public function testTheRecordedPhraseIsTheMatchedFragmentAndNotTheSurroundingNotice(): void
        {
            $result = self::parse(
                'St Anne contact the office on office@example.test or 021 555 0100. '
                . 'The retreat Mass is on 32 October 2026 at 18:00.'
            );

            self::assertSame(
                ['unparsed_date_candidate:32 October 2026'],
                self::reasons($result, 'unparsed_date_candidate')
            );

            $recorded = array_merge(
                self::reasons($result, 'unparsed_time_candidate'),
                self::reasons($result, 'unparsed_date_candidate')
            );

            foreach ($recorded as $note) {
                self::assertStringNotContainsString('example.test', $note);
                self::assertStringNotContainsString('021', $note);
                self::assertStringNotContainsString('St Anne', $note);
                self::assertStringNotContainsString('retreat', $note);
                self::assertLessThanOrEqual(40, mb_strlen(explode(':', $note, 2)[1]));
            }
        }

    /**
     * A notice that carries an unreadable date is still a notice about an event, and
     * classification is a separate question from whether the date could be read.
     */
    public function testAnUnreadableDateDoesNotStopTheEventBeingClassified(): void
    {
        $result = self::parse('The retreat Mass is on 32 October 2026 at 18:00.');

        self::assertSame('event', $result['classification'] ?? null);
        self::assertSame('18:00', $result['fields']['event_time'] ?? null);
        self::assertCount(1, self::reasons($result, 'unparsed_date_candidate'));
    }
}