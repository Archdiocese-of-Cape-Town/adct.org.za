<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Auth;

use ADCT\ParishIntake\Core\Auth\RoleCapabilityStoreInterface;
use RuntimeException;

final class WordPressRoleCapabilityStore implements RoleCapabilityStoreInterface
{
    public function addRoleIfMissing(string $role, string $label, array $capabilities): void
    {
        if (get_role($role) !== null) {
            return;
        }

        add_role($role, $label, array_fill_keys($capabilities, true));

        if (get_role($role) === null) {
            throw new RuntimeException('Could not create Parish Intake role: ' . $role);
        }
    }

    public function addCapabilityIfMissing(string $role, string $capability): void
    {
        $roleObject = get_role($role);

        if ($roleObject === null || $roleObject->has_cap($capability)) {
            return;
        }

        $roleObject->add_cap($capability);

        if (! $roleObject->has_cap($capability)) {
            throw new RuntimeException('Could not add capability ' . $capability . ' to role ' . $role);
        }
    }
}
