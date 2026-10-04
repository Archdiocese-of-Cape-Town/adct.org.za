<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Publishing;

use ADCT\ParishIntake\Core\Ports\StoredAttachmentEventMetaInterface;
use ADCT\ParishIntake\Core\Publishing\SourceAttachment;
use InvalidArgumentException;

/**
 * The event post's own record of what it publishes, and the featured image
 * beside it (issue #172, ADR 0025).
 *
 * This is the only reader of `source_attachment_ids` outside the store itself,
 * and the front end reaches it only through SourceMaterialStoreInterface::forEvent().
 * The narrowness is deliberate: this class has no attachment query at all, so
 * there is no code path here that could surface an intake attachment to a
 * visitor, promoted or not.
 *
 * Meta is read defensively. It can be hand-edited, restored from a backup, or
 * written by a version of the plugin that knew fewer fields than this one does,
 * and none of those is a reason to fail the event page.
 */
final class WordPressEventMeta implements StoredAttachmentEventMetaInterface
{
    /**
     * The post meta key holding the ordered list. Matches the key registered in
     * `EventPostType::registerMeta()`.
     */
    public const META_KEY = 'source_attachment_ids';

    /**
     * A filename longer than this is not a filename a browser or a screen
     * reader needs to see in full, and the intake table allows a long one. The
     * truncation happens on read so the public page can never be handed a
     * multi-kilobyte string to escape on every request.
     */
    private const FILENAME_LIMIT = 255;

    public function readSourceAttachmentIds(int $postId): array
    {
        $this->assertPostId($postId);

        $stored = get_post_meta($postId, self::META_KEY, true);
        if (! is_array($stored)) {
            return [];
        }

        $entries = [];
        foreach ($stored as $entry) {
            $sanitised = $this->sanitiseEntry($entry);
            if ($sanitised !== null) {
                $entries[] = $sanitised;
            }
        }

        return $entries;
    }

    public function writeSourceAttachmentIds(int $postId, array $entries): void
    {
        $this->assertPostId($postId);

        $sanitised = [];
        foreach ($entries as $entry) {
            $clean = $this->sanitiseEntry($entry);
            if ($clean !== null) {
                $sanitised[] = $clean;
            }
        }

        if ($sanitised === []) {
            // An empty list is the honest answer for "this event has no source
            // material", and storing it as an empty array keeps a stale list
            // from surviving a removal.
            update_post_meta($postId, self::META_KEY, []);

            return;
        }

        update_post_meta($postId, self::META_KEY, $sanitised);
    }

    public function setFeaturedImage(int $postId, int $mediaId): void
    {
        $this->assertPostId($postId);
        $this->assertMediaId($mediaId);

        set_post_thumbnail($postId, $mediaId);
    }

    public function clearFeaturedImage(int $postId, int $mediaId): void
    {
        $this->assertPostId($postId);
        $this->assertMediaId($mediaId);

        // Only the image that is actually set is cleared. A publisher who has
        // since chosen a different featured image by hand must not lose it
        // because an older promoted poster was removed.
        if (get_post_thumbnail_id($postId) !== $mediaId) {
            return;
        }

        delete_post_thumbnail($postId);
    }

    /**
     * @param mixed $entry
     * @return array<string, mixed>|null null when the entry cannot be trusted,
     *         which is what makes a hand-edited meta array survivable.
     */
    private function sanitiseEntry(mixed $entry): ?array
    {
        if (! is_array($entry)) {
            return null;
        }

        $mediaId = $entry['media_id'] ?? null;
        if (! is_int($mediaId) && ! (is_string($mediaId) && ctype_digit($mediaId))) {
            return null;
        }

        $mediaId = (int) $mediaId;
        if ($mediaId < 1) {
            return null;
        }

        $role = $entry['role'] ?? null;
        if (! is_string($role) || ! in_array($role, SourceAttachment::ROLES, true)) {
            return null;
        }

        $attachmentId = $entry['attachment_id'] ?? null;
        if (! is_int($attachmentId) && ! (is_string($attachmentId) && ctype_digit($attachmentId))) {
            return null;
        }

        $attachmentId = (int) $attachmentId;
        if ($attachmentId < 1) {
            return null;
        }

        $filename = $entry['original_filename'] ?? '';
        if (! is_string($filename)) {
            return null;
        }

        return [
            'media_id' => $mediaId,
            'role' => $role,
            'original_filename' => mb_substr($filename, 0, self::FILENAME_LIMIT),
            'attachment_id' => $attachmentId,
        ];
    }

    private function assertPostId(int $postId): void
    {
        if ($postId < 1) {
            throw new InvalidArgumentException('A post id must be positive.');
        }
    }

    private function assertMediaId(int $mediaId): void
    {
        if ($mediaId < 1) {
            throw new InvalidArgumentException('A media library id must be positive.');
        }
    }
}