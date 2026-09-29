<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ports;

use ADCT\ParishIntake\Core\Pdf\PdfExtractionResult;
use ADCT\ParishIntake\Core\Pdf\StoredPdfAttachment;

/**
 * Reads and writes the extraction outcome held on an attachment row.
 */
interface AttachmentExtractionStoreInterface
{
    /**
     * The PDF attachments belonging to one message, oldest first.
     *
     * @return list<StoredPdfAttachment>
     */
    public function findPendingPdfsForMessage(int $messageId): array;

    /**
     * Records the outcome, including a skip or a failure, so the attachment row
     * always says why it produced no text.
     */
    public function recordResult(int $attachmentId, PdfExtractionResult $result): void;
}
