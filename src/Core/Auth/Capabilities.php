<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Auth;

final class Capabilities
{
    public const MANAGE_SETTINGS = 'adct_pi_manage_settings';
    public const MANAGE_DIRECTORY = 'adct_pi_manage_directory';
    public const REVIEW = 'adct_pi_review';
    public const VIEW_REPORTS = 'adct_pi_view_reports';
    public const APPROVE_DEANERY = 'adct_pi_approve_deanery';
    public const EDIT_EVENTS = 'edit_events';
    public const EDIT_OTHERS_EVENTS = 'edit_others_events';
    public const EDIT_PRIVATE_EVENTS = 'edit_private_events';
    public const EDIT_PUBLISHED_EVENTS = 'edit_published_events';
    public const PUBLISH_EVENTS = 'publish_events';
    public const READ_PRIVATE_EVENTS = 'read_private_events';
    public const DELETE_EVENTS = 'delete_events';
    public const DELETE_PRIVATE_EVENTS = 'delete_private_events';
    public const DELETE_PUBLISHED_EVENTS = 'delete_published_events';
    public const DELETE_OTHERS_EVENTS = 'delete_others_events';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::MANAGE_SETTINGS,
            self::MANAGE_DIRECTORY,
            self::REVIEW,
            self::VIEW_REPORTS,
            self::APPROVE_DEANERY,
            ...self::eventCapabilities(),
        ];
    }

    /**
     * @return list<string>
     */
    public static function eventCapabilities(): array
    {
        return [
            self::EDIT_EVENTS,
            self::EDIT_OTHERS_EVENTS,
            self::EDIT_PRIVATE_EVENTS,
            self::EDIT_PUBLISHED_EVENTS,
            self::PUBLISH_EVENTS,
            self::READ_PRIVATE_EVENTS,
            self::DELETE_EVENTS,
            self::DELETE_PRIVATE_EVENTS,
            self::DELETE_PUBLISHED_EVENTS,
            self::DELETE_OTHERS_EVENTS,
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    public static function roleCapabilities(): array
    {
        return [
            'administrator' => self::all(),
            'editor' => [
                self::REVIEW,
                self::VIEW_REPORTS,
                ...self::eventCapabilities(),
            ],
            'adct_pi_intake_manager' => [
                self::MANAGE_SETTINGS,
                self::MANAGE_DIRECTORY,
                self::REVIEW,
                self::VIEW_REPORTS,
                'read',
                ...self::eventCapabilities(),
            ],
            'adct_pi_intake_reviewer' => [
                self::REVIEW,
                self::VIEW_REPORTS,
                'read',
                ...self::eventCapabilities(),
            ],
            'parish_contact' => [
                'read',
            ],
            'deanery_approver' => [
                self::APPROVE_DEANERY,
                'read',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function customRoleLabels(): array
    {
        return [
            'adct_pi_intake_manager' => 'Intake manager',
            'adct_pi_intake_reviewer' => 'Intake reviewer',
            'parish_contact' => 'Parish contact',
            'deanery_approver' => 'Deanery approver',
        ];
    }

    /**
     * @return list<string>
     */
    public static function builtInRoles(): array
    {
        return array_values(array_diff(
            array_keys(self::roleCapabilities()),
            array_keys(self::customRoleLabels())
        ));
    }
}
