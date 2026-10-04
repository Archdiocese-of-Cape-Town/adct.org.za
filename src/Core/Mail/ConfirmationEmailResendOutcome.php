<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Mail;

/**
 * How a queued resend ended up.
 *
 * Mirrors the queue's own states rather than inventing new ones, so the admin
 * notice can say what actually happened to the email rather than just "sent".
 */
enum ConfirmationEmailResendOutcome
{
    case QUEUED;
    case SENT;
    case SUPPRESSED;
    case FAILED;

    public static function fromQueueStatus(MailQueueStatus $status): self
    {
        return match ($status) {
            MailQueueStatus::QUEUED,
            MailQueueStatus::SENDING => self::QUEUED,
            MailQueueStatus::SENT => self::SENT,
            MailQueueStatus::SUPPRESSED => self::SUPPRESSED,
            MailQueueStatus::FAILED => self::FAILED,
        };
    }

    /** True when the parish will actually receive the email. */
    public function reachedParish(): bool
    {
        return $this === self::QUEUED || $this === self::SENT;
    }
}
