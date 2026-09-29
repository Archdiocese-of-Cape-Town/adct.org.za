<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Attachments;

use ADCT\ParishIntake\Core\Attachments\PreviewableImage;
use ADCT\ParishIntake\Core\Ingestion\AttachmentStoragePolicy;
use ADCT\ParishIntake\Core\Ports\PreviewableImageRepositoryInterface;
use ADCT\ParishIntake\WordPress\Database\Repository\AttachmentRepository;
use Throwable;

/**
 * Resolves stored inbound images that may be shown to a browser.
 *
 * A row only becomes a `PreviewableImage` when the storage name is one this
 * plugin wrote, the declared type matches the extension, the size is within the
 * storage cap, and the file is actually present. Anything else yields null, so
 * a crafted or skipped row can never become a browser request.
 */
final class WordPressPreviewableImageRepository implements PreviewableImageRepositoryInterface
{
    public function __construct(
        private readonly AttachmentRepository $attachments
    ) {
    }

    public function findById(int $attachmentId): ?PreviewableImage
    {
        if ($attachmentId < 1) {
            return null;
        }

        try {
            $row = $this->attachments->findStoredById($attachmentId);
        } catch (Throwable) {
            return null;
        }

        return $row === null ? null : $this->toImage($row);
    }

    public function forMessage(int $messageId): array
    {
        if ($messageId < 1) {
            return [];
        }

        try {
            $rows = $this->attachments->findStoredImagesForMessage($messageId);
        } catch (Throwable) {
            return [];
        }

        $images = [];

        foreach ($rows as $row) {
            $image = $this->toImage($row);

            if ($image !== null) {
                $images[] = $image;
            }
        }

        return $images;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function toImage(array $row): ?PreviewableImage
    {
        $storageName = (string) ($row['storage_path'] ?? '');
        $messageId = (int) ($row['message_id'] ?? 0);
        $attachmentId = (int) ($row['id'] ?? 0);

        if ($storageName === '' || $messageId < 1 || $attachmentId < 1) {
            return null;
        }

        // A row skipped for size, type or signature has no stored file and is
        // already listed for the operator as manual work.
        if (in_array((string) ($row['status'] ?? ''), [
            AttachmentStoragePolicy::STATUS_SKIPPED_SIZE,
            AttachmentStoragePolicy::STATUS_SKIPPED_TYPE,
            AttachmentStoragePolicy::STATUS_SKIPPED_SIGNATURE,
        ], true)) {
            return null;
        }

        try {
            return new PreviewableImage(
                $attachmentId,
                $messageId,
                (string) ($row['filename'] ?? ''),
                $storageName,
                (string) ($row['mime_type'] ?? ''),
                (int) ($row['size_bytes'] ?? 0)
            );
        } catch (Throwable) {
            return null;
        }
    }
}
