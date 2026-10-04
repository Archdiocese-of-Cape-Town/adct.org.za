<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Mail;

use DateTimeImmutable;
use DateTimeZone;

/**
 * What one accepted resend did, for the reviewer's confirmation notice.
 */
final class ConfirmationEmailResendResult
{
    public function __construct(
        public readonly ConfirmationEmailResendOutcome $outcome,
        public readonly int $candidateId,
        public readonly string $recipient,
        public readonly int $queueId,
        public readonly ?DateTimeImmutable $lastResentAt,
        public readonly DateTimeImmutable $nextAllowedAt,
        public readonly DateTimeImmutable $sentAt
    ) {
    }

    /**
     * The reviewer-facing sentence for a successful resend.
     *
     * $displayTimezone is passed in rather than read from the system default:
     * the plugin deals in Africa/Johannesburg, day-first, and the host may be
     * configured for anything.
     */
    public function notice(DateTimeZone $displayTimezone): string
    {
        $sentAt = $this->sentAt->setTimezone($displayTimezone);

        return match ($this->outcome) {
            ConfirmationEmailResendOutcome::QUEUED => sprintf(
                'Confirmation preview queued for %s at %s. It will be sent within the hourly mail batch. The next resend may be sent after %s.',
                $this->recipient,
                $sentAt->format('d/m/Y H:i'),
                $this->nextAllowedAt->setTimezone($displayTimezone)->format('d/m/Y H:i')
            ),
            ConfirmationEmailResendOutcome::SENT => sprintf(
                'Confirmation preview sent to %s at %s. The next resend may be sent after %s.',
                $this->recipient,
                $sentAt->format('d/m/Y H:i'),
                $this->nextAllowedAt->setTimezone($displayTimezone)->format('d/m/Y H:i')
            ),
            ConfirmationEmailResendOutcome::SUPPRESSED => sprintf(
                'Confirmation preview for %s was suppressed by test mode, so no email was sent. The next resend may be sent after %s.',
                $this->recipient,
                $this->nextAllowedAt->setTimezone($displayTimezone)->format('d/m/Y H:i')
            ),
            ConfirmationEmailResendOutcome::FAILED => sprintf(
                'The confirmation preview for %s could not be delivered. Check the mail queue for details; the next resend may be sent after %s.',
                $this->recipient,
                $this->nextAllowedAt->setTimezone($displayTimezone)->format('d/m/Y H:i')
            ),
        };
    }
}
