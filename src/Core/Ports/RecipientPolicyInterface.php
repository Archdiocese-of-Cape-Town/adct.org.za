<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ports;

interface RecipientPolicyInterface
{
    public function allows(string $normalizedRecipient): bool;
}
