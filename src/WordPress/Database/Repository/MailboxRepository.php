<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Database\Repository;

use ADCT\ParishIntake\Core\Ingestion\Imap\MailboxEncryption;
use ADCT\ParishIntake\Core\Ingestion\MailboxSettings;
use ADCT\ParishIntake\Core\Ingestion\MailboxSettingsValidator;
use ADCT\ParishIntake\Core\Ports\MailboxSettingsStoreInterface;
use DomainException;
use InvalidArgumentException;
use RuntimeException;

final class MailboxRepository extends AbstractRepository implements MailboxSettingsStoreInterface
{
    protected const TABLE_SUFFIX = 'adct_pi_mailboxes';

    protected const FIELD_FORMATS = [
        'source_id' => '%d',
        'label' => '%s',
        'host' => '%s',
        'port' => '%d',
        'encryption' => '%s',
        'username' => '%s',
        'inbox_folder' => '%s',
        'processed_folder' => '%s',
        'max_message_size_bytes' => '%d',
        'active' => '%d',
        'created_at' => '%s',
        'updated_at' => '%s',
    ];

    public function findMailboxById(int $mailboxId): ?MailboxSettings
    {
        if ($mailboxId < 1) {
            return null;
        }

        $row = $this->fetchRow($this->database->prepare(
            'SELECT * FROM ' . $this->tableName() . ' WHERE id = %d LIMIT 1',
            $mailboxId
        ));

        return $row === null ? null : $this->mapMailbox($row);
    }

    public function findMailboxBySourceId(int $sourceId): ?MailboxSettings
    {
        if ($sourceId < 1) {
            throw new InvalidArgumentException('A source ID must be positive.');
        }

        $row = $this->fetchRow($this->database->prepare(
            'SELECT * FROM ' . $this->tableName() . ' WHERE source_id = %d LIMIT 1',
            $sourceId
        ));

        return $row === null ? null : $this->mapMailbox($row);
    }

    /**
     * @return list<MailboxSettings>
     */
    public function findAllMailboxes(): array
    {
        $rows = $this->fetchRows(
            'SELECT * FROM ' . $this->tableName() . ' ORDER BY label ASC, id ASC'
        );

        return array_map(fn (array $row): MailboxSettings => $this->mapMailbox($row), $rows);
    }

    public function findActiveMailboxes(): array
    {
        $sources = $this->database->prefix() . 'adct_pi_sources';
        $rows = $this->fetchRows(
            'SELECT m.* FROM ' . $this->tableName() . " m INNER JOIN {$sources} s ON s.id = m.source_id "
            . 'WHERE m.active = 1 AND s.type = \'email\' AND s.parish_id IS NULL AND s.status = \'active\' '
            . 'ORDER BY m.id ASC'
        );

        return array_map(fn (array $row): MailboxSettings => $this->mapMailbox($row), $rows);
    }

    public function saveMailbox(MailboxSettings $settings, string $timestamp): MailboxSettings
    {
        if ($settings->sourceId < 1) {
            throw new InvalidArgumentException('A mailbox must be linked to a source before it is saved.');
        }

        if ($settings->id > 0) {
            $current = $this->findMailboxById($settings->id);

            if ($current === null) {
                throw new DomainException('The mailbox could not be found.');
            }

            $linkedMailbox = $this->findMailboxBySourceId($settings->sourceId);

            if ($linkedMailbox !== null && $linkedMailbox->id !== $settings->id) {
                throw new DomainException('This source is already linked to another mailbox.');
            }

            $this->update($settings->id, $this->values($settings, $timestamp));
            $mailboxId = $settings->id;
        } else {
            if ($this->findMailboxBySourceId($settings->sourceId) !== null) {
                throw new DomainException('This source is already linked to a mailbox.');
            }

            $values = $this->values($settings, $timestamp);
            $values['created_at'] = $timestamp;
            $mailboxId = $this->insert($values);
        }

        $saved = $this->findMailboxById($mailboxId);

        if ($saved === null) {
            throw new RuntimeException('The saved mailbox settings could not be read back.');
        }

        return $saved;
    }

    /**
     * @return array<string, scalar|null>
     */
    private function values(MailboxSettings $settings, string $timestamp): array
    {
        return [
            'source_id' => $settings->sourceId,
            'label' => $settings->label,
            'host' => $settings->host,
            'port' => $settings->port,
            'encryption' => $settings->encryption->value,
            'username' => $settings->username,
            'inbox_folder' => $settings->inboxFolder,
            'processed_folder' => $settings->processedFolder,
            'max_message_size_bytes' => $settings->maxMessageSizeBytes,
            'active' => $settings->active ? 1 : 0,
            'updated_at' => $timestamp,
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapMailbox(array $row): MailboxSettings
    {
        $mailboxId = (int) ($row['id'] ?? 0);
        $sourceId = (int) ($row['source_id'] ?? 0);
        $storedSizeBytes = (int) ($row['max_message_size_bytes'] ?? 0);
        $bytesPerMegabyte = 1024 * 1024;

        if (
            $mailboxId < 1
            || $sourceId < 1
            || $storedSizeBytes < 1
            || $storedSizeBytes % $bytesPerMegabyte !== 0
        ) {
            throw new RuntimeException('A stored mailbox has invalid IDs or message-size settings.');
        }

        $encryption = MailboxEncryption::tryFrom((string) ($row['encryption'] ?? ''));

        if ($encryption === null || $encryption === MailboxEncryption::NONE) {
            throw new RuntimeException('A stored mailbox has an unsupported encryption setting.');
        }

        try {
            return (new MailboxSettingsValidator())->validate([
                'label' => (string) ($row['label'] ?? ''),
                'host' => (string) ($row['host'] ?? ''),
                'port' => (string) ($row['port'] ?? ''),
                'encryption' => $encryption->value,
                'username' => (string) ($row['username'] ?? ''),
                'inbox_folder' => (string) ($row['inbox_folder'] ?? ''),
                'processed_folder' => (string) ($row['processed_folder'] ?? ''),
                'max_message_size_mb' => (string) intdiv($storedSizeBytes, $bytesPerMegabyte),
                'active' => (int) ($row['active'] ?? 1),
            ], $mailboxId, $sourceId);
        } catch (InvalidArgumentException $failure) {
            throw new RuntimeException('A stored mailbox has invalid settings.', 0, $failure);
        }
    }
}
