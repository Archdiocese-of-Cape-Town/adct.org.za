<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Database;

use ADCT\ParishIntake\Core\Ports\MigrationStepInterface;
use ADCT\ParishIntake\Core\Ports\SchemaInstallerInterface;

final class CreateSchemaMigration implements MigrationStepInterface
{
    public function __construct(private SchemaInstallerInterface $installer)
    {
    }

    public function version(): int
    {
        return SchemaDefinitions::VERSION;
    }

    public function apply(): void
    {
        foreach (SchemaDefinitions::statements() as $statement) {
            $this->installer->install($statement);
        }
    }
}
