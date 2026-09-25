<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ports;

use ADCT\ParishIntake\Core\Ingestion\MailboxCheckpoint;

interface MailboxCheckpointStoreInterface
{
    public function findCheckpoint(int $sourceId): ?MailboxCheckpoint;

    public function saveCheckpoint(int $sourceId, MailboxCheckpoint $checkpoint, string $timestamp): void;
}
