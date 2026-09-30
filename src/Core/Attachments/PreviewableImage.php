<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Attachments;

use InvalidArgumentException;

/**
 * A stored inbound attachment that a browser can display and that on-demand
 * client-side OCR can read (ADR 0018).
 *
 * Only the raster formats a browser can decode are previewable. PDF is handled
 * by the server-side text extraction of ADR 0015, and HEIC/HEIF are on the
 * storage allowlist but cannot be rendered by browsers in general, so they fall
 * back to manual entry.
 */
final class PreviewableImage
{
    /**
     * Mirrors the attachment storage cap, so an oversized row can never be
     * streamed to a browser.
     */
    public const MAX_SIZE_BYTES = 15 * 1024 * 1024;

    /**
     * The storage file name is always a `random_bytes`-derived 64 character hex
     * string plus a known extension. Validating it here keeps a crafted row
     * from pointing an image request at anything else in the private directory.
     */
    private const STORAGE_NAME_PATTERN = '/\A[a-f0-9]{64}\.(?:jpg|png|webp)\z/';

    /**
     * The extension each accepted image type is stored under.
     *
     * @var array<string, string>
     */
    private const MIME_TYPES = [
        'jpg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
    ];

    public readonly string $mimeType;

    public function __construct(
        public readonly int $attachmentId,
        public readonly int $messageId,
        public readonly string $filename,
        public readonly string $storageName,
        string $mimeType,
        public readonly int $sizeBytes
    ) {
        if ($attachmentId < 1 || $messageId < 1) {
            throw new InvalidArgumentException('The previewable image identifiers must be positive.');
        }

        if (preg_match(self::STORAGE_NAME_PATTERN, $storageName) !== 1) {
            throw new InvalidArgumentException('The previewable image storage name is invalid.');
        }

        $expected = self::MIME_TYPES[strtolower(pathinfo($storageName, PATHINFO_EXTENSION))] ?? null;

        if ($expected === null || strtolower(trim($mimeType)) !== $expected) {
            throw new InvalidArgumentException('The previewable image MIME type is not a browser-readable image.');
        }

        if ($sizeBytes < 1 || $sizeBytes > self::MAX_SIZE_BYTES) {
            throw new InvalidArgumentException('The previewable image size is outside the permitted range.');
        }

        $this->mimeType = $expected;
    }
}
