<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ingestion\Imap;

use Throwable;

final class AuthenticationFailed extends MailboxException
{
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct('The mailbox rejected the username or password.', 0, $previous);
    }
}
