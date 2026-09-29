<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ports;

use ADCT\ParishIntake\Core\Ingestion\MailboxMoveReceipt;
use ADCT\ParishIntake\Core\Ingestion\MailboxSettings;
use DateTimeImmutable;

interface ProcessedMailboxMessageStoreInterface
{
    public function recordMoved(
        MailboxSettings $settings,
        MailboxMoveReceipt $receipt,
        DateTimeImmutable $internalDate,
        DateTimeImmutable $recordedAt
    ): void;

    /** @return list<int> */
    public function findExpired(
        MailboxSettings $settings,
        int $uidValidity,
        DateTimeImmutable $cutoff,
        int $limit
    ): array;

    public function discardStale(MailboxSettings $settings, int $currentUidValidity): void;

    public function deleteOwned(MailboxSettings $settings, int $uidValidity, int $uid): void;
}
