<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Core\Auth;

use ADCT\ParishIntake\Core\Auth\Capabilities;
use ADCT\ParishIntake\Core\Auth\RoleCapabilityStoreInterface;
use ADCT\ParishIntake\Core\Auth\RoleInstaller;
use ADCT\ParishIntake\Core\Auth\RoleVersionStoreInterface;
use ADCT\ParishIntake\Core\Auth\VersionedRoleInstaller;
use LogicException;
use PHPUnit\Framework\TestCase;

final class RoleInstallerTest extends TestCase
{
    public function testAddsMissingCapabilitiesAndCreatesCustomRoles(): void
    {
        $store = new FakeRoleCapabilityStore();
        $installer = new RoleInstaller($store);

        $installer->install();

        foreach (Capabilities::all() as $capability) {
            self::assertTrue($store->hasCapability('administrator', $capability));
        }

        self::assertTrue($store->hasCapability('editor', Capabilities::REVIEW));
        self::assertTrue($store->hasCapability('editor', Capabilities::VIEW_REPORTS));
        self::assertFalse($store->hasCapability('editor', Capabilities::MANAGE_SETTINGS));
        self::assertFalse($store->hasCapability('editor', Capabilities::MANAGE_DIRECTORY));
        self::assertFalse($store->hasCapability('editor', Capabilities::APPROVE_DEANERY));

        foreach (Capabilities::customRoleLabels() as $role => $label) {
            self::assertSame($label, $store->roles[$role]['label']);
        }

        foreach (Capabilities::roleCapabilities() as $role => $capabilities) {
            foreach ($capabilities as $capability) {
                self::assertTrue($store->hasCapability($role, $capability));
            }
        }
    }

    public function testInstallIsIdempotentAndPreservesAdminAddedCapabilities(): void
    {
        $store = new FakeRoleCapabilityStore();
        $store->roles['administrator']['capabilities']['adct_custom_admin_capability'] = true;
        $installer = new RoleInstaller($store);

        $installer->install();
        $installedRoles = $store->roles;
        $installer->install();

        self::assertSame($installedRoles, $store->roles);
        self::assertTrue($store->hasCapability('administrator', 'adct_custom_admin_capability'));
        self::assertTrue($store->hasCapability('editor', 'edit_posts'));
        self::assertTrue($store->hasCapability('administrator', 'manage_options'));
    }

    public function testVersionedInstallerRunsOnlyWhenItsVersionIsOutOfDate(): void
    {
        $store = new FakeRoleCapabilityStore();
        $versionStore = new FakeRoleVersionStore();
        $installer = new VersionedRoleInstaller(new RoleInstaller($store), $versionStore);

        self::assertTrue($installer->upgradeIfNeeded());
        self::assertSame(VersionedRoleInstaller::CURRENT_VERSION, $versionStore->getVersion());
        self::assertFalse($installer->upgradeIfNeeded());

        unset($store->roles['adct_pi_intake_reviewer']['capabilities'][Capabilities::VIEW_REPORTS]);
        $versionStore->setStoredVersion(0);

        self::assertTrue($installer->upgradeIfNeeded());
        self::assertTrue($store->hasCapability('adct_pi_intake_reviewer', Capabilities::VIEW_REPORTS));
        self::assertSame(VersionedRoleInstaller::CURRENT_VERSION, $versionStore->getVersion());
    }

    public function testUpgradingExistingRolesAddsEventCapabilitiesWithoutReplacingOtherAccess(): void
    {
        $store = new FakeRoleCapabilityStore();
        $versionStore = new FakeRoleVersionStore(2);
        $store->roles['administrator']['capabilities']['site_specific_capability'] = true;
        $store->roles['administrator']['capabilities']['edit_events'] = true;
        $store->roles['editor']['capabilities']['edit_events'] = true;
        $installer = new VersionedRoleInstaller(new RoleInstaller($store), $versionStore);

        self::assertTrue($installer->upgradeIfNeeded());
        self::assertSame(3, $versionStore->getVersion());
        self::assertTrue($store->hasCapability('administrator', 'site_specific_capability'));
        self::assertTrue($store->hasCapability('administrator', 'edit_events'));
        self::assertTrue($store->hasCapability('editor', 'edit_events'));
        self::assertTrue($store->hasCapability('administrator', Capabilities::EDIT_EVENTS));
        self::assertTrue($store->hasCapability('editor', Capabilities::EDIT_EVENTS));
        self::assertTrue($store->hasCapability('administrator', Capabilities::PUBLISH_EVENTS));
        self::assertTrue($store->hasCapability('editor', Capabilities::PUBLISH_EVENTS));
        self::assertTrue($store->hasCapability('adct_pi_intake_manager', Capabilities::PUBLISH_EVENTS));
        self::assertTrue($store->hasCapability('adct_pi_intake_reviewer', Capabilities::PUBLISH_EVENTS));
        self::assertFalse($store->hasCapability('parish_contact', Capabilities::PUBLISH_EVENTS));
        self::assertFalse($store->hasCapability('deanery_approver', Capabilities::PUBLISH_EVENTS));
    }
}

final class FakeRoleCapabilityStore implements RoleCapabilityStoreInterface
{
    /**
     * @var array<string, array{label: string, capabilities: array<string, bool>}>
     */
    public array $roles = [
        'administrator' => [
            'label' => 'Administrator',
            'capabilities' => [
                'read' => true,
                'manage_options' => true,
            ],
        ],
        'editor' => [
            'label' => 'Editor',
            'capabilities' => [
                'read' => true,
                'edit_posts' => true,
            ],
        ],
    ];

    public function addRoleIfMissing(string $role, string $label, array $capabilities): void
    {
        if (isset($this->roles[$role])) {
            return;
        }

        $this->roles[$role] = [
            'label' => $label,
            'capabilities' => array_fill_keys($capabilities, true),
        ];
    }

    public function addCapabilityIfMissing(string $role, string $capability): void
    {
        if (! isset($this->roles[$role])) {
            throw new LogicException('The role does not exist: ' . $role);
        }

        if (empty($this->roles[$role]['capabilities'][$capability])) {
            $this->roles[$role]['capabilities'][$capability] = true;
        }
    }

    public function hasCapability(string $role, string $capability): bool
    {
        return ! empty($this->roles[$role]['capabilities'][$capability]);
    }
}

final class FakeRoleVersionStore implements RoleVersionStoreInterface
{
    public function __construct(private int $version = 0)
    {
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function setVersion(int $version): bool
    {
        $this->version = $version;

        return true;
    }

    public function setStoredVersion(int $version): void
    {
        $this->version = $version;
    }
}
