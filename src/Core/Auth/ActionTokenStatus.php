<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Auth;

enum ActionTokenStatus: string
{
    case VALID = 'valid';
    case CONSUMED = 'consumed';
    case EXPIRED = 'expired';
    case USED = 'used';
    case INVALID = 'invalid';
}
