<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ports;

use ADCT\ParishIntake\Core\Attachments\SourceMaterialReference;

/**
 * The event's ordered promotion meta: which media-library attachments this
 * event publishes as its source material, and in what role (issue #172).
 *
 * This is a port rather than a direct `get_post_meta()` call so that the
 * publishing path, the review queue and the public renderer all ask one
 * question of one implementation, and so the "nothing is promoted unless a
 * person chose it" rule has a single answer: an event with no stored value has
 * an empty list, never a default.
 *
 * Implementations must return the stored order, and must not widen the answer:
 * an attachment that is not named by the stored promotion meta is never
 * included, whatever its `post_parent` happens to be.
 */
interface SourceMaterialStoreInterface
{
    /**
     * The event's promoted source material, in the order stored.
     *
     * @return list<SourceMaterialReference>
     */
    public function forEvent(int $eventId): array;

    /**
     * Replaces the whole list.
     *
     * The order given is the order stored. Passing an empty list removes every
     * promoted reference without deleting a single file, because removal is a
     * visibility change rather than a deletion (issue #172).
     *
     * @param list<SourceMaterialReference> $references
     */
    public function replaceForEvent(int $eventId, array $references): void;
}