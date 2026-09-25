<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Mail;

enum MailPriority: int
{
    case LOGIN_OR_CONFIRMATION = 1;
    case APPROVER_OR_CHANGE = 2;
    case REMINDER_OR_DIGEST = 3;
}
