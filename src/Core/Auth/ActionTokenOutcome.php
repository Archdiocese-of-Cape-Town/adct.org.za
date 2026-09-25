<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Auth;

use InvalidArgumentException;

final class ActionTokenOutcome
{
    public function __construct(public readonly string $message)
    {
        if (trim($message) === '') {
            throw new InvalidArgumentException('An action token outcome needs display text.');
        }
    }
}
