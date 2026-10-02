<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Pdf;

/**
 * Reconstructs a readable text order from positioned text fragments.
 *
 * A plain text layer on a multi-column bulletin is interleaved: the content
 * stream emits the first line of every column, then the second line of every
 * column, and so on. Fed straight to the parser that produces events that
 * combine a date from one notice with a time from another.
 *
 * The approach here is deliberately simple and is the first half of the work
 * described in `docs/parser-samples.md` (finding 4):
 *
 *  1. Group fragments into columns by their horizontal position, using a gap
 *     threshold derived from the character width so it adapts to the font size
 *     instead of relying on a fixed number of points.
 *  2. Read each column top to bottom.
 *  3. Within a column, join fragments sharing a baseline into one line and
 *     break a paragraph wherever the vertical gap exceeds a line and a half.
 *
 * Full layout detection (nested columns, headings, tables, reading order
 * inside irregular blocks) is deliberately left to a follow-up issue.
 */
final class ColumnAwareTextAssembler
{
    /**
     * How much horizontal whitespace, in character widths, has to appear before
     * a new column is considered to have started.
     */
    public const COLUMN_GAP_IN_CHARACTERS = 4.0;

    /**
     * A vertical gap larger than this multiple of the line height starts a new
     * paragraph.
     */
    public const PARAGRAPH_GAP_IN_LINES = 1.5;

    /**
     * Fragments whose baselines differ by less than half a line height share a
     * line.
     */
    public const LINE_TOLERANCE_RATIO = 0.5;

    /**
     * Fallback column gap, in points, when no usable character width can be
     * derived from the fragments.
     */
    private const FALLBACK_COLUMN_GAP = 30.0;

    /**
     * A run shorter than this has no reliable width to contribute.
     */
    private const MIN_USEFUL_CHARACTERS = 4;

    public function __construct(
        private readonly float $columnGapInCharacters = self::COLUMN_GAP_IN_CHARACTERS,
        private readonly float $paragraphGapInLines = self::PARAGRAPH_GAP_IN_LINES,
    ) {
    }

    /**
     * Builds the reading-order text for one page.
     *
     * @param list<PositionedTextFragment> $fragments
     */
    public function assemble(array $fragments): string
    {
        $usable = $this->usableFragments($fragments);

        if ($usable === []) {
            return '';
        }

        $columns = [];

        foreach ($this->columnGroups($usable) as $column) {
            $lines = $this->linesInColumn($column);

            if ($lines !== []) {
                $columns[] = $this->joinIntoParagraphs($lines);
            }
        }

        // Each column is its own flow, so it has to be separated from the next.
        // Without the blank line the block splitter would hand the parser two
        // unrelated notices as one block and merge their dates and times.
        return implode("\n\n", $columns);
    }

    /**
     * @param list<PositionedTextFragment> $fragments
     * @return list<PositionedTextFragment>
     */
    private function usableFragments(array $fragments): array
    {
        $usable = [];

        foreach ($fragments as $fragment) {
            $text = trim($fragment->text);

            if ($text === '') {
                continue;
            }

            // A zero or negative height would poison every threshold below.
            $usable[] = new PositionedTextFragment(
                $text,
                $fragment->x,
                $fragment->y,
                $fragment->height > 0.0 ? $fragment->height : 1.0
            );
        }

        return $usable;
    }

    /**
     * Splits fragments into columns, ordered left to right.
     *
     * Fragments are sorted by x, then swept into a new column whenever the gap
     * to the previous fragment exceeds the threshold. Within a column the
     * fragments are ordered top to bottom.
     *
     * @param list<PositionedTextFragment> $fragments
     * @return list<list<PositionedTextFragment>>
     */
    private function columnGroups(array $fragments): array
    {
        if ($fragments === []) {
            return [];
        }

        $threshold = $this->columnGapThreshold($fragments);

        $byX = $fragments;
        usort($byX, static fn (PositionedTextFragment $a, PositionedTextFragment $b): int => $a->x <=> $b->x);

        $columns = [];
        $current = [];

        foreach ($byX as $fragment) {
            if ($current !== [] && $fragment->x - $current[count($current) - 1]->x > $threshold) {
                $columns[] = $current;
                $current = [];
            }

            $current[] = $fragment;
        }

        $columns[] = $current;

        foreach ($columns as $index => $column) {
            // Descending y: PDF user space measures up from the bottom of the page.
            usort(
                $column,
                static fn (PositionedTextFragment $a, PositionedTextFragment $b): int => $b->y <=> $a->y ?: $a->x <=> $b->x
            );
            $columns[$index] = $column;
        }

        return $columns;
    }

    /**
     * @param list<PositionedTextFragment> $fragments
     */
    private function columnGapThreshold(array $fragments): float
    {
        $medianCharacterWidth = $this->medianCharacterWidth($fragments);

        if ($medianCharacterWidth === null) {
            return self::FALLBACK_COLUMN_GAP;
        }

        return max(1.0, $medianCharacterWidth * $this->columnGapInCharacters);
    }

    /**
     * Estimates an average character width across the page.
     *
     * The font height stands in for the point size, so the estimate tracks
     * whatever size the document uses instead of assuming a fixed one.
     *
     * @param list<PositionedTextFragment> $fragments
     */
    private function medianCharacterWidth(array $fragments): ?float
    {
        $widths = [];

        foreach ($fragments as $fragment) {
            $characters = mb_strlen($fragment->text);

            if ($characters >= self::MIN_USEFUL_CHARACTERS) {
                $widths[] = $fragment->height / $characters;
            }
        }

        if ($widths === []) {
            return null;
        }

        return array_sum($widths) / count($widths);
    }

    /**
     * Groups fragments sharing a baseline into lines, ordered top to bottom.
     *
     * @param list<PositionedTextFragment> $column
     * @return list<PositionedTextLine>
     */
    private function linesInColumn(array $column): array
    {
        $lines = [];
        $group = [];
        $groupHeight = 0.0;

        foreach ($column as $fragment) {
            if ($group !== [] && abs($fragment->y - $group[0]->y) > $fragment->height * self::LINE_TOLERANCE_RATIO) {
                $lines[] = new PositionedTextLine($this->joinLine($group), $group[0]->y, $groupHeight);
                $group = [];
                $groupHeight = 0.0;
            }

            $group[] = $fragment;
            $groupHeight = max($groupHeight, $fragment->height);
        }

        if ($group !== []) {
            $lines[] = new PositionedTextLine($this->joinLine($group), $group[0]->y, $groupHeight);
        }

        return $lines;
    }

    /**
     * @param list<PositionedTextFragment> $fragments
     */
    private function joinLine(array $fragments): string
    {
        usort($fragments, static fn (PositionedTextFragment $a, PositionedTextFragment $b): int => $a->x <=> $b->x);

        $text = '';

        foreach ($fragments as $fragment) {
            if ($text !== '' && ! str_ends_with($text, ' ') && ! str_starts_with($fragment->text, ' ')) {
                $text .= ' ';
            }

            $text .= $fragment->text;
        }

        return $text;
    }

    /**
     * Joins lines, starting a new paragraph at each large vertical gap.
     *
     * Paragraph breaks matter: `SectionSkipper` and the block splitter both
     * treat a blank line as a boundary, so a bulletin whose paragraphs run
     * together would have its sections read as one.
     *
     * @param list<PositionedTextLine> $lines
     */
    private function joinIntoParagraphs(array $lines): string
    {
        $paragraphs = [];
        $current = [];
        $previous = null;

        foreach ($lines as $line) {
            if ($previous !== null) {
                $gap = $previous->y - $line->y;
                $threshold = max($previous->height, $line->height) * $this->paragraphGapInLines;

                if ($gap > $threshold) {
                    $paragraphs[] = implode("\n", $current);
                    $current = [];
                }
            }

            $current[] = $line->text;
            $previous = $line;
        }

        if ($current !== []) {
            $paragraphs[] = implode("\n", $current);
        }

        return implode("\n\n", $paragraphs);
    }
}
