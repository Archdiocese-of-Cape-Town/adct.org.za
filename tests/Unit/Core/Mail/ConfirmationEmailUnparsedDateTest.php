<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Core\Mail;

use ADCT\ParishIntake\Core\Directory\SenderTrust;
use ADCT\ParishIntake\Core\Mail\ConfirmationEmailActionLinks;
use ADCT\ParishIntake\Core\Mail\ConfirmationEmailBatch;
use ADCT\ParishIntake\Core\Mail\ConfirmationEmailCandidate;
use ADCT\ParishIntake\Core\Mail\ConfirmationEmailContent;
use ADCT\ParishIntake\Core\Mail\ConfirmationEmailRenderer;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

/**
 * Issue #167: the submitter is the only person who can fix the source text, so the confirmation
 * preview is where an unreadable date or time has to be surfaced. The parser's machine-readable
 * reason is replaced with a sentence addressed to the person who wrote the notice.
 */
final class ConfirmationEmailUnparsedDateTest extends TestCase
{
    public function testAnUnreadableDateIsMarkedPleaseCheckAndSpelledOutInTheNotes(): void
    {
            $content = $this->render(
            ['title' => 'Parish retreat', 'event_date' => null, 'event_time' => null],
                ['unparsed_date_candidate:32 October 2026']
            );

        self::assertStringContainsString('Date: Not identified (please check)', $content->text);
        self::assertStringContainsString(
            'The notice gives "32 October 2026" as the date. It could not be read as a real date',
            $content->text
        );
        // The token itself is an internal detail and must never reach the submitter.
        self::assertStringNotContainsString('unparsed_date_candidate', $content->text);
        self::assertStringNotContainsString('unparsed_date_candidate', $content->html);
    }

    public function testAnUnreadableTimeIsMarkedPleaseCheckAndSpelledOutInTheNotes(): void
    {
            $content = $this->render(
            ['title' => 'Parish retreat', 'event_date' => '2026-10-12', 'event_time' => null],
                ['unparsed_time_candidate:10.00-12:00am']
            );

        self::assertStringContainsString('Time: Not identified (please check)', $content->text);
        self::assertStringContainsString(
            'The notice gives "10.00-12:00am" as the time',
            $content->text
        );
        self::assertStringNotContainsString('unparsed_time_candidate', $content->text);
    }

    public function testTheHtmlPreviewCarriesTheSameSentenceAsThePlainText(): void
    {
            $content = $this->render(
            ['title' => 'Parish retreat', 'event_date' => null, 'event_time' => null],
                ['unparsed_date_candidate:32 October 2026']
            );

        self::assertStringContainsString('could not be read as a real date', $content->html);
            // And the Date row is marked uncertain in the HTML too, so the submitter sees which value
            // is missing without having to read the sentence. The marker's own label says so, and is
            // read by a screen reader as well as shown.
            self::assertStringContainsString('aria-label="please check"', $content->html);
            self::assertStringNotContainsString('unparsed_date_candidate', $content->html);
        }

        /**
         * The unreadable date is reported on the date row. A readable time on the same notice is
         * still perfectly readable, and marking it would tell the submitter to re-check a value that
         * was never in doubt.
         */
        public function testAnUnreadableDateDoesNotMarkAReadableTimeAsUncertain(): void
        {
            $content = $this->render(
                ['title' => 'Parish retreat', 'event_date' => null, 'event_time' => '18:30'],
                ['unparsed_date_candidate:32 October 2026']
            );

            self::assertStringContainsString('Time: 6:30 pm', $content->text);
            self::assertStringNotContainsString('Time: 6:30 pm (please check)', $content->text);
            self::assertStringContainsString('Date: Not identified (please check)', $content->text);
        }

        /**
         * The distinction the issue insists on: a notice that says no time at all is not the same as a
         * notice whose time could not be read. Only the second is reported as a phrase, and the first
         * keeps the wording it has always had.
         */
        public function testNoTimeAtAllIsStillReportedAsNotIdentifiedAndNotAsAnUnreadablePhrase(): void
        {
            $content = $this->render(
                ['title' => 'Parish retreat', 'event_date' => '2026-10-12', 'event_time' => null],
                []
            );

            self::assertStringContainsString('Time: Not identified', $content->text);
            self::assertStringNotContainsString('could not be read', $content->text);
            self::assertStringContainsString('Review notes: No extraction notes.', $content->text);
        }

        public function testTheEstablishedNotesAreStillCarriedThrough(): void
        {
            $content = $this->render(
                ['title' => 'Parish retreat', 'event_date' => null, 'event_time' => null],
                [
                    'unparsed_date_candidate:32 October 2026',
                    'The stated weekday does not match the parsed event date; verify it.',
                ]
            );

            self::assertStringContainsString('could not be read as a real date', $content->text);
            self::assertStringContainsString(
                'The stated weekday does not match the parsed event date; verify it.',
                $content->text
            );
        }

        /**
         * POPIA and a 40-character bound: the notice's own line is never reproduced, only as much of
         * the phrase as a human needs to recognise which text was rejected.
         */
        public function testAPhraseIsBoundedRatherThanReproducingTheWholeLine(): void
        {
            $content = $this->render(
                ['title' => 'Parish retreat', 'event_date' => null, 'event_time' => null],
                ['unparsed_date_candidate:' . str_repeat('9', 200)]
            );

            self::assertStringContainsString('could not be read as a real date', $content->text);
            self::assertStringContainsString('"' . str_repeat('9', 40) . '"', $content->text);
            self::assertStringNotContainsString(str_repeat('9', 41), $content->text);
        }

        /**
         * An all-day notice has no time row to be uncertain about, and a clean date with no phrase
         * gives the submitter nothing to act on -- so the date and time rows carry no marking at all.
         * (Every other row still shows "(please check)" for the fields the parser could not find,
         * which is pre-existing behaviour from #130 and not what this test is about.)
         */
        public function testAnAllDayNoticeWithAReadableDateIsNotMarkedOnItsDateOrTime(): void
        {
            $content = $this->render(
                ['title' => 'Parish retreat', 'event_date' => '2026-10-12', 'event_time' => null, 'all_day' => true],
                []
            );

            self::assertStringContainsString('Date: 12 October 2026', $content->text);
            self::assertStringContainsString('Time: All day', $content->text);
            self::assertStringNotContainsString('could not be read', $content->text);
        }

        /**
         * An all-day notice whose *time* could not be read must not conjure a time into the preview:
         * the event publishes as all-day, which is recoverable, so there is nothing to ask about on
         * the Time row. The sentence is still reported -- the notice did contain something, and the
         * submitter may know what it meant -- but the row is left as the renderer found it.
         */
        public function testAnUnreadableTimeOnAnAllDayNoticeReportsThePhraseButLeavesTheRowsAlone(): void
        {
            $content = $this->render(
                ['title' => 'Parish retreat', 'event_date' => '2026-10-12', 'event_time' => null, 'all_day' => true],
                ['unparsed_time_candidate:10.00-12:00am']
            );

            self::assertStringContainsString('The notice gives "10.00-12:00am" as the time', $content->text);
            // "All day" is the value; an unreadable time must not make it read as missing.
            self::assertStringContainsString('Time: All day', $content->text);
            // The Date row is untouched by a time reason.
            self::assertStringContainsString('Date: 12 October 2026', $content->text);
        }

        /**
         * A `notes` column that holds something other than a list of strings is refused outright by
         * `ConfirmationEmailCandidate` and by the job source that builds it, so this suite has no
         * licence to invent a mixed list. What it can assert is the shape that *is* legal: a list of
         * strings, none of which is a reason this renderer knows. Those must survive untouched rather
         * than being swallowed or replaced by a guess at what they meant.
         */
        public function testANoteThisRendererHasNoSentenceForIsLeftExactlyAsWritten(): void
        {
            $content = $this->render(
                ['title' => 'Parish retreat', 'event_date' => '2026-10-12', 'event_time' => '18:30'],
                ['skipped_sections: prayer=2', 'an entirely unfamiliar warning']
            );

            self::assertStringContainsString(
                'Review notes: skipped_sections: prayer=2 an entirely unfamiliar warning',
                $content->text
            );
            self::assertStringNotContainsString('could not be read', $content->text);
        }

        /**
         * An empty list and a list of only blank strings both read as "nothing to report", so the
         * preview must not claim an unreadable phrase it does not have, and must not print a
         * sentence built from an empty phrase.
         */
        public function testEmptyNotesAreNotTurnedIntoAnUnreadableWarning(): void
        {
            foreach ([[], [''], ['   ']] as $notes) {
                $content = $this->render(
                    ['title' => 'Parish retreat', 'event_date' => '2026-10-12', 'event_time' => '18:30'],
                    $notes
                );

                self::assertStringContainsString('Date: 12 October 2026', $content->text);
                self::assertStringContainsString('Time: 6:30 pm', $content->text);
                self::assertStringNotContainsString('could not be read', $content->text);
                self::assertStringNotContainsString('unparsed_', $content->text);
            }
        }

        /**
         * @param array<string, mixed> $fields
         * @param list<string> $notes
         */
        private function render(array $fields, array $notes = []): ConfirmationEmailContent
        {
            $candidate = new ConfirmationEmailCandidate(
                101,
                array_merge([
                    'title' => 'Parish retreat',
                    'event_date' => null,
                    'event_end_date' => null,
                    'event_time' => null,
                    'event_end_time' => null,
                    'venue' => null,
                    'venue_address' => null,
                    'venue_suburb' => null,
                    'parish_name' => null,
                    'description' => null,
                    'event_type' => null,
                    'contact' => null,
                    'all_day' => false,
                ], $fields),
                [],
                // Above `ReviewQueuePolicy::DEFAULT_CONFIDENCE_THRESHOLD`, so the renderer does not
                // blank out every field as unevidenced -- that would mask what these tests are about.
                0.9,
                $notes,
                'new',
                null
            );

        $batch = new ConfirmationEmailBatch(
            901,
            4,
            'sender@example.test',
            'Example Sender',
            'Event notice',
            new DateTimeImmutable('2026-10-01 12:00:00', new DateTimeZone('Africa/Johannesburg')),
            null,
            SenderTrust::UNKNOWN,
            SenderTrust::UNKNOWN,
            false,
            null,
            [$candidate]
        );

        return (new ConfirmationEmailRenderer())->render(
            $batch,
            new ConfirmationEmailActionLinks(
                'https://adct.example.test/action?token=all',
                [
                    101 => [
                        'approve' => 'https://adct.example.test/action?token=approve',
                        'deny' => 'https://adct.example.test/action?token=deny',
                        'edit' => 'https://adct.example.test/action?token=edit',
                    ],
                ]
            )
        );
    }
}
