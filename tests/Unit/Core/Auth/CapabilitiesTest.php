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
            'edit_adct_events',
            'edit_others_adct_events',
            'edit_private_adct_events',
            'edit_published_adct_events',
            'publish_adct_events',
            'read_private_adct_events',
            'delete_adct_events',
            'delete_private_adct_events',
            'delete_published_adct_events',
            'delete_others_adct_events',
        ], Capabilities::all());
    }

    public function testPluginOwnedCapabilitiesAreNamespacedForGrantAndRemoval(): void
    {
        $pattern = '/^(?:adct_[a-z0-9_]+|(?:edit|read|publish|delete)(?:_(?:others|private|published))?_adct_[a-z0-9_]+)$/';

        foreach (Capabilities::all() as $capability) {
            self::assertMatchesRegularExpression($pattern, $capability);
        }

        foreach (Capabilities::builtInRoles() as $role) {
            foreach (Capabilities::roleCapabilities()[$role] as $capability) {
                self::assertContains($capability, Capabilities::all());
            }
        }
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
