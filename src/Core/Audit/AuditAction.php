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
            self::EVENT_PUBLISHED => 'Event published',
            self::CONTACT_VERIFIED => 'Contact verified',
            self::CONTACT_BLOCKED => 'Contact blocked',
            self::CONTACT_UNBLOCKED => 'Contact unblocked',
            self::CONTACT_LINKED => 'Contact linked',
            self::CONTACT_CONFIRMED => 'Contact confirmed',
            self::CONTACT_EDITED => 'Contact edited',
            self::CONTACT_REMOVED => 'Contact removed',
            self::SETTINGS_UPDATED => 'Settings updated',
        };
    }
}