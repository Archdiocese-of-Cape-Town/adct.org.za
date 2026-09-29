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
 * A year that appears in the document must never be discarded.
 *
 * Before #133 a masthead of "September 2026" -- a month and a year, no day range --
 * did not match findBulletinDateRange(), which only recognised a day-based range like
 * "28 September to 4 October 2026". The reference date therefore fell back to the
 * received date, and "on 1 September" rolled forward a whole year to 2027-09-01.
 * A real event in the source, eleven months late, at 0.75 confidence.
 */
final class BulletinMastheadYearTest extends TestCase
{
    private static function parse(
        string $body,
        string $subject = '',
        string $referenceDate = '2026-09-28 08:00:00'
    ): array {
        $message = new Message(
            'email',
            'masthead-test',
            'events@example.test',
            'Fictional Office',
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

    private static function eventDate(string $body, string $subject = '', string $referenceDate = '2026-09-28 08:00:00'): ?string
    {
        return self::parse($body, $subject, $referenceDate)['fields']['event_date'] ?? null;
    }

    /**
     * The acceptance case: a September 2026 document saying "on 1 September" must not
     * become September 2027.
     *
     * @return array<string, array{string, string}>
     */
    public static function monthOnlyMastheadProvider(): array
    {
        return [
            '1 September' => ['Parish Mass on 1 September.', '2026-09-01'],
            '4 October' => ['Parish Mass on 4 October.', '2026-10-04'],
            '21 December' => ['Parish Mass on 21 December.', '2026-12-21'],
        ];
    }

    #[DataProvider('monthOnlyMastheadProvider')]
    public function testMonthOnlyMastheadDoesNotDiscardItsYear(string $body, string $expected): void
    {
        self::assertSame($expected, self::eventDate($body, 'Parish Newsletter | September 2026'));
    }

    /**
     * #128 taught the rule stage that a year stated in the document is authoritative:
     * it must not be rolled forward just because the day has already gone by. #132 then
     * added "end of <Month>" on top of that, and rebasing the two silently replaced
     * #128's two-branch check with a single "$date >= referenceDate" test, which
     * re-rolled exactly the case #128 exists for.
     *
     * These go through BulletinBlockSplitter's bare "<Month> <year>" context line
     * rather than a masthead. A masthead also sets the reference date to that same
     * month, so the stated year and the reference year agree and the regression is
     * invisible -- verified, not assumed. Only the context line pins the year while
     * the reference date stays at the received date, which is what makes the two
     * branches disagree.
     *
     * @return array<string, array{string}>
     */
    public static function statedYearIsNotRolledForwardProvider(): array
    {
        return [
            'past year, "end of" wording' => ["PARISH BULLETIN\n\nSeptember 2025\n\nReturns due end of September.", '2025-09-30'],
            'future year, "end of" wording' => ["PARISH BULLETIN\n\nJanuary 2027\n\nReturns due end of January.", '2027-01-31'],
        ];
    }

    #[DataProvider('statedYearIsNotRolledForwardProvider')]
    public function testStatedYearIsNotRolledForwardToTheNextYear(string $body, string $expected): void
    {
        self::assertSame($expected, self::eventDate($body, '', '2026-10-15 08:00:00'));
    }

    /**
     * A month and year in the body works too, not just in the subject.
     *
     * @return array<string, array{string, string}>
     */
    public static function bodyMastheadProvider(): array
    {
        return [
            'Month Year in the first line' => ["Parish Newsletter\nSeptember 2026\n\nMass on 1 September.", '2026-09-01'],
            'Month, Year in the first line' => ["Parish Newsletter\nSeptember, 2026\n\nMass on 1 September.", '2026-09-01'],
            'Month Year in parentheses' => ["Parish Newsletter (September 2026)\n\nMass on 1 September.", '2026-09-01'],
            'Parish Newsletter for September 2026' => ["Parish Newsletter for September 2026\n\nMass on 1 September.", '2026-09-01'],
        ];
    }

    #[DataProvider('bodyMastheadProvider')]
    public function testMonthAndYearInTheBodyIsUsedAsTheReference(string $body, string $expected): void
    {
        self::assertSame($expected, self::eventDate($body));
    }

    /**
     * A single DD Month YYYY masthead should also be recognised.
     */
    public function testSingleDateMastheadIsUsedAsTheReference(): void
    {
        $body = "Parish Newsletter\n1 September 2026\n\nRetreat gathering on 4 September.";

        self::assertSame('2026-09-04', self::eventDate($body));
    }

    /**
     * The existing day-range masthead must keep working. This is the form
     * findBulletinDateRange() already understood.
     */
    public function testDayRangeMastheadStillSetsTheReference(): void
    {
        $body = "Parish Newsletter\n28 September to 4 October 2026\n\nMass on 1 September.";

        self::assertSame('2026-09-01', self::eventDate($body));
    }

    /**
     * Year rolling is not removed, only constrained by evidence. An email received in
     * January saying "next 5 October" with no year anywhere in the document must still
     * roll forward.
     */
    public function testYearStillRollsForwardWhenTheDocumentSaysNothing(): void
    {
        $body = 'The Harvest Lunch is on 5 October.';

        self::assertSame('2026-10-05', self::eventDate($body, 'Parish notice', '2026-01-15 08:00:00'));
    }

    /**
     * A year stated in the document constrains a date in a *later* month. October is
     * after the September masthead but still in the same year.
     */
    public function testDateInALaterMonthStaysInTheMastheadYear(): void
    {
        $body = "Parish Newsletter\nSeptember 2026\n\nParish Mass on 4 October.";

        self::assertSame('2026-10-04', self::eventDate($body));
    }

    /**
     * ...and a date in an *earlier* month, where naive year application would wrongly
     * give 2025. "December 2025 retreat, report due 5 January" is the shape this covers.
     */
    public function testDateInAnEarlierMonthUsesTheFollowingYear(): void
    {
        $body = "Parish Newsletter\nDecember 2025\n\nParish Mass on 5 January.";

        self::assertSame('2026-01-05', self::eventDate($body, '', '2025-12-20 08:00:00'));
    }

    /**
     * The issue asks for a note when a year was inferred from a masthead, so a reviewer
     * can see the date did not come from the text itself.
     */
    public function testANoteIsRecordedWhenTheYearComesFromAMasthead(): void
    {
        $result = self::parse("Parish Newsletter\nSeptember 2026\n\nMass on 1 September.");

        $notes = $result['notes'] ?? [];
        $matched = false;

        foreach ($notes as $note) {
            if (stripos((string) $note, '2026') !== false) {
                $matched = true;
            }
        }

        self::assertTrue(
            $matched,
            'Expected a note mentioning the inferred year, got: ' . json_encode($notes)
        );
    }

    /**
     * No masthead means no inferred year, so no note about one.
     */
    public function testNoNoteIsRecordedWhenThereIsNoMasthead(): void
    {
        $result = self::parse('The Harvest Lunch is on 5 October.', '', '2026-01-15 08:00:00');

        foreach ($result['notes'] ?? [] as $note) {
            self::assertStringNotContainsStringIgnoringCase('masthead', (string) $note);
        }
    }

    /**
     * Regression guard: an explicit year in the date itself always wins and is
     * untouched by any masthead.
     */
    public function testExplicitYearInTheDateIsNotOverridden(): void
    {
        $body = "Parish Newsletter\nSeptember 2026\n\nParish Mass on 4 October 2027.";

        self::assertSame('2027-10-04', self::eventDate($body));
    }

    /**
     * A range whose end month is earlier than its start month wraps the new year. The
     * single year written applies to the end, so the start is the year before it.
     */
    public function testARangeWrappingTheNewYearIsUnderstood(): void
    {
        $body = "Parish Newsletter\n28 December to 4 January 2027\n\nParish Mass on 2 January.";

        self::assertSame('2027-01-02', self::eventDate($body, '', '2026-12-20 08:00:00'));
    }

    /**
     * The word "bulletin" is required, so a bare month and year in ordinary prose is not
     * a masthead and must not override the received date.
     */
    public function testABareMonthAndYearWithoutTheWordBulletinIsNotAMasthead(): void
    {
        $body = "Parish News\nSeptember 2026\n\nParish Mass on 1 September.";

        self::assertSame('2026-09-01', self::eventDate($body, '', '2026-01-15 08:00:00'));
    }

    /**
     * The extraction stage is a per-block component but the masthead is a document-level
     * fact. A two-event bulletin must give both events the same reference date, or the
     * same stated "on 1 September" resolves differently in each block.
     */
    public function testEveryEventInABulletinSharesTheOneReferenceDate(): void
    {
        $body = "Parish Newsletter\nSeptember 2026\n\nParish Mass on 1 September.\n\nParish Mass on 4 October.";
        $message = new Message(
            'email',
            'masthead-test',
            'events@example.test',
            'Fictional Office',
            '',
            $body,
            [],
            new DateTimeImmutable('2026-09-28 08:00:00', new DateTimeZone('Africa/Johannesburg'))
        );

        $candidates = (new PipelineFactory())
            ->create()
            ->parseAll($message)
            ->toArray()['candidates'] ?? [];

        $dates = [];

        foreach ($candidates as $candidate) {
            $dates[] = $candidate['fields']['event_date'] ?? null;
        }

        self::assertContains('2026-09-01', $dates, 'The 1 September event lost the masthead year: ' . json_encode($dates));
        self::assertContains('2026-10-04', $dates, 'The 4 October event lost the masthead year: ' . json_encode($dates));
    }
}
