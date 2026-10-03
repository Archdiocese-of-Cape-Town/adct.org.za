<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\WordPress\Database;

use ADCT\ParishIntake\WordPress\Database\FollowUpParishNullableMigration;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class FollowUpParishNullableMigrationTest extends TestCase
{
    public function testVersionElevenMakesAndVerifiesTheFollowUpParishColumnNullable(): void
    {
        $database = new FollowUpParishMigrationDatabase();
        $migration = new FollowUpParishNullableMigration($database);

        $migration->apply();

        self::assertSame(11, $migration->version());
        self::assertSame(
            'ALTER TABLE `wp_adct_pi_follow_ups` MODIFY COLUMN `parish_id` bigint(20) unsigned NULL',
            $database->queries[0]
        );
        self::assertSame(['parish_id'], $database->preparedQueries[0]['arguments']);
        self::assertStringContainsString(
            'SHOW COLUMNS FROM `wp_adct_pi_follow_ups` LIKE %s',
            $database->preparedQueries[0]['query']
        );
    }

    public function testTheCanonicalFreshInstallSchemaAlreadyMatchesTheMigratedColumn(): void
    {
        $schema = \ADCT\ParishIntake\Core\Database\SchemaDefinitions::statements();
        $followUps = $schema['adct_pi_follow_ups'];

        self::assertStringContainsString('parish_id bigint(20) unsigned NULL', $followUps);
    }

    public function testMigrationSurfacesDdlFailure(): void
    {
        $database = new FollowUpParishMigrationDatabase();
        $database->failAlter = true;
        $migration = new FollowUpParishNullableMigration($database);

        try {
            $migration->apply();
            self::fail('An ALTER TABLE failure must stop the migration.');
        } catch (RuntimeException $failure) {
            self::assertStringContainsString('synthetic DDL failure', $failure->getMessage());
        }
    }

    public function testMigrationFailsWhenTheDatabaseStillReportsNotNull(): void
    {
        $database = new FollowUpParishMigrationDatabase();
        $database->column = ['Null' => 'NO'];
        $migration = new FollowUpParishNullableMigration($database);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('still NOT NULL');
        $migration->apply();
    }
}

final class FollowUpParishMigrationDatabase implements \ADCT\ParishIntake\WordPress\Database\DatabaseConnectionInterface
{
    /**
     * @var list<array{query: string, arguments: array<int, mixed>}>
     */
    public array $preparedQueries = [];

    /**
     * @var list<string>
     */
    public array $queries = [];

    /**
     * @var array<string, mixed>|null
     */
    public ?array $column = ['Null' => 'YES'];

    public bool $failAlter = false;
    public string $error = '';

    public function prefix(): string
    {
        return 'wp_';
    }

    public function prepare(string $query, mixed ...$arguments): string
    {
        $this->preparedQueries[] = ['query' => $query, 'arguments' => $arguments];

        return $query;
    }

    public function query(string $query): int|false
    {
        $this->queries[] = $query;

        if ($this->failAlter) {
            $this->error = 'synthetic DDL failure';

            return false;
        }

        return 0;
    }

    public function getRow(string $query): ?array
    {
        return $this->column;
    }

    public function getResults(string $query): array
    {
        return [];
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