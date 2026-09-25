<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Database;

use ADCT\ParishIntake\Core\Ingestion\InboundMessageRecord;
use ADCT\ParishIntake\Core\Ingestion\InboundMessageStoreResult;
use ADCT\ParishIntake\Core\Ports\InboundMessageStoreInterface;
use ADCT\ParishIntake\WordPress\Database\Repository\AttachmentRepository;
use ADCT\ParishIntake\WordPress\Database\Repository\InboundMessageRepository;
use DateTimeZone;
use RuntimeException;
use Throwable;

final class WordPressInboundMessageStore implements InboundMessageStoreInterface
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
}
