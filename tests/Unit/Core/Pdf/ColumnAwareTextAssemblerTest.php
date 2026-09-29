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
        float $height = self::HEIGHT
    ): PositionedTextFragment {
        return new PositionedTextFragment($text, $x, $y, $height);
    }
}
