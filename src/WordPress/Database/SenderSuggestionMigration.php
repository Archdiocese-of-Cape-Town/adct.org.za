<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Database;

use ADCT\ParishIntake\Core\Ports\MigrationStepInterface;
use ADCT\ParishIntake\Core\Ports\SchemaInstallerInterface;

final class SenderSuggestionMigration implements MigrationStepInterface
{
    public function __construct(private SchemaInstallerInterface $installer)
    {
    }

    public function version(): int
    {
        return 7;
    }

    public function apply(): void
    {
        $this->installer->install(<<<'SQL'
CREATE TABLE {table_prefix}adct_pi_parish_contacts (
    id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    parish_id bigint(20) unsigned NOT NULL,
    email varchar(191) NOT NULL,
    display_name varchar(191) NULL,
    role_label varchar(191) NULL,
    trust varchar(20) NOT NULL DEFAULT 'unknown',
    suggested_parish_id bigint(20) unsigned NULL,
    suggestion_source varchar(20) NULL,
    verified_at datetime NULL,
    wp_user_id bigint(20) unsigned NULL,
    last_seen_at datetime NULL,
    receives_reminders tinyint(1) NOT NULL DEFAULT 1,
    created_at datetime NOT NULL,
    updated_at datetime NOT NULL,
    PRIMARY KEY  (id),
    UNIQUE KEY parish_email (parish_id,email),
    KEY trust (trust),
    KEY wp_user_id (wp_user_id)
) {charset_collate};
SQL);
    }
}
