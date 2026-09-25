<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ingestion\Imap;

use Throwable;

final class ConnectionFailed extends MailboxException
{
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct('Could not connect to the mail server. Check the host, port, and network connection.', 0, $previous);
    }
}
