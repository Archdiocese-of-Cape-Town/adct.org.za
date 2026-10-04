<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Mail;

use DateTimeImmutable;

/**
 * Raised when a resend is refused because the cooldown window has not elapsed.
 *
 * Carries both timestamps rather than just a cooldown length so the reviewer is
 * told *when* the last one went out and *when* they may try again, rather than
 * being asked to do arithmetic against an hour.
 */
final class ConfirmationEmailResendCooldownException extends \DomainException
{
    public function __construct(
        public readonly DateTimeImmutable $lastResentAt,
        public readonly DateTimeImmutable $retryAfter,
        int $candidateId
    ) {
        parent::__construct(sprintf(
            'Candidate %d was already resent at %s; the next confirmation resend may be sent after %s.',
            $candidateId,
            $lastResentAt->format('Y-m-d H:i:s'),
            $retryAfter->format('Y-m-d H:i:s')
        ));
    }
}
