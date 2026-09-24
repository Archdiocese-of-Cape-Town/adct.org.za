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
        ], Capabilities::all());
    }

    public function testDefaultAdministratorAndEditorCapabilitiesAreMapped(): void
    {
        $roles = Capabilities::roleCapabilities();

        self::assertSame(Capabilities::all(), $roles['administrator']);
        self::assertSame([
            Capabilities::REVIEW,
            Capabilities::VIEW_REPORTS,
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
            ],
            'adct_pi_intake_reviewer' => [
                Capabilities::REVIEW,
                Capabilities::VIEW_REPORTS,
                'read',
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
