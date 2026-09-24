<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Database;

use ADCT\ParishIntake\Core\Ports\SchemaInstallerInterface;
use RuntimeException;

final class DbDeltaSchemaInstaller implements SchemaInstallerInterface
{
    public function __construct(private DatabaseConnectionInterface $database)
    {
    }

    public function install(string $sqlTemplate): void
    {
        if (! function_exists('dbDelta')) {
            if (! defined('ABSPATH')) {
                throw new RuntimeException('WordPress upgrade helpers are unavailable.');
            }

            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }

        $sql = str_replace(
            ['{table_prefix}', '{charset_collate}'],
            [$this->database->prefix(), $this->database->charsetCollate()],
            $sqlTemplate
        );

        if (str_contains($sql, '{table_prefix}') || str_contains($sql, '{charset_collate}')) {
            throw new RuntimeException('The database schema SQL contains an unresolved placeholder.');
        }

        $this->database->clearLastError();
        dbDelta($sql);

        $error = $this->database->lastError();

        if ($error !== '') {
            throw new RuntimeException('dbDelta failed: ' . $error);
        }
    }
}
