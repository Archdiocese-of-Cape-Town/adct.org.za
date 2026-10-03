<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Auth;

/**
 * The answer a magic-link request gives back to its caller.
 *
 * There is deliberately no "no such account" value. ADR 0007 requires the
 * request to answer identically whether or not the address exists, so
 * `SENT` covers both, and the caller renders one page either way. Only
 * RATE_LIMITED is distinct, because telling a requester to wait is not a
 * disclosure and is what stops the queue being flooded.
 */
enum MagicLinkLoginStatus: string
{
    case SENT = 'sent';
    case RATE_LIMITED = 'rate_limited';
}