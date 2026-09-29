<?php

declare(strict_types=1);

namespace {
    if (! function_exists('dbDelta')) {
        function dbDelta(string $sql): void
        {
            $GLOBALS['adct_pi_approval_test_schema'] = $sql;
        }
    }
}

namespace ADCT\ParishIntake\Tests\Unit\WordPress\Database {
    use ADCT\ParishIntake\WordPress\Database\ApprovalNoticesMigration;
    use ADCT\ParishIntake\WordPress\Database\DatabaseConnectionInterface;
    use ADCT\ParishIntake\WordPress\Database\DbDeltaSchemaInstaller;
    use PHPUnit\Framework\TestCase;

    final class ApprovalNoticesMigrationTest extends TestCase
    {
        public function testVersionedSchemaHasRecipientIdempotencyAndRecoveryIndex(): void
        {
            $database = $this->createMock(DatabaseConnectionInterface::class);
            $database->method('prefix')->willReturn('wp_');
            $database->method('charsetCollate')->willReturn('DEFAULT CHARSET=utf8mb4');
            $database->method('lastError')->willReturn('');
            $migration = new ApprovalNoticesMigration(new DbDeltaSchemaInstaller($database));

            $migration->apply();

            self::assertSame(8, $migration->version());
            $sql = $GLOBALS['adct_pi_approval_test_schema'];
            self::assertStringContainsString('CREATE TABLE wp_adct_pi_approval_notices', $sql);
            self::assertStringContainsString('UNIQUE KEY candidate_recipient (candidate_id,recipient)', $sql);
            self::assertStringContainsString('KEY pending_group (queued_at,group_key)', $sql);
            self::assertStringContainsString('queued_at datetime NULL', $sql);
            self::assertStringContainsString('updated_at datetime NOT NULL', $sql);
            unset($GLOBALS['adct_pi_approval_test_schema']);
        }
    }
}
