<?php

namespace ADCT\ParishIntake\Core\Ports;

use ADCT\ParishIntake\Core\Parsing\Input\Message;

interface MailboxInterface
{
    /**
     * Provisional: the signature will be refined by its first consumer, IMAP intake in E2.1 (#35).
     *
     * @return Message[]
     */
    public function fetchUnseen(int $limit): array;

    public function markProcessed(Message $message): void;
}
