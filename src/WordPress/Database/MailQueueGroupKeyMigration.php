<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Database;

use ADCT\ParishIntake\Core\Ports\MigrationStepInterface;
use RuntimeException;

final class MailQueueGroupKeyMigration implements MigrationStepInterface
{
    private const VERSION = 5;
    private const INDEX_NAME = 'recipient_group';

    public function __construct(private DatabaseConnectionInterface $database)
    {
    }

    public function version(): int
    {
        return self::VERSION;
    }

    public function apply(): void
    {
        $table = $this->tableName();
        $indexRows = $this->indexRows($table);

        if ($indexRows !== []) {
            $this->assertExpectedIndex($indexRows);

            return;
        }

        $this->assertNoDuplicateKeys($table);

        $this->database->clearLastError();
        $result = $this->database->query(
            "ALTER TABLE {$table} ADD UNIQUE KEY `" . self::INDEX_NAME . '` (`recipient`, `group_key`)'
        );
        $error = $this->database->lastError();

        if ($result === false || $error !== '') {
            throw new RuntimeException(
                'The mail queue recipient/group_key index could not be added; check for duplicate legacy keys. No rows were removed.'
            );
        }

        $indexRows = $this->indexRows($table);

        if ($indexRows === []) {
            throw new RuntimeException('The mail queue recipient/group_key index is missing after migration.');
        }

        $this->assertExpectedIndex($indexRows);
    }

    private function tableName(): string
    {
        $prefix = $this->database->prefix();

        if (preg_match('/\A[A-Za-z0-9_]+\z/', $prefix) !== 1) {
            throw new RuntimeException('The WordPress database prefix cannot be used for the mail queue migration.');
        }

        return '`' . $prefix . 'adct_pi_mail_queue`';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function indexRows(string $table): array
    {
        $this->database->clearLastError();
        $rows = $this->database->getResults($this->database->prepare(
            "SHOW INDEX FROM {$table} WHERE Key_name = %s",
            self::INDEX_NAME
        ));

        if ($this->database->lastError() !== '') {
            throw new RuntimeException('The mail queue recipient/group_key index could not be inspected.');
        }

        return $rows;
    }

    private function assertNoDuplicateKeys(string $table): void
    {
        $this->database->clearLastError();
        $duplicate = $this->database->getRow(
            'SELECT `recipient`, `group_key`, COUNT(*) AS duplicate_count'
            . ' FROM ' . $table
            . ' WHERE `group_key` IS NOT NULL'
            . ' GROUP BY `recipient`, `group_key`'
            . ' HAVING COUNT(*) > 1 LIMIT 1'
        );

        if ($this->database->lastError() !== '') {
            throw new RuntimeException(
                'Duplicate mail queue recipient/group_key rows could not be checked; schema version 5 was not applied.'
            );
        }

        if ($duplicate !== null) {
            throw new RuntimeException(
                'The mail queue contains duplicate recipient/group_key pairs; resolve the collisions before schema version 5. No rows were removed.'
            );
        }
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function assertExpectedIndex(array $rows): void
    {
        $columns = [];

        foreach ($rows as $row) {
            if ((int) ($row['Non_unique'] ?? 1) !== 0) {
                throw new RuntimeException('The mail queue recipient_group index is not unique.');
            }

            $sequence = (int) ($row['Seq_in_index'] ?? 0);

            if ($sequence < 1 || $sequence > 2 || isset($columns[$sequence])) {
                throw new RuntimeException('The mail queue recipient_group index has an invalid column sequence.');
            }

            $columns[$sequence] = (string) ($row['Column_name'] ?? '');
        }

        ksort($columns, SORT_NUMERIC);

        if (count($rows) !== 2 || array_values($columns) !== ['recipient', 'group_key']) {
            throw new RuntimeException('The mail queue recipient_group index has an unexpected definition.');
        }
    }
}
