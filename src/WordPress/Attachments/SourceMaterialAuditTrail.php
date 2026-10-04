<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Attachments;

use ADCT\ParishIntake\Core\Attachments\SourceMaterialReference;
use ADCT\ParishIntake\Core\Attachments\SourceMaterialRole;
use ADCT\ParishIntake\Core\Audit\AuditAction;
use ADCT\ParishIntake\Core\Audit\AuditSubjectType;
use ADCT\ParishIntake\Core\Audit\AuditWriter;
use ADCT\ParishIntake\WordPress\Audit\ActorResolver;
use InvalidArgumentException;
use Throwable;

/**
 * The "who made this public" trail for source material (issue #172).
 *
 * Kept apart from the copier and from the decision service because it is the
 * only part that answers an attribution question, and it is the part a POPIA
 * enquiry reads. Each row names the acting user, the event, and the affected
 * attachment — the three things "who made this bulletin world-readable" needs
 * an answer to.
 *
 * Two deliberate differences from {@see \ADCT\ParishIntake\WordPress\Audit\ContactAuditRecorder},
 * which is the other trail this repository has:
 *
 * - **The actor is resolved, never supplied.** A caller that passed its own
 *   actor string could attribute a promotion to somebody who did not make it,
 *   and the whole value of this row is that it cannot be.
 * - **A failed write is not swallowed.** The contact recorder logs and
 *   continues, because losing a contact edit would be worse than losing its
 *   audit row. Here the promotion has already happened by the time the row is
 *   written, so swallowing would leave a published bulletin that nobody is
 *   recorded as having published. The failure propagates instead, and the admin
 *   screen reports it.
 */
final class SourceMaterialAuditTrail
{
    public function __construct(
        private readonly AuditWriter $audit,
        private readonly ActorResolver $actor
    ) {
    }

    /**
     * @param int|null $intakeAttachmentId the `adct_pi_attachments` row the copy
     *        came from, so the trail joins the raw file to the public copy
     *        without depending on a filename matching
     * @return int the new audit row id
     */
    public function recordPromotion(
        int $eventId,
        SourceMaterialReference $reference,
        ?int $intakeAttachmentId = null
    ): int {
        $this->assertEvent($eventId);

        return $this->audit->write(
            $this->actor->actor(),
            AuditAction::SOURCE_MATERIAL_PROMOTED,
            AuditSubjectType::EVENT,
            $eventId,
            $this->details($reference, $intakeAttachmentId)
        );
    }

    /**
     * @return int the new audit row id
     */
    public function recordRemoval(
        int $eventId,
        SourceMaterialReference $reference
    ): int {
        $this->assertEvent($eventId);

        return $this->audit->write(
            $this->actor->actor(),
            AuditAction::SOURCE_MATERIAL_REMOVED,
            AuditSubjectType::EVENT,
            $eventId,
            $this->details($reference, null)
        );
    }

    /**
     * The details shared by both actions.
     *
     * The removal's row has no intake attachment id on purpose: there is no
     * intake id to record, only the media-library id that was detached and the
     * role it used to hold, which is what makes the row read as a visibility
     * change rather than a deletion.
     *
     * @return array<string, mixed>
     */
    private function details(SourceMaterialReference $reference, ?int $intakeAttachmentId): array
    {
        $details = [
            'attachment_id' => $reference->attachmentId,
            'role' => $reference->role,
            'role_label' => $this->roleLabel($reference->role),
        ];

        if ($reference->originalName !== '') {
            // Kept because it is what a person recognises when asked "which file
            // was this?". It was sanitised on the way in, and it is only ever
            // rendered through the escaping the views already use.
            $details['original_name'] = $reference->originalName;
        }

        if ($intakeAttachmentId !== null && $intakeAttachmentId > 0) {
            $details['intake_attachment_id'] = $intakeAttachmentId;
        }

        return $details;
    }

    private function roleLabel(string $role): string
    {
        try {
            return SourceMaterialRole::label($role);
        } catch (Throwable) {
            return 'Source material';
        }
    }

    private function assertEvent(int $eventId): void
    {
        if ($eventId < 1) {
            throw new InvalidArgumentException('A source material audit row needs an event.');
        }
    }
}