<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\WordPress\Database;

use ADCT\ParishIntake\WordPress\Database\DatabaseConnectionInterface;
use ADCT\ParishIntake\WordPress\Database\MailQueueGroupKeyMigration;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MailQueueGroupKeyMigrationTest extends TestCase
{
    public function testFreshSchemaWithTheCanonicalUniqueIndexIsVerifiedWithoutDdl(): void
    {
        $database = new MailQueueGroupKeyMigrationDatabase();
        $database->indexes = self::expectedIndexRows();
        $migration = new MailQueueGroupKeyMigration($database);

        $migration->apply();

        self::assertSame(5, $migration->version());
        self::assertSame([], $database->queries);
        self::assertSame([], $database->duplicateChecks);
    }

    public function testUpgradeAddsAndVerifiesTheUniqueIndexWithoutChangingRows(): void
    {
        $database = new MailQueueGroupKeyMigrationDatabase();
        $database->existingRowCount = 3;
        $migration = new MailQueueGroupKeyMigration($database);

        $migration->apply();

        self::assertSame(1, count($database->queries));
        self::assertSame(
            'ALTER TABLE `wp_adct_pi_mail_queue` ADD UNIQUE KEY `recipient_group` (`recipient`, `group_key`)',
            $database->queries[0]
        );
        self::assertSame(1, count($database->duplicateChecks));
        self::assertStringContainsString('WHERE `group_key` IS NOT NULL', $database->duplicateChecks[0]);
        self::assertStringContainsString(
            'GROUP BY `recipient`, `group_key`',
            $database->duplicateChecks[0]
        );
        self::assertSame(3, $database->existingRowCount);
        self::assertSame(self::expectedIndexRows(), $database->indexes);
    }

    public function testDuplicateLegacyKeysFailClearlyWithoutDeletingRowsOrAddingTheIndex(): void
    {
        $database = new MailQueueGroupKeyMigrationDatabase();
        $database->duplicateRow = [
            'recipient' => 'synthetic@example.test',
            'group_key' => 'synthetic:duplicate',
            'duplicate_count' => '2',
        ];
        $migration = new MailQueueGroupKeyMigration($database);

        try {
            $migration->apply();
            self::fail('Duplicate legacy recipient/group_key pairs must block the migration.');
        } catch (RuntimeException $failure) {
            self::assertStringContainsString('duplicate recipient/group_key pairs', $failure->getMessage());
            self::assertStringContainsString('No rows were removed', $failure->getMessage());
            self::assertStringNotContainsString('synthetic@example.test', $failure->getMessage());
            self::assertStringNotContainsString('synthetic:duplicate', $failure->getMessage());
        }

        self::assertSame([], $database->queries);
        self::assertSame(2, $database->existingRowCount);
    }

    public function testUnexpectedExistingIndexDefinitionFailsInsteadOfAssumingItIsSafe(): void
    {
        $database = new MailQueueGroupKeyMigrationDatabase();
        $database->indexes = [
            [
                'Non_unique' => 1,
                'Seq_in_index' => 1,
                'Column_name' => 'recipient',
            ],
            [
                'Non_unique' => 1,
                'Seq_in_index' => 2,
                'Column_name' => 'group_key',
            ],
        ];
        $migration = new MailQueueGroupKeyMigration($database);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is not unique');
        $migration->apply();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function expectedIndexRows(): array
    {
        return [
            [
                'Non_unique' => 0,
                'Seq_in_index' => 1,
                'Column_name' => 'recipient',
            ],
            [
                'Non_unique' => 0,
                'Seq_in_index' => 2,
                'Column_name' => 'group_key',
            ],
        ];
    }
}

final class MailQueueGroupKeyMigrationDatabase implements DatabaseConnectionInterface
{
    /**
     * @var array<int, array<string, mixed>>
     */
    public array $indexes = [];

    /**
     * @var list<string>
     */
    public array $queries = [];

    /**
     * @var list<string>
     */
    public array $duplicateChecks = [];

    /**
     * @var array<string, mixed>|null
     */
    public ?array $duplicateRow = null;

    public int $existingRowCount = 2;
    public string $error = '';

    public function prefix(): string
    {
        return 'wp_';
    }

    public function prepare(string $query, mixed ...$arguments): string
    {
        return $query;
    }

    public function query(string $query): int|false
    {
        $this->queries[] = $query;

        if (str_contains($query, 'ADD UNIQUE KEY `recipient_group`')) {
            $this->indexes = [
                [
                    'Non_unique' => 0,
                    'Seq_in_index' => 1,
                    'Column_name' => 'recipient',
                ],
                [
                    'Non_unique' => 0,
                    'Seq_in_index' => 2,
                    'Column_name' => 'group_key',
                ],
            ];
        }

        return 0;
    }

    public function getRow(string $query): ?array
    {
        $this->duplicateChecks[] = $query;

        return $this->duplicateRow;
    }

    public function getResults(string $query): array
    {
        return $this->indexes;
    }

    public function escapeLike(string $text): string
    {
        return addcslashes($text, '_%\\');
    }

    public function insertId(): int
    {
        return 0;
    }

    public function charsetCollate(): string
    {
        return '';
    }

    public function clearLastError(): void
    {
        $this->error = '';
    }

    public function lastError(): string
    {
        return $this->error;
    }
}
