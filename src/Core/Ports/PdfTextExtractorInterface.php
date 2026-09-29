<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ports;

use ADCT\ParishIntake\Core\Pdf\PdfExtractionLimits;
use ADCT\ParishIntake\Core\Pdf\PdfExtractionResult;

/**
 * Reads the selectable text layer from a PDF attachment.
 *
 * Implementations must never throw for a malformed or hostile file: every
 * failure is returned as a PdfExtractionResult so the caller can record a
 * visible reason and carry on with the rest of the message.
 */
interface PdfTextExtractorInterface
{
    public function extract(string $absolutePath, PdfExtractionLimits $limits): PdfExtractionResult;
}
