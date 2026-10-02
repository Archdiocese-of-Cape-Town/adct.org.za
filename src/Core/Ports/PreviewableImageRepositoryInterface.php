<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ports;

use ADCT\ParishIntake\Core\Attachments\PreviewableImage;

/**
 * Reads stored inbound images that may be shown to a browser for on-demand
 * client-side OCR (ADR 0018).
 *
 * Implementations must only return rows whose file is actually stored, is no
 * larger than the attachment storage cap, and has a browser-readable image type.
 */
interface PreviewableImageRepositoryInterface
{
    /**
     * The image with this id, or null when it is unknown, not an image, not
     * stored, or too large to preview.
     */
    public function findById(int $attachmentId): ?PreviewableImage;

    /**
     * The browser-readable images belonging to one message, oldest first.
     *
     * @return list<PreviewableImage>
     */
    public function forMessage(int $messageId): array;
}
