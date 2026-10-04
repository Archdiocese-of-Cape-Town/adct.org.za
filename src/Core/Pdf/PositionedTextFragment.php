<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Pdf;

/**
 * One run of text at an absolute position on a page, in PDF user-space points.
 *
 * This is the library-independent shape the column assembler works on, so the
 * reading order can be tested without parsing a PDF.
 */
final readonly class PositionedTextFragment
{
    /**
     * @param float $width  advance width in points, measured from the font's own
     *                      glyph metrics. Zero when it is not known, in which case
     *                      the assembler falls back to an estimate from the
     *                      length of the text.
     * @param float $angle  rotation of the run in degrees, measured from the
     *                      horizontal, in the range (-180, 180].
     */
    public function __construct(
        public string $text,
        public float $x,
        public float $y,
        public float $height,
        public float $width = 0.0,
        public float $angle = 0.0,
    ) {
    }

    /**
     * The right-hand edge of the run.
     */
    public function rightEdge(): float
    {
        return $this->x + $this->width;
    }
}
