<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Database;

use ADCT\ParishIntake\Core\Ports\MigrationLoggerInterface;
use Throwable;

final class WordPressMigrationLogger implements MigrationLoggerInterface
{
    private const ERROR_OPTION = 'adct_pi_db_migration_error';

    public function migrationFailed(int $version, Throwable $failure): void
    {
        error_log(sprintf(
            'ADCT Parish Intake schema migration %d failed: %s',
            $version,
            $failure->getMessage()
        ));

        if (function_exists('update_option')) {
            update_option(
                self::ERROR_OPTION,
                sprintf(
                    'The Parish Intake database upgrade did not complete (schema version %d). Check the PHP error log or contact your site administrator.',
                    $version
                ),
                false
            );
        }
    }

    public function migrationsSucceeded(int $version): void
    {
        if (function_exists('delete_option')) {
            delete_option(self::ERROR_OPTION);
        }
    }
}
