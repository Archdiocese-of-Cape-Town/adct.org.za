<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Database;

use ADCT\ParishIntake\Core\Ingestion\InboundMessageRecord;
use ADCT\ParishIntake\Core\Ingestion\InboundMessageProcessingRecord;
use ADCT\ParishIntake\Core\Ingestion\InboundMessageStoreResult;
use ADCT\ParishIntake\Core\Ports\InboundMessageStoreInterface;
use ADCT\ParishIntake\Core\Ports\InboundMessageProcessingStoreInterface;
use ADCT\ParishIntake\WordPress\Database\Repository\AttachmentRepository;
use ADCT\ParishIntake\WordPress\Database\Repository\InboundMessageRepository;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class WordPressInboundMessageStore implements
    InboundMessageStoreInterface,
    InboundMessageProcessingStoreInterface
{
    public function __construct(
        private DatabaseConnectionInterface $database,
        private InboundMessageRepository $messages,
        private AttachmentRepository $attachments
    ) {
    }

    public function findDuplicate(int $sourceId, string $externalId, ?string $contentHash): ?int
    {
        return $this->messages->findDuplicate($sourceId, $externalId, $contentHash);
    }

    public function store(InboundMessageRecord $message, string $timestamp): InboundMessageStoreResult
    {
        $this->beginTransaction();

        try {
            $duplicateId = $this->messages->findDuplicate(
                $message->sourceId,
                $message->externalId,
                $message->contentHash
            );

            if ($duplicateId !== null) {
                $this->commitTransaction();

                return new InboundMessageStoreResult($duplicateId, true);
            }

            $receivedAt = $message->receivedAt->setTimezone(new DateTimeZone('UTC'));
            $values = [
                'source_id' => $message->sourceId,
                'external_id' => $message->externalId,
                'content_hash' => $message->contentHash,
                'sender_email' => $message->senderEmail,
                'sender_name' => $message->senderName,
                'subject' => $message->subject,
                'received_at' => $receivedAt->format('Y-m-d H:i:s'),
                'raw_path' => $message->rawPath,
                'body_text' => null,
                'auth_results' => $message->authResults === null || $message->authResults->isEmpty()
                    ? null
                    : $message->authResults->toJson(),
                'is_auto_reply' => $message->isAutoReply ? 1 : 0,
                'status' => $message->status,
                'error' => $message->error,
                'retention_until' => $receivedAt->modify('+12 months')->format('Y-m-d H:i:s'),
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
            $messageId = $this->messages->insert($values);

            foreach ($message->attachments as $attachment) {
                $this->attachments->insert([
                    'message_id' => $messageId,
                    'filename' => $attachment->filename,
                    'mime_type' => $attachment->mimeType,
                    'size_bytes' => $attachment->sizeBytes,
                    'storage_path' => $attachment->storagePath,
                    'content_hash' => $attachment->contentHash,
                    'extracted_text' => null,
                    'extraction_method' => 'none',
                    'status' => $attachment->status,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]);
            }

            $this->commitTransaction();

            return new InboundMessageStoreResult($messageId, false);
        } catch (Throwable $failure) {
            $this->rollbackTransaction($failure);
            throw $failure;
        }
    }

    public function findNextForProcessing(): ?InboundMessageProcessingRecord
    {
        $row = $this->messages->findNextForProcessing();

        return $row === null ? null : $this->processingRecordFromRow($row);
    }

    public function findForProcessingById(int $messageId): ?InboundMessageProcessingRecord
    {
        $row = $this->messages->findForProcessingById($messageId);

        return $row === null ? null : $this->processingRecordFromRow($row);
    }

    public function markExtracting(int $messageId, string $timestamp): bool
    {
        return $this->messages->markExtracting($messageId, $timestamp);
    }

    public function markParsed(int $messageId, string $bodyText, string $timestamp): bool
    {
        return $this->messages->markParsed($messageId, $bodyText, $timestamp);
    }

    public function markFailed(int $messageId, string $reason, string $timestamp): bool
    {
        return $this->messages->markFailed($messageId, $reason, $timestamp);
    }

    public function markIgnored(int $messageId, string $reason, string $timestamp): bool
    {
        return $this->messages->markIgnored($messageId, $reason, $timestamp);
    }

    public function requeueFailedMessages(array $messageIds, string $timestamp): array
    {
        return $this->messages->requeueFailedMessages($messageIds, $timestamp);
    }

    private function beginTransaction(): void
    {
        $this->executeTransactionQuery('START TRANSACTION');
    }

    private function commitTransaction(): void
    {
        $this->executeTransactionQuery('COMMIT');
    }

    private function rollbackTransaction(Throwable $originalFailure): void
    {
        $this->database->clearLastError();
        $result = $this->database->query('ROLLBACK');

        if ($result === false) {
            throw new RuntimeException(
                'The inbound message transaction could not be rolled back.',
                0,
                $originalFailure
            );
        }
    }

    private function executeTransactionQuery(string $query): void
    {
        $this->database->clearLastError();
        $result = $this->database->query($query);

        if ($result === false) {
            throw new RuntimeException(
                'The inbound message transaction could not be completed: ' . $this->database->lastError()
            );
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function processingRecordFromRow(array $row): InboundMessageProcessingRecord
    {
        $id = $this->positiveId($row['id'] ?? null, 'inbound message');
        $sourceId = $this->positiveId($row['source_id'] ?? null, 'inbound message source');
        $externalId = is_string($row['external_id'] ?? null) ? $row['external_id'] : '';

        if ($externalId === '' || strlen($externalId) > 191) {
            $externalId = 'inbound-row-' . $id;
        }

        return new InboundMessageProcessingRecord(
            $id,
            $sourceId,
            $externalId,
            $this->nullableString($row['sender_email'] ?? null),
            $this->nullableString($row['sender_name'] ?? null),
            is_string($row['subject'] ?? null) ? $row['subject'] : '',
            $this->receivedAt($row['received_at'] ?? null),
            $this->nullableString($row['raw_path'] ?? null),
            in_array($row['is_auto_reply'] ?? null, [1, '1', true], true)
        );
    }

    private function positiveId(mixed $value, string $label): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if (! is_int($id)) {
            throw new InvalidArgumentException('The stored ' . $label . ' ID is invalid.');
        }

        return $id;
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function receivedAt(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        $receivedAt = DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            $value,
            new DateTimeZone('UTC')
        );
        $errors = DateTimeImmutable::getLastErrors();

        if (
            $receivedAt === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $receivedAt->format('Y-m-d H:i:s') !== $value
        ) {
            return null;
        }

        return $receivedAt;
    }
}
