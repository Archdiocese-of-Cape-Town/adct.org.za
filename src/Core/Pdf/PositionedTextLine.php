<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Pdf;

/**
 * A single reconstructed line of text, with the geometry needed to decide
 * whether a vertical gap is a paragraph break.
 */
final readonly class PositionedTextLine
{
    public function __construct(
        public string $text,
        public float $y,
        public float $height,
    ) {
    }
}
