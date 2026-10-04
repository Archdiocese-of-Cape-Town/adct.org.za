<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ports;

use ADCT\ParishIntake\Core\Mail\ConfirmationEmailResendCooldownException;
use ADCT\ParishIntake\Core\Mail\ConfirmationEmailResendSlot;
use DateTimeImmutable;

/**
 * Guards and records a reviewer's request to resend a confirmation email.
 *
 * Both halves belong to one adapter deliberately: deciding the resend is allowed
 * and recording that it happened must not be separable, or two presses landing at
 * once would both see a free slot and both queue an email. An implementation is
 * expected to take a row lock, re-read the last resend, and write the audit entry
 * in the same transaction.
 */
interface ConfirmationEmailResendBookingInterface
{
    /**
     * Claim the cooldown slot for this candidate and return what to send.
     *
     * Must record the resend in the audit log even when the email later fails to
     * render or queue: a reviewer pressing "Resend" is an outbound message to a
     * parish regardless of what happens next, and POPIA makes that worth keeping.
     *
     * @param int $cooldownSeconds how long the slot stays claimed
     * @throws ConfirmationEmailResendCooldownException when a resend happened
     *         inside $cooldownSeconds
     * @throws \DomainException when the candidate has never been saved and
     *         therefore has no stored fields to render from
     */
    public function claimResendSlot(
        int $candidateId,
        string $actor,
        DateTimeImmutable $requestedAt,
        int $cooldownSeconds
    ): ConfirmationEmailResendSlot;

    /**
     * When this candidate's confirmation was last resent, or null if never.
     */
    public function lastResentAt(int $candidateId): ?DateTimeImmutable;
}
