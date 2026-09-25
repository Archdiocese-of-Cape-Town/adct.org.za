<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Mail;

enum MailQueueClaimStatus: string
{
    case CLAIMED = 'claimed';
    case CAP_REACHED = 'cap_reached';
    case NOT_CLAIMABLE = 'not_claimable';
    case LOCK_BUSY = 'lock_busy';
}
