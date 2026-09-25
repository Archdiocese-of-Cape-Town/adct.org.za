<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ports;

use ADCT\ParishIntake\Core\Ingestion\InboundMessageProcessingRecord;

interface InboundMessageProcessingStoreInterface
{
    public const MAX_REPROCESS_BATCH = 20;

    public function findNextForProcessing(): ?InboundMessageProcessingRecord;

    public function findForProcessingById(int $messageId): ?InboundMessageProcessingRecord;

    public function markExtracting(int $messageId, string $timestamp): bool;

    public function markParsed(int $messageId, string $bodyText, string $timestamp): bool;

    public function markIgnored(int $messageId, string $reason, string $timestamp): bool;

    public function markFailed(int $messageId, string $reason, string $timestamp): bool;

    /**
     * Requeue only failed messages. Their stored raw files, attachments, identity and mailbox checkpoints stay intact.
     *
     * @param list<int> $messageIds
     * @return list<int> IDs that were requeued from failed to received
     */
    public function requeueFailedMessages(array $messageIds, string $timestamp): array;
}
