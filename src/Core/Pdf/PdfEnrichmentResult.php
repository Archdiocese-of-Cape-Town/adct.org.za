<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Pdf;

use ADCT\ParishIntake\Core\Parsing\Input\Message;

/**
 * The message after PDF enrichment, plus anything the operator should know.
 */
final readonly class PdfEnrichmentResult
{
    /**
     * @param list<PdfExtractionNotice> $notices
     */
    private function __construct(
        public Message $message,
        public bool $enriched,
        public array $notices,
    ) {
    }

    /**
     * @param list<PdfExtractionNotice> $notices
     */
    public static function unchanged(Message $message, array $notices = []): self
    {
        return new self($message, false, $notices);
    }

    /**
     * @param list<PdfExtractionNotice> $notices
     */
    public static function enriched(Message $message, array $notices = []): self
    {
        return new self($message, true, $notices);
    }
}
