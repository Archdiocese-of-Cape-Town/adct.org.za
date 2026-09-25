<?php

namespace ADCT\ParishIntake\Core\Ports;

use ADCT\ParishIntake\Core\Ingestion\MailboxSearchCriteria;
use ADCT\ParishIntake\Core\Ingestion\RawMailMessage;

interface MailboxInterface
{
    /** @return list<string> */
    public function listFolders(): array;

    public function ensureFolder(string $folder): void;

    /** @return list<int> */
    public function search(MailboxSearchCriteria $criteria): array;

    /** @throws \ADCT\ParishIntake\Core\Ingestion\Imap\MessageTooLarge */
    public function fetch(int $uid): RawMailMessage;

    public function move(int $uid, string $folder): void;

    public function markSeen(int $uid): void;

    public function close(): void;
}
