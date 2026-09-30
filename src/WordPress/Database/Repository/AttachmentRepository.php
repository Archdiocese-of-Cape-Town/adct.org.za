<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Database\Repository;

use ADCT\ParishIntake\Core\Pdf\PdfExtractionResult;
use InvalidArgumentException;

final class AttachmentRepository extends AbstractRepository
{
    protected const TABLE_SUFFIX = 'adct_pi_attachments';

    protected const FIELD_FORMATS = [
        'message_id' => '%d',
        'filename' => '%s',
        'mime_type' => '%s',
        'size_bytes' => '%d',
        'storage_path' => '%s',
        'content_hash' => '%s',
        'extracted_text' => '%s',
        'extraction_method' => '%s',
        'status' => '%s',
        'created_at' => '%s',
        'updated_at' => '%s',
    ];

    /**
     * @return list<array<string, mixed>>
     */
    public function findByMessageIdAndMimeType(int $messageId, string $mimeType): array
    {
        if ($messageId < 1) {
            throw new InvalidArgumentException('A message ID must be positive.');
        }

        return $this->fetchRows($this->database->prepare(
            'SELECT id, filename, mime_type, size_bytes, storage_path, extraction_method, status '
            . 'FROM ' . $this->tableName()
            . ' WHERE message_id = %d AND mime_type = %s ORDER BY id ASC',
            $messageId,
            $mimeType
        ));
    }

    /**
     * A single attachment by ID.
     *
     * The candidate detail screen's download button posts an attachment ID, so
     * the handler needs to look the row up and then confirm the attachment
     * belongs to the candidate the reviewer opened. `extracted_text` is left out
     * because a download never needs it.
     *
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        if ($id < 1) {
            throw new InvalidArgumentException('An attachment ID must be positive.');
        }

        return $this->fetchRow($this->database->prepare(
            'SELECT id, message_id, filename, mime_type, size_bytes, storage_path, status'
            . ' FROM ' . $this->tableName()
            . ' WHERE id = %d',
            $id
        ));
    }

    /**
     * Every attachment on a message, in the order it arrived.
     *
     * The candidate detail screen shows what a parish sent so the reviewer can
     * check the poster or bulletin the event was read from. `extracted_text` is
     * deliberately left out: it can be the whole document, and the screen does not
     * need it.
     *
     * @return list<array<string, mixed>>
     */
    public function findByMessageId(int $messageId): array
    {
        if ($messageId < 1) {
        throw new InvalidArgumentException('A message ID must be positive.');
        }

        return $this->fetchRows($this->database->prepare(
        'SELECT id, filename, mime_type, size_bytes, storage_path, extraction_method, status'
        . ' FROM ' . $this->tableName()
        . ' WHERE message_id = %d ORDER BY id ASC',
        $messageId
        ));
    }

    /**
     * The most recent PDFs that were received but produced no usable text.
     *
     * These are the posters an operator has to enter by hand, so the Manual
     * parser screen lists them instead of leaving the reason only in the log.
     *
     * @return list<array{filename: string, status: string, updated_at: string}>
     */
    public function findRecentUnreadablePdfs(int $limit = 5): array
    {
        return $this->fetchRows($this->database->prepare(
            'SELECT filename, status, updated_at FROM ' . $this->tableName()
            . ' WHERE mime_type = %s AND status IN (%s, %s, %s, %s, %s, %s)'
            . ' ORDER BY id DESC LIMIT %d',
            PdfExtractionResult::MIME_TYPE,
            PdfExtractionResult::STATUS_NO_TEXT_LAYER,
            PdfExtractionResult::STATUS_SKIPPED_SIZE,
            PdfExtractionResult::STATUS_SKIPPED_PAGE_LIMIT,
            PdfExtractionResult::STATUS_SKIPPED_TIMEOUT,
            PdfExtractionResult::STATUS_FAILED,
            max(1, min(50, $limit))
        ));
    }
}
