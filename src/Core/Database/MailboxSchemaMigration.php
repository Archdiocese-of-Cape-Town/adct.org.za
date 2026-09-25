<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Database;

use ADCT\ParishIntake\Core\Ports\MigrationStepInterface;
use ADCT\ParishIntake\Core\Ports\SchemaInstallerInterface;

final class MailboxSchemaMigration implements MigrationStepInterface
{
    private const VERSION = 3;

    private const SQL = <<<'SQL'
CREATE TABLE {table_prefix}adct_pi_mailboxes (
    id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    source_id bigint(20) unsigned NOT NULL,
    label varchar(191) NOT NULL,
    host varchar(253) NOT NULL,
    port smallint(5) unsigned NOT NULL DEFAULT 993,
    encryption varchar(20) NOT NULL DEFAULT 'ssl',
    username varchar(191) NOT NULL,
    inbox_folder varchar(191) NOT NULL DEFAULT 'INBOX',
    processed_folder varchar(191) NOT NULL DEFAULT 'Processed',
    max_message_size_bytes bigint(20) unsigned NOT NULL DEFAULT 31457280,
    active tinyint(1) NOT NULL DEFAULT 1,
    created_at datetime NOT NULL,
    updated_at datetime NOT NULL,
    PRIMARY KEY  (id),
    UNIQUE KEY source_id (source_id),
    KEY active (active)
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
