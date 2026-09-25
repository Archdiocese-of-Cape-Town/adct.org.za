<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ingestion\Imap;

enum MailboxEncryption: string
{
    case SSL = 'ssl';
    case STARTTLS = 'starttls';
    case NONE = 'none';
}
