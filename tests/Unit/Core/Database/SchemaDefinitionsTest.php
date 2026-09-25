<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Core\Database;

use ADCT\ParishIntake\Core\Database\CreateSchemaMigration;
use ADCT\ParishIntake\Core\Database\SchemaDefinitions;
use ADCT\ParishIntake\Core\Database\VenueSchemaMigration;
use ADCT\ParishIntake\Core\Ports\SchemaInstallerInterface;
use PHPUnit\Framework\TestCase;

final class SchemaDefinitionsTest extends TestCase
{
    private const TABLES = [
        'adct_pi_deaneries',
        'adct_pi_deanery_approvers',
        'adct_pi_parishes',
        'adct_pi_venues',
        'adct_pi_parish_contacts',
        'adct_pi_sources',
        'adct_pi_inbound_messages',
        'adct_pi_attachments',
        'adct_pi_event_candidates',
        'adct_pi_occurrences',
        'adct_pi_event_changes',
        'adct_pi_action_tokens',
        'adct_pi_follow_ups',
        'adct_pi_audit_log',
        'adct_pi_mail_queue',
    ];

    public function testSchemaVersionIsOne(): void
    {
        self::assertSame(1, SchemaDefinitions::VERSION);
    }

    public function testEveryDocumentedCustomTableHasADefinition(): void
    {
        self::assertSame(self::TABLES, array_keys(SchemaDefinitions::statements()));
    }

    public function testGeneratedStatementsFollowDbDeltaShape(): void
    {
        foreach (SchemaDefinitions::statements() as $table => $sql) {
            self::assertStringStartsWith(
                'CREATE TABLE {table_prefix}' . $table . ' (',
                $sql,
                $table
            );
            self::assertStringEndsWith(') {charset_collate};', $sql, $table);
            self::assertSame(1, substr_count($sql, '{charset_collate}'), $table);
            self::assertStringContainsString('    PRIMARY KEY  (id),', $sql, $table);
            self::assertSame(0, preg_match('/,\s*\)/', $sql), $table);
        }
    }

    public function testCandidateDefinitionIncludesApprovalStateAndAuditFields(): void
    {
        $candidate = SchemaDefinitions::statements()['adct_pi_event_candidates'];

        foreach ([
            "status varchar(32) NOT NULL DEFAULT 'draft'",
            'confirmed_by varchar(191) NULL',
            'confirmed_at datetime NULL',
            'approved_by varchar(191) NULL',
            'approved_at datetime NULL',
            'approved_via varchar(32) NULL',
        ] as $column) {
            self::assertStringContainsString($column, $candidate);
        }
    }

    public function testCreateSchemaMigrationInstallsEveryStatement(): void
    {
        $installer = new RecordingSchemaInstaller();
        $migration = new CreateSchemaMigration($installer);

        $migration->apply();

        self::assertSame(SchemaDefinitions::VERSION, $migration->version());
        self::assertSame(array_values(SchemaDefinitions::statements()), $installer->statements);
    }

    public function testVenueSchemaMigrationAddsVersionTwoColumnsWithoutChangingV1(): void
    {
        $installer = new RecordingSchemaInstaller();
        $migration = new VenueSchemaMigration($installer);

        $migration->apply();

        self::assertSame(1, SchemaDefinitions::VERSION);
        self::assertSame(2, $migration->version());
        self::assertCount(1, $installer->statements);

        foreach ([
            'CREATE TABLE {table_prefix}adct_pi_venues',
            'aliases longtext NULL',
            'is_default tinyint(1) NOT NULL DEFAULT 0',
            "status varchar(20) NOT NULL DEFAULT 'active'",
            'source_parish_id bigint(20) unsigned NULL',
            'UNIQUE KEY source_parish_id (source_parish_id)',
        ] as $fragment) {
            self::assertStringContainsString($fragment, $installer->statements[0]);
        }
    }
}

final class RecordingSchemaInstaller implements SchemaInstallerInterface
{
    /**
     * @var string[]
     */
    public array $statements = [];

    public function install(string $sqlTemplate): void
    {
        $this->statements[] = $sqlTemplate;
    }
}
