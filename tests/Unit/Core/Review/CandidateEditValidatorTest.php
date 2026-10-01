<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Core\Review;

use ADCT\ParishIntake\Core\Review\CandidateEditValidator;
use ADCT\ParishIntake\Core\Review\CandidateFieldSet;
use PHPUnit\Framework\TestCase;

/**
 * All fixtures are synthetic. The repository is public and POPIA applies, so no
 * test may carry a real parish, address, contact detail or message.
 */
final class CandidateEditValidatorTest extends TestCase
{
    /**
     * A stored candidate as the parser leaves it: editable values plus the
     * provenance, geocoding, screening and AI bookkeeping a reviewer must not
     * disturb.
     *
     * @return array<string, mixed>
     */
    private function storedFields(): array
    {
        return [
            'title' => 'Fictional parish retreat',
            'event_date' => '2026-10-12',
            'event_time' => '09:00',
            'event_end_time' => '16:00',
            'parish_id' => 3,
            'venue_id' => 9,
            'description' => 'A fixture description.',
            'event_type' => 'Pilgrimage',
            'featured' => false,
            'status_flag' => 'scheduled',
            'exdates' => [],
            'rdates' => [],
            'source_type' => 'email',
            'source_identifier' => 'a1b2c3d4@example.test',
            'sender_email' => 'parish@example.test',
            'sender_name' => 'Fixture Parish Office',
            'venue' => 'Fixture Hall',
            'venue_text' => 'Fixture Hall',
            'venue_latitude' => -33.9249,
            'venue_longitude' => 18.4241,
            'venue_address' => '1 Fixture Street',
            'venue_suburb' => 'Fixtureville',
            'venue_match' => 'existing',
            'parish_name' => 'Fixture Parish',
            'parish_match' => 'existing',
            'event_type_source' => 'keyword',
            'event_type_confidence' => 0.8,
            'attachment_names' => ['poster.pdf'],
            'replacement_schedule_unresolved' => false,
            'source_snippet' => 'Retreat on 12 October',
            'reprocess_needed' => false,
            'ai_fields_filled' => false,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function form(array $overrides = []): array
    {
        return $overrides + [
            'title' => 'Fictional parish retreat',
            'event_date' => '12/10/2026',
            'event_time' => '09:00',
            'event_end_time' => '16:00',
            'all_day' => '0',
            'parish_id' => '3',
            'venue_id' => '9',
            'description' => 'A fixture description.',
            'event_type' => 'Pilgrimage',
            'featured' => '0',
            'status_flag' => 'scheduled',
            'exdates' => '',
            'rdates' => '',
            'contact_name' => '',
            'contact_email' => '',
            'contact_phone' => '',
            'recurrence_preset' => 'none',
        ];
    }

    private function validator(): CandidateEditValidator
    {
        return new CandidateEditValidator();
    }

    public function testAnUnchangedFormStoresExactlyWhatWasAlreadyStored(): void
    {
        $result = $this->validator()->validate($this->storedFields(), [], $this->form());

        self::assertTrue($result->isValid(), json_encode($result->errors));
        // Nothing changed, so the audit entry stays empty and the row is not
        // rewritten for no reason.
        self::assertSame([], $result->changedFields);

            // The editor rewrites only the keys it owns, so an untouched save is
            // value-identical rather than gaining keys it never had. `all_day` and
            // `contact` are always materialised from the form, which is why they
            // appear here and not in the stored row.
            foreach ($this->storedFields() as $key => $value) {
                self::assertSame($value, $result->values[$key] ?? null, $key . ' was not preserved');
            }

            self::assertSame([], $result->recurrence);
        }

    public function testEveryParserOwnedKeySurvivesAnEdit(): void
    {
            // The form posts back whatever the preset drop-down showed for the
            // stored rule, which is how the detail screen renders it.
            $result = $this->validator()->validate($this->storedFields(), [
                'frequency' => 'weekly', 'interval' => 1, 'text' => 'Every Monday', 'rrule' => 'FREQ=WEEKLY;BYDAY=MO',
            ], $this->form([
                'title' => 'Fictional parish retreat (revised)',
                'recurrence_preset' => 'weekly',
            ]));

        self::assertTrue($result->isValid(), json_encode($result->errors));
                // Only the title moved; the rule the parser recorded is untouched, so a
                // save that leaves recurrence alone is not reported as an edit to it.
                self::assertSame(['title'], $result->changedFields);
                self::assertSame('FREQ=WEEKLY;BYDAY=MO', $result->recurrence['rrule']);

        foreach ($this->storedFields() as $key => $value) {
            if ($key === 'title') {
                continue;
            }

            self::assertSame($value, $result->values[$key] ?? null, $key . ' was lost by the edit');
        }

        self::assertSame('Fictional parish retreat (revised)', $result->values['title']);
    }

    public function testDayFirstDatesAreStoredAsIsoAndRejectedWhenImpossible(): void
    {
        $result = $this->validator()->validate($this->storedFields(), [], $this->form([
            'event_date' => '31/02/2026',
        ]));

        self::assertTrue($result->hasErrors());
        self::assertNotNull($result->errorFor('event_date'));
        self::assertSame([], $result->values);

        // 12/10/2026 is 12 October, not 10 December.
        $accepted = $this->validator()->validate($this->storedFields(), [], $this->form());
        self::assertTrue($accepted->isValid(), json_encode($accepted->errors));
        self::assertSame('2026-10-12', $accepted->values['event_date']);
    }

    public function testAnEndBeforeTheStartIsRejectedAgainstTheEndDate(): void
    {
        $result = $this->validator()->validate($this->storedFields(), [], $this->form([
            'event_date' => '12/10/2026',
            'event_time' => '09:00',
            'event_end_date' => '11/10/2026',
            'event_end_time' => '16:00',
        ]));

        self::assertTrue($result->hasErrors());
        self::assertNotNull($result->errorFor('event_end_date'));
    }

    public function testAnEndTimeWithoutAnEndDateEndsOnTheStartDate(): void
    {
        // CandidatePublisher falls back to the start date for a missing end
        // date, so an end time on its own is a same-day range, not an error.
        $result = $this->validator()->validate($this->storedFields(), [], $this->form([
            'event_end_date' => '',
            'event_end_time' => '16:00',
        ]));

        self::assertTrue($result->isValid(), json_encode($result->errors));
        self::assertArrayNotHasKey('event_end_date', $result->values);
        self::assertSame('16:00', $result->values['event_end_time']);
    }

    public function testAnAllDayEditDropsTheStartAndEndTimes(): void
    {
        $result = $this->validator()->validate($this->storedFields(), [], $this->form([
            'all_day' => '1',
            'event_time' => '',
            'event_end_time' => '',
        ]));

        self::assertTrue($result->isValid(), json_encode($result->errors));
        self::assertTrue($result->values['all_day']);
        self::assertArrayNotHasKey('event_time', $result->values);
        self::assertArrayNotHasKey('event_end_time', $result->values);
        // The dropped start time is the meaningful signal; `all_day` is written
        // on every save and would otherwise be reported as a change every time.
        self::assertSame(['event_time', 'event_end_time'], $result->changedFields);
    }

    public function testExceptionDatesAcceptADayFirstDateAndStoreALocalDateTime(): void
    {
        $result = $this->validator()->validate($this->storedFields(), [], $this->form([
            'exdates' => "09/11/2026\n16/11/2026 10:00",
        ]));

        self::assertTrue($result->isValid(), json_encode($result->errors));
        self::assertSame(['2026-11-09T00:00', '2026-11-16T10:00'], $result->values['exdates']);
    }

    public function testAnUnparseableExceptionDateIsRejected(): void
    {
        $result = $this->validator()->validate($this->storedFields(), [], $this->form([
            'exdates' => 'next Friday',
        ]));

        self::assertTrue($result->hasErrors());
        self::assertNotNull($result->errorFor('exdates'));
    }

    public function testExceptionDatesOnAnAllDayEventAreNormalisedToMidnight(): void
    {
        $result = $this->validator()->validate($this->storedFields(), [], $this->form([
            'all_day' => '1',
            'event_time' => '',
            'event_end_time' => '',
            'exdates' => '09/11/2026 10:00',
        ]));

        self::assertTrue($result->isValid(), json_encode($result->errors));
        self::assertSame(['2026-11-09T00:00'], $result->values['exdates']);
    }

    public function testARecurrencePresetBecomesARuleAndKeepsTheParserMetadata(): void
    {
        $result = $this->validator()->validate($this->storedFields(), [
            'frequency' => 'monthly', 'interval' => 1, 'text' => 'Every month', 'rrule' => 'FREQ=MONTHLY;BYMONTHDAY=1',
        ], $this->form([
            'recurrence_preset' => 'weekly',
            'recurrence_weekday' => 'TH',
        ]));

        self::assertTrue($result->isValid(), json_encode($result->errors));
        self::assertSame('FREQ=WEEKLY;BYDAY=TH', $result->recurrence['rrule']);
        self::assertSame('weekly', $result->recurrence['frequency']);
        self::assertSame(1, $result->recurrence['interval']);
        self::assertNotSame('', $result->recurrence['text']);
        self::assertSame(['recurrence'], $result->changedFields);
    }

    public function testClearingTheRecurrencePresetRemovesTheRule(): void
    {
        $result = $this->validator()->validate($this->storedFields(), [
            'frequency' => 'weekly', 'interval' => 1, 'text' => 'Every Monday', 'rrule' => 'FREQ=WEEKLY;BYDAY=MO',
        ], $this->form(['recurrence_preset' => 'none']));

        self::assertTrue($result->isValid(), json_encode($result->errors));
        self::assertSame([], $result->recurrence);
        self::assertSame(['recurrence'], $result->changedFields);
    }

    public function testAnUnsupportedRecurrencePresetIsReportedAgainstTheControl(): void
    {
        $result = $this->validator()->validate($this->storedFields(), [], $this->form([
            'recurrence_preset' => 'fortnightly',
        ]));

        self::assertTrue($result->hasErrors());
        self::assertNotNull($result->errorFor('recurrence_preset'));
    }

    public function testAnAllDayUntilIsNarrowedSoTheRuleStaysPublishable(): void
    {
        // RRuleValidator rejects a datetime UNTIL on an all-day series.
        $result = $this->validator()->validate($this->storedFields(), [
            'frequency' => 'daily', 'interval' => 1, 'until' => '20261031T235959Z', 'rrule' => 'FREQ=DAILY',
        ], $this->form([
            'all_day' => '1',
            'event_time' => '',
            'event_end_time' => '',
            'recurrence_preset' => 'custom',
            'recurrence_custom' => 'FREQ=DAILY;UNTIL=20261031T235959Z',
        ]));

        self::assertTrue($result->isValid(), json_encode($result->errors));
        self::assertSame('FREQ=DAILY;UNTIL=20261031', $result->recurrence['rrule']);
        self::assertSame('20261031', $result->recurrence['until']);
    }

    public function testATimedUntilIsWidenedSoTheRuleStaysPublishable(): void
    {
        // RRuleValidator rejects a date-only UNTIL on a timed series.
        $result = $this->validator()->validate($this->storedFields(), [
            'frequency' => 'daily', 'interval' => 1, 'until' => '20261031', 'rrule' => 'FREQ=DAILY',
        ], $this->form([
            'recurrence_preset' => 'custom',
            'recurrence_custom' => 'FREQ=DAILY;UNTIL=20261031',
        ]));

        self::assertTrue($result->isValid(), json_encode($result->errors));
        self::assertSame('FREQ=DAILY;UNTIL=20261031T235959Z', $result->recurrence['rrule']);
        self::assertSame('20261031T235959Z', $result->recurrence['until']);
    }

    public function testARecurrenceIntervalIsRefreshedFromTheRule(): void
    {
        $result = $this->validator()->validate($this->storedFields(), [
            'frequency' => 'weekly', 'interval' => 1, 'text' => 'Every Monday', 'rrule' => 'FREQ=WEEKLY;BYDAY=MO',
        ], $this->form([
            'recurrence_preset' => 'custom',
            'recurrence_custom' => 'FREQ=WEEKLY;INTERVAL=3;BYDAY=MO',
        ]));

        self::assertTrue($result->isValid(), json_encode($result->errors));
        self::assertSame(3, $result->recurrence['interval']);
    }

    public function testAnUnsupportedRuleInACustomFieldIsReportedAgainstTheCustomBox(): void
    {
        $result = $this->validator()->validate($this->storedFields(), [], $this->form([
            'recurrence_preset' => 'custom',
            'recurrence_custom' => 'FREQ=HOURLY',
        ]));

        self::assertTrue($result->hasErrors());
        self::assertNotNull($result->errorFor('recurrence_custom'));
    }

    public function testFreeTextContactIsReplacedByTheStructuredValues(): void
    {
        // A string contact is silently discarded by the publisher, so an edit
        // that leaves the free-text line in place would drop it.
        $stored = $this->storedFields();
        $stored['contact'] = 'Ask the parish office';

        $result = $this->validator()->validate($stored, [], $this->form([
            'contact_name' => 'Fixture Office',
            'contact_email' => 'office@example.test',
            'contact_phone' => '000 000 0000',
        ]));

        self::assertTrue($result->isValid(), json_encode($result->errors));
        self::assertSame([
            'name' => 'Fixture Office', 'email' => 'office@example.test', 'phone' => '000 000 0000',
        ], $result->values['contact']);
        self::assertSame(['contact'], $result->changedFields);
    }

    public function testAnInvalidContactEmailIsRejected(): void
    {
        $result = $this->validator()->validate($this->storedFields(), [], $this->form([
            'contact_email' => 'not-an-address',
        ]));

        self::assertTrue($result->hasErrors());
        self::assertNotNull($result->errorFor('contact_email'));
    }

    public function testAVenueWithoutAParishIsRejected(): void
    {
        $result = $this->validator()->validate($this->storedFields(), [], $this->form([
            'parish_id' => '',
            'venue_id' => '9',
        ]));

        self::assertTrue($result->hasErrors());
        self::assertNotNull($result->errorFor('parish_id'));
    }

    public function testANonNumericIdentifierIsRejected(): void
    {
        $result = $this->validator()->validate($this->storedFields(), [], $this->form([
            'parish_id' => '3 OR 1=1',
        ]));

        self::assertTrue($result->hasErrors());
        self::assertNotNull($result->errorFor('parish_id'));
    }

    public function testAnOverlongTitleIsRejected(): void
    {
        $result = $this->validator()->validate($this->storedFields(), [], $this->form([
            'title' => str_repeat('a', 256),
        ]));

        self::assertTrue($result->hasErrors());
        self::assertNotNull($result->errorFor('title'));
    }

    public function testAnUnknownStatusFlagIsRejected(): void
    {
        $result = $this->validator()->validate($this->storedFields(), [], $this->form([
            'status_flag' => 'deleted',
        ]));

        self::assertTrue($result->hasErrors());
        self::assertNotNull($result->errorFor('status_flag'));
    }

    public function testTheStoredStatusFlagSurvivesAFormThatOmitsTheControl(): void
    {
        $stored = $this->storedFields();
        $stored['status_flag'] = 'postponed';

        $form = $this->form();
        unset($form['status_flag']);

        $result = $this->validator()->validate($stored, [], $form);

        self::assertTrue($result->isValid(), json_encode($result->errors));
        self::assertSame('postponed', $result->values['status_flag']);
    }

    public function testARejectedEditKeepsTheReviewersOwnDayFirstValues(): void
    {
        $result = $this->validator()->validate($this->storedFields(), [], $this->form([
            'event_date' => '31/02/2026',
            'title' => 'A corrected title',
            'recurrence_preset' => 'weekly',
            'recurrence_weekday' => 'WE',
        ]));

        self::assertTrue($result->hasErrors());
        self::assertNotNull($result->inputs);
        self::assertSame('A corrected title', $result->inputs->title);
        // Still day-first, so the re-rendered form does not snap back to ISO.
        self::assertSame('31/02/2026', $result->inputs->eventDate);
        self::assertSame('weekly', $result->inputs->recurrencePreset);
        self::assertSame('WE', $result->inputs->recurrenceWeekday);
        self::assertSame('A corrected title', $result->submittedInputs()['title']);
    }

    public function testInputsFromFallsBackToTheStoredValuesWhenNothingWasPosted(): void
    {
        $inputs = $this->validator()->inputsFrom([], $this->storedFields(), []);

        self::assertSame('Fictional parish retreat', $inputs->title);
        self::assertSame('12/10/2026', $inputs->eventDate);
    }

    public function testInputsFromMirrorsAPostedForm(): void
    {
        $inputs = $this->validator()->inputsFrom($this->form([
            'title' => 'Typed by the reviewer',
            'event_date' => '05/03/2027',
            'all_day' => '1',
            'featured' => '1',
        ]), $this->storedFields(), []);

        self::assertSame('Typed by the reviewer', $inputs->title);
        self::assertSame('05/03/2027', $inputs->eventDate);
        self::assertTrue($inputs->allDay);
        self::assertTrue($inputs->featured);
    }

    public function testACandidateWithNoStoredFieldsIsStillEditable(): void
    {
        $result = $this->validator()->validate([], [], $this->form([
            'event_end_time' => '',
            'parish_id' => '',
            'venue_id' => '',
        ]));

        self::assertTrue($result->isValid(), json_encode($result->errors));
        self::assertSame('Fictional parish retreat', $result->values['title']);
        self::assertSame('2026-10-12', $result->values['event_date']);
        self::assertSame('09:00', $result->values['event_time']);
        self::assertArrayNotHasKey('parish_id', $result->values);
        self::assertArrayNotHasKey('venue_id', $result->values);
    }

    public function testAStoredRuleWithNoPresetSurvivesAnUnrelatedEdit(): void
    {
        // A rule the drop-down has no preset for comes back as preset `custom`
        // with the raw text, so a title-only edit must not disturb it. If the
        // rule were rebuilt from the controls instead, the BYMONTH detail would
        // be lost and the audit would report a recurrence change nobody made.
        $stored = [
            'frequency' => 'monthly',
            'interval' => 1,
            'text' => 'Every month on the first Monday',
            'rrule' => 'FREQ=MONTHLY;BYDAY=1MO',
        ];

        $result = $this->validator()->validate($this->storedFields(), $stored, $this->form([
            'title' => 'Fictional parish retreat (revised)',
            'recurrence_preset' => 'custom',
            'recurrence_custom' => 'FREQ=MONTHLY;BYDAY=1MO',
        ]));

        self::assertTrue($result->isValid(), json_encode($result->errors));
        self::assertSame(['title'], $result->changedFields);
        self::assertSame('FREQ=MONTHLY;BYDAY=1MO', $result->recurrence['rrule']);
        self::assertSame('monthly', $result->recurrence['frequency']);
    }

    public function testAReviewerCanClearARecurrenceRule(): void
    {
        $result = $this->validator()->validate($this->storedFields(), [
            'rrule' => 'FREQ=WEEKLY;BYDAY=MO',
        ], $this->form(['recurrence_preset' => 'none']));

        self::assertTrue($result->isValid(), json_encode($result->errors));
        self::assertSame([], $result->recurrence);
        self::assertSame(['recurrence'], $result->changedFields);
    }

    public function testTheEditorOnlyEverWritesTheAllowedKeys(): void
        {
            $result = $this->validator()->validate($this->storedFields(), [
                'rrule' => 'FREQ=WEEKLY;BYDAY=MO',
            ], $this->form([
                'title' => 'A revised fixture title',
                'parish_id' => '4',
                'venue_id' => '',
                'status_flag' => 'cancelled',
                'featured' => '1',
                'recurrence_preset' => 'weekly',
            ]));

            self::assertTrue($result->isValid(), json_encode($result->errors));

            // The stored row is the write target, so it legitimately still carries
            // every parser-owned key. What matters is which keys the editor changed,
            // and that is the diff it reports.
            $edited = $result->changedFields;
            sort($edited);
            self::assertSame(
                ['featured', 'parish_id', 'status_flag', 'title', 'venue_id'],
                $edited
            );

            // Parser-owned keys pass through untouched.
            self::assertSame(
                $this->storedFields()['source_snippet'],
                $result->values['source_snippet']
            );
            self::assertSame(
                $this->storedFields()['sender_email'],
                $result->values['sender_email']
            );

            // A crafted POST body cannot smuggle a key past the whitelist: keys the
            // form does not own are never read from the request, so they neither
            // reach the result nor show up as changes.
            $smuggled = $this->validator()->validate($this->storedFields(), [], $this->form([
                'source_type' => 'tampered',
                'sender_email' => 'attacker@example.test',
                'recognition_score' => '1',
                'source_snippet' => 'Replaced provenance',
            ]));

            self::assertTrue($smuggled->isValid(), json_encode($smuggled->errors));
            self::assertSame([], $smuggled->changedFields);
            self::assertSame('email', $smuggled->values['source_type']);
            self::assertSame('parish@example.test', $smuggled->values['sender_email']);
            self::assertSame('Retreat on 12 October', $smuggled->values['source_snippet']);
        }
    }
