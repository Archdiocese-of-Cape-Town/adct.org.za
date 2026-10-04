<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ports;

use RuntimeException;

/**
 * Copies one stored inbound attachment into the WordPress media library,
 * parented to an event post (issue #172).
 *
 * This is the seam where the filesystem is touched. It is deliberately *not*
 * part of {@see \ADCT\ParishIntake\Core\Publishing\CandidatePublisher}: that
 * service runs inside a database transaction, and a file copy cannot be undone
 * by a rollback. A promotion therefore always happens as its own step after the
 * event is published, so a failure leaves the event published with no source
 * material rather than rolling back the publication or leaving a half-written
 * file behind.
 *
 * Every implementation must:
 *
 * - generate the stored file name itself, never deriving any part of the path
 *   from the parish-supplied name;
 * - refuse a source file that is missing, and refuse any type outside the
 *   promotion allowlist, by throwing {@see RuntimeException} with a message a
 *   human can act on;
 * - leave no partial file and no attachment row behind on failure;
 * - return the new media-library attachment id on success.
 */
interface SourceMaterialCopierInterface
{
    /**
     * Copy `$storageName` from the intake store to the media library as an
     * attachment parented to `$eventId`.
     *
     * @param string $storageName the intake store's own file name for the source
     * @param string $originalName the parish's filename, kept as metadata only
     * @param string $role one of {@see \ADCT\ParishIntake\Core\Attachments\SourceMaterialRole}
     * @return int the new media-library attachment id
     * @throws RuntimeException when the copy could not be completed cleanly
     */
    public function copyIntoMediaLibrary(
        int $eventId,
        string $storageName,
        string $originalName,
        string $role
    ): int;

    /**
     * Detach an attachment from the event and release its featured-image role.
     *
     * The stored file is *not* deleted: the raw file is evidence and the
     * retention settings own it. Re-promoting restores the event's source
     * material, which is only possible because nothing was destroyed here.
     *
         * Implementations must release the event's featured image when, and only
         * when, `$attachmentId` is the event's current thumbnail, so removing an
         * unrelated document never strips the poster.
         *
         * @return bool true when the attachment was detached
         */
        public function detachFromEvent(int $eventId, int $attachmentId): bool;

     /**
     * Make this attachment the event's featured image.
     *
     * @param int $attachmentId the media-library attachment, or 0 to release
     *        the event's featured image without setting a new one
     * @return bool true when the thumbnail was set
     */
        public function setFeaturedImage(int $eventId, int $attachmentId): bool;
}