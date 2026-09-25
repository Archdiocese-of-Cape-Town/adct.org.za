<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ingestion\Imap;

use Throwable;

final class ProtocolError extends MailboxException
{
    public function __construct(
        string $message = 'The mailbox server returned an unexpected response.',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
