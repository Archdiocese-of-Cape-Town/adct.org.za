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
     * Sign in to the front-end approval queue (#72, E7.6), with the magic link
     * specified in ADR 0007. Handled by LoginHandler, which re-resolves the live
     * user — active, still holding an approval capability — on both the GET
     * preview and the POST, so a revoked dean cannot ride an old link.
     *
     * The shorter default lifetime is ADR 0007's 30 minutes, not the 14 days the
     * decision-making purposes get: the link only has to survive long enough to
     * walk from the inbox to the queue.
     */
    case LOGIN = 'login';

    case APPROVE_EVENT = 'approve_event';
    case REJECT_EVENT = 'reject_event';

    /**
     * Undo one recorded change to an event, from the link in a change notice.
     * Implemented in #71 by RevertChangeHandler, which restores the before
     * snapshot and re-resolves the recipient's live approver role at act time.
     *
     * The token names the change, not the person: whoever holds it has to still
     * be an approver for the parish when they press the button.
     */
    case REVERT_CHANGE = 'revert_change';

    public function defaultLifetimeSeconds(): int
    {
        return $this === self::LOGIN ? 30 * 60 : 14 * 24 * 60 * 60;
    }
}
