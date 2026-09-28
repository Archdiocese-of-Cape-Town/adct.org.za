<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Pdf;

use ADCT\ParishIntake\Core\Pdf\PdfExtractionResult;
use ADCT\ParishIntake\Core\Pdf\StoredPdfAttachment;
use ADCT\ParishIntake\Core\Ports\AttachmentExtractionStoreInterface;
use ADCT\ParishIntake\Core\Ports\ClockInterface;
use ADCT\ParishIntake\WordPress\Database\Repository\AttachmentRepository;
use InvalidArgumentException;

/**
 * Reads and writes the extraction outcome on the attachment row.
 */
final class WordPressAttachmentExtractionStore implements AttachmentExtractionStoreInterface
{
    public function __construct(
        private readonly AttachmentRepository $attachments,
        private readonly ClockInterface $clock,
    ) {
    }

    public function findPendingPdfsForMessage(int $messageId): array
    {
        if ($messageId < 1) {
            throw new InvalidArgumentException('A message ID must be positive.');
        }

        $rows = $this->attachments->findByMessageIdAndMimeType(
            $messageId,
            PdfExtractionResult::MIME_TYPE
        );
        $pending = [];

        foreach ($rows as $row) {
            $attachment = new StoredPdfAttachment(
                (int) ($row['id'] ?? 0),
                (string) ($row['filename'] ?? ''),
                (string) ($row['storage_path'] ?? ''),
                (string) ($row['status'] ?? ''),
                (string) ($row['extraction_method'] ?? 'none')
            );

            if ($attachment->needsExtraction()) {
                $pending[] = $attachment;
            }
        }

        return $pending;
    }

    public function recordResult(int $attachmentId, PdfExtractionResult $result): void
    {
        $this->attachments->update($attachmentId, [
            'extracted_text' => $result->isExtracted() ? $result->text : null,
            'extraction_method' => $result->method,
            'status' => $result->status,
            'updated_at' => $this->clock->now()->format('Y-m-d H:i:s'),
        ]);
    }
}
