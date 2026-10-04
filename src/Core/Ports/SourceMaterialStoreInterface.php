<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ports;

use ADCT\ParishIntake\Core\Publishing\SourceAttachment;
use DomainException;

/**
 * Records an event's promoted source material (issue #172, ADR 0025).
 *
 * The port is deliberately narrow and deliberately has no "publish a candidate"
 * method on it. That absence is the whole of the no-auto-promotion guarantee:
 * the thing that writes an event cannot reach the thing that copies a bulletin,
 * so publishing an event and making its bulletin world-readable are two calls a
 * person has to make, in two different places, with two different nonces.
 *
 * It also has no "record this promotion" method. `promote()` and `remove()` are
 * the only two ways this state changes, and each writes its own
 * `adct_pi_audit_log` row on the way out, so "every promotion and every removal
 * is attributable" is a property of the implementation rather than a rule each
 * screen has to remember. An earlier draft exposed a public `record()` as well; a
 * caller that then did both wrote two rows for one promotion, which is exactly
 * the kind of ambiguous audit trail a POPIA enquiry cannot be answered from.
 * One mutation, one row.
 *
 * Implementations perform filesystem I/O and are therefore NOT allowed to run
 * inside a database transaction. `WordPressPublicationStore::publish()` wraps
 * its work in `START TRANSACTION`/`COMMIT`, and a rollback cannot undo a file
 * that has already been written. A failed promotion must leave the event
 * published with no source material - never roll back the publication, and never
 * leave a half-copied file.
 */
interface SourceMaterialStoreInterface
{
    /**
     * Copy an intake attachment into the media library, record it against the
     * event, and return what was recorded.
     *
     * This is the only method that creates a media library copy, and it is only
     * ever called from a human-driven control that has already passed its own
     * capability and nonce checks.
     *
     * @throws DomainException when the copy cannot be made. The caller must
     *         leave the event published and report the failure; see the note on
     *         transactions above.
     */
    public function promote(int $eventId, int $attachmentId, string $role): SourceAttachment;

    /**
     * Stop an already-promoted item appearing publicly.
     *
     * A visibility change, not a deletion: the stored original stays, and the
     * poster role is released so the featured image falls back to whatever it
     * was. Reversible by promoting again.
     */
    public function remove(int $eventId, int $mediaId): void;

    /**
     * The event's source material, in the order a publisher arranged it.
     *
     * This is the ONLY way the front end is allowed to learn what an event has.
     * An implementation must not answer it by querying the attachments of a post
     * directly, because unpromoted material has to stay unreachable: there is
     * no copied-but-not-yet-public state to get wrong, and a broad query over an
     * event's children would put it back.
     *
     * @return list<SourceAttachment>
     */
    public function forEvent(int $eventId): array;
}
