<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ingestion\Imap;

use Throwable;

final class TlsFailed extends MailboxException
{
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct('A secure connection to the mail server could not be established or verified.', 0, $previous);
    }
}
