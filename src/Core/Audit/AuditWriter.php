<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Audit;

/**
 * Appends one row to the audit trail.
 *
 * The interface has no update and no delete. The E2.7 retention job removes
 * old rows through its own retention store, on its own schedule, so this stays
 * the only way to add evidence to the record and there is no way to remove it
 * by calling this port.
 */
interface AuditWriter
{
    /**
     * @param string               $actor      A person or a system marker, never a user ID: the
     *                                       column holds the email address of whoever acted, or
     *                                       SYSTEM_ACTOR for unattended jobs.
     * @param AuditAction          $action
     * @param string               $subjectType One of the AuditSubjectType constants.
     * @param int                  $subjectId   0 when the action is not about one row.
     * @param array<string, mixed> $details     The diff or note. Must not contain secrets.
     *
     * @throws \RuntimeException if the row was not written.
     */
    public function write(
        string $actor,
        AuditAction $action,
        string $subjectType,
        int $subjectId,
        array $details
    ): int;
}