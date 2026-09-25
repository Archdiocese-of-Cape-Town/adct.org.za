<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Database;

use ADCT\ParishIntake\Core\Ports\MigrationStepInterface;
use ADCT\ParishIntake\Core\Ports\SchemaInstallerInterface;

final class ActionTokenRateLimitSchemaMigration implements MigrationStepInterface
{
    private const VERSION = 6;

    private const SQL = <<<'SQL'
CREATE TABLE {table_prefix}adct_pi_action_token_rate_limits (
    scope_hash char(64) NOT NULL,
    window_started_at datetime NOT NULL,
    hit_count int(10) unsigned NOT NULL DEFAULT 0,
    created_at datetime NOT NULL,
    updated_at datetime NOT NULL,
    PRIMARY KEY  (scope_hash),
    KEY window_started_at (window_started_at)
) {charset_collate};
SQL;

    public function __construct(private SchemaInstallerInterface $installer)
    {
    }

    public function version(): int
    {
        return self::VERSION;
    }

    public function apply(): void
    {
        $this->installer->install(self::SQL);
    }
}
