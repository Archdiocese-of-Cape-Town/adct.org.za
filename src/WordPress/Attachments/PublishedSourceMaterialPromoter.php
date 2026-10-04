<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Attachments;

use ADCT\ParishIntake\Core\Attachments\SourceMaterialPromotion;
use ADCT\ParishIntake\Core\Attachments\SourceMaterialRole;
use ADCT\ParishIntake\WordPress\Database\Repository\AttachmentRepository;
use InvalidArgumentException;
use Throwable;

/**
 * Publishing an event publishes the source material that arrived with it.
 *
 * This is the rule the project owner settled for issue #172, overruling the
 * promotion gate the issue originally asked for: *"I just want it simple,
 * published or not, the source of the events are public, by sending an email
 * they assuming it's public information as a whole."* A parish that emails an
 * event notice has already decided the material is public, so a second
 * confirmation step only produces events published without their poster.
 *
 * Publication is therefore the disclosure switch. There is no selection, no
 * default list and no setting, because there is nothing left to decide.
 *
 * Five properties are load-bearing, and each is a decision rather than an
 * accident:
 *
 * - **The role is derived from the file type.** It comes from
 *   {@see SourceMaterialRole::rolesFor()} rather than from a caller's choice,
 *   so an image cannot be filed as a bulletin and a PDF cannot be filed as a
 *   poster. A type no role accepts is skipped, never given a fallback.
 * - **The copy is made from the intake store's own filename.** The parish's
 *   filename is attacker-controlled and travels only as metadata, exactly as
 *   in the manual route. See {@see WordPressSourceMaterialCopier}.
 * - **One unreadable file does not cost the parish the rest.** Bulletins
 *   arrive as one email with a poster *and* a PDF, so a batch that stopped at
 *   the first fault would publish neither.
 * - **Nothing is thrown.** This runs after the publication transaction has
 *   committed. {@see \ADCT\ParishIntake\WordPress\Publishing\WordPressPublicationStore}
 *   catches anything that escapes its post-commit work and reports it as a
 *   listing-cache fault, telling the operator to retry a publish that already
 *   succeeded -- which would re-copy every file and demote the first poster to
 *   a document. A promotion fault is logged here instead, and the event stays
 *   published, which is what issue #172 requires.
 * - **The audit row is best-effort but never skipped.**
 *   {@see SourceMaterialAuditTrail} deliberately propagates a failed write,
 *   so the catch here is what stops an audit fault from becoming a cache fault.
 *
 * The idempotency guard is deliberately *not* in this class. It belongs to the
 * caller: only the fresh-publish path may promote, never the already-published
 * short-circuit, because the store tells operators to re-publish a published
 * candidate in order to repair a listing generation.
 */
final class PublishedSourceMaterialPromoter
{
    public function __construct(
        private readonly AttachmentRepository $attachments,
        private readonly SourceMaterialPromotion $promotion,
        private readonly SourceMaterialAuditTrail $audit
    ) {
    }

    /**
     * Copies every eligible file on the message behind a newly published event.
     *
     * @param int $eventId the published event
     * @param int $messageId the inbound message the event was parsed from
     */
    public function promoteForPublishedEvent(int $eventId, int $messageId): void
    {
        if ($eventId < 1) {
            throw new InvalidArgumentException('An event id must be positive.');
        }

        if ($messageId < 1) {
            throw new InvalidArgumentException('A message id must be positive.');
        }

        foreach ($this->attachments->findPromotableForMessage($messageId) as $attachment) {
                    $this->promoteOne($eventId, $attachment);
        }
    }

    /**
     * @param array<string, mixed> $attachment a row from findPromotableForMessage()
     */
    private function promoteOne(int $eventId, array $attachment): void
    {
        $intakeId = (int) ($attachment['id'] ?? 0);
        $mimeType = (string) ($attachment['mime_type'] ?? '');
        $originalName = (string) ($attachment['filename'] ?? '');

        // The first role the type can hold is the one it gets: a poster for an
        // image, a bulletin for a PDF. rolesFor() returns them in the order a
        // form would offer them, so this needs no second list to keep in step.
        $roles = SourceMaterialRole::rolesFor($mimeType);

                if ($intakeId < 1 || $roles === []) {
            return;
        }

        try {
            $reference = $this->promotion->promote(
                $eventId,
                (string) ($attachment['storage_path'] ?? ''),
                $originalName,
                $mimeType,
                $roles[0]
            );

            $this->audit->recordPromotion($eventId, $reference, $intakeId);
        } catch (Throwable $failure) {
                    // Logged, not thrown. The reason is in the class docblock: anything
                    // escaping here would be reported as a listing-cache fault by the
                    // publication store and would invite a retry that re-copies.
                    error_log(sprintf(
                        '[ADCT Parish Intake] Source material for event %d was not published: %s',
                        $eventId,
                        $failure->getMessage()
                    ));
                }
    }
}