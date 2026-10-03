<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Core\Admin;

use ADCT\ParishIntake\Core\Admin\AdminGuideLinks;
use ADCT\ParishIntake\Core\Admin\AdminHelpRegistry;
use ADCT\ParishIntake\Core\Auth\Capabilities;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * The help registry against the screens that actually exist.
 *
 * Two ways this can be wrong, and neither shows up on anybody's screen:
 *
 * - A screen with no registry entry. The help tab simply does not appear, and
 *   the omission is invisible until somebody needs it. This test lists every
 *   slug the plugin registers and requires each one to be covered.
 * - A registry keyed by the wrong thing. WordPress keys a screen by its hook
 *   suffix, `{page_type}_page_{slug}`, not by the slug the menu is registered
 *   with. A registry keyed by slug matches nothing at all: no error, no help,
 *   every screen. That is why the ids below are spelled out as literals.
 *
 * The capability on each entry is also asserted against the capability the menu
 * is registered with. The help is gated on it, so an entry that drifted to a
 * narrower capability would hide the help from a user who can legitimately read
 * the screen.
 */
final class AdminHelpRegistryTest extends TestCase
{
    /**
     * The admin screens the plugin registers, with the slug each is registered
     * under and the capability its menu is gated on.
     *
     * Read out of the add_menu_page/add_submenu_page calls in src/WordPress/Admin
     * and src/WordPress/Plugin.php. A new screen belongs here with its help
     * entry, or this test fails and says which screen.
     *
     * @var array<string, list<string>> menu slug to the WordPress screen ids it can produce
     */
    private const SCREENS = [
        'adct-parish-intake' => ['toplevel_page_adct-parish-intake'],
        'adct-parish-intake-review' => [
            // As a submenu under the menu, for a reviewer.
            'adct-parish-intake_page_adct-parish-intake-review',
            // As a top-level page, for a deanery approver who is not a reviewer.
            'toplevel_page_adct-parish-intake-review',
        ],
        'adct-parish-intake-parishes' => ['adct-parish-intake_page_adct-parish-intake-parishes'],
        'adct-parish-intake-deaneries' => ['adct-parish-intake_page_adct-parish-intake-deaneries'],
        'adct-parish-intake-senders' => ['adct-parish-intake_page_adct-parish-intake-senders'],
        'adct-parish-intake-sources' => ['adct-parish-intake_page_adct-parish-intake-sources'],
        'adct-parish-intake-inbox' => ['adct-parish-intake_page_adct-parish-intake-inbox'],
        'adct-parish-intake-mailboxes' => ['adct-parish-intake_page_adct-parish-intake-mailboxes'],
        'adct-parish-intake-outbound-mail' => ['adct-parish-intake_page_adct-parish-intake-outbound-mail'],
        'adct-parish-intake-jobs' => ['adct-parish-intake_page_adct-parish-intake-jobs'],
        'adct-parish-intake-health' => ['adct-parish-intake_page_adct-parish-intake-health'],
        'adct-parish-intake-audit-log' => ['adct-parish-intake_page_adct-parish-intake-audit-log'],
        'adct-parish-intake-retention' => ['adct-parish-intake_page_adct-parish-intake-retention'],
        'adct-parish-intake-settings' => ['adct-parish-intake_page_adct-parish-intake-settings'],
        'adct-parish-intake-manual-parser' => ['adct-parish-intake_page_adct-parish-intake-manual-parser'],
    ];

    public function testEveryRegisteredAdminScreenHasHelp(): void
    {
        $missing = [];

        foreach (self::SCREENS as $slug => $screenIds) {
            foreach ($screenIds as $screenId) {
                if (! AdminHelpRegistry::has($screenId)) {
                    $missing[] = $slug . ' (screen id ' . $screenId . ')';
                }
            }
        }

        self::assertSame(
            [],
            $missing,
            "These admin screens have no help entry, so nobody sees a Help tab on them:\n- "
                . implode("\n- ", $missing)
        );
    }

    /**
     * The other direction: help for a screen that was renamed away is worse
     * than none, because it looks like coverage while rendering nothing.
     */
    public function testThereIsNoHelpForAScreenThatNoLongerExists(): void
    {
        $known = [];

        foreach (self::SCREENS as $screenIds) {
            foreach ($screenIds as $screenId) {
                $known[] = $screenId;
            }
        }

        foreach (AdminHelpRegistry::screenIds() as $screenId) {
            self::assertContains(
                $screenId,
                $known,
                'Help is registered for ' . $screenId . ', which is not a screen the plugin registers.'
            );
        }
    }

    /**
     * The gate on the health help must be the capability the health menu is
     * registered with, not a narrower one invented for the help.
     *
     * A reviewer role holds VIEW_REPORTS and can read the health screen. Gating
     * its help on MANAGE_SETTINGS would show a user who can read the page a
     * blank help pane, and read as though they were not allowed to be there.
     */
    public function testHelpIsGatedOnTheScreensOwnViewCapability(): void
    {
        self::assertSame(
            Capabilities::VIEW_REPORTS,
            AdminHelpRegistry::forScreen('adct-parish-intake_page_adct-parish-intake-health')['capability']
        );
        self::assertSame(
            Capabilities::VIEW_REPORTS,
            AdminHelpRegistry::forScreen('adct-parish-intake_page_adct-parish-intake-jobs')['capability']
        );
        self::assertSame(
            Capabilities::REVIEW,
            AdminHelpRegistry::forScreen('adct-parish-intake_page_adct-parish-intake-review')['capability']
        );
        self::assertSame(
            Capabilities::MANAGE_DIRECTORY,
            AdminHelpRegistry::forScreen('adct-parish-intake_page_adct-parish-intake-parishes')['capability']
        );
    }

    /**
     * Every capability in the registry is one the plugin defines, so a typo
     * cannot quietly gate the help on something no role holds.
     */
    public function testEveryGateIsAPluginCapability(): void
    {
        $defined = [
            Capabilities::MANAGE_SETTINGS,
            Capabilities::MANAGE_DIRECTORY,
            Capabilities::REVIEW,
            Capabilities::VIEW_REPORTS,
            Capabilities::APPROVE_DEANERY,
        ];

        foreach (AdminHelpRegistry::screenIds() as $screenId) {
            self::assertContains(
                AdminHelpRegistry::forScreen($screenId)['capability'],
                $defined,
                'Help on ' . $screenId . ' is gated on a capability the plugin does not define.'
            );
        }
    }

    /**
     * A deanery approver holds only APPROVE_DEANERY, so they reach the review
     * queue through a *different* registration: ReviewQueuePage adds it as a
     * top-level page rather than a submenu, which changes the screen id. The
     * help has to cover both, or a dean who only ever sees the top-level entry
     * gets no Help tab at all.
     */
    public function testReviewHelpCoversBothWaysOfReachingTheQueue(): void
    {
        self::assertTrue(AdminHelpRegistry::has('adct-parish-intake_page_adct-parish-intake-review'));
        self::assertTrue(AdminHelpRegistry::has('toplevel_page_adct-parish-intake-review'));
    }

    /**
     * Every screen's help names a section of the operator guide, and the anchor
     * has to be a heading in that guide. A stale anchor still opens the right
     * document, at the top, which looks like the link works.
     */
    public function testEveryScreenPointsAtASectionThatExistsInTheOperatorGuide(): void
    {
        $anchors = self::headingAnchors('docs/operator-guide.md');

        self::assertNotEmpty($anchors, 'The operator guide has no headings to anchor into.');

        foreach (AdminHelpRegistry::screenIds() as $screenId) {
            $anchor = AdminHelpRegistry::forScreen($screenId)['anchor'];

            self::assertContains(
                $anchor,
                $anchors,
                'Help on ' . $screenId . ' links to #' . $anchor . ', which is not a heading in the operator guide.'
            );
        }
    }

    public function testGuideLinksLeadWithTheScreensOwnSection(): void
    {
        $links = AdminHelpRegistry::guideLinks('adct-parish-intake_page_adct-parish-intake-review');

        self::assertSame(
            [
                'operator' => AdminGuideLinks::operator('review-approve-and-correct'),
                'approver' => AdminGuideLinks::APPROVER_GUIDE,
                'parish' => AdminGuideLinks::PARISH_GUIDE,
            ],
            $links,
            'The review queue offers the operator section first, then the extra guides in registry order.'
        );
    }

    public function testAScreenWithNoExtraGuidesStillOffersTheOperatorSection(): void
    {
        self::assertSame(
            ['operator' => AdminGuideLinks::operator('add-or-change-a-parish')],
            AdminHelpRegistry::guideLinks('adct-parish-intake_page_adct-parish-intake-parishes')
        );
    }

    /**
     * Every guide key a screen names has a label, so the help says which
     * document it is offering rather than printing a bare URL.
     */
    public function testEveryGuideOfferedHasALabel(): void
    {
        $labels = AdminHelpRegistry::guideLabels();

        foreach (AdminHelpRegistry::screenIds() as $screenId) {
            foreach (array_keys(AdminHelpRegistry::guideLinks($screenId)) as $key) {
                self::assertArrayHasKey($key, $labels, 'No label for the ' . $key . ' guide.');
                self::assertNotSame('', $labels[$key]);
            }
        }
    }

    public function testAnUnknownScreenIsRefused(): void
    {
        self::assertFalse(AdminHelpRegistry::has('adct-parish-intake_page_adct-parish-intake-nope'));

        $this->expectException(InvalidArgumentException::class);
        AdminHelpRegistry::forScreen('adct-parish-intake_page_adct-parish-intake-nope');
    }

    /**
     * A typo in a screen's guide list would render a link to nowhere on
     * somebody's screen, so an unknown key is refused rather than resolved.
     *
     * The registry is a const, so the bad key is put there deliberately and
     * removed again; there is no other way to hand the method one.
     */
    public function testAnUnknownGuideKeyIsRefusedRatherThanLinked(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown guide key: operatr');

        // @phpstan-ignore-next-line Deliberate: exercising the guard.
        AdminHelpRegistry::guideLinksFor('adct-parish-intake_page_adct-parish-intake-review', ['operatr']);
    }

    public function testGuideLabelsCoverEveryGuideThatExists(): void
    {
        foreach (array_keys(AdminGuideLinks::all()) as $key) {
            self::assertArrayHasKey($key, AdminHelpRegistry::guideLabels());
        }
    }

    /**
     * The anchors other files already link to, as literals.
     *
     * docs/hosting-environment.md and the health screen link into these, so a
     * rename that the registry would happily accept would break them.
     */
    public function testAnchorsLinkedFromElsewhereInTheRepositoryStillResolve(): void
    {
        $anchors = self::headingAnchors('docs/operator-guide.md');

        foreach (['keep-scheduled-jobs-running', 'outbound-email-and-hourly-cap', 'configure-retention-cleanup'] as $anchor) {
            self::assertContains($anchor, $anchors, '#' . $anchor . ' is linked from elsewhere in the repository.');
        }
    }

    /**
     * The anchors GitHub derives from a heading, by its own rule: drop the
     * markup, lowercase, drop anything that is not a letter, digit or space,
     * then turn spaces into hyphens.
     *
     * @return list<string>
     */
    private static function headingAnchors(string $relativePath): array
    {
        $path = dirname(__DIR__, 4) . '/' . $relativePath;
        $contents = is_file($path) ? file_get_contents($path) : false;

        self::assertIsString($contents, $relativePath . ' could not be read.');

        $anchors = [];

        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            if (preg_match('/^#{2,4}\s+(.*)$/', $line, $matches) !== 1) {
                continue;
            }

            $text = $matches[1];
            // A heading carrying a link is anchored from its words only.
            $text = preg_replace('/\[([^\]]*)\]\([^)]*\)/', '$1', $text) ?? $text;
            $text = preg_replace('/[*_`]/', '', $text) ?? $text;
            $text = strtolower(trim($text));
            $text = preg_replace('/[^\p{L}\p{N}\s-]/u', '', $text) ?? $text;
            $text = preg_replace('/\s+/', '-', trim($text)) ?? $text;

            if ($text !== '') {
                $anchors[] = $text;
            }
        }

        return $anchors;
    }
}