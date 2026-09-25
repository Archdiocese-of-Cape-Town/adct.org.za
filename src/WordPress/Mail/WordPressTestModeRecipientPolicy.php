<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Mail;

use ADCT\ParishIntake\Core\Ports\RecipientPolicyInterface;

final class WordPressTestModeRecipientPolicy implements RecipientPolicyInterface
{
    public function allows(string $normalizedRecipient): bool
    {
        return WordPressTestModeSettings::current()->allowsRecipient($normalizedRecipient);
    }
}
