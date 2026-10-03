<?php

declare(strict_types=1);

/**
 * Generates the synthetic poster fixtures used by the E8.3 OCR tests.
 *
 * Run once with:  php tests/fixtures/posters/generate.php
 * The generated files are committed so the test suite stays hermetic and fast.
 *
 * Every fixture is invented for this repository. No real parish poster, name,
 * address or telephone number is ever copied into a fixture (POPIA). The
 * fixture also carries no EXIF or GPS metadata: it is written byte by byte
 * here, so there is nowhere for such data to hide.
 *
 * The image is a real PNG, not a stub. It is assembled from raw chunks with
 * `gzcompress()` because the PHP image extension is not guaranteed to be
 * present (and is deliberately absent from the CI image), so the fixture
 * cannot depend on GD being installed.
 *
 * The visible text is invented poster copy. It exists so a human opening the
 * fixture can see what it depicts; the tests never OCR the real pixels. They
 * replay a recorded OCR.space response, because a test suite must not depend
 * on a third-party service or on a local Tesseract install.
 */

/** @var list<string> $argv */
$outputDirectory = __DIR__;

if (! is_dir($outputDirectory) || ! is_writable($outputDirectory)) {
    fwrite(STDERR, "Fixture directory is not writable: {$outputDirectory}\n");
    exit(1);
}

/**
 * A 5x7 bitmap font, one entry per supported glyph.
 *
 * Each glyph is seven rows of five columns, most significant bit on the left.
 * Only the characters used by the poster copy below are defined; anything else
 * renders as a blank space, which keeps the generator small and obvious.
 *
 * @var array<string, list<int>> $glyphs
 */
$glyphs = [
    ' ' => [0b00000, 0b00000, 0b00000, 0b00000, 0b00000, 0b00000, 0b00000],
    'A' => [0b01110, 0b10001, 0b10001, 0b11111, 0b10001, 0b10001, 0b10001],
    'C' => [0b01110, 0b10001, 0b10000, 0b10000, 0b10000, 0b10001, 0b01110],
    'D' => [0b11110, 0b10001, 0b10001, 0b10001, 0b10001, 0b10001, 0b11110],
    'E' => [0b11111, 0b10000, 0b10000, 0b11110, 0b10000, 0b10000, 0b11111],
    'G' => [0b01110, 0b10001, 0b10000, 0b10111, 0b10001, 0b10001, 0b01111],
    'H' => [0b10001, 0b10001, 0b10001, 0b11111, 0b10001, 0b10001, 0b10001],
    'I' => [0b01110, 0b00100, 0b00100, 0b00100, 0b00100, 0b00100, 0b01110],
    'L' => [0b10000, 0b10000, 0b10000, 0b10000, 0b10000, 0b10000, 0b11111],
    'M' => [0b10001, 0b11011, 0b10101, 0b10101, 0b10001, 0b10001, 0b10001],
    'N' => [0b10001, 0b11001, 0b10101, 0b10011, 0b10001, 0b10001, 0b10001],
    'O' => [0b01110, 0b10001, 0b10001, 0b10001, 0b10001, 0b10001, 0b01110],
    'P' => [0b11110, 0b10001, 0b10001, 0b11110, 0b10000, 0b10000, 0b10000],
    'R' => [0b11110, 0b10001, 0b10001, 0b11110, 0b10100, 0b10010, 0b10001],
    'S' => [0b01111, 0b10000, 0b10000, 0b01110, 0b00001, 0b00001, 0b11110],
    'T' => [0b11111, 0b00100, 0b00100, 0b00100, 0b00100, 0b00100, 0b00100],
    'U' => [0b10001, 0b10001, 0b10001, 0b10001, 0b10001, 0b10001, 0b01110],
    'X' => [0b10001, 0b10001, 0b01010, 0b00100, 0b01010, 0b10001, 0b10001],
    '-' => [0b00000, 0b00000, 0b00000, 0b11111, 0b00000, 0b00000, 0b00000],
    '.' => [0b00000, 0b00000, 0b00000, 0b00000, 0b00000, 0b00000, 0b00100],
    '/' => [0b00001, 0b00010, 0b00010, 0b00100, 0b01000, 0b01000, 0b10000],
    '0' => [0b01110, 0b10001, 0b10011, 0b10101, 0b11001, 0b10001, 0b01110],
    '1' => [0b00100, 0b01100, 0b00100, 0b00100, 0b00100, 0b00100, 0b01110],
    '2' => [0b01110, 0b10001, 0b00001, 0b00110, 0b01000, 0b10000, 0b11111],
    '3' => [0b11111, 0b00010, 0b00100, 0b00010, 0b00001, 0b10001, 0b01110],
    '4' => [0b00010, 0b00110, 0b01010, 0b10010, 0b11111, 0b00010, 0b00010],
    '5' => [0b11111, 0b10000, 0b11110, 0b00001, 0b00001, 0b10001, 0b01110],
    '6' => [0b00110, 0b01000, 0b10000, 0b11110, 0b10001, 0b10001, 0b01110],
    '7' => [0b11111, 0b00001, 0b00010, 0b00100, 0b01000, 0b01000, 0b01000],
    '8' => [0b01110, 0b10001, 0b10001, 0b01110, 0b10001, 0b10001, 0b01110],
    '9' => [0b01110, 0b10001, 0b10001, 0b01111, 0b00001, 0b00010, 0b01100],
];

$glyphWidth = 5;
$glyphHeight = 7;

/**
 * Wraps one of the PNG chunks.
 */
function pngChunk(string $type, string $data): string
{
    return pack('N', strlen($data))
        . $type
        . $data
        . pack('N', crc32($type . $data));
}

/**
 * Builds an 8-bit truecolour PNG from a pixel grid.
 *
 * @param list<list<array{int, int, int}>> $rows
 */
function buildPng(array $rows): string
{
    $height = count($rows);
    $width = count($rows[0]);

    // Every scanline is prefixed with filter type 0 (none), which keeps the
    // encoder trivial and the output byte-for-byte reproducible.
    $scanlines = '';
    foreach ($rows as $row) {
        $scanlines .= "\x00";
        foreach ($row as [$red, $green, $blue]) {
            $scanlines .= chr($red) . chr($green) . chr($blue);
        }
    }

    return "\x89PNG\r\n\x1a\n"
        . pngChunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0))
        . pngChunk('IDAT', gzcompress($scanlines, 9))
        . pngChunk('IEND', '');
}

/**
 * A blank canvas in the given colour.
 *
 * @param array{int, int, int} $colour
 *
 * @return list<list<array{int, int, int}>>
 */
function blankCanvas(int $width, int $height, array $colour): array
{
    $row = array_fill(0, $width, $colour);

    return array_fill(0, $height, $row);
}

/**
 * Draws centred text lines onto a canvas, returning a new canvas.
 *
 * @param list<string> $lines
 * @param list<list<array{int, int, int}>> $rows
 * @param array<string, list<int>> $glyphs
 * @param array{int, int, int} $colour
 *
 * @return list<list<array{int, int, int}>>
 */
function drawPoster(
    array $lines,
    array $rows,
    array $glyphs,
    array $colour,
    int $glyphWidth,
    int $glyphHeight
): array {
    $height = count($rows);
    $width = count($rows[0]);

    // Scale the type up so the fixture reads as a poster rather than as a
    // terminal. Three pixels per font pixel keeps the 5x7 glyphs legible.
    $scale = 3;
    $lineHeight = ($glyphHeight + 4) * $scale;

    $top = intdiv($height - (count($lines) * $lineHeight), 2);

    foreach ($lines as $index => $line) {
        $characterCount = strlen($line);
        $lineWidth = $characterCount * ($glyphWidth + 1) * $scale;
        $left = intdiv($width - $lineWidth, 2);
        $y = $top + ($index * $lineHeight);

        for ($character = 0; $character < $characterCount; ++$character) {
            $glyph = $glyphs[strtoupper($line[$character])] ?? $glyphs[' '];

            for ($row = 0; $row < $glyphHeight; ++$row) {
                $bits = $glyph[$row];

                for ($column = 0; $column < $glyphWidth; ++$column) {
                    $on = ($bits >> ($glyphWidth - 1 - $column)) & 1;
                    if ($on !== 1) {
                        continue;
                    }

                    for ($dy = 0; $dy < $scale; ++$dy) {
                        for ($dx = 0; $dx < $scale; ++$dx) {
                            $x = $left
                                + ($character * ($glyphWidth + 1) * $scale)
                                + ($column * $scale)
                                + $dx;
                            $row2 = $y + ($row * $scale) + $dy;

                            if ($x < 0 || $x >= $width || $row2 < 0 || $row2 >= $height) {
                                continue;
                            }

                            $rows[$row2][$x] = $colour;
                        }
                    }
                }
            }
        }
    }

    return $rows;
}

$posterLines = [
    'EXAMPLE PARISH',
    'PARISH RETREAT DAY',
    '12/10/2026',
    '9:00 AM - 3:00 PM',
    'EXAMPLE PARISH HALL',
    '021 555 0100',
];

$canvas = blankCanvas(620, 420, [255, 255, 255]);
$canvas = drawPoster($posterLines, $canvas, $glyphs, [16, 16, 16], $glyphWidth, $glyphHeight);

$posterPath = $outputDirectory . DIRECTORY_SEPARATOR . 'example-retreat-poster.png';
file_put_contents($posterPath, buildPng($canvas));

printf("Wrote %s (%d bytes)\n", basename($posterPath), filesize($posterPath));