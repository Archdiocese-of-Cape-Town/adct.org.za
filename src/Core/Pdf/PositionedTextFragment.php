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
    public function __construct(
        public string $text,
        public float $x,
        public float $y,
        public float $height,
    ) {
    }
}
