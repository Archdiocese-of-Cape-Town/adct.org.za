<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Auth;

enum ActionTokenRenewalStatus: string
{
    case REQUESTED = 'requested';
    case RATE_LIMITED = 'rate_limited';
    case NOT_ELIGIBLE = 'not_eligible';
}
