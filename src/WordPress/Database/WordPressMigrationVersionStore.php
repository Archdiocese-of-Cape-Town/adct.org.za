<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Database;

use ADCT\ParishIntake\Core\Ports\MigrationVersionStoreInterface;

final class WordPressMigrationVersionStore implements MigrationVersionStoreInterface
{
    private const OPTION = 'adct_pi_db_version';

    public function getVersion(): int
    {
        $version = get_option(self::OPTION, 0);

        if (is_int($version) && $version >= 0) {
            return $version;
        }

        if (is_string($version) && ctype_digit($version)) {
            return (int) $version;
        }

        return 0;
    }

    public function setVersion(int $version): bool
    {
        return update_option(self::OPTION, $version, false)
            || $this->getVersion() >= $version;
    }
}
