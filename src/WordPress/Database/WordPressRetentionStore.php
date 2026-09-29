<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Database;

use ADCT\ParishIntake\Core\Ports\RetentionStoreInterface;
use ADCT\ParishIntake\WordPress\Ingestion\ProtectedInboundMailStorage;
use RuntimeException;
use Throwable;

final class WordPressRetentionStore implements RetentionStoreInterface
{
    public function __construct(
        private DatabaseConnectionInterface $database,
        private ProtectedInboundMailStorage $storage
    ) {
    }

    public function nextExpiredMessageId(int $afterId, string $cutoff): ?int
    {
        $m = $this->table('inbound_messages');
        $a = $this->table('attachments');
        $row = $this->row($this->database->prepare(
            "SELECT m.id FROM {$m} m WHERE m.id > %d AND m.retention_until < %s "
            . "AND m.status IN ('parsed', 'ignored', 'skipped', 'failed') "
            . "AND (m.raw_path IS NOT NULL OR EXISTS "
            . "(SELECT 1 FROM {$a} a WHERE a.message_id = m.id AND a.storage_path <> '')) "
            . 'ORDER BY m.id ASC LIMIT 1',
            $afterId,
            $cutoff
        ));
        return $row === null ? null : (int) $row['id'];
    }

    public function removeExpiredMessageFiles(int $id, string $cutoff): void
    {
        $m = $this->table('inbound_messages');
        $a = $this->table('attachments');
        $this->write('START TRANSACTION');
        try {
            $message = $this->row($this->database->prepare(
                "SELECT raw_path FROM {$m} WHERE id = %d AND retention_until < %s "
                . "AND status IN ('parsed', 'ignored', 'skipped', 'failed') FOR UPDATE",
                $id,
                $cutoff
            ));
            if ($message !== null) {
                $attachments = $this->rows($this->database->prepare(
                    "SELECT id, storage_path FROM {$a} WHERE message_id = %d ORDER BY id ASC FOR UPDATE",
                    $id
                ));
                if ($message['raw_path'] !== null) {
                    $this->storage->delete((string) $message['raw_path']);
                }
                foreach ($attachments as $attachment) {
                    if ($attachment['storage_path'] !== '') {
                        $this->storage->delete((string) $attachment['storage_path']);
                    }
                }
                $this->write($this->database->prepare(
                    "UPDATE {$m} SET raw_path = NULL, body_text = NULL WHERE id = %d AND retention_until < %s",
                    $id,
                    $cutoff
                ));
                $this->write($this->database->prepare(
                    "UPDATE {$a} SET storage_path = '', extracted_text = NULL WHERE message_id = %d",
                    $id
                ));
            }
            $this->write('COMMIT');
        } catch (Throwable $failure) {
            try {
                $this->write('ROLLBACK');
            } catch (Throwable $rollbackFailure) {
                throw new RuntimeException('Retention cleanup failed and the database rollback failed.', 0, $failure);
            }
            throw $failure;
        }
    }

    public function nextExpiredTokenId(int $afterId, string $cutoff): ?int
    {
        return $this->nextId('action_tokens', 'expires_at', $afterId, $cutoff);
    }

    public function removeExpiredToken(int $id, string $cutoff): void
    {
        $this->deleteExpired('action_tokens', 'expires_at', $id, $cutoff);
    }

    public function nextExpiredAuditId(int $afterId, string $cutoff): ?int
    {
        return $this->nextId('audit_log', 'created_at', $afterId, $cutoff);
    }

    public function removeExpiredAudit(int $id, string $cutoff): void
    {
        $this->deleteExpired('audit_log', 'created_at', $id, $cutoff);
    }

    private function nextId(string $suffix, string $column, int $afterId, string $cutoff): ?int
    {
        $table = $this->table($suffix);
        $row = $this->row($this->database->prepare(
            "SELECT id FROM {$table} WHERE id > %d AND {$column} < %s ORDER BY id ASC LIMIT 1",
            $afterId,
            $cutoff
        ));
        return $row === null ? null : (int) $row['id'];
    }

    private function deleteExpired(string $suffix, string $column, int $id, string $cutoff): void
    {
        $table = $this->table($suffix);
        $this->write($this->database->prepare(
            "DELETE FROM {$table} WHERE id = %d AND {$column} < %s",
            $id,
            $cutoff
        ));
    }

    private function table(string $suffix): string
    {
        return $this->database->prefix() . 'adct_pi_' . $suffix;
    }

    private function row(string $query): ?array
    {
        $this->database->clearLastError();
        $row = $this->database->getRow($query);
        if ($this->database->lastError() !== '') {
            throw new RuntimeException('Retention database read failed: ' . $this->database->lastError());
        }
        return $row;
    }

    private function rows(string $query): array
    {
        $this->database->clearLastError();
        $rows = $this->database->getResults($query);
        if ($this->database->lastError() !== '') {
            throw new RuntimeException('Retention database read failed: ' . $this->database->lastError());
        }
        return $rows;
    }

    private function write(string $query): void
    {
        $this->database->clearLastError();
        if ($this->database->query($query) === false) {
            throw new RuntimeException('Retention database write failed: ' . $this->database->lastError());
        }
    }
}
