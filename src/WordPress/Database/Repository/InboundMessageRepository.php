<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Database\Repository;

use ADCT\ParishIntake\Core\Ingestion\AttachmentStoragePolicy;
use ADCT\ParishIntake\Core\Ingestion\InboundMessageRecord;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class InboundMessageRepository extends AbstractRepository
{
    protected const TABLE_SUFFIX = 'adct_pi_inbound_messages';

    protected const FIELD_FORMATS = [
        'source_id' => '%d',
        'external_id' => '%s',
        'content_hash' => '%s',
        'sender_email' => '%s',
        'sender_name' => '%s',
        'subject' => '%s',
        'received_at' => '%s',
        'raw_path' => '%s',
        'body_text' => '%s',
        'auth_results' => '%s',
        'is_auto_reply' => '%d',
        'status' => '%s',
        'error' => '%s',
        'retention_until' => '%s',
        'created_at' => '%s',
        'updated_at' => '%s',
    ];

    public function findDuplicate(int $sourceId, string $externalId, ?string $contentHash): ?int
    {
        if ($sourceId < 1 || $externalId === '') {
            throw new InvalidArgumentException('A duplicate lookup needs a source and external ID.');
        }

        $conditions = ['source_id = %d', 'external_id = %s'];
        $arguments = [$sourceId, $externalId];

        if ($contentHash !== null) {
            $conditions[1] = '(external_id = %s OR content_hash = %s)';
            $arguments[] = $contentHash;
        }

        $arguments[] = $externalId;
        $query = 'SELECT id FROM ' . $this->tableName()
            . ' WHERE ' . $conditions[0] . ' AND ' . $conditions[1]
            . ' ORDER BY CASE WHEN external_id = %s THEN 0 ELSE 1 END, id ASC LIMIT 1';
        $row = $this->fetchRow($this->database->prepare($query, ...$arguments));

        return $row === null ? null : (int) ($row['id'] ?? 0);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findNextForProcessing(): ?array
    {
        return $this->fetchRow($this->database->prepare(
            'SELECT id, source_id, external_id, sender_email, sender_name, subject, received_at, raw_path, is_auto_reply '
            . 'FROM ' . $this->tableName()
            . ' WHERE status IN (%s, %s) ORDER BY id ASC LIMIT 1',
            InboundMessageRecord::STATUS_RECEIVED,
            InboundMessageRecord::STATUS_EXTRACTING
        ));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findForProcessingById(int $messageId): ?array
    {
        if ($messageId < 1) {
            throw new InvalidArgumentException('An inbound message ID must be positive.');
        }

        return $this->fetchRow($this->database->prepare(
            'SELECT id, source_id, external_id, sender_email, sender_name, subject, received_at, raw_path, is_auto_reply '
            . 'FROM ' . $this->tableName()
            . ' WHERE id = %d AND status IN (%s, %s) LIMIT 1',
            $messageId,
            InboundMessageRecord::STATUS_RECEIVED,
            InboundMessageRecord::STATUS_EXTRACTING
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findForInbox(?string $status, int $limit, int $offset): array
    {
        [$where, $arguments] = $this->inboxStatusFilter($status);
        $arguments[] = max(1, min(100, $limit));
        $arguments[] = max(0, $offset);

        return $this->fetchRows($this->database->prepare(
            'SELECT id, sender_email, sender_name, subject, received_at, status, error, is_auto_reply '
            . 'FROM ' . $this->tableName()
            . $where . ' ORDER BY received_at DESC, id DESC LIMIT %d OFFSET %d',
            ...$arguments
        ));
    }

    public function countForInbox(?string $status): int
    {
        [$where, $arguments] = $this->inboxStatusFilter($status);
        $query = 'SELECT COUNT(*) AS total FROM ' . $this->tableName() . $where;
        $row = $this->fetchRow(
            $arguments === []
                ? $query
                : $this->database->prepare($query, ...$arguments)
        );

        return max(0, (int) ($row['total'] ?? 0));
    }

    /**
     * @param list<int> $messageIds
     */
    public function requeueFailedMessages(array $messageIds, string $timestamp): array
    {
        if (count($messageIds) > 20) {
            throw new InvalidArgumentException('A reprocess batch cannot contain more than 20 messages.');
        }

        $uniqueIds = [];

        foreach ($messageIds as $messageId) {
            if (! is_int($messageId) || $messageId < 1) {
                throw new InvalidArgumentException('Every reprocess message ID must be positive.');
            }

            $uniqueIds[$messageId] = $messageId;
        }

        $messageIds = array_values($uniqueIds);

        if ($messageIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($messageIds), '%d'));
        $this->executeTransactionQuery('START TRANSACTION');

        try {
            $eligibleRows = $this->fetchRows($this->database->prepare(
                'SELECT id FROM ' . $this->tableName()
                . ' WHERE status = %s AND id IN (' . $placeholders . ') ORDER BY id ASC FOR UPDATE',
                InboundMessageRecord::STATUS_FAILED,
                ...$messageIds
            ));
            $eligibleIds = [];

            foreach ($eligibleRows as $row) {
                $id = filter_var($row['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

                if (! is_int($id)) {
                    throw new RuntimeException('A failed inbound message has an invalid ID.');
                }

                $eligibleIds[] = $id;
            }

            if ($eligibleIds === []) {
                $this->executeTransactionQuery('COMMIT');

                return [];
            }

            $eligiblePlaceholders = implode(', ', array_fill(0, count($eligibleIds), '%d'));
            $updated = $this->executeWrite($this->database->prepare(
                'UPDATE ' . $this->tableName()
                . ' SET status = %s, error = NULL, updated_at = %s'
                . ' WHERE status = %s AND id IN (' . $eligiblePlaceholders . ')',
                InboundMessageRecord::STATUS_RECEIVED,
                $timestamp,
                InboundMessageRecord::STATUS_FAILED,
                ...$eligibleIds
            ));

            if ($updated !== count($eligibleIds)) {
                throw new RuntimeException('Not every selected failed inbound message could be requeued.');
            }

            $this->executeTransactionQuery('COMMIT');

            return $eligibleIds;
        } catch (Throwable $failure) {
            $this->rollbackTransaction($failure);
            throw $failure;
        }
    }

    public function markExtracting(int $messageId, string $timestamp): bool
    {
        return $this->transitionStatus(
            $messageId,
            [InboundMessageRecord::STATUS_RECEIVED, InboundMessageRecord::STATUS_EXTRACTING],
            InboundMessageRecord::STATUS_EXTRACTING,
            null,
            null,
            $timestamp
        );
    }

    public function markParsed(int $messageId, string $bodyText, string $timestamp): bool
    {
        return $this->transitionStatus(
            $messageId,
            [InboundMessageRecord::STATUS_EXTRACTING],
            InboundMessageRecord::STATUS_PARSED,
            null,
            $bodyText,
            $timestamp
        );
    }

    public function markFailed(int $messageId, string $reason, string $timestamp): bool
    {
        return $this->transitionStatus(
            $messageId,
            [InboundMessageRecord::STATUS_RECEIVED, InboundMessageRecord::STATUS_EXTRACTING],
            InboundMessageRecord::STATUS_FAILED,
            $reason,
            null,
            $timestamp
        );
    }

    public function markIgnored(int $messageId, string $reason, string $timestamp): bool
    {
        return $this->transitionStatus(
            $messageId,
            [InboundMessageRecord::STATUS_RECEIVED, InboundMessageRecord::STATUS_EXTRACTING],
            InboundMessageRecord::STATUS_IGNORED,
            $reason,
            null,
            $timestamp
        );
    }

    /**
     * @return list<array{external_id: string|null, subject: string|null, received_at: string, error: string|null}>
     */
    public function findRecentSkippedMessagesBySourceId(int $sourceId, int $limit = 5): array
    {
        if ($sourceId < 1) {
            throw new InvalidArgumentException('A source ID must be positive.');
        }

        return $this->fetchRows($this->database->prepare(
            'SELECT external_id, subject, received_at, error FROM ' . $this->tableName()
            . ' WHERE source_id = %d AND status = %s ORDER BY id DESC LIMIT %d',
            $sourceId,
            'skipped',
            max(1, min(20, $limit))
        ));
    }

    /**
     * @return list<array{received_at: string, auth_results: string|null, is_auto_reply: int|string}>
     */
    public function findRecentScreeningMessagesBySourceId(int $sourceId, int $limit = 5): array
    {
        if ($sourceId < 1) {
            throw new InvalidArgumentException('A source ID must be positive.');
        }

        return $this->fetchRows($this->database->prepare(
            'SELECT received_at, auth_results, is_auto_reply FROM ' . $this->tableName()
            . ' WHERE source_id = %d AND (is_auto_reply = 1 OR auth_results IS NOT NULL) '
            . 'ORDER BY id DESC LIMIT %d',
            $sourceId,
            max(1, min(20, $limit))
        ));
    }

    /**
     * @return list<array{filename: string, mime_type: string, size_bytes: int|string, status: string}>
     */
    public function findRecentSkippedAttachmentsBySourceId(int $sourceId, int $limit = 5): array
    {
        if ($sourceId < 1) {
            throw new InvalidArgumentException('A source ID must be positive.');
        }

        $attachments = $this->database->prefix() . 'adct_pi_attachments';
        return $this->fetchRows($this->database->prepare(
            'SELECT a.filename, a.mime_type, a.size_bytes, a.status '
            . "FROM {$attachments} a INNER JOIN " . $this->tableName() . ' m ON m.id = a.message_id '
            . 'WHERE m.source_id = %d AND a.status IN (%s, %s, %s) '
            . 'ORDER BY a.id DESC LIMIT %d',
            $sourceId,
            AttachmentStoragePolicy::STATUS_SKIPPED_SIZE,
            AttachmentStoragePolicy::STATUS_SKIPPED_TYPE,
            AttachmentStoragePolicy::STATUS_SKIPPED_SIGNATURE,
            max(1, min(20, $limit))
        ));
    }

    /**
     * @return array{0: string, 1: list<string>}
     */
    private function inboxStatusFilter(?string $status): array
    {
        if ($status === null || $status === '') {
            return ['', []];
        }

        if (! in_array($status, InboundMessageRecord::FILTER_STATUSES, true)) {
            throw new InvalidArgumentException('The requested inbox status is not supported.');
        }

        if ($status === InboundMessageRecord::STATUS_IGNORED) {
            return [
                ' WHERE status IN (%s, %s)',
                [InboundMessageRecord::STATUS_IGNORED, InboundMessageRecord::STATUS_SKIPPED],
            ];
        }

        return [' WHERE status = %s', [$status]];
    }

    /**
     * @param list<string> $eligibleStatuses
     */
    private function transitionStatus(
        int $messageId,
        array $eligibleStatuses,
        string $status,
        ?string $error,
        ?string $bodyText,
        string $timestamp
    ): bool {
        if ($messageId < 1) {
            throw new InvalidArgumentException('An inbound message ID must be positive.');
        }

        $assignments = ['status = %s', 'error = ' . ($error === null ? 'NULL' : '%s'), 'updated_at = %s'];
        $arguments = [$status];

        if ($error !== null) {
            $arguments[] = $error;
        }

        $arguments[] = $timestamp;

        if ($bodyText !== null) {
            $assignments[] = 'body_text = %s';
            $arguments[] = $bodyText;
        }

        $statusPlaceholders = implode(', ', array_fill(0, count($eligibleStatuses), '%s'));
        $arguments[] = $messageId;
        array_push($arguments, ...$eligibleStatuses);

        $query = 'UPDATE ' . $this->tableName()
            . ' SET ' . implode(', ', $assignments)
            . ' WHERE id = %d AND status IN (' . $statusPlaceholders . ')';
        $updated = $this->executeWrite($this->database->prepare($query, ...$arguments));

        if ($updated > 0) {
            return true;
        }

        $row = $this->fetchRow($this->database->prepare(
            'SELECT status FROM ' . $this->tableName() . ' WHERE id = %d LIMIT 1',
            $messageId
        ));

        return ($row['status'] ?? null) === $status;
    }

    private function executeWrite(string $query): int
    {
        $this->database->clearLastError();
        $result = $this->database->query($query);

        if ($result === false) {
            throw new RuntimeException('The inbound message could not be updated: ' . $this->database->lastError());
        }

        return $result;
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
}
