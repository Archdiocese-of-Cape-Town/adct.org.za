<?php

declare(strict_types=1);

/**
 * Generates the synthetic PDF fixtures used by the E8.1 extraction tests.
 *
 * Run once with:  php tests/fixtures/pdfs/generate.php
 * The generated files are committed so the test suite stays hermetic and fast.
 *
 * Every fixture is invented for this repository. No real parish bulletin, notice,
 * name, address or telephone number is ever copied into a fixture (POPIA).
 *
 * Each page places its text with the `Tm` operator, so every line has an exact
 * absolute position. That is what makes the multi-column fixtures meaningful: a
 * naive linear read interleaves the columns, and only position-aware grouping
 * recovers the intended reading order.
 */

$outputDirectory = __DIR__;

if (! is_dir($outputDirectory) || ! is_writable($outputDirectory)) {
    fwrite(STDERR, "Fixture directory is not writable: {$outputDirectory}\n");
    exit(1);
}

/**
 * A line of text at an absolute position.
 *
 * @param float $x    left edge, in PostScript points from the left of the page
 * @param float $y    baseline, in PostScript points from the bottom of the page
 * @param float $size font size in points
 * @param float $degrees how far the baseline is turned, counter-clockwise
 */
final class TextLine
{
    public function __construct(
        public readonly string $text,
        public readonly float $x,
        public readonly float $y,
        public readonly float $size = 10.0,
        public readonly float $degrees = 0.0,
    ) {
    }

    /**
     * The four values the `Tm` operator takes before the translation, for a
     * baseline turned by `$degrees` counter-clockwise. With the rotation about
     * the origin, the run's anchor stays at the supplied position and the text
     * runs away from it along the turned baseline, which is how a publisher
     * places a vertical caption.
     *
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    public function textMatrix(): array
    {
        if ($this->degrees === 0.0) {
            return [1.0, 0.0, 0.0, 1.0];
        }

        $radians = deg2rad($this->degrees);

        return [cos($radians), sin($radians), -sin($radians), cos($radians)];
    }
}

/**
 * Builds a minimal but valid PDF 1.4 document with a classic cross-reference table.
 *
 * @param list<list<TextLine>> $pages
 */
function buildPdf(array $pages): string
{
    // Object numbering: 1 catalog, 2 pages tree, 3 font, then per page a page
    // object followed by its content stream.
    $fontObjectNumber = 3;
    $firstPageObjectNumber = 4;

    $pageCount = count($pages);
    $kidReferences = [];

    for ($index = 0; $index < $pageCount; ++$index) {
        $kidReferences[] = ($firstPageObjectNumber + ($index * 2)) . ' 0 R';
    }

    $objects = [];

    $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
    $objects[2] = sprintf(
        '<< /Type /Pages /Kids [%s] /Count %d >>',
        implode(' ', $kidReferences),
        $pageCount
    );
    $objects[$fontObjectNumber] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';

    foreach ($pages as $index => $lines) {
        $pageObjectNumber = $firstPageObjectNumber + ($index * 2);
        $contentObjectNumber = $pageObjectNumber + 1;

        $objects[$pageObjectNumber] = sprintf(
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] '
            . '/Resources << /Font << /F1 %d 0 R >> >> /Contents %d 0 R >>',
            $fontObjectNumber,
            $contentObjectNumber
        );

        $objects[$contentObjectNumber] = "stream\n" . contentStream($lines) . "\nendstream";
    }

    ksort($objects);

    $pdf = "%PDF-1.4\n";
    // A binary comment marks the file as binary for transfer tools.
    $pdf .= "%\xE2\xE3\xCF\xD3\n";

    $offsets = [];

    foreach ($objects as $objectNumber => $body) {
        $offsets[$objectNumber] = strlen($pdf);
        $pdf .= sprintf("%d 0 obj\n%s\nendobj\n", $objectNumber, $body);
    }

    $xrefOffset = strlen($pdf);
    $highestObjectNumber = max(array_keys($objects));
    $size = $highestObjectNumber + 1;

    $pdf .= "xref\n0 {$size}\n";
    $pdf .= "0000000000 65535 f \n";

    for ($objectNumber = 1; $objectNumber < $size; ++$objectNumber) {
        $offset = $offsets[$objectNumber] ?? null;

        if ($offset === null) {
            $pdf .= "0000000000 65535 f \n";

            continue;
        }

        $pdf .= sprintf("%010d 00000 n \n", $offset);
    }

    $pdf .= sprintf(
        "trailer\n<< /Size %d /Root 1 0 R >>\nstartxref\n%d\n%%%%EOF\n",
        $size,
        $xrefOffset
    );

    return $pdf;
}

/**
 * @param list<TextLine> $lines
 */
function contentStream(array $lines): string
{
    $parts = [];

    foreach ($lines as $line) {
        if (trim($line->text) === '') {
            continue;
        }

        [$a, $b, $c, $d] = $line->textMatrix();

        $parts[] = sprintf(
            'BT /F1 %s Tf %s %s %s %s %s %s Tm (%s) Tj ET',
            pdfNumber($line->size),
            pdfNumber($a),
            pdfNumber($b),
            pdfNumber($c),
            pdfNumber($d),
            pdfNumber($line->x),
            pdfNumber($line->y),
            pdfLiteralString($line->text)
        );
    }

    // A page with no text still needs a content stream: this is what a scanned
    // image-only page looks like to a text extractor.
    if ($parts === []) {
        $parts[] = '0.5 0.5 0.5 rg 72 400 451 300 re f';
    }

    return implode("\n", $parts);
}

function pdfNumber(float $value): string
{
    $formatted = rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');

    return $formatted === '' || $formatted === '-' ? '0' : $formatted;
}

function pdfLiteralString(string $text): string
{
    $escaped = str_replace(
        ['\\', '(', ')', "\r", "\n"],
        ['\\\\', '\\(', '\\)', ' ', ' '],
        $text
    );

    // The fixtures use WinAnsiEncoding, which is byte-compatible with ASCII for
    // the characters involved. Anything else is transliterated to an ASCII
    // placeholder so a fixture can never fail to encode.
    $escaped = preg_replace('/[^\x20-\x7E]/', '?', $escaped) ?? $escaped;

    return $escaped;
}

/**
 * @param list<list<TextLine>> $pages
 */
function writeFixture(string $name, array $pages): void
{
    $path = __DIR__ . DIRECTORY_SEPARATOR . $name;
    $bytes = buildPdf($pages);

    if (file_put_contents($path, $bytes) === false) {
        fwrite(STDERR, "Unable to write {$path}\n");
        exit(1);
    }

    printf("Wrote %s (%d bytes)\n", $name, strlen($bytes));
}

// ---------------------------------------------------------------------------
// 1. A single-column poster: one clear event, the common simple case.
// ---------------------------------------------------------------------------
writeFixture('single-column-poster.pdf', [
    [
        new TextLine("St Brendan's Parish", 200, 760, 18),
        new TextLine('Parish Retreat Day', 200, 700, 16),
        new TextLine('Saturday 17 October 2026, from 9am to 3pm', 200, 660, 11),
        new TextLine('All are welcome. Bring a packed lunch.', 200, 630, 11),
        new TextLine('Contact the parish office.', 200, 590, 11),
    ],
]);

// ---------------------------------------------------------------------------
// 2. A two-column bulletin. The two columns describe different events, so a
//    naive linear read interleaves them into nonsense. This is the regression
//    fixture for the column pass.
// ---------------------------------------------------------------------------
$leftColumn = [
    'Parish Notice',
    'Confirmation Day',
    'Sunday 8 November 2026',
    'at 10am in the hall.',
    'Please bring your books.',
];

$rightColumn = [
    'Parish Notice',
    'Choir Rehearsal',
    'Thursday 12 November 2026',
    'at 6pm in the church.',
    'New members welcome.',
];

$leftLines = [];
$rightLines = [];

foreach ($leftColumn as $index => $text) {
    $leftLines[] = new TextLine($text, 60, 760 - ($index * 18), 11);
}

foreach ($rightColumn as $index => $text) {
    $rightLines[] = new TextLine($text, 320, 760 - ($index * 18), 11);
}

writeFixture('two-column-bulletin.pdf', [array_merge($leftLines, $rightLines)]);

// ---------------------------------------------------------------------------
// 3. A three-column bulletin that also carries a sensitive section. Section
//    skipping must drop it from PDF text exactly as it does from email text.
// ---------------------------------------------------------------------------
$columns = [
    [
        'Diary',
        'Parish Quiz Evening',
        'Friday 20 November 2026',
        'at 7pm in the parish hall.',
    ],
    [
        'Mass intentions',
        'For the intentions of the',
        'parish community this week.',
        'Enquiries to the parish office.',
    ],
    [
        'Diary',
        'Advent Preparation Evening',
        'Tuesday 24 November 2026',
        'at 7pm in the church hall.',
    ],
];

$threeColumnLines = [];

foreach ($columns as $columnIndex => $column) {
    foreach ($column as $lineIndex => $text) {
        $threeColumnLines[] = new TextLine($text, 50 + ($columnIndex * 180), 760 - ($lineIndex * 18), 11);
    }
}

writeFixture('three-column-bulletin.pdf', [$threeColumnLines]);

// ---------------------------------------------------------------------------
// 4. A scanned notice: no text layer at all. This must be recorded as
//    "no text layer" rather than silently returning nothing.
// ---------------------------------------------------------------------------
writeFixture('image-only.pdf', [[]]);

// ---------------------------------------------------------------------------
// 5. Eleven pages, one more than the default limit. The page count must be
//    readable without extracting any text, so this must never be parsed.
// ---------------------------------------------------------------------------
$overPageLimit = [];

for ($page = 1; $page <= 11; ++$page) {
    $overPageLimit[] = [
        new TextLine('Bulletin page ' . $page . ' of 11', 60, 760, 12),
        new TextLine('Sample filler content for page ' . $page . '.', 60, 730, 10),
    ];
}

writeFixture('over-page-limit.pdf', $overPageLimit);

// ---------------------------------------------------------------------------
// 6. A bulletin that switches from one column to two partway down the page.
//    Reading a single column count for the whole page interleaves the top of
//    the page, and reading the x axis alone loses the full-width heading.
// ---------------------------------------------------------------------------
writeFixture('column-switch-bulletin.pdf', [[
    new TextLine('Parish Newsletter for November 2026', 60, 760, 12),
    new TextLine('The annual Mass intention list is on the table.', 200, 742, 10),
    new TextLine('Confirmation Day', 60, 700, 11),
    new TextLine('Sunday 8 November 2026', 320, 700, 11),
    new TextLine('at 10am in the hall.', 60, 688, 11),
    new TextLine('at 6pm in the church.', 320, 688, 11),
]]);

// ---------------------------------------------------------------------------
// 7. Two columns of diary notices with a Mass times table running across the
//    gutter between them. The table rows cross the column boundary, so they
//    have to be read whole rather than split at the gutter. The columns matter:
//    a table on its own is a four-column layout, not a table.
// ---------------------------------------------------------------------------
writeFixture('cross-column-table.pdf', [[
    new TextLine('Diary', 60, 700, 11),
    new TextLine('Choir Rehearsal', 320, 700, 11),
    new TextLine('Confirmation Day', 60, 688, 11),
    new TextLine('Thursday 12 November', 320, 688, 11),
    new TextLine('Mass times', 60, 650, 12),
    new TextLine('Monday', 60, 638, 11),
    new TextLine('5:45am', 200, 638, 11),
    new TextLine('Wednesday', 330, 638, 11),
    new TextLine('9:00am', 470, 638, 11),
    new TextLine('Tuesday', 60, 626, 11),
    new TextLine('6:30am', 200, 626, 11),
    new TextLine('Thursday', 330, 626, 11),
    new TextLine('6:00pm', 470, 626, 11),
]]);

// ---------------------------------------------------------------------------
// 8. A vertical caption in the margin, turned a quarter turn counter-clockwise.
//    Its runs share one x position and step through y, so a naive read shreds
//    the caption into one word per line and reverses it.
// ---------------------------------------------------------------------------
writeFixture('rotated-caption.pdf', [[
    new TextLine('Confirmation Day', 60, 700, 11),
    new TextLine('Sunday 8 November 2026', 60, 688, 11),
    new TextLine('Retreat', 520, 700, 11, 90),
    new TextLine('programme', 520, 740, 11, 90),
]]);

// ---------------------------------------------------------------------------
// 9. A boxed sidebar whose list is indented under its heading. The indent is a
//    nested list, not a second column.
// ---------------------------------------------------------------------------
writeFixture('boxed-sidebar.pdf', [[
    new TextLine('Confirmation Day', 60, 760, 11),
    new TextLine('Prayer group', 380, 760, 11),
    new TextLine('Sunday 8 November 2026', 60, 748, 11),
    new TextLine('* Youth group', 440, 748, 11),
    new TextLine('at 10am in the hall.', 60, 736, 11),
    new TextLine('* Marriage preparation', 440, 736, 11),
]]);

echo "Done.\n";
