<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ports;

use ADCT\ParishIntake\Core\Auth\ActionTokenBinding;

interface ActionTokenLoginSubjectResolverInterface
{
    /**
     * The LOGIN binding for an address, or null when no active account
     * entitled to the front-end approval queue owns it.
     *
     * Returning null must not be distinguishable to the caller: the request
     * page answers the same either way (ADR 0007).
     */
    public function bindingFor(string $email): ?ActionTokenBinding;
}