<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Core\Review;

use ADCT\ParishIntake\Core\Review\CandidateFieldSet;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

/**
 * Fixtures here are synthetic. The repository is public and POPIA applies, so no
 * test may carry a real parish, address, contact detail or message.
 */
final class CandidateFieldSetTest extends TestCase
{
    public function testParsesStoredFieldsIntoDayFirstEditInputs(): void
    {
        $inputs = CandidateFieldSet::fromFields([
            'title' =>  'Fictional parish retreat',
            'event_date' => '2026-10-12',
            'event_time' => '09:00',
            'event_end_date' => '2026-10-13',
            'event_end_time' => '16:00',
            'parish_id' => 3,
            'venue_id' => 9,
            'description' => "Line one\nLine two",
            'event_type' => 'Pilgrimage',
            'featured' => 1,
            'status_flag' => 'postponed',
            'exdates' => ['2026-11-09T00:00:00'],
            'rdates' => ['2026-12-07T00:00:00'],
            'contact' => ['name' => 'Fixture Office', 'email' => 'office@example.test', 'phone' => '000 000 0000'],
        ], ['rrule' => 'FREQ=WEEKLY;BYDAY=MO']);

        self::assertSame('Fictional parish retreat', $inputs->title);
        self::assertSame('12/10/2026', $inputs->eventDate);
        self::assertSame('09:00', $inputs->eventTime);
        self::assertSame('13/10/2026', $inputs->eventEndDate);
        self::assertSame('16:00', $inputs->eventEndTime);
        self::assertFalse($inputs->allDay);
        self::assertSame(3, $inputs->parishId);
        self::assertSame(9, $inputs->venueId);
        self::assertSame('Pilgrimage', $inputs->eventType);
        self::assertTrue($inputs->featured);
        self::assertSame('postponed', $inputs->statusFlag);
        self::assertSame(['2026-11-09T00:00:00'], $inputs->exdates);
        self::assertSame('weekly', $inputs->recurrencePreset);
        self::assertSame('MO', $inputs->recurrenceWeekday);
    }

    public function testAllDayDefaultsToTheAbsenceOfAStartTime(): void
    {
        // CandidatePublisher treats an absent `event_time` as an all-day event,
        // so the editor must show the same thing when `all_day` was never stored.
        self::assertTrue(CandidateFieldSet::storedAllDay(['event_date' => '2026-10-12']));
        self::assertFalse(CandidateFieldSet::storedAllDay([
            'event_date' => '2026-10-12', 'event_time' => '09:00',
        ]));
        self::assertTrue(CandidateFieldSet::storedAllDay([
            'event_date' => '2026-10-12', 'all_day' => true, 'event_time' => '09:00',
        ]));
        self::assertFalse(CandidateFieldSet::storedAllDay([
            'event_date' => '2026-10-12', 'all_day' => false,
        ]));
    }

    public function testDayFirstDatesAreStrictAndNeverRollForward(): void
    {
        self::assertSame('12/10/2026', CandidateFieldSet::toEditDate('2026-10-12'));
        self::assertSame('', CandidateFieldSet::toEditDate('2026-02-31'));
        self::assertSame('', CandidateFieldSet::toEditDate('12/10/2026'));
        self::assertSame('2026-10-12', CandidateFieldSet::toStoredDate('12/10/2026'));
        self::assertNull(CandidateFieldSet::toStoredDate('31/02/2026'));
        self::assertNull(CandidateFieldSet::toStoredDate('2026-10-12'));
        self::assertNull(CandidateFieldSet::toStoredDate(''));
        // 29 February only exists in a leap year.
        self::assertSame('2028-02-29', CandidateFieldSet::toStoredDate('29/02/2028'));
        self::assertNull(CandidateFieldSet::toStoredDate('29/02/2026'));
    }

    public function testDateParsingUsesTheInjectedCapeTownTimezone(): void
    {
        $parsed = CandidateFieldSet::parseEditDate('12/10/2026');

        self::assertNotNull($parsed);
        self::assertSame('Africa/Johannesburg', $parsed->getTimezone()->getName());
        self::assertSame(
            (new DateTimeZone('Africa/Johannesburg'))->getName(),
            $parsed->getTimezone()->getName()
        );
    }

    public function testTimeValidationAcceptsOnlyTwentyFourHourClockTimes(): void
    {
        foreach (['00:00', '09:00', '23:59'] as $valid) {
            self::assertTrue(CandidateFieldSet::isValidTime($valid), $valid);
        }

        foreach (['24:00', '9:00', '09:60', '09:00:00', 'half past nine', ''] as $invalid) {
            self::assertFalse(CandidateFieldSet::isValidTime($invalid), $invalid);
        }
    }

    public function testFreeTextContactIsKeptVisibleForTheReviewer(): void
    {
        // The parser stores a single free-text contact line. It must survive
        // into the editor so the reviewer can copy it into the structured fields.
        self::assertSame(
            ['name' => 'Ask the parish office', 'email' => '', 'phone' => ''],
            CandidateFieldSet::contactOf('Ask the parish office')
        );
        self::assertSame(
            ['name' => 'Fixture Office', 'email' => 'office@example.test', 'phone' => '000 000 0000'],
            CandidateFieldSet::contactOf([
                'name' => 'Fixture Office', 'email' => 'office@example.test', 'phone' => '000 000 0000',
            ])
        );
        self::assertSame(['name' => '', 'email' => '', 'phone' => ''], CandidateFieldSet::contactOf(null));
        self::assertSame(['name' => '', 'email' => '', 'phone' => ''], CandidateFieldSet::contactOf(42));
    }

    public function testStructuredContactIgnoresUnknownKeys(): void
    {
        self::assertSame(
            ['name' => 'Fixture Office', 'email' => '', 'phone' => ''],
            CandidateFieldSet::contactOf(['name' => 'Fixture Office', 'bank_details' => 'discarded'])
        );
    }

    public function testRecurrenceRoundTripsThroughThePresetControls(): void
    {
        $expected = [
            ['FREQ=WEEKLY;BYDAY=TH', 'weekly', 'TH'],
            ['FREQ=MONTHLY;BYDAY=2TU', 'monthly_ordinal', 'TU'],
            ['FREQ=MONTHLY;BYMONTHDAY=15', 'monthly_day', 'MO'],
        ];

        foreach ($expected as [$rule, $preset, $weekday]) {
            $inputs = CandidateFieldSet::fromFields([], ['rrule' => $rule]);

            self::assertSame($preset, $inputs->recurrencePreset, $rule);
            self::assertSame($weekday, $inputs->recurrenceWeekday, $rule);
        }
    }

    public function testAnUnsupportedRuleFallsBackToCustomWithoutLosingTheText(): void
    {
        $inputs = CandidateFieldSet::fromFields([], ['rrule' => 'FREQ=YEARLY;BYMONTH=3;BYMONTHDAY=19']);

        self::assertSame('custom', $inputs->recurrencePreset);
        self::assertSame('FREQ=YEARLY;BYMONTH=3;BYMONTHDAY=19', $inputs->recurrenceCustomRule);
    }

    public function testDecodeFieldsSurvivesUnusableJson(): void
    {
        self::assertSame([], CandidateFieldSet::decodeFields(null));
        self::assertSame([], CandidateFieldSet::decodeFields(''));
        self::assertSame([], CandidateFieldSet::decodeFields('   '));
        self::assertSame([], CandidateFieldSet::decodeFields('{not json'));
        self::assertSame([], CandidateFieldSet::decodeFields('"a string"'));
        self::assertSame(['title' => 'Fixture'], CandidateFieldSet::decodeFields('{"title":"Fixture"}'));
        self::assertSame(['title' => 'Fixture'], CandidateFieldSet::decodeFields(['title' => 'Fixture']));
    }

    public function testStatusFlagFallsBackToScheduled(): void
    {
        self::assertSame('cancelled', CandidateFieldSet::statusFlagOf(['status_flag' => 'cancelled']));
        self::assertSame('scheduled', CandidateFieldSet::statusFlagOf(['status_flag' => 'deleted']));
        self::assertSame('scheduled', CandidateFieldSet::statusFlagOf([]));
    }

    public function testStringListSplitsTextAndDropsBlanksAndDuplicates(): void
    {
        self::assertSame(['a', 'b'], CandidateFieldSet::stringList("a\n\nb\na"));
        self::assertSame(['a', 'b'], CandidateFieldSet::stringList('a, b'));
        self::assertSame(['a', 'b'], CandidateFieldSet::stringList(['a', ' b ', '', null, 'a']));
        self::assertSame([], CandidateFieldSet::stringList(null));
    }

    public function testIntOrNullAcceptsOnlyPositiveIdentifiers(): void
    {
        self::assertSame(3, CandidateFieldSet::intOrNull(3));
        self::assertSame(3, CandidateFieldSet::intOrNull('3'));
        self::assertNull(CandidateFieldSet::intOrNull('0'));
        self::assertNull(CandidateFieldSet::intOrNull(-1));
        self::assertNull(CandidateFieldSet::intOrNull('3; DROP TABLE wp_posts'));
        self::assertNull(CandidateFieldSet::intOrNull(''));
        self::assertNull(CandidateFieldSet::intOrNull(null));
    }

    public function testRruleOfIgnoresBlankAndNonStringValues(): void
    {
        self::assertSame('FREQ=DAILY', CandidateFieldSet::rruleOf(['rrule' => '  FREQ=DAILY  ']));
        self::assertNull(CandidateFieldSet::rruleOf(['rrule' => '   ']));
        self::assertNull(CandidateFieldSet::rruleOf(['rrule' => ['FREQ=DAILY']]));
        self::assertNull(CandidateFieldSet::rruleOf([]));
    }

    public function testToInputsRoundTripsTheEditableControls(): void
    {
        $inputs = CandidateFieldSet::fromFields([
            'title' => 'Fixture meeting',
            'event_date' => '2026-10-12',
            'event_time' => '09:00',
            'parish_id' => 3,
            'status_flag' => 'cancelled',
        ]);

        self::assertSame([
            'title' => 'Fixture meeting',
            'event_date' => '12/10/2026',
            'event_time' => '09:00',
            'event_end_date' => '',
            'event_end_time' => '',
            'all_day' => false,
            'parish_id' => 3,
            'venue_id' => null,
            'description' => '',
            'event_type' => '',
            'featured' => false,
            'status_flag' => 'cancelled',
            'exdates' => [],
            'rdates' => [],
            'contact' => ['name' => '', 'email' => '', 'phone' => ''],
            'recurrence_preset' => 'none',
            'recurrence_weekday' => 'MO',
            'recurrence_ordinal' => '1',
            'recurrence_month_day' => '1',
            'recurrence_custom' => '',
        ], $inputs->toInputs());
    }

    public function testAnEmptyRowStillProducesAUsableForm(): void
    {
        $inputs = CandidateFieldSet::fromFields([], []);

        self::assertSame('', $inputs->title);
        self::assertTrue($inputs->allDay);
        self::assertSame('scheduled', $inputs->statusFlag);
        self::assertSame('none', $inputs->recurrencePreset);
    }
}
