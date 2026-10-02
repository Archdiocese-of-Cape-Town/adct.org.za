<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Database;

use ADCT\ParishIntake\Core\Ports\MigrationStepInterface;
use ADCT\ParishIntake\Core\Ports\SchemaInstallerInterface;

final class ProcessedMailboxOwnershipSchemaMigration implements MigrationStepInterface
{
    private const VERSION = 9;

    private const SQL = <<<'SQL'
CREATE TABLE {table_prefix}adct_pi_processed_mail_ownership (
    id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    source_id bigint(20) unsigned NOT NULL,
    mailbox_identity char(64) NOT NULL,
    processed_folder varchar(191) NOT NULL,
    uid_validity bigint(20) unsigned NOT NULL,
    uid bigint(20) unsigned NOT NULL,
    internal_date datetime NOT NULL,
    created_at datetime NOT NULL,
    updated_at datetime NOT NULL,
    PRIMARY KEY  (id),
    UNIQUE KEY owned_message (source_id,mailbox_identity,uid_validity,uid),
    KEY retention_lookup (source_id,mailbox_identity,uid_validity,internal_date,uid)
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
