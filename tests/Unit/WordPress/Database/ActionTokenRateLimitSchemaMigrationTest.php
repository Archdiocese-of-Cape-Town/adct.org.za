<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\WordPress\Database;

use ADCT\ParishIntake\Core\Ports\SchemaInstallerInterface;
use ADCT\ParishIntake\WordPress\Database\ActionTokenRateLimitSchemaMigration;
use PHPUnit\Framework\TestCase;

final class ActionTokenRateLimitSchemaMigrationTest extends TestCase
{
    public function testVersionSixAddsIndexedHashedRateLimitBuckets(): void
    {
        $installer = new ActionTokenRateLimitSchemaInstaller();
        $migration = new ActionTokenRateLimitSchemaMigration($installer);

        $migration->apply();

        self::assertSame(6, $migration->version());
        self::assertCount(1, $installer->statements);
        self::assertStringContainsString(
            'CREATE TABLE {table_prefix}adct_pi_action_token_rate_limits',
            $installer->statements[0]
        );
        self::assertStringContainsString('scope_hash char(64) NOT NULL', $installer->statements[0]);
        self::assertStringContainsString('PRIMARY KEY  (scope_hash)', $installer->statements[0]);
        self::assertStringNotContainsString('AUTO_INCREMENT', $installer->statements[0]);
        self::assertStringContainsString('KEY window_started_at (window_started_at)', $installer->statements[0]);
        self::assertStringNotContainsString('email ', $installer->statements[0]);
        self::assertStringNotContainsString('ip ', $installer->statements[0]);
    }
}

final class ActionTokenRateLimitSchemaInstaller implements SchemaInstallerInterface
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
