<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ports;

use ADCT\ParishIntake\Core\Ingestion\MailboxMoveReceipt;

interface MailboxMoveReceiptProviderInterface
{
    /**
     * Returns null when the server does not provide an exact destination UID mapping.
     */
    public function moveWithReceipt(int $uid, string $folder): ?MailboxMoveReceipt;
}
