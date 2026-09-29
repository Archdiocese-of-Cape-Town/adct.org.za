<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\WordPress\Database;

use ADCT\ParishIntake\Core\Ports\SchemaInstallerInterface;
use ADCT\ParishIntake\WordPress\Database\SenderSuggestionMigration;
use PHPUnit\Framework\TestCase;

final class SenderSuggestionMigrationTest extends TestCase
{
    public function testVersionTenAddsSeparateUnverifiedParishSuggestions(): void
    {
        $installer = new SenderSuggestionSchemaInstaller();
        $migration = new SenderSuggestionMigration($installer);

        $migration->apply();

        self::assertSame(10, $migration->version());
        self::assertCount(1, $installer->statements);
        self::assertStringContainsString(
            'CREATE TABLE {table_prefix}adct_pi_parish_contacts',
            $installer->statements[0]
        );
        self::assertStringContainsString('suggested_parish_id bigint(20) unsigned NULL', $installer->statements[0]);
        self::assertStringContainsString('suggestion_source varchar(20) NULL', $installer->statements[0]);
        self::assertStringContainsString('UNIQUE KEY parish_email (parish_id,email)', $installer->statements[0]);
    }
}

final class SenderSuggestionSchemaInstaller implements SchemaInstallerInterface
{
    /**
     * @var list<string>
     */
    public array $statements = [];

    public function install(string $sqlTemplate): void
    {
        $this->statements[] = $sqlTemplate;
    }
}
