<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Database;

use ADCT\ParishIntake\Core\Ports\MigrationStepInterface;
use ADCT\ParishIntake\Core\Ports\SchemaInstallerInterface;

final class VenueSchemaMigration implements MigrationStepInterface
{
    private const VERSION = 2;

    private const SQL = <<<'SQL'
CREATE TABLE {table_prefix}adct_pi_venues (
    id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    parish_id bigint(20) unsigned NOT NULL,
    source_parish_id bigint(20) unsigned NULL,
    name varchar(191) NOT NULL,
    aliases longtext NULL,
    address text NULL,
    suburb varchar(191) NULL,
    latitude decimal(9,6) NULL,
    longitude decimal(9,6) NULL,
    is_default tinyint(1) NOT NULL DEFAULT 0,
    status varchar(20) NOT NULL DEFAULT 'active',
    created_at datetime NOT NULL,
    updated_at datetime NOT NULL,
    PRIMARY KEY  (id),
    UNIQUE KEY source_parish_id (source_parish_id),
    KEY parish_id (parish_id),
    KEY parish_name (parish_id,name),
    KEY parish_status_default (parish_id,status,is_default)
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
