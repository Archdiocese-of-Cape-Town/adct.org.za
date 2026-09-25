<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Database;

use ADCT\ParishIntake\Core\Ports\MigrationStepInterface;
use RuntimeException;

final class OccurrenceParishNullableMigration implements MigrationStepInterface
{
    private const VERSION = 4;

    public function __construct(private DatabaseConnectionInterface $database)
    {
    }

    public function version(): int
    {
        return self::VERSION;
    }

    public function apply(): void
    {
        $prefix = $this->database->prefix();

        if (preg_match('/\A[A-Za-z0-9_]+\z/', $prefix) !== 1) {
            throw new RuntimeException('The WordPress database prefix cannot be used for the occurrence migration.');
        }

        $table = '`' . $prefix . 'adct_pi_occurrences`';
        $this->database->clearLastError();
        $result = $this->database->query(
            "ALTER TABLE {$table} MODIFY COLUMN `parish_id` bigint(20) unsigned NULL"
        );

        if ($result === false) {
            throw new RuntimeException(
                'The occurrence parish nullability migration failed: ' . $this->database->lastError()
            );
        }

        $this->database->clearLastError();
        $column = $this->database->getRow(
            $this->database->prepare(
                "SHOW COLUMNS FROM {$table} LIKE %s",
                'parish_id'
            )
        );
        $error = $this->database->lastError();

        if ($error !== '') {
            throw new RuntimeException(
                'The occurrence parish nullability could not be verified: ' . $error
            );
        }

        if (! is_array($column) || strtoupper((string) ($column['Null'] ?? '')) !== 'YES') {
            throw new RuntimeException('The occurrence parish column is still NOT NULL after migration.');
        }
    }
}
