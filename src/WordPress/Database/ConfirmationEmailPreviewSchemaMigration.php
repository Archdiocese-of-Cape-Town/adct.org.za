<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Database;

use ADCT\ParishIntake\Core\Ports\MigrationStepInterface;
use RuntimeException;

final class ConfirmationEmailPreviewSchemaMigration implements MigrationStepInterface
{
    private const VERSION = 7;

    public function __construct(private readonly DatabaseConnectionInterface $database)
    {
    }

    public function version(): int
    {
        return self::VERSION;
    }

    public function apply(): void
    {
        $this->ensureColumn(
            'adct_pi_mail_queue',
            'thread_headers',
            'longtext NULL AFTER `group_key`',
            'longtext'
        );
        $this->ensureColumn(
            'adct_pi_mail_queue',
            'payload_fingerprint',
            'char(64) NULL AFTER `thread_headers`',
            'char(64)'
        );
        $this->ensureColumn(
            'adct_pi_inbound_messages',
            'confirmation_status',
            'varchar(16) NULL AFTER `retention_until`',
            'varchar(16)'
        );
        $this->ensureColumn(
            'adct_pi_inbound_messages',
            'confirmation_reason',
            'varchar(64) NULL AFTER `confirmation_status`',
            'varchar(64)'
        );
    }

    private function ensureColumn(
        string $tableSuffix,
        string $columnName,
        string $definition,
        string $expectedType
    ): void {
        $table = $this->quotedTableName($tableSuffix);
        $column = $this->column($table, $columnName);

        if ($column === null) {
            $this->database->clearLastError();
            $result = $this->database->query(
                "ALTER TABLE {$table} ADD COLUMN `{$columnName}` {$definition}"
            );
            $error = $this->database->lastError();

            if ($result === false || $error !== '') {
                throw new RuntimeException(
                    'A confirmation preview schema column could not be added; no existing data was changed.'
                );
            }

            $column = $this->column($table, $columnName);
        }

        if (
            $column === null
            || strtolower((string) ($column['Type'] ?? '')) !== $expectedType
            || strtoupper((string) ($column['Null'] ?? '')) !== 'YES'
        ) {
            throw new RuntimeException(
                'A confirmation preview schema column has an unexpected definition.'
            );
        }
    }

    private function quotedTableName(string $suffix): string
    {
        $prefix = $this->database->prefix();

        if (preg_match('/\A[A-Za-z0-9_]+\z/', $prefix) !== 1) {
            throw new RuntimeException('The WordPress database prefix cannot be used for the confirmation preview migration.');
        }

        return '`' . $prefix . $suffix . '`';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function column(string $table, string $columnName): ?array
    {
        $this->database->clearLastError();
        $column = $this->database->getRow($this->database->prepare(
            "SHOW COLUMNS FROM {$table} LIKE %s",
            $columnName
        ));

        if ($this->database->lastError() !== '') {
            throw new RuntimeException(
                'A confirmation preview schema column could not be inspected.'
            );
        }

        return $column;
    }
}
