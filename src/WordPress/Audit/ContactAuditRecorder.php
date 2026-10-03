<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Audit;

use ADCT\ParishIntake\Core\Audit\AuditAction;
use ADCT\ParishIntake\Core\Audit\AuditSubjectType;
use ADCT\ParishIntake\Core\Audit\AuditWriter;
use RuntimeException;

/**
 * Writes the audit rows for parish contact changes.
 *
 * Two admin screens change sender trust — the Senders screen and the parish
 * contact form — so the row shape lives here instead of being written twice.
 * The recorder is the only place that knows what a contact audit row looks
 * like; the screens supply who acted, what happened and the resulting trust.
 *
 * A failure to record is logged rather than thrown. By the time a trust change
 * is recorded the change itself has already been committed, and turning a
 * successful edit into a fatal error because the audit insert failed would lose
 * the change and alarm the operator about the wrong thing. The retention job
 * still bounds the table.
 */
final class ContactAuditRecorder
{
/**
     * @param ActorResolver $actor Supplies the address of the signed-in user.
     */
    public function __construct(
        private readonly AuditWriter $audit,
        private readonly ActorResolver $actor
    ) {
    }

    /**
     * Records one contact change.
     *
     * A contact id of null means the address has no contact row yet, which is
     * normal: an address can be blocked before it is linked to any parish. The
     * address is then the only stable identifier, so it is recorded in the
     * details and the subject id is left at zero.
     */
    public function record(
        AuditAction $action,
        string $email,
        ?int $parishId,
        ?int $contactId,
        string $trust
    ): void {
        try {
            $this->audit->write(
                $this->actor->actor(),
                $action,
                AuditSubjectType::PARISH_CONTACT,
                $contactId ?? 0,
                [
                    'email' => $email,
                    'parish_id' => $parishId,
                    'trust' => $trust,
                ]
            );
        } catch (RuntimeException $failure) {
            error_log(
                '[ADCT Parish Intake] The contact audit row could not be written ('
                . $failure->getMessage() . ').'
            );
        }
    }
}