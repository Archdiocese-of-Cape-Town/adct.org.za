<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Attachments;

use ADCT\ParishIntake\Core\Attachments\SourceMaterialRole;
use ADCT\ParishIntake\Core\Ports\SourceMaterialCopierInterface;
use ADCT\ParishIntake\WordPress\Ingestion\ProtectedInboundMailStorage;
use RuntimeException;
use Throwable;

/**
 * Copies a stored intake attachment into the WordPress media library at the
 * moment a person promotes it (issue #172).
 *
 * Three properties of this class are load-bearing, and each is why the obvious
 * shorter version is wrong:
 *
 * 1. **The stored file name is generated here**, from a random hex token and
 *    the extension the intake store itself gave the file. The parish's
 *    filename is attacker-controlled and the uploads directory is public, so
 *    not one character of it reaches the path — not the basename, not the
 *    extension, not a transliterated version. It is kept only as attachment
 *    metadata, and escaped on every render.
 * 2. **Nothing partial is left behind.** `wp_handle_sideload()` moves the file
 *    into `wp-content/uploads` before the attachment row exists, so every
 *    failure past that point has to undo both halves: delete the row *and*
 *    unlink the file. A failed promotion that left a file behind would be a
 *    file nobody chose to publish but that any visitor can fetch by guessing
 *    the URL, and a leftover row would render as a broken image.
 * 3. **Nothing is deleted on removal.** Detaching clears the parent and releases
 *    the featured image; the file and the attachment row stay, because the raw
 *    file is evidence and the retention settings own it. That is also what makes
 *    re-promoting able to restore it.
 */
final class WordPressSourceMaterialCopier implements SourceMaterialCopierInterface
{
    /**
     * The MIME type each stored extension stands for.
     *
     * Written out rather than derived, so a file name cannot smuggle a suffix
     * through: the map is the allowlist, and {@see SourceMaterialRole::allows()}
     * is what decides whether that type may hold the requested role. HEIC and
     * HEIF pass the *intake* allowlist but are absent here, which is how they
     * become "unavailable" rather than a dead link on the public page.
     *
     * @var array<string, string>
     */
    private const MIME_BY_EXTENSION = [
        'jpg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'pdf' => 'application/pdf',
    ];

    /**
     * What the caller is told, whatever WordPress said underneath. A parish
     * secretary promoting a bulletin should not be shown "Image resizing
     * failed." — but the real message must not be lost either, so it rides along
     * as the exception's previous.
     */
    private const FAILURE_MESSAGE = 'The source material could not be published.';

    public function __construct(
        private readonly ProtectedInboundMailStorage $storage
    ) {
    }

    public function copyIntoMediaLibrary(
        int $eventId,
        string $storageName,
        string $originalName,
        string $role
    ): int {
        if ($eventId < 1) {
            throw new SourceMaterialPromotionRefused('A promotion needs an event to attach to.');
        }

        $extension = $this->promotableExtension($storageName, $role);
                $source = $this->sourcePath($storageName);

        // require_once, not require: this runs inside a request WordPress has
        // already bootstrapped, and a second include is a fatal error.
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $uploadPath = $this->uploadPath();

        // sideload() moves the file, so it needs a file of its own in the temp
        // directory. copy() first, unlink() in the finally: the intake original
        // survives either way, because the raw file is the evidence the retention
        // settings own.
                $workingCopy = $this->workingCopy($extension);

        if (! copy($source, $workingCopy) || filesize($workingCopy) < 1) {
            $this->unlink($workingCopy);

            throw new SourceMaterialPromotionRefused('The source material file could not be read.');
        }

        // The two halves of a partial promotion, kept together because they are
                // always undone together. $attachmentId is 0 until a row exists; $movedFile
        // is null until the file has left the temp directory.
        $attachmentId = 0;
        $movedFile = null;

        try {
            $movedFile = $this->sideload($eventId, $workingCopy);
            $attachmentId = $this->insertAttachment($eventId, $movedFile, $originalName, $role);

            $this->generateMetadata($attachmentId, $movedFile, $role);
            $this->rememberOriginalName($attachmentId, $originalName);
        } catch (Throwable $failure) {
            $this->discard($attachmentId, $movedFile, $workingCopy);

            throw $this->reported($failure);
        } finally {
            // The working copy is unlinked by discard() when a failure meant it
            // had already been moved; this covers the success path and any
            // failure that happened before the move.
            $this->unlink($workingCopy);
        }

        return $attachmentId;
    }

    public function detachFromEvent(int $eventId, int $attachmentId): bool
    {
        if ($eventId < 1 || $attachmentId < 1) {
            return false;
        }

        $attachment = get_post($attachmentId);

        if (! is_object($attachment) || (int) ($attachment->post_parent ?? 0) !== $eventId) {
            return false;
        }

        // Releasing the thumbnail is conditional on this attachment being the
        // event's current featured image: removing an unrelated bulletin must
        // not strip the poster. Done after the ownership check above, so a
        // request naming someone else's attachment changes nothing at all.
        if (get_post_thumbnail_id($eventId) === $attachmentId) {
            delete_post_thumbnail($eventId);
        }

        $updated = wp_update_post([
            'ID' => $attachmentId,
            'post_parent' => 0,
        ]);

        return ! is_wp_error($updated) && (int) $updated === $attachmentId;
    }

    public function setFeaturedImage(int $eventId, int $attachmentId): bool
    {
        if ($eventId < 1) {
            return false;
        }

        if ($attachmentId < 1) {
            return delete_post_thumbnail($eventId);
        }

        return set_post_thumbnail($eventId, $attachmentId);
    }

    /**
     * @return non-empty-string
     */
    private function uploadPath(): string
    {
        $uploadDir = wp_upload_dir();

        if (! is_array($uploadDir) || ! empty($uploadDir['error']) || ! is_string($uploadDir['path'] ?? null)) {
            throw new SourceMaterialPromotionRefused('The uploads directory is not writable.');
        }

        return $uploadDir['path'];
    }

    /**
     * The extension to store under, or a refusal.
     *
     * Derived from the intake store's own file name — the one name in this flow
     * the plugin chose — and then checked against the role, so the allowlist has
     * exactly one home ({@see SourceMaterialRole::allows()}) rather than a second
     * copy of it here.
     */
    private function promotableExtension(string $storageName, string $role): string
    {
        $extension = strtolower(pathinfo($storageName, PATHINFO_EXTENSION));
        $mimeType = self::MIME_BY_EXTENSION[$extension] ?? null;

        if ($mimeType === null || ! SourceMaterialRole::allows($role, $mimeType)) {
            throw new SourceMaterialPromotionRefused('That file cannot be published as source material.');
        }

        return $extension;
    }

    /**
         * The private path of a stored attachment.
         *
         * The path check lives here rather than in the caller because it is
         * {@see ProtectedInboundMailStorage}'s own contract: it pattern-checks the
         * name, refuses a symlink, and resolves only inside its own directory. Taking
         * the concrete class rather than the port is what makes that a guarantee
         * instead of a convention — and it is why nothing downstream has to re-check
         * that the name cannot escape.
         */
        private function sourcePath(string $storageName): string
        {
            try {
                return $this->storage->resolveAttachmentPath($storageName);
            } catch (Throwable $failure) {
                throw new SourceMaterialPromotionRefused('The stored source file is no longer available.', 0, $failure);
            }
        }

    /**
         * A generated working copy, in the system temp directory rather than in the
         * uploads folder.
         *
         * Two reasons. The name carries no part of the parish's filename, because
         * that filename is attacker-controlled and the uploads directory is public.
         * And the temp directory is not the uploads directory: `wp_handle_sideload()`
         * works by *moving* its input, so a working copy offered from inside the
         * uploads folder would be moved onto its own path and then unlinked —
         * leaving the promotion with no file at all.
         */
        private function workingCopy(string $extension): string
        {
            return rtrim(sys_get_temp_dir(), '/\\') . '/' . bin2hex(random_bytes(16)) . '.' . $extension;
        }

    /**
     * Move the copy into its final home and return where it landed.
     *
     * The returned path is not necessarily the one that was offered: real
     * `wp_handle_sideload()` renames on collision. Everything downstream — and
     * every rollback — has to use the returned name, not the offered one.
     *
     * @return non-empty-string
     */
    private function sideload(int $eventId, string $workingCopy): string
    {
            // wp_handle_sideload() takes this by reference — WordPress rewrites the
            // array it is given — so it has to be a variable, not a literal.
            $file = [
                'name' => basename($workingCopy),
                'tmp_name' => $workingCopy,
            ];

            $sideloaded = wp_handle_sideload(
                $file,
                0,
                null,
                ['post_parent' => $eventId]
            );

        if (! is_array($sideloaded) || ! empty($sideloaded['error']) || ! is_string($sideloaded['file'] ?? null)) {
            throw new SourceMaterialPromotionRefused('The source material could not be copied.');
        }

        return $sideloaded['file'];
    }

    private function insertAttachment(
        int $eventId,
        string $movedFile,
        string $originalName,
        string $role
    ): int {
        $sideloaded = ['file' => $movedFile, 'type' => $this->mimeTypeFor($movedFile)];

        $attachmentId = wp_insert_attachment(
            [
                'post_mime_type' => $sideloaded['type'],
                'post_title' => $this->displayTitle($originalName),
                'post_content' => '',
                'post_status' => 'inherit',
                // post_parent is what puts the copy under "Uploaded to: <event>"
                // in the media library and what narrows the event's media picker.
                'post_parent' => $eventId,
            ],
            $movedFile,
            $eventId,
            true
        );

        if (is_wp_error($attachmentId) || ! is_numeric($attachmentId) || (int) $attachmentId < 1) {
            throw new SourceMaterialPromotionRefused('The source material could not be registered.');
        }

        return (int) $attachmentId;
    }

    private function mimeTypeFor(string $file): string
    {
        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));

        return self::MIME_BY_EXTENSION[$extension] ?? 'application/octet-stream';
    }

    /**
     * Only an image needs the size metadata. Generating it for a 2 MB PDF would
     * spend PHP time from a 90 s budget for nothing.
     *
     * A failure here is not swallowed: a poster published without its sizes
     * renders as a full-size image in every listing, so the promotion is
     * abandoned rather than half-completed. The rollback then removes both the
     * row and the file.
     */
    private function generateMetadata(int $attachmentId, string $movedFile, string $role): void
    {
        if ($role !== SourceMaterialRole::POSTER) {
            return;
        }

        $metadata = wp_generate_attachment_metadata($attachmentId, $movedFile);

        if (is_array($metadata)) {
            wp_update_attachment_metadata($attachmentId, $metadata);
        }
    }

    /**
     * The parish's own filename, kept as metadata only.
     *
     * Not inside `wp_update_attachment_metadata()`: a regenerated metadata array
     * replaces wholesale, which would silently drop the name on the next image
     * resize. A meta row survives.
     */
    private function rememberOriginalName(int $attachmentId, string $originalName): void
    {
        update_post_meta($attachmentId, 'adct_pi_source_name', sanitize_text_field($originalName));
    }

    /**
     * The label shown in the media library.
     *
     * Derived from the parish's name because it is a human-facing label there,
     * but reduced to a bare basename first: a title, never a path.
     */
    private function displayTitle(string $originalName): string
    {
        $basename = basename(str_replace('\\', '/', $originalName));
        $title = trim((string) pathinfo($basename, PATHINFO_FILENAME));

        return $title === '' ? 'Source material' : $title;
    }

    /**
     * Undo a partial promotion: the row first, then whatever it named, then the
     * file offered to the sideload.
     *
     * `wp_delete_attachment(…, true)` removes the row *and* its file, so the
     * explicit unlinks are what cover the window where the file exists but no
     * row does — and they are safe to repeat, because unlink() ignores a path
     * that is not there.
     */
    private function discard(int $attachmentId, ?string $movedFile, string $workingCopy): void
    {
        if ($attachmentId > 0) {
            wp_delete_attachment($attachmentId, true);
        }

        $this->unlink($movedFile ?? '');
        $this->unlink($workingCopy);
    }

    /**
         * What the admin screen is shown.
         *
         * The plugin's own refusals ({@see SourceMaterialPromotionRefused}) say
         * something the secretary can act on, so they are passed through untouched;
         * anything WordPress threw is replaced with a sentence about the action, with
         * the original kept as the previous exception so the cause is not lost from
         * the log.
         */
        private function reported(Throwable $failure): SourceMaterialPromotionRefused
        {
            if ($failure instanceof SourceMaterialPromotionRefused) {
                return $failure;
            }

            return new SourceMaterialPromotionRefused(self::FAILURE_MESSAGE, 0, $failure);
        }

    private function unlink(string $path): void
    {
        if ($path !== '' && is_file($path)) {
            @unlink($path);
        }
    }
}