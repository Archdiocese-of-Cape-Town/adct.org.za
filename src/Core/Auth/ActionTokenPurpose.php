<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Auth;

enum ActionTokenPurpose: string
{
    case CONFIRM = 'confirm';
    case DENY = 'deny';
    case EDIT = 'edit';
    case LOGIN = 'login';
    case PUBLISH_FOUND = 'publish_found';
    case APPROVE_EVENT = 'approve_event';
    case REJECT_EVENT = 'reject_event';
    case REVERT_CHANGE = 'revert_change';

    public function defaultLifetimeSeconds(): int
    {
        return $this === self::LOGIN ? 30 * 60 : 14 * 24 * 60 * 60;
    }
}
