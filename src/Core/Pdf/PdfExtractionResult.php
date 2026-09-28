<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Pdf;

/**
 * The outcome of one PDF text extraction attempt.
 *
 * Every failure mode is represented as data rather than as an exception, so a
 * caller can never be surprised by a throw and can always record a visible
 * reason on the attachment row.
 */
final readonly class PdfExtractionResult
{
    public const METHOD_PDF_TEXT = 'pdf_text';

    public const METHOD_NONE = 'none';

    /** The only attachment type this extraction path handles. */
    public const MIME_TYPE = 'application/pdf';

    /** Text was extracted from a selectable text layer. */
    public const STATUS_EXTRACTED = 'extracted';

    /** The file parses, but holds no selectable text. OCR is the follow-up path. */
    public const STATUS_NO_TEXT_LAYER = 'no_text_layer';

    /** The file is larger than the size limit and was never opened. */
    public const STATUS_SKIPPED_SIZE = 'skipped_size';

    /** The file has more pages than the limit and was never read for text. */
    public const STATUS_SKIPPED_PAGE_LIMIT = 'skipped_page_limit';

    /** Extraction ran past the time budget and was abandoned. */
    public const STATUS_SKIPPED_TIMEOUT = 'skipped_timeout';

    /** The file could not be parsed at all: truncated, corrupt or encrypted. */
    public const STATUS_FAILED = 'failed';

    private function __construct(
        public string $status,
        public string $method,
        public string $text,
        public int $pageCount,
        public ?string $reason = null,
    ) {
    }

    public static function extracted(string $text, int $pageCount): self
    {
        return new self(self::STATUS_EXTRACTED, self::METHOD_PDF_TEXT, $text, $pageCount);
    }

    public static function noTextLayer(int $pageCount): self
    {
        return new self(
            self::STATUS_NO_TEXT_LAYER,
            self::METHOD_NONE,
            '',
            $pageCount,
            'The file has no selectable text layer. It may be a scan and needs manual entry or OCR.'
        );
    }

    public static function skippedSize(int $sizeBytes, int $maxBytes): self
    {
        return new self(
            self::STATUS_SKIPPED_SIZE,
            self::METHOD_NONE,
            '',
            0,
            sprintf('The file is %s and the limit is %s.', self::humanBytes($sizeBytes), self::humanBytes($maxBytes))
        );
    }

    public static function skippedPageLimit(int $pageCount, int $maxPages): self
    {
        return new self(
            self::STATUS_SKIPPED_PAGE_LIMIT,
            self::METHOD_NONE,
            '',
            $pageCount,
            sprintf('The file has %d pages and the limit is %d.', $pageCount, $maxPages)
        );
    }

    public static function skippedTimeout(int $pageCount, int $timeBudgetSeconds): self
    {
        return new self(
            self::STATUS_SKIPPED_TIMEOUT,
            self::METHOD_NONE,
            '',
            $pageCount,
            sprintf('Reading the file took longer than the %d second budget.', $timeBudgetSeconds)
        );
    }

    public static function failed(string $reason, int $pageCount = 0): self
    {
        return new self(self::STATUS_FAILED, self::METHOD_NONE, '', $pageCount, $reason);
    }

    public function isExtracted(): bool
    {
        return $this->status === self::STATUS_EXTRACTED;
    }

    /**
     * True when an operator needs to know that this file produced nothing.
     */
    public function needsManualAttention(): bool
    {
        return ! $this->isExtracted();
    }

    /**
     * The note key used to surface this outcome on the Manual parser screen.
     */
    public function noticeKey(): string
    {
        return match ($this->status) {
            self::STATUS_SKIPPED_SIZE => 'pdf_skipped_size',
            self::STATUS_SKIPPED_PAGE_LIMIT => 'pdf_skipped_page_limit',
            self::STATUS_NO_TEXT_LAYER => 'pdf_no_text_layer',
            self::STATUS_SKIPPED_TIMEOUT => 'pdf_skipped_timeout',
            self::STATUS_FAILED => 'pdf_extraction_failed',
            default => 'pdf_extracted',
        };
    }

    private static function humanBytes(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) {
            return sprintf('%d MB', intdiv($bytes, 1024 * 1024));
        }

        if ($bytes >= 1024) {
            return sprintf('%d kB', intdiv($bytes, 1024));
        }

        return sprintf('%d bytes', $bytes);
    }
}
