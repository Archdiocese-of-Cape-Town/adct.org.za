<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ocr;

/**
 * One poster that produced no usable text, and why.
 *
 * Surfaced on the Manual parser screen so an operator knows a poster was
 * received but never read, instead of silently getting fewer candidates.
 */
final readonly class OcrExtractionNotice
{
    public function __construct(
        public int $attachmentId,
        public string $filename,
        public string $noticeKey,
        public string $reason,
    ) {
    }
}