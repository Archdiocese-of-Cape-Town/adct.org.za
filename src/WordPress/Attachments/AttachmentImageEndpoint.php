<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Attachments;

use ADCT\ParishIntake\Core\Auth\Capabilities;
use ADCT\ParishIntake\Core\Ports\PreviewableImageRepositoryInterface;
use ADCT\ParishIntake\WordPress\Ingestion\ProtectedInboundMailStorage;
use Throwable;

/**
 * Streams one stored inbound image to a logged-in reviewer (ADR 0017).
 *
 * The private storage directory stays deny-all: this is the single, capability
 * checked way an image reaches a browser, and it only ever reads a file that
 * `PreviewableImage` has already validated. The response is not cached, because
 * the bytes can contain personal information from a parish bulletin.
 */
final class AttachmentImageEndpoint
{
    public const ACTION = 'adct_pi_attachment_image';
    public const ID_PARAM = 'attachment_id';

    private const NONCE_PREFIX = 'adct_pi_attachment_image_';

    /**
     * An inline image that can never run script. It permits only this origin's
     * own styles, so nothing a poster happens to contain can be interpreted.
     */
    private const CONTENT_SECURITY_POLICY = "default-src 'none'; img-src 'self'; style-src 'self' 'unsafe-inline'";

    public function __construct(
        private readonly PreviewableImageRepositoryInterface $images,
        private readonly ProtectedInboundMailStorage $storage
    ) {
    }

    public function handleRequest(): void
    {
        if (! current_user_can(Capabilities::REVIEW)) {
            wp_die(
                esc_html__('You are not allowed to view intake attachments.', 'adct-parish-intake'),
                '',
                ['response' => 403]
            );
        }

        $attachmentId = $this->requestedId();

        if ($attachmentId < 1) {
            $this->notFound();
        }

        // The nonce is bound to the id, so one reviewer's image link cannot be
        // replayed to fetch a different attachment.
        check_admin_referer(self::NONCE_PREFIX . $attachmentId);

        $image = $this->images->findById($attachmentId);

        if ($image === null) {
            $this->notFound();
        }

        try {
            $path = $this->storage->resolveAttachmentPath($image->storageName);
        } catch (Throwable) {
            $this->notFound();
        }

        $this->send($image->mimeType, $path);
    }

    public function imageUrl(int $attachmentId): string
    {
        $url = add_query_arg(
            [
                'action' => self::ACTION,
                self::ID_PARAM => $attachmentId,
            ],
            admin_url('admin-post.php')
        );

        return wp_nonce_url($url, self::NONCE_PREFIX . $attachmentId);
    }

    private function requestedId(): int
    {
        $raw = $_GET[self::ID_PARAM] ?? null;

        if (! is_string($raw) || preg_match('/\A[0-9]{1,18}\z/', $raw) !== 1) {
            return 0;
        }

        return (int) $raw;
    }

    private function send(string $mimeType, string $path): void
    {
        nocache_headers();
        header('Content-Type: ' . $mimeType);
        header('Content-Disposition: inline; filename="attachment-' . $this->safeSuffix($mimeType) . '"');
        header('Content-Security-Policy: ' . self::CONTENT_SECURITY_POLICY);
        header('X-Content-Type-Options: nosniff');
        header('Content-Length: ' . (string) (filesize($path) ?: 0));

        readfile($path);
        exit;
    }

    private function safeSuffix(string $mimeType): string
    {
        return match ($mimeType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'img',
        };
    }

    private function notFound(): never
    {
        wp_die(
            esc_html__('That attachment is not available for preview.', 'adct-parish-intake'),
            '',
            ['response' => 404]
        );
    }
}
