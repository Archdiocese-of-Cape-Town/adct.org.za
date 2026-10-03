<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ocr;

/**
 * The outcome of one OCR attempt on one poster.
 *
 * Every failure mode is represented as data rather than as an exception, so a
 * caller can never be surprised by a throw and can always record a visible
 * reason on the attachment row.
 *
 * Both `status` and `method` are written straight to the attachment table, so
 * they are kept inside the column widths there: `status` is varchar(20) and
 * `extraction_method` is varchar(32).
 */
final readonly class OcrExtractionResult
{
    /** Text read from the image by an external OCR service. */
    public const METHOD_OCR_EXTERNAL = 'ocr_external';

    /**
     * Text read from the image by a vision model.
     *
     * Not used yet: wiring a vision model is the phase-2 optional AI work in
     * #14. It is recorded here so a row written by that path stays readable by
     * everything that already knows about `extraction_method`.
     */
    public const METHOD_AI_VISION = 'ai_vision';

    /** No method produced text. */
    public const METHOD_NONE = 'none';

    /** Text was read from the poster. */
    public const STATUS_EXTRACTED = 'extracted';

    /** The poster is larger than the size limit and was never opened. */
    public const STATUS_SKIPPED_SIZE = 'skipped_size';

    /** The poster's type is not one the OCR service is asked about. */
    public const STATUS_SKIPPED_TYPE = 'skipped_type';

    /** The attempt ran past the time budget and was abandoned. */
    public const STATUS_SKIPPED_TIMEOUT = 'skipped_timeout';

    /** The service refused: a rate limit, or the daily cap was already spent. */
    public const STATUS_SKIPPED_RATE_LIMIT = 'skipped_rate_limit';

    /** The attempt could not be made or was refused: no key, no file, an error reply. */
    public const STATUS_FAILED = 'failed';

    /** The service answered, but the poster held no readable words. */
    public const STATUS_NO_TEXT = 'no_text';

    /** OCR is switched off, or no API key is configured, so nothing was sent. */
    public const STATUS_NOT_CONFIGURED = 'not_configured';

    private function __construct(
        public string $status,
        public string $method,
        public string $text,
        public ?string $reason = null,
    ) {
    }

    public static function extracted(string $text, string $method = self::METHOD_OCR_EXTERNAL): self
    {
        return new self(self::STATUS_EXTRACTED, $method, $text);
    }

    public static function skippedSize(int $sizeBytes, int $maxBytes): self
    {
        return new self(
            self::STATUS_SKIPPED_SIZE,
            self::METHOD_NONE,
            '',
            sprintf('The poster is %s and the limit is %s.', self::humanBytes($sizeBytes), self::humanBytes($maxBytes))
        );
    }

    public static function skippedType(string $mimeType): self
    {
        return new self(
            self::STATUS_SKIPPED_TYPE,
            self::METHOD_NONE,
            '',
            sprintf('OCR is not offered a %s attachment.', $mimeType === '' ? 'unknown' : $mimeType)
        );
    }

    public static function skippedTimeout(int $timeoutSeconds): self
    {
        return new self(
            self::STATUS_SKIPPED_TIMEOUT,
            self::METHOD_NONE,
            '',
            sprintf('Reading the poster took longer than the %d second budget.', $timeoutSeconds)
        );
    }

    public static function rateLimited(string $reason): self
    {
        return new self(self::STATUS_SKIPPED_RATE_LIMIT, self::METHOD_NONE, '', $reason);
    }

    public static function noTextFound(): self
    {
        return new self(
            self::STATUS_NO_TEXT,
            self::METHOD_NONE,
            '',
            'No words could be read from the poster. It needs manual entry.'
        );
    }

    public static function notConfigured(): self
    {
        return new self(
            self::STATUS_NOT_CONFIGURED,
            self::METHOD_NONE,
            '',
            'OCR is not set up, so the poster was not read. It needs manual entry.'
        );
    }

    public static function failed(string $reason): self
    {
        return new self(self::STATUS_FAILED, self::METHOD_NONE, '', $reason);
    }

    public function isExtracted(): bool
    {
        return $this->status === self::STATUS_EXTRACTED;
    }

    /**
     * True when an operator needs to know that this poster produced nothing.
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
            self::STATUS_SKIPPED_SIZE => 'ocr_skipped_size',
            self::STATUS_SKIPPED_TYPE => 'ocr_skipped_type',
            self::STATUS_SKIPPED_TIMEOUT => 'ocr_skipped_timeout',
            self::STATUS_SKIPPED_RATE_LIMIT => 'ocr_rate_limited',
            self::STATUS_NO_TEXT => 'ocr_no_text',
            self::STATUS_NOT_CONFIGURED => 'ocr_not_configured',
            self::STATUS_FAILED => 'ocr_failed',
            default => 'ocr_extracted',
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