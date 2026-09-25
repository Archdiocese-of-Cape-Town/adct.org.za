<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Mail;

enum MailQueueStatus: string
{
    case QUEUED = 'queued';
    case SENDING = 'sending';
    case SENT = 'sent';
    case FAILED = 'failed';
    case SUPPRESSED = 'suppressed';

    public function isPending(): bool
    {
        return $this === self::QUEUED || $this === self::SENDING;
    }
}
