<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Auth;

use ADCT\ParishIntake\Core\Auth\RoleVersionStoreInterface;

final class WordPressRoleVersionStore implements RoleVersionStoreInterface
{
    private const OPTION_NAME = 'adct_pi_roles_version';

    public function getVersion(): int
    {
        return max(0, (int) get_option(self::OPTION_NAME, 0));
    }

    public function setVersion(int $version): bool
    {
        if ($this->getVersion() >= $version) {
            return true;
        }

        $updated = update_option(self::OPTION_NAME, $version, false);

        return $updated || $this->getVersion() >= $version;
    }
}
