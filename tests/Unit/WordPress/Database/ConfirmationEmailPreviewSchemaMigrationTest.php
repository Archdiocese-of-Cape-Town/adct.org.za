<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\WordPress\Database;

use ADCT\ParishIntake\Core\Database\SchemaDefinitions;
use ADCT\ParishIntake\WordPress\Database\ConfirmationEmailPreviewSchemaMigration;
use ADCT\ParishIntake\WordPress\Database\DatabaseConnectionInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ConfirmationEmailPreviewSchemaMigrationTest extends TestCase
{
    public function testAddsNullablePreviewColumnsAndIsSafeToRetry(): void
    {
        $columns = [];
        $database = $this->databaseMock($columns);
        $migration = new ConfirmationEmailPreviewSchemaMigration($database);

        self::assertSame(7, $migration->version());
        $migration->apply();

        self::assertSame([
            'thread_headers' => ['Type' => 'longtext', 'Null' => 'YES'],
            'payload_fingerprint' => ['Type' => 'char(64)', 'Null' => 'YES'],
            'confirmation_status' => ['Type' => 'varchar(16)', 'Null' => 'YES'],
            'confirmation_reason' => ['Type' => 'varchar(64)', 'Null' => 'YES'],
        ], $columns);

        $migration->apply();
        self::assertCount(4, $columns);
    }

    public function testFreshSchemaContainsTheSameConfirmationColumns(): void
    {
        $statements = SchemaDefinitions::statements();

        self::assertStringContainsString('confirmation_status varchar(16) NULL', $statements['adct_pi_inbound_messages']);
        self::assertStringContainsString('confirmation_reason varchar(64) NULL', $statements['adct_pi_inbound_messages']);
        self::assertStringContainsString('thread_headers longtext NULL', $statements['adct_pi_mail_queue']);
        self::assertStringContainsString('payload_fingerprint char(64) NULL', $statements['adct_pi_mail_queue']);
    }

    public function testRejectsAnExistingColumnWithAnUnexpectedDefinition(): void
    {
        $columns = [
            'thread_headers' => ['Type' => 'varchar(255)', 'Null' => 'YES'],
        ];
        $database = $this->databaseMock($columns);

        $this->expectException(RuntimeException::class);
        (new ConfirmationEmailPreviewSchemaMigration($database))->apply();
    }

    /**
     * @param array<string, array{Type: string, Null: string}> $columns
     */
    private function databaseMock(array &$columns): DatabaseConnectionInterface
    {
        $database = $this->createMock(DatabaseConnectionInterface::class);
        $database->method('prefix')->willReturn('wp_');
        $database->method('prepare')->willReturnCallback(
            static function (string $query, mixed ...$arguments): string {
                return str_replace('%s', "'" . (string) ($arguments[0] ?? '') . "'", $query);
            }
        );
        $database->method('lastError')->willReturn('');
        $database->method('getRow')->willReturnCallback(
            static function (string $query) use (&$columns): ?array {
                if (preg_match("/LIKE '([a-z_]+)'/", $query, $matches) !== 1) {
                    return null;
                }

                return isset($columns[$matches[1]])
                    ? ['Field' => $matches[1]] + $columns[$matches[1]]
                    : null;
            }
        );
        $database->method('query')->willReturnCallback(
            static function (string $query) use (&$columns): int|false {
                if (preg_match('/ADD COLUMN `([a-z_]+)` ([^ ]+)/', $query, $matches) !== 1) {
                    return false;
                }

                $columns[$matches[1]] = ['Type' => strtolower($matches[2]), 'Null' => 'YES'];

                return 0;
            }
        );

        return $database;
    }
}
