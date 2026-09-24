<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Jobs;

enum JobRunStatus: string
{
    case LOCKED = 'locked';
    case NOT_DUE = 'not_due';
    case COMPLETED = 'completed';
    case TIME_BUDGET_REACHED = 'time_budget_reached';
    case ITEM_BUDGET_REACHED = 'item_budget_reached';
    case LOCK_LOST = 'lock_lost';
    case FAILED = 'failed';
}
