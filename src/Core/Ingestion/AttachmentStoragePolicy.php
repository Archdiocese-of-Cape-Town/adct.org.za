<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ingestion;

final class AttachmentStoragePolicy
{
    public const MAX_ATTACHMENT_SIZE_BYTES = 15 * 1024 * 1024;

    public const STATUS_PENDING = 'pending';
    public const STATUS_SKIPPED_SIZE = 'skipped_size';
    public const STATUS_SKIPPED_TYPE = 'skipped_type';
    public const STATUS_SKIPPED_SIGNATURE = 'skipped_signature';

    /**
     * The keys are MIME types accepted for storage and each value is the safe file extension.
     *
     * @var array<string, string>
     */
    public const ALLOWED_MIME_TYPES = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/heic' => 'heic',
        'image/heif' => 'heif',
    ];

    public function prepare(RawEmailAttachment $attachment): PreparedEmailAttachment
    {
        $filename = $this->safeFilename($attachment->filename);
        $mimeType = $this->normalizeMimeType($attachment->mimeType);
        $sizeBytes = strlen($attachment->content);
        $contentHash = hash('sha256', $attachment->content);

        if ($sizeBytes > self::MAX_ATTACHMENT_SIZE_BYTES) {
            return new PreparedEmailAttachment(
                $filename,
                $mimeType,
                $sizeBytes,
                $contentHash,
                self::STATUS_SKIPPED_SIZE,
                null,
                $attachment->content
            );
        }

        $extension = self::ALLOWED_MIME_TYPES[$mimeType] ?? null;

        if ($extension === null) {
            return new PreparedEmailAttachment(
                $filename,
                $mimeType,
                $sizeBytes,
                $contentHash,
                self::STATUS_SKIPPED_TYPE,
                null,
                $attachment->content
            );
        }

        if (! $this->signatureMatches($mimeType, $attachment->content)) {
            return new PreparedEmailAttachment(
                $filename,
                $mimeType,
                $sizeBytes,
                $contentHash,
                self::STATUS_SKIPPED_SIGNATURE,
                null,
                $attachment->content
            );
        }

        return new PreparedEmailAttachment(
            $filename,
            $mimeType,
            $sizeBytes,
            $contentHash,
            self::STATUS_PENDING,
            $extension,
            $attachment->content
        );
    }

    private function normalizeMimeType(string $mimeType): string
    {
        $type = strtolower(trim(explode(';', $mimeType, 2)[0]));

        return $type !== '' ? $type : 'application/octet-stream';
    }

    private function safeFilename(string $filename): string
    {
        $filename = basename(str_replace('\\', '/', $filename));
        $filename = preg_replace('/[\x00-\x1F\x7F]/u', '', $filename) ?? '';
        $filename = trim($filename);

        if ($filename === '' || $filename === '.' || $filename === '..') {
            return 'attachment';
        }

        if (preg_match('/\A.{0,191}/us', $filename, $matches) === 1) {
            return $matches[0];
        }

        return substr($filename, 0, 191);
    }

    private function signatureMatches(string $mimeType, string $content): bool
    {
        return match ($mimeType) {
            'application/pdf' => str_starts_with($content, '%PDF-'),
            'image/jpeg' => str_starts_with($content, "\xFF\xD8\xFF"),
            'image/png' => str_starts_with($content, "\x89PNG\r\n\x1A\n"),
            'image/webp' => strlen($content) >= 12
                && substr($content, 0, 4) === 'RIFF'
                && substr($content, 8, 4) === 'WEBP',
            'image/heic' => $this->matchesBrands($content, ['heic', 'heix', 'hevc', 'hevx']),
            'image/heif' => $this->matchesBrands($content, ['mif1', 'msf1']),
            default => false,
        };
    }

    /**
     * @param list<string> $brands
     */
    private function matchesBrands(string $content, array $brands): bool
    {
        return strlen($content) >= 12
            && substr($content, 4, 4) === 'ftyp'
            && in_array(substr($content, 8, 4), $brands, true);
    }
}
