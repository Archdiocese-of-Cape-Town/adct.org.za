<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Ocr;

use ADCT\ParishIntake\Core\Ocr\OcrExtractionResult;
use ADCT\ParishIntake\Core\Ocr\StoredImageAttachment;
use ADCT\ParishIntake\Core\Ports\ClockInterface;
use ADCT\ParishIntake\Core\Ports\ImageExtractionStoreInterface;
use ADCT\ParishIntake\WordPress\Database\Repository\AttachmentRepository;
use InvalidArgumentException;

/**
 * Reads and writes the OCR outcome on an image attachment row.
 *
 * A skip is recorded as deliberately as a success. Without it the next cron
 * tick would meet the same poster in the same state and try to send it again,
 * so a poster that is too big, or that arrived while the daily cap was spent,
 * would be re-offered to a paid third party every two hours for as long as the
 * message stayed unread.
 */
final class WordPressImageOcrExtractionStore implements ImageExtractionStoreInterface
{
    public function __construct(
        private readonly AttachmentRepository $attachments,
        private readonly ClockInterface $clock,
    ) {
    }

    public function findPendingImagesForMessage(int $messageId): array
    {
        if ($messageId < 1) {
            throw new InvalidArgumentException('A message ID must be positive.');
        }

        $pending = [];

        foreach ($this->attachments->findPendingImagesForMessage($messageId) as $row) {
            $attachment = new StoredImageAttachment(
                (int) ($row['id'] ?? 0),
                (string) ($row['filename'] ?? ''),
                (string) ($row['storage_path'] ?? ''),
                (string) ($row['status'] ?? ''),
                (string) ($row['extraction_method'] ?? OcrExtractionResult::METHOD_NONE),
                (int) ($row['size_bytes'] ?? 0),
                (string) ($row['mime_type'] ?? ''),
            );

            if ($attachment->needsExtraction()) {
                $pending[] = $attachment;
            }
        }

        return $pending;
    }

    public function recordResult(int $attachmentId, OcrExtractionResult $result): void
    {
        if ($attachmentId < 1) {
            throw new InvalidArgumentException('An attachment ID must be positive.');
        }

        $this->attachments->update($attachmentId, [
            'extracted_text' => $result->isExtracted() ? $result->text : null,
            'extraction_method' => $result->method,
            'status' => $result->status,
            'updated_at' => $this->clock->now()->format('Y-m-d H:i:s'),
        ]);
    }
}