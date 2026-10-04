<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Core\Pdf;

use ADCT\ParishIntake\Core\Pdf\ColumnAwareTextAssembler;
use ADCT\ParishIntake\Core\Pdf\PositionedTextFragment;
use PHPUnit\Framework\TestCase;

final class ColumnAwareTextAssemblerTest extends TestCase
{
    private const LEFT_X = 60.0;
    private const RIGHT_X = 320.0;
    private const HEIGHT = 12.0;

    public function testReturnsAnEmptyStringWhenThereIsNoText(): void
    {
        self::assertSame('', (new ColumnAwareTextAssembler())->assemble([]));
    }

    public function testIgnoresFragmentsThatAreOnlyWhitespace(): void
    {
        self::assertSame('', (new ColumnAwareTextAssembler())->assemble([
            new PositionedTextFragment('   ', self::LEFT_X, 700.0, self::HEIGHT),
            new PositionedTextFragment('', self::LEFT_X, 690.0, self::HEIGHT),
        ]));
    }

    public function testReadsASingleColumnTopToBottom(): void
    {
        $assembled = (new ColumnAwareTextAssembler())->assemble([
            $this->fragment('Contact the parish office.', 676.0),
            $this->fragment('Parish Retreat Day', 700.0),
            $this->fragment('Saturday 17 October 2026', 688.0),
        ]);

        self::assertSame(
            "Parish Retreat Day\nSaturday 17 October 2026\nContact the parish office.",
            $assembled
        );
    }

    /**
     * A content stream emits rows, not columns: every column's first line, then
     * every column's second line. Reading the fragments in the order they arrive
     * would interleave two unrelated notices.
     */
    public function testReadsInterleavedColumnsOneColumnAtATime(): void
    {
        $assembled = $this->assembleInterleaved(
            [
                'Confirmation Day' => 700.0,
                'Sunday 8 November 2026' => 685.0,
                'at 10am in the hall.' => 670.0,
            ],
            [
                'Choir Rehearsal' => 700.0,
                'Thursday 12 November 2026' => 685.0,
                'at 6pm in the church.' => 670.0,
            ]
        );

        self::assertSame(
            "Confirmation Day\nSunday 8 November 2026\nat 10am in the hall.\n\n"
            . "Choir Rehearsal\nThursday 12 November 2026\nat 6pm in the church.",
            $assembled
        );
    }

    public function testSeparatesColumnsWithABlankLineSoTheBlockSplitterKeepsThemApart(): void
    {
        $assembled = $this->assembleInterleaved(
            ['Please bring your books.' => 670.0],
            ['Parish Notice' => 700.0]
        );

        self::assertStringContainsString("Please bring your books.\n\nParish Notice", $assembled);
    }

    public function testKeepsIdenticalTextInDifferentColumnsApart(): void
    {
        $assembled = $this->assembleInterleaved(
            ['Diary' => 700.0, 'Confirmation Day' => 685.0],
            ['Diary' => 700.0, 'Choir Rehearsal' => 685.0]
        );

        self::assertSame("Diary\nConfirmation Day\n\nDiary\nChoir Rehearsal", $assembled);
    }

    public function testStartsANewParagraphAtALargeVerticalGap(): void
    {
        $assembled = (new ColumnAwareTextAssembler())->assemble([
            $this->fragment('Parish Quiz Evening', 700.0),
            $this->fragment('Friday 20 November 2026', 688.0),
            $this->fragment('at 7pm in the parish hall.', 676.0),
            $this->fragment('Enquiries to the parish office.', 650.0),
        ]);

        self::assertSame(
            "Parish Quiz Evening\nFriday 20 November 2026\nat 7pm in the parish hall.\n\n"
            . 'Enquiries to the parish office.',
            $assembled
        );
    }

    public function testJoinsFragmentsThatShareABaselineIntoOneLine(): void
    {
        $assembled = (new ColumnAwareTextAssembler())->assemble([
            new PositionedTextFragment('Mass intentions', self::LEFT_X, 700.0, self::HEIGHT),
            new PositionedTextFragment('Enquiries to the parish office.', self::LEFT_X, 688.0, self::HEIGHT),
            new PositionedTextFragment('Diary', self::RIGHT_X, 700.0, self::HEIGHT),
        ]);

        self::assertSame("Mass intentions\nEnquiries to the parish office.\n\nDiary", $assembled);
    }

    public function testScalesTheColumnGapToTheFontSize(): void
    {
        // A wide threshold: the right column is far enough away to stay its own
        // column even though the runs are only a few characters wide.
        $wide = new ColumnAwareTextAssembler(columnGapInCharacters: 40.0);

        self::assertSame(
            "Confirmation Day\n\nChoir Rehearsal",
            $wide->assemble([
                $this->fragment('Confirmation Day', 700.0),
                $this->fragment('Choir Rehearsal', 700.0, 300.0),
            ])
        );
    }

    public function testFallsBackToAPointThresholdWhenNoRunIsLongEnoughToMeasure(): void
    {
        // Every run is too short to estimate a character width, so the fixed
        // 30 point fallback applies and a wide gap still splits.
        $assembled = (new ColumnAwareTextAssembler())->assemble([
            new PositionedTextFragment('A', 60.0, 700.0, 12.0),
            new PositionedTextFragment('B', 60.0, 688.0, 12.0),
            new PositionedTextFragment('C', 200.0, 700.0, 12.0),
        ]);

        self::assertSame("A\nB\n\nC", $assembled);
    }

    public function testTreatsAZeroHeightAsAMinimumRatherThanDividingByIt(): void
    {
        $assembled = (new ColumnAwareTextAssembler())->assemble([
            new PositionedTextFragment('Confirmation Day', self::LEFT_X, 700.0, 0.0),
            new PositionedTextFragment('Choir Rehearsal', self::RIGHT_X, 700.0, 0.0),
        ]);

        self::assertSame("Confirmation Day\n\nChoir Rehearsal", $assembled);
    }

    /**
     * A bulletin is not always the same layout all the way down. Once the full
     * width block above the columns ends, the columns below it must still be
     * read one at a time: banding the whole page on the x-axis would treat the
     * full width line as part of the left column and pull the date out of the
     * notice it belongs to.
     */
    public function testDetectsColumnsPerBlockWhenTheLayoutSwitchesFromOneColumnToTwo(): void
    {
        // The second line is centred, so its left edge lands between the two
        // columns: banded on the x-axis it starts a column of its own.
        $assembled = (new ColumnAwareTextAssembler())->assemble([
            $this->fragment('Parish Newsletter for November 2026', 760.0),
            $this->fragment('The annual Mass intention list is on the table.', 742.0, 200.0),
            $this->fragment('Confirmation Day', 700.0),
            $this->fragment('Sunday 8 November 2026', 700.0, self::RIGHT_X),
            $this->fragment('at 10am in the hall.', 688.0),
            $this->fragment('at 6pm in the church.', 688.0, self::RIGHT_X),
        ]);

        self::assertSame(
            "Parish Newsletter for November 2026\n"
            . "The annual Mass intention list is on the table.\n\n"
            . "Confirmation Day\n"
            . "at 10am in the hall.\n\n"
            . "Sunday 8 November 2026\n"
            . 'at 6pm in the church.',
            $assembled
        );
    }

    /**
     * A Mass times table spans the gutter between the columns. A gutter gap in
     * the x-axis projection is only a column boundary while nothing crosses it,
     * and a row that spans several columns is a table row that has to be read
     * whole, from left to right, rather than split at the gutter.
     */
    public function testReadsAFullWidthTableThatCrossesBothColumnsAsWholeRows(): void
    {
        $assembled = (new ColumnAwareTextAssembler())->assemble([
            $this->fragment('Parish Newsletter', 780.0),
            $this->fragment('Diary', 700.0),
            $this->fragment('Choir Rehearsal', 700.0, self::RIGHT_X),
            $this->fragment('Confirmation Day', 688.0),
            $this->fragment('Thursday 12 November', 688.0, self::RIGHT_X),
            $this->fragment('Mass times', 650.0),
            $this->fragment('Monday', 638.0),
            $this->fragment('5:45am', 638.0, 200.0),
            $this->fragment('Wednesday', 638.0, 330.0),
            $this->fragment('9:00am', 638.0, 470.0),
            $this->fragment('Tuesday', 626.0),
            $this->fragment('6:30am', 626.0, 200.0),
            $this->fragment('Thursday', 626.0, 330.0),
            $this->fragment('6:00pm', 626.0, 470.0),
        ]);

        self::assertSame(
            "Parish Newsletter\n\n"
            . "Diary\n"
            . "Confirmation Day\n\n"
            . "Choir Rehearsal\n"
            . "Thursday 12 November\n\n"
            . "Mass times\n\n"
            . "Monday 5:45am Wednesday 9:00am\n"
            . 'Tuesday 6:30am Thursday 6:00pm',
            $assembled
        );
    }

    /**
     * Rotated text runs along its own baseline, so the fragments of one rotated
     * line all share an x position and step through y. Treating that as a change
     * of line shreds the sentence into one word per line and reverses it.
     */
    public function testKeepsRotatedTextOnOneLineInsteadOfSplittingItIntoOneLinePerWord(): void
    {
        $assembled = (new ColumnAwareTextAssembler())->assemble([
            $this->fragment('Confirmation Day', 700.0),
            $this->fragment('Sunday 8 November 2026', 688.0),
            $this->fragment('at 10am in the hall.', 676.0),
            $this->fragment('Retreat', 700.0, 520.0, angle: 90.0),
            $this->fragment('programme', 740.0, 520.0, angle: 90.0),
        ]);

        self::assertSame(
            "Confirmation Day\n"
            . "Sunday 8 November 2026\n"
            . "at 10am in the hall.\n\n"
            . 'Retreat programme',
            $assembled
        );
    }

    /**
     * The extractor reports an angle with every run, and a skewed scan of a
     * bulletin carries a slightly different angle on every line even though
     * the page itself is upright. An angle recorded against a run is a property
     * of that run, not a page rotation, so it must not move the run or reorder
     * it: a page read with skewed metadata has to read the same as the page
     * read without it.
     */
    public function testReadsAPageTheSameWayWhateverSlopTheRunsAnglesCarry(): void
    {
        // A two column grid with a second line under the left column.
        $runs = [
            ['Confirmation Day', self::LEFT_X, 700.0],
            ['Choir Rehearsal', self::RIGHT_X, 700.0],
            ['Diary', self::LEFT_X, 688.0],
        ];

        $build = static function (array $runs, float $angle): array {
            return array_map(
                static fn (array $run): PositionedTextFragment => new PositionedTextFragment(
                    $run[0],
                    $run[1],
                    $run[2],
                    self::HEIGHT,
                    angle: $angle
                ),
                $runs
            );
        };

        $upright = (new ColumnAwareTextAssembler())->assemble($build($runs, 0.0));

        // Every run leans the same small amount, which is what a skewed scan of
        // a bulletin looks like.
        self::assertSame($upright, (new ColumnAwareTextAssembler())->assemble($build($runs, 1.5)));

        self::assertSame(
            "Confirmation Day\nDiary\n\nChoir Rehearsal",
            $upright
        );
    }

    /**
     * A boxed sidebar indents its list under its heading. The indent is a nested
     * list, not a third column, and reading it as one gives the block splitter a
     * heading with an empty block under it.
     */
    public function testDoesNotStartASpuriousColumnForANestedIndentInsideASidebar(): void
    {
        $assembled = (new ColumnAwareTextAssembler())->assemble([
            $this->fragment('Confirmation Day', 760.0),
            $this->fragment('Prayer group', 760.0, 380.0),
            $this->fragment('Sunday 8 November 2026', 748.0),
            $this->fragment('* Youth group', 748.0, 440.0),
            $this->fragment('at 10am in the hall.', 736.0),
            $this->fragment('* Marriage preparation', 736.0, 440.0),
        ]);

        self::assertSame(
            "Confirmation Day\n"
            . "Sunday 8 November 2026\n"
            . "at 10am in the hall.\n\n"
            . "Prayer group\n"
            . "* Youth group\n"
            . '* Marriage preparation',
            $assembled
        );
    }

    /**
     * A scan or a slightly rotated export is still a normal page. Only a real
     * rotation is treated as one, or every bulletin that came off a flatbed
     * would be shredded into one line per word.
     */
    public function testKeepsASlightlyTiltedRunInTheReadingFlow(): void
    {
        $assembled = (new ColumnAwareTextAssembler())->assemble([
            new PositionedTextFragment('Confirmation Day', self::LEFT_X, 700.0, self::HEIGHT, angle: 2.0),
            new PositionedTextFragment('Choir Rehearsal', self::RIGHT_X, 700.0, self::HEIGHT, angle: -1.5),
        ]);

        self::assertSame("Confirmation Day\n\nChoir Rehearsal", $assembled);
    }

    /**
     * @param array<string, float> $left  text => y, in the left column
     * @param array<string, float> $right text => y, in the right column
     */
    private function assembleInterleaved(array $left, array $right): string
    {
        $fragments = [];
        $rightTexts = array_keys($right);

        // Row-major order, exactly as a content stream emits it.
        foreach (array_keys($left) as $index => $text) {
            $fragments[] = $this->fragment($text, $left[$text]);

            if (isset($rightTexts[$index])) {
                $fragments[] = $this->fragment($rightTexts[$index], $right[$rightTexts[$index]], self::RIGHT_X);
            }
        }

        return (new ColumnAwareTextAssembler())->assemble($fragments);
    }

    private function fragment(
        string $text,
        float $y,
        float $x = self::LEFT_X,
        float $height = self::HEIGHT,
        float $angle = 0.0,
    ): PositionedTextFragment {
        return new PositionedTextFragment($text, $x, $y, $height, angle: $angle);
    }
}
