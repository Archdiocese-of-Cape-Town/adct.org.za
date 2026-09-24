<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Auth;

interface RoleCapabilityStoreInterface
{
    /**
     * @param list<string> $capabilities
     */
    public function addRoleIfMissing(string $role, string $label, array $capabilities): void;

    public function addCapabilityIfMissing(string $role, string $capability): void;
}
