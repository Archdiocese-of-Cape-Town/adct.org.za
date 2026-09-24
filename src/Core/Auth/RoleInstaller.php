<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Auth;

final class RoleInstaller
{
    private RoleCapabilityStoreInterface $roleStore;

    public function __construct(RoleCapabilityStoreInterface $roleStore)
    {
        $this->roleStore = $roleStore;
    }

    public function install(): void
    {
        $roleLabels = Capabilities::customRoleLabels();

        foreach (Capabilities::roleCapabilities() as $role => $capabilities) {
            if (isset($roleLabels[$role])) {
                $this->roleStore->addRoleIfMissing($role, $roleLabels[$role], $capabilities);
            }

            foreach ($capabilities as $capability) {
                $this->roleStore->addCapabilityIfMissing($role, $capability);
            }
        }
    }
}
