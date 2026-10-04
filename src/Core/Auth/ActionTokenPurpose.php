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

    /**
     * Take one published event off the events page, from the link in a change
     * notice. ADR 0008 point 4 puts Revert and Unpublish side by side: a change
     * that moved an event to the wrong parish is not fixed by restoring the old
     * fields, because the event itself was never ours to publish. Implemented in
     * #71 by UnpublishEventHandler.
     *
     * Unpublishing is terminal for the post but not for the record. It appends an
     * `unpublish` row to the same trail a revert appends a `revert` row to, so
     * "who took this down, and what was live before" stays answerable from the
     * history rather than from a trashed post.
     *
     * Separate from REVERT_CHANGE rather than a flag on it: the two answer
     * different questions, only one of them is undoable from the trail, and a
     * token that could mean either would be a credential for both.
     */
    case UNPUBLISH_EVENT = 'unpublish_event';

    /**
     * Move one approver between per-item email and the daily digest (#169).
     * Implemented by NotifyModeChangeHandler, reached from the link in the
     * approval email so a dean who has never had a wp-admin account can set
     * the mode without an administrator acting for them.
     *
     * The token carries no authority: it names the wp_user_id to change, and
     * the handler re-resolves that user's live, active deanery assignments on
     * both the GET preview and the POST, so a dean removed from every deanery
     * in the meantime cannot change anything with an old link.
     *
     * Seven days rather than the fourteen the decision-making purposes get:
     * the link arrives with the next notice the recipient reads, and a
     * preference is the one link in the set that only ever needs to be walked
     * from the inbox once. Seven days keeps it valid across a weekend and a
     * quiet Monday, and expires it before it can be found months later.
     */
    case CHANGE_NOTIFY_MODE = 'change_notify_mode';

    public function defaultLifetimeSeconds(): int
    {
        return match ($this) {
            self::LOGIN => 30 * 60,
            self::CHANGE_NOTIFY_MODE => 7 * 24 * 60 * 60,
            default => 14 * 24 * 60 * 60,
        };
    }
}
