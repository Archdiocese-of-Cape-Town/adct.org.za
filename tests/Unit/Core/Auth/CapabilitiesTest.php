<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Core\Auth;

use ADCT\ParishIntake\Core\Auth\Capabilities;
use PHPUnit\Framework\TestCase;

final class CapabilitiesTest extends TestCase
{
    public function testDefinesThePluginCapabilities(): void
    {
        self::assertSame([
            'adct_pi_manage_settings',
            'adct_pi_manage_directory',
            'adct_pi_review',
            'adct_pi_view_reports',
            'adct_pi_approve_deanery',
            'edit_events',
            'edit_others_events',
            'edit_private_events',
            'edit_published_events',
            'publish_events',
            'read_private_events',
            'delete_events',
            'delete_private_events',
            'delete_published_events',
            'delete_others_events',
        ], Capabilities::all());
    }

    public function testDefaultAdministratorAndEditorCapabilitiesAreMapped(): void
    {
        $roles = Capabilities::roleCapabilities();

        self::assertSame(Capabilities::all(), $roles['administrator']);
        self::assertSame([
            Capabilities::REVIEW,
            Capabilities::VIEW_REPORTS,
            ...Capabilities::eventCapabilities(),
        ], $roles['editor']);
    }

    public function testCustomRoleCapabilitiesAreMapped(): void
    {
        self::assertSame([
            'adct_pi_intake_manager' => [
                Capabilities::MANAGE_SETTINGS,
                Capabilities::MANAGE_DIRECTORY,
                Capabilities::REVIEW,
                Capabilities::VIEW_REPORTS,
                'read',
                ...Capabilities::eventCapabilities(),
            ],
            'adct_pi_intake_reviewer' => [
                Capabilities::REVIEW,
                Capabilities::VIEW_REPORTS,
                'read',
                ...Capabilities::eventCapabilities(),
            ],
            'parish_contact' => [
                'read',
            ],
            'deanery_approver' => [
                Capabilities::APPROVE_DEANERY,
                'read',
            ],
        ], array_intersect_key(
            Capabilities::roleCapabilities(),
            Capabilities::customRoleLabels()
        ));
    }
}
