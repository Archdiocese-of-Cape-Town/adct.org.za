<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ports;

use ADCT\ParishIntake\Core\Auth\ActionTokenBinding;
use ADCT\ParishIntake\Core\Auth\IssuedActionToken;

interface ActionTokenRenewalDeliveryInterface
{
    public function deliver(ActionTokenBinding $binding, IssuedActionToken $issuedToken): void;
}
