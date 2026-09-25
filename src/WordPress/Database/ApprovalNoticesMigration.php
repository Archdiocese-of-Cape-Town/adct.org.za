<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Database;

use ADCT\ParishIntake\Core\Ports\MigrationStepInterface;

final class ApprovalNoticesMigration implements MigrationStepInterface
{
    public function __construct(private readonly DbDeltaSchemaInstaller $installer)
    {
    }

    public function version(): int
    {
        return 8;
    }

    public function apply(): void
    {
        $this->installer->install(<<<'SQL'
CREATE TABLE {table_prefix}adct_pi_approval_notices (
    id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    candidate_id bigint(20) unsigned NOT NULL,
    recipient varchar(191) NOT NULL,
    group_key varchar(191) NOT NULL,
    notify_mode varchar(20) NOT NULL,
    queued_at datetime NULL,
    created_at datetime NOT NULL,
    updated_at datetime NOT NULL,
    PRIMARY KEY  (id),
    UNIQUE KEY candidate_recipient (candidate_id,recipient),
    KEY pending_group (queued_at,group_key)
) {charset_collate};
SQL);
    }
}
