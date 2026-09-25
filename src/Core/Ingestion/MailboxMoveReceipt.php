<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ingestion;

use InvalidArgumentException;

final readonly class MailboxMoveReceipt
{
    public function __construct(
        public int $destinationUidValidity,
        public int $destinationUid
    ) {
        if (
            $destinationUidValidity < 1
            || $destinationUidValidity > MailboxCheckpoint::MAX_UID
            || $destinationUid < 1
            || $destinationUid > MailboxCheckpoint::MAX_UID
        ) {
            throw new InvalidArgumentException('A mailbox move receipt needs valid destination identifiers.');
        }
    }
}
