<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ports;

use InvalidArgumentException;

/**
 * The event's own record of what source material it publishes, and nothing
 * else.
 *
 * Deliberately narrow for the same reason as the media API below: the public
 * event page must learn what to show from the ordered `source_attachment_ids`
 * meta and from nowhere else. This interface has no children query, no
 * `get_posts` and no attachment listing of any kind, so a change that made
 * unpromoted material reachable would have to add a method here first, and
 * that addition is what a reviewer would see.
 */
interface StoredAttachmentEventMetaInterface
{
    /**
     * The ordered, sanitised entries recorded against an event.
     *
     * Implementations must treat anything they cannot parse as absent rather
     * than as an error: post meta can be hand-edited, restored from a backup,
     * or written by an older version of the plugin, and the front end must
     * still render.
     *
     * @return list<array<string, mixed>> sanitised entries, in publisher order
     * @throws InvalidArgumentException when the post id is not positive.
     */
    public function readSourceAttachmentIds(int $postId): array;

    /**
     * @param list<array<string, mixed>> $entries
     * @throws InvalidArgumentException when the post id is not positive.
     */
    public function writeSourceAttachmentIds(int $postId, array $entries): void;

    /**
     * @throws InvalidArgumentException when either id is not positive.
     */
    public function setFeaturedImage(int $postId, int $mediaId): void;

    /**
     * @throws InvalidArgumentException when either id is not positive.
     */
    public function clearFeaturedImage(int $postId, int $mediaId): void;
}
