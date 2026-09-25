<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Mail;

enum ConfirmationEmailReason: string
{
    case BLOCKED_SENDER = 'blocked_sender';
    case AUTOMATED_OR_LIST = 'automated_or_list';
    case NO_SAFE_RECIPIENT = 'no_safe_recipient';
    case NO_CANDIDATES = 'no_candidates';
    case TEST_MODE = 'test_mode_suppressed';
    case DELIVERY_FAILED = 'delivery_failed';
}
