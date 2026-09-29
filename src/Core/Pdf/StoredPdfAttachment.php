<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Pdf;

/**
 * The PDF attachments stored for one message, and the outcome of extracting each.
 */
final readonly class StoredPdfAttachment
{
    public function __construct(
        public int $id,
        public string $filename,
        public string $storagePath,
        public string $status,
        public string $extractionMethod,
    ) {
    }

    /**
     * True when this attachment is worth extracting: it is a PDF that has not
     * already been read.
     */
    public function needsExtraction(): bool
    {
        return $this->status === 'pending' && $this->extractionMethod === 'none';
    }
}
