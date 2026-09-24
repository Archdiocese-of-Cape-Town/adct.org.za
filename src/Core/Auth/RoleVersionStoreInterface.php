<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Auth;

interface RoleVersionStoreInterface
{
    public function getVersion(): int;

    public function setVersion(int $version): bool;
}
