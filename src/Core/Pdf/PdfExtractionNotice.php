<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Pdf;

/**
 * One attachment that produced no usable text, and why.
 *
 * Surfaced on the Manual parser screen so an operator knows a poster was
 * received but never read, instead of silently getting fewer candidates.
 */
final readonly class PdfExtractionNotice
{
    public function __construct(
        public int $attachmentId,
        public string $filename,
        public string $noticeKey,
        public string $reason,
    ) {
    }
}
