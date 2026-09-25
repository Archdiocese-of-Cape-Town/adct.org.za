<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Mail;

use ADCT\ParishIntake\Core\Ports\RecipientPolicyInterface;

final class AllowAllRecipientPolicy implements RecipientPolicyInterface
{
    public function allows(string $normalizedRecipient): bool
    {
        return true;
    }
}
