<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Auth;

use RuntimeException;

final class VersionedRoleInstaller
{
    public const CURRENT_VERSION = 3;

    private RoleInstaller $installer;
    private RoleVersionStoreInterface $versionStore;

    public function __construct(RoleInstaller $installer, RoleVersionStoreInterface $versionStore)
    {
        $this->installer = $installer;
        $this->versionStore = $versionStore;
    }

    public function install(): void
    {
        $this->installer->install();

        if (
            $this->versionStore->getVersion() < self::CURRENT_VERSION
            && ! $this->versionStore->setVersion(self::CURRENT_VERSION)
            && $this->versionStore->getVersion() < self::CURRENT_VERSION
        ) {
            throw new RuntimeException('Could not save the Parish Intake roles version.');
        }
    }

    public function upgradeIfNeeded(): bool
    {
        if ($this->versionStore->getVersion() >= self::CURRENT_VERSION) {
            return false;
        }

        $this->install();

        return true;
    }
}
