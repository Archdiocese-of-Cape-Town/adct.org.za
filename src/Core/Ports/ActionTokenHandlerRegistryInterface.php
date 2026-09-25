<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ports;

use ADCT\ParishIntake\Core\Auth\ActionTokenPurpose;

interface ActionTokenHandlerRegistryInterface
{
    public function forPurpose(ActionTokenPurpose $purpose): ?ActionTokenActionHandlerInterface;
}
