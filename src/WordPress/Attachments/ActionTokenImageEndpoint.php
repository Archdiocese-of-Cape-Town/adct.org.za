<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Attachments;

use ADCT\ParishIntake\Core\Attachments\CandidateSourceImageResolver;
use ADCT\ParishIntake\Core\Attachments\PreviewableImage;
use ADCT\ParishIntake\Core\Auth\ActionTokenService;
use ADCT\ParishIntake\Core\Auth\ActionTokenStatus;
use ADCT\ParishIntake\Core\Ports\PreviewableImageRepositoryInterface;
use ADCT\ParishIntake\WordPress\Ingestion\ProtectedInboundMailStorage;
use Throwable;

/**
 * Streams the poster image behind an emailed action-token page (ADR 0018).
 *
 * These pages are public, so there is no capability to check and the live
 * action token is the authorisation. The requested image must be the one
 * attached to the message the token's own candidate came from, so a token can
 * never be pointed at any other stored file.
 *
 * Reading an image never consumes the token. That keeps ADR 0004's
 * GET-shows / POST-acts model intact: a reviewer can look at the poster as often
 * as they like and still have exactly one chance to decide.
 */
final class ActionTokenImageEndpoint
{
    public const IMAGE_PARAM = 'adct_token_image';

    private const CONTENT_SECURITY_POLICY = "default-src 'none'; img-src 'self'; style-src 'self' 'unsafe-inline'";

    /**
     * The token states that can still render their own preview page. USED is
     * included because an approved or rejected candidate is still shown to its
     * reviewer afterwards.
     */
    private const VIEWABLE_STATUSES = [
        ActionTokenStatus::VALID,
        ActionTokenStatus::USED,
    ];

    public function __construct(
        private readonly ActionTokenService $tokens,
        private readonly CandidateSourceImageResolver $resolver,
        private readonly PreviewableImageRepositoryInterface $images,
        private readonly ProtectedInboundMailStorage $storage
    ) {
    }

    /**
     * The image id this request asks for, or 0 when it does not ask for one.
     */
    public static function requestedAttachmentId(): int
    {
        $raw = $_GET[self::IMAGE_PARAM] ?? null;

        if (! is_string($raw) || preg_match('/\A[0-9]{1,18}\z/', $raw) !== 1) {
            return 0;
        }

        return (int) $raw;
    }

    /**
     * The image a live token is allowed to see, or null when the token cannot
     * show its page any more, or does not belong to the requested image.
     *
     * The image is available in exactly the states the preview page is
     * (VALID, and USED for the approve/reject recovery flow), so an image can
     * never outlive the page that links to it.
     *
     * Reading an image never consumes the token, so a reviewer can look at the
     * poster as often as they like and still have exactly one chance to decide.
     */
    public function allowedImage(string $token, int $attachmentId): ?PreviewableImage
    {
        if ($attachmentId < 1) {
            return null;
        }

        $image = $this->imageForToken($token);

        return $image !== null && $image->attachmentId === $attachmentId ? $image : null;
    }

    /**
     * The single image this token's own candidate came from, or null when the
     * token cannot show its page or the message had no browser-readable image.
     */
    public function imageForToken(string $token): ?PreviewableImage
    {
        if ($token === '') {
            return null;
        }

        try {
            $inspection = $this->tokens->inspect($token);
        } catch (Throwable) {
            return null;
        }

        if (! in_array($inspection->status, self::VIEWABLE_STATUSES, true)) {
            return null;
        }

        if ($inspection->binding === null) {
            return null;
        }

        $source = $this->resolver->forBinding($inspection->binding);

        return $source === null ? null : $this->images->findById($source->attachmentId);
    }

    public function resolvePath(PreviewableImage $image): ?string
    {
        try {
            return $this->storage->resolveAttachmentPath($image->storageName);
        } catch (Throwable) {
            return null;
        }
    }

    public function send(PreviewableImage $image, string $path): never
    {
        if (! headers_sent()) {
            nocache_headers();
            header('Content-Type: ' . $image->mimeType);
            header('Content-Disposition: inline; filename="poster-' . $this->safeSuffix($image) . '"');
            header('Content-Security-Policy: ' . self::CONTENT_SECURITY_POLICY);
            header('X-Content-Type-Options: nosniff');
            header('Content-Length: ' . (string) (filesize($path) ?: 0));
        }

        readfile($path);
        exit;
    }

    private function safeSuffix(PreviewableImage $image): string
    {
        return match ($image->mimeType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'img',
        };
    }
}
