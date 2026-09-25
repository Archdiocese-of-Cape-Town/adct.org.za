<?php

namespace ADCT\ParishIntake\Core\Ports;

use ADCT\ParishIntake\Core\Ingestion\MailboxSearchCriteria;
use ADCT\ParishIntake\Core\Ingestion\RawMailMessage;

interface MailboxInterface
{
    /** @return list<string> */
    public function listFolders(): array;

    public function ensureFolder(string $folder): void;

    public function uidValidity(?string $folder = null): int;

    public function uidNext(string $folder): int;

    /** @return list<int> */
    public function search(MailboxSearchCriteria $criteria): array;

    /** @return list<int> */
    public function searchFolder(MailboxSearchCriteria $criteria, string $folder): array;

    /** @throws \ADCT\ParishIntake\Core\Ingestion\Imap\MessageTooLarge */
    public function fetch(int $uid): RawMailMessage;

    public function move(int $uid, string $folder): void;

    public function delete(int $uid, string $folder): void;

    public function markSeen(int $uid): void;

    public function close(): void;
}
