<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Audit;

/**
 * The subject types an audit row can point at.
 *
 * `subject_id` is the row id in the named table. It is nullable because a few
 * actions — a settings change, or an email address verified across every
 * parish it appears in — are not about one row.
 */
final class AuditSubjectType
{
    /** adct_pi_event_candidates.id */
    public const EVENT_CANDIDATE = 'event_candidate';

    /** adct_pi_event_changes.id */
    public const EVENT_CHANGE = 'event_change';

    /** adct_pi_parish_contacts.id */
    public const PARISH_CONTACT = 'parish_contact';

    /** adct_pi_parishes.id */
    public const PARISH = 'parish';

    /** wp_posts.ID of the published event. */
    public const EVENT = 'event';

    /** A plugin setting; subject_id is null. */
    public const SETTINGS = 'settings';

    /**
     * An approver's own notification preference; subject_id is the WordPress
     * user ID, not a row of a plugin table. A dean may approve for more than
     * one deanery, so this deliberately does not point at a single assignment
     * (issue #169).
     */
    public const APPROVAL_PREFERENCE = 'approval_preference';

    /**
     * Human labels for the filter dropdown, keyed by subject type.
     *
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::EVENT_CANDIDATE => 'Event candidate',
            self::EVENT_CHANGE => 'Event change',
            self::PARISH_CONTACT => 'Parish contact',
            self::PARISH => 'Parish',
            self::EVENT => 'Published event',
            self::SETTINGS => 'Plugin settings',
            self::APPROVAL_PREFERENCE => 'Approver email preference',
        ];
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_keys(self::labels());
    }

    private function __construct()
    {
    }
}