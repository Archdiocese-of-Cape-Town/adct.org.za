<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Parsing;

use ADCT\ParishIntake\Core\Parsing\Confidence\CandidateScorer;
use ADCT\ParishIntake\Core\Parsing\Confidence\FieldEvidence;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the scoring model itself.
 *
 * The end-to-end behaviour these guarantee is covered in ConfidenceScoringStageTest; this file
 * pins the table and the arithmetic, so a change to either is a deliberate, visible edit.
 */
final class FieldConfidenceTest extends TestCase
{
    public function testAFullyReadNoticeReachesNearFullConfidence(): void
    {
        $score = (new CandidateScorer())->score([
            'title' => new FieldEvidence(FieldEvidence::LABELLED),
            'event_date' => new FieldEvidence(FieldEvidence::EXPLICIT),
                'event_time' => new FieldEvidence(FieldEvidence::EXPLICIT),
                'parish_name' => new FieldEvidence(FieldEvidence::EXPLICIT),
                'venue' => new FieldEvidence(FieldEvidence::EXPLICIT),
                'event_type' => new FieldEvidence(FieldEvidence::EXPLICIT),
                'contact' => new FieldEvidence(FieldEvidence::EXPLICIT),
                'description' => new FieldEvidence(FieldEvidence::EXPLICIT),
                'recurrence' => new FieldEvidence(FieldEvidence::EXPLICIT),
            ]);

            // The scale must be anchored near the top so a notice the parser read cleanly is not
            // held for review on formatting alone. A notice with every field read cannot reach
            // exactly 1.0, because `explicit` is deliberately not a perfect score.
            self::assertGreaterThan(0.9, $score['score']);
            self::assertSame(1.0, $score['coverage']);
            self::assertSame(1.0, $score['fields']['title']['score']);
            self::assertSame(0.98, $score['fields']['event_date']['score']);
        }

    #[DataProvider('originScores')]
    public function testEachOriginScoresOnItsDocumentedValue(string $origin, float $expected): void
    {
        self::assertSame($expected, (new CandidateScorer())->scoreField('title', $origin));
    }

    /** @return array<string, array{0: string, 1: float}> */
    public static function originScores(): array
    {
        return [
            'a directory-verified value' => [FieldEvidence::DIRECTORY_VERIFIED, 1.0],
            'a labelled value' => [FieldEvidence::LABELLED, 1.0],
            'an explicit value' => [FieldEvidence::EXPLICIT, 0.98],
            'a directory text match' => [FieldEvidence::DIRECTORY_TEXT, 0.96],
            'a value from shared context' => [FieldEvidence::CONTEXT, 0.92],
            'a weakly derived value' => [FieldEvidence::INFERRED, 0.85],
            'an AI-supplied value' => [FieldEvidence::AI, 0.45],
            'a fabricated value' => [FieldEvidence::UNSUPPORTED, 0.0],
            'a human-entered value' => [FieldEvidence::MANUAL, 1.0],
        ];
    }

    public function testOriginsAreOrderedFromDirectToInvented(): void
    {
        $scorer = new CandidateScorer();
        $order = [
            FieldEvidence::LABELLED,
            FieldEvidence::EXPLICIT,
            FieldEvidence::DIRECTORY_TEXT,
            FieldEvidence::CONTEXT,
            FieldEvidence::INFERRED,
            FieldEvidence::AI,
            FieldEvidence::UNSUPPORTED,
        ];
        $scores = array_map(
            static fn (string $origin): float => $scorer->scoreField('title', $origin),
            $order
        );

        $sorted = $scores;
        rsort($sorted);
        self::assertSame($sorted, $scores, 'Origins must score in a strict order, most direct first.');
    }

    public function testAManualValueIsNeverScoredDownByItsFlags(): void
    {
        // A human correcting the parser's work must not have their correction discounted.
        self::assertSame(
            1.0,
            (new CandidateScorer())->scoreField('title', FieldEvidence::MANUAL, ['sentence_fragment', 'no_date_context'])
        );
    }

    public function testFlagPenaltiesAreSubtractedFromTheFieldScore(): void
        {
            $scorer = new CandidateScorer();

            // Each flag carries one documented subtraction, applied on top of the origin's score.
            self::assertEqualsWithDelta(0.93, $scorer->scoreField('title', FieldEvidence::EXPLICIT, ['sentence_fragment']), 0.0001);
            self::assertEqualsWithDelta(0.88, $scorer->scoreField('event_date', FieldEvidence::EXPLICIT, ['no_date_context']), 0.0001);
            self::assertEqualsWithDelta(0.93, $scorer->scoreField('event_date', FieldEvidence::EXPLICIT, ['sentence_fragment']), 0.0001);

            // Flags describing how a value was obtained add no penalty; they are kept so a reviewer
            // can see why a value was reached, not because reaching it was itself a defect.
            self::assertEqualsWithDelta(0.98, $scorer->scoreField('title', FieldEvidence::EXPLICIT, ['masthead_subject']), 0.0001);
            self::assertEqualsWithDelta(0.98, $scorer->scoreField('title', FieldEvidence::EXPLICIT, ['sender_fallback']), 0.0001);

            // Two flags accumulate rather than replace one another.
            self::assertEqualsWithDelta(
                0.88,
                $scorer->scoreField('title', FieldEvidence::EXPLICIT, ['sentence_fragment', 'unanchored_parish_match']),
                0.0001
            );
        }

        public function testScoresStayWithinZeroToOne(): void
        {
            $scorer = new CandidateScorer();
            $everyFlag = [
                'masthead_fallback', 'masthead_subject', 'sentence_fragment',
                'unanchored_parish_match', 'bare_time_match', 'sender_fallback', 'no_date_context',
            ];

            // No stack of penalties may drive a score below zero, and no origin may score above one.
            self::assertSame(0.0, $scorer->scoreField('title', FieldEvidence::UNSUPPORTED, $everyFlag));
            self::assertGreaterThanOrEqual(
                0.0,
                $scorer->scoreField('title', FieldEvidence::AI, $everyFlag)
            );
            self::assertLessThanOrEqual(1.0, $scorer->scoreField('title', FieldEvidence::LABELLED, $everyFlag));
        }

    public function testUnknownFlagsAreIgnoredRatherThanFatal(): void
    {
        // FieldEvidence rejects unknown flags at construction, so reaching here would need an
        // unvalidated input; the scorer degrades instead of throwing, which is what a scoring
        // pass needs to do to a stored row it did not write.
        self::assertSame(
            0.98,
            (new CandidateScorer())->scoreField('title', FieldEvidence::EXPLICIT, ['not_a_real_flag'])
        );
    }

    public function testFabricatingAFieldLowersTheOverallScore(): void
    {
        // The core of #130: adding an invented value cannot raise the score.
        $scorer = new CandidateScorer();
        $withoutContact = $scorer->score([
            'title' => new FieldEvidence(FieldEvidence::LABELLED),
            'event_date' => new FieldEvidence(FieldEvidence::EXPLICIT),
        ]);
        $withFabricatedContact = $scorer->score([
            'title' => new FieldEvidence(FieldEvidence::LABELLED),
            'event_date' => new FieldEvidence(FieldEvidence::EXPLICIT),
            'contact' => FieldEvidence::unsupported('sender_fallback'),
        ]);

        self::assertLessThan(
            $withoutContact['score'],
            $withFabricatedContact['score'],
            'A fabricated field must lower the overall score, not leave it unchanged.'
        );
        self::assertSame(0.0, $withFabricatedContact['fields']['contact']['score']);
    }

    public function testFabricatingARequiredFieldIsChargedAsMissingEvidence(): void
    {
        $scorer = new CandidateScorer();
        $scored = $scorer->score([
            'title' => new FieldEvidence(FieldEvidence::LABELLED),
            'event_date' => FieldEvidence::unsupported('no_date_context'),
        ]);

        // A fabricated date is no better than no date: both leave the event unpublishable, so
                // both must be charged against coverage.
                $absent = $scorer->score([
                    'title' => new FieldEvidence(FieldEvidence::LABELLED),
                ]);

                self::assertLessThan(1.0, $scored['score']);
        self::assertLessThanOrEqual($absent['score'], $scored['score']);
        self::assertSame(0.0, $scored['fields']['event_date']['score']);
    }

    public function testCoverageCountsMissingRequiredFieldsAgainstACandidate(): void
    {
        $scorer = new CandidateScorer();
        $complete = $scorer->score([
            'title' => new FieldEvidence(FieldEvidence::LABELLED),
            'event_date' => new FieldEvidence(FieldEvidence::EXPLICIT),
            'event_time' => new FieldEvidence(FieldEvidence::EXPLICIT),
            'parish_name' => new FieldEvidence(FieldEvidence::CONTEXT),
            'venue' => new FieldEvidence(FieldEvidence::EXPLICIT),
            'event_type' => new FieldEvidence(FieldEvidence::CONTEXT),
            'contact' => new FieldEvidence(FieldEvidence::EXPLICIT),
            'description' => new FieldEvidence(FieldEvidence::EXPLICIT),
            'recurrence' => new FieldEvidence(FieldEvidence::EXPLICIT),
        ]);
        $noDate = $scorer->score([
            'title' => new FieldEvidence(FieldEvidence::LABELLED),
            'event_time' => new FieldEvidence(FieldEvidence::EXPLICIT),
            'parish_name' => new FieldEvidence(FieldEvidence::CONTEXT),
            'venue' => new FieldEvidence(FieldEvidence::EXPLICIT),
            'event_type' => new FieldEvidence(FieldEvidence::CONTEXT),
            'contact' => new FieldEvidence(FieldEvidence::EXPLICIT),
            'description' => new FieldEvidence(FieldEvidence::EXPLICIT),
            'recurrence' => new FieldEvidence(FieldEvidence::EXPLICIT),
        ]);

        self::assertSame(1.0, $complete['coverage']);
        self::assertLessThan(1.0, $noDate['coverage']);
        self::assertLessThan(
            $complete['score'],
            $noDate['score'],
            'A notice with no date must not out-score a complete notice just because fewer fields dilute the mean.'
        );
    }

    public function testOverallScoreNeverExceedsOne(): void
    {
        $scored = (new CandidateScorer())->score([
            'title' => new FieldEvidence(FieldEvidence::DIRECTORY_VERIFIED),
            'event_date' => new FieldEvidence(FieldEvidence::LABELLED),
            'event_time' => new FieldEvidence(FieldEvidence::LABELLED),
            'parish_name' => new FieldEvidence(FieldEvidence::LABELLED),
            'venue' => new FieldEvidence(FieldEvidence::LABELLED),
            'event_type' => new FieldEvidence(FieldEvidence::LABELLED),
            'contact' => new FieldEvidence(FieldEvidence::LABELLED),
            'description' => new FieldEvidence(FieldEvidence::LABELLED),
            'recurrence' => new FieldEvidence(FieldEvidence::LABELLED),
        ]);

        self::assertLessThanOrEqual(1.0, $scored['score']);
        self::assertGreaterThanOrEqual(0.0, $scored['score']);
    }

    public function testPerFieldScoresAreReportedAlongsideTheOverallScore(): void
    {
        $scored = (new CandidateScorer())->score([
            'title' => FieldEvidence::unsupported('sentence_fragment'),
            'event_date' => new FieldEvidence(FieldEvidence::EXPLICIT),
        ]);

        self::assertSame(
            ['score', 'origin', 'flags'],
            array_keys($scored['fields']['title'])
        );
        self::assertSame('unsupported', $scored['fields']['title']['origin']);
        self::assertSame(['sentence_fragment'], $scored['fields']['title']['flags']);
        self::assertSame([], $scored['fields']['event_date']['flags']);
    }

    public function testScoringAFieldWithNoWeightIsRefused(): void
    {
        // A field that is scored but cannot move the overall score is the "the numbers look fine
        // but the event is not" failure this scoring exists to prevent, so it is a hard error
        // rather than a silently ignored entry.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('has no weight');

        (new CandidateScorer())->score([
            'organiser' => new FieldEvidence(FieldEvidence::EXPLICIT),
        ]);
    }

    public function testWholeCandidatePenaltiesApplyOnceEachAndClampAtZero(): void
    {
        $scorer = new CandidateScorer();

        self::assertEqualsWithDelta(
                    0.80,
            $scorer->applyWholeCandidatePenalties(0.95, ['weekday_mismatch']),
                    0.0001,
                    'weekday_mismatch is charged once, at its documented 0.15.'
                );
                self::assertEqualsWithDelta(
                    0.65,
                    $scorer->applyWholeCandidatePenalties(0.95, ['weekday_mismatch', 'end_before_start']),
                    0.0001
                );
        self::assertSame(
            0.0,
            $scorer->applyWholeCandidatePenalties(0.05, ['weekday_mismatch', 'end_before_start', 'recurrence_ambiguous']),
            'A deeply defective candidate floors at zero rather than going negative.'
        );
        self::assertSame(0.9, $scorer->applyWholeCandidatePenalties(0.9, ['not_a_real_flag']));
    }

    public function testUnsupportedPenaltyIsScaledByTheFieldsWeight(): void
    {
        $scorer = new CandidateScorer();

        // Inventing a title must cost more overall than inventing a description.
        self::assertGreaterThan($scorer->unsupportedPenalty('description'), $scorer->unsupportedPenalty('title'));
    }

    public function testAnUnknownOriginScoresZeroRatherThanFullConfidence(): void
    {
        // Defensive: an origin this version does not know about must never be read as strong.
        self::assertSame(0.0, (new CandidateScorer())->scoreField('title', 'origin_from_the_future'));
    }
}