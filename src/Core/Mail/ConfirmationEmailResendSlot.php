<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Mail;

use DateTimeImmutable;

/**
 * A claimed resend slot: the email to send, and what the slot displaced.
 *
 * {@see $lastResentAt} is the *previous* resend, not this one — it is what the
 * reviewer is told about when a cooldown blocks them, so it has to be the value
 * that existed before this request.
 */
final class ConfirmationEmailResendSlot
{
    public function __construct(
        public readonly ConfirmationEmailBatch $batch,
        public readonly ?DateTimeImmutable $lastResentAt
    ) {
    }
}
