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
 * A turned run is read along its own baseline, so a stamp set sideways comes
 * out as the line it is instead of as a stack of one-word lines. A run that
 * merely leans — a scan, or a page re-exported at a slight skew — stays in the
 * upright flow, or every bulletin that came off a flatbed would be shredded
 * into one word per line.
 *
 * The rest is read in four steps (see `docs/parser-samples.md`, finding 4):
 *
 *  1. Runs are sorted onto baselines, and each row of the page is then split
 *     into cells, wherever the horizontal gap between two runs is wider than a
 *     threshold derived from the character width, so it adapts to the font
 *     size instead of relying on a fixed number of points.
 *  2. The columns of the page are the ones most of its rows agree on: the
 *     narrowest count that at least half the rows splitting into cells share, so
 *     that a Mass times table crossing the gutter cannot redefine the columns of
 *     the page it sits in.
 *  3. Rows are grouped into bands, split wherever the page pauses for longer
 *     than two and a half lines, so the columns of the flow above a heading are
 *     not dragged into the ones below it. A row with one cell per column gives a
 *     line to each column; a row with more cells than the page has columns is a
 *     table row crossing the gutter, and is read whole and left to right in a
 *     block of its own. A row of one cell joins the column its left edge falls
 *     in, so a centred masthead above the columns reads with the band it sits
 *     in; only a run starting to the left of every column — an indent that
 *     belongs to nothing — keeps a block of its own.
 *
 * Blocks are then read in the order they start on the page, and a block that
 * starts further left first, so a table reads before or after the columns it
 * sits between rather than inside them. Turned runs are read last, because a
 * sideways stamp sits at the edge of a page rather than in the flow of it.
 *
 * When no row on the page splits into cells at all there is no grid to read
 * columns from, so the page falls back to banding the runs on the x-axis and
 * reading those bands from left to right.
 *
 * Within a block, runs sharing a baseline are joined into one line and a
 * paragraph starts wherever the vertical gap exceeds a line and a half.
 *
 * Every block is its own flow, so blocks are separated by a blank line. Without
 * the blank line the block splitter would hand the parser two unrelated notices
 * as one block and merge their dates and times.
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
     * A vertical gap larger than this multiple of the line height ends the
     * current block, so the block below it is read with the columns it has at
     * that point instead of the ones above it.
     */
    public const BAND_GAP_IN_LINES = 2.5;

    /**
     * Fallback column gap, in points, when no usable character width can be
     * derived from the fragments.
     */
    private const FALLBACK_COLUMN_GAP = 30.0;

    /**
     * A run shorter than this has no reliable width to contribute.
     */
    private const MIN_USEFUL_CHARACTERS = 4;

    /**
     * How far a run may lean, in degrees, before it counts as turned rather than
     * as a slightly skewed upright run.
     */
    public const ROTATION_TOLERANCE_DEGREES = 5.0;

    /**
     * How far two turned runs may differ in angle, in degrees, before they are
     * treated as separate runs rather than as words on one baseline.
     */
    private const ANGLE_TOLERANCE_DEGREES = 1.0;

    /**
     * The width of an average character, in multiples of the font height. Used
     * only where a run has to be measured to see how far it reaches, and only
     * when the PDF did not report that run's own advance width.
     */
    private const ESTIMATED_WIDTH_PER_CHARACTER = 0.5;

    /**
     * How far, as a multiple of the line height, a run may start outside a block
     * and still be read as part of it, so a caption set to one side of its
     * heading is not split off on its own.
     */
    private const BLOCK_EDGE_SLACK_IN_HEIGHTS = 4.0;

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
        [$upright, $turned] = $this->partitionByAngle($this->usableFragments($fragments));

        $rows = $this->rows($upright);
        $flows = [];

        if ($rows !== []) {
            $columnGap = $this->columnGapThreshold($this->allFragments($rows));
            $columnCount = $this->columnCount($rows, $columnGap);

            // With no row reaching across a gutter there is no grid to read columns
            // from, so the runs are banded on the x-axis and read left to right.
            $flows = $columnCount < 2
                ? [$this->assembleWithoutAGrid($this->allFragments($rows))]
                : $this->gridFlows($rows, $columnCount, $columnGap);
        }

        // A turned run sits at the edge of a page rather than in the flow of it, so
        // it is read after the upright text.
        foreach ($this->turnedFlows($turned) as $flow) {
            $flows[] = $flow;
        }

        // Each block is its own flow, so it has to be separated from the next.
        // Without the blank line the block splitter would hand the parser two
        // unrelated notices as one block and merge their dates and times.
        return implode("\n\n", $flows);
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
                $fragment->height > 0.0 ? $fragment->height : 1.0,
                $fragment->width,
                $fragment->angle,
            );
        }

        return $usable;
    }

    /**
     * Splits the runs into the upright flow and the runs turned far enough to read
     * along their own baseline.
     *
     * A run that merely leans is left in the upright flow: a scan or a page
     * re-exported at a slight skew would otherwise be shredded into one word per
     * line.
     *
     * @param list<PositionedTextFragment> $fragments
     * @return array{list<PositionedTextFragment>, list<PositionedTextFragment>}
     */
    private function partitionByAngle(array $fragments): array
    {
        $upright = [];
        $turned = [];

        foreach ($fragments as $fragment) {
            if (abs($fragment->angle) > self::ROTATION_TOLERANCE_DEGREES) {
                $turned[] = $fragment;
                continue;
            }

            $upright[] = $fragment;
        }

        return [$upright, $turned];
    }

    /**
     * Groups the upright fragments onto baselines into rows, ordered top to
     * bottom, with the fragments of each row ordered left to right.
     *
     * @param list<PositionedTextFragment> $fragments
     * @return list<list<PositionedTextFragment>>
     */
    private function rows(array $fragments): array
    {
        if ($fragments === []) {
            return [];
        }

        // Descending y: PDF user space measures up from the bottom of the page.
        usort(
            $fragments,
            static fn (PositionedTextFragment $a, PositionedTextFragment $b): int => $b->y <=> $a->y ?: $a->x <=> $b->x,
        );

        $rows = [];
        $row = [];
        $height = 0.0;

        foreach ($fragments as $fragment) {
            $tolerance = max($height, $fragment->height) * self::LINE_TOLERANCE_RATIO;

            if ($row !== [] && abs($fragment->y - $row[0]->y) > $tolerance) {
                $rows[] = $row;
                $row = [];
                $height = 0.0;
            }

            $row[] = $fragment;
            $height = max($height, $fragment->height);
        }

        if ($row !== []) {
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param list<list<PositionedTextFragment>> $rows
     * @return list<PositionedTextFragment>
     */
    private function allFragments(array $rows): array
    {
        $fragments = [];

        foreach ($rows as $row) {
            foreach ($row as $fragment) {
                $fragments[] = $fragment;
            }
        }

        return $fragments;
    }

    /**
     * How wide a run reaches, from the font's own metrics where the PDF reported
     * them and from the length of the text where it did not.
     */
    private function fragmentWidth(PositionedTextFragment $fragment): float
    {
        if ($fragment->width > 0.0) {
            return $fragment->width;
        }

        return mb_strlen($fragment->text) * $fragment->height * self::ESTIMATED_WIDTH_PER_CHARACTER;
    }

    /**
     * Splits a row into cells wherever the gap between two runs is wider than the
     * column gap.
     *
     * @param list<PositionedTextFragment> $row
     * @return list<list<PositionedTextFragment>>
     */
    private function cells(array $row, float $columnGap): array
    {
        if ($row === []) {
            return [];
        }

        $cells = [];
        $cell = [];

        foreach ($row as $fragment) {
            if ($cell !== []) {
                $previous = $cell[count($cell) - 1];

                if ($fragment->x - ($previous->x + $this->fragmentWidth($previous)) > $columnGap) {
                    $cells[] = $cell;
                    $cell = [];
                }
            }

            $cell[] = $fragment;
        }

        $cells[] = $cell;

        return $cells;
    }

    /**
     * The columns of the page: the narrowest count at least half the rows that
     * split into cells agree on, so that a table crossing the gutter cannot
     * redefine the columns of the page it sits in.
     *
     * @param list<list<PositionedTextFragment>> $rows
     */
    private function columnCount(array $rows, float $columnGap): int
    {
        $agreeing = [];

        foreach ($rows as $row) {
            $count = count($this->cells($row, $columnGap));

            if ($count < 2) {
                continue;
            }

            $agreeing[$count] = ($agreeing[$count] ?? 0) + 1;
        }

        if ($agreeing === []) {
            return 1;
        }

        $splittingRows = array_sum($agreeing);
        ksort($agreeing);

        foreach ($agreeing as $count => $rows) {
            if ($rows * 2 >= $splittingRows) {
                return $count;
            }
        }

        return 1;
    }

    /**
     * Splits the rows into bands wherever the page pauses for longer than two and
     * a half lines, so the columns of the flow above a heading are not dragged
     * into the ones below it.
     *
     * @param list<list<PositionedTextFragment>> $rows
     * @return list<list<list<PositionedTextFragment>>>
     */
    private function bands(array $rows): array
    {
        $bands = [];
        $band = [];
        $previous = null;
        $previousHeight = 0.0;

        foreach ($rows as $row) {
            $height = $this->fragmentsHeight($row);

            if (
                $previous !== null
                && $previous->y - $row[0]->y > max($previousHeight, $height) * self::BAND_GAP_IN_LINES
            ) {
                $bands[] = $band;
                $band = [];
            }

            $band[] = $row;
            $previous = $row[0];
            $previousHeight = $height;
        }

        if ($band !== []) {
            $bands[] = $band;
        }

        return $bands;
    }

    /**
     * Reads the rows of one band into blocks, so that a layout which changes
     * partway down the page is read with the columns it has at that point.
     *
     * A row with one cell per column gives a line to each column. A row with more
     * cells than the band has columns is a table row reaching across the gutter:
     * it is read whole, left to right, in a block of its own. A row with fewer
     * cells joins the block its left edge falls in, so a centred masthead reads
     * with the heading it sits under rather than starting a column of its own.
     *
     * @param list<list<PositionedTextFragment>> $band
     * @param int                               $pageColumns columns agreed on by the whole page
     * @return list<array{lines: list<PositionedTextLine>, top: float, left: float, right: float}>
     */
    private function blocksInBand(array $band, int $pageColumns, float $columnGap): array
    {
        // The columns of this band rather than of the page: a page that runs a
        // full width masthead above two columns has one column where the masthead
        // is and two where the columns are. A band may not claim more columns
        // than the page agreed on, or a Mass times table crossing the gutter
        // would redefine the page it sits in.
        $bandColumns = min($pageColumns, $this->columnCount($band, $columnGap));

        $columns = [];
        $others = [];
        $tables = [];
        $inTable = false;

        foreach ($band as $row) {
            $cells = $this->cells($row, $columnGap);

            // More cells than the band has columns: a table row reaching across the
            // gutter, read whole rather than split at the column boundary. Rows that
            // follow one another keep the same block, so a table reads as the table it
            // is rather than as one block per row.
            if ($bandColumns >= 2 && count($cells) > $bandColumns) {
                $last = count($tables) - 1;

                if (! $inTable) {
                    $tables[] = $this->newBlock($row[0]);
                    $last++;
                }

                $tables[$last] = $this->addLine($tables[$last], $this->flatten($cells));
                $inTable = true;

                continue;
            }

            $inTable = false;

            // One cell per column: a line of each column.
            if ($bandColumns >= 2 && count($cells) === $bandColumns) {
                foreach ($cells as $index => $cell) {
                    if (! isset($columns[$index])) {
                        $columns[$index] = $this->newBlock($cell[0]);
                    }

                    $columns[$index] = $this->addLine($columns[$index], $cell);
                }

                continue;
            }

            // Fewer cells than columns: one run reaching across the gutter, which
            // belongs to the block it starts inside rather than to a column.
            $this->addSpanningRow($row, $columns, $others);
        }

        return array_values(array_merge(array_values($columns), $others, $tables));
    }

    /**
     * Adds a row that does not fill the band's columns to the block its left edge
     * falls in, or to the heading above it, so a centred masthead reads with the
     * band it sits in. A run that falls inside no other block, such as an indent
     * belonging to nothing, keeps a block of its own.
     *
     * @param list<PositionedTextFragment> $row
     * @param array<int, array{lines: list<PositionedTextLine>, top: float, left: float, right: float}> $columns
     * @param list<array{lines: list<PositionedTextLine>, top: float, left: float, right: float}> $others
     */
    private function addSpanningRow(
        array $row,
        array &$columns,
        array &$others,
    ): void {
        $anchor = $row[0];
        $slack = $this->fragmentsHeight($row) * self::BLOCK_EDGE_SLACK_IN_HEIGHTS;

        foreach ($columns as $index => $block) {
            if ($anchor->x >= $block['left'] - $slack && $anchor->x <= $block['right'] + $slack) {
                $columns[$index] = $this->addLine($block, $row);

                return;
            }
        }

        foreach ($others as $index => $block) {
            if ($anchor->x >= $block['left'] - $slack && $anchor->x <= $block['right'] + $slack) {
                $others[$index] = $this->addLine($block, $row);

                return;
            }
        }

        $others[] = $this->addLine($this->newBlock($anchor), $row);
    }

    /**
     * @param list<list<PositionedTextFragment>> $cells
     * @return list<PositionedTextFragment>
     */
    private function flatten(array $cells): array
    {
        return array_merge(...$cells);
    }

    /**
     * Reads every band of the page, ordered by where each block starts.
     *
     * @param list<list<PositionedTextFragment>> $rows
     * @return list<string>
     */
    private function gridFlows(array $rows, int $columnCount, float $columnGap): array
    {
        $blocks = [];

        foreach ($this->bands($rows) as $band) {
            foreach ($this->blocksInBand($band, $columnCount, $columnGap) as $block) {
                $blocks[] = $block;
            }
        }

        // A block starting higher on the page is read first, and one starting at
        // the same height from left to right, so a table reads before or after the
        // columns it sits between rather than inside them.
        usort(
            $blocks,
            static fn (array $a, array $b): int => $b['top'] <=> $a['top'] ?: $a['left'] <=> $b['left'],
        );

        $flows = [];

        foreach ($blocks as $block) {
            $flows[] = $this->joinIntoParagraphs($block['lines']);
        }

        return $flows;
    }

    /**
     * Reads the turned runs, each along its own baseline.
     *
     * The words of a turned line sit side by side across its baseline and step
     * along it, so they are one line and not one word per line. Which side that
     * is depends on how far the run is turned, so the runs are found across the
     * baseline rather than down the page: a run turned a quarter turn groups by
     * x, one turned to a diagonal steps through both x and y, and neither may be
     * read down the page as if it were upright text.
     *
     * @param list<PositionedTextFragment> $turned
     * @return list<string>
     */
    private function turnedFlows(array $turned): array
    {
        $runs = [];

        foreach ($turned as $fragment) {
            foreach ($runs as $index => $run) {
                if ($this->onTheSameBaseline($fragment, $run)) {
                    $runs[$index]['fragments'][] = $fragment;

                    continue 2;
                }
            }

            $runs[] = [
                'angle' => $fragment->angle,
                'offset' => $this->acrossBaseline($fragment, $fragment->angle),
                'fragments' => [$fragment],
            ];
        }

        $flows = [];

        foreach ($runs as $run) {
            $fragments = $this->alongBaseline($run['fragments'], $run['angle']);

            $flows[] = [
                'text' => $this->joinOrdered($fragments),
                'top' => max(array_column($fragments, 'y')),
                'left' => min(array_column($fragments, 'x')),
            ];
        }

        usort($flows, static fn (array $a, array $b): int => $b['top'] <=> $a['top'] ?: $a['left'] <=> $b['left']);

        return array_column($flows, 'text');
    }

    /**
     * Whether a fragment sits on the baseline of a turned run, and is turned the
     * same way as that run.
     *
     * @param array{angle: float, offset: float, fragments: list<PositionedTextFragment>} $run
     */
    private function onTheSameBaseline(PositionedTextFragment $fragment, array $run): bool
    {
        return abs($fragment->angle - $run['angle']) <= self::ANGLE_TOLERANCE_DEGREES
            && abs($this->acrossBaseline($fragment, $run['angle']) - $run['offset'])
                <= $fragment->height * self::LINE_TOLERANCE_RATIO;
    }

    /**
     * Where a fragment sits across a baseline turned through an angle, which is
     * the axis at right angles to the direction that baseline reads in.
     */
    private function acrossBaseline(PositionedTextFragment $fragment, float $angle): float
    {
        $radians = deg2rad($angle);

        return $fragment->x * -sin($radians) + $fragment->y * cos($radians);
    }

    /**
     * Orders fragments along the direction their run reads in, which for a turned
     * run is neither left to right nor top to bottom but the baseline itself.
     *
     * The first word of the run is the one furthest back along that direction, so
     * a run turned a quarter turn anticlockwise reads from the bottom of the page
     * upwards and one turned clockwise reads from the top down.
     *
     * @param list<PositionedTextFragment> $fragments
     * @return list<PositionedTextFragment>
     */
    private function alongBaseline(array $fragments, float $angle): array
    {
        $radians = deg2rad($angle);
        $alongX = cos($radians);
        $alongY = sin($radians);

        usort(
            $fragments,
            static fn (PositionedTextFragment $a, PositionedTextFragment $b): int
                => ($a->x * $alongX + $a->y * $alongY) <=> ($b->x * $alongX + $b->y * $alongY),
        );

        return $fragments;
    }

    /**
     * Joins fragments in the order they were given rather than left to right.
     *
     * @param list<PositionedTextFragment> $fragments
     */
    private function joinOrdered(array $fragments): string
    {
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
     * @return array{lines: list<PositionedTextLine>, top: float, left: float, right: float}
     */
    private function newBlock(PositionedTextFragment $anchor): array
    {
        return [
            'lines' => [],
            'top' => $anchor->y,
            'left' => $anchor->x,
            'right' => $anchor->x + $this->fragmentWidth($anchor),
        ];
    }

    /**
     * @param array{lines: list<PositionedTextLine>, top: float, left: float, right: float} $block
     * @param list<PositionedTextFragment> $fragments
     * @return array{lines: list<PositionedTextLine>, top: float, left: float, right: float}
     */
    private function addLine(array $block, array $fragments): array
    {
        $block['lines'][] = $this->lineOf($fragments);

        foreach ($fragments as $fragment) {
            $block['left'] = min($block['left'], $fragment->x);
            $block['right'] = max($block['right'], $fragment->x + $this->fragmentWidth($fragment));
        }

        return $block;
    }

    /**
     * @param list<PositionedTextFragment> $fragments
     */
    private function lineOf(array $fragments): PositionedTextLine
    {
        $y = 0.0;

        foreach ($fragments as $fragment) {
            $y = max($y, $fragment->y);
        }

        return new PositionedTextLine($this->joinLine($fragments), $y, $this->fragmentsHeight($fragments));
    }

    /**
     * @param list<PositionedTextFragment> $fragments
     */
    private function fragmentsHeight(array $fragments): float
    {
        $height = 0.0;

        foreach ($fragments as $fragment) {
            $height = max($height, $fragment->height);
        }

        return $height;
    }

    /**
     * Falls back to banding the runs on the x-axis, for a page where no row
     * reaches across a gutter and so there is no grid to read columns from.
     *
     * @param list<PositionedTextFragment> $fragments
     */
    private function assembleWithoutAGrid(array $fragments): string
    {
        $flows = [];

        foreach ($this->columnGroups($fragments) as $column) {
            $flows[] = $this->joinIntoParagraphs($this->linesInColumn($column));
        }

        return implode("\n\n", $flows);
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
