<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Auth;

/**
 * The purposes an emailed action token can be minted for.
 *
 * A purpose only becomes usable once a handler is registered for it in
 * ADCT\ParishIntake\WordPress\Plugin. A case with no handler mints tokens that
 * no page can act on, so each case below is either handled or deliberately
 * reserved for a named issue. ActionTokenPurposeReservationTest enforces that:
 * it fails on any case that is neither, and records the issue that owns a
 * reserved case. Do not add a case without a handler or a reservation.
 */
enum ActionTokenPurpose: string
{
    case CONFIRM = 'confirm';
    case DENY = 'deny';
    case EDIT = 'edit';

    /**
     * Reserved for #72 (E7.6 front-end dean approval queue, magic-link login).
     * No handler consumes it yet, so a login token is not actioned. The shorter
     * default lifetime is already specified in ADR 0007.
     */
    case LOGIN = 'login';

    case APPROVE_EVENT = 'approve_event';
    case REJECT_EVENT = 'reject_event';

    /**
     * Reserved for #71 (revert a published change from an emailed link). No
     * handler consumes it yet, so a revert token is not actioned.
     */
    case REVERT_CHANGE = 'revert_change';

    public function defaultLifetimeSeconds(): int
    {
        return $this === self::LOGIN ? 30 * 60 : 14 * 24 * 60 * 60;
    }
}
