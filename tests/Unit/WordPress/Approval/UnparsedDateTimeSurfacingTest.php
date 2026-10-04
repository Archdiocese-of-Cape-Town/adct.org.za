<?php

declare(strict_types=1);

namespace {

    require_once __DIR__ . '/../../../Support/WordPressStubs.php';
}

/**
 * #167, the two surfaces that carry the one-press approve link.
 *
 * The emailed approval link is the last place an approver can be told that the notice's
 * date could not be read before their press publishes the event. Recording the failure
 * in a note is not enough: nobody reads notes. These two surfaces have to say it in
 * words, and they have to say it without blocking, because the approver pressed one
 * button and cannot be asked for a second.
 */

namespace ADCT\ParishIntake\Tests\Unit\WordPress\Approval {

    use ADCT\ParishIntake\Core\Auth\ActionTokenPreview;
    use ADCT\ParishIntake\Core\Auth\ActionTokenPurpose;
    use ADCT\ParishIntake\Core\Parsing\UnparsedDateTimeCandidate;
    use PHPUnit\Framework\TestCase;

    final class UnparsedDateTimeSurfacingTest extends TestCase
    {
        /**
         * @return list<array{0: string, 1: string}>
         */
        public static function reasonProvider(): array
        {
            return [
                'an unreadable date' => [
                    UnparsedDateTimeCandidate::DATE_REASON . ':32 October 2026',
                    'The notice gives "32 October 2026" as the date.',
                ],
                'an unreadable time' => [
                    UnparsedDateTimeCandidate::TIME_REASON . ':half nine in the morning',
                    'The notice gives "half nine in the morning" as the time.',
                ],
            ];
        }

        /**
         * A note from a different feature is not an unparsed date, and must not be
         * rendered as one. The wording is this class's alone; a screen branching on
         * the reason must not claim another feature's vocabulary.
         */
        #[\PHPUnit\Framework\Attributes\DataProvider('reasonProvider')]
        public function testTheWarningSaysCouldNotBeReadAndNeverThatTheFieldIsEmpty(string $note, string $expected): void
        {
            $described = UnparsedDateTimeCandidate::describe($note);

            self::assertNotNull($described, 'An unparsed note must describe itself.');
            self::assertStringContainsString($expected, $described);
            self::assertStringContainsString('could not be read', $described);
            self::assertStringNotContainsStringIgnoringCase('is empty', $described);
            self::assertStringNotContainsStringIgnoringCase('no date was given', $described);
        }

        public function testAnUnrelatedNoteIsNotDescribed(): void
        {
            self::assertNull(UnparsedDateTimeCandidate::describe('dmarc_fail'));
            self::assertNull(UnparsedDateTimeCandidate::describe('unknown_sender'));
            self::assertNull(UnparsedDateTimeCandidate::describe('manual_entry'));
            self::assertNull(
                UnparsedDateTimeCandidate::describe('possible_missed_event_after_skipped_section:2')
            );
        }

        /**
         * The phrase is quoted back to a human, so it must be the bounded matched
         * fragment and never the surrounding line of a real bulletin.
         */
        public function testTheQuotedPhraseIsBoundedAndNeverTheSurroundingLine(): void
        {
                    // A bulletin line that runs on: the recognisable part is the front of it,
                    // and the rest of the sentence -- and anything after it -- must not travel
                    // with the quote onto a screen the parish and the public can both see.
                    $line = 'Parish Mass 32 October 2026 followed by tea in the hall every Sunday at 10am';

                    $described = (string) UnparsedDateTimeCandidate::describe(
                        UnparsedDateTimeCandidate::DATE_REASON . ':' . $line
            );

                    self::assertStringContainsString('Parish Mass 32 October 2026', $described);
                    self::assertStringNotContainsString('tea in the hall', $described);
                    self::assertStringNotContainsString(
                        str_repeat('a', 41),
                        (string) UnparsedDateTimeCandidate::describe(
                            UnparsedDateTimeCandidate::DATE_REASON . ':' . str_repeat('a', 400)
                        )
                    );
                }

        /**
         * Whitespace in a matched fragment collapses, so a line-broken date does not
         * arrive on a screen as several ragged lines.
         */
        public function testWhitespaceInThePhraseCollapses(): void
        {
            $described = UnparsedDateTimeCandidate::describe(
                UnparsedDateTimeCandidate::DATE_REASON . ":32  \n  October\t2026"
            );

            self::assertNotNull($described);
            self::assertStringContainsString('"32 October 2026"', $described);
        }

        /**
         * A reason with no phrase says a value could not be read but not which one.
         * There is nothing to put in front of a human, so it must not be counted as
         * something to acknowledge -- otherwise an approver ticks a box about a value
         * they have never been shown.
         */
        public function testAReasonWithNoPhraseIsNotSomethingToAcknowledge(): void
        {
            $unparsed = UnparsedDateTimeCandidate::fromNotes([
                UnparsedDateTimeCandidate::DATE_REASON . ':',
                UnparsedDateTimeCandidate::DATE_REASON,
            ]);

            self::assertFalse($unparsed->hasDate());
            self::assertFalse($unparsed->isUnresolved());
            self::assertSame([], $unparsed->phrases());
        }

        public function testTheTwoReasonsAreDistinguishableFromEachOther(): void
        {
            $unparsed = UnparsedDateTimeCandidate::fromNotes([
                UnparsedDateTimeCandidate::DATE_REASON . ':32 October 2026',
                UnparsedDateTimeCandidate::TIME_REASON . ':ten past nine',
            ]);

            self::assertSame(['32 October 2026'], $unparsed->dates);
            self::assertSame(['ten past nine'], $unparsed->times);
            self::assertTrue($unparsed->hasDate());
            self::assertTrue($unparsed->hasTime());
            self::assertTrue($unparsed->isUnresolved());
        }

        public function testARecognisablePhraseIsShownUnchanged(): void
        {
            $unparsed = UnparsedDateTimeCandidate::fromNotes([
                UnparsedDateTimeCandidate::DATE_REASON . ':12/13/2026',
            ]);

            self::assertSame(['12/13/2026'], $unparsed->dates);
            self::assertStringContainsString(
                '"12/13/2026"',
                (string) UnparsedDateTimeCandidate::describe($unparsed->dates === [] ? '' : (
                    UnparsedDateTimeCandidate::DATE_REASON . ':' . $unparsed->dates[0]
                ))
            );
        }

        /**
         * An unreadable time is recoverable: the publisher treats a candidate with no
         * time as all-day, which is wrong for a morning Mass but publishable. An
         * unreadable date is not recoverable -- it publishes an event that never
         * happens. Only the date is something an approver has to acknowledge.
         */
        public function testOnlyAnUnreadableDateHasToBeAcknowledged(): void
        {
            self::assertTrue(
                UnparsedDateTimeCandidate::fromNotes([
                    UnparsedDateTimeCandidate::DATE_REASON . ':32 October 2026',
                ])->isUnresolved()
            );

            self::assertFalse(
                UnparsedDateTimeCandidate::fromNotes([
                    UnparsedDateTimeCandidate::TIME_REASON . ':ten past nine',
                ])->isUnresolved()
            );

            self::assertFalse(
                UnparsedDateTimeCandidate::fromNotes(['dmarc_fail'])->isUnresolved(),
                'An unrelated warning must not gate approval.'
            );
        }

        /**
         * No time at all and an unreadable time are different problems and must stay
         * different. "All day" means the parish said nothing about a clock; an
         * unreadable clock means something was said that we could not read.
         */
        public function testNoTimeAtAllIsNotAnUnparsedTime(): void
        {
            $unparsed = UnparsedDateTimeCandidate::fromNotes([]);

            self::assertFalse($unparsed->hasTime());
            self::assertFalse($unparsed->hasDate());
            self::assertFalse($unparsed->isUnresolved());
        }

        /**
         * The stored note is the mechanism, so a screen can rebuild the whole warning
         * from the row it was handed. Nothing about the warning lives only in the
         * parse result.
         */
        public function testTheWarningIsRebuiltFromTheStoredNoteAlone(): void
        {
            $note = UnparsedDateTimeCandidate::note(
                UnparsedDateTimeCandidate::DATE_REASON,
                '32 October 2026'
            );

            self::assertSame('unparsed_date_candidate:32 October 2026', $note);

            $unparsed = UnparsedDateTimeCandidate::fromNotes([$note]);

            self::assertTrue($unparsed->isUnresolved());
            self::assertStringContainsString(
                '"32 October 2026"',
                (string) UnparsedDateTimeCandidate::describe($note)
            );
        }

        /**
         * A GET shows a page and must not decide, so the preview is read-only. The
         * warning appears on it because the approver reads it before pressing.
         */
        public function testTheWarningIsAdditiveAndNeverRemovesTheAction(): void
        {
            $plain = new ActionTokenPreview(
                'Review event',
                'Retreat day',
                'Approve and publish',
                ['Parish: St Anne']
            );

            $withWarning = new ActionTokenPreview(
                'Review event',
                'Retreat day',
                'Approve and publish',
                [
                    'Parish: St Anne',
                    'Warning: ' . UnparsedDateTimeCandidate::describe(
                        UnparsedDateTimeCandidate::DATE_REASON . ':32 October 2026'
                    ),
                ]
            );

            self::assertTrue($plain->actionable);
            self::assertTrue(
                $withWarning->actionable,
                'A warning must not make the one-press link non-actionable.'
            );
            self::assertSame(
                'Approve and publish',
                $withWarning->submitLabel,
                'The warning must not change what the button says.'
            );
            self::assertCount(1, array_diff($withWarning->details, $plain->details));
        }
    }
}