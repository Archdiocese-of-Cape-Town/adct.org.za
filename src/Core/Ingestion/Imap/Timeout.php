<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ingestion\Imap;

use Throwable;

final class Timeout extends MailboxException
{
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct('The mail server did not respond before the connection timed out.', 0, $previous);
    }
}
