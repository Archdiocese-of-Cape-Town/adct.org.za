<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Attachments;

use ADCT\ParishIntake\Core\Attachments\SourceMaterialReference;
use ADCT\ParishIntake\Core\Ports\SourceMaterialStoreInterface;

/**
 * The WordPress side of an event's ordered promotion meta (issue #172).
 *
 * The meta key is the contract with the front end: `PublicEventPage` and
 * `PublicEventListing` read promoted items through
 * {@see \ADCT\ParishIntake\Core\Attachments\SourceMaterialReference::listFromStored()}
 * and nothing else, so an attachment that was never promoted cannot be reached
 * from a public page even though it sits in the same media library.
 *
 * Reading an event with no stored value answers an empty list. There is no
 * default, no "the featured image counts as source material" and no fallback to
 * `get_children()`, because a default is how an unapproved file becomes public
 * by accident.
 */
final class WordPressSourceMaterialStore implements SourceMaterialStoreInterface
{
    public const META_KEY = 'source_attachment_ids';

    public function forEvent(int $eventId): array
    {
        if ($eventId < 1) {
            return [];
        }

        // Deliberately not cached in a static: two promotions in one request
        // must not read each other's stale value, and the meta cache is already
        // busted by the `updated_post_meta` hook.
        return SourceMaterialReference::listFromStored(get_post_meta($eventId, self::META_KEY, true));
    }

    /**
     * @param list<SourceMaterialReference> $references
     */
    public function replaceForEvent(int $eventId, array $references): void
    {
        if ($eventId < 1) {
            return;
        }

        if ($references === []) {
            // delete_post_meta() rather than writing an empty array, so a
            // removed promotion leaves no stored value that a later reader could
            // mistake for "promoted, but empty".
            delete_post_meta($eventId, self::META_KEY);

            return;
        }

        update_post_meta($eventId, self::META_KEY, SourceMaterialReference::encodeStored($references));
    }
}