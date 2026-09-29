<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Mail;

enum ConfirmationEmailOutcome: string
{
    case QUEUED = 'queued';
    case SENT = 'sent';
    case SUPPRESSED = 'suppressed';
    case FAILED = 'failed';
}
