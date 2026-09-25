<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Database\Repository;

use ADCT\ParishIntake\Core\Ingestion\AttachmentStoragePolicy;
use InvalidArgumentException;

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
     * @return list<array{
     *     received_at: string,
     *     auth_results: string|null,
     *     is_auto_reply: int|string,
     *     confirmation_status: string|null,
     *     confirmation_reason: string|null
     * }>
     */
    public function findRecentScreeningMessagesBySourceId(int $sourceId, int $limit = 5): array
    {
        if ($sourceId < 1) {
            throw new InvalidArgumentException('A source ID must be positive.');
        }

        return $this->fetchRows($this->database->prepare(
            'SELECT received_at, auth_results, is_auto_reply, confirmation_status, confirmation_reason '
            . 'FROM ' . $this->tableName()
            . ' WHERE source_id = %d AND (is_auto_reply = 1 OR auth_results IS NOT NULL '
            . 'OR confirmation_status IS NOT NULL) '
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
}
