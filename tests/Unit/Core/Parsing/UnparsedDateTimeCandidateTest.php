<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Core\Parsing;

use ADCT\ParishIntake\Core\Parsing\UnparsedDateTimeCandidate;
use PHPUnit\Framework\TestCase;

/**
 * #167: the vocabulary the parser writes and every screen branches on.
 *
 * These are the rules that keep an unresolved value out of the prose. A screen has to be
 * able to tell "the notice wrote 32 October and it is not a date" from every other kind of
 * note without matching on English, and the phrase it shows has to stay a fragment of the
 * matched run rather than a line of somebody's bulletin.
 */
final class UnparsedDateTimeCandidateTest extends TestCase
{
    public function testAnEmptyNoteListIsNothingUnresolved(): void
    {
        $unresolved = UnparsedDateTimeCandidate::fromNotes([]);

        self::assertFalse($unresolved->hasDate());
        self::assertFalse($unresolved->hasTime());
        self::assertFalse($unresolved->isUnresolved());
        self::assertSame([], $unresolved->phrases());
    }

    /**
     * Notes that are not ours have to pass through untouched and be ignored here. A parish
     * bulletin produces notes this class has never heard of, and treating one of those as
     * an unresolved date would block an approval for no reason.
     */
    public function testNotesFromOtherReasonsAreIgnored(): void
    {
        $unresolved = UnparsedDateTimeCandidate::fromNotes([
            'manual_entry',
            'dmarc_fail',
            'skipped_sections: notices=2',
            'The year was taken from the bulletin masthead.',
            'unparsed_time_candidate_lookalike:2575am',
        ]);

        self::assertFalse($unresolved->isUnresolved());
        self::assertSame([], $unresolved->phrases());
    }

    public function testADateAndATimeAreHeldApart(): void
    {
        $unresolved = UnparsedDateTimeCandidate::fromNotes([
            'unparsed_date_candidate:32 October 2026',
            'unparsed_time_candidate:2575am',
        ]);

        self::assertTrue($unresolved->hasDate());
        self::assertTrue($unresolved->hasTime());
        self::assertTrue($unresolved->isUnresolved());
        self::assertSame(['32 October 2026', '2575am'], $unresolved->phrases());
        self::assertSame(['32 October 2026'], $unresolved->dates);
        self::assertSame(['2575am'], $unresolved->times);
    }

    /**
     * An approver is asked to acknowledge the date, because an event with no date is the
         * failure this is all about. An unreadable time is a different problem -- the publisher
         * makes it an all-day event, which for a morning Mass is wrong but recoverable, and the
         * notice may have been all-day to begin with -- so it is surfaced, not acknowledged.
         */
        public function testAnUnreadableTimeAloneIsNotUnresolvedForApproval(): void
        {
            $unresolved = UnparsedDateTimeCandidate::fromNotes(['unparsed_time_candidate:2575am']);

            self::assertTrue($unresolved->hasTime());
            self::assertFalse($unresolved->isUnresolved());
        }

    /**
     * The phrase is quoted back to a human, so a note with nothing after the reason carries
     * no information and must not be counted as a phrase to show.
     */
    public function testAReasonWithNoPhraseIsNotAPhrase(): void
    {
        $unresolved = UnparsedDateTimeCandidate::fromNotes([
            'unparsed_date_candidate:',
            'unparsed_time_candidate:   ',
        ]);

        self::assertFalse($unresolved->isUnresolved());
    }

    public function testDuplicateNotesAreListedOnce(): void
    {
        $unresolved = UnparsedDateTimeCandidate::fromNotes([
            'unparsed_date_candidate:32 October 2026',
            'unparsed_date_candidate:32 October 2026',
        ]);

        self::assertSame(['32 October 2026'], $unresolved->dates);
    }

    /**
     * The stored note survives whatever the pipeline does to it, so reading it back must not
     * depend on it still being a well-formed record.
     */
    public function testANoteThatIsNotAStringIsSkipped(): void
    {
        $unresolved = UnparsedDateTimeCandidate::fromNotes([null, 42, ['a'], 'unparsed_date_candidate:12/13/2026']);

        self::assertSame(['12/13/2026'], $unresolved->dates);
    }

    /**
     * The phrase has to fit in a table cell. A notice can put an unreadable date in the
     * middle of a long sentence, and the match can be longer than the cell.
     */
    public function testThePhraseIsBoundedWhenItIsRecorded(): void
    {
        $note = UnparsedDateTimeCandidate::note(
            UnparsedDateTimeCandidate::DATE_REASON,
            '2026 ' . str_repeat('9', 80)
        );

        $phrase = explode(':', $note, 2)[1];

        self::assertLessThanOrEqual(40, mb_strlen($phrase));
        self::assertSame(
            [$phrase],
            UnparsedDateTimeCandidate::fromNotes([$note])->dates,
            'a phrase truncated on the way in has to come back out unchanged'
        );
    }

    /**
     * A phrase is stored in a JSON array and shown as text. A newline inside one would let a
     * truncated value read as a note that is not there, so the phrase is collapsed on the
     * way in rather than trusted on the way out.
     */
    public function testANewlineInAMatchedRunCannotForgeASecondNote(): void
    {
        $note = UnparsedDateTimeCandidate::note(
            UnparsedDateTimeCandidate::DATE_REASON,
            "12 October 2026\nunparsed_time_candidate:99pm"
        );

        self::assertStringNotContainsString("\n", $note);
        self::assertFalse(UnparsedDateTimeCandidate::fromNotes([$note])->hasTime());
                self::assertStringContainsString(
                    '12 October 2026 unparsed_time_candidate:',
                    UnparsedDateTimeCandidate::fromNotes([$note])->dates[0]
        );
    }

    /**
     * The sentence has to say the value could not be read. "No date was found" is what a
     * submitter must not read: it describes an empty field, which is indistinguishable from
     * a bulletin that never mentioned one, and it invites the wrong correction.
     */
    public function testTheDescriptionSaysTheValueCouldNotBeRead(): void
    {
        $date = UnparsedDateTimeCandidate::describe('unparsed_date_candidate:32 October 2026');
        $time = UnparsedDateTimeCandidate::describe('unparsed_time_candidate:2575am');

        self::assertIsString($date);
        self::assertIsString($time);

        foreach ([$date, $time] as $sentence) {
            self::assertStringContainsString('could not be read', $sentence);
                    self::assertStringContainsString('has been set', $sentence);
            self::assertStringNotContainsString('not identified', $sentence);
            self::assertStringNotContainsString('not stated', $sentence);
            self::assertStringNotContainsString('no date was', $sentence);
        }

                self::assertStringContainsString('no date has been set', $date);
                self::assertStringContainsString('no time has been set', $time);

        self::assertStringContainsString('32 October 2026', $date);
        self::assertStringContainsString('2575am', $time);
    }

    public function testANoteThatIsNotOursHasNoDescription(): void
    {
        self::assertNull(UnparsedDateTimeCandidate::describe('manual_entry'));
        self::assertNull(UnparsedDateTimeCandidate::describe('skipped_sections: notices=2'));
    }
}