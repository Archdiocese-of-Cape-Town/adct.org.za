<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Audit;

/**
 * The complete catalogue of audit actions this plugin writes.
 *
 * The `action` column is a free-text varchar, so nothing at the database level
 * stops a typo from being logged as an action that the admin screen does not
 * list as a filter option. Every write site therefore names its action with a
 * case from this enum, and AuditActionTest fails on any action literal written
 * straight into a query.
 *
 * These values are already stored in fixtures and read back by tests, so they
 * are append-only: add a case, never rename or remove one. Nothing here deletes
 * or edits a row; the log is append-only by design, and only the retention job
 * in E2.7 ever removes rows.
 */
enum AuditAction: string
{
    /** An approver published a candidate from their own screen. */
    case APPROVER_APPROVED = 'approver_approved';

    /** An approver refused a candidate. */
    case APPROVER_REJECTED = 'approver_rejected';

    /** An approver corrected fields on a candidate before deciding it. */
    case APPROVER_EDITED = 'approver_edited';

    /** The submitter confirmed their own submission. */
    case SUBMITTER_CONFIRMED = 'submitter_confirmed';

    /** The submitter refused their own submission. */
    case SUBMITTER_DENIED = 'submitter_denied';

    /** A reviewer moved a candidate to a parish from the review queue. */
    case CANDIDATE_PARISH_ASSIGNED = 'candidate_parish_assigned';

    /** A reviewer edited candidate fields from the review queue. */
    case CANDIDATE_EDITED = 'candidate_edited';

    /** A recorded change to a published event was undone. */
    case CHANGE_REVERTED = 'change_reverted';

        /**
         * A published event was taken off the events page by an approver.
         *
         * A new verb rather than a flag on `change_reverted`, because the two
         * records answer different questions and the difference is the whole reason
         * to keep them apart. `change_reverted` says the amendment was undone and
         * the event that was live is live again; `event_unpublished` says the event
         * itself was wrong and is now gone from the public list. Reusing
         * `event_published` for the removal would make the audit log claim the
         * opposite of what happened, and reusing `change_reverted` would hide a
         * terminal action behind a word that promises the old state comes back.
         */
        case EVENT_UNPUBLISHED = 'event_unpublished';

    /** An approved candidate became a published event. */
    case EVENT_PUBLISHED = 'event_published';

    /** A sender address was verified against a parish. */
    case CONTACT_VERIFIED = 'contact_verified';

    /** A sender address was blocked, so it can never self-approve. */
    case CONTACT_BLOCKED = 'contact_blocked';

    /** A previously blocked sender address was allowed again. */
    case CONTACT_UNBLOCKED = 'contact_unblocked';

    /** A sender address was linked to a parish contact record. */
    case CONTACT_LINKED = 'contact_linked';

    /** A sender confirmed, by link, that they belong to a parish. */
    case CONTACT_CONFIRMED = 'contact_confirmed';

    /** A stored parish contact link was edited. */
    case CONTACT_EDITED = 'contact_edited';

    /** A stored parish contact link was removed. */
    case CONTACT_REMOVED = 'contact_removed';

    /** A plugin setting was changed. The details diff carries the values. */
    case SETTINGS_UPDATED = 'settings_updated';

        /** An approver created a blank event to type in beside a stored poster. */
            case CANDIDATE_CREATED_BY_HAND = 'candidate_created_by_hand';

    /**
     * An approver resolved an ambiguous match from the candidate detail screen
     * (issue #177).
     *
     * Deliberately its own verb rather than another `candidate_parish_assigned`:
     * the two acts are audited for different reasons. Assigning a parish is a
     * routing correction; resolving a match also removes the two `fields` keys
     * that block approval, so a trail that showed only the assignment would not
     * record that anybody ever cleared the block.
     */
    case CANDIDATE_MATCH_RESOLVED = 'candidate_match_resolved';

    /**
     * An approver changed their own per-item/daily notification mode from the
     * link in their own approval email, without an administrator doing it for
     * them (issue #169). The details diff carries the before and after modes.
     *
     * Deliberately its own verb rather than another `settings_updated`: that
     * action is an administrator changing site-wide plugin settings, with the
     * settings screen as its subject. This is a dean changing one field of
     * their own approver record, and it is the act that proves a link in their
     * mail was honoured, so a trail that filed it under `settings_updated`
     * would both misattribute who changed what and hide every self-service
     * change behind a generic settings row. The two sets coexist: reusing a
     * value for a second meaning would read historical rows back wrongly.
     */
    case APPROVER_NOTIFY_MODE_CHANGED = 'approver_notify_mode_changed';

    /**
     * A reviewer re-sent the confirmation preview to the submitter from the
     * candidate detail screen (issue #176).
     *
     * Its own verb because it is not a field change and not a decision: it puts a
     * message in front of a real person outside the archdiocese, which is worth a
     * row in the trail under POPIA even though nothing about the candidate
     * changed.
     *
     * The details identify the candidate and its inbound message, not the
     * recipient. The recipient is deliberately not duplicated here: the claim is
     * made before the address is resolved, and the queued mail row already records
     * exactly who was written to. Read the two together — this row says who asked
     * and when, the queue row says who received it.
     */
    case CANDIDATE_CONFIRMATION_RESENT = 'candidate_confirmation_resent';

    /**
         * A person chose to make an event's source material publicly viewable
         * (issue #172, ADR 0025).
         *
         * Under POPIA this is the row that answers "who made this bulletin public",
         * so it names the acting user, the event, and the intake attachment that was
         * copied. It is separate from `event_published` on purpose: publishing an
         * event and making the parish's poster world-readable are two different
         * acts, by two different people, at two different moments, and a trail that
         * filed the second under the first would claim the material was made public
         * by whoever happened to approve the event — which is exactly what ADR 0025
         * forbids happening automatically.
         *
         * The details carry the generated stored name, never a path derived from
         * the parish's filename.
         */
        case SOURCE_MATERIAL_PROMOTED = 'source_material_promoted';

        /**
         * A person took an event's source material back out of public view.
         *
         * Its own verb rather than a flag on the promotion: removal is a visibility
         * change and not a deletion (the stored file stays and the promotion can be
         * repeated), so a reader of the trail needs to be able to tell "was made
         * public and then was not" from "was never public". Filing both under one
         * value would make the last state of every document ambiguous.
         */
        case SOURCE_MATERIAL_REMOVED = 'source_material_removed';

        /**
         * Human labels for the filter dropdown, keyed by action value.
     *
     * @return array<string, string>
     */
    public static function labels(): array
    {
        $labels = [];

        foreach (self::cases() as $case) {
            $labels[$case->value] = self::labelFor($case);
        }

        return $labels;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    private static function labelFor(self $case): string
    {
        return match ($case) {
            self::APPROVER_APPROVED => 'Approver approved',
            self::APPROVER_REJECTED => 'Approver rejected',
            self::APPROVER_EDITED => 'Approver edited',
            self::SUBMITTER_CONFIRMED => 'Submitter confirmed',
            self::SUBMITTER_DENIED => 'Submitter denied',
            self::CANDIDATE_PARISH_ASSIGNED => 'Parish assigned',
            self::CANDIDATE_EDITED => 'Candidate edited',
            self::CHANGE_REVERTED => 'Change reverted',
            self::EVENT_UNPUBLISHED => 'Event taken off the events page',
            self::EVENT_PUBLISHED => 'Event published',
            self::CONTACT_VERIFIED => 'Contact verified',
            self::CONTACT_BLOCKED => 'Contact blocked',
            self::CONTACT_UNBLOCKED => 'Contact unblocked',
            self::CONTACT_LINKED => 'Contact linked',
            self::CONTACT_CONFIRMED => 'Contact confirmed',
            self::CONTACT_EDITED => 'Contact edited',
            self::CONTACT_REMOVED => 'Contact removed',
            self::SETTINGS_UPDATED => 'Settings updated',
            self::CANDIDATE_CREATED_BY_HAND => 'Event started by hand',
            self::CANDIDATE_MATCH_RESOLVED => 'Ambiguous match resolved',
            self::APPROVER_NOTIFY_MODE_CHANGED => 'Approver email mode changed',
            self::CANDIDATE_CONFIRMATION_RESENT => 'Confirmation preview resent',
                        self::SOURCE_MATERIAL_PROMOTED => 'Source material published with event',
                        self::SOURCE_MATERIAL_REMOVED => 'Source material taken off the event',
                    };
    }
}