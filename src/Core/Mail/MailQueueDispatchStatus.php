<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Mail;

enum MailQueueDispatchStatus: string
{
    case SENT = 'sent';
    case RETRY_SCHEDULED = 'retry_scheduled';
    case FAILED = 'failed';
    case SUPPRESSED = 'suppressed';
    case INTERRUPTED_REQUEUED = 'interrupted_requeued';
    case EMPTY = 'empty';
    case CAP_REACHED = 'cap_reached';
    case LOCK_BUSY = 'lock_busy';
    case CLAIM_LOST = 'claim_lost';

    public function processedItem(): bool
    {
        return in_array($this, [
            self::SENT,
            self::RETRY_SCHEDULED,
            self::FAILED,
            self::SUPPRESSED,
            self::INTERRUPTED_REQUEUED,
        ], true);
    }
}
