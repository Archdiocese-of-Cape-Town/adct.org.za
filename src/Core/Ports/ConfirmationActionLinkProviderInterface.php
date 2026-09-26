<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ports;

interface ConfirmationActionLinkProviderInterface
{
    public function urlForToken(string $token): string;
}
