<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Auth;

use ADCT\ParishIntake\Core\Ports\ConfirmationActionLinkProviderInterface;

final class WordPressConfirmationActionLinkProvider implements ConfirmationActionLinkProviderInterface
{
    public function urlForToken(string $token): string
    {
        return ActionTokenEndpoint::urlForToken($token);
    }
}
