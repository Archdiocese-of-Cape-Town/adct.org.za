<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ports;

use ADCT\ParishIntake\Core\Auth\ActionTokenBinding;
use ADCT\ParishIntake\Core\Auth\IssuedActionToken;

interface ActionTokenLoginDeliveryInterface
{
    /**
     * Put the magic link into the mail queue for this recipient.
     *
     * Throwing aborts the request, so an implementation must either queue the
     * message or say the link could not be sent.
     */
    public function deliver(ActionTokenBinding $binding, IssuedActionToken $issuedToken): void;
}