<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ports;

use ADCT\ParishIntake\Core\Ocr\OcrExtractionResult;
use ADCT\ParishIntake\Core\Ocr\StoredImageAttachment;

/**
 * Reads and writes the OCR outcome held on an image attachment row.
 *
 * Separate from {@see AttachmentExtractionStoreInterface} because the two
 * extract with different tools and record different methods, and because one
 * path may be configured while the other is not.
 */
interface ImageExtractionStoreInterface
{
    /**
     * The image attachments belonging to one message that OCR could still read,
     * oldest first.
     *
     * @return list<StoredImageAttachment>
     */
    public function findPendingImagesForMessage(int $messageId): array;

    /**
     * Records the outcome, including a skip or a failure, so the attachment row
     * always says why it produced no text.
     */
    public function recordResult(int $attachmentId, OcrExtractionResult $result): void;
}