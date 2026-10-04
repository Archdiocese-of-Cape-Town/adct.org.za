<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Parsing;

use ADCT\ParishIntake\Core\Parsing\ParseOutcome;
use ADCT\ParishIntake\Core\Parsing\ParseResult;
use ADCT\ParishIntake\Tests\Support\EmailFixtureResult;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class EmailFixtureResultTest extends TestCase
{
    public function testMatchExpectationsRequireExistingEventContext(): void
    {
        $result = new ParseResult();
        $outcome = new ParseOutcome([], [], [], [], $result);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('require existing_event context');

        EmailFixtureResult::actual(['match_kind' => 'cancellation'], $outcome);
    }

    /**
     * #167. A fixture asserting the candidate's notes is the only golden-corpus evidence
     * that an unreadable date is recorded at all, and the merge order used to throw
     * every note away: `ParseOutcome::$notes` is outcome-level and empty for a clean
     * parse, so it shadowed the primary result's notes with an empty list.
     */
    public function testTheCandidatesOwnNotesReachTheExpectationSurface(): void
    {
        $result = new ParseResult();
        $result->addNote('unparsed_date_candidate:32 October');
        $outcome = new ParseOutcome([], [], [], [], $result);

        $actual = EmailFixtureResult::actual([], $outcome);

        $this->assertSame(['unparsed_date_candidate:32 October'], $actual['candidate_notes']);
    }

    /**
     * The other half of the same fix: the outcome still owns the counts and the block
     * metadata, so a correction that dropped it would be a regression.
     */
    public function testTheOutcomeStillOwnsTheCountsAndBlocks(): void
    {
        $outcome = new ParseOutcome([], [], [], ['block one'], new ParseResult());

        $actual = EmailFixtureResult::actual([], $outcome);

        $this->assertSame(0, $actual['candidate_count']);
        $this->assertSame(['block one'], $actual['blocks']);
    }

    /**
     * The candidate's notes have their own key, and `notes` still means what it has
     * always meant in this corpus: the outcome's list. `unheaded-event-after-intentions`
     * depends on that -- all four of its expected notes are outcome-level, and the
     * candidate separately repeats one of them.
     */
    public function testNotesKeepMeaningTheOutcomesOwnList(): void
    {
        $result = new ParseResult();
        $result->addNote('possible_missed_event_after_skipped_section: 1');
        $outcome = new ParseOutcome([], ['skipped_sections: mass_intentions=1'], [], [], $result);

        $actual = EmailFixtureResult::actual([], $outcome);

        $this->assertSame(['skipped_sections: mass_intentions=1'], $actual['notes']);
        $this->assertSame(
            ['possible_missed_event_after_skipped_section: 1'],
            $actual['candidate_notes']
        );
    }

    /**
     * A candidate with no notes of its own must report an empty list rather than leaving
     * the key absent, so a fixture may assert it.
     */
    public function testTheCandidatesNotesAreAlwaysPresent(): void
    {
        $outcome = new ParseOutcome([], ['an outcome note'], [], [], new ParseResult());

        $actual = EmailFixtureResult::actual([], $outcome);

        $this->assertArrayHasKey('candidate_notes', $actual);
        $this->assertSame([], $actual['candidate_notes']);
    }
}