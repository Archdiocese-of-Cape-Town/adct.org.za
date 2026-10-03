<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ocr;

/**
 * An image attachment stored for one message, as OCR sees it.
 */
final readonly class StoredImageAttachment
{
    public function __construct(
        public int $id,
        public string $filename,
        public string $storagePath,
        public string $status,
        public string $extractionMethod,
        public int $sizeBytes,
        public string $mimeType,
    ) {
    }

    /**
     * True when this attachment is worth reading: it is an image that has not
     * already been read, by OCR or anything else.
     */
    public function needsExtraction(): bool
    {
        return $this->status === 'pending' && $this->extractionMethod === 'none';
    }
}